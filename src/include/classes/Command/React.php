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

use Symfony\Component\HttpKernel\HttpKernel;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException; 
use React\EventLoop\LoopInterface;
use React\EventLoop\Factory as ReactFactory;
use React\Datagram\Factory as DatagramFactory;
use React\Socket\UnixConnector;
use React\Socket\ConnectionInterface;

use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;

use HomeLan\FileStore\Services\ServiceDispatcher;
use HomeLan\FileStore\WebSocket\Handler as WebSocketHandler;
use HomeLan\FileStore\Piconet\Handler as PiconetHandler;
use HomeLan\FileStore\Piconet\Map as PiconetMap;
use HomeLan\FileStore\Aun\Map; 
use HomeLan\FileStore\Aun\AunPacket; 
use HomeLan\FileStore\Aun\Handler as AunHandler; 
use HomeLan\FileStore\Authentication\Security; 
use HomeLan\FileStore\Vfs\Vfs; 
use HomeLan\FileStore\Admin\Kernel;

use HomeLan\FileStore\Encapsulation\PacketDispatcher;
use HomeLan\FileStore\Encapsulation\EncapsulationTypeMap;

use HomeLan\FileStore\React\UnixDeviceConnector;
 
use config;
use Exception;

/**
 * filestored is the main loop of the application.  It deals with all the socket operations, dispatches and collects
 * data from the main application classes (fileserver, print server), and handles all the initialization tasks.
*/
class React extends Command {
	/*
 	 * The delay be building a AUN packet, and dispatching it to the network.
 	 *
 	 * BBC clients can't cope with the server responding with a ack too quickly,
 	 * they are simply not ready for it in time, so the packet effectivly gets dropped.
 	 *
 	 * This delay prevents the server replying too quickly.
 	*/ 
	const AUN_PKT_DELAY  = 0.04;


	protected static $defaultDescription = 'Start the file, print and bridge services';
 public function __construct(private readonly \Psr\Log\LoggerInterface $oLogger, private readonly ServiceDispatcher $oServices)
	{
		parent::__construct();
	}

	/**
	 * The starting method for the application
	 *
	 * This does not contain the main loop, it just initializes the system
	 * then jumps to the main loop.
	*/
	protected function execute(InputInterface $oInput, OutputInterface $oOutput): int
	{
		$bDaemonize = FALSE;
		$sPidFile = "";

		$this->oLogger->info("Config input is ".$oInput->getOption('config'));
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

		//Initialize the security system
		Security::init($this->oLogger);


		//Initialize the system
		try {
			//Setup signle handler
			pcntl_signal(SIGCHLD,$this->sigHandler(...));
			pcntl_signal(SIGTERM,$this->sigHandler(...));


		}catch(Exception $oException){
			//Failed to initialize log and exit none 0
			$this->oLogger->debug($oException->getMessage());
			$this->oLogger->emergency("Un-abale to initialize the server, shutting down.");
			exit(1);
		}

		$this->MainLoop();
  		return \Symfony\Component\Console\Command\Command::SUCCESS;
	}

	/**
	 * Creates the primary react php loop, and starts it 
	 *
	*/
	private function MainLoop():void
	{

		//Setup the main react loop
		$oLoop = ReactFactory::create();
		$oLogger = $this->oLogger;
		$oEncapsulationTypeMap = EncapsulationTypeMap::create();
		
		$this->oLogger->info("core: Using ".$oLoop::class." as the primary event loop handler");


		//Setup the handler for all the encaptulation types (outbound packets)
		$oPacketDispatcher = PacketDispatcher::create($oEncapsulationTypeMap, $oLoop);

		//Create the AUN packet handler
		$oAunHandler = $this->aunService($oLoop, $oPacketDispatcher);
		Map::init($this->oLogger,$oAunHandler);

		//Setup the Piconet interface handler 
		$oPiconet = $this->piconetService($oLoop,$oPacketDispatcher);
		PiconetMap::init($oLogger, $oPiconet);


		//Setup the websocket handler 
		$this->websocketService($oLoop,$oPacketDispatcher);

		//Setup the Web admin handler
		$this->adminService($oLoop);

		
		//Send any outstanding replies, normally its one request in one reply out.  However some services (e.g. File) have direct streams that can generate 
		//mutiple replies to an initial request.
		$oServices = $this->oServices;
		$oServices->start($oEncapsulationTypeMap, $oLoop);
		$oLoop->addPeriodicTimer(1, function(\React\EventLoop\Timer\Timer $oTimer) use ($oPacketDispatcher, $oServices ) {
			//Send any messages for the services
			$aReplies = $oServices->getReplies();
			foreach($aReplies as $oReply){
				$oPacketDispatcher->sendPacket($oReply);
			}
		});
		
		//Run regular house keeping tasks	
		$oLoop->addPeriodicTimer(config::getValue('housekeeping_interval'), function(\React\EventLoop\Timer\Timer $oTimer) use ($oServices, $oLogger) {
			$oLogger->debug("Running house keeping tasks");
			Security::houseKeeping();
			vfs::houseKeeping();
			$oServices->houseKeeping();
	
		});

		$oLoop->addPeriodicTimer(self::AUN_PKT_DELAY,function(\React\EventLoop\Timer\Timer $oTimer) use ($oAunHandler){
			$oAunHandler->timer();
		});

		//Enter main loop
		$this->oLogger->debug("Starting primary loop.");
		$oLoop->run();
	}

	/**
	 * Displays how to use the command
	 *
	*/
	protected function configure(): void
	{
		$sHelp =<<<EOF
Start the file, print and bridge services
EOF;

		parent::configure();
		$this->setName('filestore')
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
	public function sigHandler(int $iSigno): void
	{
		switch($iSigno){
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
	  * Adds all the AUN handling services to the event loop
	*/
	private function aunService(LoopInterface $oLoop, PacketDispatcher $oPacketDispatcher):AunHandler
	{

		//Add udp handling for AUN 
		$oDatagramFactory = new DatagramFactory($oLoop);
		$oAunHandler = new AunHandler($this->oLogger, $this->oServices, $oPacketDispatcher);

		$oDatagramFactory->createServer(config::getValue('aun_listen_address').':'.config::getValue('aun_listen_port'))
	  		->then(function (\React\Datagram\Socket $oAunServer) use ($oAunHandler) {
				$oAunServer->on('message', function($sMessage, $sSrcAddress, $oSocket) use ($oAunHandler){
					$oAunHandler->receive($sMessage, $sSrcAddress, $oSocket->getLocalAddress());
				});
				$oAunHandler->setSocket($oAunServer);
			});
		return $oAunHandler;
	}

	/**
	 * Adds all the websocket handling services to the event loop
	*/
	public function websocketService(LoopInterface $oLoop,PacketDispatcher $oPacketDispatcher):void
	{

		//Add udp handling for AUN 
		$oWebSocketTransport = new \React\Socket\SocketServer(config::getValue('websocket_listen_address').':'.config::getValue('websocket_listen_port'));

		$oServices = $this->oServices;
		$oLogger = $this->oLogger;
		$oWebSocketHandler = new WebSocketHandler($this->oLogger, $oServices, $oPacketDispatcher);

		$oWebsocketServer = new IoServer(
			new HttpServer(
				new WsServer(
					$oWebSocketHandler
				)
			),
			$oWebSocketTransport,
			$oLoop
		);
	}

	/**
	 * Adds all the piconet handling services to the event loop
	*/
	public function piconetService(LoopInterface $oLoop, PacketDispatcher $oPacketDispatcher):PiconetHandler
	{

		$oPiconet = new UnixDeviceConnector($oLoop);
		$oPiconetHandler = new PiconetHandler($this->oLogger, $this->oServices, $oPacketDispatcher);
		$oPiconet->connect('file:///'.config::getValue('piconet_device'))->then(function (ConnectionInterface $oConnection) use ($oPiconetHandler){

			$oPiconetHandler->onOpen($oConnection);
			$oPiconetHandler->onConnect();
			

			$oConnection->on('data',function ($sMessage) use ($oPiconetHandler) {
				$oPiconetHandler->onMessage($sMessage);
			});
			$oConnection->on('close',function () use ($oPiconetHandler) {
				$oPiconetHandler->onClose();
			});
			$oConnection->on('error', function(\Exception $e) use ($oPiconetHandler){
				$oPiconetHandler->onError($e);
			});
		});

		return $oPiconetHandler;

	}


	/** 
	  * Seetup the Admin web interface service
	  *
	  * @param LoopInterface $oLoop The loop to add the service to
	*/
	public function adminService(LoopInterface $oLoop):void
	{
		$oKernel = new Kernel('prod', false);
		$oLogger = $this->oLogger;
		$callback = function (\Psr\Http\Message\ServerRequestInterface $oRequest) use ($oKernel, $oLogger) {
			$sMethod = $oRequest->getMethod();
			$aHeaders = $oRequest->getHeaders();
			$sContent = $oRequest->getBody();
			$oLogger->info("Admin page request ".$oRequest->getUri()->getPath());

			$aPost = [];
			if (in_array(strtoupper($sMethod), ['POST', 'PUT', 'DELETE', 'PATCH']) &&
				isset($aHeaders['Content-Type']) && (str_starts_with($aHeaders['Content-Type'], 'application/x-www-form-urlencoded')) //@phpstan-ignore-line
			) {
				parse_str($sContent, $result);
			}
			$sfRequest = new \Symfony\Component\HttpFoundation\Request(
				$oRequest->getQueryParams(),
				$aPost,
				[],
				$oRequest->getCookieParams(), // To get the cookies, we'll need to parse the aHeaders
				$oRequest->getUploadedFiles(),
				[], // Server is partially filled a few lines below
				$sContent
			);
			$sfRequest->setMethod($sMethod);
			$sfRequest->headers->replace($aHeaders);
			$sfRequest->server->set('REQUEST_URI', $oRequest->getUri()->getPath());
			if (isset($aHeaders['Host'])) {
				$sfRequest->server->set('SERVER_NAME', explode(':', (string) $aHeaders['Host'][0]));
			}
			
			try {
				$sfResponse = $oKernel->handle($sfRequest);
			}catch(NotFoundHttpException){
				$oLogger->info("Admin page not found (".$oRequest->getUri()->getPath().")");
				return  new  \React\Http\Message\Response(
						404,
						[],
						"Page \"".$oRequest->getUri()->getPath()."\" not found.");
			}catch(\Exception $oException){
				$oLogger->info("Error: ".$oException->getMessage());
				throw $oException;
			}
			$oResponse = new \React\Http\Message\Response(
						200,
						$sfResponse->headers->all(),
						$sfResponse->getContent());
			$oKernel->terminate($sfRequest, $sfResponse);
			return $oResponse;
		};

		$oHttpSocket = new \React\Socket\Server(config::getValue('webadmin_listen_address').':'.config::getValue('webadmin_listen_port'),$oLoop);
		$oHttpServer = new \React\Http\HttpServer($callback);
		$oHttpServer->listen($oHttpSocket);
	}

	/**
	 * Cause the program to daemonize 
	 *
	 * The current pid starts a child (that continues and becomes the running server) while the parent pid exits
	 *
	 * @param string $sPidFile The file to write the pid of the child to
	*/
	function daemonize(string $sPidFile): void
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

}
