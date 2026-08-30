<?php
/*
 * Phar stub for filestore.phar.
 *
 * The archive bundles the whole application (src/ + composer dependencies +
 * a pre-warmed Symfony admin container cache). The real entry point is the
 * `filestored` launcher, added to the archive with its shebang stripped so it
 * can be require()d here.
 */

Phar::mapPhar('filestore.phar');

require 'phar://filestore.phar/filestored';

__HALT_COMPILER();
