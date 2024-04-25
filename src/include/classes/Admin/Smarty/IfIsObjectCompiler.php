<?php
namespace HomeLan\FileStore\Admin\Smarty; 

use Smarty\Compile\Modifier\Base;
use Smarty\CompilerException;

/**
 * Smarty is_array modifier plugin
 */
class IfIsObjectCompiler extends Base {

	/**
 	 * @param array<mixed> $params
 	*/ 	
        public function compile(mixed $params, \Smarty\Compiler\Template $compiler) {

                if (is_countable($params) && count($params) !== 1) {
                        throw new CompilerException("Invalid number of arguments for is_object. is_object expects exactly 1 parameter.");
                }

                return 'is_object(' . $params[0] . ')';
        }

}

