<?php

/**
 * Spy/stub auth plugin used by SecurityTest to verify that Security::
 * delegates to the configured plugin correctly.
 *
 * Security::_getAuthPlugins() constructs "AuthPlugin" + ucfirst($sPlugin),
 * so this class must be named AuthPluginMock and registered under the
 * Authentication\Plugins namespace.  Tests override the config value
 * 'security_auth_plugins' to 'mock' to activate it.
 */

namespace HomeLan\FileStore\Authentication\Plugins;

use HomeLan\FileStore\Authentication\User;

class AuthPluginMock implements AuthPluginInterface
{
    /** Users returned by getAllUsers(). */
    public static array $aUsersToReturn = [];

    /** Whether removeUser() should throw instead of returning a value. */
    public static bool $bRemoveUserThrow = false;

    /** Value returned by removeUser() when it does not throw. */
    public static bool $bRemoveUserResult = true;

    /**
     * Ordered log of calls made to methods that Security delegates to.
     * Each entry: ['method' => string, ...args]
     */
    public static array $aCallLog = [];

    /** Reset all spy state between tests. */
    public static function reset(): void
    {
        self::$aUsersToReturn = [];
        self::$bRemoveUserThrow = false;
        self::$bRemoveUserResult = true;
        self::$aCallLog = [];
    }

    // -------------------------------------------------------------------------
    // AuthPluginInterface
    // -------------------------------------------------------------------------

    public static function init(\Psr\Log\LoggerInterface $oLogger, $sUsers = null): void {}

    public static function login(string $sUsername, string $sPassword, ?int $iNetwork = null, ?int $iStation = null): bool
    {
        return false;
    }

    public static function buildUserObject(string $sUsername): User
    {
        $oUser = new User();
        $oUser->setUsername(strtoupper($sUsername));
        return $oUser;
    }

    public static function getAllUsers(): array
    {
        self::$aCallLog[] = ['method' => 'getAllUsers'];
        return self::$aUsersToReturn;
    }

    public static function setPassword(string $sUsername, string $sOldPassword, string $sPassword): void
    {
        self::$aCallLog[] = ['method' => 'setPassword', 'username' => $sUsername];
    }

    public static function createUser(User $oUser): void
    {
        self::$aCallLog[] = ['method' => 'createUser', 'username' => $oUser->getUsername()];
    }

    public static function removeUser(string $sUsername): bool
    {
        self::$aCallLog[] = ['method' => 'removeUser', 'username' => $sUsername];
        if (self::$bRemoveUserThrow) {
            throw new \Exception('User does not exist');
        }
        return self::$bRemoveUserResult;
    }

    public static function setPriv(string $sUsername, string $sPriv): void
    {
        self::$aCallLog[] = ['method' => 'setPriv', 'username' => $sUsername, 'priv' => $sPriv];
    }

    public static function setOpt(string $sUsername, string $sOpt): void
    {
        self::$aCallLog[] = ['method' => 'setOpt', 'username' => $sUsername, 'opt' => $sOpt];
    }
}
