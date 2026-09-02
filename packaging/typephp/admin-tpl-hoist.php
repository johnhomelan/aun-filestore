<?php

declare(strict_types=1);

/*
 * Pass 3 of the admin-template transform (see build-admin-templates.php and
 * PORTING-REACT.md "Stage 10d - templating").
 *
 * Smarty compiles {foreach} headers and loop counters to expressions like
 *
 *     $_smarty_tpl->getVariable('x')->value                       // foreach target
 *     $_smarty_tpl->getVariable('x')->iteration = 0;              // bare lvalue
 *     $_smarty_tpl->getVariable('x')->iteration++;
 *
 * where a *method-call result* is used as an assignment / foreach key-value
 * target. tpc transpiles this but its C++ codegen then emits
 * `methodcall(...).attr(name, AttrMode::Update) = ...` which g++ rejects.
 *
 * This pass hoists every such method-call lvalue into a plain local first, so
 * the assignment target is `$local->prop` (which tpc codegens fine):
 *
 *     foreach ($src as $__sm_v1) {
 *         $__sm_o1 = $_smarty_tpl->getVariable('x'); $__sm_o1->value = $__sm_v1;
 *         ...
 *     }
 *     $__sm_o2 = $_smarty_tpl->getVariable('x'); $__sm_o2->iteration = 0;
 *
 * AST-based (nikic/php-parser, format-preserving) so it is robust against the
 * exact whitespace of Smarty's output.
 *
 * require this file; call admin_tpl_hoist_lvalues(string $phpSource): string.
 */

require_once __DIR__ . '/../../src/vendor/autoload.php';

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter;

function admin_tpl_hoist_lvalues(string $sCode): string
{
    $oParserFactory = new ParserFactory();
    $oParser = $oParserFactory->createForHostVersion();

    $oLexerTokens = null;
    $aOld = $oParser->parse($sCode);
    if ($aOld === null) {
        throw new \RuntimeException('admin_tpl_hoist_lvalues: parse failed');
    }
    $aOldTokens = $oParser->getTokens();

    $oTraverser = new NodeTraverser();
    $oCloner = new \PhpParser\NodeVisitor\CloningVisitor();
    $oTraverser->addVisitor($oCloner);
    $aNew = $oTraverser->traverse($aOld);

    $oTraverser2 = new NodeTraverser();
    $oTraverser2->addVisitor(new class extends NodeVisitorAbstract {
        private int $iN = 0;

        /**
         * A `$_smarty_tpl->getVariable('NAME')->PROP` property-fetch, or null.
         * @return array{0: Expr\MethodCall, 1: string}|null
         */
        private function matchGetVarProp(Node $oNode): ?array
        {
            if (!$oNode instanceof Expr\PropertyFetch) {
                return null;
            }
            $oInner = $oNode->var;
            if (!$oInner instanceof Expr\MethodCall) {
                return null;
            }
            if (!$oInner->name instanceof Node\Identifier || $oInner->name->toString() !== 'getVariable') {
                return null;
            }
            if (!$oInner->var instanceof Expr\Variable || $oInner->var->name !== '_smarty_tpl') {
                return null;
            }
            if (!$oNode->name instanceof Node\Identifier) {
                return null;
            }
            return [$oInner, $oNode->name->toString()];
        }

        public function leaveNode(Node $oNode)
        {
            // --- foreach ($src as [$k =>] $v)  with method-call targets ------
            if ($oNode instanceof Node\Stmt\Foreach_) {
                $aPrepend = [];

                foreach (['keyVar', 'valueVar'] as $sSlot) {
                    $oTarget = $oNode->{$sSlot};
                    if ($oTarget === null) {
                        continue;
                    }
                    $aMatch = $this->matchGetVarProp($oTarget);
                    if ($aMatch === null) {
                        continue;
                    }
                    [$oMethodCall, $sProp] = $aMatch;
                    $this->iN++;
                    $sObjVar  = '__sm_o' . $this->iN;
                    $sLoopVar = '__sm_' . ($sSlot === 'keyVar' ? 'k' : 'v') . $this->iN;

                    // foreach target -> plain local
                    $oNode->{$sSlot} = new Expr\Variable($sLoopVar);

                    // body preamble: $__sm_oN = $_smarty_tpl->getVariable('x');
                    //                $__sm_oN->PROP = $__sm_(k|v)N;
                    $aPrepend[] = new Node\Stmt\Expression(new Expr\Assign(
                        new Expr\Variable($sObjVar),
                        $oMethodCall
                    ));
                    $aPrepend[] = new Node\Stmt\Expression(new Expr\Assign(
                        new Expr\PropertyFetch(new Expr\Variable($sObjVar), $sProp),
                        new Expr\Variable($sLoopVar)
                    ));
                }

                if ($aPrepend !== []) {
                    $oNode->stmts = array_merge($aPrepend, $oNode->stmts);
                    return $oNode;
                }
                return null;
            }

            // --- bare `<mc>->PROP = ...` / `++` / `--` statements -----------
            if ($oNode instanceof Node\Stmt\Expression) {
                $oExpr = $oNode->expr;
                $oLval = null;
                if ($oExpr instanceof Expr\Assign || $oExpr instanceof Expr\AssignOp) {
                    $oLval = $oExpr->var;
                } elseif (
                    $oExpr instanceof Expr\PostInc || $oExpr instanceof Expr\PostDec
                    || $oExpr instanceof Expr\PreInc || $oExpr instanceof Expr\PreDec
                ) {
                    $oLval = $oExpr->var;
                }
                if ($oLval === null) {
                    return null;
                }
                $aMatch = $this->matchGetVarProp($oLval);
                if ($aMatch === null) {
                    return null;
                }
                [$oMethodCall, $sProp] = $aMatch;
                $this->iN++;
                $sObjVar = '__sm_o' . $this->iN;

                // rewrite the op's target to $__sm_oN->PROP
                $oNewLval = new Expr\PropertyFetch(new Expr\Variable($sObjVar), $sProp);
                if ($oExpr instanceof Expr\Assign || $oExpr instanceof Expr\AssignOp) {
                    $oExpr->var = $oNewLval;
                } else {
                    $oExpr->var = $oNewLval;
                }

                return [
                    new Node\Stmt\Expression(new Expr\Assign(new Expr\Variable($sObjVar), $oMethodCall)),
                    $oNode,
                ];
            }

            return null;
        }
    });
    $aNew = $oTraverser2->traverse($aNew);

    $oPrinter = new PrettyPrinter\Standard();
    return $oPrinter->printFormatPreserving($aNew, $aOld, $aOldTokens);
}
