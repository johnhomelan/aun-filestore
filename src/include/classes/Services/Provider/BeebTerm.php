<?php
/**
 * This file contains the bridge class
 *
 * @author John Brown <john@home-lan.co.uk>
 * @package core
*/
namespace HomeLan\FileStore\Services\Provider; 

use HomeLan\FileStore\Services\ProviderInterface;
use HomeLan\FileStore\Services\ServiceDispatcher;
use HomeLan\FileStore\Aun\Map; 
use HomeLan\FileStore\Messages\BeebTermRequest; 
use HomeLan\FileStore\Messages\EconetPacket; 
use HomeLan\FileStore\Services\Provider\BeebTerm\Admin;
use config;
use Exception;
use React\ChildProcess\Process;

/**
 * This class implements the econet bridge
 *
 * @package core
 *
 * @phpstan-type BeebTermClient array{process:Process,net:int,station:int,request:BeebTermRequest,lastactivity:int,rxseq:int,txseq:int}
*/
class BeebTerm implements ProviderInterface {

	/** @var array<int,EconetPacket> */
	protected array $aReplyBuffer = [];

	protected \Psr\Log\LoggerInterface $oLogger;

	/** @var array<string,string> */
	protected array $aServices = [];

	/** @var array<string,BeebTermClient> */
	protected array $aClients = [];

	private ServiceDispatcher $oServiceDispatcher;

	const DEFAULT_TIMEOUT = 120;

	/**
	 * Initializes the service
	 *
	*/
	public function __construct(\Psr\Log\LoggerInterface $oLogger, ?string $sServices=null)
	{
		$this->oLogger = $oLogger;
		if(is_null($sServices)){
			if(!file_exists(config::getValueAsString('beeb_term_services_file'))){
				return;
			}
			$sServices = file_get_contents(config::getValueAsString('beeb_term_services_file'));
			if($sServices === false){
				$this->oLogger->warning("Unable to read beeb term services file ".config::getValueAsString('beeb_term_services_file'));
				return;
			}
		}
		$aLines = explode("\n",$sServices);
		foreach($aLines as $sLine){
			//Matchs the form "network station ip mask" e.g. "1 4 192.168.0.4 255.255.255.0"
			if(preg_match('/^([a-z,A-Z,0-9]+) "(.+)"/',$sLine,$aMatches)>0){
				$this->addService((string) $aMatches[1],(string) $aMatches[2]);
			}
		}
	}

	protected function _addReplyToBuffer(EconetPacket $oReply): void
	{
		$this->aReplyBuffer[]=$oReply;
	}

	public function getName(): string
	{
		return "Beeb Term";
	}

	/** 
	 * Gets the admin interface Object for this serivce provider 
	 *
	*/
	public function getAdminInterface(): ?AdminInterface
	{
		return new Admin($this);
	}

	/**
	 * Gets the ports this service uses 
	 * 
	 * @return array<int,int>
	*/
	public function getServicePorts(): array
	{
		return [0xa2];
	}

	/**
	 * @return array<int,array<string,string>>
	*/
	public function getServices(): array
	{
		$aReturn = [];
		foreach($this->aServices as $sName=>$sCommand){
			$aReturn[] = ['name'=>$sName, 'command'=>$sCommand];
		}
		return $aReturn;
	}

	/**
	 * @return array<int,array{network:int,station:int,command:string,pid:int}>
	*/
	public function getSessions(): array
	{
		$aReturn = [];
		foreach($this->aClients as $sKey=>$aClient ){
			$aReturn[] = ['network'=>$aClient['net'], 'station'=>$aClient['station'], 'command'=>$aClient['process']->getCommand(),'pid'=>$aClient['process']->getPid() ?? 0];
		}
		return $aReturn;
	}

	/** 
	 * All inbound bridge messages come in via broadcast 
	 *
	*/
	public function broadcastPacketIn(EconetPacket $oPacket): void
	{

	}

	/** 
	 * All inbound bridge messages come in via broadcast, so unicast should ignore them
	 *
	*/
	public function unicastPacketIn(EconetPacket $oPacket): void
	{
		$this->processRequest(new BeebTermRequest($oPacket,$this->oLogger));
	}


	public function registerService(ServiceDispatcher $oServiceDispatcher): void
	{
		$this->oServiceDispatcher = $oServiceDispatcher;

		$_this = $this;
		$oServiceDispatcher->addHousingKeepingTask(function() use ($_this){
			$_this->houseKeeping();
		});


	}

	public function houseKeeping(): void
	{
		$aTimeoutKeys = [];
		foreach($this->aClients as $sKey=>$aDetail){
			$iLast = time() - $aDetail['lastactivity'];
			if(self::DEFAULT_TIMEOUT < $iLast){
				//Timeout 
				$aTimeoutKeys[] = $sKey;
			}
		}
		foreach($aTimeoutKeys as $sKey){
			$this->closeSession($sKey);
		}
	}

	public function addService(string $sName, string $sCommand): void
	{
		$this->aServices[$sName] = $sCommand;
	}

	/**
	 * Retreives all the reply objects built by the bridge 
	 *
	 * This method removes the replies from the buffer
	 *
	 * @return array<int,EconetPacket>
	*/
	public function getReplies(): array
	{
		$aBuffer = $this->aReplyBuffer;
		$this->aReplyBuffer = [];
		return $aBuffer;
	}

	/**
	 * This processes Beeb Term requests
	 *
	*/
	public function processRequest(BeebTermRequest $oRequest): void
	{
		$sType = $oRequest->getType();
		$this->oLogger->debug("Term: Message Type ".$sType);
		switch($sType){
			case 'LOGIN':
				$this->login($oRequest);
				break;
			case 'DATA':
				$this->econetDataIn($oRequest);
				break;
			//Station to bridge protocol
			case 'TERMINATE':
				$this->closeSession($oRequest->getSourceNetwork()."-".$oRequest->getSourceStation());
				break;
			case 'LOGIN_OK':
			case 'LOGIN_REJECT':
				break;
			default:
				$this->oLogger->warning("BeebTerm: Unrecognised message type ".var_export($sType,true).", ignoring");
				break;
		}
	}

	/**
	 * Handles the login request, that should create a new session or reject 
	 *
	*/   	
	public function login(BeebTermRequest $oRequest): void
	{
		$iNetwork = $oRequest->getSourceNetwork();
		$iStation = $oRequest->getSourceStation();
		if($iNetwork === null || $iStation === null){
			$this->oLogger->warning("BeebTerm: Login request with no source network/station, ignoring");
			return;
		}
		$sKey = $iNetwork.'-'.$iStation;

		if(array_key_exists($sKey, $this->aClients)){
			//Session already exists, client must have died and restarted
			//close the old session
			$this->closeSession($sKey);
		}
		//Setup session (the logon request is really the session start request)
		$oReply = $oRequest->buildReply();
		$this->oLogger->debug("BeebTerm: Logging into server with service ".$oRequest->getService());

		if(array_key_exists($oRequest->getService() ?? '',$this->aServices)){
			//Create a new  session
			//Creates a process, and links to the main loop so it output can be handled
			$oProcess = $this->createProcess($this->aServices[$oRequest->getService()]);
			$oProcess->start($this->oServiceDispatcher->getLoop());

			//Store the proces object linked to the service name and the net/station
			$this->aClients[$sKey] = ['process'=>$oProcess,'net'=>$iNetwork,'station'=>$iStation,'request'=>$oRequest,'lastactivity'=>time(),'rxseq'=>0,'txseq'=>0];
			$_this = $this;

			if($oProcess->stdout === null){
				$this->oLogger->error("BeebTerm: process has no stdout stream after start()");
				return;
			}

			//Setup the handling of output from the process
			$oProcess->stdout->on('data',function(string $sData) use($_this,$sKey){
				$_this->processDataOut($sKey,$sData);
			});
			$oProcess->stdout->on('end',function(){
			});
			$oProcess->stdout->on('error',function(Exception $oException){
				$this->oLogger->debug("BeebTerm: An error occured (".$oException->getMessage().")");
			});
			$oProcess->stdout->on('close',function() use($_this,$sKey){
				$_this->closeSession($sKey);  //close the session if the process exists
			});

			$this->oLogger->debug("BeebTerm: Login OK");
			//Set the flag to login ok
			$oReply->appendString($oRequest->getService() ?? '');
			$oReply->setFlags(0x82);

		}else{
			//The requestion session does not exsit
			//Set the flag to login reject
			$this->oLogger->debug("BeebTerm: Login Fail");
			$oReply->setFlags(0x83); //0x83 is the flag for logon reject 
			$oReply->appendString("Invaild Service");
		}

		//Add the reply to the output buffer 
		$this->_addReplyToBuffer($oReply->buildEconetpacket());
	}

	/**
	 * Handles data comming from the BeebTerm client over econet
	 *
	*/  	
	public function econetDataIn(BeebTermRequest $oRequest):void 
	{
		$sKey = $oRequest->getSourceNetwork().'-'.$oRequest->getSourceStation();
		$this->oLogger->debug("BeebTerm: Data from econet client ".$sKey." (".$oRequest->getData().")");
		if(array_key_exists($sKey,$this->aClients)){
			$this->aClients[$sKey]['lastactivity']=time();
			if((($oRequest->getRxSeq() - $this->aClients[$sKey]['rxseq']) & 0xFF) > 0){
				//Its new data as the RxSeq is greater than the last value
				$this->aClients[$sKey]['rxseq']=$oRequest->getRxSeq();
				$oProcess = $this->aClients[$sKey]['process'];
				if(!$oProcess->stdin instanceof \React\Stream\WritableStreamInterface){
					$this->oLogger->error("BeebTerm: process has no writable stdin stream");
				}else{
					$oProcess->stdin->write($oRequest->getData()); //Send the data to the process
				}

				//Craft a reply to ack the data 
				$oReply = $oRequest->buildReply();
				$oReply->setFlags(0x0);  //Data
				$oReply->appendByte($this->aClients[$sKey]['txseq']);
				$oReply->appendByte($this->aClients[$sKey]['rxseq']);
				$this->_addReplyToBuffer($oReply->buildEconetpacket());
	
			}
		}else{
			//Handle the session not existing (and tell the client to terminate)
			$this->oLogger->debug("BeebTerm: Could not find matching session for ".$sKey);
			$oReply = $oRequest->buildReply();
			$oReply->setFlags(0x4);  //Terminate
			$this->_addReplyToBuffer($oReply->buildEconetpacket());
		}
		
	}

	/**
	 * Closes an existing session 
	 *
	 * It includes messaging the client 
	*/ 	
	public function closeSession(string $sKey):void 
	{
		if(array_key_exists($sKey,$this->aClients)){
			$this->oLogger->debug("BeebTerm: Closing session for ".$sKey);
			$this->aClients[$sKey]['process']->terminate();
			$oReply = $this->aClients[$sKey]['request']->buildReply();
			$oReply->setFlags(0x4); //Terminate
			$this->_addReplyToBuffer($oReply->buildEconetpacket());
			unset($this->aClients[$sKey]);
		}
	}

	/**
	 * Handles sending data from the running process of an established session
	 *
	*/ 
	public function processDataOut(string $sKey,string $sData):void
	{
		$this->oLogger->debug("BeebTerm:  Data from process for ".$sKey." (".$sData.")");
		 if(array_key_exists($sKey,$this->aClients)){
			$this->aClients[$sKey]['lastactivity']=time();
			$oReply = $this->aClients[$sKey]['request']->buildReply();
			$oReply->setFlags(0x0);  //Data
			$oReply->appendByte($this->aClients[$sKey]['txseq']);
			$oReply->appendByte($this->aClients[$sKey]['rxseq']);
			$oReply->appendRaw($sData);
			$this->_addReplyToBuffer($oReply->buildEconetpacket());
			$this->oServiceDispatcher->sendPackets($this);
			$this->aClients[$sKey]['txseq'] = $this->aClients[$sKey]['txseq']+1;
		}
	}

	/**
	 * @return array<int,array<string,mixed>>
	*/
	public function getJobs():array
	{
		return [];
	}

	protected function createProcess(string $sCommand): Process
	{
		return new Process($sCommand);
	}



}
