<?php

/*
 * Unit tests for HomeLan\FileStore\Command\S3Upload.
 *
 * S3Upload creates an S3Client inside execute() via the protected
 * _buildClient() method.  Tests use a subclass (TestableS3Upload) that
 * overrides _buildClient() with a spy double so no real AWS calls are made.
 *
 * The AWS SDK S3Client exposes API operations via __call(), so the spy
 * records calls to putObject() via __call rather than a real method.
 */

include_once(__DIR__ . '/../../src/include/system.inc.php');

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Console\Command\Command;
use HomeLan\FileStore\Command\S3Upload;
use Aws\S3\S3Client;

// ---------------------------------------------------------------------------
// S3Client spy — records putObject calls without touching AWS
// ---------------------------------------------------------------------------

class S3ClientSpy extends S3Client
{
    public array $aCalls = [];

    public function __construct() {}  // skip real construction

    public function putObject(array $aArgs): void
    {
        $this->aCalls[] = $aArgs;
    }
}

// ---------------------------------------------------------------------------
// Testable subclass — replaces _buildClient() with the spy
// ---------------------------------------------------------------------------

class TestableS3Upload extends S3Upload
{
    public S3ClientSpy $oSpy;
    public ?\Aws\S3\Exception\S3Exception $oThrow = null;

    protected function _buildClient(array $aMapping): S3Client
    {
        if ($this->oThrow !== null) {
            throw $this->oThrow;
        }
        return $this->oSpy;
    }
}

// ---------------------------------------------------------------------------
// Test suite
// ---------------------------------------------------------------------------

class S3UploadTest extends TestCase
{
    private string $sTmpDir;

    protected function setUp(): void
    {
        $this->sTmpDir = sys_get_temp_dir() . '/s3upload_test_' . uniqid();
        mkdir($this->sTmpDir, 0755, true);

        // Default config: one valid mapping
        config::overrideValue('vfs_plugin_s3_mappings', json_encode([[
            'econet_path' => '$.s3files',
            'bucket'      => 'test-bucket',
            'prefix'      => 'uploads',
            'region'      => 'eu-west-1',
            'key'         => 'AKID',
            'secret'      => 'SECRET',
        ]]));
    }

    protected function tearDown(): void
    {
        config::resetValue('vfs_plugin_s3_mappings');
        $this->_rmDir($this->sTmpDir);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function _rmDir(string $sDir): void
    {
        if (!is_dir($sDir)) {
            return;
        }
        $oIt = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($oIt as $oItem) {
            $oItem->isDir() ? rmdir($oItem->getRealPath()) : unlink($oItem->getRealPath());
        }
        rmdir($sDir);
    }

    private function _writeFile(string $sRelPath, string $sContent = 'data'): string
    {
        $sFullPath = $this->sTmpDir . '/' . $sRelPath;
        $sDirPath  = dirname($sFullPath);
        if (!is_dir($sDirPath)) {
            mkdir($sDirPath, 0755, true);
        }
        file_put_contents($sFullPath, $sContent);
        return $sFullPath;
    }

    private function _writeInf(string $sRelPath, int $iLoad, int $iExec): void
    {
        $sInf = 'TAPE file '
            . str_pad(dechex($iLoad), 8, '0', STR_PAD_LEFT) . ' '
            . str_pad(dechex($iExec), 8, '0', STR_PAD_LEFT);
        file_put_contents($this->sTmpDir . '/' . $sRelPath . '.inf', $sInf);
    }

    private function _makeCommand(): TestableS3Upload
    {
        $oCmd      = new TestableS3Upload();
        $oCmd->oSpy = new S3ClientSpy();
        return $oCmd;
    }

    private function _run(
        TestableS3Upload $oCmd,
        string $sSource,
        string $sMapping = '$.s3files',
        array $aExtra = []
    ): CommandTester {
        $oTester = new CommandTester($oCmd);
        $oTester->execute(array_merge([
            'source'    => $sSource,
            '--mapping' => $sMapping,
        ], $aExtra));
        return $oTester;
    }

    // =========================================================================
    // Input validation
    // =========================================================================

    public function testMissingMappingOptionReturnsFailure(): void
    {
        $oCmd    = $this->_makeCommand();
        $oTester = new CommandTester($oCmd);
        $oTester->execute(['source' => $this->sTmpDir, '--mapping' => '']);
        $this->assertSame(Command::FAILURE, $oTester->getStatusCode());
    }

    public function testMissingMappingOptionWritesError(): void
    {
        $oCmd    = $this->_makeCommand();
        $oTester = new CommandTester($oCmd);
        $oTester->execute(['source' => $this->sTmpDir, '--mapping' => '']);
        $this->assertStringContainsString('--mapping is required', $oTester->getDisplay());
    }

    public function testMissingS3MappingsConfigReturnsFailure(): void
    {
        config::overrideValue('vfs_plugin_s3_mappings', '');
        $oCmd    = $this->_makeCommand();
        $oTester = $this->_run($oCmd, $this->sTmpDir);
        $this->assertSame(Command::FAILURE, $oTester->getStatusCode());
    }

    public function testMissingS3MappingsConfigWritesError(): void
    {
        config::overrideValue('vfs_plugin_s3_mappings', '');
        $oCmd    = $this->_makeCommand();
        $oTester = $this->_run($oCmd, $this->sTmpDir);
        $this->assertStringContainsString('vfs_plugin_s3_mappings is not configured', $oTester->getDisplay());
    }

    public function testUnknownMappingPathReturnsFailure(): void
    {
        $oCmd    = $this->_makeCommand();
        $oTester = $this->_run($oCmd, $this->sTmpDir, '$.notexist');
        $this->assertSame(Command::FAILURE, $oTester->getStatusCode());
    }

    public function testUnknownMappingPathWritesError(): void
    {
        $oCmd    = $this->_makeCommand();
        $oTester = $this->_run($oCmd, $this->sTmpDir, '$.notexist');
        $this->assertStringContainsString('No S3 mapping found for', $oTester->getDisplay());
    }

    public function testNonExistentSourceReturnsFailure(): void
    {
        $oCmd    = $this->_makeCommand();
        $oTester = $this->_run($oCmd, '/no/such/path/exists');
        $this->assertSame(Command::FAILURE, $oTester->getStatusCode());
    }

    public function testNonExistentSourceWritesError(): void
    {
        $oCmd    = $this->_makeCommand();
        $oTester = $this->_run($oCmd, '/no/such/path/exists');
        $this->assertStringContainsString('Source path does not exist', $oTester->getDisplay());
    }

    // =========================================================================
    // Dry-run mode
    // =========================================================================

    public function testDryRunReturnsSuccess(): void
    {
        $this->_writeFile('FILE1');
        $oCmd    = $this->_makeCommand();
        $oTester = $this->_run($oCmd, $this->sTmpDir, '$.s3files', ['--dry-run' => true]);
        $this->assertSame(Command::SUCCESS, $oTester->getStatusCode());
    }

    public function testDryRunDoesNotCallPutObject(): void
    {
        $this->_writeFile('FILE1');
        $oCmd    = $this->_makeCommand();
        $this->_run($oCmd, $this->sTmpDir, '$.s3files', ['--dry-run' => true]);
        $this->assertEmpty($oCmd->oSpy->aCalls);
    }

    public function testDryRunOutputMentionsWouldUpload(): void
    {
        $this->_writeFile('FILE1');
        $oCmd    = $this->_makeCommand();
        $oTester = $this->_run($oCmd, $this->sTmpDir, '$.s3files', ['--dry-run' => true]);
        $this->assertStringContainsString('[dry-run]', $oTester->getDisplay());
    }

    public function testDryRunOutputIncludesFilePath(): void
    {
        $sFile   = $this->_writeFile('MYFILE');
        $oCmd    = $this->_makeCommand();
        $oTester = $this->_run($oCmd, $this->sTmpDir, '$.s3files', ['--dry-run' => true]);
        $this->assertStringContainsString($sFile, $oTester->getDisplay());
    }

    public function testDryRunSummaryIncludesWouldBeUploaded(): void
    {
        $this->_writeFile('FILE1');
        $oCmd    = $this->_makeCommand();
        $oTester = $this->_run($oCmd, $this->sTmpDir, '$.s3files', ['--dry-run' => true]);
        $this->assertStringContainsString('would be', $oTester->getDisplay());
    }

    // =========================================================================
    // Successful upload
    // =========================================================================

    public function testUploadSingleFileReturnsSuccess(): void
    {
        $this->_writeFile('HELLO');
        $oCmd    = $this->_makeCommand();
        $oTester = $this->_run($oCmd, $this->sTmpDir);
        $this->assertSame(Command::SUCCESS, $oTester->getStatusCode());
    }

    public function testUploadSingleFileCallsPutObjectTwice(): void
    {
        // Once for the file, once for the .inf sidecar
        $this->_writeFile('HELLO');
        $oCmd = $this->_makeCommand();
        $this->_run($oCmd, $this->sTmpDir);
        $this->assertCount(2, $oCmd->oSpy->aCalls);
    }

    public function testUploadUsesCorrectBucket(): void
    {
        $this->_writeFile('HELLO');
        $oCmd = $this->_makeCommand();
        $this->_run($oCmd, $this->sTmpDir);
        $this->assertSame('test-bucket', $oCmd->oSpy->aCalls[0]['Bucket']);
    }

    public function testUploadConstructsS3KeyWithPrefix(): void
    {
        $this->_writeFile('HELLO');
        $oCmd = $this->_makeCommand();
        $this->_run($oCmd, $this->sTmpDir);
        $this->assertSame('uploads/HELLO', $oCmd->oSpy->aCalls[0]['Key']);
    }

    public function testUploadInfSidecarKeyHasInfSuffix(): void
    {
        $this->_writeFile('HELLO');
        $oCmd = $this->_makeCommand();
        $this->_run($oCmd, $this->sTmpDir);
        $this->assertSame('uploads/HELLO.inf', $oCmd->oSpy->aCalls[1]['Key']);
    }

    public function testUploadOutputConfirmsFile(): void
    {
        $this->_writeFile('HELLO');
        $oCmd    = $this->_makeCommand();
        $oTester = $this->_run($oCmd, $this->sTmpDir);
        $this->assertStringContainsString('Uploaded:', $oTester->getDisplay());
    }

    public function testUploadSummaryCountIsCorrect(): void
    {
        $this->_writeFile('FILE1');
        $this->_writeFile('FILE2');
        $oCmd    = $this->_makeCommand();
        $oTester = $this->_run($oCmd, $this->sTmpDir);
        $this->assertStringContainsString('2 file(s)', $oTester->getDisplay());
    }

    // =========================================================================
    // .inf sidecar handling
    // =========================================================================

    public function testInfSidecarSkippedAsMainFile(): void
    {
        // .inf files must not be uploaded as data files themselves
        $this->_writeFile('HELLO');
        $this->_writeFile('HELLO.inf', 'TAPE file ffff0e00 ffff8023');
        $oCmd = $this->_makeCommand();
        $this->_run($oCmd, $this->sTmpDir);
        // Only HELLO triggers uploads — 2 putObject calls, not 4
        $this->assertCount(2, $oCmd->oSpy->aCalls);
    }

    public function testInfSidecarParsedForLoadAddress(): void
    {
        $this->_writeFile('HELLO');
        $this->_writeInf('HELLO', 0xFFFF0E00, 0xFFFF8023);
        $oCmd = $this->_makeCommand();
        $this->_run($oCmd, $this->sTmpDir);
        $sInfBody = $oCmd->oSpy->aCalls[1]['Body'];
        $this->assertStringContainsString('ffff0e00', $sInfBody);
    }

    public function testInfSidecarParsedForExecAddress(): void
    {
        $this->_writeFile('HELLO');
        $this->_writeInf('HELLO', 0xFFFF0E00, 0xFFFF8023);
        $oCmd = $this->_makeCommand();
        $this->_run($oCmd, $this->sTmpDir);
        $sInfBody = $oCmd->oSpy->aCalls[1]['Body'];
        $this->assertStringContainsString('ffff8023', $sInfBody);
    }

    public function testMissingInfUsesDefaultLoadAddress(): void
    {
        $this->_writeFile('HELLO');
        $oCmd = $this->_makeCommand();
        $this->_run($oCmd, $this->sTmpDir);
        $sInfBody = $oCmd->oSpy->aCalls[1]['Body'];
        $this->assertStringContainsString('ffff0000', $sInfBody);
    }

    public function testInfBodyHasTapeFilePrefix(): void
    {
        $this->_writeFile('HELLO');
        $oCmd = $this->_makeCommand();
        $this->_run($oCmd, $this->sTmpDir);
        $this->assertStringStartsWith('TAPE file ', $oCmd->oSpy->aCalls[1]['Body']);
    }

    // =========================================================================
    // Mapping path matching
    // =========================================================================

    public function testMappingPathMatchesWithTrailingDot(): void
    {
        // '$.s3files.' should match mapping '$.s3files'
        $this->_writeFile('HELLO');
        $oCmd    = $this->_makeCommand();
        $oTester = $this->_run($oCmd, $this->sTmpDir, '$.s3files.');
        $this->assertSame(Command::SUCCESS, $oTester->getStatusCode());
    }

    public function testMappingWithoutPrefixUsesFileNameAsKey(): void
    {
        config::overrideValue('vfs_plugin_s3_mappings', json_encode([[
            'econet_path' => '$.noprefix',
            'bucket'      => 'my-bucket',
        ]]));
        $this->_writeFile('HELLO');
        $oCmd = $this->_makeCommand();
        $this->_run($oCmd, $this->sTmpDir, '$.noprefix');
        $this->assertSame('HELLO', $oCmd->oSpy->aCalls[0]['Key']);
    }

    // =========================================================================
    // Directory scanning
    // =========================================================================

    public function testDirectoryScanUploadsFilesInSubdirectory(): void
    {
        $this->_writeFile('subdir/DEEP');
        $oCmd = $this->_makeCommand();
        $this->_run($oCmd, $this->sTmpDir);
        $aKeys = array_column($oCmd->oSpy->aCalls, 'Key');
        $this->assertContains('uploads/subdir/DEEP', $aKeys);
    }

    public function testSingleFileSourceUploadsJustThatFile(): void
    {
        $sFile = $this->_writeFile('ONLY');
        $oCmd  = $this->_makeCommand();
        $this->_run($oCmd, $sFile);
        $this->assertCount(2, $oCmd->oSpy->aCalls);
    }

    // =========================================================================
    // S3Exception failure
    // =========================================================================

    public function testS3ExceptionReturnsFailure(): void
    {
        $this->_writeFile('HELLO');
        $oCmd          = $this->_makeCommand();
        $oCmd->oSpy    = new class extends S3ClientSpy {
            public function putObject(array $aArgs): void
            {
                throw new \Aws\S3\Exception\S3Exception(
                    'Upload failed',
                    new \Aws\Command('PutObject')
                );
            }
        };
        $oTester = $this->_run($oCmd, $this->sTmpDir);
        $this->assertSame(Command::FAILURE, $oTester->getStatusCode());
    }

    public function testS3ExceptionWritesError(): void
    {
        $this->_writeFile('HELLO');
        $oCmd       = $this->_makeCommand();
        $oCmd->oSpy = new class extends S3ClientSpy {
            public function putObject(array $aArgs): void
            {
                throw new \Aws\S3\Exception\S3Exception(
                    'Upload failed',
                    new \Aws\Command('PutObject')
                );
            }
        };
        $oTester = $this->_run($oCmd, $this->sTmpDir);
        $this->assertStringContainsString('Failed to upload', $oTester->getDisplay());
    }
}
