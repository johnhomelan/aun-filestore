<?php

/*
 * @group unit-tests
 *
 * Tests for remote bridge protocol version negotiation.
 *
 * Both sides include their supported version list in the HELLO / CHALLENGE messages.
 * The server selects the highest version present in both lists; if there is no overlap
 * it sends VERSION_REJECT and closes the connection.
 */

include_once(__DIR__ . '/../../src/include/system.inc.php');
include_once(__DIR__ . '/MockTcpConnection.php');

use PHPUnit\Framework\TestCase;
use Monolog\Logger;
use Monolog\Handler\NullHandler;
use HomeLan\FileStore\RemoteBridge\Connection;
use HomeLan\FileStore\RemoteBridge\Map;
use HomeLan\FileStore\RemoteBridge\BridgePacket;

class RemoteBridgeVersionTest extends TestCase
{
    private Logger $oLogger;

    protected function setUp(): void
    {
        $this->oLogger = new Logger('test');
        $this->oLogger->pushHandler(new NullHandler());
        Map::reset();
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function makeServer(MockTcpConnection $oTcp, array $aVersions = null): Connection
    {
        return new Connection(
            $this->oLogger, $oTcp, 'server', 'secret', [1],
            static function (BridgePacket $p) {},
            $aVersions,
        );
    }

    private function makeClient(MockTcpConnection $oTcp, array $aVersions = null): Connection
    {
        return new Connection(
            $this->oLogger, $oTcp, 'client', 'secret', [2],
            static function (BridgePacket $p) {},
            $aVersions,
        );
    }

    /**
     * Run a full handshake between a server and client, relaying all messages both ways.
     * Returns [$oServer, $oClient].
     */
    private function doHandshake(?array $aServerVersions = null, ?array $aClientVersions = null): array
    {
        $oServerTcp = new MockTcpConnection();
        $oClientTcp = new MockTcpConnection();
        $oServer = $this->makeServer($oServerTcp, $aServerVersions);
        $oClient = $this->makeClient($oClientTcp, $aClientVersions);

        // HELLO → CHALLENGE (or VERSION_REJECT)
        $oServer->onData($oClientTcp->allWritten()); $oClientTcp->aWritten = [];
        // CHALLENGE → AUTH (or close on VERSION_REJECT)
        $oClient->onData($oServerTcp->allWritten()); $oServerTcp->aWritten = [];
        if (!$oServerTcp->bClosed && !$oClientTcp->bClosed) {
            // AUTH → AUTH_OK + NETWORKS
            $oServer->onData($oClientTcp->allWritten()); $oClientTcp->aWritten = [];
            // AUTH_OK → NETWORKS
            $oClient->onData($oServerTcp->allWritten()); $oServerTcp->aWritten = [];
        }
        return [$oServer, $oClient, $oServerTcp, $oClientTcp];
    }

    // -----------------------------------------------------------------------
    // HELLO wire format
    // -----------------------------------------------------------------------

    public function testHelloIncludesVersionList(): void
    {
        $oTcp = new MockTcpConnection();
        $this->makeClient($oTcp, ['1.0']);

        $sHello = $oTcp->writtenLines()[0];
        $this->assertStringStartsWith('HELLO ', $sHello);
        // HELLO <timestamp> <versions>
        $aParts = explode(' ', $sHello);
        $this->assertCount(3, $aParts, 'HELLO must have 3 space-separated fields');
        $this->assertSame('1.0', $aParts[2]);
    }

    public function testHelloWithMultipleVersionsLists(): void
    {
        $oTcp = new MockTcpConnection();
        $this->makeClient($oTcp, ['1.0', '2.0']);

        $sHello = $oTcp->writtenLines()[0];
        $aParts = explode(' ', $sHello);
        $this->assertSame('1.0,2.0', $aParts[2]);
    }

    public function testDefaultHelloContainsVersion1_0(): void
    {
        $oTcp = new MockTcpConnection();
        $this->makeClient($oTcp); // no custom versions — uses SUPPORTED_VERSIONS

        $sHello = $oTcp->writtenLines()[0];
        $this->assertStringContainsString('1.0', $sHello);
    }

    // -----------------------------------------------------------------------
    // CHALLENGE wire format
    // -----------------------------------------------------------------------

    public function testChallengeIncludesAgreedVersion(): void
    {
        $oTcp = new MockTcpConnection();
        $oServer = $this->makeServer($oTcp, ['1.0']);

        $iTs = time();
        $oServer->onData("HELLO {$iTs} 1.0\n");

        // CHALLENGE <nonce> <agreed_version>
        $sChallenge = $oTcp->writtenLines()[0];
        $aParts = explode(' ', $sChallenge);
        $this->assertCount(3, $aParts, 'CHALLENGE must have 3 space-separated fields');
        $this->assertSame('CHALLENGE', $aParts[0]);
        $this->assertSame('1.0', $aParts[2]);
    }

    public function testChallengeVersionIsHighestCommon(): void
    {
        $oTcp = new MockTcpConnection();
        // Server supports 1.0 and 2.0
        $oServer = $this->makeServer($oTcp, ['1.0', '2.0']);

        // Client offers 1.0 and 2.0
        $oServer->onData("HELLO " . time() . " 1.0,2.0\n");

        $sChallenge = $oTcp->writtenLines()[0];
        $sAgreedVersion = explode(' ', $sChallenge)[2];
        $this->assertSame('2.0', $sAgreedVersion, 'Server should pick the highest common version');
    }

    public function testChallengePicksHighestServerCanSupport(): void
    {
        $oTcp = new MockTcpConnection();
        // Server supports only 1.0
        $oServer = $this->makeServer($oTcp, ['1.0']);

        // Client offers 1.0 and 2.0
        $oServer->onData("HELLO " . time() . " 1.0,2.0\n");

        $sChallenge = $oTcp->writtenLines()[0];
        $sAgreedVersion = explode(' ', $sChallenge)[2];
        $this->assertSame('1.0', $sAgreedVersion, 'Server should pick 1.0 (highest it supports)');
    }

    // -----------------------------------------------------------------------
    // Static negotiation helper
    // -----------------------------------------------------------------------

    public function testNegotiateVersionPicksHighest(): void
    {
        $this->assertSame('2.0', Connection::negotiateVersion(['1.0', '2.0'], ['1.0', '2.0']));
    }

    public function testNegotiateVersionPicksHighestCommonSubset(): void
    {
        // Client has 1.0 and 3.0; server has 1.0 and 2.0 → highest common is 1.0
        $this->assertSame('1.0', Connection::negotiateVersion(['1.0', '3.0'], ['1.0', '2.0']));
    }

    public function testNegotiateVersionReturnsNullWhenNoCommon(): void
    {
        $this->assertNull(Connection::negotiateVersion(['2.0'], ['1.0']));
    }

    public function testNegotiateVersionReturnsNullWhenEitherListEmpty(): void
    {
        $this->assertNull(Connection::negotiateVersion([], ['1.0']));
        $this->assertNull(Connection::negotiateVersion(['1.0'], []));
    }

    public function testNegotiateVersionSingleCommon(): void
    {
        $this->assertSame('1.0', Connection::negotiateVersion(['1.0'], ['1.0']));
    }

    public function testNegotiateVersionTrimsWhitespace(): void
    {
        $this->assertSame('1.0', Connection::negotiateVersion([' 1.0 '], [' 1.0 ']));
    }

    // -----------------------------------------------------------------------
    // VERSION_REJECT — server sends when no common version
    // -----------------------------------------------------------------------

    public function testServerSendsVersionRejectWhenNoCommonVersion(): void
    {
        $oTcp = new MockTcpConnection();
        $oServer = $this->makeServer($oTcp, ['1.0']);

        $oServer->onData("HELLO " . time() . " 2.0\n");

        $this->assertTrue($oTcp->bClosed);
        $sWritten = $oTcp->allWritten();
        $this->assertStringContainsString('VERSION_REJECT', $sWritten);
    }

    public function testVersionRejectContainsServerSupportedVersions(): void
    {
        $oTcp = new MockTcpConnection();
        $oServer = $this->makeServer($oTcp, ['1.0', '2.0']);

        $oServer->onData("HELLO " . time() . " 3.0\n");

        $sWritten = $oTcp->allWritten();
        $this->assertStringContainsString('1.0', $sWritten);
        $this->assertStringContainsString('2.0', $sWritten);
    }

    public function testServerDoesNotAuthenticateAfterVersionReject(): void
    {
        $oTcp = new MockTcpConnection();
        $oServer = $this->makeServer($oTcp, ['1.0']);

        $oServer->onData("HELLO " . time() . " 2.0\n");

        $this->assertFalse($oServer->isAuthenticated());
    }

    // -----------------------------------------------------------------------
    // Client handles VERSION_REJECT
    // -----------------------------------------------------------------------

    public function testClientClosesOnVersionReject(): void
    {
        $oTcp = new MockTcpConnection();
        $oClient = $this->makeClient($oTcp, ['1.0']);

        // Server rejects because it only speaks 2.0
        $oClient->onData("VERSION_REJECT 2.0\n");

        $this->assertTrue($oTcp->bClosed);
        $this->assertFalse($oClient->isAuthenticated());
    }

    public function testClientDoesNotAuthenticateAfterVersionReject(): void
    {
        $oTcp = new MockTcpConnection();
        $oClient = $this->makeClient($oTcp, ['1.0']);
        $oClient->onData("VERSION_REJECT 2.0\n");

        $this->assertFalse($oClient->isAuthenticated());
    }

    // -----------------------------------------------------------------------
    // getProtocolVersion() — querying the negotiated version
    // -----------------------------------------------------------------------

    public function testGetProtocolVersionEmptyBeforeHandshake(): void
    {
        $oTcp = new MockTcpConnection();
        $oServer = $this->makeServer($oTcp, ['1.0']);
        $this->assertSame('', $oServer->getProtocolVersion());
    }

    public function testGetProtocolVersionSetOnServerAfterChallenge(): void
    {
        $oTcp = new MockTcpConnection();
        $oServer = $this->makeServer($oTcp, ['1.0']);
        $oServer->onData("HELLO " . time() . " 1.0\n");
        // After sending CHALLENGE the server has agreed on a version
        $this->assertSame('1.0', $oServer->getProtocolVersion());
    }

    public function testGetProtocolVersionSetOnClientAfterChallenge(): void
    {
        $oServerTcp = new MockTcpConnection();
        $oClientTcp = new MockTcpConnection();
        $oServer = $this->makeServer($oServerTcp, ['1.0']);
        $oClient = $this->makeClient($oClientTcp, ['1.0']);

        // Client sends HELLO; server processes and sends CHALLENGE 1.0
        $oServer->onData($oClientTcp->allWritten()); $oClientTcp->aWritten = [];
        // Client receives CHALLENGE with version
        $oClient->onData($oServerTcp->allWritten());

        $this->assertSame('1.0', $oClient->getProtocolVersion());
    }

    public function testBothSidesAgreeOnSameVersion(): void
    {
        [$oServer, $oClient] = $this->doHandshake(['1.0'], ['1.0']);
        $this->assertSame($oServer->getProtocolVersion(), $oClient->getProtocolVersion());
    }

    public function testFullHandshakeDefaultsTo1_0(): void
    {
        // No custom versions — both sides use SUPPORTED_VERSIONS (['1.0'])
        [$oServer, $oClient] = $this->doHandshake();
        $this->assertTrue($oServer->isAuthenticated());
        $this->assertTrue($oClient->isAuthenticated());
        $this->assertSame('1.0', $oServer->getProtocolVersion());
        $this->assertSame('1.0', $oClient->getProtocolVersion());
    }

    public function testFullHandshakeWithMultipleVersionsNegotiatesHighest(): void
    {
        [$oServer, $oClient] = $this->doHandshake(['1.0', '2.0'], ['1.0', '2.0']);
        $this->assertTrue($oServer->isAuthenticated());
        $this->assertTrue($oClient->isAuthenticated());
        $this->assertSame('2.0', $oServer->getProtocolVersion());
        $this->assertSame('2.0', $oClient->getProtocolVersion());
    }

    public function testFullHandshakeFailsWhenVersionsIncompatible(): void
    {
        [$oServer, $oClient, $oServerTcp, $oClientTcp] = $this->doHandshake(['1.0'], ['2.0']);
        $this->assertFalse($oServer->isAuthenticated());
        $this->assertFalse($oClient->isAuthenticated());
        $this->assertTrue($oClientTcp->bClosed, 'Client should close after VERSION_REJECT');
    }

    // -----------------------------------------------------------------------
    // SUPPORTED_VERSIONS constant
    // -----------------------------------------------------------------------

    public function testSupportedVersionsConstantContains1_0(): void
    {
        $this->assertContains('1.0', Connection::SUPPORTED_VERSIONS);
    }

    public function testSupportedVersionsConstantIsNonEmpty(): void
    {
        $this->assertNotEmpty(Connection::SUPPORTED_VERSIONS);
    }
}
