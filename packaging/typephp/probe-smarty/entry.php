<?php
declare(strict_types=1);

use Smarty\Smarty;

function main(int $argc, array $argv): void
{
    // Mirrors HomeLan\FileStore\Admin\Service\Smarty::getSmarty() minus the
    // BundledFileResource/Extension wiring (probe: does the runtime transpile?).
    $oSmarty = new Smarty();
    $oSmarty->setTemplateDir(__DIR__ . '/../../../src/include/classes/Admin/templates');
    $oSmarty->setCompileDir(__DIR__ . '/../../../src/include/classes/Admin/templates_c');
    $oSmarty->setCompileCheck(Smarty::COMPILECHECK_OFF);
    $oSmarty->assign('aServices', []);
    $oSmarty->assign('aEncapsulations', []);
    echo $oSmarty->fetch('index.tpl');
}
