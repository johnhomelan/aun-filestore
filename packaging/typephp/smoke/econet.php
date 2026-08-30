<?php

declare(strict_types=1);

use HomeLan\FileStore\Aun\Handler as AunHandler;
use HomeLan\FileStore\Aun\Map as AunMap;
use HomeLan\FileStore\Authentication\Security;
use HomeLan\FileStore\WebSocket\Map as WebSocketMap;
use HomeLan\FileStore\Encapsulation\EncapsulationTypeMap;
use HomeLan\FileStore\Encapsulation\PacketDispatcher;
use HomeLan\FileStore\Logging\StderrLogger;
use HomeLan\FileStore\Services\Provider\BeebTerm;
use HomeLan\FileStore\Services\Provider\Bridge;
use HomeLan\FileStore\Services\Provider\EcoSyslog;
use HomeLan\FileStore\Services\Provider\FileServer;
use HomeLan\FileStore\Services\Provider\IPv4;
use HomeLan\FileStore\Services\Provider\MaceMail;
use HomeLan\FileStore\Services\Provider\PrintServer;
use HomeLan\FileStore\Services\Provider\ProxyProvider;
use HomeLan\FileStore\Services\Provider\Teletext;
use HomeLan\FileStore\Services\Provider\Torchnet;
use HomeLan\FileStore\Services\Provider\Viewdata;
use HomeLan\FileStore\Services\ServiceDispatcher;
use React\Datagram\Socket as DatagramSocket;
use React\EventLoop\StreamSelectLoop;
use React\EventLoop\TimerInterface;

/**
 * PORTING-REACT.md Stage 3 / 3b smoke: boot the project's own encapsulation +
 * service-dispatch code on the native ReactPHP loop with the full daemon
 * provider set (same list as src/filestored), bind the AUN UDP socket, and push
 * real Econet packets through:
 *
 *   - one EcoSyslog log record  (port 0xB6) -> EcoSyslog::unicastPacketIn()
 *   - one FS "*I AM SYST" OSCLI  (port 0x99) -> FileServer::unicastPacketIn()
 *                                            -> FsRequest decode -> Security -> reply
 *
 * Nothing interpreted on that path - the loop, the datagram socket, the AUN
 * decoder, the encapsulation map, the service dispatcher and every provider are
 * compiled. (The dynamic auth/VFS plugin *loaders* fall back to Zend at
 * boot time; the packet handlers themselves are native.)
 */
function main(int $argc, array $argv): void
{
    $oLogger = new StderrLogger('debug');
    $oLogger->info('econet smoke: booting');

    $oLoop = new StreamSelectLoop();
    $oLogger->info('econet smoke: loop is ' . \get_class($oLoop));

    Security::init($oLogger);
    // EncapsulationTypeMap::getType() consults WebSocketMap on every outbound
    // packet; give it a logger even though this smoke runs no websocket service.
    WebSocketMap::init($oLogger);

    $oTypeMap    = EncapsulationTypeMap::create();
    $oDispatcher = PacketDispatcher::create($oTypeMap, $oLoop);

    $oServices = ServiceDispatcher::create($oLogger, [
        new FileServer($oLogger),
        new PrintServer($oLogger),
        new Bridge($oLogger),
        new IPv4($oLogger),
        new BeebTerm($oLogger),
        new Torchnet($oLogger),
        new MaceMail($oLogger),
        new Teletext($oLogger),
        new Viewdata($oLogger),
        new EcoSyslog($oLogger),
        new ProxyProvider($oLogger, [0xB7]),
    ]);
    $oServices->start($oTypeMap, $oLoop);

    $oAun = new AunHandler($oLogger, $oServices, $oDispatcher);
    AunMap::init($oLogger, $oAun);

    $rSock = @\stream_socket_server('udp://127.0.0.1:0', $iErrno, $sErr, \STREAM_SERVER_BIND);
    if ($rSock === false) {
        $oLogger->error("econet smoke: bind failed: {$sErr}");
        echo "FAIL\n";
        return;
    }
    $sBound = (string) \stream_socket_get_name($rSock, false);
    $oSock  = new DatagramSocket($oLoop, $rSock);
    $oSock->on('message', function (string $sMsg, string $sPeer, DatagramSocket $oS) use ($oAun): void {
        $mLocal = $oS->getLocalAddress();
        $oAun->receive($sMsg, $sPeer, \is_string($mLocal) ? $mLocal : '');
    });
    $oAun->setSocket($oSock);
    $oLogger->info("econet smoke: AUN socket bound on {$sBound}");

    $oLoop->addPeriodicTimer(1.0, function (TimerInterface $oTimer) use ($oDispatcher, $oServices): void {
        foreach ($oServices->getReplies() as $oReply) {
            $oDispatcher->sendPacket($oReply);
        }
    });
    $oLoop->addPeriodicTimer(0.04, function (TimerInterface $oTimer) use ($oAun): void {
        $oAun->timer();
    });

    // --- self-test packets ---------------------------------------------------
    // AUN frame: type(2=Unicast) port ctrl retrans | LE seq | Econet payload
    $fAun = static function (int $iPort, int $iSeq, string $sPayload): string {
        return \chr(2) . \chr($iPort) . \chr(0x00) . \chr(0x00) . \pack('V', $iSeq) . $sPayload;
    };

    // 1) EcoSyslog: severity byte 6 (info) + text
    $sSyslog = $fAun(0xB6, 1, \chr(6) . 'compiled-path alive');

    // 2) FileServer FS request on port 0x99:
    //    replyPort, function 0 (OSCLI), url/dir/lib handle bytes, then "I AM SYST\r"
    $sFsBody = \chr(0x90) . \chr(0x00) . \chr(0x00) . \chr(0x00) . \chr(0x00) . "I AM SYST\r";
    $sFs     = $fAun(0x99, 2, $sFsBody);

    $rClient = @\stream_socket_client('udp://' . $sBound, $iErrno, $sErr);
    if ($rClient === false) {
        $oLogger->error("econet smoke: client socket failed: {$sErr}");
        echo "FAIL\n";
        return;
    }
    $oLoop->addTimer(0.2, function (TimerInterface $oTimer) use ($rClient, $sSyslog): void {
        \fwrite($rClient, $sSyslog);
    });
    $oLoop->addTimer(0.5, function (TimerInterface $oTimer) use ($rClient, $sFs): void {
        \fwrite($rClient, $sFs);
    });

    $oLoop->addTimer(2.0, function (TimerInterface $oTimer) use ($oLoop): void {
        $oLoop->stop();
    });

    $oLogger->info('econet smoke: entering native loop');
    $oLoop->run();
    $oLogger->info('econet smoke: loop exited');
    echo "DONE\n";
}
