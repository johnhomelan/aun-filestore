<?php

/*
 * @group unit-tests
 *
 * Tests for RemoteBridge Connection authentication state machine.
 */

include_once(__DIR__ . '/../../src/include/system.inc.php');
include_once(__DIR__ . '/MockTcpConnection.php');

use PHPUnit\Framework\TestCase;
use Monolog\Logger;
use Monolog\Handler\NullHandler;
use HomeLan\FileStore\RemoteBridge\Connection;
use HomeLan\FileStore\RemoteBridge\Map;
use HomeLan\FileStore\RemoteBridge\BridgePacket;
use HomeLan\FileStore\Messages\EconetPacket;

// ---------------------------------------------------------------------------
// Test helpers
// ---------------------------------------------------------------------------
class RemoteBridgeAuthTest extends TestCase
{
    private Logger $oLogger;

    protected function setUp(): void
    {
        $this->oLogger = new Logger('test');
        $this->oLogger->pushHandler(new NullHandler());
        Map::reset();
    }

    private function makeServerConn(
        MockTcpConnection $oTcp,
        string $sSecret = 'testsecret',
        array $aLocalNetworks = [1],
        ?\Closure $fOnPacket = null
    ): Connection {
        return new Connection(
            $this->oLogger,
            $oTcp,
            'server',
            $sSecret,
            $aLocalNetworks,
            $fOnPacket ?? static function (BridgePacket $p) {},
        );
    }

    private function makeClientConn(
        MockTcpConnection $oTcp,
        string $sSecret = 'testsecret',
        array $aLocalNetworks = [2],
        ?\Closure $fOnPacket = null
    ): Connection {
        return new Connection(
            $this->oLogger,
            $oTcp,
            'client',
            $sSecret,
            $aLocalNetworks,
            $fOnPacket ?? static function (BridgePacket $p) {},
        );
    }

    // -----------------------------------------------------------------------
    // Server state machine
    // -----------------------------------------------------------------------

    public function testServerSendsChallengeonHello(): void
    {
        $oTcp = new MockTcpConnection();
        $oConn = $this->makeServerConn($oTcp);

        $oConn->onData("HELLO " . time() . "\n");

        $aLines = $oTcp->writtenLines();
        $this->assertNotEmpty($aLines);
        $this->assertStringStartsWith('CHALLENGE ', $aLines[0]);
    }

    public function testServerChallengeNonceIs32HexChars(): void
    {
        $oTcp = new MockTcpConnection();
        $oConn = $this->makeServerConn($oTcp);
        $oConn->onData("HELLO " . time() . "\n");

        // CHALLENGE <nonce> <agreed_version> — extract just the nonce
        $sNonce = explode(' ', $oTcp->writtenLines()[0])[1];
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $sNonce);
    }

    public function testServerRejectsStaleTimestamp(): void
    {
        $oTcp = new MockTcpConnection();
        $oConn = $this->makeServerConn($oTcp);

        $iStale = time() - 120;
        $oConn->onData("HELLO {$iStale}\n");

        $this->assertTrue($oTcp->bClosed);
        $this->assertStringContainsString('AUTH_FAIL', $oTcp->allWritten());
    }

    public function testServerAuthenticatesWithCorrectHmac(): void
    {
        $oTcp = new MockTcpConnection();
        $sSecret = 'mysecret';
        $oConn = $this->makeServerConn($oTcp, $sSecret, [1]);

        $iTs = time();
        $oConn->onData("HELLO {$iTs}\n");

        // CHALLENGE <nonce> <agreed_version> — extract just the nonce
        $sNonce = explode(' ', $oTcp->writtenLines()[0])[1];

        // Compute correct HMAC and send AUTH
        $sHmac = hash_hmac('sha256', $sNonce . ':' . $iTs, $sSecret);
        $oTcp->aWritten = [];
        $oConn->onData("AUTH {$sHmac}\n");

        $this->assertTrue($oConn->isAuthenticated());
        $this->assertStringContainsString('AUTH_OK', $oTcp->allWritten());
    }

    public function testServerSendsNetworksAfterAuth(): void
    {
        $oTcp = new MockTcpConnection();
        $sSecret = 'mysecret';
        $oConn = $this->makeServerConn($oTcp, $sSecret, [1, 3]);

        $iTs = time();
        $oConn->onData("HELLO {$iTs}\n");
        // CHALLENGE <nonce> <agreed_version> — extract just the nonce
        $sNonce = explode(' ', $oTcp->writtenLines()[0])[1];
        $sHmac = hash_hmac('sha256', $sNonce . ':' . $iTs, $sSecret);
        $oTcp->aWritten = [];
        $oConn->onData("AUTH {$sHmac}\n");

        $aLines = $oTcp->writtenLines();
        $sNetworksLine = array_values(array_filter($aLines, fn($l) => str_starts_with($l, 'NETWORKS')))[0] ?? '';
        $this->assertStringStartsWith('NETWORKS ', $sNetworksLine);
        $this->assertStringContainsString('1', $sNetworksLine);
        $this->assertStringContainsString('3', $sNetworksLine);
    }

    public function testServerRejectsWrongHmac(): void
    {
        $oTcp = new MockTcpConnection();
        $oConn = $this->makeServerConn($oTcp, 'correctsecret', [1]);

        $iTs = time();
        $oConn->onData("HELLO {$iTs}\n");

        $oTcp->aWritten = [];
        $oConn->onData("AUTH " . str_repeat('0', 64) . "\n");

        $this->assertFalse($oConn->isAuthenticated());
        $this->assertTrue($oTcp->bClosed);
        $this->assertStringContainsString('AUTH_FAIL', $oTcp->allWritten());
    }

    public function testServerRejectsWrongCommand(): void
    {
        $oTcp = new MockTcpConnection();
        $oConn = $this->makeServerConn($oTcp);

        $oConn->onData("GARBAGE\n");

        $this->assertFalse($oConn->isAuthenticated());
        $this->assertTrue($oTcp->bClosed);
    }

    // -----------------------------------------------------------------------
    // Client state machine
    // -----------------------------------------------------------------------

    public function testClientSendsHelloOnConnect(): void
    {
        $oTcp = new MockTcpConnection();
        $oConn = $this->makeClientConn($oTcp);

        $aLines = $oTcp->writtenLines();
        $this->assertNotEmpty($aLines);
        $this->assertStringStartsWith('HELLO ', $aLines[0]);
    }

    public function testClientHelloContainsTimestamp(): void
    {
        $iBefore = time();
        $oTcp = new MockTcpConnection();
        $oConn = $this->makeClientConn($oTcp);
        $iAfter = time();

        $sHello = $oTcp->writtenLines()[0];
        $iTs = (int) substr($sHello, strlen('HELLO '));
        $this->assertGreaterThanOrEqual($iBefore, $iTs);
        $this->assertLessThanOrEqual($iAfter, $iTs);
    }

    public function testClientRespondsToChallenge(): void
    {
        $oTcp = new MockTcpConnection();
        $sSecret = 'testsecret';
        $oConn = $this->makeClientConn($oTcp, $sSecret, [2]);

        // Get the HELLO timestamp that the client sent
        $iTs = (int) substr($oTcp->writtenLines()[0], strlen('HELLO '));
        $sNonce = bin2hex(random_bytes(16));

        $oTcp->aWritten = [];
        $oConn->onData("CHALLENGE {$sNonce}\n");

        $aLines = $oTcp->writtenLines();
        $this->assertNotEmpty($aLines);
        $this->assertStringStartsWith('AUTH ', $aLines[0]);

        // Verify the HMAC is correct
        $sExpected = hash_hmac('sha256', $sNonce . ':' . $iTs, $sSecret);
        $sActual = substr($aLines[0], strlen('AUTH '));
        $this->assertSame($sExpected, $sActual);
    }

    public function testClientAuthenticatesOnAuthOk(): void
    {
        $oTcp = new MockTcpConnection();
        $sSecret = 'testsecret';
        $oConn = $this->makeClientConn($oTcp, $sSecret, [2]);

        // Simulate HELLO → CHALLENGE → AUTH → AUTH_OK
        $iTs = (int) substr($oTcp->writtenLines()[0], strlen('HELLO '));
        $sNonce = bin2hex(random_bytes(16));
        $oConn->onData("CHALLENGE {$sNonce}\n");
        $oTcp->aWritten = [];
        $oConn->onData("AUTH_OK\n");

        $this->assertTrue($oConn->isAuthenticated());
        // Client should send NETWORKS after AUTH_OK
        $this->assertStringContainsString('NETWORKS', $oTcp->allWritten());
    }

    public function testClientClosesOnAuthFail(): void
    {
        $oTcp = new MockTcpConnection();
        $oConn = $this->makeClientConn($oTcp);

        $oConn->onData("CHALLENGE " . bin2hex(random_bytes(16)) . "\n");
        $oTcp->aWritten = [];
        $oConn->onData("AUTH_FAIL bad_hmac\n");

        $this->assertFalse($oConn->isAuthenticated());
        $this->assertTrue($oTcp->bClosed);
    }

    // -----------------------------------------------------------------------
    // Full handshake simulation (server + client talking to each other)
    // -----------------------------------------------------------------------

    public function testFullHandshake(): void
    {
        $sSecret = 'sharedsecret';
        $oServerTcp = new MockTcpConnection();
        $oClientTcp = new MockTcpConnection();

        $oServer = $this->makeServerConn($oServerTcp, $sSecret, [1]);
        $oClient = $this->makeClientConn($oClientTcp, $sSecret, [2]);

        // Client → Server: HELLO
        $sClientHello = $oClientTcp->allWritten();
        $oClientTcp->aWritten = [];

        // Server processes HELLO, sends CHALLENGE
        $oServer->onData($sClientHello);
        $sServerChallenge = $oServerTcp->allWritten();
        $oServerTcp->aWritten = [];

        // Client processes CHALLENGE, sends AUTH
        $oClient->onData($sServerChallenge);
        $sClientAuth = $oClientTcp->allWritten();
        $oClientTcp->aWritten = [];

        // Server processes AUTH, sends AUTH_OK + NETWORKS 1
        $oServer->onData($sClientAuth);
        $sServerAuthOk = $oServerTcp->allWritten();
        $oServerTcp->aWritten = [];

        // Client processes AUTH_OK, sends NETWORKS 2
        $oClient->onData($sServerAuthOk);

        $this->assertTrue($oServer->isAuthenticated());
        $this->assertTrue($oClient->isAuthenticated());
    }

    // -----------------------------------------------------------------------
    // Post-auth SEND handling
    // -----------------------------------------------------------------------

    public function testAuthenticatedConnectionDeliversPacket(): void
    {
        $sSecret = 'secret';
        $oServerTcp = new MockTcpConnection();
        $oClientTcp = new MockTcpConnection();

        $aReceived = [];
        $oServer = $this->makeServerConn($oServerTcp, $sSecret, [1], function (BridgePacket $p) use (&$aReceived) {
            $aReceived[] = $p;
        });
        $oClient = $this->makeClientConn($oClientTcp, $sSecret, [2]);

        // Complete handshake
        $oServer->onData($oClientTcp->allWritten()); $oClientTcp->aWritten = [];
        $oClient->onData($oServerTcp->allWritten()); $oServerTcp->aWritten = [];
        $oServer->onData($oClientTcp->allWritten()); $oClientTcp->aWritten = [];
        $oClient->onData($oServerTcp->allWritten()); $oServerTcp->aWritten = [];

        // Client sends a packet to server's network 1
        $oPkt = new EconetPacket();
        $oPkt->setDestinationNetwork(1);
        $oPkt->setDestinationstation(254);
        $oPkt->setSourceNetwork(2);
        $oPkt->setSourceStation(5);
        $oPkt->setPort(0x99);
        $oPkt->setFlags(0);
        $oPkt->setData('test');
        $oClient->send($oPkt);

        // Server receives and dispatches
        $oServer->onData($oClientTcp->allWritten());

        $this->assertCount(1, $aReceived);
        $this->assertSame(1,   $aReceived[0]->getDstNetwork());
        $this->assertSame(254, $aReceived[0]->getDstStation());
    }

    public function testDropsPacketForWrongNetwork(): void
    {
        $sSecret = 'secret';
        $oServerTcp = new MockTcpConnection();
        $oClientTcp = new MockTcpConnection();

        $aReceived = [];
        // Server only serves network 1
        $oServer = $this->makeServerConn($oServerTcp, $sSecret, [1], function (BridgePacket $p) use (&$aReceived) {
            $aReceived[] = $p;
        });
        $oClient = $this->makeClientConn($oClientTcp, $sSecret, [2]);

        // Complete handshake
        $oServer->onData($oClientTcp->allWritten()); $oClientTcp->aWritten = [];
        $oClient->onData($oServerTcp->allWritten()); $oServerTcp->aWritten = [];
        $oServer->onData($oClientTcp->allWritten()); $oClientTcp->aWritten = [];
        $oClient->onData($oServerTcp->allWritten()); $oServerTcp->aWritten = [];

        // Client sends a SEND line directly for network 99 (not server's network)
        $oServerTcp->aWritten = [];
        $oServer->onData("SEND 99 5 2 5 0x99 0 " . base64_encode('x') . "\n");

        $this->assertEmpty($aReceived);
    }

    public function testOnCloseUnregistersFromMap(): void
    {
        $sSecret = 'secret';
        $oServerTcp = new MockTcpConnection();
        $oClientTcp = new MockTcpConnection();

        $oServer = $this->makeServerConn($oServerTcp, $sSecret, [1]);
        $oClient = $this->makeClientConn($oClientTcp, $sSecret, [2]);

        // HELLO → CHALLENGE
        $oServer->onData($oClientTcp->allWritten()); $oClientTcp->aWritten = [];
        // CHALLENGE → AUTH
        $oClient->onData($oServerTcp->allWritten()); $oServerTcp->aWritten = [];
        // AUTH → AUTH_OK + NETWORKS 1
        $oServer->onData($oClientTcp->allWritten()); $oClientTcp->aWritten = [];
        // AUTH_OK → NETWORKS 2 (client sends its networks)
        $oClient->onData($oServerTcp->allWritten()); $oServerTcp->aWritten = [];
        // Server receives NETWORKS 2, registers network 2 in Map
        $oServer->onData($oClientTcp->allWritten()); $oClientTcp->aWritten = [];

        $this->assertTrue(Map::networkKnown(2), 'Network 2 should be registered after NETWORKS exchange');

        $oServer->onClose();
        $this->assertFalse(Map::networkKnown(2), 'Network 2 should be removed after connection close');
    }
}
