<?php
/*
 * Assemble filestore.phar from an already-prepared staging tree.
 *
 * The staging tree is a copy of src/ with:
 *   - composer dependencies installed (--no-dev)
 *   - the Symfony admin container cache pre-warmed into var/cache/
 *
 * All of that is done by packaging/phar/build-phar.sh; this script only turns
 * the tree into a phar. Run it with phar creation enabled:
 *
 *   php -d phar.readonly=0 packaging/phar/create-phar.php <output.phar> <stage-dir>
 */

if ($argc < 3) {
    fwrite(STDERR, "usage: php -d phar.readonly=0 create-phar.php <output.phar> <stage-dir>\n");
    exit(1);
}

$sOutput = $argv[1];
$sStage  = rtrim($argv[2], '/');
$sStub   = __DIR__.'/stub.php';

if (ini_get('phar.readonly')) {
    fwrite(STDERR, "phar.readonly is on - re-run with: php -d phar.readonly=0 ...\n");
    exit(1);
}
if (!is_dir($sStage)) {
    fwrite(STDERR, "staging dir not found: {$sStage}\n");
    exit(1);
}

@unlink($sOutput);
@unlink($sOutput.'.gz');

$oPhar = new Phar($sOutput, 0, 'filestore.phar');
$oPhar->startBuffering();

// Everything in the staging tree (php, yaml, tpl, ico, js, the warmed cache...).
$oPhar->buildFromDirectory($sStage);

// The daemon launchers are shell-style scripts with a `#!/usr/bin/php` line.
// Strip the shebang so the stub can require() `filestored`, and keep the
// siblings around for reference / future multi-entry use.
foreach (['filestored', 'sharefsd', 'dnsd', 'ntpd', 'ecosyslogd', 'sql-serverd'] as $sLauncher) {
    $sPath = $sStage.'/'.$sLauncher;
    if (is_file($sPath)) {
        $sCode = (string) file_get_contents($sPath);
        $sCode = preg_replace('/^#![^\n]*\n/', '', $sCode, 1);
        $oPhar->addFromString($sLauncher, $sCode);
    }
}

$oPhar->setStub((string) file_get_contents($sStub));

$oPhar->stopBuffering();

printf("built %s (%d entries)\n", $sOutput, count($oPhar));
