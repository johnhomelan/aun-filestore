<?php

/*
 * @group unit-tests
*/

if (!defined('CONFIG_security_mode')) {
    define('CONFIG_security_mode', 'singleuser');
}
if (!defined('CONFIG_vfs_plugin_s3_mappings')) {
    define('CONFIG_vfs_plugin_s3_mappings', '');
}

include_once(__DIR__ . '/../../src/include/system.inc.php');

use PHPUnit\Framework\TestCase;
use Monolog\Logger;
use Monolog\Handler\NullHandler;
use HomeLan\FileStore\Authentication\User;
use HomeLan\FileStore\Vfs\Plugin\S3 as S3Plugin;
use HomeLan\FileStore\Vfs\FilePath;
use HomeLan\FileStore\Vfs\Exception as VfsException;

/**
 * Minimal S3 client stub — covers the subset of the AWS SDK S3Client API
 * that the S3 VFS plugin calls.  State is tracked in an in-memory array
 * so tests can verify what was written.
 */
class StubS3Client {
    private array $aObjects = [];

    public function seed(string $sKey, string $sBody): void
    {
        $this->aObjects[$sKey] = $sBody;
    }

    public function getContents(): array
    {
        return $this->aObjects;
    }

    public function doesObjectExist(string $sBucket, string $sKey): bool
    {
        return isset($this->aObjects[$sKey]);
    }

    public function getObject(array $aArgs): array
    {
        $sKey = $aArgs['Key'];
        if (!isset($this->aObjects[$sKey])) {
            throw new \Aws\S3\Exception\S3Exception(
                'NoSuchKey',
                new \Aws\Command('GetObject')
            );
        }
        return ['Body' => $this->aObjects[$sKey]];
    }

    public function putObject(array $aArgs): array
    {
        $sKey  = $aArgs['Key'];
        $sBody = $aArgs['Body'] ?? '';
        if (isset($aArgs['SourceFile'])) {
            $sBody = file_get_contents($aArgs['SourceFile']);
        }
        $this->aObjects[$sKey] = (string) $sBody;
        return [];
    }

    public function deleteObject(array $aArgs): array
    {
        unset($this->aObjects[$aArgs['Key']]);
        return [];
    }

    public function copyObject(array $aArgs): array
    {
        $sSrc = ltrim($aArgs['CopySource'], '/');
        // CopySource = bucket/key — strip the bucket prefix
        $sKey = substr($sSrc, strpos($sSrc, '/') + 1);
        $this->aObjects[$aArgs['Key']] = $this->aObjects[$sKey] ?? '';
        return [];
    }

    public function listObjectsV2(array $aArgs): array
    {
        $sPrefix    = $aArgs['Prefix'] ?? '';
        $sDelimiter = $aArgs['Delimiter'] ?? '';

        $aContents       = [];
        $aCommonPrefixes = [];
        $aSeen           = [];

        foreach ($this->aObjects as $sKey => $sBody) {
            if (!str_starts_with($sKey, $sPrefix)) {
                continue;
            }
            $sAfterPrefix = substr($sKey, strlen($sPrefix));

            if ($sDelimiter !== '' && strpos($sAfterPrefix, $sDelimiter) !== false) {
                // This key is in a "subdirectory"
                $iSlash = strpos($sAfterPrefix, $sDelimiter);
                $sDir   = $sPrefix . substr($sAfterPrefix, 0, $iSlash + 1);
                if (!in_array($sDir, $aSeen)) {
                    $aSeen[]           = $sDir;
                    $aCommonPrefixes[] = ['Prefix' => $sDir];
                }
            } else {
                $aContents[] = [
                    'Key'          => $sKey,
                    'Size'         => strlen($sBody),
                    'LastModified' => new \DateTimeImmutable(),
                ];
            }
        }

        return ['Contents' => $aContents, 'CommonPrefixes' => $aCommonPrefixes];
    }
}

class vfsplugins3Test extends TestCase {

    private User $oUser;
    private StubS3Client $oS3;
    private array $aMapping;
    private string $sEconetBase = '$.s3files';

    protected function setUp(): void
    {
        $aMapping = [
            'econet_path'  => $this->sEconetBase,
            'bucket'       => 'testbucket',
            'prefix'       => 'econet',
            'region'       => 'eu-west-1',
            'write_enabled' => true,
        ];
        $this->aMapping = $aMapping;

        $oLogger = new Logger('s3test');
        $oLogger->pushHandler(new NullHandler());

        S3Plugin::reset();
        config::overrideValue('vfs_plugin_s3_mappings', json_encode([$aMapping]));
        S3Plugin::init($oLogger);

        $this->oS3 = new StubS3Client();
        S3Plugin::setS3Client($this->sEconetBase, $this->oS3);

        $this->oUser = new User();
        $this->oUser->setUsername('s3testuser');
        $this->oUser->setHomedir('$');
        $this->oUser->setBootOpt(0);
        $this->oUser->setUnixUid(5000);
        $this->oUser->setPriv('u');
    }

    protected function tearDown(): void
    {
        S3Plugin::reset();
        config::resetValue('vfs_plugin_s3_mappings');
    }

    // -------------------------------------------------------------------------
    // Path conversion helpers (tested indirectly via saveFile / getFile)
    // -------------------------------------------------------------------------

    public function testSaveFileCreatesObjectAndInf(): void
    {
        S3Plugin::saveFile(
            $this->oUser,
            new FilePath($this->sEconetBase, 'hello'),
            'hello world',
            0xFF0408, 0xFF0509
        );
        $aContents = $this->oS3->getContents();
        $this->assertArrayHasKey('econet/hello', $aContents);
        $this->assertArrayHasKey('econet/hello.inf', $aContents);
        $this->assertSame('hello world', $aContents['econet/hello']);
        $this->assertSame('TAPE file 00ff0408 00ff0509', $aContents['econet/hello.inf']);
    }

    public function testSaveFileInSubdir(): void
    {
        S3Plugin::saveFile(
            $this->oUser,
            new FilePath($this->sEconetBase . '.docs', 'readme'),
            'doc content',
            0x00000000, 0x00000000
        );
        $this->assertArrayHasKey('econet/docs/readme', $this->oS3->getContents());
        $this->assertArrayHasKey('econet/docs/readme.inf', $this->oS3->getContents());
    }

    public function testGetFileReturnsContent(): void
    {
        $this->oS3->seed('econet/myfile', 'binary data');
        $sData = S3Plugin::getFile($this->oUser, new FilePath($this->sEconetBase, 'myfile'));
        $this->assertSame('binary data', $sData);
    }

    public function testGetFileMissingThrowsVfsException(): void
    {
        $this->expectException(VfsException::class);
        S3Plugin::getFile($this->oUser, new FilePath($this->sEconetBase, 'nosuchfile'));
    }

    public function testGetFileNoMappingThrowsVfsException(): void
    {
        $this->expectException(VfsException::class);
        S3Plugin::getFile($this->oUser, new FilePath('$.othertree', 'myfile'));
    }

    // -------------------------------------------------------------------------
    // Directory listing
    // -------------------------------------------------------------------------

    public function testDirectoryListingShowsFiles(): void
    {
        $this->oS3->seed('econet/alpha', 'aaa');
        $this->oS3->seed('econet/alpha.inf', 'TAPE file 00ff0408 00ff0509');
        $this->oS3->seed('econet/beta', 'bbb');

        $aListing = S3Plugin::getDirectoryListing($this->sEconetBase, []);
        $this->assertArrayHasKey('alpha', $aListing);
        $this->assertArrayHasKey('beta', $aListing);
        $this->assertArrayNotHasKey('alpha.inf', $aListing);
    }

    public function testDirectoryListingLoadsInf(): void
    {
        $this->oS3->seed('econet/prog', 'data');
        $this->oS3->seed('econet/prog.inf', 'TAPE file ffff1600 ffff8023');

        $aListing = S3Plugin::getDirectoryListing($this->sEconetBase, []);
        $this->assertArrayHasKey('prog', $aListing);
        $this->assertSame(0xffff1600, $aListing['prog']->getLoadAddr());
        $this->assertSame(0xffff8023, $aListing['prog']->getExecAddr());
    }

    public function testDirectoryListingShowsSubdirectories(): void
    {
        $this->oS3->seed('econet/subdir/file1', 'x');
        $this->oS3->seed('econet/subdir/file2', 'y');

        $aListing = S3Plugin::getDirectoryListing($this->sEconetBase, []);
        $this->assertArrayHasKey('subdir', $aListing);
        $this->assertTrue($aListing['subdir']->isDir());
    }

    public function testDirectoryListingEmptyForUnknownMapping(): void
    {
        $aListing = S3Plugin::getDirectoryListing('$.notmapped', []);
        $this->assertSame([], $aListing);
    }

    public function testDirectoryListingInSubdir(): void
    {
        $this->oS3->seed('econet/docs/readme', 'readme content');

        $aListing = S3Plugin::getDirectoryListing($this->sEconetBase . '.docs', []);
        $this->assertArrayHasKey('readme', $aListing);
    }

    // -------------------------------------------------------------------------
    // createDirectory
    // -------------------------------------------------------------------------

    public function testCreateDirectoryCreatesPlaceholder(): void
    {
        S3Plugin::createDirectory($this->oUser, new FilePath($this->sEconetBase, 'newdir'));
        $this->assertArrayHasKey('econet/newdir/', $this->oS3->getContents());
    }

    public function testCreateDirectoryReturnsFalseForUnmapped(): void
    {
        $bResult = S3Plugin::createDirectory($this->oUser, new FilePath('$.other', 'newdir'));
        $this->assertFalse($bResult);
    }

    // -------------------------------------------------------------------------
    // deleteFile
    // -------------------------------------------------------------------------

    public function testDeleteFileRemovesObjectAndInf(): void
    {
        $this->oS3->seed('econet/todelete', 'data');
        $this->oS3->seed('econet/todelete.inf', 'TAPE file 00000000 00000000');

        $bResult = S3Plugin::deleteFile($this->oUser, new FilePath($this->sEconetBase, 'todelete'));
        $this->assertTrue($bResult);
        $this->assertArrayNotHasKey('econet/todelete', $this->oS3->getContents());
        $this->assertArrayNotHasKey('econet/todelete.inf', $this->oS3->getContents());
    }

    public function testDeleteFileMissingReturnsFalse(): void
    {
        $bResult = S3Plugin::deleteFile($this->oUser, new FilePath($this->sEconetBase, 'nosuchfile'));
        $this->assertFalse($bResult);
    }

    public function testDeleteFileUnmappedReturnsFalse(): void
    {
        $bResult = S3Plugin::deleteFile($this->oUser, new FilePath('$.other', 'file'));
        $this->assertFalse($bResult);
    }

    // -------------------------------------------------------------------------
    // moveFile
    // -------------------------------------------------------------------------

    public function testMoveFileRenamesObjectAndInf(): void
    {
        $this->oS3->seed('econet/original', 'my data');
        $this->oS3->seed('econet/original.inf', 'TAPE file 00000000 00000000');

        S3Plugin::moveFile(
            $this->oUser,
            new FilePath($this->sEconetBase, 'original'),
            new FilePath($this->sEconetBase, 'renamed')
        );

        $aContents = $this->oS3->getContents();
        $this->assertArrayHasKey('econet/renamed', $aContents);
        $this->assertArrayHasKey('econet/renamed.inf', $aContents);
        $this->assertArrayNotHasKey('econet/original', $aContents);
        $this->assertArrayNotHasKey('econet/original.inf', $aContents);
    }

    // -------------------------------------------------------------------------
    // createFile
    // -------------------------------------------------------------------------

    public function testCreateFileUploadsPaddedBody(): void
    {
        S3Plugin::createFile($this->oUser, new FilePath($this->sEconetBase, 'newfile'), 16, 0x00000000, 0x00000000);
        $aContents = $this->oS3->getContents();
        $this->assertArrayHasKey('econet/newfile', $aContents);
        $this->assertSame(16, strlen($aContents['econet/newfile']));
        $this->assertSame(str_repeat("\x00", 16), $aContents['econet/newfile']);
    }

    // -------------------------------------------------------------------------
    // setMeta
    // -------------------------------------------------------------------------

    public function testSetMetaUpdatesInf(): void
    {
        $this->oS3->seed('econet/prog', 'data');
        $this->oS3->seed('econet/prog.inf', 'TAPE file 00001234 00005678');

        S3Plugin::setMeta($this->sEconetBase . '.prog', 0xABCD0000, 0x12340000, 0);

        $aContents = $this->oS3->getContents();
        $this->assertStringContainsString('abcd0000', $aContents['econet/prog.inf']);
        $this->assertStringContainsString('12340000', $aContents['econet/prog.inf']);
    }

    public function testSetMetaCreatesInfWhenMissing(): void
    {
        $this->oS3->seed('econet/prog', 'data');
        S3Plugin::setMeta($this->sEconetBase . '.prog', 0x00001234, 0x00005678, 0);
        $aContents = $this->oS3->getContents();
        $this->assertArrayHasKey('econet/prog.inf', $aContents);
        $this->assertStringContainsString('00001234', $aContents['econet/prog.inf']);
    }

    public function testSetMetaIgnoresUnmappedPath(): void
    {
        // Should silently return without throwing
        S3Plugin::setMeta('$.notmapped.prog', 0x1234, 0x5678, 0);
        $this->assertTrue(true);
    }

    // -------------------------------------------------------------------------
    // In-memory handle I/O
    // -------------------------------------------------------------------------

    public function testHandleReadWriteAndClose(): void
    {
        $this->oS3->seed('econet/rw', 'initial data');

        $oFd = S3Plugin::_buildFiledescriptorFromEconetPath(
            $this->oUser,
            new FilePath($this->sEconetBase, 'rw'),
            true,
            false
        );

        $this->assertSame(0, $oFd->fsFTell());
        $this->assertFalse($oFd->isEof());

        $sRead = $oFd->read(7);
        $this->assertSame('initial', $sRead);
        $this->assertSame(7, $oFd->fsFTell());

        $oFd->setPos(0);
        $oFd->write('OVERWRITE');

        $oFd->close();

        // 'initial data' with 'OVERWRITE' (9 chars) written at offset 0
        // substr_replace replaces 9 chars → 'OVERWRITEata'
        $this->assertSame('OVERWRITEata', $this->oS3->getContents()['econet/rw']);
    }

    public function testHandleReadOnly(): void
    {
        $this->oS3->seed('econet/ro', 'read only data');
        $oFd = S3Plugin::_buildFiledescriptorFromEconetPath(
            $this->oUser, new FilePath($this->sEconetBase, 'ro'), true, true
        );

        $this->expectException(VfsException::class);
        $oFd->write('should fail');
    }

    public function testHandleEofDetection(): void
    {
        $this->oS3->seed('econet/tiny', 'abc');
        $oFd = S3Plugin::_buildFiledescriptorFromEconetPath(
            $this->oUser, new FilePath($this->sEconetBase, 'tiny'), true, true
        );

        $oFd->read(3);
        $this->assertTrue($oFd->isEof());
    }

    public function testFsStat(): void
    {
        $this->oS3->seed('econet/statme', 'twelve chars');
        $oFd = S3Plugin::_buildFiledescriptorFromEconetPath(
            $this->oUser, new FilePath($this->sEconetBase, 'statme'), true, true
        );

        $aStat = $oFd->fsFStat();
        $this->assertSame(12, $aStat['size']);
    }

    public function testBuildFiledescriptorMissingFileMustExistThrows(): void
    {
        $this->expectException(VfsException::class);
        S3Plugin::_buildFiledescriptorFromEconetPath(
            $this->oUser, new FilePath($this->sEconetBase, 'nosuchfile'), true, false
        );
    }

    public function testBuildFiledescriptorNoMappingThrows(): void
    {
        $this->expectException(VfsException::class);
        S3Plugin::_buildFiledescriptorFromEconetPath(
            $this->oUser, new FilePath('$.notmapped', 'file'), true, false
        );
    }

    public function testBuildFiledescriptorNewFileNotMustExist(): void
    {
        $oFd = S3Plugin::_buildFiledescriptorFromEconetPath(
            $this->oUser, new FilePath($this->sEconetBase, 'newfile'), false, false
        );
        $this->assertInstanceOf(\HomeLan\FileStore\Vfs\FileDescriptor::class, $oFd);
        $this->assertFalse($oFd->isDir());
    }

    public function testReadOnlyHandleNotWrittenBackOnClose(): void
    {
        $this->oS3->seed('econet/nodirty', 'original');
        $oFd = S3Plugin::_buildFiledescriptorFromEconetPath(
            $this->oUser, new FilePath($this->sEconetBase, 'nodirty'), true, true
        );
        $oFd->close();
        // read-only handle — no putObject should have been called; original data preserved
        $this->assertSame('original', $this->oS3->getContents()['econet/nodirty']);
    }

    // -------------------------------------------------------------------------
    // Readonly mapping enforcement
    // -------------------------------------------------------------------------

    private function reinitReadonly(): void
    {
        $aMapping = [
            'econet_path' => $this->sEconetBase,
            'bucket'      => 'testbucket',
            'prefix'      => 'econet',
            'region'      => 'eu-west-1',
            // write_enabled absent — defaults to read-only
        ];
        S3Plugin::reset();
        $oLogger = new Logger('s3test');
        $oLogger->pushHandler(new NullHandler());
        config::overrideValue('vfs_plugin_s3_mappings', json_encode([$aMapping]));
        S3Plugin::init($oLogger);
        S3Plugin::setS3Client($this->sEconetBase, $this->oS3);
    }

    public function testReadonlyDefaultBlocksSaveFile(): void
    {
        $this->reinitReadonly();
        $this->expectException(VfsException::class);
        S3Plugin::saveFile($this->oUser, new FilePath($this->sEconetBase, 'f'), 'data', 0, 0);
    }

    public function testReadonlyDefaultBlocksCreateFile(): void
    {
        $this->reinitReadonly();
        $this->expectException(VfsException::class);
        S3Plugin::createFile($this->oUser, new FilePath($this->sEconetBase, 'f'), 16, 0, 0);
    }

    public function testReadonlyDefaultBlocksDeleteFile(): void
    {
        $this->oS3->seed('econet/f', 'data');
        $this->reinitReadonly();
        $this->expectException(VfsException::class);
        S3Plugin::deleteFile($this->oUser, new FilePath($this->sEconetBase, 'f'));
    }

    public function testReadonlyDefaultBlocksMoveFile(): void
    {
        $this->oS3->seed('econet/orig', 'data');
        $this->reinitReadonly();
        $this->expectException(VfsException::class);
        S3Plugin::moveFile(
            $this->oUser,
            new FilePath($this->sEconetBase, 'orig'),
            new FilePath($this->sEconetBase, 'dest')
        );
    }

    public function testReadonlyDefaultBlocksCreateDirectory(): void
    {
        $this->reinitReadonly();
        $this->expectException(VfsException::class);
        S3Plugin::createDirectory($this->oUser, new FilePath($this->sEconetBase, 'newdir'));
    }

    public function testReadonlyDefaultBlocksSetMeta(): void
    {
        $this->oS3->seed('econet/f', 'data');
        $this->reinitReadonly();
        $this->expectException(VfsException::class);
        S3Plugin::setMeta($this->sEconetBase . '.f', 0x1234, 0x5678, 0);
    }

    public function testReadonlyDefaultForcesHandleReadonly(): void
    {
        $this->oS3->seed('econet/rof', 'content');
        $this->reinitReadonly();
        // Open with $bReadOnly=false — mapping readonly must override it
        $oFd = S3Plugin::_buildFiledescriptorFromEconetPath(
            $this->oUser, new FilePath($this->sEconetBase, 'rof'), true, false
        );
        $this->expectException(VfsException::class);
        $oFd->write('should be blocked');
    }

    public function testReadonlyExplicitFalseBlocksWrites(): void
    {
        // write_enabled: false is the same as omitting it
        $aMapping = [
            'econet_path'  => $this->sEconetBase,
            'bucket'       => 'testbucket',
            'prefix'       => 'econet',
            'region'       => 'eu-west-1',
            'write_enabled' => false,
        ];
        S3Plugin::reset();
        $oLogger = new Logger('s3test');
        $oLogger->pushHandler(new NullHandler());
        config::overrideValue('vfs_plugin_s3_mappings', json_encode([$aMapping]));
        S3Plugin::init($oLogger);
        S3Plugin::setS3Client($this->sEconetBase, $this->oS3);

        $this->expectException(VfsException::class);
        S3Plugin::saveFile($this->oUser, new FilePath($this->sEconetBase, 'f'), 'data', 0, 0);
    }

    public function testDirectoryListingAccessModeReflectsReadonly(): void
    {
        $this->oS3->seed('econet/afile', 'x');
        $this->reinitReadonly();
        $aListing = S3Plugin::getDirectoryListing($this->sEconetBase, []);
        $this->assertArrayHasKey('afile', $aListing);
        // Readonly access: no write bits — Locked flag set, no W
        $sMode = $aListing['afile']->getEconetMode();
        $this->assertStringNotContainsString('W', $sMode);
    }

    public function testDirectoryListingAccessModeReflectsWritable(): void
    {
        $this->oS3->seed('econet/afile', 'x');
        $aListing = S3Plugin::getDirectoryListing($this->sEconetBase, []);
        $this->assertArrayHasKey('afile', $aListing);
        $sMode = $aListing['afile']->getEconetMode();
        $this->assertStringContainsString('W', $sMode);
    }

    // -------------------------------------------------------------------------
    // Multiple mappings
    // -------------------------------------------------------------------------

    public function testMultipleMappingsRoutedCorrectly(): void
    {
        $oLogger  = new Logger('s3test');
        $oLogger->pushHandler(new NullHandler());

        $aMapping2 = [
            'econet_path'  => '$.archive',
            'bucket'       => 'archivebucket',
            'prefix'       => 'bbc',
            'region'       => 'eu-west-1',
            'write_enabled' => true,
        ];

        S3Plugin::reset();
        config::overrideValue('vfs_plugin_s3_mappings', json_encode([$this->aMapping, $aMapping2]));
        S3Plugin::init($oLogger);

        $oS3a = new StubS3Client();
        $oS3b = new StubS3Client();
        S3Plugin::setS3Client($this->sEconetBase, $oS3a);
        S3Plugin::setS3Client('$.archive', $oS3b);

        S3Plugin::saveFile($this->oUser, new FilePath($this->sEconetBase, 'filea'), 'data a', 0, 0);
        S3Plugin::saveFile($this->oUser, new FilePath('$.archive', 'fileb'), 'data b', 0, 0);

        $this->assertArrayHasKey('econet/filea', $oS3a->getContents());
        $this->assertArrayNotHasKey('bbc/fileb', $oS3a->getContents());
        $this->assertArrayHasKey('bbc/fileb', $oS3b->getContents());
        $this->assertArrayNotHasKey('econet/filea', $oS3b->getContents());
    }
}
