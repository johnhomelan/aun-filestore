<?php

namespace HomeLan\FileStore\Command; 
declare(ticks = 1);

include_once(__DIR__.'/../../system.inc.php');

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Command\Command;

use HomeLan\FileStore\Aun\Map; 
use HomeLan\FileStore\Aun\AunPacket; 
use HomeLan\FileStore\Messages\FsRequest;
use HomeLan\FileStore\Messages\PrintServerEnquiry; 
use HomeLan\FileStore\Messages\PrintServerData; 
use HomeLan\FileStore\Messages\BridgeRequest; 
use HomeLan\FileStore\Authentication\Security; 
use HomeLan\FileStore\Vfs\Vfs; 
 
use config;
use Exception;

/**
 * filestored is the main loop of the application.  It deals with all the socket operations, dispatches and collects
 * data from the main application classes (fileserver, print server), and handles all the initialization tasks.
*/
class Filestore extends Command {

	/**
	 * Hold the single instance of the fileserver object
	 *
	 * @var object fileserver
	*/
	protected $oFileServer;

	/**
	 * Hold the single instance of the printserver object
	 *
	*/
	protected $oPrintServer;


	/**
	 * Hold the single instance of the birdge object
	 *
	*/
	protected $oBridge;

	/**
	 * The handle for the AUN udp socket
	 *
	*/
	protected $oAunSocket = NULL;
	
	/**
	 * An array of all the readable sockets currently held open by the application
	 *	
	 * @var array
	*/
	protected $aAllReadableSockets = array();


	/**
	 * The log handler 
	 *
	*/
	protected $oLogger; 

	/**
	 * Initializes the application injecting the service deps 
	 *
	*/
	public function __construct(\HomeLan\FileStore\Services\FileServer $oFileServer, \HomeLan\FileStore\Services\PrintServer $oPrintServer, \HomeLan\FileStore\Services\Bridge $oBridge, \Psr\Log\LoggerInterface $oLogger)
	{
		$this->oFileServer = $oFileServer;
		$this->oPrintServer = $oPrintServer;
		$this->oBridge = $oBridge;
		$this->oLogger = $oLogger;
		parent::__construct();
	}

	/**
	 * The starting method for the application
	 *
	 * This does not contain the main loop, it just initializes the system
	 * then jumps to the main loop.
	*/
	protected function execute(InputInterface $oInput, OutputInterface $oOutput)
	{
		$bDaemonize = FALSE;
		$sPidFile = "";

		if($oInput->getOption('config')!==null){
			safe_define('CONFIG_CONF_FILE_PATH',$oInput->getOption('config'));
		}
		if($oInput->getOption('pidfile')!==null){
			$sPidFile = $oInput->getOption('pidfile');
		}
		if($oInput->getOption('daemonize')!==null){
			$bDaemonize = true;
		}

		//Fork and background
		if($bDaemonize){
			$this->daemonize($sPidFile);
		}

		//Initialize the system
		try {
			//Setup signle handler
			pcntl_signal(SIGCHLD,array($this,'sigHandler'));
			pcntl_signal(SIGALRM, array($this, 'sigHandler'));
			pcntl_signal(SIGTERM,array($this,'sigHandler'));
			$this->registerNextAlarm();

			//Create a file server instance and start it
			$this->oFileServer->init($this);

			//Create a print server instance and start it
			$this->oPrintServer->init($this);

			//Create the bridge instance and start it
			$this->oBridge->init($this);

			//Load the Aun-Map that maps econet network/station numbers to ip addresses and ports
			Map::init($this->oLogger);
			
			//Setup listening sockets
			$this->bindSockets();
		}catch(Exception $oException){
			//Failed to initialize log and exit none 0
			$this->oLogger->debug($oException->getMessage());
			$this->oLogger->emergency("Un-abale to initialize the server, shutting down.");
			exit(1);
		}

		//Enter main loop
		$this->oLogger->debug("Starting primary loop.");
		$this->loop();
	}

	/**
	 * Displays how to use the command
	 *
	*/
	protected function configure()
	{
		$sHelp =<<<EOF
Start the file, print and bridge services
EOF;

		parent::configure();
		$this->setName('filestore')
			->setDescription('Start the file, print and bridge services')
			->addOption(
				'config',
				'c',
				InputOption::VALUE_OPTIONAL,
				'Provides the path to the config directory to be used (any files ending in .conf will be read from this directory)',
				null
			)->addOption(
				'daemonize',
				'd',
				InputOption::VALUE_OPTIONAL,
				'Causes the filestored to daemonize and drop into the background, otherwise the process contiunes to run in the forground'
			)->addOption(
				'pidfile',
				'p',
				InputOption::VALUE_OPTIONAL,
				'Cause filestored to write the PID of the deamonized process to a file'
			)->setHelp($sHelp);
				
	}

	/**
	 * Handles any unix signals 
	 *
	 * The main signal this handles is SIGALARM, that is used to perform the house keeping tasks
	 * @param int $iSigno
	*/
	public function sigHandler($iSigno)
	{
		switch($iSigno){
			case SIGALRM:
				//On sigalarm perform any house keeping tasks
				$this->oLogger->debug("Got sig alarm");
				Security::houseKeeping();
				vfs::houseKeeping();
				$this->registerNextAlarm();
				break;
			case SIGTERM:
				$this->oLogger->info("Shutting down filestored");
				break;
			case SIGCHLD:
			default:
				//Ignore
				break;
		}
	}

	/**
	 * Sets up the next alarm to trigger the house keeping tasks
	 *
	*/
	protected function registerNextAlarm()
	{
		pcntl_alarm(config::getValue('housekeeping_interval'));
	}


	/**
	 * Cause the program to daemonize 
	 *
	 * The current pid starts a child (that continues and becomes the running server) while the parent pid exits
	 *
	 * @param string $sPidFile The file to write the pid of the child to
	*/
	function daemonize($sPidFile)
	{
		$iPid=pcntl_fork();
		if($iPid != 0){
			//We are the parent 
			if($sPidFile!=""){
				//Write the child pid to the pid file
				file_put_contents($sPidFile,$iPid);
			}
			exit(0);
		}else{
			//We are the child close stdin,stdout,stderr
			fclose(STDIN);
			fclose(STDOUT);
			fclose(STDERR);
		}
	}

	/**
	 * Sets up a UDP sockets listening on the port used for aun packets 
	 *
	*/	
	function bindSockets()
	{
		$this->oAunSocket = @stream_socket_server('udp://'.config::getValue('aun_listen_address').':'.config::getValue('aun_listen_port'),$iErrno,$sErrstr,STREAM_SERVER_BIND);
		if($this->oAunSocket===FALSE){
			throw new Exception("Un-able to bind AUN socket (".$sErrstr.")",$iErrno);
		}
		$this->aAllReadableSockets=array($this->oAunSocket);
	}

	/**
	 * The main code loop
	 *
	 * Reads any aun packets, decodes them, acks them, sends them to the fileserver class (if they are file server packets) and dispatches any replys
	*/
	function loop()
	{
		$sErrstr=NULL;
		$iErrno=NULL;
		$bLoop=TRUE;
		$aWriteSet=NULL;
		$aAllExpSockets=array(NULL);

		//Main Loop
		while($bLoop){
			try{
				$aReadSet=$this->aAllReadableSockets;
				$aExpSet=$aAllExpSockets;
				$iSockets = @stream_select($aReadSet,$aWriteSet,$aExpSet,NULL);
				if($iSockets!==FALSE){
					$this->oLogger->debug("Got data");
					//Step through each socket we need to read from
					foreach($aReadSet as  $iReadSocket){
						if($iReadSocket == $this->oAunSocket){
							//We've received an AUN packet process it 
							$this->processAunPacket($this->oAunSocket);
						}
					}
				}
			}catch(Exception $oException){
				$this->oLogger->error($oException->getMessage());
			}
		}
	}
	
	/**
	 * This method reads a aun packet sends any acks (if needed)
	 * and passes the resulting econet packet to processEconetPacket
	 *
	 * @param int $oSocket The socket to read the aun packet from
	*/
	public function processAunPacket($oSocket)
	{
		$oAunPacket = new AunPacket();
		
		//Read the UDP data
		$sUdpData= stream_socket_recvfrom($oSocket,1500,0,$sHost);
		$this->oLogger->debug("filestore: Aun packet recvieved from ".$sHost);	
		$oAunPacket->setSourceIP($sHost);

		//Decode the AUN packet
		$oAunPacket->setDestinationIP(config::getValue('local_ip'));
		$oAunPacket->decode($sUdpData);
		
		//Send an ack for the AUN packet if needed
		$sAck = $oAunPacket->buildAck();
		if(strlen($sAck)>0){
			$this->oLogger->debug("filestore: Sending Ack packet");
			stream_socket_sendto($oSocket,$sAck,0,$sHost);
		}
		$this->oLogger->debug("filestore: ".$oAunPacket->toString());
	
		//Build an econet packet from the AUN packet an pass it to be processed
		switch($oAunPacket->getPacketType()){
			case 'Immediate':
			case 'Unicast':
				$oEconetPacket = $oAunPacket->buildEconetPacket();
				$this->processEconetPacket($oEconetPacket);
				break;
			case 'Broadcast':
				$this->oLogger->debug("filestore: Received broadcast packet ");
				switch($oAunPacket->getPortName()){
					case 'Bridge':
						$this->bridgeCommand($oAunPacket->buildEconetPacket());
						break;
					case 'FileServerCommand':
						//Some fileserver commands can be send via broadcase (e.g. getDiscs which is used to find servers)
						$oEconetPacket = $oAunPacket->buildEconetPacket();
						$this->processEconetPacket($oEconetPacket);
						break;
					
				}
		}

	}

	/**
	 * Read the econet packet and passes it to the class to deal with that type of packet
	 *
	 * Only the file server is implemented at the moment so only file server packets are processed 
	*/
	public function processEconetPacket($oEconetPacket)
	{
	
		$sPort = $oEconetPacket->getPortName();
		switch($sPort){
			case 'FileServerCommand':
				$this->fileServerCommand($oEconetPacket);
				break;
			case 'PrinterServerEnquiry':
				$this->printServerEnquiry($oEconetPacket);
				break;
			case 'PrinterServerData':
				$this->printerServerData($oEconetPacket);
				break;
			default:
				$this->oLogger->debug("filestore: Recived packet on un-handle port (".$sPort.")");
				$this->oLogger->debug("filestore: ".$oEconetPacket->toString());
				break;
		}

	}

	/**
	 * Deals with a econet packet on the file server port
	 *
	 * It build an fsrequest from the econet packet and dispatches it to the fileserver class to be dealt with
	 * Any reply packets produced by the file server class at this point are dispatched	
	 * @param object econetpacket $oEconetPacket
	*/
	public function fileServerCommand($oEconetPacket)
	{
		$oFsRequest = new FsRequest($oEconetPacket, $this->oLogger);
		$this->oFileServer->processRequest($oFsRequest);
		$aReplies = $this->oFileServer->getReplies();
		foreach($aReplies as $oReply){
			$this->oLogger->debug("filestore: Sending econet reply");
			$oReplyEconetPacket = $oReply->buildEconetpacket();
			$this->dispatchReply($oReplyEconetPacket);	
		}
	}

	/**
	 * This handles print server enquires 
	 *
	 * @param object econetpacket $oEconetPacket The packet from the client to the print server enquiry port
	*/
	public function printServerEnquiry($oEconetPacket)
	{
		$oPrintServerEnquiry = new PrintServerEnquiry($oEconetPacket, $this->oLogger);
		$this->oPrintServer->processEnquiry($oPrintServerEnquiry);
		$this->sendPrinterReplies();
		
	}

	/**
	 * This handles print data from the client
	 *
	 * @param object econetpacket $oEconetPacket The print data from the client 
	*/
	public function printerServerData($oEconetPacket)
	{
		$oPrintServerData = new PrintServerData($oEconetPacket);
		$this->oPrintServer->processData($oPrintServerData);
		$this->sendPrinterReplies();
	}

	/**
	 * This dispatches inbound bridge commands to the birdge service 
	 *
	 * @param object econetpacket $oEconetPacket The bridge command from the client
	*/
	public function bridgeCommand($oEconetPacket)
	{
		try{
			$oBridgeRequest = new BridgeRequest($oEconetPacket, $this->oLogger);
			$this->oBridge->processRequest($oEconetPacket);
			$this->sendBridgeReplies();
		}catch(Exception $oException){
			$this->oLogger->debug("bridge: ".$oException->getMessage());
		}
	}

	/**
	 * Sends any print server packets to thier respective clients 
	 *
	*/
	public function sendPrinterReplies()
	{
		$aReplies = $this->oPrintServer->getReplies();
		foreach($aReplies as $oReply){
			$this->oLogger->debug("filestore: Sending econet reply");
			$oReplyEconetPacket = $oReply->buildEconetpacket();
			$this->dispatchReply($oReplyEconetPacket);	
		}
	}

	/**
	 * Sends any bridge reply packets to thier respective clients 
	 *
	*/
	public function sendBridgeReplies()
	{
		$aReplies = $this->oBridge->getReplies();
		foreach($aReplies as $oReply){
			$this->oLogger->debug("bridge: Sending econet reply");
			$oReplyEconetPacket = $oReply->buildEconetpacket();
			$this->dispatchReply($oReplyEconetPacket);	
		}
	}

	/**
	 * Sends a replay packet to a remote machine
	 *
	 * @param object econetpacket $oReplyEconetPacket
	*/
	public function dispatchReply($oReplyEconetPacket)
	{
		usleep(config::getValue('bbc_default_pkg_sleep'));
		$sIP = Map::ecoAddrToIpAddr($oReplyEconetPacket->getDestinationNetwork(),$oReplyEconetPacket->getDestinationStation());
		if(strlen($sIP)>0){
			$sPacket = $oReplyEconetPacket->getAunFrame();
			$this->oLogger->debug("filestore: AUN packet to ".$sIP." (".implode(':',unpack('C*',$sPacket)).")");
			if(strlen($sPacket)>0){
				if(strpos($sIP,':')===FALSE){
					$sHost=$sIP.':'.config::getValue('aun_default_port');
				}else{
					$sHost=$sIP;
				}
				stream_socket_sendto($this->oAunSocket,$sPacket,0,$sHost);
			}
		}
	}

	/**
	 * This method is to allow for the file server to break out of the main loop breifly and
	 * accept a direct stream from the client
	 *
	 * @param int $iPort the econet port to accept the stream on
	*/
	public function directStream($iNetwork,$iStation,$iPort,$iRecursion=0)
	{
		if($iRecursion>20){
			throw new Exception("No direct stream recevied in 20 packets");
		}
		$aReadSet=$this->aAllReadableSockets;
		$aWriteSet=NULL;
		$aAllExpSockets=array(NULL);
		$aExpSet=$aAllExpSockets;

		//Wait for an aun packet
		$iSockets = @stream_select($aReadSet,$aWriteSet,$aExpSet,4);
		if($iSockets!=FALSE){
			//Step through each socket we need to read from
			foreach($aReadSet as  $iReadSocket){
				if($iReadSocket == $this->oAunSocket){
					//We've received an AUN packet process it 
					$oAunPacket = new AunPacket();
					$sUdpData= stream_socket_recvfrom($this->oAunSocket,1500,0,$sHost);
					$oAunPacket->setSourceIP($sHost);
					$this->oLogger->debug("filestore: Aun packet recvieved from ".$sHost);	

					//Decode the AUN packet
					$oAunPacket->setDestinationIP(config::getValue('local_ip'));
					$oAunPacket->decode($sUdpData);
					
					//Send an ack for the AUN packet if needed
					$sAck = $oAunPacket->buildAck();
					if(strlen($sAck)>0){
						$this->oLogger->debug("filestore: Sending Ack packet");
						stream_socket_sendto($this->oAunSocket,$sAck,0,$sHost);
					}
				
					//Build an econet packet from the AUN packet and see if it's the direct stream we are waiting for 
					if($oAunPacket->getPacketType()=='Unicast'){
						$oEconetPacket = $oAunPacket->buildEconetPacket();
						if($oEconetPacket->getSourceNetwork()==$iNetwork AND $oEconetPacket->getSourceStation()==$iStation AND $oEconetPacket->getPort()==$iPort){
							//It's the frame we are waiting for.
							return $oEconetPacket;
						}else{
							//Not the direct data stream we are waiting for but the packet must be processed in the normal way
							$this->processEconetPacket($oEconetPacket);
							//Go back to listening for the direct stream
							return $this->directStream($iNetwork,$iStation,$iPort,$iRecursion+1);
						}
					}else{
						return $this->directStream($iNetwork,$iStation,$iPort,$iRecursion+1);
					}
				}
			}
		
		}else{
			throw new Exception("Direct stream timeout");
		}
	}

	/**
	 * This method keaps waiting for an ack from a given econet address
	 *
	 * If a none ack packet arrives while waiting for an ack that packet is processed
	 *
	 * @param int $iNetwork
	 * @param int $iStation
	 * @param int $iRecursion
	*/
	public function waitForAck($iNetwork,$iStation,$iRecursion=0)
	{
		if($iRecursion>20){
			throw new Exception("No ack recevied in 20 packets");
		}

		$aReadSet=$this->aAllReadableSockets;
		$aWriteSet=NULL;
		$aAllExpSockets=array(NULL);
		$aExpSet=$aAllExpSockets;

		//Wait for an aun packet
		$iSockets = @stream_select($aReadSet,$aWriteSet,$aExpSet,1);

		if($iSockets!=FALSE){
			//Step through each socket we need to read from
			foreach($aReadSet as  $iReadSocket){
				if($iReadSocket == $this->oAunSocket){
					//We've received an AUN packet process it 
					$oAunPacket = new AunPacket();
					$sUdpData= stream_socket_recvfrom($this->oAunSocket,1500,0,$sHost);
					$oAunPacket->setSourceIP($sHost);
					$this->oLogger->debug("filestore: Aun packet recvieved from ".$sHost);	

					//Decode the AUN packet
					$oAunPacket->setDestinationIP(config::getValue('local_ip'));
					$oAunPacket->decode($sUdpData);
					
					//Send an ack for the AUN packet if needed
					$sAck = $oAunPacket->buildAck();
					if(strlen($sAck)>0){
						$this->oLogger->debug("filestore: Sending Ack packet");
						stream_socket_sendto($this->oAunSocket,$sAck,0,$sHost);
					}
				
					//Build an econet packet from the AUN packet and see if it's the direct stream we are waiting for 
					if($oAunPacket->getPacketType()=='Ack'){
						$oEconetPacket = $oAunPacket->buildEconetPacket();
						if($oEconetPacket->getSourceNetwork()==$iNetwork AND $oEconetPacket->getSourceStation()==$iStation){
							//It's the frame we are waiting for.
							return $oEconetPacket;
						}else{
							//Not the direct data stream we are waiting for but the packet must be processed in the normal way
							$this->processEconetPacket($oEconetPacket);
							//Go back to listening for the direct stream
							return $this->waitForAck($iNetwork,$iStation,$iRecursion+1);
						}
					}else{
						return $this->waitForAck($iNetwork,$iStation,$iRecursion+1);
					}
				}
			}
		
		}else{
			throw new Exception("Waiting for ack timeout");
		}

	}
}
