<?php
/*
 * Pre-warm the Symfony admin container cache inside the staging tree so the
 * phar can boot the admin web front end without ever writing to disk (the
 * archive is read-only at run time).
 *
 * Run from the staging directory:
 *
 *   cd <stage-dir> && php <repo>/packaging/phar/warm-admin-cache.php
 *
 * getCacheDir() on the admin Kernel resolves relative to the Kernel class
 * location, so with the code running from the staging tree the warmed cache
 * lands in <stage-dir>/var/cache and is picked up verbatim once bundled.
 */

require getcwd().'/vendor/autoload.php';

use HomeLan\FileStore\Admin\Kernel;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\ConsoleOutput;

$oKernel = new Kernel('prod', false);
$oApp = new Application($oKernel);
$oApp->setAutoExit(false);

$iExit = $oApp->run(
    new ArrayInput(['command' => 'cache:warmup', '--no-optional-warmers' => false]),
    new ConsoleOutput()
);

exit($iExit);
