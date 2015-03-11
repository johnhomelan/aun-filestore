<?
/**
 * This file contains the bridge class
 *
 * @author John Brown <john@home-lan.co.uk>
 * @package core
*/

/**
 * This class implements the econet bridge
 *
 * @package core
*/
class bridge {

	protected $oMainApp = NULL ;
	
	protected $aReplyBuffer = array();

	protected function _addReplyToBuffer($oReply)
	{
		$this->aReplyBuffer[]=$oReply;
	}

	public function __construct($oMainApp)
	{
		$this->oMainApp = $oMainApp;
	}

	/**
	 * Initilizes the bridge loading all the routing information for econet networks
	 *
	 * @TODO Load the routing data
	*/
	public function init()
	{
	}


	/**
	 * Retreives all the reply objects built by the bridge 
	 *
	 * This method removes the replies from the buffer 
	*/
	public function getReplies()
	{
		$aReplies = $this->aReplyBuffer;
		$this->aReplyBuffer = array();
		return $aReplies;
	}

	/**
	 * This is the main entry point to this class 
	 *
	 * The bridgerequest object contains the request the bridge must process 
	 * @param object bridgerequest $oBridgeRequest
	*/
	public function processRequest($oBridgeRequest)
	{
		$sFunction = $oBridgeRequest->getFunction();
		logger::log("Bridge function ".$sFunction,LOG_DEBUG);
		switch($oBridgeRequest->getFunction()){
			case 'EC_BR_QUERY':
				break;
			case 'EC_BR_QUERY2':
				break;
			case 'EC_BR_LOCALNET':
				break;
			case 'EC_BR_NETKNOWN':		
				break;
			default:
				throw new Exception("Un-handled bridge request function");
		}
	}

}

?>
