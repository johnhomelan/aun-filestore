<?php

declare(strict_types=1);

namespace HomeLan\FileStore\Admin\Smarty;

use Smarty\Resource\FilePlugin;
use Smarty\Template;
use Smarty\Template\Source;

/**
 * A file template resource whose compiled-file identity depends only on the
 * template name, not on the absolute template directory.
 *
 * Smarty's stock file resource derives the compiled *.php filename from
 * sha1($name . $absoluteTemplateDir), so a template compiled in one location
 * (e.g. a build staging tree) is invisible when the same code later runs from a
 * different path - inside a phar (phar://...), or from a TypePHP-compiled
 * binary. Keying only on the name makes the ahead-of-time compiled templates
 * portable, which lets Smarty run with compile_check off and its whole runtime
 * compiler out of the picture.
 *
 * Safe as long as each Smarty instance keeps its own compile directory and its
 * template names are unique within it - both true for the Admin and ShareFs
 * admin UIs (single template dir each, no duplicate basenames).
 */
class BundledFileResource extends FilePlugin
{
    public function populate(Source $source, ?Template $_template = null): void
    {
        parent::populate($source, $_template);

        $source->uid = sha1($source->name);
    }
}
