<?


function safe_define($sName, $sDefine) {
	if (!defined($sName)) {
		define($sName, $sDefine);
		return TRUE;
	} else {
		return FALSE;
	}
}

require_once (__DIR__.DIRECTORY_SEPARATOR."config.inc.php");

function __autoload($sClass)
{
	$sClass=strtolower($sClass);

	//See if the class is loaded already
	if (class_exists($sClass, FALSE)){
		return;
	}
	$sFilename = $sClass.".php";

	//Try local dir
	if (file_exists($sFilename)) {
		include_once($sFilename);
		return;
	}elseif(file_exists("include".DIRECTORY_SEPARATOR."classes".DIRECTORY_SEPARATOR.$sFilename)) {
		//Include relative to the currently working directory
		include_once ("include".DIRECTORY_SEPARATOR."classes".DIRECTORY_SEPARATOR.$sFilename);
		return;
	}elseif(file_exists(__DIR__.DIRECTORY_SEPARATOR."classes".DIRECTORY_SEPARATOR.$sFilename)) {
		//Include relative to the location of this file (where ever it is in the tree)
		include_once (__DIR__.DIRECTORY_SEPARATOR."classes".DIRECTORY_SEPARATOR.$sFilename);
		return;
	}elseif(class_exists('Phar') AND strlen(Phar::running())>0 AND file_exists(Phar::running().DIRECTORY_SEPARATOR."classes".DIRECTORY_SEPARATOR.$sFilename)){
		//Includes relative to this file if it this file is contained in a phar archive
		include_once(Phar::running().DIRECTORY_SEPARATOR."classes".DIRECTORY_SEPARATOR.$sFilename);
		return;
	}

	//Search the include path
	$aPath = explode( PATH_SEPARATOR, get_include_path() );
	foreach($aPath as $sPath){
		if(file_exists($sPath."/include/classes/".$sFilename)) {
			include_once ($sPath."/include/classes/".$sFilename);
			return;
		}elseif(file_exists($sPath."/".$sFilename)) {
			include_once ($sPath."/".$sFilename);
			return;
		}
	}

	logger::log("Failed to load class ".$sClass,LOG_DEBUG);
}

//Register autoload function using newer stack-based system that plays better with other modules with their own autoloaders
spl_autoload_register('__autoload');

function pearErrorHandler($error)
{
	 throw new Exception($error->getMessage().' -- '.$error->getDebugInfo());
}

?>
