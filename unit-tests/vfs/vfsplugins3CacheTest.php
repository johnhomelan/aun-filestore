<?php

/*
 * @group unit-tests
 *
 * Cache-specific tests for the S3 VFS plugin.
 * The shared StubS3Client and vfsplugins3BaseTest live in vfsplugins3Test.php.
*/

require_once __DIR__ . '/vfsplugins3Test.php';

use HomeLan\FileStore\Vfs\Plugin\S3 as S3Plugin;
use HomeLan\FileStore\Vfs\FilePath;
use HomeLan\FileStore\Vfs\Exception as VfsException;
use Monolog\Logger;
use Monolog\Handler\NullHandler;

class vfsplugins3CacheTest extends vfsplugins3BaseTest {

    // -------------------------------------------------------------------------
    // Cache population
    // -------------------------------------------------------------------------

    public function testReadHandleCachesFileToDisk(): void
    {
        $this->oS3->seed('econet/cached', 'hello cache');

        $oFd = S3Plugin::_buildFiledescriptorFromEconetPath(
            $this->oUser, new FilePath($this->sEconetBase, 'cached'), true, true
        );
        $oFd->close();

        $this->assertFileExists($this->cachePathFor('econet/cached'));
        $this->assertSame('hello cache', file_get_contents($this->cachePathFor('econet/cached')));
    }

    public function testGetFileCachesToDisk(): void
    {
        $this->oS3->seed('econet/getfile', 'get file content');

        S3Plugin::getFile($this->oUser, new FilePath($this->sEconetBase, 'getfile'));

        $this->assertFileExists($this->cachePathFor('econet/getfile'));
        $this->assertSame('get file content', file_get_contents($this->cachePathFor('econet/getfile')));
    }

    public function testCacheHitSkipsS3ForHandle(): void
    {
        $this->oS3->seed('econet/popular', 'popular file');

        // First open populates the cache.
        $oFd = S3Plugin::_buildFiledescriptorFromEconetPath(
            $this->oUser, new FilePath($this->sEconetBase, 'popular'), true, true
        );
        $oFd->close();
        $iCallsAfterFirst = $this->oS3->getGetObjectCallCount();

        // Second open must serve from cache — no additional S3 getObject call.
        $oFd2 = S3Plugin::_buildFiledescriptorFromEconetPath(
            $this->oUser, new FilePath($this->sEconetBase, 'popular'), true, true
        );
        $this->assertSame('popular file', $oFd2->read(100));
        $oFd2->close();

        $this->assertSame($iCallsAfterFirst, $this->oS3->getGetObjectCallCount());
    }

    public function testCacheHitSkipsS3ForGetFile(): void
    {
        $this->oS3->seed('econet/popular2', 'popular file 2');

        // First call populates the cache.
        S3Plugin::getFile($this->oUser, new FilePath($this->sEconetBase, 'popular2'));
        $iCallsAfterFirst = $this->oS3->getGetObjectCallCount();

        // Second call must serve from cache.
        $sData = S3Plugin::getFile($this->oUser, new FilePath($this->sEconetBase, 'popular2'));
        $this->assertSame('popular file 2', $sData);
        $this->assertSame($iCallsAfterFirst, $this->oS3->getGetObjectCallCount());
    }

    // -------------------------------------------------------------------------
    // Cache invalidation
    // -------------------------------------------------------------------------

    public function testSaveFileInvalidatesCache(): void
    {
        // Prime the cache.
        $this->oS3->seed('econet/toupdate', 'old content');
        S3Plugin::getFile($this->oUser, new FilePath($this->sEconetBase, 'toupdate'));
        $this->assertFileExists($this->cachePathFor('econet/toupdate'));

        S3Plugin::saveFile($this->oUser, new FilePath($this->sEconetBase, 'toupdate'), 'new content', 0, 0);
        $this->assertFileDoesNotExist($this->cachePathFor('econet/toupdate'));
    }

    public function testCreateFileInvalidatesCache(): void
    {
        $this->oS3->seed('econet/recreate', 'stale');
        S3Plugin::getFile($this->oUser, new FilePath($this->sEconetBase, 'recreate'));
        $this->assertFileExists($this->cachePathFor('econet/recreate'));

        S3Plugin::createFile($this->oUser, new FilePath($this->sEconetBase, 'recreate'), 4, 0, 0);
        $this->assertFileDoesNotExist($this->cachePathFor('econet/recreate'));
    }

    public function testDeleteFileInvalidatesCache(): void
    {
        $this->oS3->seed('econet/todelete', 'bye');
        S3Plugin::getFile($this->oUser, new FilePath($this->sEconetBase, 'todelete'));
        $this->assertFileExists($this->cachePathFor('econet/todelete'));

        S3Plugin::deleteFile($this->oUser, new FilePath($this->sEconetBase, 'todelete'));
        $this->assertFileDoesNotExist($this->cachePathFor('econet/todelete'));
    }

    public function testMoveFileInvalidatesSourceCache(): void
    {
        $this->oS3->seed('econet/moveme', 'data');
        S3Plugin::getFile($this->oUser, new FilePath($this->sEconetBase, 'moveme'));
        $this->assertFileExists($this->cachePathFor('econet/moveme'));

        S3Plugin::moveFile(
            $this->oUser,
            new FilePath($this->sEconetBase, 'moveme'),
            new FilePath($this->sEconetBase, 'moved')
        );
        $this->assertFileDoesNotExist($this->cachePathFor('econet/moveme'));
    }

    public function testWriteHandleOpenInvalidatesCacheImmediately(): void
    {
        // Prime cache with initial content.
        $this->oS3->seed('econet/writable', 'before write');
        S3Plugin::getFile($this->oUser, new FilePath($this->sEconetBase, 'writable'));
        $this->assertFileExists($this->cachePathFor('econet/writable'));

        // Open a write handle — cache should be removed on open, not just on close.
        $oFd = S3Plugin::_buildFiledescriptorFromEconetPath(
            $this->oUser, new FilePath($this->sEconetBase, 'writable'), true, false
        );
        $this->assertFileDoesNotExist($this->cachePathFor('econet/writable'));

        $oFd->write('after write');
        $oFd->close();

        // Cache should still be absent after write-back.
        $this->assertFileDoesNotExist($this->cachePathFor('econet/writable'));
    }

    // -------------------------------------------------------------------------
    // Write-handle fence — reads while a write is in flight
    // -------------------------------------------------------------------------

    public function testOpenWriteHandlePreventsReadCaching(): void
    {
        $this->oS3->seed('econet/fenced', 'original');

        // Open a write handle — subsequent read handles must not cache.
        $oFdWrite = S3Plugin::_buildFiledescriptorFromEconetPath(
            $this->oUser, new FilePath($this->sEconetBase, 'fenced'), true, false
        );

        $oFdRead = S3Plugin::_buildFiledescriptorFromEconetPath(
            $this->oUser, new FilePath($this->sEconetBase, 'fenced'), true, true
        );
        $oFdRead->close();

        $this->assertFileDoesNotExist($this->cachePathFor('econet/fenced'));

        $oFdWrite->close();
    }

    public function testReadHandleBypassesCacheWhenWriteHandleOpen(): void
    {
        $this->oS3->seed('econet/bypass', 'v1');

        // Prime cache.
        S3Plugin::getFile($this->oUser, new FilePath($this->sEconetBase, 'bypass'));
        $this->oS3->resetCallCounts();

        // Open write handle (invalidates cache, registers fence).
        $oFdWrite = S3Plugin::_buildFiledescriptorFromEconetPath(
            $this->oUser, new FilePath($this->sEconetBase, 'bypass'), true, false
        );
        $this->oS3->resetCallCounts();

        // A read handle while the write handle is live must go to S3, not cache.
        $oFdRead = S3Plugin::_buildFiledescriptorFromEconetPath(
            $this->oUser, new FilePath($this->sEconetBase, 'bypass'), true, true
        );
        $this->assertGreaterThan(0, $this->oS3->getGetObjectCallCount());

        $oFdRead->close();
        $oFdWrite->close();
    }

    public function testGetFileBypassesCacheWhenWriteHandleOpen(): void
    {
        $this->oS3->seed('econet/getbypass', 'content');

        // Prime cache.
        S3Plugin::getFile($this->oUser, new FilePath($this->sEconetBase, 'getbypass'));
        $this->oS3->resetCallCounts();

        // Open write handle.
        $oFdWrite = S3Plugin::_buildFiledescriptorFromEconetPath(
            $this->oUser, new FilePath($this->sEconetBase, 'getbypass'), true, false
        );
        $this->oS3->resetCallCounts();

        // getFile while write handle is live must hit S3.
        S3Plugin::getFile($this->oUser, new FilePath($this->sEconetBase, 'getbypass'));
        $this->assertGreaterThan(0, $this->oS3->getGetObjectCallCount());

        $oFdWrite->close();
    }

    public function testWriteHandleFenceLiftsAfterClose(): void
    {
        $this->oS3->seed('econet/unfenced', 'data');

        $oFdWrite = S3Plugin::_buildFiledescriptorFromEconetPath(
            $this->oUser, new FilePath($this->sEconetBase, 'unfenced'), true, false
        );
        $oFdWrite->close();

        // After the write handle is closed, read handles should cache again.
        $oFdRead = S3Plugin::_buildFiledescriptorFromEconetPath(
            $this->oUser, new FilePath($this->sEconetBase, 'unfenced'), true, true
        );
        $oFdRead->close();

        $this->assertFileExists($this->cachePathFor('econet/unfenced'));
    }

    // -------------------------------------------------------------------------
    // Config defaults
    // -------------------------------------------------------------------------

    public function testDefaultCacheDirUsedWhenConfigAbsent(): void
    {
        S3Plugin::reset();
        $oLogger = new Logger('s3test');
        $oLogger->pushHandler(new NullHandler());
        config::overrideValue('vfs_plugin_s3_mappings', json_encode([$this->aMapping]));
        // Deliberately do NOT set vfs_plugin_s3_cache_dir
        S3Plugin::init($oLogger);
        S3Plugin::setS3Client($this->sEconetBase, $this->oS3);

        // With no config the plugin uses /var/lib/cache/aun/s3/ — this will likely
        // not be writable in CI; the plugin silently skips caching.  The important
        // assertion is that it still reads the file correctly from S3.
        $this->oS3->seed('econet/default', 'default dir test');
        $sData = S3Plugin::getFile($this->oUser, new FilePath($this->sEconetBase, 'default'));
        $this->assertSame('default dir test', $sData);
    }
}
