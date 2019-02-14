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

use HomeLan\FileStore\Services\ServiceDispatcher;
use HomeLan\FileStore\Aun\Map; 
use HomeLan\FileStore\Aun\AunPacket; 
use HomeLan\FileStore\Authentication\Security; 
use HomeLan\FileStore\Vfs\Vfs; 
 
use config;
use Exception;

/**
 * filestored is the main loop of the application.  It deals with all the socket operations, dispatches and collects
 * data from the main application classes (fileserver, print server), and handles all the initialization tasks.
*/
class React extends Command {

	private $oLogger; 
	private $oServices;

	public function __construct(\Psr\Log\LoggerInterface $oLogger, ServiceDispatcher $oServices)
	{
		$this->oServices = $oServices;
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

			Map::init($this->oLogger);

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

}
