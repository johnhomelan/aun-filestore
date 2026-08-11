# Authentication System — Developer Guide

This document describes how the authentication layer works and how to add a
new authentication plugin.

---

## Overview

Authentication is handled by the static `Security` class, which acts as a
facade over one or more **auth plugins**. Plugins are listed in the
`security_auth_plugins` config key (comma-separated). When the server needs
to authenticate a user it tries each plugin in order; the first plugin to
return a successful `login()` result wins and owns the session.

```
Client LOGIN request
        │
        ▼
Security::login(net, stn, username, password)
        │
        ├─── Plugin[0]::login(user, pass, net, stn)  → true  ─▶ session created
        │                                              false  ─┐
        │                                                      │
        ├─── Plugin[1]::login(user, pass, net, stn)  → true  ─▶ session created
        │                                              false  ─┐
        │                                                      │
        └─── (all failed) ─────────────────────────────────▶ return false
```

Once a login succeeds the `Security` class records which plugin authenticated
the user. All subsequent session-scoped write operations (change password,
set privilege, set boot option) use **that same plugin** for the current
session. Read-only queries (`getAllUsers()`) aggregate results from all plugins.

---

## Session management

Sessions are stored in `Security::$aSessions[network][station]`:

```php
$aSessions[1][34] = [
    'idle'     => int,      // Unix timestamp of last activity
    'datetime' => int,      // Unix timestamp of login
    'provider' => string,   // Fully-qualified class name of the auth plugin
    'user'     => User,     // User object for the logged-in user
];
```

The session idle timer is updated on every packet received from the station
(`Security::updateIdleTimer()`). The housekeeping task (called on the regular
server timer) logs out sessions that have been idle longer than
`security_max_session_idle` seconds.

Key static methods used throughout the server:

| Method | Description |
|---|---|
| `Security::isLoggedIn(net, stn)` | True if a session exists for this station |
| `Security::getUser(net, stn)` | Return the `User` object for the session, or `null` |
| `Security::getUserByName(username)` | Return a `User` from any plugin by name (not session-scoped), or `null` |
| `Security::login(net, stn, user, pass)` | Attempt login; return true on success |
| `Security::logout(net, stn)` | Remove the session |
| `Security::getUsersOnline()` | Return all active sessions (for the admin UI) |
| `Security::getAllUsers()` | Aggregate all users from all plugins |
| `Security::createUser(net, stn, User)` | Create a new user (admin only) |
| `Security::removeUser(net, stn, username)` | Delete a user (admin only) |
| `Security::setPriv(net, stn, username, 'S'\|'U')` | Set administrator privilege (admin only) |
| `Security::setOpt(net, stn, optString)` | Set the current user's boot option |
| `Security::setConnectedUsersPassword(net, stn, old, new)` | Change the current user's own password (old password required) |
| `Security::setAdminPassword(net, stn, username, new)` | Reset any user's password without the old password (admin only) |
| `Security::setUserQuota(net, stn, username, quota)` | Set per-user disc quota in bytes; 0 = use global default (admin only) |

---

## The `User` object

**File:** `src/include/classes/Authentication/User.php`
**Namespace:** `HomeLan\FileStore\Authentication`

`User` is a plain data object. Its fields map to the Acorn file server user
model.

| Field / Method | Type | Description |
|---|---|---|
| `getUsername()` / `setUsername()` | string | Econet username (stored upper-case) |
| `getUnixUid()` / `setUnixUid()` | int | Unix UID used when switching file ownership |
| `getHomedir()` / `setHomedir()` | string | Econet path to the user's home directory (e.g. `$.HOME.JOHN`) |
| `getRoot()` / `setRoot()` | string | Chroot prefix (default `$`; restricts the user's view of the filesystem) |
| `getCsd()` / `setCsd()` | string | Current Selected Directory (defaults to home dir on login) |
| `getLib()` / `setLib()` | string | Library directory (defaults to `library_path` config key) |
| `getBootOpt()` / `setBootOpt()` | int | Boot option (0–3); controls what the client does on login |
| `getPriv()` / `setPriv()` | `'S'` or `'U'` | Privilege: `'S'` = system manager (admin), `'U'` = user |
| `isAdmin()` | bool | True when `getPriv() === 'S'` |
| `getQuota()` / `setQuota()` | int | Per-user disc quota in bytes; `0` means use the global `vfs_default_disc_free` config value |

Plugins receive and return `User` objects. The `Security` class stores the
object built by the winning plugin's `buildUserObject()` method in the session.
Changes to CSD and LIB are made directly on the stored `User` object by the
`FileServer` provider as the user navigates directories.

---

## `AuthPluginInterface`

**File:** `src/include/classes/Authentication/Plugins/AuthPluginInterface.php`
**Namespace:** `HomeLan\FileStore\Authentication\Plugins`

Every auth plugin is a **static PHP class** implementing this interface.

```php
interface AuthPluginInterface {
    static public function init(\Psr\Log\LoggerInterface $oLogger, $sUsers = null): void;
    static public function login(string $sUsername, string $sPassword, ?int $iNetwork, ?int $iStation): bool;
    static public function buildUserObject(string $sUsername): User;
    static public function getAllUsers(): array;
    static public function setPassword(string $sUsername, string $sOldPassword, string $sPassword): void;
    static public function setPasswordAdmin(string $sUsername, string $sPassword): void;
    static public function createUser(User $oUser): void;
    static public function removeUser(string $sUsername): bool;
    static public function setPriv(string $sUsername, string $sPriv): void;
    static public function setOpt(string $sUsername, string $sOpt): void;
    static public function setQuota(string $sUsername, int $iQuota): void;
}
```

### Method-by-method notes

**`init($oLogger, $sUsers = null)`**

Called once when the plugin class is first loaded. Load users from the
backend (disk file, database, LDAP, etc.). The optional `$sUsers` parameter
is a raw string that can inject data during unit tests, bypassing the config
file. Throw an exception if the backend is unreachable and the plugin should
not be active.

**`login($sUsername, $sPassword, $iNetwork, $iStation)`**

Validate the credentials. Return `true` on success, `false` on failure.
The network and station are provided so that a plugin can optionally restrict
login to specific subnets or stations. Plugins that do not need this (e.g. a
flat file plugin) can ignore these parameters.

**`buildUserObject($sUsername)`**

Construct and return a `User` object for the given username. Called immediately
after a successful `login()`. The returned object must have username, Unix UID,
home dir, boot option, and privilege correctly populated. Do not call `setCsd()`
or `setLib()` here — the `FileServer` provider sets those after login.

**`getAllUsers()`**

Return an array of `User` objects for every user known to this backend. Used
by the admin web interface and by the file server's `USERS` command. The
`Security` class aggregates the results from all plugins and de-duplicates
them by username.

**`setPassword($sUsername, $sOldPassword, $sPassword)`**

Change the user's password. The old password must be verified first (throw
an exception if it is wrong). The new password should be stored in whatever
format the backend uses (hashed, etc.). Throw an exception if the backend is
read-only.

**`createUser(User $oUser)`**

Add a new user to the backend. The `Security` class has already verified that
the caller has admin rights before calling this. Throw an exception if the user
already exists, or if the backend is read-only. The `Security` class will try
each plugin in turn and stop at the first one that succeeds.

**`removeUser($sUsername)`**

Delete the named user. Throw an exception if the user does not exist or the
backend is read-only. Return `true` on success.

**`setPriv($sUsername, $sPriv)`**

Set the privilege flag to `'S'` (system manager) or `'U'` (user). Persist
the change to the backend. The `Security` class has already validated the
`$sPriv` value.

**`setOpt($sUsername, $sOpt)`**

Set the boot option string for the user. The value is always the string
representation of the option (e.g. `'0'`, `'1'`, `'2'`, `'3'`). Persist
the change.

**`setPasswordAdmin($sUsername, $sPassword)`**

Reset a user's password without requiring the current password. For sysop use only. The `Security` class verifies admin rights before calling this; the plugin itself must **not** re-check privileges. Throw an exception if the user does not exist or the backend is read-only.

**`setQuota($sUsername, $iQuota)`**

Set the per-user disc quota in bytes. A value of `0` means "use the global `vfs_default_disc_free` config value" — it does not set a zero-byte quota. Persist the change. If the user does not exist, the method may silently do nothing (consistent with the `setPriv`/`setOpt` pattern).

---

## Built-in plugin: `AuthPluginFile`

**File:** `src/include/classes/Authentication/Plugins/AuthPluginFile.php`

The only built-in plugin. Reads and writes a plain-text user file at startup
and after every mutation.

**File format** — one user per line:

```
USERNAME:hashtype-hash:homedir:unixuid:opt:priv[:quota]
```

The optional 7th field `quota` was added to support per-user disc quotas. Lines without a 7th field (legacy format) parse with a quota of `0` (use the global default).

| Field | Example | Description |
|---|---|---|
| username | `JOHN` | Upper-case Econet username |
| hash | `md5-5f4dcc3b5aa765d61d8327deb882cf99` | `hashtype-` prefix, then the hash; or empty for no password |
| homedir | `$.HOME.JOHN` | Econet path to home directory |
| unixuid | `5001` | Unix UID for file ownership |
| opt | `3` | Boot option (0–3) |
| priv | `U` | `S` = system manager, `U` = user |
| quota | `0` | Per-user disc quota in bytes; `0` or absent = use global default |

**Config keys:**

| Key | Description |
|---|---|
| `security_plugin_file_user_file` | Path to the user file |
| `security_plugin_file_default_crypt` | Hash type for new passwords: `plain`, `sha1`, or `md5` (default) |

---

## Registering plugins

Plugins are activated via the `security_auth_plugins` config key:

```
security_auth_plugins = File
```

Multiple plugins can be listed:

```
security_auth_plugins = File,Ldap
```

The `Security` class resolves each name to the class
`HomeLan\FileStore\Authentication\Plugins\AuthPlugin<Name>` (with the first
letter uppercased). So `File` → `AuthPluginFile`, `Ldap` → `AuthPluginLdap`.

---

## Writing a new plugin

### Step 1 — Create the class

Create
`src/include/classes/Authentication/Plugins/AuthPluginMyBackend.php`:

```php
namespace HomeLan\FileStore\Authentication\Plugins;

use HomeLan\FileStore\Authentication\User;
use Exception;

class AuthPluginMyBackend implements AuthPluginInterface
{
    protected static \Psr\Log\LoggerInterface $oLogger;

    // Internal state — load from the backend in init()
    protected static array $aUsers = [];

    public static function init(\Psr\Log\LoggerInterface $oLogger, $sUsers = null): void
    {
        self::$oLogger = $oLogger;

        // Load users from your backend.
        // For a database: open a connection, query all users.
        // For LDAP: bind, search.
        // Throw an exception here if the backend is unavailable and the
        // plugin should not participate.
        self::$aUsers = self::_loadFromBackend();
    }

    public static function login(string $sUsername, string $sPassword, ?int $iNetwork = null, ?int $iStation = null): bool
    {
        $sUsername = strtoupper($sUsername);
        if (!array_key_exists($sUsername, self::$aUsers)) {
            return false;
        }
        // Validate credentials against the backend
        return password_verify($sPassword, self::$aUsers[$sUsername]['password_hash']);
    }

    public static function buildUserObject(string $sUsername): User
    {
        $sUsername = strtoupper($sUsername);
        $oUser = new User();
        if (array_key_exists($sUsername, self::$aUsers)) {
            $aData = self::$aUsers[$sUsername];
            $oUser->setUsername($aData['username']);
            $oUser->setUnixUid($aData['unix_uid']);
            $oUser->setHomedir($aData['homedir']);
            $oUser->setBootOpt($aData['boot_opt']);
            $oUser->setPriv($aData['priv']);
        }
        return $oUser;
    }

    public static function getAllUsers(): array
    {
        $aReturn = [];
        foreach (self::$aUsers as $sUsername => $aData) {
            $aReturn[] = self::buildUserObject($sUsername);
        }
        return $aReturn;
    }

    public static function setPassword(string $sUsername, string $sOldPassword, string $sPassword): void
    {
        if (!self::login($sUsername, $sOldPassword)) {
            throw new Exception("Old password is incorrect");
        }
        $sUsername = strtoupper($sUsername);
        if (!array_key_exists($sUsername, self::$aUsers)) {
            throw new Exception("User not found");
        }
        self::$aUsers[$sUsername]['password_hash'] = password_hash($sPassword, PASSWORD_DEFAULT);
        self::_persist();
    }

    public static function createUser(User $oUser): void
    {
        $sUsername = strtoupper((string) $oUser->getUsername());
        if (array_key_exists($sUsername, self::$aUsers)) {
            throw new Exception("User already exists");
        }
        self::$aUsers[$sUsername] = [
            'username'      => $oUser->getUsername(),
            'password_hash' => '',
            'unix_uid'      => $oUser->getUnixUid(),
            'homedir'       => $oUser->getHomedir(),
            'boot_opt'      => $oUser->getBootOpt(),
            'priv'          => $oUser->getPriv(),
        ];
        self::_persist();
    }

    public static function removeUser(string $sUsername): bool
    {
        $sUsername = strtoupper($sUsername);
        if (!array_key_exists($sUsername, self::$aUsers)) {
            throw new Exception("User does not exist");
        }
        unset(self::$aUsers[$sUsername]);
        self::_persist();
        return true;
    }

    public static function setPriv(string $sUsername, string $sPriv): void
    {
        $sUsername = strtoupper($sUsername);
        if (array_key_exists($sUsername, self::$aUsers)) {
            self::$aUsers[$sUsername]['priv'] = $sPriv;
            self::_persist();
        }
    }

    public static function setOpt(string $sUsername, string $sOpt): void
    {
        $sUsername = strtoupper($sUsername);
        if (array_key_exists($sUsername, self::$aUsers)) {
            self::$aUsers[$sUsername]['boot_opt'] = (int) $sOpt;
            self::_persist();
        }
    }

    public static function setPasswordAdmin(string $sUsername, string $sPassword): void
    {
        $sUsername = strtoupper($sUsername);
        if (!array_key_exists($sUsername, self::$aUsers)) {
            throw new Exception("User not found");
        }
        // No old-password check — caller (Security) has already verified admin privilege
        self::$aUsers[$sUsername]['password_hash'] = password_hash($sPassword, PASSWORD_DEFAULT);
        self::_persist();
    }

    public static function setQuota(string $sUsername, int $iQuota): void
    {
        $sUsername = strtoupper($sUsername);
        if (array_key_exists($sUsername, self::$aUsers)) {
            self::$aUsers[$sUsername]['quota'] = $iQuota;
            self::_persist();
        }
    }

    // --- Private helpers ---

    private static function _loadFromBackend(): array
    {
        // Load and return the user map from your storage backend.
        // Keys must be upper-case usernames.
        return [];
    }

    private static function _persist(): void
    {
        // Write any in-memory changes back to the backend.
    }
}
```

### Step 2 — Add any required config keys

Add keys to the config file for any connection strings, file paths, or
credentials the plugin needs. Document them in the project config
documentation.

### Step 3 — Register the plugin

Add the plugin name (without the `AuthPlugin` prefix) to `security_auth_plugins`:

```
security_auth_plugins = File,MyBackend
```

The `Security` class will call `AuthPluginMyBackend::init()` on first use and
then try it in order for every login.

---

## Admin integration

The admin web front end's **Users** tab is populated by
`Security::getAllUsers()`, which aggregates `getAllUsers()` from every active
plugin. No additional work is required — if your plugin correctly implements
`getAllUsers()` and `buildUserObject()`, the admin UI will display all your
users automatically.

---

## Files at a glance

| File | Role |
|---|---|
| `src/include/classes/Authentication/Security.php` | Static facade — all auth operations go through here |
| `src/include/classes/Authentication/User.php` | User data object |
| `src/include/classes/Authentication/Plugins/AuthPluginInterface.php` | Interface every plugin must implement |
| `src/include/classes/Authentication/Plugins/AuthPluginFile.php` | Built-in flat-file backend (reference implementation) |
