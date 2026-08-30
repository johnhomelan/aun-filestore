<?php

declare(strict_types=1);

/*
 * TypePHP (packaging/typephp) shim for symfony/deprecation-contracts.
 *
 * guzzlehttp/psr7 calls trigger_deprecation() from several methods (Uri,
 * PumpStream, NoSeekStream, Query, ...). The real function ships in
 * symfony/deprecation-contracts/function.php behind a top-level
 * `if (!function_exists(...))` guard - an executable statement at file scope,
 * which `bin` mode forbids - so it can't be added to `sources` directly.
 *
 * This build has no use for runtime deprecation notices, so the shim is a
 * no-op. Compile-only: never autoloaded by the interpreted runtime, so no
 * redeclaration clash with the real one.
 */
function trigger_deprecation(string $package, string $version, string $message, mixed ...$args): void
{
}
