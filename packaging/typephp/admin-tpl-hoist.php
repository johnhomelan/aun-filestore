<?php

declare(strict_types=1);

/*
 * Pass 2 of the admin-template transform (see build-admin-templates.php and
 * PORTING-REACT.md "Stage 10d").
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
 *         $__sm_ov1 = $_smarty_tpl->getVariable('x'); $__sm_ov1->value = $__sm_v1;
 *         ...
 *     }
 *     $__sm_o2 = $_smarty_tpl->getVariable('x'); $__sm_o2->iteration = 0;
 *
 * Smarty's compiled output is regular enough that this is line-oriented regex,
 * NOT an AST pass - deliberately: nikic/php-parser is a dev-only dependency and
 * this script runs in the typephp-prep step where `src/vendor` is installed
 * `--no-dev`. Every match anchors `^` (each construct is on its own line in the
 * compiled .tpl.php).
 *
 * require this file; call admin_tpl_hoist_lvalues(string $phpSource): string.
 */

function admin_tpl_hoist_lvalues(string $sCode): string
{
    $iN = 0;

    // --- keyed foreach: `foreach (SRC as $tpl->getVariable('K')->value => $tpl->getVariable('V')->value) {`
    $sCode = \preg_replace_callback(
        '#^foreach \((?<src>.+?) as \$_smarty_tpl->getVariable\(\x27(?<k>[^\x27]+)\x27\)->value'
        . ' => \$_smarty_tpl->getVariable\(\x27(?<v>[^\x27]+)\x27\)->value\) \{$#m',
        static function (array $aM) use (&$iN): string {
            $iN++;
            return \sprintf(
                "foreach (%s as \$__sm_k%d => \$__sm_v%d) {\n"
                . "\$__sm_ok%d = \$_smarty_tpl->getVariable('%s'); \$__sm_ok%d->value = \$__sm_k%d;\n"
                . "\$__sm_ov%d = \$_smarty_tpl->getVariable('%s'); \$__sm_ov%d->value = \$__sm_v%d;",
                $aM['src'], $iN, $iN,
                $iN, $aM['k'], $iN, $iN,
                $iN, $aM['v'], $iN, $iN,
            );
        },
        $sCode,
    );

    // --- unkeyed foreach: `foreach (SRC as $tpl->getVariable('V')->value) {`
    $sCode = \preg_replace_callback(
        '#^foreach \((?<src>.+?) as \$_smarty_tpl->getVariable\(\x27(?<v>[^\x27]+)\x27\)->value\) \{$#m',
        static function (array $aM) use (&$iN): string {
            $iN++;
            return \sprintf(
                "foreach (%s as \$__sm_v%d) {\n"
                . "\$__sm_ov%d = \$_smarty_tpl->getVariable('%s'); \$__sm_ov%d->value = \$__sm_v%d;",
                $aM['src'], $iN,
                $iN, $aM['v'], $iN, $iN,
            );
        },
        $sCode,
    );

    // --- bare lvalue statement: `$tpl->getVariable('X')->iteration = ...;` / `...++;` / `...--;`
    $sCode = \preg_replace_callback(
        '#^\$_smarty_tpl->getVariable\(\x27(?<n>[^\x27]+)\x27\)->(?<prop>iteration|value)(?<op>\s*=\s*(?!=)|\+\+|--)#m',
        static function (array $aM) use (&$iN): string {
            $iN++;
            return \sprintf(
                "\$__sm_o%d = \$_smarty_tpl->getVariable('%s'); \$__sm_o%d->%s%s",
                $iN, $aM['n'], $iN, $aM['prop'], $aM['op'],
            );
        },
        $sCode,
    );

    if ($sCode === null) {
        throw new \RuntimeException('admin_tpl_hoist_lvalues: preg_replace_callback failed');
    }

    return $sCode;
}
