<?php
namespace HomeLan\FileStore\ShareFs\Admin\Service;

use Smarty\Smarty as smartyEngine;

class Smarty {

	public function getSmarty(): smartyEngine
	{
		$oSmarty = new smartyEngine();
		$oSmarty->setCompileDir(__DIR__.'/../../../../../var/sharefs_templates_c/');
		$oSmarty->addTemplateDir(__DIR__.'/../templates','Default');
		$oSmarty->registerPlugin("modifier", "ucfirst", "ucfirst");
		return $oSmarty;
	}
}
