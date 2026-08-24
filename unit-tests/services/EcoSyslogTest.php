<?php

/*
 * @group unit-tests
 *
 * Tests for Services\Provider\EcoSyslog - see docs/protocols/ecosyslog.md:
 *   - the severity byte maps to the right PSR-3 level
 *   - the rest of the payload becomes the log message
 *   - source network/station are attached as log context
 *   - an out-of-range severity byte falls back to "info"
 *   - an empty payload is ignored
 *   - broadcastPacketIn() behaves exactly like unicastPacketIn()
 *   - getReplies() is always empty - EcoSyslog is fire-and-forget
 */

include_once(__DIR__.'/../../src/include/system.inc.php');

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Monolog\Logger;
use Monolog\Handler\TestHandler;
use HomeLan\FileStore\Services\Provider\EcoSyslog;
use HomeLan\FileStore\Messages\EconetPacket;

class EcoSyslogTest extends TestCase
{
    private TestHandler $oHandler;
    private EcoSyslog $oProvider;

    protected function setUp(): void
    {
        $this->oHandler = new TestHandler();
        $oLogger = new Logger('test');
        $oLogger->pushHandler($this->oHandler);
        $this->oProvider = new EcoSyslog($oLogger);
    }

    private function packetWithData(string $sData): EconetPacket
    {
        $oPacket = new EconetPacket();
        $oPacket->setSourceNetwork(0);
        $oPacket->setSourceStation(42);
        $oPacket->setDestinationNetwork(0);
        $oPacket->setDestinationStation(254);
        $oPacket->setPort(0xB6);
        $oPacket->setData($sData);
        return $oPacket;
    }

    public function testGetServicePortsReturnsB6(): void
    {
        $this->assertSame([0xB6], $this->oProvider->getServicePorts());
    }

    #[DataProvider('severityProvider')]
    public function testSeverityByteMapsToExpectedLevel(int $iSeverity, string $sHasThatContainsMethod): void
    {
        $this->oProvider->unicastPacketIn($this->packetWithData(chr($iSeverity) . 'something happened'));

        $this->assertTrue($this->oHandler->{$sHasThatContainsMethod}('something happened'));
    }

    /** @return array<string,array{0:int,1:string}> */
    public static function severityProvider(): array
    {
        return [
            'emergency' => [0, 'hasEmergencyThatContains'],
            'alert' => [1, 'hasAlertThatContains'],
            'critical' => [2, 'hasCriticalThatContains'],
            'error' => [3, 'hasErrorThatContains'],
            'warning' => [4, 'hasWarningThatContains'],
            'notice' => [5, 'hasNoticeThatContains'],
            'info' => [6, 'hasInfoThatContains'],
            'debug' => [7, 'hasDebugThatContains'],
        ];
    }

    public function testOutOfRangeSeverityFallsBackToInfo(): void
    {
        $this->oProvider->unicastPacketIn($this->packetWithData(chr(99) . 'weird severity'));
        $this->assertTrue($this->oHandler->hasInfoThatContains('weird severity'));
    }

    public function testSourceNetworkAndStationAreAttachedAsContext(): void
    {
        $this->oProvider->unicastPacketIn($this->packetWithData(chr(6) . 'hello'));

        $aRecords = $this->oHandler->getRecords();
        $this->assertCount(1, $aRecords);
        $this->assertSame(0, $aRecords[0]['context']['network']);
        $this->assertSame(42, $aRecords[0]['context']['station']);
    }

    public function testEmptyPayloadIsIgnored(): void
    {
        $this->oProvider->unicastPacketIn($this->packetWithData(''));
        $this->assertEmpty($this->oHandler->getRecords());
    }

    public function testBroadcastPacketInBehavesLikeUnicast(): void
    {
        $this->oProvider->broadcastPacketIn($this->packetWithData(chr(5) . 'broadcast log'));
        $this->assertTrue($this->oHandler->hasNoticeThatContains('broadcast log'));
    }

    public function testGetRepliesIsAlwaysEmpty(): void
    {
        $this->oProvider->unicastPacketIn($this->packetWithData(chr(6) . 'hello'));
        $this->assertSame([], $this->oProvider->getReplies());
    }

    public function testGetJobsIsAlwaysEmpty(): void
    {
        $this->assertSame([], $this->oProvider->getJobs());
    }
}
