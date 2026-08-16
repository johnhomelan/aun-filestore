<?php
/**
 * This file contains the viewdata bridge class
 *
 * @author John Brown <john@home-lan.co.uk>
 * @package core
*/
namespace HomeLan\FileStore\Services\Provider;

use HomeLan\FileStore\Services\ProviderInterface;
use HomeLan\FileStore\Services\ServiceDispatcher;
use HomeLan\FileStore\Messages\ViewdataRequest;
use HomeLan\FileStore\Messages\EconetPacket;
use HomeLan\FileStore\Services\Provider\Viewdata\Admin;
use config;
use React\Socket\ConnectionInterface;
use React\Socket\ConnectorInterface;
use React\Socket\Connector;
use Throwable;

/**
 * Bridges an Econet station to a remote viewdata/videotex server (e.g. the
 * Telstar server at glasstty.com) over a plain TCP connection, one session
 * per (network,station). The wire framing to the Econet station mirrors
 * BeebTerm's: a LOGIN/DATA/TERMINATE message set with tx/rx sequence
 * numbers for flow control. Unlike BeebTerm, there is exactly one upstream
 * target (configured via viewdata_host/viewdata_port) rather than a menu of
 * named local services, and the far end of the pipe is a TCP socket instead
 * of a spawned local process.
 *
 * The bridge does no character-set translation or pacing of its own — the
 * upstream server already throttles rendering to an emulated period baud
 * rate, so this is a transparent byte pipe in both directions.
 *
 * @package core
 *
 * @phpstan-type ViewdataClient array{connection:ConnectionInterface,net:int,station:int,request:ViewdataRequest,lastactivity:int,rxseq:int,txseq:int}
*/
class Viewdata implements ProviderInterface {

	/** @var array<int,EconetPacket> */
	protected array $aReplyBuffer = [];

	protected \Psr\Log\LoggerInterface $oLogger;

	/** @var array<string,ViewdataClient> */
	protected array $aClients = [];

	private ServiceDispatcher $oServiceDispatcher;

	const DEFAULT_TIMEOUT = 120;

	/**
	 * Initializes the service
	 *
	*/
	public function __construct(\Psr\Log\LoggerInterface $oLogger)
	{
		$this->oLogger = $oLogger;
	}

	protected function _addReplyToBuffer(EconetPacket $oReply): void
	{
		$this->aReplyBuffer[]=$oReply;
	}

	public function getName(): string
	{
		return "Viewdata";
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
		return [0xa3];
	}

	/**
	 * @return array<int,array{network:int,station:int,connected:int}>
	*/
	public function getSessions(): array
	{
		$aReturn = [];
		foreach($this->aClients as $aClient ){
			$aReturn[] = ['network'=>$aClient['net'], 'station'=>$aClient['station'], 'connected'=>$aClient['lastactivity']];
		}
		return $aReturn;
	}

	/**
	 * All inbound bridge messages come in via broadcast, so broadcast is unused
	 *
	*/
	public function broadcastPacketIn(EconetPacket $oPacket): void
	{

	}

	/**
	 * All inbound bridge messages come in via unicast
	 *
	*/
	public function unicastPacketIn(EconetPacket $oPacket): void
	{
		$this->processRequest(new ViewdataRequest($oPacket,$this->oLogger));
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
	 * This processes Viewdata requests
	 *
	*/
	public function processRequest(ViewdataRequest $oRequest): void
	{
		$sType = $oRequest->getType();
		$this->oLogger->debug("Viewdata: Message Type ".$sType);
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
				$this->oLogger->warning("Viewdata: Unrecognised message type ".var_export($sType,true).", ignoring");
		}
	}

	/**
	 * Handles the login request: opens a TCP connection to the configured
	 * viewdata server and creates a new session, or rejects if the
	 * connection cannot be established.
	 *
	*/
	public function login(ViewdataRequest $oRequest): void
	{
		$iNetwork = $oRequest->getSourceNetwork();
		$iStation = $oRequest->getSourceStation();
		if($iNetwork === null || $iStation === null){
			$this->oLogger->warning("Viewdata: Login request with no source network/station, ignoring");
			return;
		}
		$sKey = $iNetwork.'-'.$iStation;

		if(array_key_exists($sKey, $this->aClients)){
			//Session already exists, client must have died and restarted
			//close the old session
			$this->closeSession($sKey);
		}

		$sHost = config::getValueAsString('viewdata_host');
		$iPort = config::getValueAsInt('viewdata_port');
		$this->oLogger->debug("Viewdata: Connecting to {$sHost}:{$iPort} for session ".$sKey);

		$_this = $this;
		$this->createConnector()->connect("tcp://{$sHost}:{$iPort}")->then(
			function(ConnectionInterface $oConnection) use ($_this,$sKey,$iNetwork,$iStation,$oRequest){
				$_this->onConnected($sKey,$iNetwork,$iStation,$oRequest,$oConnection);
			},
			function(Throwable $oException) use ($_this,$sKey,$oRequest){
				$_this->onConnectFailed($sKey,$oRequest,$oException);
			}
		);
	}

	/**
	 * Completes session setup once the outbound TCP connection succeeds,
	 * and replies LOGIN_OK to the waiting Econet station.
	 *
	*/
	public function onConnected(string $sKey, int $iNetwork, int $iStation, ViewdataRequest $oRequest, ConnectionInterface $oConnection): void
	{
		$this->aClients[$sKey] = ['connection'=>$oConnection,'net'=>$iNetwork,'station'=>$iStation,'request'=>$oRequest,'lastactivity'=>time(),'rxseq'=>0,'txseq'=>0];

		$_this = $this;
		$oConnection->on('data',function(string $sData) use($_this,$sKey){
			$_this->processDataOut($sKey,$sData);
		});
		$oConnection->on('close',function() use($_this,$sKey){
			$_this->closeSession($sKey);
		});
		$oConnection->on('error',function(\Exception $oException){
			$this->oLogger->debug("Viewdata: An error occured (".$oException->getMessage().")");
		});

		$this->oLogger->debug("Viewdata: Login OK for ".$sKey);
		$oReply = $oRequest->buildReply();
		$oReply->setFlags(0x82);
		$this->_addReplyToBuffer($oReply->buildEconetpacket());
		$this->oServiceDispatcher->sendPackets($this);
	}

	/**
	 * Replies LOGIN_REJECT when the outbound TCP connection could not be established.
	 *
	*/
	public function onConnectFailed(string $sKey, ViewdataRequest $oRequest, Throwable $oException): void
	{
		$this->oLogger->warning("Viewdata: Unable to connect for session ".$sKey." (".$oException->getMessage().")");
		$oReply = $oRequest->buildReply();
		$oReply->setFlags(0x83); //0x83 is the flag for logon reject
		$oReply->appendString("Unable to connect to viewdata server");
		$this->_addReplyToBuffer($oReply->buildEconetpacket());
		$this->oServiceDispatcher->sendPackets($this);
	}

	/**
	 * Handles data comming from the Viewdata client over econet
	 *
	*/
	public function econetDataIn(ViewdataRequest $oRequest):void
	{
		$sKey = $oRequest->getSourceNetwork().'-'.$oRequest->getSourceStation();
		$this->oLogger->debug("Viewdata: Data from econet client ".$sKey." (".$oRequest->getData().")");
		if(array_key_exists($sKey,$this->aClients)){
			$this->aClients[$sKey]['lastactivity']=time();
			if((($oRequest->getRxSeq() - $this->aClients[$sKey]['rxseq']) & 0xFF) > 0){
				//Its new data as the RxSeq is greater than the last value
				$this->aClients[$sKey]['rxseq']=$oRequest->getRxSeq();
				$this->aClients[$sKey]['connection']->write((string) $oRequest->getData()); //Send the data to the remote server

				//Craft a reply to ack the data
				$oReply = $oRequest->buildReply();
				$oReply->setFlags(0x0);  //Data
				$oReply->appendByte($this->aClients[$sKey]['txseq']);
				$oReply->appendByte($this->aClients[$sKey]['rxseq']);
				$this->_addReplyToBuffer($oReply->buildEconetpacket());

			}
		}else{
			//Handle the session not existing (and tell the client to terminate)
			$this->oLogger->debug("Viewdata: Could not find matching session for ".$sKey);
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
			$this->oLogger->debug("Viewdata: Closing session for ".$sKey);
			//Capture and remove the session before closing the connection: closing
			//a stream commonly emits its 'close' event synchronously, which would
			//otherwise re-enter this method and unset the session out from under
			//the rest of this call.
			$aClient = $this->aClients[$sKey];
			unset($this->aClients[$sKey]);
			$aClient['connection']->close();
			$oReply = $aClient['request']->buildReply();
			$oReply->setFlags(0x4); //Terminate
			$this->_addReplyToBuffer($oReply->buildEconetpacket());
		}
	}

	/**
	 * Handles sending data from the remote viewdata server of an established session
	 *
	*/
	public function processDataOut(string $sKey,string $sData):void
	{
		$this->oLogger->debug("Viewdata: Data from server for ".$sKey." (".$sData.")");
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

	/**
	 * Creates the connector used to open the outbound TCP connection to the
	 * viewdata server. Overridden in tests to return a fake connector.
	 *
	*/
	protected function createConnector(): ConnectorInterface
	{
		return new Connector($this->oServiceDispatcher->getLoop());
	}

}
