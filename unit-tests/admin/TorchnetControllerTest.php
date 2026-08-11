<?php

/*
 * Tests for HomeLan\FileStore\Admin\Controller\TorchnetController.
 *
 * The HTTP action methods (browse, fileDownload) delegate to
 * ServiceDispatcher::create() — a static factory that requires a full
 * bootstrap — so only the two pure private helpers are exercised:
 *   - _isValidPath(string $sPath, array $aDrives): bool
 *   - _buildBreadcrumbs(string $sPath, array $aDrives): array
 */

include_once(__DIR__ . '/../../src/include/system.inc.php');

use PHPUnit\Framework\TestCase;
use HomeLan\FileStore\Admin\Controller\TorchnetController;

class TorchnetControllerTest extends TestCase
{
    private TorchnetController $oController;
    private \ReflectionMethod $oIsValidPath;
    private \ReflectionMethod $oBuildBreadcrumbs;

    /** Simple drive map shared across most tests */
    private array $aDrives = [
        'A' => '/data/torchnet/A',
        'B' => '/data/torchnet/B',
    ];

    protected function setUp(): void
    {
        $this->oController       = new TorchnetController();
        $this->oIsValidPath      = new \ReflectionMethod(TorchnetController::class, '_isValidPath');
        $this->oBuildBreadcrumbs = new \ReflectionMethod(TorchnetController::class, '_buildBreadcrumbs');
        $this->oIsValidPath->setAccessible(true);
        $this->oBuildBreadcrumbs->setAccessible(true);
    }

    private function isValid(string $sPath, ?array $aDrives = null): bool
    {
        return $this->oIsValidPath->invoke($this->oController, $sPath, $aDrives ?? $this->aDrives);
    }

    private function breadcrumbs(string $sPath, ?array $aDrives = null): array
    {
        return $this->oBuildBreadcrumbs->invoke($this->oController, $sPath, $aDrives ?? $this->aDrives);
    }

    // =========================================================================
    // _isValidPath()
    // =========================================================================

    public function testExactDriveRootIsValid(): void
    {
        $this->assertTrue($this->isValid('/data/torchnet/A'));
    }

    public function testPathUnderDriveIsValid(): void
    {
        $this->assertTrue($this->isValid('/data/torchnet/A.DOCS'));
    }

    public function testDeepPathUnderDriveIsValid(): void
    {
        $this->assertTrue($this->isValid('/data/torchnet/A.DOCS.Work.2024'));
    }

    public function testUnknownPathIsInvalid(): void
    {
        $this->assertFalse($this->isValid('/data/torchnet/C'));
    }

    public function testEtcPasswdIsInvalid(): void
    {
        $this->assertFalse($this->isValid('/etc/passwd'));
    }

    public function testEmptyStringIsInvalid(): void
    {
        $this->assertFalse($this->isValid(''));
    }

    public function testDrivePathWithNoDotSuffixIsValidOnlyWhenExact(): void
    {
        // '/data/torchnet/AB' is not a valid prefix of '/data/torchnet/A' —
        // the separator must be a dot, not just any character.
        $this->assertFalse($this->isValid('/data/torchnet/AB'));
    }

    public function testSecondDriveRootIsValid(): void
    {
        $this->assertTrue($this->isValid('/data/torchnet/B'));
    }

    public function testPathUnderSecondDriveIsValid(): void
    {
        $this->assertTrue($this->isValid('/data/torchnet/B.FILES'));
    }

    public function testEmptyDriveMapMakesEverythingInvalid(): void
    {
        $this->assertFalse($this->isValid('/data/torchnet/A', []));
    }

    // =========================================================================
    // _buildBreadcrumbs() — drive root
    // =========================================================================

    public function testDriveRootAlwaysStartsWithDrivesEntry(): void
    {
        $aBreadcrumbs = $this->breadcrumbs('/data/torchnet/A');
        $this->assertSame('Drives', $aBreadcrumbs[0]['label']);
    }

    public function testDriveRootFirstEntryPathIsEmptyString(): void
    {
        $aBreadcrumbs = $this->breadcrumbs('/data/torchnet/A');
        $this->assertSame('', $aBreadcrumbs[0]['path']);
    }

    public function testDriveRootReturnsTwoEntries(): void
    {
        $aBreadcrumbs = $this->breadcrumbs('/data/torchnet/A');
        $this->assertCount(2, $aBreadcrumbs);
    }

    public function testDriveRootSecondEntryLabelIncludesDriveLetter(): void
    {
        $aBreadcrumbs = $this->breadcrumbs('/data/torchnet/A');
        $this->assertStringContainsString('A', $aBreadcrumbs[1]['label']);
    }

    public function testDriveRootLastEntryPathIsNull(): void
    {
        $aBreadcrumbs = $this->breadcrumbs('/data/torchnet/A');
        $this->assertNull($aBreadcrumbs[1]['path']);
    }

    // =========================================================================
    // _buildBreadcrumbs() — one level under a drive
    // =========================================================================

    public function testOneLevelUnderDriveReturnsThreeEntries(): void
    {
        $aBreadcrumbs = $this->breadcrumbs('/data/torchnet/A.DOCS');
        $this->assertCount(3, $aBreadcrumbs);
    }

    public function testOneLevelDriveEntryPathIsTheRoot(): void
    {
        $aBreadcrumbs = $this->breadcrumbs('/data/torchnet/A.DOCS');
        $this->assertSame('/data/torchnet/A', $aBreadcrumbs[1]['path']);
    }

    public function testOneLevelSubdirLabelIsCorrect(): void
    {
        $aBreadcrumbs = $this->breadcrumbs('/data/torchnet/A.DOCS');
        $this->assertSame('DOCS', $aBreadcrumbs[2]['label']);
    }

    public function testOneLevelSubdirPathIsNull(): void
    {
        $aBreadcrumbs = $this->breadcrumbs('/data/torchnet/A.DOCS');
        $this->assertNull($aBreadcrumbs[2]['path']);
    }

    // =========================================================================
    // _buildBreadcrumbs() — two levels under a drive
    // =========================================================================

    public function testTwoLevelsReturnsFourEntries(): void
    {
        $aBreadcrumbs = $this->breadcrumbs('/data/torchnet/A.DOCS.Work');
        $this->assertCount(4, $aBreadcrumbs);
    }

    public function testTwoLevelsFirstSubdirHasFullPath(): void
    {
        $aBreadcrumbs = $this->breadcrumbs('/data/torchnet/A.DOCS.Work');
        $this->assertSame('/data/torchnet/A.DOCS', $aBreadcrumbs[2]['path']);
    }

    public function testTwoLevelsLastSubdirHasNullPath(): void
    {
        $aBreadcrumbs = $this->breadcrumbs('/data/torchnet/A.DOCS.Work');
        $this->assertNull($aBreadcrumbs[3]['path']);
    }

    public function testTwoLevelsAllLabelsAreCorrect(): void
    {
        $aBreadcrumbs = $this->breadcrumbs('/data/torchnet/A.DOCS.Work');
        $this->assertSame('Drives',  $aBreadcrumbs[0]['label']);
        $this->assertStringContainsString('A', $aBreadcrumbs[1]['label']);
        $this->assertSame('DOCS',    $aBreadcrumbs[2]['label']);
        $this->assertSame('Work',    $aBreadcrumbs[3]['label']);
    }

    // =========================================================================
    // _buildBreadcrumbs() — unknown path (no drive match)
    // =========================================================================

    public function testUnknownPathReturnsOnlyDrivesEntry(): void
    {
        $aBreadcrumbs = $this->breadcrumbs('/unknown/path');
        $this->assertCount(1, $aBreadcrumbs);
        $this->assertSame('Drives', $aBreadcrumbs[0]['label']);
    }

    // =========================================================================
    // _buildBreadcrumbs() — cumulative path invariant
    // =========================================================================

    public function testCumulativePathsGrowByOneSegment(): void
    {
        $aBreadcrumbs = $this->breadcrumbs('/data/torchnet/A.X.Y.Z');
        // [0] Drives, [1] Drive A (path=root), [2] X, [3] Y, [4] Z (null)
        $this->assertSame('/data/torchnet/A',     $aBreadcrumbs[1]['path']);
        $this->assertSame('/data/torchnet/A.X',   $aBreadcrumbs[2]['path']);
        $this->assertSame('/data/torchnet/A.X.Y', $aBreadcrumbs[3]['path']);
        $this->assertNull($aBreadcrumbs[4]['path']);
    }
}
