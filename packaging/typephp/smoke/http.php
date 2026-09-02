<?php

declare(strict_types=1);

use React\EventLoop\StreamSelectLoop;
use React\EventLoop\TimerInterface;
use React\Http\HttpServer;
use React\Http\Message\Response;
use React\Socket\TcpServer;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Admin HTTP UI plan, Stage 10a smoke: stand up the real react/http stack -
 * HttpServer(handler)->listen(TcpServer) - on the native loop, independent of
 * Ratchet (which only ever does the WebSocket upgrade handshake, never a plain
 * HTTP response). Success = an in-process client sees a real "HTTP/1.1 200"
 * status line and the handler's body back.
 *
 * Nothing interpreted: the loop, the TCP server, ringcentral/psr7's message
 * parsing and react/http's StreamingServer + middleware chain are all compiled.
 */
function main(int $argc, array $argv): void
{
    $oLogger = new HomeLan\FileStore\Logging\StderrLogger('debug');
    $oLoop   = new StreamSelectLoop();

    $oTransport = new TcpServer('127.0.0.1:0', $oLoop);
    $mAddr = $oTransport->getAddress();
    $sAddr = \is_string($mAddr) ? \str_replace('tcp://', '', $mAddr) : '';

    $oHttp = new HttpServer($oLoop, function (ServerRequestInterface $oRequest): Response {
        return new Response(
            200,
            ['Content-Type' => 'text/plain'],
            "ok " . $oRequest->getUri()->getPath() . "\n"
        );
    });
    $oHttp->listen($oTransport);
    $oLogger->info("http smoke: react/http HttpServer listening on {$sAddr}");

    // --- in-process plain HTTP/1.1 client ---------------------------------
    $rCli = @\stream_socket_client('tcp://' . $sAddr, $iErr, $sErrStr, 2.0, \STREAM_CLIENT_CONNECT);
    if ($rCli === false) {
        $oLogger->error("http smoke: client connect failed: {$sErrStr}");
        echo "FAIL\n";
        return;
    }
    \stream_set_blocking($rCli, false);
    \fwrite($rCli, "GET /probe HTTP/1.1\r\nHost: 127.0.0.1\r\nConnection: close\r\n\r\n");

    $sBuf = '';
    $bGotStatusLine = false;
    $bGotBody = false;

    $oLoop->addReadStream($rCli, function ($rStream) use (&$sBuf, &$bGotStatusLine, &$bGotBody, $oLogger, $oLoop): void {
        $sChunk = \fread($rStream, 8192);
        if ($sChunk === false || $sChunk === '') {
            return;
        }
        $sBuf .= $sChunk;

        if (!$bGotStatusLine && \str_contains($sBuf, "\r\n")) {
            $bGotStatusLine = \str_starts_with($sBuf, 'HTTP/1.1 200');
            $oLogger->info('http smoke: status line ' . ($bGotStatusLine ? 'OK (200)' : 'NOT 200: ' . \strtok($sBuf, "\r\n")));
        }
        if (\str_contains($sBuf, "ok /probe")) {
            $bGotBody = true;
            $oLogger->info('http smoke: got response body');
            $oLoop->stop();
        }
    });

    $oLoop->addTimer(2.0, function (TimerInterface $oTimer) use ($oLoop): void {
        $oLoop->stop();
    });

    $oLogger->info('http smoke: entering native loop');
    $oLoop->run();

    echo (($bGotStatusLine && $bGotBody) ? 'PASS' : 'FAIL')
        . ' (statusLine=' . ($bGotStatusLine ? 'y' : 'n')
        . ' body=' . ($bGotBody ? 'y' : 'n') . ")\n";
}
