<?php
namespace HomeLan\FileStore\Admin\Service;

use Smarty as smartyEngine;  

class Smarty {


	public function getSmarty(): smartyEngine
	{
		$oSmarty = new smartyEngine();
		$oSmarty->setCompileDir(__DIR__.'/../../../../var/templates_c/');
		$oSmarty->addTemplateDir(__DIR__.'/../templates','Default');
		return $oSmarty;
	}
}
