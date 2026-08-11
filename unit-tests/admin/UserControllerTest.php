<?php

/*
 * Tests for HomeLan\FileStore\Admin\Controller\UserController.
 *
 * A TestableUserController subclass overrides all protected security wrappers
 * and renderTemplate() so that:
 *   - No real Security / auth-plugin state is needed
 *   - The Smarty template system is never invoked
 *   - Call arguments are captured for assertion
 *
 * All HTTP action methods are exercised (GET + POST paths).
 */

include_once(__DIR__ . '/../../src/include/system.inc.php');

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use HomeLan\FileStore\Admin\Controller\UserController;
use HomeLan\FileStore\Admin\Service\Smarty;
use HomeLan\FileStore\Authentication\User;

// ---------------------------------------------------------------------------
// TestableUserController — isolates the controller from Security and Smarty
// ---------------------------------------------------------------------------
class TestableUserController extends UserController
{
    public array  $stubUsers       = [];
    public ?User  $stubUserByName  = null;
    public ?string $lastTemplate   = null;
    public array  $lastVars        = [];

    // Capture arrays for each mutation call
    public array  $capCreated      = [];
    public array  $capRemoved      = [];
    public array  $capSetPriv      = [];
    public array  $capSetOpt       = [];
    public array  $capSetQuota     = [];
    public array  $capSetPassword  = [];

    // Configurable exceptions to simulate backend errors
    public ?\Exception $throwOnCreate   = null;
    public ?\Exception $throwOnRemove   = null;
    public ?\Exception $throwOnSetPriv  = null;
    public ?\Exception $throwOnSetPassword = null;

    protected function secGetAllUsers(): array
    {
        return $this->stubUsers;
    }

    protected function secGetUserByName(string $sUsername): ?User
    {
        return $this->stubUserByName;
    }

    protected function secAdminCreateUser(User $oUser): void
    {
        if ($this->throwOnCreate) {
            throw $this->throwOnCreate;
        }
        $this->capCreated[] = $oUser;
    }

    protected function secAdminRemoveUser(string $sUsername): bool
    {
        if ($this->throwOnRemove) {
            throw $this->throwOnRemove;
        }
        $this->capRemoved[] = $sUsername;
        return true;
    }

    protected function secAdminSetPriv(string $sUsername, string $sPriv): void
    {
        if ($this->throwOnSetPriv) {
            throw $this->throwOnSetPriv;
        }
        $this->capSetPriv[] = ['username' => $sUsername, 'priv' => $sPriv];
    }

    protected function secAdminSetOpt(string $sUsername, string $sOpt): void
    {
        $this->capSetOpt[] = ['username' => $sUsername, 'opt' => $sOpt];
    }

    protected function secAdminSetQuota(string $sUsername, int $iQuota): void
    {
        $this->capSetQuota[] = ['username' => $sUsername, 'quota' => $iQuota];
    }

    protected function secAdminSetPassword(string $sUsername, string $sPassword): void
    {
        if ($this->throwOnSetPassword) {
            throw $this->throwOnSetPassword;
        }
        $this->capSetPassword[] = ['username' => $sUsername, 'password' => $sPassword];
    }

    protected function renderTemplate(Smarty $oSmartyService, string $sTemplate, array $aVars): Response
    {
        $this->lastTemplate = $sTemplate;
        $this->lastVars     = $aVars;
        return new Response('RENDERED:' . $sTemplate);
    }
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function makeUser(string $sUsername, string $sPriv = 'U', string $sHomedir = '$.HOME', int $iBootOpt = 0, int $iQuota = 0): User
{
    $oUser = new User();
    $oUser->setUsername($sUsername);
    $oUser->setPriv($sPriv);
    $oUser->setHomedir($sHomedir);
    $oUser->setBootOpt($iBootOpt);
    $oUser->setQuota($iQuota);
    $oUser->setUnixUid(5000);
    return $oUser;
}

function makeSmartyStub(): Smarty
{
    return new class extends Smarty {
        public function getSmarty(): \Smarty\Smarty
        {
            // Return a real Smarty instance but it will never be called because
            // TestableUserController overrides renderTemplate() before getSmarty() runs.
            return parent::getSmarty();
        }
    };
}

// ---------------------------------------------------------------------------
// Test class
// ---------------------------------------------------------------------------
class UserControllerTest extends TestCase
{
    private TestableUserController $oController;
    private Smarty                 $oSmarty;

    protected function setUp(): void
    {
        $this->oController = new TestableUserController();
        $this->oSmarty     = makeSmartyStub();
    }

    // =========================================================================
    // index()
    // =========================================================================

    public function testIndexRendersUsersList(): void
    {
        $oUser = makeUser('ALICE');
        $this->oController->stubUsers = [['plugin' => 'File', 'user' => $oUser]];

        $oRequest  = Request::create('/users', 'GET');
        $oResponse = $this->oController->index($this->oSmarty, $oRequest);

        $this->assertSame('users.tpl', $this->oController->lastTemplate);
    }

    public function testIndexPassesUserListToTemplate(): void
    {
        $oUser = makeUser('BOB');
        $this->oController->stubUsers = [['plugin' => 'File', 'user' => $oUser]];

        $oRequest = Request::create('/users', 'GET');
        $this->oController->index($this->oSmarty, $oRequest);

        $this->assertSame($this->oController->stubUsers, $this->oController->lastVars['aUsers']);
    }

    public function testIndexPassesEmptyMessageByDefault(): void
    {
        $oRequest = Request::create('/users', 'GET');
        $this->oController->index($this->oSmarty, $oRequest);

        $this->assertSame('', $this->oController->lastVars['sMessage']);
    }

    public function testIndexPassesQueryMessageToTemplate(): void
    {
        $oRequest = Request::create('/users?msg=created', 'GET');
        $this->oController->index($this->oSmarty, $oRequest);

        $this->assertSame('created', $this->oController->lastVars['sMessage']);
    }

    public function testIndexReturns200(): void
    {
        $oRequest  = Request::create('/users', 'GET');
        $oResponse = $this->oController->index($this->oSmarty, $oRequest);

        $this->assertSame(200, $oResponse->getStatusCode());
    }

    // =========================================================================
    // create() — GET
    // =========================================================================

    public function testCreateGetRendersForm(): void
    {
        $oRequest = Request::create('/users/create', 'GET');
        $this->oController->create($this->oSmarty, $oRequest);

        $this->assertSame('users-form.tpl', $this->oController->lastTemplate);
    }

    public function testCreateGetPassesCreateAction(): void
    {
        $oRequest = Request::create('/users/create', 'GET');
        $this->oController->create($this->oSmarty, $oRequest);

        $this->assertSame('create', $this->oController->lastVars['sAction']);
    }

    public function testCreateGetHasNoError(): void
    {
        $oRequest = Request::create('/users/create', 'GET');
        $this->oController->create($this->oSmarty, $oRequest);

        $this->assertNull($this->oController->lastVars['sError']);
    }

    // =========================================================================
    // create() — POST success
    // =========================================================================

    public function testCreatePostCallsCreateWithCorrectUsername(): void
    {
        $oRequest = Request::create('/users/create', 'POST', [
            'username' => 'newuser',
            'homedir'  => '$.HOME.NEWUSER',
            'unixuid'  => '5002',
            'bootopt'  => '0',
            'priv'     => 'U',
            'quota'    => '0',
        ]);
        $this->oController->create($this->oSmarty, $oRequest);

        $this->assertCount(1, $this->oController->capCreated);
        $this->assertSame('NEWUSER', $this->oController->capCreated[0]->getUsername());
    }

    public function testCreatePostUsernameIsUppercased(): void
    {
        $oRequest = Request::create('/users/create', 'POST', [
            'username' => 'alice',
            'homedir'  => '$.HOME.ALICE',
            'unixuid'  => '5001',
            'bootopt'  => '0',
            'priv'     => 'U',
            'quota'    => '0',
        ]);
        $this->oController->create($this->oSmarty, $oRequest);

        $this->assertSame('ALICE', $this->oController->capCreated[0]->getUsername());
    }

    public function testCreatePostSetsPrivilegeCorrectly(): void
    {
        $oRequest = Request::create('/users/create', 'POST', [
            'username' => 'SYSOP',
            'homedir'  => '$.HOME.SYSOP',
            'unixuid'  => '5003',
            'bootopt'  => '0',
            'priv'     => 'S',
            'quota'    => '0',
        ]);
        $this->oController->create($this->oSmarty, $oRequest);

        $this->assertSame('S', $this->oController->capCreated[0]->getPriv());
    }

    public function testCreatePostSetsQuota(): void
    {
        $oRequest = Request::create('/users/create', 'POST', [
            'username' => 'QUOTAUSER',
            'homedir'  => '$.HOME.QUOTAUSER',
            'unixuid'  => '5004',
            'bootopt'  => '0',
            'priv'     => 'U',
            'quota'    => '4096',
        ]);
        $this->oController->create($this->oSmarty, $oRequest);

        $this->assertSame(4096, $this->oController->capCreated[0]->getQuota());
    }

    public function testCreatePostRedirectsOnSuccess(): void
    {
        $oRequest  = Request::create('/users/create', 'POST', [
            'username' => 'NEWUSER',
            'homedir'  => '$.HOME.NEWUSER',
            'unixuid'  => '5002',
            'bootopt'  => '0',
            'priv'     => 'U',
            'quota'    => '0',
        ]);
        $oResponse = $this->oController->create($this->oSmarty, $oRequest);

        $this->assertInstanceOf(RedirectResponse::class, $oResponse);
        $this->assertStringContainsString('/users', $oResponse->getTargetUrl());
        $this->assertStringContainsString('msg=created', $oResponse->getTargetUrl());
    }

    // =========================================================================
    // create() — POST validation errors
    // =========================================================================

    public function testCreatePostRejectsEmptyUsername(): void
    {
        $oRequest = Request::create('/users/create', 'POST', [
            'username' => '',
            'homedir'  => '$.HOME.X',
            'unixuid'  => '5000',
            'bootopt'  => '0',
            'priv'     => 'U',
            'quota'    => '0',
        ]);
        $this->oController->create($this->oSmarty, $oRequest);

        $this->assertEmpty($this->oController->capCreated);
        $this->assertNotNull($this->oController->lastVars['sError']);
    }

    public function testCreatePostRejectsUsernameWithInvalidChars(): void
    {
        $oRequest = Request::create('/users/create', 'POST', [
            'username' => 'bad user!',
            'homedir'  => '$.HOME.X',
            'unixuid'  => '5000',
            'bootopt'  => '0',
            'priv'     => 'U',
            'quota'    => '0',
        ]);
        $this->oController->create($this->oSmarty, $oRequest);

        $this->assertEmpty($this->oController->capCreated);
        $this->assertNotNull($this->oController->lastVars['sError']);
    }

    public function testCreatePostRejectsEmptyHomedir(): void
    {
        $oRequest = Request::create('/users/create', 'POST', [
            'username' => 'VALIDNAME',
            'homedir'  => '',
            'unixuid'  => '5000',
            'bootopt'  => '0',
            'priv'     => 'U',
            'quota'    => '0',
        ]);
        $this->oController->create($this->oSmarty, $oRequest);

        $this->assertEmpty($this->oController->capCreated);
        $this->assertNotNull($this->oController->lastVars['sError']);
    }

    public function testCreatePostRejectsInvalidPriv(): void
    {
        $oRequest = Request::create('/users/create', 'POST', [
            'username' => 'VALIDNAME',
            'homedir'  => '$.HOME.VALIDNAME',
            'unixuid'  => '5000',
            'bootopt'  => '0',
            'priv'     => 'X',
            'quota'    => '0',
        ]);
        $this->oController->create($this->oSmarty, $oRequest);

        $this->assertEmpty($this->oController->capCreated);
        $this->assertNotNull($this->oController->lastVars['sError']);
    }

    public function testCreatePostShowsErrorWhenPluginThrows(): void
    {
        $this->oController->throwOnCreate = new \Exception('User already exists');
        $oRequest = Request::create('/users/create', 'POST', [
            'username' => 'DUPLICATE',
            'homedir'  => '$.HOME.DUPLICATE',
            'unixuid'  => '5000',
            'bootopt'  => '0',
            'priv'     => 'U',
            'quota'    => '0',
        ]);
        $oResponse = $this->oController->create($this->oSmarty, $oRequest);

        $this->assertNotInstanceOf(RedirectResponse::class, $oResponse);
        $this->assertStringContainsString('User already exists', $this->oController->lastVars['sError']);
    }

    // =========================================================================
    // edit() — GET
    // =========================================================================

    public function testEditGetRedirectsWhenUserNotFound(): void
    {
        $this->oController->stubUserByName = null;
        $oRequest  = Request::create('/users/edit?username=NOBODY', 'GET');
        $oResponse = $this->oController->edit($this->oSmarty, $oRequest);

        $this->assertInstanceOf(RedirectResponse::class, $oResponse);
        $this->assertStringContainsString('notfound', $oResponse->getTargetUrl());
    }

    public function testEditGetRendersFormWhenUserFound(): void
    {
        $this->oController->stubUserByName = makeUser('ALICE', 'U', '$.HOME.ALICE');
        $oRequest = Request::create('/users/edit?username=ALICE', 'GET');
        $this->oController->edit($this->oSmarty, $oRequest);

        $this->assertSame('users-form.tpl', $this->oController->lastTemplate);
        $this->assertSame('edit', $this->oController->lastVars['sAction']);
    }

    public function testEditGetPassesUserObjectToTemplate(): void
    {
        $oUser = makeUser('ALICE');
        $this->oController->stubUserByName = $oUser;
        $oRequest = Request::create('/users/edit?username=ALICE', 'GET');
        $this->oController->edit($this->oSmarty, $oRequest);

        $this->assertSame($oUser, $this->oController->lastVars['oUser']);
    }

    // =========================================================================
    // edit() — POST success
    // =========================================================================

    public function testEditPostCallsSetPriv(): void
    {
        $this->oController->stubUserByName = makeUser('ALICE');
        $oRequest = Request::create('/users/edit?username=ALICE', 'POST', [
            'priv'    => 'S',
            'bootopt' => '2',
            'quota'   => '0',
        ]);
        $this->oController->edit($this->oSmarty, $oRequest);

        $this->assertCount(1, $this->oController->capSetPriv);
        $this->assertSame('ALICE', $this->oController->capSetPriv[0]['username']);
        $this->assertSame('S', $this->oController->capSetPriv[0]['priv']);
    }

    public function testEditPostCallsSetOpt(): void
    {
        $this->oController->stubUserByName = makeUser('ALICE');
        $oRequest = Request::create('/users/edit?username=ALICE', 'POST', [
            'priv'    => 'U',
            'bootopt' => '3',
            'quota'   => '0',
        ]);
        $this->oController->edit($this->oSmarty, $oRequest);

        $this->assertCount(1, $this->oController->capSetOpt);
        $this->assertSame('ALICE', $this->oController->capSetOpt[0]['username']);
        $this->assertSame('3', $this->oController->capSetOpt[0]['opt']);
    }

    public function testEditPostCallsSetQuota(): void
    {
        $this->oController->stubUserByName = makeUser('ALICE');
        $oRequest = Request::create('/users/edit?username=ALICE', 'POST', [
            'priv'    => 'U',
            'bootopt' => '0',
            'quota'   => '8192',
        ]);
        $this->oController->edit($this->oSmarty, $oRequest);

        $this->assertCount(1, $this->oController->capSetQuota);
        $this->assertSame(8192, $this->oController->capSetQuota[0]['quota']);
    }

    public function testEditPostRedirectsOnSuccess(): void
    {
        $this->oController->stubUserByName = makeUser('ALICE');
        $oRequest  = Request::create('/users/edit?username=ALICE', 'POST', [
            'priv'    => 'U',
            'bootopt' => '0',
            'quota'   => '0',
        ]);
        $oResponse = $this->oController->edit($this->oSmarty, $oRequest);

        $this->assertInstanceOf(RedirectResponse::class, $oResponse);
        $this->assertStringContainsString('msg=updated', $oResponse->getTargetUrl());
    }

    public function testEditPostRejectsInvalidPriv(): void
    {
        $this->oController->stubUserByName = makeUser('ALICE');
        $oRequest = Request::create('/users/edit?username=ALICE', 'POST', [
            'priv'    => 'X',
            'bootopt' => '0',
            'quota'   => '0',
        ]);
        $this->oController->edit($this->oSmarty, $oRequest);

        $this->assertEmpty($this->oController->capSetPriv);
        $this->assertNotNull($this->oController->lastVars['sError']);
    }

    public function testEditPostRejectsOutOfRangeBootOpt(): void
    {
        $this->oController->stubUserByName = makeUser('ALICE');
        $oRequest = Request::create('/users/edit?username=ALICE', 'POST', [
            'priv'    => 'U',
            'bootopt' => '9',
            'quota'   => '0',
        ]);
        $this->oController->edit($this->oSmarty, $oRequest);

        $this->assertEmpty($this->oController->capSetOpt);
        $this->assertNotNull($this->oController->lastVars['sError']);
    }

    public function testEditPostShowsErrorWhenPluginThrows(): void
    {
        $this->oController->stubUserByName = makeUser('ALICE');
        $this->oController->throwOnSetPriv = new \Exception('Backend write failed');
        $oRequest  = Request::create('/users/edit?username=ALICE', 'POST', [
            'priv'    => 'S',
            'bootopt' => '0',
            'quota'   => '0',
        ]);
        $oResponse = $this->oController->edit($this->oSmarty, $oRequest);

        $this->assertNotInstanceOf(RedirectResponse::class, $oResponse);
        $this->assertStringContainsString('Backend write failed', $this->oController->lastVars['sError']);
    }

    // =========================================================================
    // delete() — GET
    // =========================================================================

    public function testDeleteGetRedirectsWhenUserNotFound(): void
    {
        $this->oController->stubUserByName = null;
        $oRequest  = Request::create('/users/delete?username=GHOST', 'GET');
        $oResponse = $this->oController->delete($this->oSmarty, $oRequest);

        $this->assertInstanceOf(RedirectResponse::class, $oResponse);
        $this->assertStringContainsString('notfound', $oResponse->getTargetUrl());
    }

    public function testDeleteGetRendersConfirmation(): void
    {
        $this->oController->stubUserByName = makeUser('BOB');
        $oRequest = Request::create('/users/delete?username=BOB', 'GET');
        $this->oController->delete($this->oSmarty, $oRequest);

        $this->assertSame('users-delete.tpl', $this->oController->lastTemplate);
    }

    public function testDeleteGetPassesUserToTemplate(): void
    {
        $oUser = makeUser('BOB');
        $this->oController->stubUserByName = $oUser;
        $oRequest = Request::create('/users/delete?username=BOB', 'GET');
        $this->oController->delete($this->oSmarty, $oRequest);

        $this->assertSame($oUser, $this->oController->lastVars['oUser']);
    }

    public function testDeleteGetHasNoError(): void
    {
        $this->oController->stubUserByName = makeUser('BOB');
        $oRequest = Request::create('/users/delete?username=BOB', 'GET');
        $this->oController->delete($this->oSmarty, $oRequest);

        $this->assertNull($this->oController->lastVars['sError']);
    }

    // =========================================================================
    // delete() — POST
    // =========================================================================

    public function testDeletePostCallsRemoveUser(): void
    {
        $this->oController->stubUserByName = makeUser('BOB');
        $oRequest = Request::create('/users/delete?username=BOB', 'POST');
        $this->oController->delete($this->oSmarty, $oRequest);

        $this->assertCount(1, $this->oController->capRemoved);
        $this->assertSame('BOB', $this->oController->capRemoved[0]);
    }

    public function testDeletePostRedirectsOnSuccess(): void
    {
        $this->oController->stubUserByName = makeUser('BOB');
        $oRequest  = Request::create('/users/delete?username=BOB', 'POST');
        $oResponse = $this->oController->delete($this->oSmarty, $oRequest);

        $this->assertInstanceOf(RedirectResponse::class, $oResponse);
        $this->assertStringContainsString('msg=deleted', $oResponse->getTargetUrl());
    }

    public function testDeletePostShowsErrorWhenPluginThrows(): void
    {
        $this->oController->stubUserByName    = makeUser('BOB');
        $this->oController->throwOnRemove     = new \Exception('Cannot remove last admin');
        $oRequest  = Request::create('/users/delete?username=BOB', 'POST');
        $oResponse = $this->oController->delete($this->oSmarty, $oRequest);

        $this->assertNotInstanceOf(RedirectResponse::class, $oResponse);
        $this->assertStringContainsString('Cannot remove last admin', $this->oController->lastVars['sError']);
    }

    // =========================================================================
    // setPassword() — GET
    // =========================================================================

    public function testSetPasswordGetRedirectsWhenUserNotFound(): void
    {
        $this->oController->stubUserByName = null;
        $oRequest  = Request::create('/users/setpassword?username=GHOST', 'GET');
        $oResponse = $this->oController->setPassword($this->oSmarty, $oRequest);

        $this->assertInstanceOf(RedirectResponse::class, $oResponse);
        $this->assertStringContainsString('notfound', $oResponse->getTargetUrl());
    }

    public function testSetPasswordGetRendersForm(): void
    {
        $this->oController->stubUserByName = makeUser('CAROL');
        $oRequest = Request::create('/users/setpassword?username=CAROL', 'GET');
        $this->oController->setPassword($this->oSmarty, $oRequest);

        $this->assertSame('users-setpassword.tpl', $this->oController->lastTemplate);
    }

    public function testSetPasswordGetHasNoError(): void
    {
        $this->oController->stubUserByName = makeUser('CAROL');
        $oRequest = Request::create('/users/setpassword?username=CAROL', 'GET');
        $this->oController->setPassword($this->oSmarty, $oRequest);

        $this->assertNull($this->oController->lastVars['sError']);
    }

    // =========================================================================
    // setPassword() — POST
    // =========================================================================

    public function testSetPasswordPostCallsAdminSetPasswordWithCorrectArgs(): void
    {
        $this->oController->stubUserByName = makeUser('CAROL');
        $oRequest = Request::create('/users/setpassword?username=CAROL', 'POST', [
            'password' => 's3cr3t',
        ]);
        $this->oController->setPassword($this->oSmarty, $oRequest);

        $this->assertCount(1, $this->oController->capSetPassword);
        $this->assertSame('CAROL',  $this->oController->capSetPassword[0]['username']);
        $this->assertSame('s3cr3t', $this->oController->capSetPassword[0]['password']);
    }

    public function testSetPasswordPostAllowsEmptyPassword(): void
    {
        $this->oController->stubUserByName = makeUser('CAROL');
        $oRequest = Request::create('/users/setpassword?username=CAROL', 'POST', [
            'password' => '',
        ]);
        $this->oController->setPassword($this->oSmarty, $oRequest);

        $this->assertCount(1, $this->oController->capSetPassword);
        $this->assertSame('', $this->oController->capSetPassword[0]['password']);
    }

    public function testSetPasswordPostRedirectsOnSuccess(): void
    {
        $this->oController->stubUserByName = makeUser('CAROL');
        $oRequest  = Request::create('/users/setpassword?username=CAROL', 'POST', [
            'password' => 'newpass',
        ]);
        $oResponse = $this->oController->setPassword($this->oSmarty, $oRequest);

        $this->assertInstanceOf(RedirectResponse::class, $oResponse);
        $this->assertStringContainsString('msg=password_changed', $oResponse->getTargetUrl());
    }

    public function testSetPasswordPostShowsErrorWhenPluginThrows(): void
    {
        $this->oController->stubUserByName       = makeUser('CAROL');
        $this->oController->throwOnSetPassword   = new \Exception('User does not exist');
        $oRequest  = Request::create('/users/setpassword?username=CAROL', 'POST', [
            'password' => 'newpass',
        ]);
        $oResponse = $this->oController->setPassword($this->oSmarty, $oRequest);

        $this->assertNotInstanceOf(RedirectResponse::class, $oResponse);
        $this->assertStringContainsString('User does not exist', $this->oController->lastVars['sError']);
    }
}
