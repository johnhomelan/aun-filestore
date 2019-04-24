<?php

namespace HomeLan\FileStore\Console;

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;

class SingleCommandApplication extends Application {

        protected $oCommand;

	protected $sCommandName;

        public function __construct(Command $oCommand)
        {
                $this->oCommand = $oCommand;
                $this->sCommandName = $oCommand->getName();
                parent::__construct();
        }

        /**
         * Gets the default command that should be run
         *
         */
        protected function getDefaultCommands():array
        {
                $aCommands = parent::getDefaultCommands();
                $aCommands[] = $this->oCommand;
                return $aCommands;
        }

        /**
         * Gets the diffinition of the single application
	 *
         */
        public function getDefinition(): \Symfony\Component\Console\Input\InputDefinition
        {
                $oDefinition = parent::getDefinition();
                $oDefinition->setArguments();

                return $oDefinition;
        }

        /**
         * Gets the name of the command
         *
         */
        protected function getCommandName(InputInterface $input): string
        {
                return $this->oCommand->getName();
        }
}
