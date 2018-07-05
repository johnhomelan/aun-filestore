<?php

namespace HomeLan\FileStore\Vfs; 

use Exception as BaseException; 

/**
 * This file contains the vfsexception class
 *
 * @package corevfs
*/

/**
 * The vfsexception class allows vfs plugins to report types of error to the vfs layer.
 *
 * For example a vfs plugin should throw a vfsexception if it's a readonly vfs and a write operation is requested so the next vfs plugin can try, or
 * a vfs pluin would throw a vfsexception to abort a vfs operation all together (e.g. if we had a veto files vfs plugin thats how it could block write to certain files
 * no matter what).
 *
 * @package corevfs
 * @author John Brown <john@home-lan.co.uk>
*/
class Exception extends BaseException {

	protected $bHard = FALSE;

	/**
	 * Creates a new vfsexcption 
	 *
	 * @param string $sMessage Human readable description of the exception 
	 * @param boolean $bHardError This indicates if the exception is a hard error or not.  Hard errors should stop the vfs operation, while soft errors should just cause the vfs to move on the next plugin
	*/
	public function __construct($sMessage,$bHardError=FALSE)
	{
		parent::__construct($sMessage);
		$this->bHard=$bHardError;
	}

	/**
	 * Tests if this exception should be tread as a hard error.
	 *
	 * Oh matron 
	 * @return boolean
	*/
	public function isHard()
	{
		return $this->bHard;
	}
	
}
