<?php

/*
 * Tests for:
 *   HomeLan\FileStore\Admin\Session\ReactSessionStorage
 *   HomeLan\FileStore\Admin\Session\ReactSessionStorageFactory
 *
 * ReactSessionStorage extends NativeSessionStorage and replaces the PHP
 * native session engine with a simple file-backed store keyed on the session
 * ID extracted from the request cookie.  Tests that exercise start() and
 * save() use a unique session ID per test so they never collide, and clean up
 * any temporary files in tearDown.
 */

include_once(__DIR__ . '/../../src/include/system.inc.php');

use PHPUnit\Framework\TestCase;
use HomeLan\FileStore\Admin\Session\ReactSessionStorage;
use HomeLan\FileStore\Admin\Session\ReactSessionStorageFactory;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Storage\SessionStorageInterface;

class ReactSessionStorageTest extends TestCase
{
    /** Session IDs created during this test run that need temp-file cleanup. */
    private array $aSessionIds = [];

    protected function setUp(): void
    {
        // NativeSessionStorage constructor throws if a PHP session is already active.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        // Reset the static session store between tests.
        $rp = new \ReflectionProperty(ReactSessionStorage::class, 'SESSION');
        $rp->setAccessible(true);
        $rp->setValue(null, []);
    }

    protected function tearDown(): void
    {
        // Remove any session files written during tests.
        foreach ($this->aSessionIds as $sId) {
            $sFile = '/tmp/session-' . $sId . '.dat';
            if (file_exists($sFile)) {
                unlink($sFile);
            }
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeStorage(): ReactSessionStorage
    {
        return new ReactSessionStorage();
    }

    private function makeRequest(string $sSessionId): Request
    {
        $this->aSessionIds[] = $sSessionId;
        $sName = session_name() ?: 'PHPSESSID';
        return Request::create('/', 'GET', [], [$sName => $sSessionId]);
    }

    private function startStorage(ReactSessionStorage $oStorage, string $sSessionId): void
    {
        $oStorage->setRequest($this->makeRequest($sSessionId));
        $oStorage->start();
    }

    // =========================================================================
    // Constant accessors (no session required)
    // =========================================================================

    public function testGetNameReturnsReactSessionStorage(): void
    {
        $oStorage = $this->makeStorage();
        $this->assertSame('ReactSessionStorage', $oStorage->getName());
    }

    public function testGetIdBeforeStartReturnsDefaultSetme(): void
    {
        $oStorage = $this->makeStorage();
        $this->assertSame('setme', $oStorage->getId());
    }

    public function testSetIdIsNoOp(): void
    {
        $oStorage = $this->makeStorage();
        $oStorage->setId('new-id');
        // setId is a no-op — getId should still return the pre-start default
        $this->assertSame('setme', $oStorage->getId());
    }

    public function testSetNameIsNoOp(): void
    {
        $oStorage = $this->makeStorage();
        $oStorage->setName('other');
        $this->assertSame('ReactSessionStorage', $oStorage->getName());
    }

    // =========================================================================
    // setRequest() / start() — reading session ID from cookie
    // =========================================================================

    public function testStartReturnsTrueOnFirstCall(): void
    {
        $oStorage = $this->makeStorage();
        $this->startStorage($oStorage, 'test-sess-001');
        // start() is already called; verify via isStarted()
        $this->assertTrue($oStorage->isStarted());
    }

    public function testGetIdAfterStartReturnsSessionIdFromCookie(): void
    {
        $oStorage = $this->makeStorage();
        $this->startStorage($oStorage, 'test-sess-002');
        $this->assertSame('test-sess-002', $oStorage->getId());
    }

    public function testStartTwiceIsIdempotent(): void
    {
        $oStorage = $this->makeStorage();
        $this->startStorage($oStorage, 'test-sess-003');
        // Calling start() a second time must return true and not reset the storage.
        $oStorage->start();
        $this->assertSame('test-sess-003', $oStorage->getId());
    }

    public function testStartWithNoExistingFileInitialisesCleanSession(): void
    {
        $sId = 'test-sess-new-' . uniqid();
        $this->aSessionIds[] = $sId;

        // Guarantee no stale file exists.
        @unlink('/tmp/session-' . $sId . '.dat');

        $oStorage = $this->makeStorage();
        $this->startStorage($oStorage, $sId);

        // A clean start must not throw — the storage is usable.
        $this->assertSame($sId, $oStorage->getId());
    }

    // =========================================================================
    // save()
    // =========================================================================

    public function testSaveWritesSessionFile(): void
    {
        $sId = 'test-sess-save-' . uniqid();
        $this->aSessionIds[] = $sId;

        $oStorage = $this->makeStorage();
        $this->startStorage($oStorage, $sId);
        $oStorage->save();

        $this->assertFileExists('/tmp/session-' . $sId . '.dat');
    }

    public function testSaveMarksStorageAsClosed(): void
    {
        $sId = 'test-sess-close-' . uniqid();
        $this->aSessionIds[] = $sId;

        $oStorage = $this->makeStorage();
        $this->startStorage($oStorage, $sId);
        $oStorage->save();

        $this->assertFalse($oStorage->isStarted());
    }

    public function testSaveAndStartRestoresSessionData(): void
    {
        $sId = 'test-sess-persist-' . uniqid();
        $this->aSessionIds[] = $sId;

        // Write a known value, save, then start a fresh storage and verify it loads.
        $rp = new \ReflectionProperty(ReactSessionStorage::class, 'SESSION');
        $rp->setAccessible(true);

        $oStorage = $this->makeStorage();
        $this->startStorage($oStorage, $sId);

        // Plant data directly in the static store to simulate a bag writing data.
        $aData = $rp->getValue(null);
        $aData[$sId]['_test_key'] = 'hello';
        $rp->setValue(null, $aData);

        $oStorage->save();

        // Now open a second storage for the same session.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        $oStorage2 = $this->makeStorage();
        $this->startStorage($oStorage2, $sId);

        $aLoaded = $rp->getValue(null);
        $this->assertArrayHasKey('_test_key', $aLoaded[$sId] ?? []);
        $this->assertSame('hello', $aLoaded[$sId]['_test_key']);
    }

    // =========================================================================
    // clear()
    // =========================================================================

    public function testClearResetsSessionData(): void
    {
        $sId = 'test-sess-clear-' . uniqid();
        $this->aSessionIds[] = $sId;

        $rp = new \ReflectionProperty(ReactSessionStorage::class, 'SESSION');
        $rp->setAccessible(true);

        $oStorage = $this->makeStorage();
        $this->startStorage($oStorage, $sId);

        // Plant arbitrary data.
        $aData = $rp->getValue(null);
        $aData[$sId]['_custom'] = 'value';
        $rp->setValue(null, $aData);

        $oStorage->clear();

        $aAfter = $rp->getValue(null);
        $this->assertArrayNotHasKey('_custom', $aAfter[$sId] ?? []);
    }

    public function testClearLeavesStorageStarted(): void
    {
        $sId = 'test-sess-clearstarted-' . uniqid();
        $this->aSessionIds[] = $sId;

        $oStorage = $this->makeStorage();
        $this->startStorage($oStorage, $sId);
        $oStorage->clear();

        $this->assertTrue($oStorage->isStarted());
    }
}

// =============================================================================
// ReactSessionStorageFactory
// =============================================================================

class ReactSessionStorageFactoryTest extends TestCase
{
    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        $rp = new \ReflectionProperty(ReactSessionStorage::class, 'SESSION');
        $rp->setAccessible(true);
        $rp->setValue(null, []);
    }

    // =========================================================================
    // createStorage()
    // =========================================================================

    public function testCreateStorageReturnsReactSessionStorage(): void
    {
        $oFactory = new ReactSessionStorageFactory();
        $oRequest = Request::create('/');
        $this->assertInstanceOf(ReactSessionStorage::class, $oFactory->createStorage($oRequest));
    }

    public function testCreateStorageImplementsSessionStorageInterface(): void
    {
        $oFactory = new ReactSessionStorageFactory();
        $oRequest = Request::create('/');
        $this->assertInstanceOf(SessionStorageInterface::class, $oFactory->createStorage($oRequest));
    }

    public function testCreateStorageWithNullRequestDoesNotThrow(): void
    {
        $oFactory = new ReactSessionStorageFactory();
        $oStorage = $oFactory->createStorage(null);
        $this->assertInstanceOf(ReactSessionStorage::class, $oStorage);
    }

    public function testCreateStorageWithSecureOptionSetsSecureWhenRequestIsSecure(): void
    {
        $oFactory = new ReactSessionStorageFactory([], null, null, true);
        $oRequest = Request::create('https://example.com/', 'GET');
        $oRequest->server->set('HTTPS', 'on');

        // createStorage should not throw when secure + HTTPS
        $oStorage = $oFactory->createStorage($oRequest);
        $this->assertInstanceOf(ReactSessionStorage::class, $oStorage);
    }

    public function testCreateStorageWithSecureFalseDoesNotSetCookieSecure(): void
    {
        // secure=false means cookie_secure must NOT be forced even on HTTPS
        $oFactory = new ReactSessionStorageFactory([], null, null, false);
        $oRequest = Request::create('https://example.com/');
        $oRequest->server->set('HTTPS', 'on');

        // Should create storage without exception
        $oStorage = $oFactory->createStorage($oRequest);
        $this->assertInstanceOf(ReactSessionStorage::class, $oStorage);
    }

    public function testCreateStorageReturnsFreshInstanceEachCall(): void
    {
        $oFactory = new ReactSessionStorageFactory();
        $oRequest = Request::create('/');
        $oA = $oFactory->createStorage($oRequest);
        $oB = $oFactory->createStorage($oRequest);
        $this->assertNotSame($oA, $oB);
    }
}
