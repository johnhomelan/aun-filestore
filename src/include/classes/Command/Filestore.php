#!/usr/bin/php
<?php
declare(ticks = 1);

//If we are running the installed version update the include path so the installed version of any
//classes will run first.
if(__DIR__ == '/usr/sbin'){
	define("AUNFILESTORED_LIB","/usr/share/aun-filestored");
	if(is_dir(AUNFILESTORED_LIB)){
		ini_set("include_path", AUNFILESTORED_LIB .PATH_SEPARATOR . get_include_path());
	}
}

include_once('include/system.inc.php');

/**
 * filestored is the main loop of the application.  It deals with all the socket operations, dispatches and collects
 * data from the main application classes (fileserver, print server), and handles all the initialization tasks.
*/
class filestored  {

	/**
	 * Hold the single instance of the fileserver object
	 *
	 * @var object fileserver
	*/
	protected $oFileserver = NULL;

	/**
	 * Hold the single instance of the printserver object
	 *
	 * @var object printserver
	*/
	protected $oPrintServer = NULL;


	/**
	 * Hold the single instance of the birdge object
	 *
	 * @var ojbect bridge
	*/
	protected $oBridge = NULL;

	/**
	 * The handle for the AUN udp socket
	 *
	 * @var handle
	*/
	protected $oAunSocket = NULL;
	
	/**
	 * An array of all the readable sockets currently held open by the application
	 *	
	 * @var array
	*/
	protected $aAllReadableSockets = array();

	/**
	 * The starting method for the application
	 *
	 * This does not contain the main loop, it just initializes the system
	 * then jumps to the main loop.
	*/
	function main()
	{
		$aOpts = getopt("dp:c:h");
		$bDaemonize = FALSE;
		$sPidFile = "";
		foreach($aOpts AS $sOpt => $sValue){
			switch($sOpt){
				case "d":
					$bDaemonize = TRUE;
					break;
				case "p":
					$sPidFile = $sValue;
					break;
				case "c":
					safe_define('CONFIG_CONF_FILE_PATH',$sValue);
					break;
				case "h":
					$this->usage();
					break;
			}
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
			$this->oFileserver = new fileserver($this);
			$this->oFileserver->init();

			//Create a print server instance and start it
			$this->oPrintServer = new printserver($this);
			$this->oPrintServer->init();

			//Create the bridge instance and start it
			$this->oBridge = new bridge($this);
			$this->oBridge->init();

			//Load the aunmap that maps econet network/station numbers to ip addresses and ports
			aunmap::loadMap();
			
			//Setup listening sockets
			$this->bindSockets();
		}catch(Exception $oException){
			//Failed to initialize log and exit none 0
			logger::log($oException->getMessage(),LOG_INFO);
			logger::log("Un-abale to initialize the server, shutting down.",LOG_INFO);
			exit(1);
		}

		//Enter main loop
		logger::log("Starting primary loop.",LOG_DEBUG);
		$this->loop();
	}

	/**
	 * Displays how to use the command
	 *
	*/
	public function usage()
	{
		echo "\n";
		echo "filestored\n";
		echo "----------\n";
		echo "-h\tShows the help message\n";
		echo "-d\tCauses the filestored to daemonize and drop into the background, otherwise the process contiunes to run in the forground\n";
		echo "-p <file>\tCause filestored to write the PID of the deamonized process to a file\n";
		echo "-c <config_dir>\tProvides the path to the config directory to be used (any files ending in .conf will be read from this directory)\n";
		echo "\n";
		exit();
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
				logger::log("Got sig alarm",LOG_DEBUG);
				security::houseKeeping();
				vfs::houseKeeping();
				$this->registerNextAlarm();
				break;
			case SIGTERM:
				logger::log("Shutting down filestored",LOG_INFO);
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
					logger::log("Got data",LOG_DEBUG);
					//Step through each socket we need to read from
					foreach($aReadSet as  $iReadSocket){
						if($iReadSocket == $this->oAunSocket){
							//We've received an AUN packet process it 
							$this->processAunPacket($this->oAunSocket);
						}
					}
				}
			}catch(Exception $oException){
				logger::log($oException->getMessage(),LOG_ERR);
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
		logger::log("filestore: Aun packet recvieved from ".$sHost,LOG_DEBUG);	
		$oAunPacket->setSourceIP($sHost);

		//Decode the AUN packet
		$oAunPacket->setDestinationIP(config::getValue('local_ip'));
		$oAunPacket->decode($sUdpData);
		
		//Send an ack for the AUN packet if needed
		$sAck = $oAunPacket->buildAck();
		if(strlen($sAck)>0){
			logger::log("filestore: Sending Ack packet",LOG_DEBUG);
			stream_socket_sendto($oSocket,$sAck,0,$sHost);
		}
		logger::log("filestore: ".$oAunPacket->toString(),LOG_DEBUG);
	
		//Build an econet packet from the AUN packet an pass it to be processed
		switch($oAunPacket->getPacketType()){
			case 'Immediate':
			case 'Unicast':
				$oEconetPacket = $oAunPacket->buildEconetPacket();
				$this->processEconetPacket($oEconetPacket);
				break;
			case 'Broadcast':
				logger::log("filestore: Received broadcast packet ",LOG_DEBUG);
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
				$this->PrinterServerData($oEconetPacket);
				break;
			default:
				logger::log("filestore: Recived packet on un-handle port (".$sPort.")",LOG_DEBUG);
				logger::log("filestore: ".$oEconetPacket->toString(),LOG_DEBUG);
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
		$oFsRequest = new fsrequest($oEconetPacket);
		$this->oFileserver->processRequest($oFsRequest);
		$aReplies = $this->oFileserver->getReplies();
		foreach($aReplies as $oReply){
			logger::log("filestore: Sending econet reply",LOG_DEBUG);
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
		$oPrintServerEnquiry = new printserverenquiry($oEconetPacket);
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
		$oPrintServerData = new printserverdata($oEconetPacket);
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
			$oBridgeRequest = new bridgerequest($oEconetPacket);
			$this->oBridge->processRequest($oEconetPacket);
			$this->sendBridgeReplies();
		}catch(Exception $oException){
			logger::log("bridge: ".$oException->getMessage(),LOG_DEBUG);
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
			logger::log("filestore: Sending econet reply",LOG_DEBUG);
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
			logger::log("bridge: Sending econet reply",LOG_DEBUG);
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
		$sIP = aunmap::ecoAddrToIpAddr($oReplyEconetPacket->getDestinationNetwork(),$oReplyEconetPacket->getDestinationStation());
		if(strlen($sIP)>0){
			$sPacket = $oReplyEconetPacket->getAunFrame();
			logger::log("filestore: AUN packet to ".$sIP." (".implode(':',unpack('C*',$sPacket)).")",LOG_DEBUG);
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
					logger::log("filestore: Aun packet recvieved from ".$sHost,LOG_DEBUG);	

					//Decode the AUN packet
					$oAunPacket->setDestinationIP(config::getValue('local_ip'));
					$oAunPacket->decode($sUdpData);
					
					//Send an ack for the AUN packet if needed
					$sAck = $oAunPacket->buildAck();
					if(strlen($sAck)>0){
						logger::log("filestore: Sending Ack packet",LOG_DEBUG);
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
					logger::log("filestore: Aun packet recvieved from ".$sHost,LOG_DEBUG);	

					//Decode the AUN packet
					$oAunPacket->setDestinationIP(config::getValue('local_ip'));
					$oAunPacket->decode($sUdpData);
					
					//Send an ack for the AUN packet if needed
					$sAck = $oAunPacket->buildAck();
					if(strlen($sAck)>0){
						logger::log("filestore: Sending Ack packet",LOG_DEBUG);
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
							$this->processEconetPacket();
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

$oApp = new filestored();
$oApp->main();
