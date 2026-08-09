# Virtual File System (VFS) — Developer Guide

This document describes how the VFS layer works, how errors are modelled, and
what a developer needs to implement to add a new VFS plugin.

---

## Overview

The VFS layer provides the file server with a single, uniform API for all file
operations — directory listings, open/read/write/close, metadata, and so on —
regardless of where the underlying data actually lives. All callers interact
exclusively with the static `Vfs` class; they never touch a plugin directly.

Internally, `Vfs` maintains a list of active plugins (ordered by configuration)
and a table of open file handles. For most operations it walks the plugin list
in order, asks each plugin to attempt the operation, and stops as soon as one
succeeds. The exception model controls how a failed attempt is handled —
**soft failures** move on to the next plugin, **hard failures** abort the
chain entirely.

```
Caller (FileServer)
      │
      ▼
 Vfs::*()   ─── path resolution ──▶ FilePath
      │
      ├─── Plugin[0]::method()   ◀── try first
      │         │
      │   soft error? ──────────────┐
      │                             │
      ├─── Plugin[1]::method()   ◀──┘ try next
      │         │
      │   hard error? ──────────────▶ abort, re-throw
      │         │
      │   success ─────────────────▶ return result
      │
      └─── (all failed) ──────────▶ throw generic Exception
```

---

## Key classes

### `Vfs` — the facade

**File:** `src/include/classes/Vfs/Vfs.php`
**Namespace:** `HomeLan\FileStore\Vfs`

A static class. All public methods require the caller to supply a
`$iNetwork` / `$iStation` pair identifying the Econet workstation. The `Vfs`
class verifies that the station is logged in (via `Security::isLoggedIn()`)
before allowing any file operation.

Startup:

```php
Vfs::init($oLogger, 'LocalFile,S3');   // comma-separated plugin names
```

Key public methods:

| Method | Description |
|---|---|
| `createFsHandle(net, stn, path, mustExist, readOnly)` | Open a file or directory handle |
| `getFsHandle(net, stn, handleId)` | Look up an open handle by its client-visible ID |
| `closeFsHandle(net, stn, handleId)` | Close and release a single handle |
| `closeAllFsHandles(net, stn)` | Close all handles for a station (used on logout) |
| `replaceFsHandle(net, stn, old, new)` | Swap one handle ID for another |
| `getDirectoryListing(FileDescriptor)` | Get the entries for a directory handle |
| `getMeta(net, stn, path)` | Fetch metadata for a file or directory |
| `setMeta(net, stn, path, load, exec, access)` | Set metadata; `null` fields are left unchanged |
| `saveFile(net, stn, path, data, load, exec)` | Write a complete file |
| `createFile(net, stn, path, size, load, exec)` | Create a new empty file |
| `getFile(net, stn, path)` | Read a complete file |
| `createDirectory(net, stn, path)` | Create a directory |
| `deleteFile(net, stn, path)` | Delete a file or directory |
| `moveFile(net, stn, from, to)` | Rename / move |
| `getSin(econetPath)` | Get a unique 24-bit serial identity number for a path |
| `houseKeeping()` | Called periodically; cleans up stale handles and runs plugin housekeeping |

### `FilePath` — path container

**File:** `src/include/classes/Vfs/FilePath.php`

A simple value object that holds a directory string and a filename string,
both in Econet notation (`.` as separator, `$` as root). Plugins receive a
`FilePath` for all operations that address a specific file.

```php
$oPath = new FilePath('$.HOME.JOHN', 'MYFILE');
$oPath->sDir;        // '$.HOME.JOHN'
$oPath->sFile;       // 'MYFILE'
$oPath->getFilePath(); // '$.HOME.JOHN.MYFILE'
```

`Vfs::buildFullPath()` (private) converts a raw Econet path string into a
`FilePath`, resolving:
- Absolute paths starting with `$` (or `&`, a legacy alias for `$`)
- Relative paths (prepended with the user's current selected directory, CSD)
- Wildcard expansion (`*`) via `_resolveFullPath()`
- Chroot restrictions (when a user's root is not `$`)

### `FileDescriptor` — an open handle

**File:** `src/include/classes/Vfs/FileDescriptor.php`

Wraps one open VFS handle. Created by `Vfs::createFsHandle()` and stored in
`Vfs::$aHandles[network][station][handleId]`. The file server hands the client
a single-byte handle ID; the `Vfs` class maps that ID back to a
`FileDescriptor` on every subsequent call.

Important methods:

| Method | Description |
|---|---|
| `getID()` | The client-visible handle number (1–254) |
| `getEconetPath()` | The full Econet path this handle refers to |
| `isFile()` | True for file handles, false for directory handles |
| `isDir()` | True for directory handles |
| `read($iLength)` | Read bytes from the current position |
| `write($sData)` | Write bytes at the current position |
| `fsFTell()` | Return current byte position |
| `fsFStat()` | Return file size / attributes |
| `isEof()` | True when at end of file |
| `setPos($iPos)` | Seek to a byte position |
| `setExt($iExt)` | Set the file length (truncate or extend) |
| `fsLock(bool $bExclusive)` | Acquire a lock on the underlying file |
| `fsUnlock()` | Release the lock |
| `close()` | Close the underlying plugin handle |

Each `FileDescriptor` records which plugin opened it (`$sVfsPlugin`). If a
plugin call returns a soft VfsException the descriptor will attempt to fall
back to the next plugin via `changeVfs()`. This lets a read-only plugin serve
reads even after a write-capable plugin fails.

---

## The exception model

**File:** `src/include/classes/Vfs/Exception.php`

All VFS errors are reported by throwing a `HomeLan\FileStore\Vfs\Exception`
(aliased to `VfsException` throughout the codebase).

```php
class Exception extends BaseException {
    public function __construct(
        string $sMessage,
        protected bool $bHard   = false,
        protected bool $bLocked = false
    ) { ... }

    public function isHard(): bool   { return $this->bHard; }
    public function isLocked(): bool { return $this->bLocked; }
}
```

### Hard vs soft errors

| Flag | Meaning | VFS behaviour |
|---|---|---|
| `$bHard = false` | Soft / transient error | VFS moves on to the next plugin |
| `$bHard = true` | Hard / fatal error | VFS aborts the chain and re-throws immediately |

A **soft error** means "I cannot handle this path / operation; try the next
plugin". Use it when the path does not belong to your plugin, or when a
read-only plugin receives a write request.

A **hard error** means "something is definitively wrong and no other plugin
should be tried". Use it for genuine I/O failures, permission denials, or
veto operations.

### The locked flag

`$bLocked = true` signals a file-lock conflict — the file is already open by
another handle with an incompatible access mode. The Vfs layer sets this flag
internally; plugins do **not** need to set it, since locking is managed by
`Vfs` itself (see [File locking](#file-locking) below).

The `FileServer` provider catches locked exceptions and returns Econet error
`0xC3 "Already open"` to the client, whereas other VfsExceptions return a
generic failure.

---

## Plugin chain execution

For each VFS operation `Vfs` iterates `getVfsPlugins()` and calls the
appropriate method on each plugin:

```php
foreach ($aPlugins as $sPlugin) {
    try {
        $result = $sPlugin::someOperation(...);
        if ($result) { return $result; }  // plugin handled it
    } catch (VfsException $e) {
        if ($e->isHard()) { throw $e; }   // abort chain
        // soft error: try next plugin
    }
}
throw new Exception("No plugin handled the operation");
```

The plugins are tried in the order listed in `vfs_plugins`. Once a plugin
returns a truthy value (or void without exception) the chain stops. If the
chain exhausts all plugins without success, `Vfs` throws a plain `Exception`
(not a `VfsException`).

---

## File locking

File-level locking is enforced entirely within the `Vfs` class. Plugins do
**not** implement their own locking logic independently — they only receive
calls to `fsLock()` / `fsUnlock()` as a notification to propagate the lock
to the underlying storage medium (where supported).

The model follows Acorn's Level 4 File Server semantics:

- Multiple simultaneous **read-only** handles to the same file are permitted.
- A **read-write** handle is exclusive — it blocks all other readers and
  writers.

`Vfs` tracks lock state in two private static maps:

```
$aLocks[econetPath] = ['readers' => int, 'writers' => int]
$aHandleLocks[network][station][handleId] = 'read' | 'write'
```

When `createFsHandle()` opens a file (not a directory) it calls
`_acquireLock()`. If the lock cannot be granted the descriptor is immediately
closed and a locked `VfsException` is thrown. On success `Vfs` calls
`$oHandle->fsLock()` to notify the plugin.

Locks are released when:
- `closeFsHandle()` is called explicitly.
- `closeAllFsHandles()` is called (on logout).
- `houseKeeping()` discovers a handle for a station that is no longer logged in.

---

## `PluginInterface`

**File:** `src/include/classes/Vfs/Plugin/PluginInterface.php`
**Namespace:** `HomeLan\FileStore\Vfs\Plugin`

Every VFS plugin is a **static PHP class** that implements this interface. All
methods are static — there are no instances. This is a deliberate design choice
that avoids dependency-injection boilerplate given PHP's class autoloading model.

```php
interface PluginInterface {
    // Lifecycle
    public static function init(\Psr\Log\LoggerInterface $oLogger, bool $bMultiuser = false);
    public static function houseKeeping();

    // Handle construction (low-level — called by Vfs)
    public static function _buildFiledescriptorFromEconetPath(
        $oUser, FilePath $oEconetPath, bool $bMustExist, bool $bReadOnly
    );
    public static function _getAccessMode(int $iGid, int $iUid, int $iMode);

    // Directory
    public static function getDirectoryListing(string $sEconetPath, array $aDirectoryListing): array;
    public static function createDirectory($oUser, FilePath $oPath);

    // File metadata
    public static function setMeta(string $sEconetPath, int $iLoad, int $iExec, int $iAccess);

    // File I/O (whole-file)
    public static function saveFile($oUser, FilePath $oEconetPath, string $sData, int $iLoadAddr, int $iExecAddr);
    public static function createFile($oUser, FilePath $oEconetPath, int $iSize, int $iLoadAddr, int $iExecAddr);
    public static function getFile($oUser, FilePath $oEconetPath): string;
    public static function deleteFile($oUser, FilePath $oEconetPath);
    public static function moveFile($oUser, FilePath $oEconetPathFrom, FilePath $oEconetPathTo);

    // Handle I/O (streaming, called through FileDescriptor)
    public static function fsFtell($oUser, $fLocalHandle);
    public static function fsFStat($oUser, $fLocalHandle);
    public static function isEof($oUser, $fLocalHandle);
    public static function setPos($oUser, $fLocalHandle, $iPos);
    public static function setExt($oUser, $fLocalHandle, int $iExt): void;
    public static function read($oUser, $fLocalHandle, $iLength);
    public static function write($oUser, $fLocalHandle, $sData);

    // Locking (propagated from Vfs; no-op is acceptable)
    public static function fsLock($oUser, $fLocalHandle, bool $bExclusive): void;
    public static function fsUnlock($oUser, $fLocalHandle): void;

    // Handle lifecycle
    public static function fsClose($oUser, $fLocalHandle);
}
```

### Method-by-method notes

**`init()`** — Called once when the plugin class is first loaded. Read config,
open database connections, initialise static state. Must not throw for missing
config if the plugin should silently opt out of serving any paths — instead
return without setting up the state tables (subsequent calls will then produce
soft errors).

**`houseKeeping()`** — Called on the regular server timer (typically every
few seconds). Clean up expired resources, flush write-behind caches, etc.

**`_buildFiledescriptorFromEconetPath()`** — The most critical method. Called
by `Vfs::_buildFiledescriptorFromEconetPath()` for every file open. The plugin
must decide whether the given `FilePath` belongs to it. If not, return `null`
(or throw a soft `VfsException`). If yes, open the file in the underlying
storage and return a configured `FileDescriptor` object:

```php
return new FileDescriptor(
    $oLogger,
    self::class,         // plugin class name — stored in descriptor
    $oUser,              // User object
    $sUnixPath,          // local absolute path (for debugging)
    $oEconetPath->getFilePath(), // full econet path string
    $fHandle,            // the raw handle from fopen() / fopen-equivalent
    Vfs::getFreeFileHandleID($oUser), // client-visible handle ID (1-254)
    true,                // bFile
    false                // bDir
);
```

For directory handles, set `bFile = false` and `bDir = true`. Pass `null`
as the raw handle for directory descriptors (directories have no stream handle).

**`getDirectoryListing()`** — Takes an existing directory listing array and
**adds** entries from this plugin for the given Econet path. Returning the
`$aDirectoryListing` array unmodified (or returning the enriched array) both
work. The Vfs layer calls this on every plugin so multiple plugins can
contribute entries to the same directory. Entry keys are the Econet filenames;
values must be metadata objects that implement `getEconetName()`,
`getLoadAddr()`, `getExecAddr()`, `getAccess()`, `isDir()`, etc.

**`saveFile()` / `createFile()` / `deleteFile()` / `moveFile()`** — Return
`true` if the operation succeeded, `null`/`false` if this plugin does not own
the path (soft error), or throw a hard `VfsException` on a genuine failure.

**`setMeta()`** — Called on all plugins (no short-circuit). All plugins that
know about the file should update their stored metadata.

**`read()` / `write()` / `setExt()`** — These are only ever called through a
`FileDescriptor` that was opened by this plugin. The `$fLocalHandle` is
exactly what `_buildFiledescriptorFromEconetPath()` stored as `$fHandle`.

**`fsLock()` / `fsUnlock()`** — Called by `FileDescriptor` as a notification
after `Vfs` has already granted or released the logical lock. Plugins that have
no mechanism for advisory locks (disk images, HTTP catalogues, etc.) should
provide a no-op implementation. Plugins that can support advisory locks (local
files via `flock()`, S3 via object tagging, etc.) should forward the call to
the underlying storage. A no-op is always safe — the logical lock in `Vfs` is
the authoritative source of truth.

---

## Writing a new plugin

### Step 1 — Create the class

Create `src/include/classes/Vfs/Plugin/MyPlugin.php`:

```php
namespace HomeLan\FileStore\Vfs\Plugin;

use HomeLan\FileStore\Vfs\FilePath;
use HomeLan\FileStore\Vfs\FileDescriptor;
use HomeLan\FileStore\Vfs\Exception as VfsException;
use HomeLan\FileStore\Vfs\Vfs;

class MyPlugin implements PluginInterface
{
    protected static \Psr\Log\LoggerInterface $oLogger;

    // The Econet path prefix this plugin serves, e.g. '$.mystore'
    protected static string $sRootPath = '';

    public static function init(\Psr\Log\LoggerInterface $oLogger, bool $bMultiuser = false): void
    {
        self::$oLogger = $oLogger;
        self::$sRootPath = config::getValue('vfs_plugin_myplugin_root_path');
        // Perform any other setup here
    }

    public static function houseKeeping(): void
    {
        // Periodic cleanup — leave empty if not needed
    }

    public static function _getAccessMode(int $iGid, int $iUid, int $iMode): int
    {
        // Return an Econet access byte (bitmask).  See existing plugins for reference.
        return 0xFF;
    }

    public static function _buildFiledescriptorFromEconetPath(
        $oUser, FilePath $oEconetPath, bool $bMustExist, bool $bReadOnly
    ): ?FileDescriptor {
        $sEconetPath = $oEconetPath->getFilePath();

        // Check if this path belongs to our plugin
        if (!str_starts_with($sEconetPath, self::$sRootPath)) {
            return null;  // soft error — let the next plugin try
        }

        // Map to the underlying resource
        $sLocalPath = self::_econetToLocal($sEconetPath);

        if ($bMustExist && !file_exists($sLocalPath)) {
            throw new VfsException("File not found", false);  // soft error
        }

        $bIsDir = is_dir($sLocalPath);
        $fHandle = null;

        if (!$bIsDir) {
            $fHandle = fopen($sLocalPath, $bReadOnly ? 'rb' : 'c+b');
            if ($fHandle === false) {
                throw new VfsException("Cannot open file", true);  // hard error
            }
        }

        return new FileDescriptor(
            self::$oLogger,
            self::class,
            $oUser,
            $sLocalPath,
            $sEconetPath,
            $fHandle,
            Vfs::getFreeFileHandleID($oUser),
            !$bIsDir,   // bFile
            $bIsDir     // bDir
        );
    }

    public static function getDirectoryListing(string $sEconetPath, array $aDirectoryListing): array
    {
        if (!str_starts_with($sEconetPath, self::$sRootPath)) {
            return $aDirectoryListing;  // not our path — return unchanged
        }
        // Add entries to $aDirectoryListing and return it
        return $aDirectoryListing;
    }

    public static function createDirectory($oUser, FilePath $oPath): bool
    {
        if (!str_starts_with($oPath->getFilePath(), self::$sRootPath)) {
            return false;
        }
        // Create the directory
        return true;
    }

    public static function deleteFile($oUser, FilePath $oEconetPath): bool
    {
        if (!str_starts_with($oEconetPath->getFilePath(), self::$sRootPath)) {
            return false;
        }
        // Delete the file
        return true;
    }

    public static function moveFile($oUser, FilePath $oFrom, FilePath $oTo): bool
    {
        if (!str_starts_with($oFrom->getFilePath(), self::$sRootPath)) {
            return false;
        }
        // Move the file
        return true;
    }

    public static function saveFile($oUser, FilePath $oEconetPath, string $sData, int $iLoad, int $iExec): bool
    {
        if (!str_starts_with($oEconetPath->getFilePath(), self::$sRootPath)) {
            return false;
        }
        // Write $sData to the path
        return true;
    }

    public static function createFile($oUser, FilePath $oEconetPath, int $iSize, int $iLoad, int $iExec): bool
    {
        if (!str_starts_with($oEconetPath->getFilePath(), self::$sRootPath)) {
            return false;
        }
        // Create an empty file of $iSize bytes
        return true;
    }

    public static function getFile($oUser, FilePath $oEconetPath): string
    {
        if (!str_starts_with($oEconetPath->getFilePath(), self::$sRootPath)) {
            throw new VfsException("Not our path", false);
        }
        // Return file contents as a binary string
        return '';
    }

    public static function setMeta(string $sEconetPath, int $iLoad, int $iExec, int $iAccess): void
    {
        if (!str_starts_with($sEconetPath, self::$sRootPath)) {
            return;
        }
        // Persist the metadata
    }

    // --- Handle I/O ---

    public static function fsFtell($oUser, $fLocalHandle)
    {
        return ftell($fLocalHandle);
    }

    public static function fsFStat($oUser, $fLocalHandle)
    {
        return fstat($fLocalHandle);
    }

    public static function isEof($oUser, $fLocalHandle): bool
    {
        return feof($fLocalHandle);
    }

    public static function setPos($oUser, $fLocalHandle, $iPos): void
    {
        fseek($fLocalHandle, $iPos, SEEK_SET);
    }

    public static function setExt($oUser, $fLocalHandle, int $iExt): void
    {
        ftruncate($fLocalHandle, $iExt);
    }

    public static function read($oUser, $fLocalHandle, $iLength): string
    {
        return (string) fread($fLocalHandle, $iLength);
    }

    public static function write($oUser, $fLocalHandle, $sData): void
    {
        fwrite($fLocalHandle, $sData);
    }

    // --- Locking (no-op if not supported) ---

    public static function fsLock($oUser, $fLocalHandle, bool $bExclusive): void
    {
        // No-op. Implement flock() here if the underlying medium supports it.
    }

    public static function fsUnlock($oUser, $fLocalHandle): void
    {
        // No-op.
    }

    public static function fsClose($oUser, $fLocalHandle): void
    {
        if (is_resource($fLocalHandle)) {
            fclose($fLocalHandle);
        }
    }

    // --- Private helpers ---

    private static function _econetToLocal(string $sEconetPath): string
    {
        // Convert Econet dot-notation to a local path
        $sRelative = substr($sEconetPath, strlen(self::$sRootPath));
        return '/my/storage' . str_replace('.', DIRECTORY_SEPARATOR, $sRelative);
    }
}
```

### Step 2 — Add a config key

Add a `vfs_plugin_myplugin_root_path` key (or whatever config your plugin
needs) to the server config file.

### Step 3 — Register the plugin

Add the plugin name to the `vfs_plugins` config key:

```
vfs_plugins = LocalFile,MyPlugin,S3
```

Order matters — plugins are tried left to right. Put more-specific plugins
before more-general ones. The `LocalFile` plugin is the usual catch-all and
should come last (or early if it should own paths that other plugins do not
claim).

---

## Files at a glance

| File | Role |
|---|---|
| `src/include/classes/Vfs/Vfs.php` | Static facade — all file operations go through here |
| `src/include/classes/Vfs/Exception.php` | `VfsException` — the hard/soft/locked error model |
| `src/include/classes/Vfs/FilePath.php` | Path value object passed to plugins |
| `src/include/classes/Vfs/FileDescriptor.php` | Wraps one open handle; delegates I/O to the plugin |
| `src/include/classes/Vfs/Plugin/PluginInterface.php` | Interface every plugin must implement |
| `src/include/classes/Vfs/Plugin/LocalFile.php` | Reference implementation — local filesystem, flock() locking |
| `src/include/classes/Vfs/Plugin/S3.php` | S3-backed storage; no-op locking |
| `src/include/classes/Vfs/Plugin/AfsImg.php` | AFS disc image plugin; write methods are no-ops |
| `src/include/classes/Vfs/Plugin/DfsSsd.php` | DFS SSD disc image plugin; write methods are no-ops |
| `src/include/classes/Vfs/Plugin/AdfsHD.php` | ADFS hard-disc image plugin; write methods are no-ops |
| `src/include/classes/Vfs/Plugin/Catalogue.php` | Read-only HTTP catalogue plugin; write methods are no-ops |
