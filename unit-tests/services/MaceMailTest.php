<?php

/*
 * @group unit-tests
 */

use PHPUnit\Framework\TestCase;
use Monolog\Logger;
use Monolog\Handler\NullHandler;
use HomeLan\FileStore\Messages\EconetPacket;
use HomeLan\FileStore\Services\Provider\MaceMail;
use HomeLan\FileStore\Services\Provider\MaceMail\Storage;
use HomeLan\FileStore\Authentication\User;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

include_once(__DIR__ . '/../../src/include/system.inc.php');

// ---------------------------------------------------------------------------
// Testable subclass — stubs the Security wrappers (matching the established
// FileServerTestable/PrintServerTestable pattern) so no real static Security
// state is touched. Storage itself is a real constructor-injected Mockery
// mock, not stubbed here — MaceMail never touches a filesystem in these
// tests.
// ---------------------------------------------------------------------------
class MaceMailTestable extends MaceMail
{
    public bool    $stubIsLoggedIn  = false;
    public bool    $stubLoginResult = true;
    public ?object $stubUser        = null;
    public array   $stubUserStation = [];   // username => ['network'=>n,'station'=>s]
    public array   $stubUsersOnline = [];   // [network][station] => ['user' => User]
    public array   $stubToday       = [15, 6, 26];
    public array   $stubUsersByName = [];   // username => User

    public array $capLogin  = [];
    public array $capLogout = [];

    protected function secIsLoggedIn(int $iNet, int $iStn): bool
    { return $this->stubIsLoggedIn; }

    protected function secLogin(int $iNet, int $iStn, string $sUser, string $sPass): bool
    {
        $this->capLogin[] = compact('iNet', 'iStn', 'sUser', 'sPass');
        return $this->stubLoginResult;
    }

    protected function secLogout(int $iNet, int $iStn): void
    { $this->capLogout[] = compact('iNet', 'iStn'); }

    protected function secGetUser(int $iNet, int $iStn): ?User
    { return $this->stubUser; }

    protected function secGetUsersStation(string $sUser): array
    { return $this->stubUserStation[strtoupper($sUser)] ?? []; }

    protected function secGetUsersOnline(): array
    { return $this->stubUsersOnline; }

    protected function secUpdateIdleTimer(int $iNet, int $iStn): void {}

    protected function today(): array
    { return $this->stubToday; }

    protected function secGetUserByName(string $sUsername): ?User
    { return $this->stubUsersByName[strtoupper($sUsername)] ?? null; }
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------
class MaceMailTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected MaceMailTestable $oProvider;
    protected $oStorage;

    protected function setUp(): void
    {
        if (!defined('CONFIG_macemail_usergroup')) {
            define('CONFIG_macemail_usergroup', 'MAIL');
        }
        config::overrideValue('macemail_usergroup', 'MAIL');

        $oLogger = new Logger('macemail-test');
        $oLogger->pushHandler(new NullHandler());

        $this->oStorage  = Mockery::mock(Storage::class);
        $this->oProvider = new MaceMailTestable($oLogger, $this->oStorage);
    }

    protected function tearDown(): void
    {
        config::resetValue('macemail_usergroup');
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    protected function _quickCommandPacket(int $iOp, int $iSlot, int $iParam, int $iNet = 0, int $iStn = 201): EconetPacket
    {
        $sData = "MAI" . chr(1) . chr(0) . chr($iOp) . chr($iSlot) . chr($iParam);
        $oPacket = new EconetPacket();
        $oPacket->setPort(MaceMail::PORT_REQUEST);
        $oPacket->setFlags(0x80);
        $oPacket->setSourceNetwork($iNet);
        $oPacket->setSourceStation($iStn);
        $oPacket->setData($sData);
        return $oPacket;
    }

    protected function _logonPacket(string $sPassword, int $iSlot, int $iNet = 0, int $iStn = 201): EconetPacket
    {
        $sData = str_pad($sPassword, 5, ' ') . chr($iSlot);
        $oPacket = new EconetPacket();
        $oPacket->setPort(MaceMail::PORT_LOGON_REQUEST);
        $oPacket->setFlags(0x80);
        $oPacket->setSourceNetwork($iNet);
        $oPacket->setSourceStation($iStn);
        $oPacket->setData($sData);
        return $oPacket;
    }

    /** @param array<int,int> $aToSlots up to 6 recipient slot numbers */
    protected function _saveMailPacket(
        string $sBody,
        string $sSubject,
        int $iType,
        bool $bEveryone,
        array $aToSlots,
        int $iToFlag1 = 64,
        int $iToFlag2 = 64,
        int $iNet = 0,
        int $iStn = 201
    ): EconetPacket {
        $sData  = str_pad(substr($sBody, 0, 418), 440, "\0");      // 0-417 body, 418-439 pad
        $sData .= str_pad(substr($sSubject, 0, 28), 28, ' ');       // 440-467 subject
        $sData .= "\0";                                             // 468 pad
        $sData .= chr($iType);                                      // 469 message type
        $sData .= chr($bEveryone ? 65 : 0);                         // 470 everyone flag
        for ($i = 0; $i < 6; $i++) {
            $sData .= chr($aToSlots[$i] ?? 64);                     // 471-476 TO slots
        }
        $sData .= chr($iToFlag1);                                   // 477 TO flag 1
        $sData .= chr($iToFlag2);                                   // 478 TO flag 2
        for ($i = 0; $i < 6; $i++) {
            $sData .= chr(64);                                      // 479-484 CC slots (unsupported)
        }
        $sData .= chr(64) . chr(64);                                // 485-486 CC flags (unsupported)
        $sData .= str_repeat("\0", 3);                              // 487-489 pad

        $oPacket = new EconetPacket();
        $oPacket->setPort(MaceMail::PORT_MAIL_POST_REQUEST);
        $oPacket->setFlags(0x80);
        $oPacket->setSourceNetwork($iNet);
        $oPacket->setSourceStation($iStn);
        $oPacket->setData($sData);
        return $oPacket;
    }

    protected function _storeSaveDataPacket(string $sData, int $iNet = 0, int $iStn = 201): EconetPacket
    {
        $oPacket = new EconetPacket();
        $oPacket->setPort(MaceMail::PORT_STORE_SAVE_REQUEST);
        $oPacket->setFlags(0x80);
        $oPacket->setSourceNetwork($iNet);
        $oPacket->setSourceStation($iStn);
        $oPacket->setData(str_pad(substr($sData, 0, 440), 440, "\0"));
        return $oPacket;
    }

    /** @param array<int,int> $aIds up to 6 message ids to look up */
    protected function _lookPacket(array $aIds, int $iNet = 0, int $iStn = 201): EconetPacket
    {
        $sData = '';
        for ($i = 0; $i < 6; $i++) {
            $sData .= chr($aIds[$i] ?? 0);
        }
        $oPacket = new EconetPacket();
        $oPacket->setPort(MaceMail::PORT_LOOK_REQUEST);
        $oPacket->setFlags(0x80);
        $oPacket->setSourceNetwork($iNet);
        $oPacket->setSourceStation($iStn);
        $oPacket->setData($sData);
        return $oPacket;
    }

    // -------------------------------------------------------------------------
    // Basic plugin lifecycle
    // -------------------------------------------------------------------------

    public function testGetNameReturnsMaceMail(): void
    {
        $this->assertSame('MaceMail', $this->oProvider->getName());
    }

    public function testGetAdminInterfaceReturnsAnAdminObject(): void
    {
        $this->assertInstanceOf(
            \HomeLan\FileStore\Services\Provider\MaceMail\Admin::class,
            $this->oProvider->getAdminInterface()
        );
    }

    public function testGetJobsReturnsEmptyArray(): void
    {
        $this->assertSame([], $this->oProvider->getJobs());
    }

    public function testGetServicePortsIncludesAllSeventeenPorts(): void
    {
        $aPorts = $this->oProvider->getServicePorts();
        $this->assertCount(17, $aPorts);
        foreach ([0x19, 0x1A, 0x1B, 0x1C, 0x1E, 0x1F, 0x21, 0x23, 0x25, 0x26, 0x27, 0x28, 0x29, 0x2A, 0x2B, 0x31, 0x40] as $iPort) {
            $this->assertContains($iPort, $aPorts);
        }
    }

    public function testGetRepliesDrainsBuffer(): void
    {
        $this->oProvider->broadcastPacketIn($this->_quickCommandPacket(MaceMail::OP_NOOP, 0, 0));
        $this->assertCount(1, $this->oProvider->getReplies());
        $this->assertCount(0, $this->oProvider->getReplies());
    }

    // -------------------------------------------------------------------------
    // Quick command / discovery ack (0x19 -> 0x1A)
    // -------------------------------------------------------------------------

    public function testQuickCommandAlwaysProducesAnAck(): void
    {
        $this->oProvider->broadcastPacketIn($this->_quickCommandPacket(MaceMail::OP_NOOP, 0, 0));
        $aReplies = $this->oProvider->getReplies();
        $this->assertCount(1, $aReplies);
        $this->assertSame(MaceMail::PORT_ACK, $aReplies[0]->getPort());
    }

    public function testAckContainsMaceAndUsergroup(): void
    {
        $this->oProvider->broadcastPacketIn($this->_quickCommandPacket(MaceMail::OP_NOOP, 0, 0));
        $oReply = $this->oProvider->getReplies()[0];
        $this->assertSame("MACE\x00MAIL\x00\x00", $oReply->getData());
    }

    public function testAckIsAddressedBackToSourceStation(): void
    {
        $this->oProvider->broadcastPacketIn($this->_quickCommandPacket(MaceMail::OP_NOOP, 0, 0, 1, 44));
        $oReply = $this->oProvider->getReplies()[0];
        $this->assertSame(1, $oReply->getDestinationNetwork());
        $this->assertSame(44, $oReply->getDestinationStation());
    }

    public function testQuickCommandAlsoHandledViaUnicast(): void
    {
        $this->oProvider->unicastPacketIn($this->_quickCommandPacket(MaceMail::OP_NOOP, 0, 0));
        $this->assertCount(1, $this->oProvider->getReplies());
    }

    // -------------------------------------------------------------------------
    // Unicast quick-command support — a modified client can address the
    // quick-command envelope (port 0x19) directly to this server's own
    // network/station instead of broadcasting it, once it already knows the
    // address. Every op reachable over broadcast must behave identically
    // over unicast, and every reply must go back to the real requester
    // (never to a broadcast address), without changing anything about how
    // the original, unmodified broadcast-only client is served.
    // -------------------------------------------------------------------------

    public function testUnicastAckIsByteIdenticalToBroadcastAck(): void
    {
        $this->oProvider->broadcastPacketIn($this->_quickCommandPacket(MaceMail::OP_NOOP, 0, 0));
        $sBroadcastAck = $this->oProvider->getReplies()[0]->getData();

        $this->oProvider->unicastPacketIn($this->_quickCommandPacket(MaceMail::OP_NOOP, 0, 0));
        $sUnicastAck = $this->oProvider->getReplies()[0]->getData();

        $this->assertSame($sBroadcastAck, $sUnicastAck);
    }

    public function testUnicastQuickCommandRepliesAreAddressedToTheRequestersStation(): void
    {
        $this->_authenticateAs('JSMITH', 3, 2, 77);
        $this->oStorage->shouldReceive('getMailCounts')->with('JSMITH')->andReturn(
            ['unread_normal' => 0, 'unread_express' => 0, 'read_normal' => 0, 'read_express' => 0]
        );

        $this->oProvider->unicastPacketIn($this->_quickCommandPacket(MaceMail::OP_MAIL_CHECK, 3, 0, 2, 77));

        $aReplies = $this->oProvider->getReplies();
        $this->assertCount(2, $aReplies);
        foreach ($aReplies as $oReply) {
            $this->assertSame(2, $oReply->getDestinationNetwork());
            $this->assertSame(77, $oReply->getDestinationStation());
        }
    }

    public function testLogoffWorksViaUnicast(): void
    {
        $this->oProvider->stubIsLoggedIn = true;
        $this->oProvider->unicastPacketIn($this->_quickCommandPacket(MaceMail::OP_LOGOFF, 3, 0));
        $this->assertCount(1, $this->oProvider->capLogout);
    }

    public function testDirectoryOpWorksViaUnicast(): void
    {
        $this->_authenticateAs('JSMITH', 3);
        $this->oStorage->shouldReceive('getAllSlots')->andReturn([1 => 'AWILSON']);

        $this->oProvider->unicastPacketIn($this->_quickCommandPacket(MaceMail::OP_DIRECTORY_ALL, 3, 0));

        $aReplies = $this->oProvider->getReplies();
        $this->assertSame(MaceMail::PORT_DIRECTORY_REPLY, $aReplies[1]->getPort());
        $this->assertSame(1, ord($aReplies[1]->getData()[0]));
    }

    public function testMailCheckWorksViaUnicast(): void
    {
        $this->_authenticateAs('JSMITH', 3);
        $this->oStorage->shouldReceive('getMailCounts')->with('JSMITH')->andReturn(
            ['unread_normal' => 5, 'unread_express' => 2, 'read_normal' => 7, 'read_express' => 1]
        );

        $this->oProvider->unicastPacketIn($this->_quickCommandPacket(MaceMail::OP_MAIL_CHECK, 3, 0));

        $this->assertSame(MaceMail::PORT_MAILCHECK_REPLY, $this->oProvider->getReplies()[1]->getPort());
    }

    public function testGetMailWorksViaUnicast(): void
    {
        $this->_authenticateAs('JSMITH', 3);
        $this->oStorage->shouldReceive('getMailCounts')->andReturn(
            ['unread_normal' => 0, 'unread_express' => 0, 'read_normal' => 0, 'read_express' => 0]
        );
        $this->oStorage->shouldReceive('getMailIndex')->andReturn([]);

        $this->oProvider->unicastPacketIn($this->_quickCommandPacket(MaceMail::OP_GET_MAIL_NEW, 3, 0));

        $this->assertSame(MaceMail::PORT_MAIL_LIST_REPLY, $this->oProvider->getReplies()[1]->getPort());
    }

    public function testIndividualMailWorksViaUnicast(): void
    {
        $this->_authenticateAs('JSMITH', 3);
        $this->oStorage->shouldReceive('getMailItem')->with('JSMITH', 5)->andReturn(null);

        $this->oProvider->unicastPacketIn($this->_quickCommandPacket(MaceMail::OP_INDIVIDUAL_MAIL, 3, 5));

        $oReply = $this->oProvider->getReplies()[1];
        $this->assertSame(MaceMail::PORT_MAIL_ITEM_REPLY, $oReply->getPort());
        $this->assertSame("\xFF\xFF\xFF\xFF", $oReply->getData());
    }

    public function testDeleteMailWorksViaUnicast(): void
    {
        $this->_authenticateAs('JSMITH', 3);
        $this->oStorage->shouldReceive('deleteMailItem')->once()->with('JSMITH', 5);

        $this->oProvider->unicastPacketIn($this->_quickCommandPacket(MaceMail::OP_DELETE_MAIL, 3, 5));
    }

    public function testGetStoreWorksViaUnicast(): void
    {
        $this->_authenticateAs('JSMITH', 3);
        $this->oStorage->shouldReceive('getStoreSlot')->with('JSMITH', 2)->andReturn('saved content');

        $this->oProvider->unicastPacketIn($this->_quickCommandPacket(MaceMail::OP_GET_STORE, 3, 2));

        $oReply = $this->oProvider->getReplies()[1];
        $this->assertSame(MaceMail::PORT_STORE_RECALL_REPLY, $oReply->getPort());
        $this->assertStringStartsWith('saved content', $oReply->getData());
    }

    public function testSaveStoreWorksViaUnicast(): void
    {
        $this->_authenticateAs('JSMITH', 3);
        $this->oStorage->shouldReceive('setStoreSlot')
            ->once()
            ->with('JSMITH', 4, Mockery::on(fn($sData) => str_starts_with($sData, 'file contents')));

        $this->oProvider->unicastPacketIn($this->_quickCommandPacket(MaceMail::OP_SAVE_STORE, 3, 4));
        $this->oProvider->unicastPacketIn($this->_storeSaveDataPacket('file contents'));
    }

    public function testDeleteStoreWorksViaUnicast(): void
    {
        $this->_authenticateAs('JSMITH', 3);
        $this->oStorage->shouldReceive('setStoreMask')->once()->with('JSMITH', 0x05);

        $this->oProvider->unicastPacketIn($this->_quickCommandPacket(MaceMail::OP_DELETE_STORE, 3, 0x05));
    }

    public function testMailboxScanWorksViaUnicast(): void
    {
        $this->_authenticateAs('JSMITH', 3);
        $this->oStorage->shouldReceive('getMailIndex')->with('JSMITH')->andReturn([['id' => 1]]);

        $this->oProvider->unicastPacketIn($this->_quickCommandPacket(MaceMail::OP_MAILBOX_SCAN, 3, 0));

        $oReply = $this->oProvider->getReplies()[1];
        $this->assertSame(MaceMail::PORT_MAILBOX_SCAN_REPLY, $oReply->getPort());
        $this->assertSame(1, ord($oReply->getData()[0]));
    }

    public function testChatWorksViaUnicast(): void
    {
        $this->_authenticateAs('JSMITH', 3);
        $this->oStorage->shouldReceive('getUsernameForSlot')->with(5)->andReturn('AWILSON');
        $this->oProvider->stubUserStation = ['AWILSON' => ['network' => 0, 'station' => 55]];

        $this->oProvider->unicastPacketIn($this->_quickCommandPacket(MaceMail::OP_CHAT_REQUEST, 3, 5));

        $aReplies = $this->oProvider->getReplies();
        $this->assertSame(MaceMail::PORT_NOTIFY, $aReplies[1]->getPort());
    }

    public function testSetAvailabilityWorksViaUnicast(): void
    {
        $this->_authenticateAs('JSMITH', 3);
        $oRefl = new ReflectionProperty(MaceMail::class, 'aAvailability');
        $oRefl->setAccessible(true);

        $this->oProvider->unicastPacketIn($this->_quickCommandPacket(MaceMail::OP_SET_AVAILABILITY, 3, 0));

        $this->assertFalse($oRefl->getValue($this->oProvider)['JSMITH']);
    }

    public function testLookRequestUnaffectedByUnicastQuickCommandChange(): void
    {
        // Look was always a self-contained unicast request/reply (0x2A/0x2B),
        // never routed through the quick-command envelope — confirm it still
        // works unchanged alongside the new unicast quick-command path.
        $this->_authenticateAs('JSMITH', 3);
        $this->oStorage->shouldReceive('getMailItem')->andReturn(null);

        $this->oProvider->unicastPacketIn($this->_lookPacket([1]));

        $this->assertSame(MaceMail::PORT_LOOK_REPLY, $this->oProvider->getReplies()[0]->getPort());
    }

    public function testUnknownPortLogsAndProducesNoReply(): void
    {
        $oPacket = new EconetPacket();
        $oPacket->setPort(0xAB);
        $oPacket->setFlags(0x80);
        $oPacket->setSourceNetwork(0);
        $oPacket->setSourceStation(1);
        $oPacket->setData('');
        $this->oProvider->unicastPacketIn($oPacket);
        $this->assertCount(0, $this->oProvider->getReplies());
    }

    // -------------------------------------------------------------------------
    // Logoff (quick command op 2 — no dedicated port)
    // -------------------------------------------------------------------------

    public function testLogoffCallsSecLogoutWhenLoggedIn(): void
    {
        $this->oProvider->stubIsLoggedIn = true;
        $this->oProvider->broadcastPacketIn($this->_quickCommandPacket(MaceMail::OP_LOGOFF, 3, 0, 0, 201));
        $this->assertCount(1, $this->oProvider->capLogout);
        $this->assertSame(0, $this->oProvider->capLogout[0]['iNet']);
        $this->assertSame(201, $this->oProvider->capLogout[0]['iStn']);
    }

    public function testLogoffDoesNothingWhenNotLoggedIn(): void
    {
        $this->oProvider->stubIsLoggedIn = false;
        $this->oProvider->broadcastPacketIn($this->_quickCommandPacket(MaceMail::OP_LOGOFF, 3, 0));
        $this->assertCount(0, $this->oProvider->capLogout);
    }

    public function testLogoffStillProducesTheAck(): void
    {
        $this->oProvider->stubIsLoggedIn = true;
        $this->oProvider->broadcastPacketIn($this->_quickCommandPacket(MaceMail::OP_LOGOFF, 3, 0));
        $this->assertCount(1, $this->oProvider->getReplies());
    }

    // -------------------------------------------------------------------------
    // Logon (0x1B request / 0x1C reply)
    // -------------------------------------------------------------------------

    public function testLogonForUnassignedSlotReturnsUnknownSlotError(): void
    {
        $this->oStorage->shouldReceive('getUsernameForSlot')->with(9)->andReturn(null);

        $this->oProvider->unicastPacketIn($this->_logonPacket('PASS', 9));

        $oReply = $this->oProvider->getReplies()[0];
        $this->assertSame(MaceMail::PORT_LOGON_REPLY, $oReply->getPort());
        $this->assertSame("\xFE", $oReply->getData());
        $this->assertCount(0, $this->oProvider->capLogin);
    }

    public function testLogonAlreadyOnlineElsewhereReturnsError(): void
    {
        $this->oStorage->shouldReceive('getUsernameForSlot')->with(3)->andReturn('JSMITH');
        $this->oProvider->stubUserStation = ['JSMITH' => ['network' => 0, 'station' => 55]];

        $this->oProvider->unicastPacketIn($this->_logonPacket('PASS', 3, 0, 201));

        $oReply = $this->oProvider->getReplies()[0];
        $this->assertSame("\xFD", $oReply->getData());
        $this->assertCount(0, $this->oProvider->capLogin);
    }

    public function testLogonReconnectFromSameStationIsAllowed(): void
    {
        $this->oStorage->shouldReceive('getUsernameForSlot')->with(3)->andReturn('JSMITH');
        $this->oProvider->stubUserStation = ['JSMITH' => ['network' => 0, 'station' => 201]];
        $this->oStorage->shouldReceive('touchLastUsed')->once();
        $this->oStorage->shouldReceive('getUserMeta')->andReturn(['registered' => [1, 2, 24], 'last_used' => [1, 2, 24], 'store_mask' => 0]);
        $this->oStorage->shouldReceive('getMailCounts')->andReturn(['unread_normal' => 0, 'unread_express' => 0, 'read_normal' => 0, 'read_express' => 0]);
        $this->oStorage->shouldReceive('getAllSlots')->andReturn(['3' => 'JSMITH']);

        $this->oProvider->unicastPacketIn($this->_logonPacket('PASS', 3, 0, 201));

        $this->assertCount(1, $this->oProvider->capLogin);
    }

    public function testLogonBadPasswordReturnsError(): void
    {
        $this->oStorage->shouldReceive('getUsernameForSlot')->with(3)->andReturn('JSMITH');
        $this->oProvider->stubUserStation = [];
        $this->oProvider->stubLoginResult = false;

        $this->oProvider->unicastPacketIn($this->_logonPacket('WRONG', 3));

        $oReply = $this->oProvider->getReplies()[0];
        $this->assertSame("\xFC", $oReply->getData());
    }

    public function testLogonPassesPasswordAndResolvedUsernameToSecLogin(): void
    {
        $this->oStorage->shouldReceive('getUsernameForSlot')->with(3)->andReturn('JSMITH');
        $this->oProvider->stubUserStation = [];
        $this->_stubStorageForSuccessfulLogon();

        $this->oProvider->unicastPacketIn($this->_logonPacket('PASS', 3, 0, 201));

        $this->assertSame('JSMITH', $this->oProvider->capLogin[0]['sUser']);
        $this->assertSame('PASS', $this->oProvider->capLogin[0]['sPass']);
    }

    public function testSuccessfulLogonReplyPort(): void
    {
        $this->oStorage->shouldReceive('getUsernameForSlot')->with(3)->andReturn('JSMITH');
        $this->_stubStorageForSuccessfulLogon();

        $this->oProvider->unicastPacketIn($this->_logonPacket('PASS', 3));

        $this->assertSame(MaceMail::PORT_LOGON_REPLY, $this->oProvider->getReplies()[0]->getPort());
    }

    public function testSuccessfulLogonReplyLayout(): void
    {
        $this->oStorage->shouldReceive('getUsernameForSlot')->with(3)->andReturn('JSMITH');
        $this->_stubStorageForSuccessfulLogon(
            registered: [1, 2, 24],
            counts: ['unread_normal' => 2, 'unread_express' => 1, 'read_normal' => 5, 'read_express' => 0],
            storeMask: 0x03,
            slots: ['1' => 'AWILSON', '3' => 'JSMITH']
        );
        $this->oProvider->stubUser  = (new class extends User {
            public function __construct() { $this->setUsername('JSMITH'); }
        });
        $this->oProvider->stubToday = [15, 6, 26];

        $this->oProvider->unicastPacketIn($this->_logonPacket('PASS', 3));

        $sData = $this->oProvider->getReplies()[0]->getData();
        $this->assertSame(45, strlen($sData));
        $this->assertStringStartsWith("JSMITH\x0D", $sData);
        $this->assertSame(2, ord($sData[27]));          // registered user count (2 slots assigned)
        $this->assertSame(0x03, ord($sData[28]));        // store mask
        $this->assertSame([1, 2, 24], [ord($sData[29]), ord($sData[30]), ord($sData[31])]);   // registered date
        $this->assertSame([15, 6, 26], [ord($sData[32]), ord($sData[33]), ord($sData[34])]);  // last-used date (today)
        $this->assertSame(2, ord($sData[41]));  // unread normal
        $this->assertSame(5, ord($sData[42]));  // read normal
        $this->assertSame(1, ord($sData[43]));  // unread express
        $this->assertSame(0, ord($sData[44]));  // read express
    }

    public function testSuccessfulLogonCachesPasswordForSession(): void
    {
        $this->oStorage->shouldReceive('getUsernameForSlot')->with(3)->andReturn('JSMITH');
        $this->_stubStorageForSuccessfulLogon();

        $this->oProvider->unicastPacketIn($this->_logonPacket('PASS', 3, 0, 201));

        $oRefl = new ReflectionProperty(MaceMail::class, 'aSessionPassword');
        $oRefl->setAccessible(true);
        $this->assertSame('PASS', $oRefl->getValue($this->oProvider)['0.201']);
    }

    public function testLogoffClearsCachedSessionPassword(): void
    {
        $this->oStorage->shouldReceive('getUsernameForSlot')->with(3)->andReturn('JSMITH');
        $this->_stubStorageForSuccessfulLogon();
        $this->oProvider->unicastPacketIn($this->_logonPacket('PASS', 3, 0, 201));

        $this->oProvider->stubIsLoggedIn = true;
        $this->oProvider->broadcastPacketIn($this->_quickCommandPacket(MaceMail::OP_LOGOFF, 3, 0, 0, 201));

        $oRefl = new ReflectionProperty(MaceMail::class, 'aSessionPassword');
        $oRefl->setAccessible(true);
        $this->assertArrayNotHasKey('0.201', $oRefl->getValue($this->oProvider));
    }

    protected function _stubStorageForSuccessfulLogon(
        array $registered = [1, 2, 24],
        array $counts = ['unread_normal' => 0, 'unread_express' => 0, 'read_normal' => 0, 'read_express' => 0],
        int $storeMask = 0,
        array $slots = ['3' => 'JSMITH']
    ): void {
        $this->oProvider->stubUserStation = [];
        $this->oProvider->stubLoginResult = true;
        $this->oStorage->shouldReceive('touchLastUsed')->once();
        $this->oStorage->shouldReceive('getUserMeta')->andReturn(['registered' => $registered, 'last_used' => $registered, 'store_mask' => $storeMask]);
        $this->oStorage->shouldReceive('getMailCounts')->andReturn($counts);
        $this->oStorage->shouldReceive('getAllSlots')->andReturn($slots);
    }

    /** Sets up the provider as if $sUsername is already authenticated at (iNet,iStn) holding $iSlot. */
    protected function _authenticateAs(string $sUsername, int $iSlot, int $iNet = 0, int $iStn = 201): void
    {
        $this->oProvider->stubIsLoggedIn = true;
        $this->oProvider->stubUser = new class($sUsername) extends User {
            public function __construct(string $sUsername) { $this->setUsername($sUsername); }
        };
        $this->oStorage->shouldReceive('getSlotForUsername')->with($sUsername)->andReturn($iSlot);
    }

    protected function _fakeUser(string $sUsername): User
    {
        return new class($sUsername) extends User {
            public function __construct(string $sUsername) { $this->setUsername($sUsername); }
        };
    }

    // -------------------------------------------------------------------------
    // Directory (quick command op 4 / 11 -> 0x1F reply)
    // -------------------------------------------------------------------------

    public function testDirectoryOpRejectedWhenNotLoggedIn(): void
    {
        $this->oProvider->stubIsLoggedIn = false;
        $this->oProvider->broadcastPacketIn($this->_quickCommandPacket(MaceMail::OP_DIRECTORY_ALL, 3, 0));

        // Only the universal ack, no directory reply.
        $aReplies = $this->oProvider->getReplies();
        $this->assertCount(1, $aReplies);
        $this->assertSame(MaceMail::PORT_ACK, $aReplies[0]->getPort());
    }

    public function testDirectoryOpRejectedWhenSlotDoesNotMatchSession(): void
    {
        $this->_authenticateAs('JSMITH', 3);
        // Request claims slot 9, but the session is really slot 3.
        $this->oProvider->broadcastPacketIn($this->_quickCommandPacket(MaceMail::OP_DIRECTORY_ALL, 9, 0));

        $aReplies = $this->oProvider->getReplies();
        $this->assertCount(1, $aReplies);
        $this->assertSame(MaceMail::PORT_ACK, $aReplies[0]->getPort());
    }

    public function testDirectoryAllRepliesOnPort0x1F(): void
    {
        $this->_authenticateAs('JSMITH', 3);
        $this->oStorage->shouldReceive('getAllSlots')->andReturn([]);

        $this->oProvider->broadcastPacketIn($this->_quickCommandPacket(MaceMail::OP_DIRECTORY_ALL, 3, 0));

        $aReplies = $this->oProvider->getReplies();
        $this->assertSame(MaceMail::PORT_DIRECTORY_REPLY, $aReplies[1]->getPort());
    }

    public function testAckPortIsNotCorruptedByASecondReplyOnADifferentPort(): void
    {
        // Regression test: the ack (0x1A) and the directory reply (0x1F)
        // share one MaceMailRequest object with setReplyPort() called twice
        // in turn — the ack's own port must stay 0x1A, not pick up the
        // later-set 0x1F because Reply::buildEconetpacket() read the port
        // lazily off the shared request instead of snapshotting it.
        $this->_authenticateAs('JSMITH', 3);
        $this->oStorage->shouldReceive('getAllSlots')->andReturn([]);

        $this->oProvider->broadcastPacketIn($this->_quickCommandPacket(MaceMail::OP_DIRECTORY_ALL, 3, 0));

        $aReplies = $this->oProvider->getReplies();
        $this->assertCount(2, $aReplies);
        $this->assertSame(MaceMail::PORT_ACK, $aReplies[0]->getPort());
        $this->assertSame(MaceMail::PORT_DIRECTORY_REPLY, $aReplies[1]->getPort());
    }

    public function testDirectoryAllWithNoUsersHasZeroCount(): void
    {
        $this->_authenticateAs('JSMITH', 3);
        $this->oStorage->shouldReceive('getAllSlots')->andReturn([]);

        $this->oProvider->broadcastPacketIn($this->_quickCommandPacket(MaceMail::OP_DIRECTORY_ALL, 3, 0));

        $sData = $this->oProvider->getReplies()[1]->getData();
        $this->assertSame("\x00", $sData);
    }

    public function testDirectoryAllListsEveryRegisteredSlotSortedByNumber(): void
    {
        $this->_authenticateAs('JSMITH', 3);
        $this->oStorage->shouldReceive('getAllSlots')->andReturn([5 => 'BROWN', 1 => 'AWILSON', 3 => 'JSMITH']);

        $this->oProvider->broadcastPacketIn($this->_quickCommandPacket(MaceMail::OP_DIRECTORY_ALL, 3, 0));

        $sData = $this->oProvider->getReplies()[1]->getData();
        $this->assertSame(3, ord($sData[0]));
        // Record 0: 29-byte name field then slot number, sorted by slot ascending (1, 3, 5).
        $this->assertStringStartsWith("AWILSON\x0D", substr($sData, 1, 29));
        $this->assertSame(1, ord($sData[30]));
        $this->assertStringStartsWith("JSMITH\x0D", substr($sData, 31, 29));
        $this->assertSame(3, ord($sData[60]));
        $this->assertStringStartsWith("BROWN\x0D", substr($sData, 61, 29));
        $this->assertSame(5, ord($sData[90]));
    }

    public function testDirectoryAllIncludesUsersWhoAreOffline(): void
    {
        $this->_authenticateAs('JSMITH', 3);
        $this->oStorage->shouldReceive('getAllSlots')->andReturn([1 => 'AWILSON']);
        $this->oProvider->stubUsersOnline = [];

        $this->oProvider->broadcastPacketIn($this->_quickCommandPacket(MaceMail::OP_DIRECTORY_ALL, 3, 0));

        $sData = $this->oProvider->getReplies()[1]->getData();
        $this->assertSame(1, ord($sData[0]));
    }

    public function testDirectoryOnlineExcludesTheRequesterThemselves(): void
    {
        $this->_authenticateAs('JSMITH', 3);
        $this->oProvider->stubUsersOnline = [
            0 => [201 => ['user' => $this->_fakeUser('JSMITH')]],
        ];

        $this->oProvider->broadcastPacketIn($this->_quickCommandPacket(MaceMail::OP_DIRECTORY_ONLINE, 3, 0));

        $sData = $this->oProvider->getReplies()[1]->getData();
        $this->assertSame(0, ord($sData[0]));
    }

    public function testDirectoryOnlineListsOtherOnlineUsersOnly(): void
    {
        $this->_authenticateAs('JSMITH', 3);
        $this->oProvider->stubUsersOnline = [
            0 => [
                201 => ['user' => $this->_fakeUser('JSMITH')],
                55  => ['user' => $this->_fakeUser('AWILSON')],
            ],
        ];
        $this->oStorage->shouldReceive('getSlotForUsername')->with('AWILSON')->andReturn(1);

        $this->oProvider->broadcastPacketIn($this->_quickCommandPacket(MaceMail::OP_DIRECTORY_ONLINE, 3, 0));

        $sData = $this->oProvider->getReplies()[1]->getData();
        $this->assertSame(1, ord($sData[0]));
        $this->assertStringStartsWith("AWILSON\x0D", substr($sData, 1, 29));
    }

    public function testDirectoryOnlineSkipsAnOnlineUserWithNoAssignedSlot(): void
    {
        $this->_authenticateAs('JSMITH', 3);
        $this->oProvider->stubUsersOnline = [
            0 => [77 => ['user' => $this->_fakeUser('GHOST')]],
        ];
        $this->oStorage->shouldReceive('getSlotForUsername')->with('GHOST')->andReturn(null);

        $this->oProvider->broadcastPacketIn($this->_quickCommandPacket(MaceMail::OP_DIRECTORY_ONLINE, 3, 0));

        $sData = $this->oProvider->getReplies()[1]->getData();
        $this->assertSame(0, ord($sData[0]));
    }

    public function testDirectoryUpdatesIdleTimerOnSuccess(): void
    {
        // secUpdateIdleTimer is a no-op stub here; this just confirms the
        // authenticated path completes without needing any extra stubbing
        // beyond what resolveAuthenticatedUsername requires.
        $this->_authenticateAs('JSMITH', 3);
        $this->oStorage->shouldReceive('getAllSlots')->andReturn([]);
        $this->oProvider->broadcastPacketIn($this->_quickCommandPacket(MaceMail::OP_DIRECTORY_ALL, 3, 0));
        $this->assertCount(2, $this->oProvider->getReplies());
    }

    // -------------------------------------------------------------------------
    // Mail check (quick command op 10 -> 0x25 reply)
    // -------------------------------------------------------------------------

    public function testMailCheckRejectedWhenNotLoggedIn(): void
    {
        $this->oProvider->stubIsLoggedIn = false;
        $this->oProvider->broadcastPacketIn($this->_quickCommandPacket(MaceMail::OP_MAIL_CHECK, 3, 0));
        $this->assertCount(1, $this->oProvider->getReplies());
    }

    public function testMailCheckRepliesOnPort0x25(): void
    {
        $this->_authenticateAs('JSMITH', 3);
        $this->oStorage->shouldReceive('getMailCounts')->with('JSMITH')->andReturn(
            ['unread_normal' => 0, 'unread_express' => 0, 'read_normal' => 0, 'read_express' => 0]
        );

        $this->oProvider->broadcastPacketIn($this->_quickCommandPacket(MaceMail::OP_MAIL_CHECK, 3, 0));

        $this->assertSame(MaceMail::PORT_MAILCHECK_REPLY, $this->oProvider->getReplies()[1]->getPort());
    }

    public function testMailCheckReplyLayout(): void
    {
        $this->_authenticateAs('JSMITH', 3);
        $this->oStorage->shouldReceive('getMailCounts')->with('JSMITH')->andReturn(
            ['unread_normal' => 5, 'unread_express' => 2, 'read_normal' => 7, 'read_express' => 1]
        );

        $this->oProvider->broadcastPacketIn($this->_quickCommandPacket(MaceMail::OP_MAIL_CHECK, 3, 0));

        $sData = $this->oProvider->getReplies()[1]->getData();
        $this->assertSame(5, strlen($sData));
        $this->assertSame(15, ord($sData[0])); // total
        $this->assertSame(2, ord($sData[1]));  // unread express
        $this->assertSame(1, ord($sData[2]));  // read express
        $this->assertSame(5, ord($sData[3]));  // unread normal
        $this->assertSame(7, ord($sData[4]));  // read normal
    }

    public function testMailCheckWithNoMailIsAllZero(): void
    {
        $this->_authenticateAs('JSMITH', 3);
        $this->oStorage->shouldReceive('getMailCounts')->with('JSMITH')->andReturn(
            ['unread_normal' => 0, 'unread_express' => 0, 'read_normal' => 0, 'read_express' => 0]
        );

        $this->oProvider->broadcastPacketIn($this->_quickCommandPacket(MaceMail::OP_MAIL_CHECK, 3, 0));

        $this->assertSame("\x00\x00\x00\x00\x00", $this->oProvider->getReplies()[1]->getData());
    }

    // -------------------------------------------------------------------------
    // Who-am-I (quick command op 5 — ack only, no further reply)
    // -------------------------------------------------------------------------

    public function testWhoAmIProducesOnlyTheAck(): void
    {
        $this->_authenticateAs('JSMITH', 3);
        $this->oProvider->broadcastPacketIn($this->_quickCommandPacket(MaceMail::OP_WHOAMI, 3, 0));

        $aReplies = $this->oProvider->getReplies();
        $this->assertCount(1, $aReplies);
        $this->assertSame(MaceMail::PORT_ACK, $aReplies[0]->getPort());
    }

    // -------------------------------------------------------------------------
    // Save mail (port 0x26 request, no dedicated reply)
    // -------------------------------------------------------------------------

    public function testSaveMailRejectedWhenNotLoggedIn(): void
    {
        $this->oProvider->stubIsLoggedIn = false;
        $this->oStorage->shouldNotReceive('addMailItem');

        $this->oProvider->unicastPacketIn($this->_saveMailPacket('Hello', 'Subj', 0, false, [7]));

        $this->assertCount(0, $this->oProvider->getReplies());
    }

    public function testSaveMailRejectedWhenSenderHasNoSlot(): void
    {
        $this->oProvider->stubIsLoggedIn = true;
        $this->oProvider->stubUser = $this->_fakeUser('JSMITH');
        $this->oStorage->shouldReceive('getSlotForUsername')->with('JSMITH')->andReturn(null);
        $this->oStorage->shouldNotReceive('addMailItem');

        $this->oProvider->unicastPacketIn($this->_saveMailPacket('Hello', 'Subj', 0, false, [7]));
    }

    public function testSaveMailDeliversToExplicitRecipient(): void
    {
        $this->_authenticateAs('JSMITH', 3);
        $this->oStorage->shouldReceive('getUsernameForSlot')->with(7)->andReturn('AWILSON');
        $this->oStorage->shouldReceive('addMailItem')
            ->once()
            ->with('AWILSON', Mockery::on(function (array $aHeader) {
                return $aHeader['sender_slot'] === 3
                    && trim($aHeader['subject']) === 'Hello there'
                    && $aHeader['type'] === 0
                    && $aHeader['express'] === false
                    && $aHeader['ack_requested'] === false
                    && $aHeader['reply_requested'] === false;
            }), Mockery::on(fn($sBody) => str_starts_with($sBody, 'Message body')))
            ->andReturn(1);

        $this->oProvider->unicastPacketIn(
            $this->_saveMailPacket('Message body', 'Hello there', 0, false, [7])
        );
    }

    public function testSaveMailWithAckAndExpressFlags(): void
    {
        $this->_authenticateAs('JSMITH', 3);
        $this->oStorage->shouldReceive('getUsernameForSlot')->with(7)->andReturn('AWILSON');
        $this->oStorage->shouldReceive('addMailItem')
            ->once()
            ->with('AWILSON', Mockery::on(function (array $aHeader) {
                return $aHeader['express'] === true && $aHeader['ack_requested'] === true;
            }), Mockery::any())
            ->andReturn(1);

        // TO flag 1 = ack-requested, TO flag 2 = express.
        $this->oProvider->unicastPacketIn(
            $this->_saveMailPacket('Body', 'Subj', 0, false, [7], 1, 3)
        );
    }

    public function testSaveMailWithReplyRequestedFlag(): void
    {
        $this->_authenticateAs('JSMITH', 3);
        $this->oStorage->shouldReceive('getUsernameForSlot')->with(7)->andReturn('AWILSON');
        $this->oStorage->shouldReceive('addMailItem')
            ->once()
            ->with('AWILSON', Mockery::on(fn(array $aHeader) => $aHeader['reply_requested'] === true), Mockery::any())
            ->andReturn(1);

        $this->oProvider->unicastPacketIn(
            $this->_saveMailPacket('Body', 'Subj', 0, false, [7], 4, 64)
        );
    }

    public function testSaveMailToEveryoneDeliversToAllRegisteredSlots(): void
    {
        $this->_authenticateAs('JSMITH', 3);
        $this->oStorage->shouldReceive('getAllSlots')->andReturn([1 => 'AWILSON', 3 => 'JSMITH']);
        $this->oStorage->shouldReceive('getUsernameForSlot')->with(1)->andReturn('AWILSON');
        $this->oStorage->shouldReceive('getUsernameForSlot')->with(3)->andReturn('JSMITH');
        $this->oStorage->shouldReceive('addMailItem')->twice()->andReturn(1);

        $this->oProvider->unicastPacketIn(
            $this->_saveMailPacket('Body', 'Subj', 0, true, [])
        );
    }

    public function testSaveMailSkipsUnassignedToSlots(): void
    {
        $this->_authenticateAs('JSMITH', 3);
        $this->oStorage->shouldReceive('getUsernameForSlot')->with(9)->andReturn(null);
        $this->oStorage->shouldNotReceive('addMailItem');

        $this->oProvider->unicastPacketIn(
            $this->_saveMailPacket('Body', 'Subj', 0, false, [9])
        );
    }

    public function testSaveMailNotifiesAnOnlineRecipient(): void
    {
        $this->_authenticateAs('JSMITH', 3);
        $this->oStorage->shouldReceive('getUsernameForSlot')->with(7)->andReturn('AWILSON');
        $this->oStorage->shouldReceive('addMailItem')->once()->andReturn(1);
        $this->oProvider->stubUserStation = ['AWILSON' => ['network' => 0, 'station' => 55]];

        $this->oProvider->unicastPacketIn(
            $this->_saveMailPacket('Body', 'Subj', 0, false, [7])
        );

        $aReplies = $this->oProvider->getReplies();
        $this->assertCount(1, $aReplies);
        $this->assertSame(MaceMail::PORT_NOTIFY, $aReplies[0]->getPort());
        $this->assertSame(0, $aReplies[0]->getDestinationNetwork());
        $this->assertSame(55, $aReplies[0]->getDestinationStation());
        $this->assertSame(chr(7) . chr(0) . chr(0) . chr(0), $aReplies[0]->getData());
    }

    public function testSaveMailDoesNotNotifyAnOfflineRecipient(): void
    {
        $this->_authenticateAs('JSMITH', 3);
        $this->oStorage->shouldReceive('getUsernameForSlot')->with(7)->andReturn('AWILSON');
        $this->oStorage->shouldReceive('addMailItem')->once()->andReturn(1);
        $this->oProvider->stubUserStation = [];

        $this->oProvider->unicastPacketIn(
            $this->_saveMailPacket('Body', 'Subj', 0, false, [7])
        );

        $this->assertCount(0, $this->oProvider->getReplies());
    }

    // -------------------------------------------------------------------------
    // Get mail list (quick command op 12/13 -> 0x27 reply)
    // -------------------------------------------------------------------------

    public function testGetMailRejectedWhenNotLoggedIn(): void
    {
        $this->oProvider->stubIsLoggedIn = false;
        $this->oProvider->broadcastPacketIn($this->_quickCommandPacket(MaceMail::OP_GET_MAIL_NEW, 3, 0));
        $this->assertCount(1, $this->oProvider->getReplies());
    }

    public function testGetMailRepliesOnPort0x27(): void
    {
        $this->_authenticateAs('JSMITH', 3);
        $this->oStorage->shouldReceive('getMailCounts')->andReturn(
            ['unread_normal' => 0, 'unread_express' => 0, 'read_normal' => 0, 'read_express' => 0]
        );
        $this->oStorage->shouldReceive('getMailIndex')->andReturn([]);

        $this->oProvider->broadcastPacketIn($this->_quickCommandPacket(MaceMail::OP_GET_MAIL_NEW, 3, 0));

        $this->assertSame(MaceMail::PORT_MAIL_LIST_REPLY, $this->oProvider->getReplies()[1]->getPort());
    }

    public function testGetMailOldUsesTheSameHandler(): void
    {
        $this->_authenticateAs('JSMITH', 3);
        $this->oStorage->shouldReceive('getMailCounts')->andReturn(
            ['unread_normal' => 0, 'unread_express' => 0, 'read_normal' => 0, 'read_express' => 0]
        );
        $this->oStorage->shouldReceive('getMailIndex')->andReturn([]);

        $this->oProvider->broadcastPacketIn($this->_quickCommandPacket(MaceMail::OP_GET_MAIL_OLD, 3, 0));

        $this->assertSame(MaceMail::PORT_MAIL_LIST_REPLY, $this->oProvider->getReplies()[1]->getPort());
    }

    public function testGetMailReplyLayout(): void
    {
        $this->_authenticateAs('JSMITH', 3);
        $this->oStorage->shouldReceive('getMailCounts')->andReturn(
            ['unread_normal' => 1, 'unread_express' => 1, 'read_normal' => 0, 'read_express' => 0]
        );
        $this->oStorage->shouldReceive('getMailIndex')->andReturn([
            ['id' => 1, 'read' => false, 'express' => true],
            ['id' => 2, 'read' => true,  'express' => false],
        ]);

        $this->oProvider->broadcastPacketIn($this->_quickCommandPacket(MaceMail::OP_GET_MAIL_NEW, 3, 0));

        $sData = $this->oProvider->getReplies()[1]->getData();
        $this->assertSame(9, strlen($sData));
        $this->assertSame(2, ord($sData[0]));   // total
        $this->assertSame(1, ord($sData[5]));   // first entry id
        $this->assertSame(0x40, ord($sData[6])); // unread + express => express bit only
        $this->assertSame(2, ord($sData[7]));   // second entry id
        $this->assertSame(0x80, ord($sData[8])); // read, not express => read bit only
    }

    // -------------------------------------------------------------------------
    // Individual mail item (quick command op 14 -> 0x28 reply)
    // -------------------------------------------------------------------------

    public function testIndividualMailAccessDeniedWhenNotLoggedIn(): void
    {
        $this->oProvider->stubIsLoggedIn = false;
        $this->oProvider->broadcastPacketIn($this->_quickCommandPacket(MaceMail::OP_INDIVIDUAL_MAIL, 3, 5));

        $oReply = $this->oProvider->getReplies()[1];
        $this->assertSame(MaceMail::PORT_MAIL_ITEM_REPLY, $oReply->getPort());
        $this->assertSame("\xFF\xFF\xFF\xFF", $oReply->getData());
    }

    public function testIndividualMailAccessDeniedWhenMessageDoesNotExist(): void
    {
        $this->_authenticateAs('JSMITH', 3);
        $this->oStorage->shouldReceive('getMailItem')->with('JSMITH', 5)->andReturn(null);

        $this->oProvider->broadcastPacketIn($this->_quickCommandPacket(MaceMail::OP_INDIVIDUAL_MAIL, 3, 5));

        $oReply = $this->oProvider->getReplies()[1];
        $this->assertSame("\xFF\xFF\xFF\xFF", $oReply->getData());
    }

    public function testIndividualMailReplyIsAlways768Bytes(): void
    {
        $this->_authenticateAs('JSMITH', 3);
        $this->oStorage->shouldReceive('getMailItem')->with('JSMITH', 5)->andReturn([
            'id' => 5, 'subject' => 'Test subject', 'sender_slot' => 9,
            'ack_requested' => false, 'reply_requested' => false,
        ]);
        $this->oStorage->shouldReceive('getMailBody')->with('JSMITH', 5)->andReturn('Message body text');
        $this->oStorage->shouldReceive('markMailRead')->with('JSMITH', 5)->once();

        $this->oProvider->broadcastPacketIn($this->_quickCommandPacket(MaceMail::OP_INDIVIDUAL_MAIL, 3, 5));

        $sData = $this->oProvider->getReplies()[1]->getData();
        $this->assertSame(768, strlen($sData));
        $this->assertStringStartsWith('Message body text', $sData);
        $this->assertSame('Test subject', trim(substr($sData, 440, 28)));
    }

    public function testIndividualMailActionFlagsByte(): void
    {
        $this->_authenticateAs('JSMITH', 3);
        $this->oStorage->shouldReceive('getMailItem')->with('JSMITH', 5)->andReturn([
            'id' => 5, 'subject' => '', 'sender_slot' => 9,
            'ack_requested' => true, 'reply_requested' => true,
        ]);
        $this->oStorage->shouldReceive('getMailBody')->andReturn('');
        $this->oStorage->shouldReceive('markMailRead')->once();

        $this->oProvider->broadcastPacketIn($this->_quickCommandPacket(MaceMail::OP_INDIVIDUAL_MAIL, 3, 5));

        $sData = $this->oProvider->getReplies()[1]->getData();
        $this->assertSame(255, ord($sData[469]));
    }

    public function testIndividualMailSenderSlotByteAtComputedOffset(): void
    {
        $this->_authenticateAs('JSMITH', 3);
        // id 9 => 9 % 7 = 2 => offset 541 + 35*2 = 611.
        $this->oStorage->shouldReceive('getMailItem')->with('JSMITH', 9)->andReturn([
            'id' => 9, 'subject' => '', 'sender_slot' => 42,
            'ack_requested' => false, 'reply_requested' => false,
        ]);
        $this->oStorage->shouldReceive('getMailBody')->andReturn('');
        $this->oStorage->shouldReceive('markMailRead')->once();

        $this->oProvider->broadcastPacketIn($this->_quickCommandPacket(MaceMail::OP_INDIVIDUAL_MAIL, 3, 9));

        $sData = $this->oProvider->getReplies()[1]->getData();
        $this->assertSame(42, ord($sData[611]));
    }

    // -------------------------------------------------------------------------
    // Delete mail (quick command op 16 — no dedicated reply port)
    // -------------------------------------------------------------------------

    public function testDeleteMailCallsStorageWhenAuthenticated(): void
    {
        $this->_authenticateAs('JSMITH', 3);
        $this->oStorage->shouldReceive('deleteMailItem')->once()->with('JSMITH', 5);

        $this->oProvider->broadcastPacketIn($this->_quickCommandPacket(MaceMail::OP_DELETE_MAIL, 3, 5));
    }

    public function testDeleteMailDoesNothingWhenNotLoggedIn(): void
    {
        $this->oProvider->stubIsLoggedIn = false;
        $this->oStorage->shouldNotReceive('deleteMailItem');

        $this->oProvider->broadcastPacketIn($this->_quickCommandPacket(MaceMail::OP_DELETE_MAIL, 3, 5));
    }

    // -------------------------------------------------------------------------
    // Store slots (quick command ops 7/8/9 -> ports 0x21/0x23)
    // -------------------------------------------------------------------------

    public function testGetStoreRejectedWhenNotLoggedIn(): void
    {
        $this->oProvider->stubIsLoggedIn = false;
        $this->oStorage->shouldNotReceive('getStoreSlot');

        $this->oProvider->broadcastPacketIn($this->_quickCommandPacket(MaceMail::OP_GET_STORE, 3, 2));

        $this->assertCount(1, $this->oProvider->getReplies());
    }

    public function testGetStoreRepliesOnPort0x21With440Bytes(): void
    {
        $this->_authenticateAs('JSMITH', 3);
        $this->oStorage->shouldReceive('getStoreSlot')->with('JSMITH', 2)->andReturn('saved file content');

        $this->oProvider->broadcastPacketIn($this->_quickCommandPacket(MaceMail::OP_GET_STORE, 3, 2));

        $oReply = $this->oProvider->getReplies()[1];
        $this->assertSame(MaceMail::PORT_STORE_RECALL_REPLY, $oReply->getPort());
        $this->assertSame(440, strlen($oReply->getData()));
        $this->assertStringStartsWith('saved file content', $oReply->getData());
    }

    public function testGetStoreOnAnEmptySlotIsAllZero(): void
    {
        $this->_authenticateAs('JSMITH', 3);
        $this->oStorage->shouldReceive('getStoreSlot')->with('JSMITH', 5)->andReturn('');

        $this->oProvider->broadcastPacketIn($this->_quickCommandPacket(MaceMail::OP_GET_STORE, 3, 5));

        $sData = $this->oProvider->getReplies()[1]->getData();
        $this->assertSame(str_repeat("\0", 440), $sData);
    }

    public function testSaveStoreQuickCommandAloneDoesNotWriteStorage(): void
    {
        $this->_authenticateAs('JSMITH', 3);
        $this->oStorage->shouldNotReceive('setStoreSlot');

        $this->oProvider->broadcastPacketIn($this->_quickCommandPacket(MaceMail::OP_SAVE_STORE, 3, 4));
    }

    public function testSaveStoreWritesOnceTheDataArrivesOnItsOwnPort(): void
    {
        $this->_authenticateAs('JSMITH', 3);
        $this->oStorage->shouldReceive('setStoreSlot')
            ->once()
            ->with('JSMITH', 4, Mockery::on(fn($sData) => str_starts_with($sData, 'file contents')));

        $this->oProvider->broadcastPacketIn($this->_quickCommandPacket(MaceMail::OP_SAVE_STORE, 3, 4));
        $this->oProvider->unicastPacketIn($this->_storeSaveDataPacket('file contents'));
    }

    public function testSaveStoreDataWithNoPrecedingQuickCommandIsIgnored(): void
    {
        $this->oStorage->shouldNotReceive('setStoreSlot');
        $this->oProvider->unicastPacketIn($this->_storeSaveDataPacket('unexpected data'));
    }

    public function testSaveStoreDataIsOnlyConsumedOnce(): void
    {
        $this->_authenticateAs('JSMITH', 3);
        $this->oStorage->shouldReceive('setStoreSlot')->once()->with('JSMITH', 4, Mockery::any());

        $this->oProvider->broadcastPacketIn($this->_quickCommandPacket(MaceMail::OP_SAVE_STORE, 3, 4));
        $this->oProvider->unicastPacketIn($this->_storeSaveDataPacket('first'));
        // A second, unrelated arrival on the same port with no fresh op-8
        // quick command behind it has nothing pending, so it is ignored.
        $this->oProvider->unicastPacketIn($this->_storeSaveDataPacket('second'));
    }

    public function testDeleteStoreRejectedWhenNotLoggedIn(): void
    {
        $this->oProvider->stubIsLoggedIn = false;
        $this->oStorage->shouldNotReceive('setStoreMask');

        $this->oProvider->broadcastPacketIn($this->_quickCommandPacket(MaceMail::OP_DELETE_STORE, 3, 0x05));
    }

    public function testDeleteStoreOverwritesTheWholeMask(): void
    {
        $this->_authenticateAs('JSMITH', 3);
        $this->oStorage->shouldReceive('setStoreMask')->once()->with('JSMITH', 0x05);

        $this->oProvider->broadcastPacketIn($this->_quickCommandPacket(MaceMail::OP_DELETE_STORE, 3, 0x05));
    }

    // -------------------------------------------------------------------------
    // Mailbox scan (quick command op 17 -> 0x29 reply)
    // -------------------------------------------------------------------------

    public function testMailboxScanRejectedWhenNotLoggedIn(): void
    {
        $this->oProvider->stubIsLoggedIn = false;
        $this->oProvider->broadcastPacketIn($this->_quickCommandPacket(MaceMail::OP_MAILBOX_SCAN, 3, 0));
        $this->assertCount(1, $this->oProvider->getReplies());
    }

    public function testMailboxScanRepliesOnPort0x29With512Bytes(): void
    {
        $this->_authenticateAs('JSMITH', 3);
        $this->oStorage->shouldReceive('getMailIndex')->with('JSMITH')->andReturn([
            ['id' => 1], ['id' => 2],
        ]);

        $this->oProvider->broadcastPacketIn($this->_quickCommandPacket(MaceMail::OP_MAILBOX_SCAN, 3, 0));

        $oReply = $this->oProvider->getReplies()[1];
        $this->assertSame(MaceMail::PORT_MAILBOX_SCAN_REPLY, $oReply->getPort());
        $sData = $oReply->getData();
        $this->assertSame(512, strlen($sData));
        $this->assertSame(1, ord($sData[0]));
        $this->assertSame(2, ord($sData[1]));
        $this->assertSame(0, ord($sData[2]));
    }

    // -------------------------------------------------------------------------
    // Look (port 0x2A request / 0x2B reply — self-contained, not routed via
    // the quick-command dispatch)
    // -------------------------------------------------------------------------

    public function testLookRejectedWhenNotLoggedIn(): void
    {
        $this->oProvider->stubIsLoggedIn = false;
        $this->oProvider->unicastPacketIn($this->_lookPacket([1]));
        $this->assertCount(0, $this->oProvider->getReplies());
    }

    public function testLookRepliesOnPort0x2BWith210Bytes(): void
    {
        $this->_authenticateAs('JSMITH', 3);
        $this->oStorage->shouldReceive('getMailItem')->andReturn(null);

        $this->oProvider->unicastPacketIn($this->_lookPacket([1, 2]));

        $oReply = $this->oProvider->getReplies()[0];
        $this->assertSame(MaceMail::PORT_LOOK_REPLY, $oReply->getPort());
        $this->assertSame(6 * 35, strlen($oReply->getData()));
    }

    public function testLookReturnsAllZeroRecordForAnUnknownId(): void
    {
        $this->_authenticateAs('JSMITH', 3);
        $this->oStorage->shouldReceive('getMailItem')->with('JSMITH', 9)->andReturn(null);

        $this->oProvider->unicastPacketIn($this->_lookPacket([9]));

        $sData = $this->oProvider->getReplies()[0]->getData();
        $this->assertSame(str_repeat("\0", 35), substr($sData, 0, 35));
    }

    public function testLookRecordLayoutForAKnownMessage(): void
    {
        $this->_authenticateAs('JSMITH', 3);
        $this->oStorage->shouldReceive('getMailItem')->with('JSMITH', 1)->andReturn([
            'id' => 1, 'sender_slot' => 9, 'subject' => 'Test',
            'date' => [15, 6, 26], 'read' => true, 'express' => true,
            'ack_requested' => false, 'reply_requested' => true,
        ]);

        $this->oProvider->unicastPacketIn($this->_lookPacket([1]));

        $sData = substr($this->oProvider->getReplies()[0]->getData(), 0, 35);
        $this->assertSame(1, ord($sData[0]));
        $this->assertSame(9, ord($sData[1]));
        $this->assertSame(1 | 2 | 8, ord($sData[2])); // read + express + reply_requested
        $this->assertSame([15, 6, 26], [ord($sData[3]), ord($sData[4]), ord($sData[5])]);
        $this->assertSame('Test', trim(substr($sData, 6, 29)));
    }

    // -------------------------------------------------------------------------
    // Chat (quick command op 15 -> async invite on port 0x40)
    // -------------------------------------------------------------------------

    public function testChatRejectedWhenCallerNotLoggedIn(): void
    {
        $this->oProvider->stubIsLoggedIn = false;
        $this->oProvider->broadcastPacketIn($this->_quickCommandPacket(MaceMail::OP_CHAT_REQUEST, 3, 5));
        $this->assertCount(1, $this->oProvider->getReplies());
    }

    public function testChatDoesNothingWhenTargetSlotUnassigned(): void
    {
        $this->_authenticateAs('JSMITH', 3);
        $this->oStorage->shouldReceive('getUsernameForSlot')->with(5)->andReturn(null);

        $this->oProvider->broadcastPacketIn($this->_quickCommandPacket(MaceMail::OP_CHAT_REQUEST, 3, 5));

        $this->assertCount(1, $this->oProvider->getReplies());
    }

    public function testChatDoesNothingWhenTargetIsOffline(): void
    {
        $this->_authenticateAs('JSMITH', 3);
        $this->oStorage->shouldReceive('getUsernameForSlot')->with(5)->andReturn('AWILSON');
        $this->oProvider->stubUserStation = [];

        $this->oProvider->broadcastPacketIn($this->_quickCommandPacket(MaceMail::OP_CHAT_REQUEST, 3, 5));

        $this->assertCount(1, $this->oProvider->getReplies());
    }

    public function testChatDoesNothingWhenTargetIsMarkedBusy(): void
    {
        $this->_authenticateAs('JSMITH', 3);
        $this->oStorage->shouldReceive('getUsernameForSlot')->with(5)->andReturn('AWILSON');
        $this->oProvider->stubUserStation = ['AWILSON' => ['network' => 0, 'station' => 55]];

        $oRefl = new ReflectionProperty(MaceMail::class, 'aAvailability');
        $oRefl->setAccessible(true);
        $oRefl->setValue($this->oProvider, ['AWILSON' => false]);

        $this->oProvider->broadcastPacketIn($this->_quickCommandPacket(MaceMail::OP_CHAT_REQUEST, 3, 5));

        $this->assertCount(1, $this->oProvider->getReplies());
    }

    public function testChatSendsInviteToAnOnlineAvailableTarget(): void
    {
        $this->_authenticateAs('JSMITH', 3);
        $this->oStorage->shouldReceive('getUsernameForSlot')->with(5)->andReturn('AWILSON');
        $this->oProvider->stubUserStation = ['AWILSON' => ['network' => 0, 'station' => 55]];

        $this->oProvider->broadcastPacketIn($this->_quickCommandPacket(MaceMail::OP_CHAT_REQUEST, 3, 5, 0, 201));

        $aReplies = $this->oProvider->getReplies();
        $this->assertCount(2, $aReplies);
        $oInvite = $aReplies[1];
        $this->assertSame(MaceMail::PORT_NOTIFY, $oInvite->getPort());
        $this->assertSame(0, $oInvite->getDestinationNetwork());
        $this->assertSame(55, $oInvite->getDestinationStation());
        // type=1 (chat invite), E1=caller's own slot (3), E2=caller's own station (201), pad=0
        $this->assertSame(chr(1) . chr(3) . chr(201) . chr(0), $oInvite->getData());
    }

    // -------------------------------------------------------------------------
    // Set availability (quick command op 20 — no dedicated reply)
    // -------------------------------------------------------------------------

    public function testSetAvailabilityRejectedWhenNotLoggedIn(): void
    {
        $this->oProvider->stubIsLoggedIn = false;
        $this->oProvider->broadcastPacketIn($this->_quickCommandPacket(MaceMail::OP_SET_AVAILABILITY, 3, 0));

        $oRefl = new ReflectionProperty(MaceMail::class, 'aAvailability');
        $oRefl->setAccessible(true);
        $this->assertSame([], $oRefl->getValue($this->oProvider));
    }

    public function testSetAvailabilityToBusyThenAvailableAgain(): void
    {
        $this->_authenticateAs('JSMITH', 3);
        $oRefl = new ReflectionProperty(MaceMail::class, 'aAvailability');
        $oRefl->setAccessible(true);

        $this->oProvider->broadcastPacketIn($this->_quickCommandPacket(MaceMail::OP_SET_AVAILABILITY, 3, 0));
        $this->assertFalse($oRefl->getValue($this->oProvider)['JSMITH']);

        $this->oProvider->broadcastPacketIn($this->_quickCommandPacket(MaceMail::OP_SET_AVAILABILITY, 3, 1));
        $this->assertTrue($oRefl->getValue($this->oProvider)['JSMITH']);
    }

    public function testLogoffClearsAvailabilityFlag(): void
    {
        $this->_authenticateAs('JSMITH', 3);
        $this->oProvider->broadcastPacketIn($this->_quickCommandPacket(MaceMail::OP_SET_AVAILABILITY, 3, 0));

        $this->oProvider->stubIsLoggedIn = true;
        $this->oProvider->broadcastPacketIn($this->_quickCommandPacket(MaceMail::OP_LOGOFF, 3, 0));

        $oRefl = new ReflectionProperty(MaceMail::class, 'aAvailability');
        $oRefl->setAccessible(true);
        $this->assertArrayNotHasKey('JSMITH', $oRefl->getValue($this->oProvider));
    }

    // -------------------------------------------------------------------------
    // Admin support
    // -------------------------------------------------------------------------

    public function testGetRegisteredSlotsIsEmptyWithNoAssignments(): void
    {
        $this->oStorage->shouldReceive('getAllSlots')->andReturn([]);
        $this->assertSame([], $this->oProvider->getRegisteredSlots());
    }

    public function testGetRegisteredSlotsReportsOnlineStatusAndSortsBySlot(): void
    {
        $this->oStorage->shouldReceive('getAllSlots')->andReturn([5 => 'BROWN', 1 => 'AWILSON']);
        $this->oStorage->shouldReceive('getUserMeta')->with('BROWN')->andReturn(['last_used' => [1, 2, 24], 'store_mask' => 0]);
        $this->oStorage->shouldReceive('getUserMeta')->with('AWILSON')->andReturn(['last_used' => [15, 6, 26], 'store_mask' => 3]);
        $this->oProvider->stubUserStation = ['AWILSON' => ['network' => 0, 'station' => 201]];

        $aSlots = $this->oProvider->getRegisteredSlots();

        $this->assertSame([1, 5], array_column($aSlots, 'slot'));
        $this->assertSame(['AWILSON', 'BROWN'], array_column($aSlots, 'username'));
        $this->assertTrue($aSlots[0]['online']);
        $this->assertFalse($aSlots[1]['online']);
        $this->assertSame('15/06/26', $aSlots[0]['last_used']);
        $this->assertSame(3, $aSlots[0]['store_mask']);
    }

    public function testGetOnlineMailUsersOnlyIncludesOnlineSlots(): void
    {
        $this->oStorage->shouldReceive('getAllSlots')->andReturn([1 => 'AWILSON', 3 => 'JSMITH']);
        $this->oProvider->stubUserStation = ['AWILSON' => ['network' => 0, 'station' => 55]];

        $aOnline = $this->oProvider->getOnlineMailUsers();

        $this->assertSame([['username' => 'AWILSON', 'network' => 0, 'station' => 55]], $aOnline);
    }

    public function testAdminAssignSlotRejectsOutOfRangeSlot(): void
    {
        $this->oStorage->shouldNotReceive('assignSlot');
        $this->expectException(\InvalidArgumentException::class);
        $this->oProvider->adminAssignSlot(-1, 'JSMITH');
    }

    public function testAdminAssignSlotRejectsUnknownUsername(): void
    {
        $this->oStorage->shouldNotReceive('assignSlot');
        $this->expectException(\InvalidArgumentException::class);
        $this->oProvider->adminAssignSlot(3, 'GHOST');
    }

    public function testAdminAssignSlotSucceedsForKnownUser(): void
    {
        $this->oProvider->stubUsersByName = ['JSMITH' => $this->_fakeUser('JSMITH')];
        $this->oStorage->shouldReceive('assignSlot')->once()->with(3, 'JSMITH');

        $this->oProvider->adminAssignSlot(3, 'JSMITH');
    }

    public function testAdminUnassignSlotCallsStorage(): void
    {
        $this->oStorage->shouldReceive('unassignSlot')->once()->with(3);
        $this->oProvider->adminUnassignSlot(3);
    }

    public function testAdminForceLogoffIsANoOpWhenUserIsOffline(): void
    {
        $this->oProvider->stubUserStation = [];
        $this->oProvider->adminForceLogoff('JSMITH');
        $this->assertSame([], $this->oProvider->getReplies());
        $this->assertCount(0, $this->oProvider->capLogout);
    }

    public function testAdminForceLogoffNotifiesAndLogsOutAnOnlineUser(): void
    {
        $this->oProvider->stubUserStation = ['JSMITH' => ['network' => 0, 'station' => 201]];

        $this->oProvider->adminForceLogoff('JSMITH');

        $aReplies = $this->oProvider->getReplies();
        $this->assertCount(1, $aReplies);
        $this->assertSame(MaceMail::PORT_NOTIFY, $aReplies[0]->getPort());
        $this->assertSame(chr(10) . chr(0) . chr(0) . chr(0), $aReplies[0]->getData());
        $this->assertCount(1, $this->oProvider->capLogout);
        $this->assertSame(0, $this->oProvider->capLogout[0]['iNet']);
        $this->assertSame(201, $this->oProvider->capLogout[0]['iStn']);
    }

    public function testAdminBroadcastMessageRejectsUnknownType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->oProvider->adminBroadcastMessage(99);
    }

    public function testAdminBroadcastMessageSendsToEveryOnlineUser(): void
    {
        $this->oStorage->shouldReceive('getAllSlots')->andReturn([1 => 'AWILSON', 3 => 'JSMITH']);
        $this->oProvider->stubUserStation = [
            'AWILSON' => ['network' => 0, 'station' => 55],
            'JSMITH'  => ['network' => 0, 'station' => 201],
        ];

        $this->oProvider->adminBroadcastMessage(3);

        $aReplies = $this->oProvider->getReplies();
        $this->assertCount(2, $aReplies);
        $this->assertSame(chr(3) . chr(0) . chr(0) . chr(0), $aReplies[0]->getData());
        $this->assertSame(55, $aReplies[0]->getDestinationStation());
        $this->assertSame(201, $aReplies[1]->getDestinationStation());
    }
}
