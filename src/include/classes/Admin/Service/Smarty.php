<?php
namespace HomeLan\FileStore\Admin\Service;

use Smarty\Smarty as smartyEngine;
use HomeLan\FileStore\Admin\Smarty\Extension as LocalExtension;
use HomeLan\FileStore\Admin\Smarty\BundledFileResource;

class Smarty {


	public function getSmarty(): smartyEngine
	{
		$oSmarty = new smartyEngine();

		// Portable compiled-file names (see BundledFileResource) so that
		// ahead-of-time compiled templates are found wherever the code runs.
		$oSmarty->registerResource('file', new BundledFileResource());

		$oSmarty->setTemplateDir(__DIR__.'/../templates');
		$oSmarty->registerPlugin("modifier", "implodemod", "implode");
		$oSmarty->registerPlugin("modifier", "ucfirst", "ucfirst");
		$oSmarty->addExtension(new LocalExtension());

		$sBundled = __DIR__.'/../templates_c';
		if (is_dir($sBundled)) {
			// Templates were compiled ahead of time and shipped: use them as-is
			// and never invoke the Smarty compiler at runtime.
			$oSmarty->setCompileDir($sBundled);
			$oSmarty->setCompileCheck(smartyEngine::COMPILECHECK_OFF);
			$oSmarty->setForceCompile(false);
		} else {
			$oSmarty->setCompileDir($this->getCompileDir());
		}

		return $oSmarty;
	}

	/**
	 * Work out where Smarty should write its compiled templates when they are
	 * NOT pre-compiled and shipped (the plain run-from-source case).
	 *
	 * Normally this is var/templates_c/ inside the install, but when the code is
	 * running from a read-only location (e.g. bundled inside a phar) that path
	 * cannot be created or written to, so fall back to a per-user temp directory.
	 */
	private function getCompileDir(): string
	{
		$sInTree = __DIR__.'/../../../../var/templates_c/';
		if (is_dir($sInTree)) {
			if (is_writable($sInTree)) {
				return $sInTree;
			}
		} elseif (@mkdir($sInTree, 0777, true) || is_dir($sInTree)) {
			return $sInTree;
		}

		$sTmp = sys_get_temp_dir().'/aun-filestore/templates_c/';
		if (!is_dir($sTmp)) {
			@mkdir($sTmp, 0777, true);
		}
		return $sTmp;
	}
}
