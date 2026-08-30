<?php

declare(strict_types=1);

namespace HomeLan\FileStore\Cli;

use HomeLan\FileStore\Command\NewsImport;
use HomeLan\FileStore\Command\TeefaxImport;
use HomeLan\FileStore\Command\TvGuideImport;
use HomeLan\FileStore\Command\WeatherImport;
use HomeLan\FileStore\Command\WebfaxImport;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/*
 * The import commands' execute() is protected. Each Runner subclass exposes it
 * as a public run(), so main-teletext.php can invoke the command's real logic
 * without Symfony's Application / Command::run() machinery.
 */

final class NewsImportRunner extends NewsImport
{
    public function run(InputInterface $oIn, OutputInterface $oOut): int
    {
        return $this->execute($oIn, $oOut);
    }
}

final class TeefaxImportRunner extends TeefaxImport
{
    public function run(InputInterface $oIn, OutputInterface $oOut): int
    {
        return $this->execute($oIn, $oOut);
    }
}

final class TvGuideImportRunner extends TvGuideImport
{
    public function run(InputInterface $oIn, OutputInterface $oOut): int
    {
        return $this->execute($oIn, $oOut);
    }
}

final class WeatherImportRunner extends WeatherImport
{
    public function run(InputInterface $oIn, OutputInterface $oOut): int
    {
        return $this->execute($oIn, $oOut);
    }
}

final class WebfaxImportRunner extends WebfaxImport
{
    public function run(InputInterface $oIn, OutputInterface $oOut): int
    {
        return $this->execute($oIn, $oOut);
    }
}
