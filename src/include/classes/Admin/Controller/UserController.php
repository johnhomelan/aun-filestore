<?php
namespace HomeLan\FileStore\Admin\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use HomeLan\FileStore\Admin\Service\Smarty;
use HomeLan\FileStore\Authentication\Security;
use HomeLan\FileStore\Authentication\User;

class UserController extends AbstractController
{
    public function index(Smarty $oSmartyService, Request $oRequest): Response
    {
        $aUsers = $this->secGetAllUsers();
        return $this->renderTemplate($oSmartyService, 'users.tpl', [
            'aUsers'    => $aUsers,
            'sMessage'  => (string) $oRequest->query->get('msg', ''),
        ]);
    }

    public function create(Smarty $oSmartyService, Request $oRequest): Response
    {
        $sError = null;
        $aPost  = [];

        if ($oRequest->getMethod() === 'POST') {
            $aPost = $oRequest->request->all();
            try {
                $oUser = $this->_buildUserFromRequest($oRequest);
                $this->secAdminCreateUser($oUser);
                return new RedirectResponse('/users?msg=created');
            } catch (\Exception $e) {
                $sError = $e->getMessage();
            }
        }

        return $this->renderTemplate($oSmartyService, 'users-form.tpl', [
            'sAction'    => 'create',
            'sActionUrl' => '/users/create',
            'sError'     => $sError,
            'aPost'      => $aPost,
            'oUser'      => null,
        ]);
    }

    public function edit(Smarty $oSmartyService, Request $oRequest): Response
    {
        $sUsername = trim((string) $oRequest->query->get('username', ''));
        $oUser     = $this->secGetUserByName($sUsername);

        if ($oUser === null) {
            return new RedirectResponse('/users?msg=notfound');
        }

        $sError = null;

        if ($oRequest->getMethod() === 'POST') {
            try {
                $sPriv    = (string) $oRequest->request->get('priv', 'U');
                $iBootOpt = (int)    $oRequest->request->get('bootopt', 0);
                $iQuota   = (int)    $oRequest->request->get('quota', 0);

                if (!in_array($sPriv, ['S', 'U'], true)) {
                    throw new \InvalidArgumentException('Invalid privilege value — must be S or U.');
                }
                if ($iBootOpt < 0 || $iBootOpt > 3) {
                    throw new \InvalidArgumentException('Boot option must be 0–3.');
                }

                $this->secAdminSetPriv($sUsername, $sPriv);
                $this->secAdminSetOpt($sUsername, (string) $iBootOpt);
                $this->secAdminSetQuota($sUsername, $iQuota);

                return new RedirectResponse('/users?msg=updated');
            } catch (\Exception $e) {
                $sError = $e->getMessage();
            }
        }

        return $this->renderTemplate($oSmartyService, 'users-form.tpl', [
            'sAction'    => 'edit',
            'sActionUrl' => '/users/edit?username=' . urlencode($sUsername),
            'sError'     => $sError,
            'aPost'      => [],
            'oUser'      => $oUser,
        ]);
    }

    public function delete(Smarty $oSmartyService, Request $oRequest): Response
    {
        $sUsername = trim((string) $oRequest->query->get('username', ''));
        $oUser     = $this->secGetUserByName($sUsername);

        if ($oUser === null) {
            return new RedirectResponse('/users?msg=notfound');
        }

        $sError = null;

        if ($oRequest->getMethod() === 'POST') {
            try {
                $this->secAdminRemoveUser($sUsername);
                return new RedirectResponse('/users?msg=deleted');
            } catch (\Exception $e) {
                $sError = $e->getMessage();
            }
        }

        return $this->renderTemplate($oSmartyService, 'users-delete.tpl', [
            'oUser'  => $oUser,
            'sError' => $sError,
        ]);
    }

    public function setPassword(Smarty $oSmartyService, Request $oRequest): Response
    {
        $sUsername = trim((string) $oRequest->query->get('username', ''));
        $oUser     = $this->secGetUserByName($sUsername);

        if ($oUser === null) {
            return new RedirectResponse('/users?msg=notfound');
        }

        $sError = null;

        if ($oRequest->getMethod() === 'POST') {
            try {
                $sPassword = (string) $oRequest->request->get('password', '');
                $this->secAdminSetPassword($sUsername, $sPassword);
                return new RedirectResponse('/users?msg=password_changed');
            } catch (\Exception $e) {
                $sError = $e->getMessage();
            }
        }

        return $this->renderTemplate($oSmartyService, 'users-setpassword.tpl', [
            'oUser'  => $oUser,
            'sError' => $sError,
        ]);
    }

    // -------------------------------------------------------------------------
    // Protected security wrappers — overridden by TestableUserController
    // -------------------------------------------------------------------------

    protected function secGetAllUsers(): array
    {
        return Security::getAllUsers();
    }

    protected function secGetUserByName(string $sUsername): ?User
    {
        return Security::getUserByName($sUsername);
    }

    protected function secAdminCreateUser(User $oUser): void
    {
        Security::adminCreateUser($oUser);
    }

    protected function secAdminRemoveUser(string $sUsername): bool
    {
        return Security::adminRemoveUser($sUsername);
    }

    protected function secAdminSetPriv(string $sUsername, string $sPriv): void
    {
        Security::adminSetPriv($sUsername, $sPriv);
    }

    protected function secAdminSetOpt(string $sUsername, string $sOpt): void
    {
        Security::adminSetOpt($sUsername, $sOpt);
    }

    protected function secAdminSetQuota(string $sUsername, int $iQuota): void
    {
        Security::adminSetQuota($sUsername, $iQuota);
    }

    protected function secAdminSetPassword(string $sUsername, string $sPassword): void
    {
        Security::adminSetPassword($sUsername, $sPassword);
    }

    protected function renderTemplate(Smarty $oSmartyService, string $sTemplate, array $aVars): Response
    {
        $oSmarty = $oSmartyService->getSmarty();
        foreach ($aVars as $sKey => $oValue) {
            $oSmarty->assign($sKey, $oValue);
        }
        return new Response($oSmarty->fetch($sTemplate));
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function _buildUserFromRequest(Request $oRequest): User
    {
        $sUsername = strtoupper(trim((string) $oRequest->request->get('username', '')));
        $sHomedir  = trim((string) $oRequest->request->get('homedir', ''));
        $iUnixUid  = (int) $oRequest->request->get('unixuid', 5000);
        $iBootOpt  = (int) $oRequest->request->get('bootopt', 0);
        $sPriv     = (string) $oRequest->request->get('priv', 'U');
        $iQuota    = (int) $oRequest->request->get('quota', 0);

        if ($sUsername === '') {
            throw new \InvalidArgumentException('Username is required.');
        }
        if (!preg_match('/^[A-Z0-9]+$/', $sUsername)) {
            throw new \InvalidArgumentException('Username must contain only letters and numbers.');
        }
        if ($sHomedir === '') {
            throw new \InvalidArgumentException('Home directory is required.');
        }
        if (!in_array($sPriv, ['S', 'U'], true)) {
            throw new \InvalidArgumentException('Invalid privilege value — must be S or U.');
        }
        if ($iBootOpt < 0 || $iBootOpt > 3) {
            throw new \InvalidArgumentException('Boot option must be 0–3.');
        }

        $oUser = new User();
        $oUser->setUsername($sUsername);
        $oUser->setHomedir($sHomedir);
        $oUser->setUnixUid($iUnixUid);
        $oUser->setBootOpt($iBootOpt);
        $oUser->setPriv($sPriv);
        $oUser->setQuota($iQuota);
        return $oUser;
    }
}
