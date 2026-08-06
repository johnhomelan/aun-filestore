<?php

/*
 * @group unit-tests
 *
 * Tests for the MakeCatalogueArchive command.
 */

if (!defined('CONFIG_security_mode')) {
    define('CONFIG_security_mode', 'singleuser');
}

include_once(__DIR__ . '/../../src/include/system.inc.php');

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use HomeLan\FileStore\Command\MakeCatalogueArchive;

class MakeCatalogueArchiveTest extends TestCase {

    protected string $sSourceDir;
    protected string $sOutputDir;

    protected function setUp(): void
    {
        $sBase = sys_get_temp_dir() . '/mkcattest_' . uniqid();
        $this->sSourceDir = $sBase . '/src';
        $this->sOutputDir = $sBase . '/out';
        mkdir($this->sSourceDir, 0755, true);
        mkdir($this->sOutputDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->_rmDir(dirname($this->sSourceDir));
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

    private function _writeFile(string $sRelPath, string $sContent): string
    {
        $sFullPath = $this->sSourceDir . '/' . $sRelPath;
        $sDir = dirname($sFullPath);
        if (!is_dir($sDir)) {
            mkdir($sDir, 0755, true);
        }
        file_put_contents($sFullPath, $sContent);
        return $sFullPath;
    }

    private function _writeInf(string $sRelPath, int $iLoad, int $iExec): void
    {
        $sInf = 'TAPE file ' . str_pad(dechex($iLoad), 8, '0', STR_PAD_LEFT)
              . ' ' . str_pad(dechex($iExec), 8, '0', STR_PAD_LEFT);
        file_put_contents($this->sSourceDir . '/' . $sRelPath . '.inf', $sInf);
    }

    private function _run(array $aArgs = [], array $aOptions = []): CommandTester
    {
        $oCmd    = new MakeCatalogueArchive();
        $oTester = new CommandTester($oCmd);
        $aInput  = array_merge(['source' => $this->sSourceDir], $aArgs);
        foreach ($aOptions as $k => $v) {
            $aInput['--' . $k] = $v;
        }
        $oTester->execute($aInput);
        return $oTester;
    }

    private function _readTarIndex(string $sTarPath): array
    {
        $sTempDir = sys_get_temp_dir() . '/mkcattest_rd_' . uniqid();
        mkdir($sTempDir, 0755, true);
        $oTar = new \PharData($sTarPath);
        $oTar->extractTo($sTempDir, 'index.json', true);
        $sJson = file_get_contents($sTempDir . '/index.json');
        $this->_rmDir($sTempDir);
        return json_decode($sJson, true) ?? [];
    }

    private function _tarContainsFile(string $sTarPath, string $sArchivePath): bool
    {
        $oTar = new \PharData($sTarPath);
        foreach ($oTar as $oFile) {
            if ($oFile->getFilename() === $sArchivePath || str_ends_with($oFile->getPathname(), '/' . $sArchivePath)) {
                return true;
            }
        }
        return false;
    }

    private function _extractFileFromTar(string $sTarPath, string $sArchivePath): ?string
    {
        $sTempDir = sys_get_temp_dir() . '/mkcattest_ex_' . uniqid();
        mkdir($sTempDir, 0755, true);
        try {
            $oTar = new \PharData($sTarPath);
            $oTar->extractTo($sTempDir, $sArchivePath, true);
            $sPath = $sTempDir . '/' . $sArchivePath;
            $sContent = file_exists($sPath) ? file_get_contents($sPath) : null;
        } catch (\Exception) {
            $sContent = null;
        }
        $this->_rmDir($sTempDir);
        return $sContent;
    }

    // -------------------------------------------------------------------------
    // Basic creation
    // -------------------------------------------------------------------------

    public function testCreatesOutputTar(): void
    {
        $this->_writeFile('game', 'game data');
        $sOutput = $this->sOutputDir . '/archive.tar';

        $oTester = $this->_run([], ['output' => $sOutput]);

        $this->assertSame(0, $oTester->getStatusCode());
        $this->assertFileExists($sOutput);
    }

    public function testTarContainsSourceFile(): void
    {
        $this->_writeFile('game', 'hello');
        $sOutput = $this->sOutputDir . '/archive.tar';

        $this->_run([], ['output' => $sOutput]);
        $sContent = $this->_extractFileFromTar($sOutput, 'game');
        $this->assertSame('hello', $sContent);
    }

    public function testTarContainsIndexJson(): void
    {
        $this->_writeFile('prog', 'binary');
        $sOutput = $this->sOutputDir . '/archive.tar';

        $this->_run([], ['output' => $sOutput]);
        $sIndex = $this->_extractFileFromTar($sOutput, 'index.json');
        $this->assertNotNull($sIndex);
        $aData = json_decode($sIndex, true);
        $this->assertArrayHasKey('files', $aData);
    }

    public function testTarDoesNotContainInfFiles(): void
    {
        $this->_writeFile('game', 'data');
        $this->_writeInf('game', 0xFFFF1900, 0xFFFF1900);
        $sOutput = $this->sOutputDir . '/archive.tar';

        $this->_run([], ['output' => $sOutput]);

        // .inf must not appear in the archive.
        $oTar = new \PharData($sOutput);
        foreach ($oTar as $oFile) {
            $this->assertStringEndsNotWith('.inf', $oFile->getFilename());
        }
    }

    public function testSubdirectoryFilesAreIncluded(): void
    {
        $this->_writeFile('utils/editor', 'ed');
        $sOutput = $this->sOutputDir . '/archive.tar';

        $this->_run([], ['output' => $sOutput]);
        $sContent = $this->_extractFileFromTar($sOutput, 'utils/editor');
        $this->assertSame('ed', $sContent);
    }

    // -------------------------------------------------------------------------
    // index.json catalogue entries
    // -------------------------------------------------------------------------

    public function testIndexJsonContainsFileEntry(): void
    {
        $this->_writeFile('game', 'data');
        $sOutput = $this->sOutputDir . '/archive.tar';

        $this->_run([], ['output' => $sOutput]);
        $aIndex = $this->_readTarIndex($sOutput);
        $this->assertArrayHasKey('game', $aIndex['files']);
    }

    public function testIndexJsonEntryHasCorrectMd5(): void
    {
        $sData = 'hello world';
        $this->_writeFile('hello', $sData);
        $sOutput = $this->sOutputDir . '/archive.tar';

        $this->_run([], ['output' => $sOutput]);
        $aIndex = $this->_readTarIndex($sOutput);
        $this->assertSame(md5($sData), $aIndex['files']['hello']['md5sum']);
    }

    public function testIndexJsonEntryHasCorrectSize(): void
    {
        $sData = str_repeat('x', 42);
        $this->_writeFile('bigfile', $sData);
        $sOutput = $this->sOutputDir . '/archive.tar';

        $this->_run([], ['output' => $sOutput]);
        $aIndex = $this->_readTarIndex($sOutput);
        $this->assertSame(42, $aIndex['files']['bigfile']['size']);
    }

    public function testIndexJsonLoadAndExecDefaultsWhenNoInf(): void
    {
        $this->_writeFile('bare', 'data');
        $sOutput = $this->sOutputDir . '/archive.tar';

        $this->_run([], ['output' => $sOutput]);
        $aIndex = $this->_readTarIndex($sOutput);
        $this->assertSame(0xFFFF0000, $aIndex['files']['bare']['load']);
        $this->assertSame(0xFFFF0000, $aIndex['files']['bare']['exec']);
    }

    public function testIndexJsonLoadAndExecReadFromInf(): void
    {
        $this->_writeFile('boot', 'rom');
        $this->_writeInf('boot', 0xFFFF8000, 0xFFFF8023);
        $sOutput = $this->sOutputDir . '/archive.tar';

        $this->_run([], ['output' => $sOutput]);
        $aIndex = $this->_readTarIndex($sOutput);
        $this->assertSame(0xFFFF8000, $aIndex['files']['boot']['load']);
        $this->assertSame(0xFFFF8023, $aIndex['files']['boot']['exec']);
    }

    public function testIndexJsonUrlIsRelativePath(): void
    {
        $this->_writeFile('utils/viewer', 'view');
        $sOutput = $this->sOutputDir . '/archive.tar';

        $this->_run([], ['output' => $sOutput]);
        $aIndex = $this->_readTarIndex($sOutput);
        // Econet key uses '.' separator; URL uses '/' separator
        $this->assertSame('utils/viewer', $aIndex['files']['utils.viewer']['url']);
    }

    public function testIndexJsonEconetKeyUsesEconetSeparator(): void
    {
        $this->_writeFile('apps/game', 'bin');
        $sOutput = $this->sOutputDir . '/archive.tar';

        $this->_run([], ['output' => $sOutput]);
        $aIndex = $this->_readTarIndex($sOutput);
        $this->assertArrayHasKey('apps.game', $aIndex['files']);
        $this->assertArrayNotHasKey('apps/game', $aIndex['files']);
    }

    // -------------------------------------------------------------------------
    // Version numbering — fresh archive (no existing tar)
    // -------------------------------------------------------------------------

    public function testNewFileGetsVersionOne(): void
    {
        $this->_writeFile('newfile', 'content');
        $sOutput = $this->sOutputDir . '/archive.tar';

        $this->_run([], ['output' => $sOutput]);
        $aIndex = $this->_readTarIndex($sOutput);
        $this->assertSame(1, $aIndex['files']['newfile']['version']);
    }

    // -------------------------------------------------------------------------
    // Version numbering — with existing tar
    // -------------------------------------------------------------------------

    public function testUnchangedFileCopiesVersion(): void
    {
        $sContent = 'stable content';
        $this->_writeFile('stable', $sContent);

        // First run — creates version 1.
        $sFirst = $this->sOutputDir . '/first.tar';
        $this->_run([], ['output' => $sFirst]);

        // Manually bump the version in a synthetic old index to "5".
        // Re-run with the first archive as existing-tar — same md5, so version must stay 5.
        // We'll fake this by building a custom old tar.
        $aOldFiles = ['stable' => ['version' => 5, 'md5sum' => md5($sContent), 'load' => 0, 'exec' => 0, 'size' => 14, 'url' => 'stable']];
        $sOldIndexJson = json_encode(['files' => $aOldFiles]);
        $sOldTar = $this->sOutputDir . '/old.tar';
        if (file_exists($sOldTar)) {
            unlink($sOldTar);
        }
        $oOldTar = new \PharData($sOldTar);
        $oOldTar->addFromString('index.json', $sOldIndexJson);

        $sSecond = $this->sOutputDir . '/second.tar';
        $this->_run([], ['output' => $sSecond, 'existing-tar' => $sOldTar]);

        $aIndex = $this->_readTarIndex($sSecond);
        $this->assertSame(5, $aIndex['files']['stable']['version']);
    }

    public function testChangedFileIncrementsVersion(): void
    {
        $this->_writeFile('changing', 'old content');

        // Build a synthetic old tar with a different md5 (simulating old content).
        $aOldFiles = ['changing' => ['version' => 3, 'md5sum' => md5('old content'), 'load' => 0, 'exec' => 0, 'size' => 11, 'url' => 'changing']];
        $sOldTar = $this->sOutputDir . '/old.tar';
        if (file_exists($sOldTar)) {
            unlink($sOldTar);
        }
        $oOldTar = new \PharData($sOldTar);
        $oOldTar->addFromString('index.json', json_encode(['files' => $aOldFiles]));

        // Now write a new (different) version of the file.
        file_put_contents($this->sSourceDir . '/changing', 'new content');

        $sOutput = $this->sOutputDir . '/archive.tar';
        $this->_run([], ['output' => $sOutput, 'existing-tar' => $sOldTar]);

        $aIndex = $this->_readTarIndex($sOutput);
        $this->assertSame(4, $aIndex['files']['changing']['version']);
    }

    public function testNewFileInDirectoryGetsVersionOneWhenExistingTarSupplied(): void
    {
        $this->_writeFile('existing', 'data');
        $this->_writeFile('brandnew', 'fresh');

        $aOldFiles = ['existing' => ['version' => 2, 'md5sum' => md5('data'), 'load' => 0, 'exec' => 0, 'size' => 4, 'url' => 'existing']];
        $sOldTar = $this->sOutputDir . '/old.tar';
        if (file_exists($sOldTar)) {
            unlink($sOldTar);
        }
        $oOldTar = new \PharData($sOldTar);
        $oOldTar->addFromString('index.json', json_encode(['files' => $aOldFiles]));

        $sOutput = $this->sOutputDir . '/archive.tar';
        $this->_run([], ['output' => $sOutput, 'existing-tar' => $sOldTar]);

        $aIndex = $this->_readTarIndex($sOutput);
        $this->assertSame(2, $aIndex['files']['existing']['version']);
        $this->assertSame(1, $aIndex['files']['brandnew']['version']);
    }

    public function testMissingExistingTarStartsFresh(): void
    {
        $this->_writeFile('file', 'x');
        $sOutput = $this->sOutputDir . '/archive.tar';

        // Point to a non-existent tar.
        $this->_run([], ['output' => $sOutput, 'existing-tar' => '/nonexistent/path/old.tar']);

        $aIndex = $this->_readTarIndex($sOutput);
        $this->assertSame(1, $aIndex['files']['file']['version']);
    }

    public function testExistingOutputFileIsRemovedBeforeCreation(): void
    {
        // Pre-create a file at the output path that is NOT a valid tar.
        // The command should unlink it and create a valid tar in its place.
        $sOutput = $this->sOutputDir . '/archive.tar';
        file_put_contents($sOutput, 'not a tar file');

        $this->_writeFile('item', 'hello');
        $oTester = $this->_run([], ['output' => $sOutput]);
        $this->assertSame(0, $oTester->getStatusCode());

        // The output must now be a readable tar containing index.json.
        $sIndex = $this->_extractFileFromTar($sOutput, 'index.json');
        $this->assertNotNull($sIndex);
        $aData = json_decode($sIndex, true);
        $this->assertArrayHasKey('files', $aData);
    }

    public function testInvalidSourceReturnsFailure(): void
    {
        $oCmd    = new MakeCatalogueArchive();
        $oTester = new CommandTester($oCmd);
        $oTester->execute(['source' => '/no/such/directory', '--output' => $this->sOutputDir . '/x.tar']);
        $this->assertSame(1, $oTester->getStatusCode());
    }
}
