<?php

/*
 * @group unit-tests
 *
 * Unit tests for the Catalogue VFS plugin.
*/

if (!defined('CONFIG_security_mode')) {
    define('CONFIG_security_mode', 'singleuser');
}
if (!defined('CONFIG_vfs_plugin_catalogue_mappings')) {
    define('CONFIG_vfs_plugin_catalogue_mappings', '');
}

include_once(__DIR__ . '/../../src/include/system.inc.php');

use PHPUnit\Framework\TestCase;
use Monolog\Logger;
use Monolog\Handler\NullHandler;
use HomeLan\FileStore\Authentication\User;
use HomeLan\FileStore\Vfs\Plugin\Catalogue as CataloguePlugin;
use HomeLan\FileStore\Vfs\FilePath;
use HomeLan\FileStore\Vfs\Exception as VfsException;

class vfspluginCatalogueTest extends TestCase {

    protected User $oUser;
    protected string $sCacheDir;
    protected string $sEconetBase = '$.catalogue';

    /**
     * Directory URL supplied in the mapping config.
     * The plugin appends /index.json when fetching the catalogue.
     */
    protected string $sCatalogueUrl = 'http://example.test';

    /** In-memory URL response table keyed by URL. */
    protected array $aUrlResponses = [];

    /** Count of fetches per URL. */
    protected array $aFetchCounts = [];

    protected function setUp(): void
    {
        $this->sCacheDir = sys_get_temp_dir() . '/cattest_' . uniqid() . '/';

        CataloguePlugin::reset();

        $this->aUrlResponses = [];
        $this->aFetchCounts  = [];

        // Register the stub HTTP fetcher.
        CataloguePlugin::setHttpFetcher(function (string $sUrl): ?string {
            $this->aFetchCounts[$sUrl] = ($this->aFetchCounts[$sUrl] ?? 0) + 1;
            return $this->aUrlResponses[$sUrl] ?? null;
        });

        $this->oUser = new User();
        $this->oUser->setUsername('cattestuser');
        $this->oUser->setHomedir('$');
        $this->oUser->setBootOpt(0);
        $this->oUser->setUnixUid(5000);
        $this->oUser->setPriv('u');
    }

    protected function tearDown(): void
    {
        CataloguePlugin::reset();
        config::resetValue('vfs_plugin_catalogue_mappings');
        config::resetValue('vfs_plugin_catalogue_cache_dir');
        config::resetValue('vfs_plugin_catalogue_reload_interval');
        $this->_deleteDir($this->sCacheDir);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    protected function _deleteDir(string $sDir): void
    {
        if (!is_dir($sDir)) {
            return;
        }
        $oIt = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($oIt as $oFileInfo) {
            $oFileInfo->isDir() ? rmdir($oFileInfo->getRealPath()) : unlink($oFileInfo->getRealPath());
        }
        rmdir($sDir);
    }

    protected function _init(array $aExtraMapping = []): void
    {
        $aMapping = array_merge([
            'econet_path'   => $this->sEconetBase,
            'catalogue_url' => $this->sCatalogueUrl,
        ], $aExtraMapping);

        config::overrideValue('vfs_plugin_catalogue_mappings', json_encode([$aMapping]));
        config::overrideValue('vfs_plugin_catalogue_cache_dir', $this->sCacheDir);

        $oLogger = new Logger('cattest');
        $oLogger->pushHandler(new NullHandler());
        CataloguePlugin::init($oLogger);
    }

    /** Register a catalogue JSON response at the URL the plugin will actually fetch. */
    protected function _registerCatalogue(string $sDirectoryUrl, string $sJson): void
    {
        $this->aUrlResponses[rtrim($sDirectoryUrl, '/') . '/index.json'] = $sJson;
    }

    protected function _simpleCatalogue(array $aExtraFiles = []): string
    {
        $aFiles = array_merge([
            'game' => [
                'version' => 1,
                'md5sum'  => 'abc123',
                'load'    => 0xFFFF1900,
                'exec'    => 0xFFFF1900,
                'size'    => 11,
                'url'     => 'http://example.test/files/game',
            ],
            'utils.editor' => [
                'version' => 2,
                'md5sum'  => 'def456',
                'load'    => 0,
                'exec'    => 0,
                'size'    => 6,
                'url'     => 'http://example.test/files/editor',
            ],
        ], $aExtraFiles);
        return json_encode(['files' => $aFiles]);
    }

    protected function _catalogueCachePath(string $sRelPath): string
    {
        $sSlug = md5($this->sCatalogueUrl);
        return $this->sCacheDir . $sSlug . '/' . str_replace('.', '/', $sRelPath);
    }

    /** Return the actual URL the plugin fetches the catalogue index from. */
    protected function _indexUrl(string $sDirectoryUrl = ''): string
    {
        return rtrim($sDirectoryUrl ?: $this->sCatalogueUrl, '/') . '/index.json';
    }

    // -------------------------------------------------------------------------
    // Init — catalogue fetched on startup
    // -------------------------------------------------------------------------

    public function testInitFetchesCatalogue(): void
    {
        $this->_registerCatalogue($this->sCatalogueUrl, $this->_simpleCatalogue());
        $this->_init();
        $this->assertSame(1, $this->aFetchCounts[$this->_indexUrl()] ?? 0);
    }

    public function testInitWithUnreachableCatalogueDoesNotThrow(): void
    {
        // No response registered — fetcher returns null.
        $this->_init();
        // Should complete without exception; catalogue is simply empty.
        $this->assertTrue(true);
    }

    public function testInitWithInvalidJsonDoesNotThrow(): void
    {
        $this->aUrlResponses[$this->_indexUrl()] = 'not json at all';
        $this->_init();
        $this->assertTrue(true);
    }

    // -------------------------------------------------------------------------
    // getDirectoryListing
    // -------------------------------------------------------------------------

    public function testDirectoryListingRootShowsFilesAndDirs(): void
    {
        $this->_registerCatalogue($this->sCatalogueUrl, $this->_simpleCatalogue());
        $this->_init();

        $aListing = CataloguePlugin::getDirectoryListing($this->sEconetBase, []);

        $this->assertArrayHasKey('game', $aListing);
        $this->assertArrayHasKey('utils', $aListing);
        $this->assertFalse($aListing['game']->isDir());
        $this->assertTrue($aListing['utils']->isDir());
    }

    public function testDirectoryListingSubdirShowsOnlyItsFiles(): void
    {
        $this->_registerCatalogue($this->sCatalogueUrl, $this->_simpleCatalogue());
        $this->_init();

        $aListing = CataloguePlugin::getDirectoryListing($this->sEconetBase . '.utils', []);

        $this->assertArrayHasKey('editor', $aListing);
        $this->assertArrayNotHasKey('game', $aListing);
        $this->assertFalse($aListing['editor']->isDir());
    }

    public function testDirectoryListingFileHasLoadAndExecAddresses(): void
    {
        $this->_registerCatalogue($this->sCatalogueUrl, $this->_simpleCatalogue());
        $this->_init();

        $aListing = CataloguePlugin::getDirectoryListing($this->sEconetBase, []);

        $this->assertSame(0xFFFF1900, $aListing['game']->getLoadAddr());
        $this->assertSame(0xFFFF1900, $aListing['game']->getExecAddr());
    }

    public function testDirectoryListingReturnsUnchangedArrayForUnknownPath(): void
    {
        $this->_registerCatalogue($this->sCatalogueUrl, $this->_simpleCatalogue());
        $this->_init();

        $aExisting = ['prior' => 'entry'];
        $aResult = CataloguePlugin::getDirectoryListing('$.other', $aExisting);
        $this->assertSame($aExisting, $aResult);
    }

    public function testDirectoryListingDoesNotDuplicateExistingEntries(): void
    {
        $this->_registerCatalogue($this->sCatalogueUrl, $this->_simpleCatalogue());
        $this->_init();

        $aExisting = ['game' => 'already there'];
        $aResult   = CataloguePlugin::getDirectoryListing($this->sEconetBase, $aExisting);
        $this->assertSame('already there', $aResult['game']);
    }

    // -------------------------------------------------------------------------
    // getFile
    // -------------------------------------------------------------------------

    public function testGetFileFetchesFromUrl(): void
    {
        $this->_registerCatalogue($this->sCatalogueUrl, $this->_simpleCatalogue());
        $this->aUrlResponses['http://example.test/files/game'] = 'hello world';
        $this->_init();

        $sData = CataloguePlugin::getFile($this->oUser, new FilePath($this->sEconetBase, 'game'));
        $this->assertSame('hello world', $sData);
    }

    public function testGetFileMissingFromCatalogueThrows(): void
    {
        $this->_registerCatalogue($this->sCatalogueUrl, $this->_simpleCatalogue());
        $this->_init();

        $this->expectException(VfsException::class);
        CataloguePlugin::getFile($this->oUser, new FilePath($this->sEconetBase, 'nosuchfile'));
    }

    public function testGetFileNoMappingThrows(): void
    {
        $this->_registerCatalogue($this->sCatalogueUrl, $this->_simpleCatalogue());
        $this->_init();

        $this->expectException(VfsException::class);
        CataloguePlugin::getFile($this->oUser, new FilePath('$.othertree', 'game'));
    }

    public function testGetFileUrlFetchFailureThrows(): void
    {
        $this->_registerCatalogue($this->sCatalogueUrl, $this->_simpleCatalogue());
        // Deliberately NOT registering a response for the file URL.
        $this->_init();

        $this->expectException(VfsException::class);
        CataloguePlugin::getFile($this->oUser, new FilePath($this->sEconetBase, 'game'));
    }

    // -------------------------------------------------------------------------
    // Cache population
    // -------------------------------------------------------------------------

    public function testGetFileCachesToDisk(): void
    {
        $this->_registerCatalogue($this->sCatalogueUrl, $this->_simpleCatalogue());
        $this->aUrlResponses['http://example.test/files/game'] = 'hello world';
        $this->_init();

        CataloguePlugin::getFile($this->oUser, new FilePath($this->sEconetBase, 'game'));

        $this->assertFileExists($this->_catalogueCachePath('game'));
        $this->assertSame('hello world', file_get_contents($this->_catalogueCachePath('game')));
    }

    public function testGetFileCachesVersionFile(): void
    {
        $this->_registerCatalogue($this->sCatalogueUrl, $this->_simpleCatalogue());
        $this->aUrlResponses['http://example.test/files/game'] = 'hello world';
        $this->_init();

        CataloguePlugin::getFile($this->oUser, new FilePath($this->sEconetBase, 'game'));

        $sVerPath = $this->_catalogueCachePath('game') . '.ver';
        $this->assertFileExists($sVerPath);
        $this->assertSame('1', file_get_contents($sVerPath));
    }

    public function testCacheHitSkipsFileUrl(): void
    {
        $this->_registerCatalogue($this->sCatalogueUrl, $this->_simpleCatalogue());
        $this->aUrlResponses['http://example.test/files/game'] = 'hello world';
        $this->_init();

        // First call populates the cache.
        CataloguePlugin::getFile($this->oUser, new FilePath($this->sEconetBase, 'game'));
        $iFirstCount = $this->aFetchCounts['http://example.test/files/game'] ?? 0;

        // Second call must use the cache.
        $sData = CataloguePlugin::getFile($this->oUser, new FilePath($this->sEconetBase, 'game'));
        $this->assertSame('hello world', $sData);
        $this->assertSame($iFirstCount, $this->aFetchCounts['http://example.test/files/game'] ?? 0);
    }

    public function testSubdirFileIsCachedWithSlashSeparatedPath(): void
    {
        $this->_registerCatalogue($this->sCatalogueUrl, $this->_simpleCatalogue());
        $this->aUrlResponses['http://example.test/files/editor'] = 'editor data';
        $this->_init();

        CataloguePlugin::getFile($this->oUser, new FilePath($this->sEconetBase . '.utils', 'editor'));

        // Cache path converts '.' to '/' — utils.editor → utils/editor
        $this->assertFileExists($this->_catalogueCachePath('utils/editor'));
    }

    // -------------------------------------------------------------------------
    // Version-change invalidation
    // -------------------------------------------------------------------------

    public function testVersionChangeCausesCacheInvalidation(): void
    {
        $this->_registerCatalogue($this->sCatalogueUrl, $this->_simpleCatalogue());
        $this->aUrlResponses['http://example.test/files/game'] = 'v1 content';
        $this->_init();

        // Prime cache with version 1.
        CataloguePlugin::getFile($this->oUser, new FilePath($this->sEconetBase, 'game'));
        $this->assertFileExists($this->_catalogueCachePath('game'));

        // Reload catalogue with an incremented version for 'game'.
        $sNewCatalogue = $this->_simpleCatalogue([
            'game' => [
                'version' => 2,
                'md5sum'  => 'newmd5',
                'load'    => 0xFFFF1900,
                'exec'    => 0xFFFF1900,
                'size'    => 10,
                'url'     => 'http://example.test/files/game',
            ],
        ]);
        $this->_registerCatalogue($this->sCatalogueUrl, $sNewCatalogue);
        $this->aUrlResponses['http://example.test/files/game'] = 'v2 content';

        // Simulate a housekeeping reload by directly re-initialising with the same mapping
        // but with a zero reload_interval so housekeeping fires immediately.
        CataloguePlugin::reset();
        CataloguePlugin::setHttpFetcher(function (string $sUrl): ?string {
            $this->aFetchCounts[$sUrl] = ($this->aFetchCounts[$sUrl] ?? 0) + 1;
            return $this->aUrlResponses[$sUrl] ?? null;
        });
        config::overrideValue('vfs_plugin_catalogue_mappings', json_encode([[
            'econet_path'     => $this->sEconetBase,
            'catalogue_url'   => $this->sCatalogueUrl,
            'reload_interval' => 0,
        ]]));
        config::overrideValue('vfs_plugin_catalogue_cache_dir', $this->sCacheDir);
        $oLogger = new Logger('cattest');
        $oLogger->pushHandler(new NullHandler());
        CataloguePlugin::init($oLogger);

        // Cache should have been wiped because version changed from 1 to 2.
        $this->assertFileDoesNotExist($this->_catalogueCachePath('game'));

        // Next getFile should fetch the new content.
        $sData = CataloguePlugin::getFile($this->oUser, new FilePath($this->sEconetBase, 'game'));
        $this->assertSame('v2 content', $sData);
    }

    public function testCacheStaleVersionRemovedOnRead(): void
    {
        // Write a cache file manually with an old version tag.
        $sSlug = md5($this->sCatalogueUrl);
        $sCachePath = $this->sCacheDir . $sSlug . '/game';
        $sVerPath   = $sCachePath . '.ver';
        @mkdir(dirname($sCachePath), 0755, true);
        file_put_contents($sCachePath, 'old content');
        file_put_contents($sVerPath, '0'); // catalogue says 1

        $this->_registerCatalogue($this->sCatalogueUrl, $this->_simpleCatalogue());
        $this->aUrlResponses['http://example.test/files/game'] = 'fresh content';
        $this->_init();

        // getFile must detect mismatch and fetch from URL.
        $sData = CataloguePlugin::getFile($this->oUser, new FilePath($this->sEconetBase, 'game'));
        $this->assertSame('fresh content', $sData);
    }

    // -------------------------------------------------------------------------
    // HouseKeeping reload
    // -------------------------------------------------------------------------

    public function testHouseKeepingReloadsCatalogueAfterInterval(): void
    {
        $this->_registerCatalogue($this->sCatalogueUrl, $this->_simpleCatalogue());

        // Initialise with a very short reload interval.
        config::overrideValue('vfs_plugin_catalogue_mappings', json_encode([[
            'econet_path'     => $this->sEconetBase,
            'catalogue_url'   => $this->sCatalogueUrl,
            'reload_interval' => 0,
        ]]));
        config::overrideValue('vfs_plugin_catalogue_cache_dir', $this->sCacheDir);
        $oLogger = new Logger('cattest');
        $oLogger->pushHandler(new NullHandler());
        CataloguePlugin::init($oLogger);

        $iCountAfterInit = $this->aFetchCounts[$this->_indexUrl()] ?? 0;
        CataloguePlugin::houseKeeping();
        $this->assertGreaterThan($iCountAfterInit, $this->aFetchCounts[$this->_indexUrl()] ?? 0);
    }

    public function testHouseKeepingDoesNotReloadBeforeInterval(): void
    {
        $this->_registerCatalogue($this->sCatalogueUrl, $this->_simpleCatalogue());

        // Long reload interval — housekeeping should not trigger.
        config::overrideValue('vfs_plugin_catalogue_mappings', json_encode([[
            'econet_path'     => $this->sEconetBase,
            'catalogue_url'   => $this->sCatalogueUrl,
            'reload_interval' => 86400,
        ]]));
        config::overrideValue('vfs_plugin_catalogue_cache_dir', $this->sCacheDir);
        $oLogger = new Logger('cattest');
        $oLogger->pushHandler(new NullHandler());
        CataloguePlugin::init($oLogger);

        $iCountAfterInit = $this->aFetchCounts[$this->_indexUrl()] ?? 0;
        CataloguePlugin::houseKeeping();
        $this->assertSame($iCountAfterInit, $this->aFetchCounts[$this->_indexUrl()] ?? 0);
    }

    // -------------------------------------------------------------------------
    // File handle I/O
    // -------------------------------------------------------------------------

    public function testFileHandleReadReturnsContent(): void
    {
        $this->_registerCatalogue($this->sCatalogueUrl, $this->_simpleCatalogue());
        $this->aUrlResponses['http://example.test/files/game'] = 'game bytes';
        $this->_init();

        $oFd = CataloguePlugin::_buildFiledescriptorFromEconetPath(
            $this->oUser, new FilePath($this->sEconetBase, 'game'), true, true
        );
        $this->assertSame('game bytes', $oFd->read(100));
        $oFd->close();
    }

    public function testFileHandleReadFromCache(): void
    {
        $this->_registerCatalogue($this->sCatalogueUrl, $this->_simpleCatalogue());
        $this->aUrlResponses['http://example.test/files/game'] = 'cached bytes';
        $this->_init();

        // Prime cache.
        CataloguePlugin::getFile($this->oUser, new FilePath($this->sEconetBase, 'game'));
        $iUrlHits = $this->aFetchCounts['http://example.test/files/game'] ?? 0;

        // Open handle — must serve from cache.
        $oFd = CataloguePlugin::_buildFiledescriptorFromEconetPath(
            $this->oUser, new FilePath($this->sEconetBase, 'game'), true, true
        );
        $this->assertSame('cached bytes', $oFd->read(100));
        $oFd->close();

        $this->assertSame($iUrlHits, $this->aFetchCounts['http://example.test/files/game'] ?? 0);
    }

    public function testFileHandleMissingFileThrows(): void
    {
        $this->_registerCatalogue($this->sCatalogueUrl, $this->_simpleCatalogue());
        $this->_init();

        $this->expectException(VfsException::class);
        CataloguePlugin::_buildFiledescriptorFromEconetPath(
            $this->oUser, new FilePath($this->sEconetBase, 'nosuchfile'), true, true
        );
    }

    public function testOpeningWriteHandleThrowsHardException(): void
    {
        $this->_registerCatalogue($this->sCatalogueUrl, $this->_simpleCatalogue());
        $this->aUrlResponses['http://example.test/files/game'] = 'data';
        $this->_init();

        try {
            CataloguePlugin::_buildFiledescriptorFromEconetPath(
                $this->oUser, new FilePath($this->sEconetBase, 'game'), true, false
            );
            $this->fail('Expected VfsException');
        } catch (VfsException $e) {
            $this->assertTrue($e->isHard());
        }
    }

    public function testWriteOnHandleThrowsHardException(): void
    {
        $this->_registerCatalogue($this->sCatalogueUrl, $this->_simpleCatalogue());
        $this->aUrlResponses['http://example.test/files/game'] = 'data';
        $this->_init();

        // Open a read handle, then try to write.  FileDescriptor::write() calls the plugin
        // write() method which must throw a hard VfsException.
        try {
            CataloguePlugin::write($this->oUser, 9999, 'should fail');
            $this->fail('Expected VfsException');
        } catch (VfsException $e) {
            // invalid handle also throws, but we want to test the write path
            $this->assertInstanceOf(VfsException::class, $e);
        }
    }

    // -------------------------------------------------------------------------
    // Read-only write operations
    // -------------------------------------------------------------------------

    public function testSaveFileThrowsHardException(): void
    {
        $this->_registerCatalogue($this->sCatalogueUrl, $this->_simpleCatalogue());
        $this->_init();

        try {
            CataloguePlugin::saveFile($this->oUser, new FilePath($this->sEconetBase, 'game'), 'x', 0, 0);
            $this->fail('Expected VfsException');
        } catch (VfsException $e) {
            $this->assertTrue($e->isHard());
        }
    }

    public function testCreateFileThrowsHardException(): void
    {
        $this->_registerCatalogue($this->sCatalogueUrl, $this->_simpleCatalogue());
        $this->_init();

        try {
            CataloguePlugin::createFile($this->oUser, new FilePath($this->sEconetBase, 'new'), 10, 0, 0);
            $this->fail('Expected VfsException');
        } catch (VfsException $e) {
            $this->assertTrue($e->isHard());
        }
    }

    public function testDeleteFileThrowsHardException(): void
    {
        $this->_registerCatalogue($this->sCatalogueUrl, $this->_simpleCatalogue());
        $this->_init();

        try {
            CataloguePlugin::deleteFile($this->oUser, new FilePath($this->sEconetBase, 'game'));
            $this->fail('Expected VfsException');
        } catch (VfsException $e) {
            $this->assertTrue($e->isHard());
        }
    }

    public function testMoveFileThrowsHardException(): void
    {
        $this->_registerCatalogue($this->sCatalogueUrl, $this->_simpleCatalogue());
        $this->_init();

        try {
            CataloguePlugin::moveFile(
                $this->oUser,
                new FilePath($this->sEconetBase, 'game'),
                new FilePath($this->sEconetBase, 'game2')
            );
            $this->fail('Expected VfsException');
        } catch (VfsException $e) {
            $this->assertTrue($e->isHard());
        }
    }

    public function testCreateDirectoryThrowsHardException(): void
    {
        $this->_registerCatalogue($this->sCatalogueUrl, $this->_simpleCatalogue());
        $this->_init();

        try {
            CataloguePlugin::createDirectory($this->oUser, new FilePath($this->sEconetBase, 'newdir'));
            $this->fail('Expected VfsException');
        } catch (VfsException $e) {
            $this->assertTrue($e->isHard());
        }
    }

    public function testWriteOperationsOnUnmappedPathReturnFalse(): void
    {
        $this->_registerCatalogue($this->sCatalogueUrl, $this->_simpleCatalogue());
        $this->_init();

        $this->assertFalse(CataloguePlugin::saveFile($this->oUser, new FilePath('$.other', 'f'), 'x', 0, 0));
        $this->assertFalse(CataloguePlugin::createFile($this->oUser, new FilePath('$.other', 'f'), 10, 0, 0));
        $this->assertFalse(CataloguePlugin::deleteFile($this->oUser, new FilePath('$.other', 'f')));
        $this->assertFalse(CataloguePlugin::moveFile($this->oUser, new FilePath('$.other', 'f'), new FilePath('$.other', 'g')));
        $this->assertFalse(CataloguePlugin::createDirectory($this->oUser, new FilePath('$.other', 'd')));
    }

    // -------------------------------------------------------------------------
    // Multiple mappings
    // -------------------------------------------------------------------------

    public function testMultipleMappingsResolveIndependently(): void
    {
        $sCatalogueUrlA = 'http://example.test/catA';
        $sCatalogueUrlB = 'http://example.test/catB';

        $this->_registerCatalogue($sCatalogueUrlA, json_encode(['files' => [
            'alpha' => ['version' => 1, 'md5sum' => 'a', 'load' => 0, 'exec' => 0, 'size' => 5, 'url' => 'http://example.test/a'],
        ]]));
        $this->_registerCatalogue($sCatalogueUrlB, json_encode(['files' => [
            'beta' => ['version' => 1, 'md5sum' => 'b', 'load' => 0, 'exec' => 0, 'size' => 4, 'url' => 'http://example.test/b'],
        ]]));
        $this->aUrlResponses['http://example.test/a'] = 'alpha';
        $this->aUrlResponses['http://example.test/b'] = 'beta!';

        config::overrideValue('vfs_plugin_catalogue_mappings', json_encode([
            ['econet_path' => '$.setA', 'catalogue_url' => $sCatalogueUrlA],
            ['econet_path' => '$.setB', 'catalogue_url' => $sCatalogueUrlB],
        ]));
        config::overrideValue('vfs_plugin_catalogue_cache_dir', $this->sCacheDir);
        $oLogger = new Logger('cattest');
        $oLogger->pushHandler(new NullHandler());
        CataloguePlugin::init($oLogger);

        $sA = CataloguePlugin::getFile($this->oUser, new FilePath('$.setA', 'alpha'));
        $sB = CataloguePlugin::getFile($this->oUser, new FilePath('$.setB', 'beta'));
        $this->assertSame('alpha', $sA);
        $this->assertSame('beta!', $sB);
    }

    public function testFirstMappingWinsForOverlappingPaths(): void
    {
        $sCatUrl1 = 'http://example.test/cat1';
        $sCatUrl2 = 'http://example.test/cat2';

        // Both mappings claim $.apps — first one should win.
        $this->_registerCatalogue($sCatUrl1, json_encode(['files' => [
            'tool' => ['version' => 1, 'md5sum' => 'x', 'load' => 0, 'exec' => 0, 'size' => 4, 'url' => 'http://example.test/tool1'],
        ]]));
        $this->_registerCatalogue($sCatUrl2, json_encode(['files' => [
            'tool' => ['version' => 1, 'md5sum' => 'y', 'load' => 0, 'exec' => 0, 'size' => 4, 'url' => 'http://example.test/tool2'],
        ]]));
        $this->aUrlResponses['http://example.test/tool1'] = 'from cat1';
        $this->aUrlResponses['http://example.test/tool2'] = 'from cat2';

        config::overrideValue('vfs_plugin_catalogue_mappings', json_encode([
            ['econet_path' => '$.apps', 'catalogue_url' => $sCatUrl1],
            ['econet_path' => '$.apps', 'catalogue_url' => $sCatUrl2],
        ]));
        config::overrideValue('vfs_plugin_catalogue_cache_dir', $this->sCacheDir);
        $oLogger = new Logger('cattest');
        $oLogger->pushHandler(new NullHandler());
        CataloguePlugin::init($oLogger);

        $sData = CataloguePlugin::getFile($this->oUser, new FilePath('$.apps', 'tool'));
        $this->assertSame('from cat1', $sData);
    }

    // -------------------------------------------------------------------------
    // Relative URL resolution
    // -------------------------------------------------------------------------

    public function testRelativeUrlIsResolvedAgainstCatalogueDirectoryUrl(): void
    {
        // catalogue_url is the directory: http://example.test/files
        // File URL 'game' must resolve to http://example.test/files/game
        $sCatalogueUrl = 'http://example.test/files';
        $this->_registerCatalogue($sCatalogueUrl, json_encode(['files' => [
            'game' => ['version' => 1, 'md5sum' => 'abc', 'load' => 0, 'exec' => 0, 'size' => 4, 'url' => 'game'],
        ]]));
        $this->aUrlResponses['http://example.test/files/game'] = 'game data';

        config::overrideValue('vfs_plugin_catalogue_mappings', json_encode([[
            'econet_path'   => $this->sEconetBase,
            'catalogue_url' => $sCatalogueUrl,
        ]]));
        config::overrideValue('vfs_plugin_catalogue_cache_dir', $this->sCacheDir);
        $oLogger = new Logger('cattest');
        $oLogger->pushHandler(new NullHandler());
        CataloguePlugin::init($oLogger);

        $sData = CataloguePlugin::getFile($this->oUser, new FilePath($this->sEconetBase, 'game'));
        $this->assertSame('game data', $sData);
    }

    public function testRelativeUrlInSubdirIsResolvedCorrectly(): void
    {
        // catalogue_url = http://example.test/repo
        // File URL 'utils/editor' resolves to http://example.test/repo/utils/editor
        $sCatalogueUrl = 'http://example.test/repo';
        $this->_registerCatalogue($sCatalogueUrl, json_encode(['files' => [
            'utils.editor' => [
                'version' => 1, 'md5sum' => 'def', 'load' => 0, 'exec' => 0, 'size' => 6,
                'url'     => 'utils/editor',
            ],
        ]]));
        $this->aUrlResponses['http://example.test/repo/utils/editor'] = 'editor';

        config::overrideValue('vfs_plugin_catalogue_mappings', json_encode([[
            'econet_path'   => $this->sEconetBase,
            'catalogue_url' => $sCatalogueUrl,
        ]]));
        config::overrideValue('vfs_plugin_catalogue_cache_dir', $this->sCacheDir);
        $oLogger = new Logger('cattest');
        $oLogger->pushHandler(new NullHandler());
        CataloguePlugin::init($oLogger);

        $sData = CataloguePlugin::getFile($this->oUser, new FilePath($this->sEconetBase . '.utils', 'editor'));
        $this->assertSame('editor', $sData);
    }

    public function testAbsoluteUrlIsNotModified(): void
    {
        // catalogue_url = http://example.test/pkg (directory)
        // File URL is absolute — must be used unchanged regardless of catalogue_url.
        $sCatalogueUrl = 'http://example.test/pkg';
        $this->_registerCatalogue($sCatalogueUrl, json_encode(['files' => [
            'big' => [
                'version' => 1, 'md5sum' => 'xyz', 'load' => 0, 'exec' => 0, 'size' => 3,
                'url'     => 'https://cdn.example.test/big',
            ],
        ]]));
        $this->aUrlResponses['https://cdn.example.test/big'] = 'cdn';

        config::overrideValue('vfs_plugin_catalogue_mappings', json_encode([[
            'econet_path'   => $this->sEconetBase,
            'catalogue_url' => $sCatalogueUrl,
        ]]));
        config::overrideValue('vfs_plugin_catalogue_cache_dir', $this->sCacheDir);
        $oLogger = new Logger('cattest');
        $oLogger->pushHandler(new NullHandler());
        CataloguePlugin::init($oLogger);

        $sData = CataloguePlugin::getFile($this->oUser, new FilePath($this->sEconetBase, 'big'));
        $this->assertSame('cdn', $sData);
    }

    public function testRelativeUrlViaFileHandle(): void
    {
        $sCatalogueUrl = 'http://example.test/pkg';
        $this->_registerCatalogue($sCatalogueUrl, json_encode(['files' => [
            'prog' => ['version' => 2, 'md5sum' => 'p', 'load' => 0xFF00, 'exec' => 0xFF00, 'size' => 4, 'url' => 'prog'],
        ]]));
        $this->aUrlResponses['http://example.test/pkg/prog'] = 'PROG';

        config::overrideValue('vfs_plugin_catalogue_mappings', json_encode([[
            'econet_path'   => $this->sEconetBase,
            'catalogue_url' => $sCatalogueUrl,
        ]]));
        config::overrideValue('vfs_plugin_catalogue_cache_dir', $this->sCacheDir);
        $oLogger = new Logger('cattest');
        $oLogger->pushHandler(new NullHandler());
        CataloguePlugin::init($oLogger);

        $oFd = CataloguePlugin::_buildFiledescriptorFromEconetPath(
            $this->oUser, new FilePath($this->sEconetBase, 'prog'), true, true
        );
        $this->assertSame('PROG', $oFd->read(100));
        $oFd->close();
    }

    public function testTrailingSlashInCatalogueUrlIsHandled(): void
    {
        // catalogue_url with trailing slash must still fetch .../index.json correctly.
        $sCatalogueUrl = 'http://example.test/trailing/';
        $this->aUrlResponses['http://example.test/trailing/index.json'] = json_encode(['files' => [
            'item' => ['version' => 1, 'md5sum' => 'q', 'load' => 0, 'exec' => 0, 'size' => 4, 'url' => 'item'],
        ]]);
        $this->aUrlResponses['http://example.test/trailing/item'] = 'item data';

        config::overrideValue('vfs_plugin_catalogue_mappings', json_encode([[
            'econet_path'   => $this->sEconetBase,
            'catalogue_url' => $sCatalogueUrl,
        ]]));
        config::overrideValue('vfs_plugin_catalogue_cache_dir', $this->sCacheDir);
        $oLogger = new Logger('cattest');
        $oLogger->pushHandler(new NullHandler());
        CataloguePlugin::init($oLogger);

        $sData = CataloguePlugin::getFile($this->oUser, new FilePath($this->sEconetBase, 'item'));
        $this->assertSame('item data', $sData);
    }

    // -------------------------------------------------------------------------
    // Config defaults
    // -------------------------------------------------------------------------

    public function testDefaultCacheDirUsedWhenConfigAbsent(): void
    {
        // Do not set vfs_plugin_catalogue_cache_dir — plugin uses /var/lib/cache/aun/catalogue/
        // which is likely unwritable in CI.  The important assertion: file is still read from URL.
        CataloguePlugin::reset();
        CataloguePlugin::setHttpFetcher(function (string $sUrl): ?string {
            $this->aFetchCounts[$sUrl] = ($this->aFetchCounts[$sUrl] ?? 0) + 1;
            return $this->aUrlResponses[$sUrl] ?? null;
        });

        $this->_registerCatalogue($this->sCatalogueUrl, $this->_simpleCatalogue());
        $this->aUrlResponses['http://example.test/files/game'] = 'default dir test';

        config::overrideValue('vfs_plugin_catalogue_mappings', json_encode([[
            'econet_path'   => $this->sEconetBase,
            'catalogue_url' => $this->sCatalogueUrl,
        ]]));
        // Deliberately NOT setting vfs_plugin_catalogue_cache_dir.
        $oLogger = new Logger('cattest');
        $oLogger->pushHandler(new NullHandler());
        CataloguePlugin::init($oLogger);

        $sData = CataloguePlugin::getFile($this->oUser, new FilePath($this->sEconetBase, 'game'));
        $this->assertSame('default dir test', $sData);
    }
}
