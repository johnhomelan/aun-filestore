<?php
namespace HomeLan\FileStore\ShareFs\Admin\Service;

use Smarty\Smarty as smartyEngine;
use HomeLan\FileStore\Admin\Smarty\BundledFileResource;

class Smarty {

	public function getSmarty(): smartyEngine
	{
		$oSmarty = new smartyEngine();

		$oSmarty->registerResource('file', new BundledFileResource());

		$oSmarty->setTemplateDir(__DIR__.'/../templates');
		$oSmarty->registerPlugin("modifier", "ucfirst", "ucfirst");

		$sBundled = __DIR__.'/../templates_c';
		if (is_dir($sBundled)) {
			$oSmarty->setCompileDir($sBundled);
			$oSmarty->setCompileCheck(smartyEngine::COMPILECHECK_OFF);
			$oSmarty->setForceCompile(false);
		} else {
			$oSmarty->setCompileDir($this->getCompileDir());
		}

		return $oSmarty;
	}

	/**
	 * Where Smarty writes compiled templates when they are not pre-compiled and
	 * shipped: var/sharefs_templates_c/ inside the install, or a per-user temp
	 * directory when that is not writable (e.g. running from a phar).
	 */
	private function getCompileDir(): string
	{
		$sInTree = __DIR__.'/../../../../../var/sharefs_templates_c/';
		if (is_dir($sInTree)) {
			if (is_writable($sInTree)) {
				return $sInTree;
			}
		} elseif (@mkdir($sInTree, 0777, true) || is_dir($sInTree)) {
			return $sInTree;
		}

		$sTmp = sys_get_temp_dir().'/aun-filestore/sharefs_templates_c/';
		if (!is_dir($sTmp)) {
			@mkdir($sTmp, 0777, true);
		}
		return $sTmp;
	}
}
