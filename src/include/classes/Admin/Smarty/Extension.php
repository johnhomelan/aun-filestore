<?php

namespace HomeLan\FileStore\Admin\Smarty; 
use Smarty\Extension\Base;
use HomeLan\FileStore\Admin\Smarty\IfIsObjectCompiler;

class Extension extends Base {

    public function getModifierCompiler(string $modifier): ?\Smarty\Compile\Modifier\ModifierCompilerInterface {

        switch ($modifier) {
            case 'is_object': return new IfIsObjectCompiler();
        }

        return null;
    }
}

