<?php

declare(strict_types=1);

/*
 * Build-time transform for the TypePHP native admin UI (see PORTING-REACT.md
 * "Stage 10c/10d").
 *
 * Smarty pre-compiles src/include/classes/Admin/templates/*.tpl into
 * src/include/classes/Admin/templates_c/<hash>_0.file_<name>.tpl.php, but each
 * of those opens - at file scope - with
 *
 *     if ($_smarty_tpl->getCompiled()->isFresh($_smarty_tpl, array(...))) {
 *     function content_XXXX (\Smarty\Template $_smarty_tpl) { ... }
 *     }
 *
 * tpc `mode: bin` rejects the file-scope `if` ("Unsupported statement: Stmt_If")
 * and cannot `include` a PHP file at run time to pull the function in anyway.
 *
* Three passes per compiled template:
 *
 *   1. Strip the `if (...isFresh...) {` prefix and its matching closing `}`,
 *      leaving a bare `function content_XXXX(...) { ... }` that CAN be a
 *      `sources` entry. Also emits admin_template_dispatch.php - a name ->
 *      unifunc table plus a `switch()` invoker - so the Smarty runtime shim
 *      (shims/smarty_runtime.php) resolves fetch('index.tpl') /
 *      renderSubTemplate('file:std-head.tpl') without a variable-function call.
 *   2. Hoist Smarty's method-call lvalue targets
 *      (`$_smarty_tpl->getVariable('x')->value` / `->iteration` as a foreach or
 *      assignment target) into plain locals - tpc's C++ codegen cannot assign
 *      through a method-call result (admin-tpl-hoist.php; line-oriented regex,
 *      no nikic/php-parser).
 *   3. Rewrite every inline-HTML island (`?>...<?php`, `<?= ... ?>`) into an
 *      `echo '...';` statement - tpc `mode: bin` rejects `Stmt_InlineHTML`
 *      inside a function body, and Smarty's compiled output is built entirely
 *      on `?>html<?php` interleaving.
 *
 * tpc `mode: bin` rejects the original file-scope `if` ("Unsupported statement:
 * Stmt_If") and cannot `include` a PHP file at run time to pull the function in
 * anyway - hence pass 1.
 *
 * Usage: php build-admin-templates.php <templates_c dir> <out dir>
 */

require_once __DIR__ . '/admin-tpl-hoist.php';

/**
 * Rewrite a PHP source string so it contains NO inline-HTML / close-tag / short
 * echo transitions - every literal HTML run becomes `echo '<literal>';`. The
 * result is pure PHP (single leading `<?php`), semantically identical.
 */
function admin_tpl_deinline(string $sCode): string
{
    $aTokens = \token_get_all($sCode);
    $sOut = "<?php\n";

    foreach ($aTokens as $mToken) {
        if (\is_array($mToken)) {
            [$iId, $sText] = $mToken;
            switch ($iId) {
                case \T_OPEN_TAG:
                    // open tag - drop it (we emitted our own single leader)
                    break;
                case \T_OPEN_TAG_WITH_ECHO:
                    // short-echo open tag - becomes an `echo` statement
                    $sOut .= ' echo ';
                    break;
                case \T_CLOSE_TAG:
                    // close tag - terminates the current statement
                    $sOut .= '; ';
                    break;
                case \T_INLINE_HTML:
                    $sOut .= 'echo ' . \var_export($sText, true) . ';';
                    break;
                default:
                    $sOut .= $sText;
                    break;
            }
        } else {
            $sOut .= $mToken;
        }
    }

    return $sOut;
}

if ($argc < 3) {
    fwrite(STDERR, "usage: build-admin-templates.php <templates_c-dir> <out-dir>\n");
    exit(2);
}

$sSrcDir = rtrim($argv[1], '/');
$sOutDir = rtrim($argv[2], '/');

if (!is_dir($sSrcDir)) {
    fwrite(STDERR, "build-admin-templates: source dir not found: {$sSrcDir}\n");
    exit(1);
}
if (!is_dir($sOutDir) && !mkdir($sOutDir, 0777, true) && !is_dir($sOutDir)) {
    fwrite(STDERR, "build-admin-templates: cannot create out dir: {$sOutDir}\n");
    exit(1);
}

// name (e.g. "index.tpl") => unifunc (e.g. "content_6a96ee1ca41e11_05042358")
$aMap = [];
// unifunc list, in emit order, for the match() invoker
$aUnifuncs = [];

foreach (glob($sSrcDir . '/*.tpl.php') as $sPath) {
    $sCode = file_get_contents($sPath);
    if ($sCode === false) {
        fwrite(STDERR, "build-admin-templates: cannot read {$sPath}\n");
        exit(1);
    }

    // The unit-function name and the template's own resource name both live in
    // the isFresh() properties array.
    if (!preg_match("/'unifunc'\\s*=>\\s*'([A-Za-z0-9_]+)'/", $sCode, $aM)) {
        fwrite(STDERR, "build-admin-templates: no 'unifunc' in {$sPath}\n");
        exit(1);
    }
    $sUnifunc = $aM[1];

    // file_dependency's first entry: [0 => '<name>.tpl', 1 => <mtime>, 2 => 'file']
    if (!preg_match("/0\\s*=>\\s*'([^']+\\.tpl)'/", $sCode, $aM)) {
        fwrite(STDERR, "build-admin-templates: no source name in {$sPath}\n");
        exit(1);
    }
    $sName = $aM[1];

    // Strip the file-scope wrapper. The generated shape is fixed:
    //   ...preamble comments...
    //   if ($_smarty_tpl->getCompiled()->isFresh($_smarty_tpl, array (
    //     ...literal array...
    //   ))) {
    //   function content_XXXX (\Smarty\Template $_smarty_tpl) {
    //   ...body...
    //   <?php }
    //   }
    // Remove everything from the `if (` line up to (not including) the
    // `function content_XXXX` line, and drop the final `}` that closes the `if`.
    $sPattern = '/^if \(\$_smarty_tpl->getCompiled\(\)->isFresh\(.*?\)\) \{\n(?=function ' . preg_quote($sUnifunc, '/') . ' )/sm';
    $sStripped = preg_replace($sPattern, '', $sCode, 1, $iCount);
    if ($iCount !== 1) {
        fwrite(STDERR, "build-admin-templates: wrapper prefix not matched in {$sPath}\n");
        exit(1);
    }

    // Drop the trailing `}` (closes the former `if`). The file now ends with
    // "...<?php }\n}\n" (function close, then if close) - remove the last one.
    $sStripped = preg_replace('/\}\s*$/', '', rtrim($sStripped) . "\n");
    $sStripped = rtrim($sStripped) . "\n";

    // Pass 2: hoist Smarty method-call lvalue targets into locals (line-oriented
    // regex - admin-tpl-hoist.php; no nikic/php-parser, which is dev-only and
    // absent from the --no-dev typephp-prep vendor set).
    $sStripped = admin_tpl_hoist_lvalues($sStripped);

    // Pass 3: rewrite inline-HTML islands to `echo` statements.
    $sStripped = admin_tpl_deinline($sStripped);

    // Sanity: the result must be valid PHP and free of inline HTML.
    $aCheck = @token_get_all($sStripped, TOKEN_PARSE);
    foreach ($aCheck as $mTok) {
        if (\is_array($mTok) && \in_array($mTok[0], [\T_INLINE_HTML, \T_CLOSE_TAG, \T_OPEN_TAG_WITH_ECHO], true)) {
            fwrite(STDERR, "build-admin-templates: de-inline left a " . token_name($mTok[0]) . " in {$sPath}\n");
            exit(1);
        }
    }

    $sOutPath = $sOutDir . '/' . basename($sPath);
    if (file_put_contents($sOutPath, $sStripped) === false) {
        fwrite(STDERR, "build-admin-templates: cannot write {$sOutPath}\n");
        exit(1);
    }

    $aMap[$sName]  = $sUnifunc;
    $aUnifuncs[]   = $sUnifunc;
}

if ($aMap === []) {
    fwrite(STDERR, "build-admin-templates: no templates found in {$sSrcDir}\n");
    exit(1);
}

// --- emit admin_template_dispatch.php --------------------------------------
$aLines = [];
$aLines[] = '<?php';
$aLines[] = '';
$aLines[] = '// GENERATED by packaging/typephp/build-admin-templates.php - do not edit.';
$aLines[] = '// Resolves a Smarty template name / unit-function name to the compiled';
$aLines[] = '// content_*() function (stripped of its file-scope isFresh() wrapper),';
$aLines[] = '// without a variable-function call (unsupported under tpc mode:bin).';
$aLines[] = '';
$aLines[] = 'namespace HomeLan\FileStore\Admin\Compiled;';
$aLines[] = '';

// name => unifunc
$aLines[] = 'const ADMIN_TPL_UNIFUNC = [';
foreach ($aMap as $sName => $sUnifunc) {
    $aLines[] = "    '" . addslashes($sName) . "' => '" . $sUnifunc . "',";
}
$aLines[] = '];';
$aLines[] = '';

// unifunc invoker
$aLines[] = 'function admin_tpl_invoke(string $sUnifunc, \Smarty\Template $oTpl): void';
$aLines[] = '{';
$aLines[] = '    switch ($sUnifunc) {';
foreach ($aUnifuncs as $sUnifunc) {
    $aLines[] = "        case '" . $sUnifunc . "':";
    $aLines[] = "            \\{$sUnifunc}(\$oTpl);";
    $aLines[] = '            return;';
}
$aLines[] = '        default:';
$aLines[] = '            throw new \RuntimeException("admin_tpl_invoke: unknown unit function \"{$sUnifunc}\"");';
$aLines[] = '    }';
$aLines[] = '}';
$aLines[] = '';

$sDispatchPath = $sOutDir . '/admin_template_dispatch.php';
if (file_put_contents($sDispatchPath, implode("\n", $aLines)) === false) {
    fwrite(STDERR, "build-admin-templates: cannot write {$sDispatchPath}\n");
    exit(1);
}

fprintf(STDERR, "  [typephp] admin templates: stripped %d compiled templates -> %s\n", count($aMap), $sOutDir);
