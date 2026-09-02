<?php
declare(strict_types=1);

function main(int $argc, array $argv): void
{
    $oContainer = new HomeLan_FileStore_Admin_KernelProdContainer();
    $oController = $oContainer->get('HomeLan\\FileStore\\Admin\\Controller\\IndexController');
    echo get_class($oController) . "\n";
}
