<?php
namespace HomeLan\FileStore\Admin\Service;

use Smarty\Smarty as smartyEngine;  
use HomeLan\FileStore\Admin\Smarty\Extension as LocalExtension;

class Smarty {


	public function getSmarty(): smartyEngine
	{
		$oSmarty = new smartyEngine();
		$oSmarty->setCompileDir(__DIR__.'/../../../../var/templates_c/');
		$oSmarty->addTemplateDir(__DIR__.'/../templates','Default');
		$oSmarty->registerPlugin("modifier", "implodemod", "implode");
		$oSmarty->registerPlugin("modifier", "ucfirst", "ucfirst");
		$oSmarty->addExtension(new LocalExtension());	
		return $oSmarty;
	}
}
