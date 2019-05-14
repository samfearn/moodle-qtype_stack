<?php


require_once(__DIR__ . '/filter.interface.php');
require_once(__DIR__ . '/../../maximaparser/utils.php');

/**
 * AST filter that handles the logarithm base syntax-extension.
 *
 * e.g. log_10(x) => lg(x,10), log_y+x(z) => lg(z,y+x)
 *
 * Also maps log10(x) => lg(x,10)
 *
 * Will add 'logsubs' answernote if triggered.
 */
class stack_ast_log_candy_002 implements stack_cas_astfilter {
    public function filter(MP_Node $ast, array &$errors, array &$answernotes, stack_cas_security $identifierrules): MP_Node {
        $process = function($node) use (&$errors, &$answernotes) {
            if ($node instanceof MP_Functioncall && $node->name instanceof MP_Identifier) {
                // If we are already a function call and the name fits then this might be easy.
                if ($node->name->value === 'log10') {
                    $node->name->value = 'lg';
                    // The special replace of FunctionCalls appends an argument.
                    $node->replace(-1, new MP_Integer(10));
                    $answernotes[] = 'logsubs';
                    return false;
                }
                if ($node->name->value === 'log_') {
                    // This is a problem case. Lets assume we are dealing with:
                    //  log_(baseexpression)...(x) => lg(x,(baseexpression)...)
                    // As we cannot be dealing with an empty base. So lets eat that.
                    $arguments = array(); // Should be only one.
                    foreach ($node->arguments as $arg) {
                        $arguments[] = $arg->toString();
                    }
                    $newnode = new MP_Identifier('log_(' . implode(',', $arguments) . ')');
                    $node->parentnode->replace($node, $newnode);
                    // We generate answernotes and errors if this thing eats the whole 
                    // expression not before.
                    return false;
                }
                if (core_text::substr($node->name->value, 0, 4) === 'log_' &&
                    !isset($node->position['invalid'])) {
                    // Now we have something of the form 'log_xyz(y)' we will simply turn it 
                    // to 'lg(y,xyz)' by parsing 'xyz'. We do not need to care about any rules
                    // when parsing it as it will be a pure statement and will be parseable.
                    $argument = core_text::substr($node->name->value, 4);
                    // This will unfortunately lose all the inforamtion about insertted stars 
                    // but that is hardly an issue.
                    if (core_text::substr($argument, -1) === '*') {
                        // If we have this for whatever reason somethign went wrong in the logic
                        // Something we still don't know. Lets hack it here.
                        $argument = core_text::substr($argument, 0, -1);
                    }
                    try {
                        $parsed = maxima_parser_utils::parse($argument, 'Root');
                        // There will be only one statement and it is a statement.
                        $parsed = $parsed->items[0]->statement;

                        // Then we rewrite things.
                        $node->name->value = 'lg';
                        // The special replace of FunctionCalls appends an argument.
                        $node->replace(-1, $parsed);
                        $answernotes[] = 'logsubs';
                        return false;
                    }  catch (SyntaxError $e) {
                        $node->position['invalid'] = true;
                        $errors[] = 'Trouble parsing logarithms base.';
                        return false;
                    }
                }
            }

            if ($node instanceof MP_Operation && !($node->op === ':' || $node->op === '=')) {
                $lhs = $node->rightmostofleft();
                if ($lhs instanceof MP_Identifier && core_text::substr($lhs->value, 0, 4) === 'log_') {
                    $rhs = $node->leftmostofright();
                    // There is an operation between an identifier and something else
                    // We eat that op and either merge to the other thing or eat it as well.
                    $newname = $lhs->value;
                    if (stack_cas_security::get_feature($node->op, 'spacesurroundedop') !== null) {
                        $newname = $newname . ' ' . $node->op . ' ';
                    } else {
                        $newname = $newname . $node->op;
                    }
                    if ($rhs instanceof MP_Atom) {
                        $newname = $newname . $rhs->toString();
                        // lets take the term from the lhs and plug it into rhs
                        $rhs->parentnode->replace($rhs, new MP_Identifier($newname));
                        // Then move the whole rhs under the lhs
                        $lhs->parentnode->replace($lhs, $node->rhs);
                        // And elevate the lhs to replace the original op.
                        $node->parentnode->replace($node, $node->lhs);
                        return false;
                    } else if ($rhs instanceof MP_Functioncall) {
                        $newname = $newname . $rhs->name->toString();
                        $rhs->parentnode->replace($rhs, new MP_Functioncall(new MP_Identifier($newname), $rhs->arguments));
                        $lhs->parentnode->replace($lhs, $node->rhs);
                        $node->parentnode->replace($node, $node->lhs);
                        return false;
                    } else if ($rhs instanceof MP_Group) {
                        if ($node->op === '*' && (isset($node->position['fixspaces']) ||
                            isset($node->position['insertstars']))) {
                            // If there were starts insertted it was probably a function call 
                            // to begin with. Lets merge it back.
                            $rhs->parentnode->replace($rhs, new MP_Functioncall(new MP_Identifier($lhs->value), $rhs->items));
                            $lhs->parentnode->replace($lhs, $node->rhs);
                            $node->parentnode->replace($node, $node->lhs);
                            return false;                            
                        } else {
                            // The stars were there from the start so lets eat it.
                            $newname = $newname . $rhs->toString();
                            $rhs->parentnode->replace($rhs, new MP_Identifier($newname));
                            $lhs->parentnode->replace($lhs, $node->rhs);
                            $node->parentnode->replace($node, $node->lhs);
                            return false;
                        }

                        // Insert into the subtree on the right. Elevate the subtree.
                        $rhs = $node->parentnode->leftmostofright();
                        if ($rhs instanceof MP_Atom) {
                            $newname .= $rhs->toString();
                            $node->value = $newname;
                            $rhs->parentnode->replace($rhs, $node);
                            $node->parentnode->parentnode->replace($node->parentnode, $node->parentnode->rhs);  
                            return false;
                        } else if ($rhs instanceof MP_Functioncall) {
                            $newname .= $rhs->name->toString();
                            $rhs->parentnode->replace($rhs, new MP_Functioncall(new MP_Identifier($newname), $rhs->arguments));
                            $node->parentnode->parentnode->replace($node->parentnode, $node->parentnode->rhs);  
                            return false;
                        } 
                    }
                    if ($node->parentnode->rhs instanceof MP_Atom) {
                        $newname .= $node->parentnode->rhs->toString();
                        $node->parentnode->parentnode->replace($node->parentnode, 
                            new MP_Identifier($newname));   
                        return false;
                    }
                }
            }

            if ($node instanceof MP_Identifier && !$node->is_function_name()) {
                if (core_text::substr($node->value, 0, 4) === 'log_' && (
                    $node->parentnode instanceof MP_List ||
                    $node->parentnode instanceof MP_Set ||
                    $node->parentnode instanceof MP_Group ||
                    $node->parentnode instanceof MP_Statement ||
                    $node->parentnode instanceof MP_Functioncall)) {
                    // We have ended up in a situation where there is nothing to eat.
                    $node->position['invalid'] = true;
                    // TODO: localise, maybe include the erroneous portion.
                    $errors[] = 'Logarithm without an argument...';
                    return false;
                }
            }

            return true;
        };

        // This filter does massive tree modifications that are beyond the capabilities
        // of the normal graph updating so we need to activate that updating manually
        // between steps. That updating ensures that parentnode links i.e. backreferences 
        // are valid but it does not do it unless absolutely necessary. And now it is.
        $ast->callbackRecurse(null);
        while ($ast->callbackRecurse($process, true) !== true) {
            $ast->callbackRecurse(null);
        }

        return $ast;
    }
}