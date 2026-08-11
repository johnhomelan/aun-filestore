<?php

/*
 * Tests for HomeLan\FileStore\Admin\Controller\FileServerController.
 *
 * The HTTP action methods (browse, fileDownload) delegate to
 * ServiceDispatcher::create() — a static factory that requires a full
 * bootstrap — so only the pure private helper _buildBreadcrumbs() is
 * exercised here (via reflection).
 */

include_once(__DIR__ . '/../../src/include/system.inc.php');

use PHPUnit\Framework\TestCase;
use HomeLan\FileStore\Admin\Controller\FileServerController;

class FileServerControllerTest extends TestCase
{
    private FileServerController $oController;
    private \ReflectionMethod $oMethod;

    protected function setUp(): void
    {
        $this->oController = new FileServerController();
        $this->oMethod     = new \ReflectionMethod(FileServerController::class, '_buildBreadcrumbs');
        $this->oMethod->setAccessible(true);
    }

    private function breadcrumbs(string $sPath): array
    {
        return $this->oMethod->invoke($this->oController, $sPath);
    }

    // =========================================================================
    // Root path '$'
    // =========================================================================

    public function testRootPathReturnsSingleBreadcrumb(): void
    {
        $aBreadcrumbs = $this->breadcrumbs('$');
        $this->assertCount(1, $aBreadcrumbs);
    }

    public function testRootPathLabelIsDollarSign(): void
    {
        $aBreadcrumbs = $this->breadcrumbs('$');
        $this->assertSame('$', $aBreadcrumbs[0]['label']);
    }

    public function testRootPathHasNullPath(): void
    {
        $aBreadcrumbs = $this->breadcrumbs('$');
        $this->assertNull($aBreadcrumbs[0]['path']);
    }

    // =========================================================================
    // One level deep: '$.DIRNAME'
    // =========================================================================

    public function testOneLevelReturnsRootPlusDirEntry(): void
    {
        $aBreadcrumbs = $this->breadcrumbs('$.Documents');
        $this->assertCount(2, $aBreadcrumbs);
    }

    public function testOneLevelRootEntryHasDollarPath(): void
    {
        $aBreadcrumbs = $this->breadcrumbs('$.Documents');
        $this->assertSame('$', $aBreadcrumbs[0]['path']);
    }

    public function testOneLevelDirEntryHasCorrectLabel(): void
    {
        $aBreadcrumbs = $this->breadcrumbs('$.Documents');
        $this->assertSame('Documents', $aBreadcrumbs[1]['label']);
    }

    public function testOneLevelLastEntryPathIsNull(): void
    {
        $aBreadcrumbs = $this->breadcrumbs('$.Documents');
        $this->assertNull($aBreadcrumbs[1]['path']);
    }

    // =========================================================================
    // Two levels deep: '$.DIR1.DIR2'
    // =========================================================================

    public function testTwoLevelsReturnsThreeEntries(): void
    {
        $aBreadcrumbs = $this->breadcrumbs('$.Documents.Work');
        $this->assertCount(3, $aBreadcrumbs);
    }

    public function testTwoLevelsIntermediateEntryHasFullPath(): void
    {
        $aBreadcrumbs = $this->breadcrumbs('$.Documents.Work');
        $this->assertSame('$.Documents', $aBreadcrumbs[1]['path']);
    }

    public function testTwoLevelsIntermediateEntryHasCorrectLabel(): void
    {
        $aBreadcrumbs = $this->breadcrumbs('$.Documents.Work');
        $this->assertSame('Documents', $aBreadcrumbs[1]['label']);
    }

    public function testTwoLevelsLastEntryLabelIsCorrect(): void
    {
        $aBreadcrumbs = $this->breadcrumbs('$.Documents.Work');
        $this->assertSame('Work', $aBreadcrumbs[2]['label']);
    }

    public function testTwoLevelsLastEntryPathIsNull(): void
    {
        $aBreadcrumbs = $this->breadcrumbs('$.Documents.Work');
        $this->assertNull($aBreadcrumbs[2]['path']);
    }

    // =========================================================================
    // Three levels deep: '$.A.B.C'
    // =========================================================================

    public function testThreeLevelsReturnsFourEntries(): void
    {
        $aBreadcrumbs = $this->breadcrumbs('$.A.B.C');
        $this->assertCount(4, $aBreadcrumbs);
    }

    public function testThreeLevelsFirstIntermediateHasOneLevelPath(): void
    {
        $aBreadcrumbs = $this->breadcrumbs('$.A.B.C');
        $this->assertSame('$.A', $aBreadcrumbs[1]['path']);
    }

    public function testThreeLevelsSecondIntermediateHasTwoLevelPath(): void
    {
        $aBreadcrumbs = $this->breadcrumbs('$.A.B.C');
        $this->assertSame('$.A.B', $aBreadcrumbs[2]['path']);
    }

    public function testThreeLevelsLastEntryHasNullPath(): void
    {
        $aBreadcrumbs = $this->breadcrumbs('$.A.B.C');
        $this->assertNull($aBreadcrumbs[3]['path']);
    }

    public function testThreeLevelsAllLabelsAreCorrect(): void
    {
        $aBreadcrumbs = $this->breadcrumbs('$.Alpha.Beta.Gamma');
        $this->assertSame('$',     $aBreadcrumbs[0]['label']);
        $this->assertSame('Alpha', $aBreadcrumbs[1]['label']);
        $this->assertSame('Beta',  $aBreadcrumbs[2]['label']);
        $this->assertSame('Gamma', $aBreadcrumbs[3]['label']);
    }

    // =========================================================================
    // Breadcrumb structure invariants
    // =========================================================================

    public function testAllEntriesHaveLabelKey(): void
    {
        foreach ($this->breadcrumbs('$.Dir1.Dir2') as $aEntry) {
            $this->assertArrayHasKey('label', $aEntry);
        }
    }

    public function testAllEntriesHavePathKey(): void
    {
        foreach ($this->breadcrumbs('$.Dir1.Dir2') as $aEntry) {
            $this->assertArrayHasKey('path', $aEntry);
        }
    }

    public function testCumulativePathsIncrementByOneSegment(): void
    {
        // For '$.A.B.C', intermediate paths must be '$.A' then '$.A.B'
        $aBreadcrumbs = $this->breadcrumbs('$.A.B.C');
        $this->assertSame('$',    $aBreadcrumbs[0]['path']);
        $this->assertSame('$.A',  $aBreadcrumbs[1]['path']);
        $this->assertSame('$.A.B',$aBreadcrumbs[2]['path']);
        $this->assertNull($aBreadcrumbs[3]['path']);
    }
}
