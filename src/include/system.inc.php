<?


function safe_define($sName, $sDefine) {
	if (!defined($sName)) {
		define($sName, $sDefine);
		return TRUE;
	} else {
		return FALSE;
	}
}

require_once ("include/config.inc.php");

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
		include_once("./".$sFilename);
		return;
	}elseif(file_exists("include/classes/".$sFilename)) {
		include_once ("include/classes/".$sFilename);
		return;
	}

	//Try include path
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
	throw new Exception("Failed to load class ".$sClass);
}

//Register autoload function using newer stack-based system that plays better with other modules with their own autoloaders
spl_autoload_register('__autoload');

function pearErrorHandler($error)
{
	 throw new Exception($error->getMessage().' -- '.$error->getDebugInfo());
}

?>
