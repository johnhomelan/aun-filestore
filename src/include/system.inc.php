<?php


function safe_define($sName, $sDefine) {
	if (!defined($sName)) {
		define($sName, $sDefine);
		return TRUE;
	} else {
		return FALSE;
	}
}
require_once (__DIR__.DIRECTORY_SEPARATOR."/../vendor/autoload.php");
require_once (__DIR__.DIRECTORY_SEPARATOR."config.inc.php");

function pearErrorHandler($error)
{
	 throw new Exception($error->getMessage().' -- '.$error->getDebugInfo());
}

