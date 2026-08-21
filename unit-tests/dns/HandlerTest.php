<?php

/*
 * @group unit-tests
 *
 * Tests for Dns\Handler - answers a decoded DnsMessage query from HostsFile and sends the
 * response back on whichever socket it was given (a real UDP socket or, in dnsd's case, a
 * RemoteSocket\RelayedUdpTransport - see docs/DNSD.md).
 */

include_once(__DIR__ . '/../../src/include/system.inc.php');

use PHPUnit\Framework\TestCase;
use Monolog\Logger;
use Monolog\Handler\NullHandler;
use HomeLan\FileStore\Dns\Handler;
use HomeLan\FileStore\Dns\HostsFile;
use HomeLan\FileStore\Dns\Forwarder;
use HomeLan\FileStore\Dns\DomainFilter;
use HomeLan\FileStore\Dns\Messages\DnsMessage;
use React\Datagram\SocketInterface;
use React\EventLoop\Factory as LoopFactory;
use React\Promise\PromiseInterface;

use function React\Promise\resolve;
use function React\Promise\reject;

/**
 * A Forwarder that never touches the network: forward() returns whatever canned
 * result the test configured, instead of actually talking to an upstream server.
 */
class StubForwarder extends Forwarder
{
    private mixed $mResult = null;

    public function __construct()
    {
        $oLogger = new Logger('stub-forwarder');
        $oLogger->pushHandler(new NullHandler());
        parent::__construct(LoopFactory::create(), $oLogger, '0.0.0.0:0', 5.0);
    }

    protected function createUpstreamConnectionPromise(string $sUpstreamAddress): mixed
    {
        return reject(new \Exception('StubForwarder never really connects'));
    }

    public function willResolveWith(string $sRawResponse): void
    {
        $this->mResult = $sRawResponse;
    }

    public function willReject(): void
    {
        $this->mResult = null;
    }

    public function forward(string $sQueryPacket): PromiseInterface
    {
        return $this->mResult === null ? reject(new \Exception('StubForwarder configured to reject')) : resolve($this->mResult);
    }
}

class HandlerTest extends TestCase
{
    private Logger $oLogger;
    private SocketInterface $oSocket;
    private Handler $oHandler;

    protected function setUp(): void
    {
        $this->oLogger = new Logger('test');
        $this->oLogger->pushHandler(new NullHandler());

        HostsFile::init($this->oLogger, "192.168.0.5 fileserver\n");

        $this->oSocket = $this->createMock(SocketInterface::class);
        $this->oHandler = new Handler($this->oLogger);
        $this->oHandler->setSocket($this->oSocket);
    }

    protected function tearDown(): void
    {
        HostsFile::reset();
    }

    private function buildQueryBytes(string $sName, int $iType, int $iClass = DnsMessage::CLASS_IN, int $iId = 0x1234, int $iOpcode = 0): string
    {
        $iFlags = ($iOpcode << 11) | (1 << 8);
        $sHeader = pack('n6', $iId, $iFlags, 1, 0, 0, 0);
        $sEncodedName = '';
        foreach (explode('.', $sName) as $sLabel) {
            if ($sLabel === '') {
                continue;
            }
            $sEncodedName .= chr(strlen($sLabel)) . $sLabel;
        }
        $sEncodedName .= "\x00";
        return $sHeader . $sEncodedName . pack('n2', $iType, $iClass);
    }

    public function testAQueryForAKnownHostReturnsItsAddress(): void
    {
        $sSent = null;
        $this->oSocket->method('send')->willReturnCallback(function (string $sData) use (&$sSent) {
            $sSent = $sData;
        });

        $this->oHandler->receive($this->buildQueryBytes('fileserver', DnsMessage::TYPE_A), '10.0.0.5:5000');

        $sRdata = substr((string) $sSent, -4);
        $this->assertSame('192.168.0.5', inet_ntop($sRdata));
    }

    public function testAQueryWithMultipleAddressesReturnsAllOfThem(): void
    {
        HostsFile::init($this->oLogger, "192.168.0.5 multihomed\n192.168.0.6 multihomed\n");

        $sSent = null;
        $this->oSocket->method('send')->willReturnCallback(function (string $sData) use (&$sSent) {
            $sSent = $sData;
        });

        $this->oHandler->receive($this->buildQueryBytes('multihomed', DnsMessage::TYPE_A), '10.0.0.5:5000');

        $aHeader = unpack('nid/nflags/nqdcount/nancount', (string) $sSent);
        $this->assertSame(2, $aHeader['ancount']);
    }

    public function testAaaaQueryGetsNotImplemented(): void
    {
        // dnsd is IPv4-only (see docs/protocols/dns.md) - AAAA is never served, even for a
        // name that has an A record.
        $sSent = null;
        $this->oSocket->method('send')->willReturnCallback(function (string $sData) use (&$sSent) {
            $sSent = $sData;
        });

        $this->oHandler->receive($this->buildQueryBytes('fileserver', DnsMessage::TYPE_AAAA), '10.0.0.5:5000');

        $aHeader = unpack('nid/nflags', (string) $sSent);
        $this->assertSame(DnsMessage::RCODE_NOTIMP, $aHeader['flags'] & 0xF);
    }

    public function testQueryForAnUnknownNameReturnsNxDomain(): void
    {
        $sSent = null;
        $this->oSocket->method('send')->willReturnCallback(function (string $sData) use (&$sSent) {
            $sSent = $sData;
        });

        $this->oHandler->receive($this->buildQueryBytes('nosuchhost', DnsMessage::TYPE_A), '10.0.0.5:5000');

        $aHeader = unpack('nid/nflags', (string) $sSent);
        $this->assertSame(DnsMessage::RCODE_NXDOMAIN, $aHeader['flags'] & 0xF);
    }

    public function testResponseIsSentBackToTheSender(): void
    {
        $this->oSocket->expects($this->once())
            ->method('send')
            ->with($this->anything(), '10.0.0.5:5000');

        $this->oHandler->receive($this->buildQueryBytes('fileserver', DnsMessage::TYPE_A), '10.0.0.5:5000');
    }

    public function testUnsupportedQueryTypeGetsNotImplementedWithNoError(): void
    {
        $sSent = null;
        $this->oSocket->method('send')->willReturnCallback(function (string $sData) use (&$sSent) {
            $sSent = $sData;
        });

        // A known host, but a query type this server doesn't serve records for (e.g. MX = 15).
        $this->oHandler->receive($this->buildQueryBytes('fileserver', 15), '10.0.0.5:5000');

        $aHeader = unpack('nid/nflags', (string) $sSent);
        $this->assertSame(DnsMessage::RCODE_NOTIMP, $aHeader['flags'] & 0xF);
    }

    public function testNonStandardOpcodeGetsNotImplemented(): void
    {
        $sSent = null;
        $this->oSocket->method('send')->willReturnCallback(function (string $sData) use (&$sSent) {
            $sSent = $sData;
        });

        $this->oHandler->receive($this->buildQueryBytes('fileserver', DnsMessage::TYPE_A, iOpcode: 2), '10.0.0.5:5000');

        $aHeader = unpack('nid/nflags', (string) $sSent);
        $this->assertSame(DnsMessage::RCODE_NOTIMP, $aHeader['flags'] & 0xF);
    }

    public function testMalformedQueryIsDroppedWithoutReply(): void
    {
        $this->oSocket->expects($this->never())->method('send');
        $this->oHandler->receive('not a dns packet', '10.0.0.5:5000');
    }

    // -----------------------------------------------------------------------
    // PTR (reverse) queries
    // -----------------------------------------------------------------------

    public function testPtrQueryForAKnownAddressReturnsItsName(): void
    {
        $sSent = null;
        $this->oSocket->method('send')->willReturnCallback(function (string $sData) use (&$sSent) {
            $sSent = $sData;
        });

        $this->oHandler->receive($this->buildQueryBytes('5.0.168.192.in-addr.arpa', DnsMessage::TYPE_PTR), '10.0.0.5:5000');

        $this->assertSame(DnsMessage::encodeDomainName('fileserver'), substr((string) $sSent, -12));
    }

    public function testPtrQueryForAnUnknownAddressReturnsNxDomain(): void
    {
        $sSent = null;
        $this->oSocket->method('send')->willReturnCallback(function (string $sData) use (&$sSent) {
            $sSent = $sData;
        });

        $this->oHandler->receive($this->buildQueryBytes('99.0.168.192.in-addr.arpa', DnsMessage::TYPE_PTR), '10.0.0.5:5000');

        $aHeader = unpack('nid/nflags', (string) $sSent);
        $this->assertSame(DnsMessage::RCODE_NXDOMAIN, $aHeader['flags'] & 0xF);
    }

    public function testMalformedPtrNameReturnsNxDomain(): void
    {
        $sSent = null;
        $this->oSocket->method('send')->willReturnCallback(function (string $sData) use (&$sSent) {
            $sSent = $sData;
        });

        $this->oHandler->receive($this->buildQueryBytes('not.a.reverse.name', DnsMessage::TYPE_PTR), '10.0.0.5:5000');

        $aHeader = unpack('nid/nflags', (string) $sSent);
        $this->assertSame(DnsMessage::RCODE_NXDOMAIN, $aHeader['flags'] & 0xF);
    }

    // -----------------------------------------------------------------------
    // Forwarding
    // -----------------------------------------------------------------------

    private function buildForwardingHandler(StubForwarder $oForwarder, ?DomainFilter $oDomainFilter = null): Handler
    {
        $oHandler = new Handler($this->oLogger, $oForwarder, $oDomainFilter);
        $oHandler->setSocket($this->oSocket);
        return $oHandler;
    }

    /**
     * A well-formed upstream response Forwarder might hand back: header (with its own id, as
     * Forwarder would assign), the question echoed back, and one A answer.
     *
     * @param list<string> $aExtraAnswers already wire-encoded RRs, e.g. from buildRR()
     */
    private function buildUpstreamResponseBytes(string $sQuestionName, int $iQType, string $sAnswerIp, array $aExtraAnswers = []): string
    {
        $sQuestion = DnsMessage::encodeDomainName($sQuestionName) . pack('n2', $iQType, DnsMessage::CLASS_IN);
        $sAnswer = "\xC0\x0C" . pack('n2N', DnsMessage::TYPE_A, DnsMessage::CLASS_IN, 300) . pack('n', 4) . (string) inet_pton($sAnswerIp);
        $sHeader = pack('n6', 0, 0x8180, 1, 1 + count($aExtraAnswers), 0, 0);
        return $sHeader . $sQuestion . $sAnswer . implode('', $aExtraAnswers);
    }

    private function buildRR(string $sWireName, int $iType, int $iClass, int $iTtl, string $sRdata): string
    {
        return $sWireName . pack('n2N', $iType, $iClass, $iTtl) . pack('n', strlen($sRdata)) . $sRdata;
    }

    public function testUnknownNameIsForwardedWhenForwarderConfigured(): void
    {
        $oForwarder = new StubForwarder();
        $oForwarder->willResolveWith($this->buildUpstreamResponseBytes('nosuchhost', DnsMessage::TYPE_A, '203.0.113.9'));
        $oHandler = $this->buildForwardingHandler($oForwarder);

        $sSent = null;
        $this->oSocket->method('send')->willReturnCallback(function (string $sData) use (&$sSent) {
            $sSent = $sData;
        });

        $oHandler->receive($this->buildQueryBytes('nosuchhost', DnsMessage::TYPE_A, iId: 0xABCD), '10.0.0.5:5000');

        // The forwarded reply is relayed through, except the id is rewritten back to the
        // original client's own query id (0xABCD), not the Forwarder's internal one (0).
        $aHeader = unpack('nid/nflags/nqdcount/nancount', (string) $sSent);
        $this->assertSame(0xABCD, $aHeader['id']);
        $this->assertSame(1, $aHeader['ancount']);
        $this->assertSame('203.0.113.9', inet_ntop(substr((string) $sSent, -4)));
    }

    public function testForwardedResponseHasAnyAaaaRecordsStripped(): void
    {
        $sAaaaRR = $this->buildRR("\xC0\x0C", DnsMessage::TYPE_AAAA, DnsMessage::CLASS_IN, 300, str_repeat("\x00", 16));
        $oForwarder = new StubForwarder();
        $oForwarder->willResolveWith($this->buildUpstreamResponseBytes('nosuchhost', DnsMessage::TYPE_A, '203.0.113.9', [$sAaaaRR]));
        $oHandler = $this->buildForwardingHandler($oForwarder);

        $sSent = null;
        $this->oSocket->method('send')->willReturnCallback(function (string $sData) use (&$sSent) {
            $sSent = $sData;
        });

        $oHandler->receive($this->buildQueryBytes('nosuchhost', DnsMessage::TYPE_A), '10.0.0.5:5000');

        // Only the A record survives - the AAAA record must never reach the (Econet) client.
        $aHeader = unpack('nid/nflags/nqdcount/nancount', (string) $sSent);
        $this->assertSame(1, $aHeader['ancount']);
        $this->assertSame('203.0.113.9', inet_ntop(substr((string) $sSent, -4)));
    }

    public function testUnparseableUpstreamResponseFallsBackToNxDomainRatherThanBeingRelayed(): void
    {
        // Well-formed-looking header claiming answers that aren't actually there - must not be
        // relayed to the client half-parsed or unfiltered.
        $oForwarder = new StubForwarder();
        $oForwarder->willResolveWith(pack('n6', 0, 0x8180, 1, 1, 0, 0) . 'not a valid question or RR');
        $oHandler = $this->buildForwardingHandler($oForwarder);

        $sSent = null;
        $this->oSocket->method('send')->willReturnCallback(function (string $sData) use (&$sSent) {
            $sSent = $sData;
        });

        $oHandler->receive($this->buildQueryBytes('nosuchhost', DnsMessage::TYPE_A), '10.0.0.5:5000');

        $aHeader = unpack('nid/nflags', (string) $sSent);
        $this->assertSame(DnsMessage::RCODE_NXDOMAIN, $aHeader['flags'] & 0xF);
    }

    public function testHostsFileAnswerIsUsedWithoutForwardingWhenPresent(): void
    {
        $oForwarder = new StubForwarder();
        $oForwarder->willResolveWith('should never be used');
        $oHandler = $this->buildForwardingHandler($oForwarder);

        $sSent = null;
        $this->oSocket->method('send')->willReturnCallback(function (string $sData) use (&$sSent) {
            $sSent = $sData;
        });

        $oHandler->receive($this->buildQueryBytes('fileserver', DnsMessage::TYPE_A), '10.0.0.5:5000');

        $sRdata = substr((string) $sSent, -4);
        $this->assertSame('192.168.0.5', inet_ntop($sRdata));
    }

    public function testForwarderFailureFallsBackToNxDomain(): void
    {
        $oForwarder = new StubForwarder();
        $oForwarder->willReject();
        $oHandler = $this->buildForwardingHandler($oForwarder);

        $sSent = null;
        $this->oSocket->method('send')->willReturnCallback(function (string $sData) use (&$sSent) {
            $sSent = $sData;
        });

        $oHandler->receive($this->buildQueryBytes('nosuchhost', DnsMessage::TYPE_A), '10.0.0.5:5000');

        $aHeader = unpack('nid/nflags', (string) $sSent);
        $this->assertSame(DnsMessage::RCODE_NXDOMAIN, $aHeader['flags'] & 0xF);
    }

    public function testForwardingDisallowedByDomainFilterFallsBackToNxDomainWithoutCallingForwarder(): void
    {
        $oForwarder = new StubForwarder();
        $oForwarder->willResolveWith('should never be used');
        $oHandler = $this->buildForwardingHandler($oForwarder, new DomainFilter('example.com'));

        $sSent = null;
        $this->oSocket->method('send')->willReturnCallback(function (string $sData) use (&$sSent) {
            $sSent = $sData;
        });

        $oHandler->receive($this->buildQueryBytes('nosuchhost', DnsMessage::TYPE_A), '10.0.0.5:5000');

        $aHeader = unpack('nid/nflags', (string) $sSent);
        $this->assertSame(DnsMessage::RCODE_NXDOMAIN, $aHeader['flags'] & 0xF);
    }

    public function testForwardingAllowedByDomainFilterIsUsed(): void
    {
        $oForwarder = new StubForwarder();
        $oForwarder->willResolveWith($this->buildUpstreamResponseBytes('nosuchhost.example.com', DnsMessage::TYPE_A, '203.0.113.9'));
        $oHandler = $this->buildForwardingHandler($oForwarder, new DomainFilter('example.com'));

        $sSent = null;
        $this->oSocket->method('send')->willReturnCallback(function (string $sData) use (&$sSent) {
            $sSent = $sData;
        });

        $oHandler->receive($this->buildQueryBytes('nosuchhost.example.com', DnsMessage::TYPE_A), '10.0.0.5:5000');

        $this->assertSame('203.0.113.9', inet_ntop(substr((string) $sSent, -4)));
    }

    public function testUnknownPtrNameIsForwardedWhenForwarderConfigured(): void
    {
        $sPtrAnswer = $this->buildRR("\xC0\x0C", DnsMessage::TYPE_PTR, DnsMessage::CLASS_IN, 300, DnsMessage::encodeDomainName('somehost.example.com'));
        $sQuestion = DnsMessage::encodeDomainName('99.0.168.192.in-addr.arpa') . pack('n2', DnsMessage::TYPE_PTR, DnsMessage::CLASS_IN);
        $sHeader = pack('n6', 0, 0x8180, 1, 1, 0, 0);

        $oForwarder = new StubForwarder();
        $oForwarder->willResolveWith($sHeader . $sQuestion . $sPtrAnswer);
        $oHandler = $this->buildForwardingHandler($oForwarder);

        $sSent = null;
        $this->oSocket->method('send')->willReturnCallback(function (string $sData) use (&$sSent) {
            $sSent = $sData;
        });

        $oHandler->receive($this->buildQueryBytes('99.0.168.192.in-addr.arpa', DnsMessage::TYPE_PTR), '10.0.0.5:5000');

        $this->assertStringEndsWith(DnsMessage::encodeDomainName('somehost.example.com'), (string) $sSent);
    }
}
