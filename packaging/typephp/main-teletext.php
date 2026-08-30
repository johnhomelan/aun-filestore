<?php

declare(strict_types=1);

/*
 * TypePHP binary-mode entry point for the teletext fetch scripts. One binary,
 * `build/typephp/teletext-import`, dispatching on the first argument:
 *
 *   teletext-import news    --feed bbc|guardian|sky [--channel N] [--source URL] [--dry-run]
 *   teletext-import teefax  [--channel N] [--source URL] [--dry-run]
 *   teletext-import tvguide [--channel N] [--index-page NNN] [--dry-run]
 *   teletext-import weather [--channel N] [--index-page NNN] [--dry-run]
 *   teletext-import webfax  --service KEY [--channel N] [--source URL] [--dry-run]
 *
 * All accept -c/--config <dir>. Built by `make teletext-typephp`. Replaces the
 * five Symfony\Component\Console\Application wrappers under src/util/*-import.
 * See packaging/typephp/PORTING-REACT.md.
 */

use HomeLan\FileStore\Cli\ArgvInput;
use HomeLan\FileStore\Cli\NewsImportRunner;
use HomeLan\FileStore\Cli\StdoutOutput;
use HomeLan\FileStore\Cli\TeefaxImportRunner;
use HomeLan\FileStore\Cli\TvGuideImportRunner;
use HomeLan\FileStore\Cli\WeatherImportRunner;
use HomeLan\FileStore\Cli\WebfaxImportRunner;

function main(int $argc, array $argv): void
{
    if ($argc < 2) {
        \fwrite(\STDERR, "usage: teletext-import <news|teefax|tvguide|weather|webfax> [options]\n");
        exit(2);
    }

    $sCmd  = $argv[1];
    $aRest = \array_slice($argv, 2);

    // -c/--config is handled here (define the constant config.php reads) rather
    // than left to each command, so it is set before the first config lookup.
    $oInput = new ArgvInput($aRest);
    $mConfig = $oInput->getOption('config');
    if (\is_string($mConfig) && $mConfig !== '') {
        \define('CONFIG_CONF_FILE_PATH', $mConfig);
    }

    $oOutput = new StdoutOutput();

    $iExit = match ($sCmd) {
        'news'    => (new NewsImportRunner())->run($oInput, $oOutput),
        'teefax'  => (new TeefaxImportRunner())->run($oInput, $oOutput),
        'tvguide' => (new TvGuideImportRunner())->run($oInput, $oOutput),
        'weather' => (new WeatherImportRunner())->run($oInput, $oOutput),
        'webfax'  => (new WebfaxImportRunner())->run($oInput, $oOutput),
        default   => aun_teletext_unknown($sCmd),
    };

    exit($iExit);
}

function aun_teletext_unknown(string $sCmd): int
{
    \fwrite(\STDERR, "teletext-import: unknown sub-command '{$sCmd}' (news|teefax|tvguide|weather|webfax)\n");
    return 2;
}
