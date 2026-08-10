<?php

/*
 * @group unit-tests
 *
 * Tests for HomeLan\FileStore\Services\Provider\FileServer\Admin.
 *
 * FakeFileServer extends FileServer, bypasses the real constructor (which calls
 * vfsInit()), and overrides getStreams() / getServicePorts().
 *
 * For session/user entities the tests rely on Security's static state being
 * reset to empty via ReflectionProperty, which makes getUsersOnline() return []
 * and getAllUsers() return [] (since AuthPluginFile::$aUsers is also empty).
 */

include_once(__DIR__ . '/../../src/include/system.inc.php');

use PHPUnit\Framework\TestCase;
use Monolog\Logger;
use Monolog\Handler\NullHandler;
use HomeLan\FileStore\Services\Provider\FileServer;
use HomeLan\FileStore\Services\Provider\FileServer\Admin as FileServerAdmin;
use HomeLan\FileStore\Services\Provider\AdminEntity;
use HomeLan\FileStore\Services\ServiceDispatcher;
use HomeLan\FileStore\Authentication\Security;
use HomeLan\FileStore\Authentication\Plugins\AuthPluginFile;

// ---------------------------------------------------------------------------
// Fake provider — bypasses vfsInit(), returns stub stream/port data
// ---------------------------------------------------------------------------
class FakeFileServer extends FileServer
{
    public array $aStubStreams = [];
    public int   $iStubPort   = 0x99;

    public function __construct(\Psr\Log\LoggerInterface $oLogger)
    {
        // bypass real constructor — sets only the logger
        $this->oLogger = $oLogger;
    }

    public function getStreams(): array      { return $this->aStubStreams; }
    public function getServicePorts(): array { return [$this->iStubPort]; }
}

// ---------------------------------------------------------------------------
// Minimal stream object to simulate what getStreams() returns
// ---------------------------------------------------------------------------
class FakeFileServerStream
{
    public function __construct(private string $sUser, private string $sPath) {}
    public function getUser(): string { return $this->sUser; }
    public function getPath(): string { return $this->sPath; }
}

// ---------------------------------------------------------------------------
// Test class
// ---------------------------------------------------------------------------
class FileServerAdminTest extends TestCase
{
    private FakeFileServer $oProvider;
    private FileServerAdmin $oAdmin;
    private Logger $oLogger;

    protected function setUp(): void
    {
        $this->oLogger = new Logger('test');
        $this->oLogger->pushHandler(new NullHandler());

        $this->oProvider = new FakeFileServer($this->oLogger);
        $this->oAdmin    = new FileServerAdmin($this->oProvider);

        // Pre-seed ServiceDispatcher singleton
        $oStub = $this->createStub(ServiceDispatcher::class);
        $rp    = new \ReflectionProperty(ServiceDispatcher::class, 'oSingleton');
        $rp->setAccessible(true);
        $rp->setValue(null, $oStub);

        // Initialize Security with a logger (required by _getAuthPlugins error handler)
        Security::init($this->oLogger);

        // Reset Security sessions to empty
        $rp = new \ReflectionProperty(Security::class, 'aSessions');
        $rp->setAccessible(true);
        $rp->setValue(null, []);

        // Reset AuthPluginFile user table to empty
        $rp = new \ReflectionProperty(AuthPluginFile::class, 'aUsers');
        $rp->setAccessible(true);
        $rp->setValue(null, []);

        // Point auth plugin to 'file' so _getAuthPlugins() finds a loaded class
        config::overrideValue('security_auth_plugins', 'file');
    }

    protected function tearDown(): void
    {
        $rp = new \ReflectionProperty(ServiceDispatcher::class, 'oSingleton');
        $rp->setAccessible(true);
        $rp->setValue(null, null);

        $rp = new \ReflectionProperty(Security::class, 'aSessions');
        $rp->setAccessible(true);
        $rp->setValue(null, []);

        $rp = new \ReflectionProperty(AuthPluginFile::class, 'aUsers');
        $rp->setAccessible(true);
        $rp->setValue(null, []);

        config::resetValue('security_auth_plugins');
    }

    // -----------------------------------------------------------------------
    // getName / getDescription
    // -----------------------------------------------------------------------

    public function testGetNameReturnsFileServer(): void
    {
        $this->assertSame('File Server', $this->oAdmin->getName());
    }

    public function testGetDescriptionIsNonEmpty(): void
    {
        $this->assertNotEmpty($this->oAdmin->getDescription());
    }

    // -----------------------------------------------------------------------
    // getProvider
    // -----------------------------------------------------------------------

    public function testGetProviderReturnsTheSameProviderInstance(): void
    {
        $this->assertSame($this->oProvider, $this->oAdmin->getProvider());
    }

    // -----------------------------------------------------------------------
    // isDisabled / getStatus
    // -----------------------------------------------------------------------

    public function testIsDisabledDefaultsFalse(): void
    {
        $this->assertFalse($this->oAdmin->isDisabled());
    }

    public function testGetStatusDefaultsOnline(): void
    {
        $this->assertSame('On-line', $this->oAdmin->getStatus());
    }

    public function testSetDisabledMakesAdminDisabled(): void
    {
        $this->oAdmin->setDisabled();
        $this->assertTrue($this->oAdmin->isDisabled());
        $this->assertSame('Disabled', $this->oAdmin->getStatus());
    }

    public function testSetEnabledRestoresOnlineStatus(): void
    {
        $this->oAdmin->setDisabled();
        $this->oAdmin->setEnabled();
        $this->assertFalse($this->oAdmin->isDisabled());
        $this->assertSame('On-line', $this->oAdmin->getStatus());
    }

    // -----------------------------------------------------------------------
    // getEntityTypes
    // -----------------------------------------------------------------------

    public function testGetEntityTypesHasSessionStreamUserKeys(): void
    {
        $aTypes = $this->oAdmin->getEntityTypes();
        $this->assertArrayHasKey('session', $aTypes);
        $this->assertArrayHasKey('stream', $aTypes);
        $this->assertArrayHasKey('user', $aTypes);
    }

    // -----------------------------------------------------------------------
    // getEntityFields
    // -----------------------------------------------------------------------

    public function testGetEntityFieldsSessionHasExpectedKeys(): void
    {
        $aFields = $this->oAdmin->getEntityFields('session');
        $this->assertArrayHasKey('network', $aFields);
        $this->assertArrayHasKey('station', $aFields);
        $this->assertArrayHasKey('user', $aFields);
    }

    public function testGetEntityFieldsStreamHasExpectedKeys(): void
    {
        $aFields = $this->oAdmin->getEntityFields('stream');
        $this->assertArrayHasKey('network', $aFields);
        $this->assertArrayHasKey('station', $aFields);
        $this->assertArrayHasKey('user', $aFields);
        $this->assertArrayHasKey('path', $aFields);
    }

    public function testGetEntityFieldsUserHasExpectedKeys(): void
    {
        $aFields = $this->oAdmin->getEntityFields('user');
        $this->assertArrayHasKey('plugin', $aFields);
        $this->assertArrayHasKey('username', $aFields);
        $this->assertArrayHasKey('priv', $aFields);
        $this->assertArrayHasKey('homedir', $aFields);
        $this->assertArrayHasKey('bootopt', $aFields);
    }

    public function testGetEntityFieldsUnknownReturnsEmpty(): void
    {
        $this->assertSame([], $this->oAdmin->getEntityFields('unknown'));
    }

    // -----------------------------------------------------------------------
    // getEntities — session (empty Security state)
    // -----------------------------------------------------------------------

    public function testGetEntitiesSessionReturnsEmptyWhenNoUsersLoggedIn(): void
    {
        $this->assertSame([], $this->oAdmin->getEntities('session'));
    }

    // -----------------------------------------------------------------------
    // getEntities — user (empty AuthPluginFile state)
    // -----------------------------------------------------------------------

    public function testGetEntitiesUserReturnsEmptyWhenNoUsersExist(): void
    {
        $this->assertSame([], $this->oAdmin->getEntities('user'));
    }

    // -----------------------------------------------------------------------
    // getEntities — stream
    // -----------------------------------------------------------------------

    public function testGetEntitiesStreamReturnsEmptyByDefault(): void
    {
        $this->assertSame([], $this->oAdmin->getEntities('stream'));
    }

    public function testGetEntitiesStreamCallsProviderGetStreams(): void
    {
        $oStream = new FakeFileServerStream('ALICE', '$.Documents.file.txt');
        $this->oProvider->aStubStreams = [
            ['network' => 1, 'station' => 5, 'stream' => $oStream],
        ];
        $aEntities = $this->oAdmin->getEntities('stream');
        $this->assertCount(1, $aEntities);
        $this->assertInstanceOf(AdminEntity::class, $aEntities[0]);
    }

    public function testGetEntitiesStreamEntityHasCorrectValues(): void
    {
        $oStream = new FakeFileServerStream('BOB', '$.Work.report.txt');
        $this->oProvider->aStubStreams = [
            ['network' => 2, 'station' => 8, 'stream' => $oStream],
        ];
        $oEntity = $this->oAdmin->getEntities('stream')[0];
        $this->assertSame(2, $oEntity->getValue('network'));
        $this->assertSame(8, $oEntity->getValue('station'));
        $this->assertSame('BOB', $oEntity->getValue('user'));
        $this->assertSame('$.Work.report.txt', $oEntity->getValue('path'));
    }

    public function testGetEntitiesStreamUsesPathAsIdField(): void
    {
        $oStream = new FakeFileServerStream('CAROL', '$.data\txt');
        $this->oProvider->aStubStreams = [
            ['network' => 1, 'station' => 3, 'stream' => $oStream],
        ];
        $oEntity = $this->oAdmin->getEntities('stream')[0];
        $this->assertSame('$.data\txt', $oEntity->getId());
    }

    // -----------------------------------------------------------------------
    // getEntities — unknown type
    // -----------------------------------------------------------------------

    public function testGetEntitiesUnknownTypeReturnsEmpty(): void
    {
        $this->assertSame([], $this->oAdmin->getEntities('unknown'));
    }

    // -----------------------------------------------------------------------
    // getCommands
    // -----------------------------------------------------------------------

    public function testGetCommandsReturnsOneCommand(): void
    {
        $aCommands = $this->oAdmin->getCommands();
        $this->assertCount(1, $aCommands);
    }

    public function testGetCommandsCommandUrlContainsPort(): void
    {
        $this->oProvider->iStubPort = 0x99;
        $aCommands = $this->oAdmin->getCommands();
        $this->assertStringContainsString('port=' . 0x99, $aCommands[0]['url']);
    }

    public function testGetCommandsCommandUrlStartsWithFileServerBrowse(): void
    {
        $aCommands = $this->oAdmin->getCommands();
        $this->assertStringStartsWith('/service/fileserver/browse', $aCommands[0]['url']);
    }
}
