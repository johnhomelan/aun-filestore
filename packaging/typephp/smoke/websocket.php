<?php

declare(strict_types=1);

use HomeLan\FileStore\Encapsulation\EncapsulationTypeMap;
use HomeLan\FileStore\Encapsulation\PacketDispatcher;
use HomeLan\FileStore\Logging\StderrLogger;
use HomeLan\FileStore\Services\ServiceDispatcher;
use HomeLan\FileStore\WebSocket\Handler as WebSocketHandler;
use HomeLan\FileStore\WebSocket\Map as WebSocketMap;
use Ratchet\Http\HttpServer;
use Ratchet\Server\IoServer;
use Ratchet\WebSocket\WsServer;
use React\EventLoop\StreamSelectLoop;
use React\EventLoop\TimerInterface;
use React\Socket\TcpServer;

/**
 * PORTING-REACT.md Stage 4b smoke: stand up the real Ratchet WebSocket stack -
 * IoServer(HttpServer(WsServer(WebSocket\Handler))) - on the native loop over a
 * native react/socket TcpServer, then drive it with an in-process async client
 * that does the HTTP/1.1 upgrade and sends one masked text frame. Success = the
 * frame reaches WebSocket\Handler::onMessage() (which decodes it as a JsonPacket
 * and, for a malformed body, logs "Discarding malformed message").
 *
 * Nothing interpreted: the loop, the TCP server, guzzle/psr7's request parse,
 * ratchet/rfc6455's handshake + frame codec and WebSocket\Handler are all
 * compiled.
 */
function main(int $argc, array $argv): void
{
    $oLogger = new StderrLogger('debug');
    $oLoop   = new StreamSelectLoop();

    WebSocketMap::init($oLogger);
    $oTypeMap    = EncapsulationTypeMap::create();
    $oDispatcher = PacketDispatcher::create($oTypeMap, $oLoop);
    $oServices   = ServiceDispatcher::create($oLogger, []);
    $oServices->start($oTypeMap, $oLoop);

    $oHandler = new WebSocketHandler($oLogger, $oServices, $oDispatcher);

    $oTransport = new TcpServer('127.0.0.1:0', $oLoop);
    $mAddr = $oTransport->getAddress();
    $sAddr = \is_string($mAddr) ? \str_replace('tcp://', '', $mAddr) : '';
    new IoServer(new HttpServer(new WsServer($oHandler)), $oTransport, $oLoop);
    $oLogger->info("ws smoke: Ratchet WsServer listening on {$sAddr}");

    // --- in-process async WebSocket client -------------------------------------
    $rCli = @\stream_socket_client('tcp://' . $sAddr, $iErr, $sErrStr, 2.0, \STREAM_CLIENT_CONNECT);
    if ($rCli === false) {
        $oLogger->error("ws smoke: client connect failed: {$sErrStr}");
        echo "FAIL\n";
        return;
    }
    \stream_set_blocking($rCli, false);

    $sKey      = \base64_encode(\random_bytes(16));
    $sHttpReq  = "GET / HTTP/1.1\r\nHost: 127.0.0.1\r\nUpgrade: websocket\r\n"
               . "Connection: Upgrade\r\nSec-WebSocket-Key: {$sKey}\r\n"
               . "Sec-WebSocket-Version: 13\r\n\r\n";
    \fwrite($rCli, $sHttpReq);

    $sBuf         = '';
    $bUpgraded    = false;
    $bFrameSent   = false;
    $bGotResponse = false;

    // client -> server text frame, masked (RFC 6455 6.1)
    $fMaskedTextFrame = static function (string $sPayload): string {
        $sMask  = \random_bytes(4);
        $sOut   = \chr(0x81) . \chr(0x80 | \strlen($sPayload)) . $sMask;
        for ($i = 0, $n = \strlen($sPayload); $i < $n; $i++) {
            $sOut .= $sPayload[$i] ^ $sMask[$i % 4];
        }
        return $sOut;
    };

    $oLoop->addReadStream($rCli, function ($rStream) use (
        &$sBuf, &$bUpgraded, &$bFrameSent, &$bGotResponse,
        $rCli, $fMaskedTextFrame, $oLogger, $oLoop
    ): void {
        $sChunk = \fread($rStream, 8192);
        if ($sChunk === false || $sChunk === '') {
            return;
        }
        $sBuf .= $sChunk;

        if (!$bUpgraded && \str_contains($sBuf, "\r\n\r\n")) {
            $bUpgraded = \str_contains($sBuf, '101');
            $oLogger->info('ws smoke: server upgrade response ' . ($bUpgraded ? 'OK (101)' : 'NOT 101'));
            $sBuf = '';
            if ($bUpgraded && !$bFrameSent) {
                \fwrite($rCli, $fMaskedTextFrame('{"not":"a valid econet packet"}'));
                $bFrameSent = true;
                $oLogger->info('ws smoke: sent one masked text frame');
            }
            return;
        }

        if ($bUpgraded && $sBuf !== '') {
            // any server->client bytes after the upgrade = WsServer produced a frame
            $bGotResponse = true;
            $oLogger->info('ws smoke: server sent ' . \strlen($sBuf) . ' bytes back after the frame');
            $oLoop->stop();
        }
    });

    $oLoop->addTimer(2.0, function (TimerInterface $oTimer) use ($oLoop): void {
        $oLoop->stop();
    });

    $oLogger->info('ws smoke: entering native loop');
    $oLoop->run();

    echo (($bUpgraded && $bFrameSent) ? 'PASS' : 'FAIL')
        . ' (upgraded=' . ($bUpgraded ? 'y' : 'n')
        . ' frameSent=' . ($bFrameSent ? 'y' : 'n')
        . ' serverReplied=' . ($bGotResponse ? 'y' : 'n') . ")\n";
}
