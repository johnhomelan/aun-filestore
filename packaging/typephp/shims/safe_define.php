<?php

declare(strict_types=1);

/*
 * safe_define() shim for the TypePHP builds.
 *
 * The interpreted app defines safe_define() in src/include/system.inc.php,
 * whose file-scope bootstrap code is illegal in `bin` mode - build-typephp.sh
 * strips the `include_once … system.inc.php` line from the staged
 * build/typephp/stage/cmd/*.php copies of the command classes. Those classes
 * still call `safe_define('CONFIG_CONF_FILE_PATH', <dir>)` from execute() when
 * --config is given, so this supplies it: `define()` only if not already set,
 * which matches system.inc.php's semantics.
 *
 * A bare function declaration - no `if (!function_exists())` wrapper, which is
 * file-scope executable code and illegal in `bin` mode. Listed only in
 * packaging/typephp/project*.yml `sources`, never autoloaded, so there is no
 * collision with the real definition under an interpreted run.
 */

function safe_define(string $sName, mixed $mValue): void
{
    if (!\defined($sName)) {
        \define($sName, $mValue);
    }
}
