<?php

declare(strict_types=1);

use HomeLan\FileStore\Logging\StderrLogger;
use React\EventLoop\StreamSelectLoop;
use React\EventLoop\TimerInterface;
use React\Socket\ConnectionInterface;
use React\Socket\Connector;
use React\Socket\TcpServer;

/**
 * PORTING-REACT.md Stage 6 pre-req: prove the native react/dns chain works.
 *
 * Stands up an in-process TcpServer, then uses the full React\Socket\Connector
 * facade (TcpConnector -> DnsConnector -> react/dns Resolver -> HappyEyeBalls)
 * to connect to it by the HOSTNAME "localhost". "localhost" resolves from
 * /etc/hosts via react/dns's HostsFileExecutor with no network - so this
 * exercises the whole resolver chain (Config, Resolver\Factory, the executor
 * stack, Model\Message, Protocol\Parser) as compiled code.
 */
function main(int $argc, array $argv): void
{
    $oLogger = new StderrLogger('debug');
    $oLoop   = new StreamSelectLoop();

    $oServer = new TcpServer('127.0.0.1:0', $oLoop);
    $mAddr = $oServer->getAddress();
    $sHostPort = \is_string($mAddr) ? \str_replace('tcp://', '', $mAddr) : '';
    $iPort = (int) \substr($sHostPort, \strrpos($sHostPort, ':') + 1);
    $oServer->on('connection', function (ConnectionInterface $oConn) use ($oLogger): void {
        $oLogger->info('dns smoke: server accepted a connection from ' . (string) $oConn->getRemoteAddress());
        $oConn->write("hello\n");
    });
    $oLogger->info("dns smoke: TcpServer on 127.0.0.1:{$iPort}, will connect via hostname 'localhost'");

    $bConnected = false;
    $oConnector = new Connector(['tls' => false], $oLoop);
    $oConnector->connect("tcp://localhost:{$iPort}")->then(
        function (ConnectionInterface $oConn) use (&$bConnected, $oLogger, $oLoop): void {
            $bConnected = true;
            $oLogger->info('dns smoke: CONNECTED to localhost via react/dns, remote=' . (string) $oConn->getRemoteAddress());
            $oConn->on('data', function (string $s) use ($oLogger, $oLoop): void {
                $oLogger->info('dns smoke: server said: ' . \trim($s));
                $oLoop->stop();
            });
        },
        function (\Throwable $e) use ($oLogger, $oLoop): void {
            $oLogger->error('dns smoke: connect failed: ' . $e->getMessage());
            $oLoop->stop();
        }
    );

    $oLoop->addTimer(4.0, function (TimerInterface $t) use ($oLoop): void {
        $oLoop->stop();
    });

    $oLoop->run();
    echo ($bConnected ? 'PASS' : 'FAIL') . "\n";
}
