<?php

declare(strict_types=1);

use React\Datagram\Socket as DatagramSocket;
use React\EventLoop\StreamSelectLoop;
use React\EventLoop\TimerInterface;

/**
 * PORTING-REACT.md Stage 2 smoke: bind a UDP socket via react/datagram on the
 * native loop, round-trip one packet to a client socket, confirm the payload.
 *
 * react/datagram's Factory is bypassed - it eagerly builds a react/dns resolver
 * in its constructor, which this build deliberately omits. A daemon binding a
 * fixed local address wants the raw stream_socket_server() anyway.
 */
function main(int $argc, array $argv): void
{
    $oLoop = new StreamSelectLoop();

    $rServer = @\stream_socket_server('udp://127.0.0.1:0', $iErrno, $sErr, \STREAM_SERVER_BIND);
    if ($rServer === false) {
        echo "FAIL: server bind: {$sErr}\n";
        return;
    }
    $sServerAddr = \stream_socket_get_name($rServer, false);
    $oServer = new DatagramSocket($oLoop, $rServer);

    $rClient = @\stream_socket_client('udp://' . $sServerAddr, $iErrno, $sErr);
    if ($rClient === false) {
        echo "FAIL: client socket: {$sErr}\n";
        return;
    }
    $oClient = new DatagramSocket($oLoop, $rClient);

    $sGot = '';

    $oServer->on('message', function (string $sMsg, string $sPeer, DatagramSocket $oSock): void {
        $oSock->send('echo:' . $sMsg, $sPeer);
    });

    $oClient->on('message', function (string $sMsg, string $sPeer, DatagramSocket $oSock) use (&$sGot, $oLoop): void {
        $sGot = $sMsg;
        $oLoop->stop();
    });

    $oClient->send('ping');

    $oLoop->addTimer(2.0, function (TimerInterface $oTimer) use ($oLoop): void {
        $oLoop->stop();
    });

    $oLoop->run();

    echo "client received: '{$sGot}'\n";
    echo ($sGot === 'echo:ping' ? "PASS" : "FAIL") . "\n";
}
