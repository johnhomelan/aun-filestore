<?php

namespace HomeLan\FileStore\Vfs;

/**
 * CP/M-compatible view of a directory entry.
 *
 * Wraps a standard DirectoryEntry and translates the Acorn '.' directory
 * separator to the CP/M '\' separator wherever a name or path is returned
 * to the CP/M protocol layer.  All other behaviour (access bits, load/exec
 * addresses, size, timestamps, SIN) is delegated unchanged to the underlying
 * entry.
 *
 * Instantiate via the static wrap() factory rather than the constructor
 * directly, so that the protected state of the source entry is copied
 * faithfully without needing a public getter for every field.
 *
 * @package corevfs
 * @author John Brown <john@home-lan.co.uk>
 */
class CpmDirectoryEntry extends DirectoryEntry
{
    /**
     * Wrap an existing DirectoryEntry in a CP/M-compatible view.
     *
     * The access value is copied exactly via setAccess() rather than
     * re-derived from a mode string, so no information is lost.
     */
    public static function wrap(DirectoryEntry $oEntry): self
    {
        // Protected properties of a parent-class instance are accessible
        // within child-class scope in PHP, so we can read them here to
        // reconstruct the entry faithfully.
        // sUnixName is deliberately not copied — Unix filesystem paths must
        // not be exposed to the CP/M layer (see getUnixName() below).
        $oCpmEntry = new self(
            $oEntry->sEconetName,
            '',                         // unix name withheld from CP/M layer
            $oEntry->sVfsPlugin,
            $oEntry->iLoadAddr,
            $oEntry->iExecAddr,
            $oEntry->iSize,
            $oEntry->sEconetFullFilePath,
            $oEntry->iCTime,
            '',         // sMode is ignored — setAccess() below copies the exact bitmask
            $oEntry->bDir
        );
        $oCpmEntry->setAccess($oEntry->iAccess);
        return $oCpmEntry;
    }

    /**
     * Unix filesystem paths are not exposed through the CP/M layer.
     * Path resolution between Acorn paths and the local filesystem is the
     * exclusive responsibility of Vfs and its plugins.
     */
    public function getUnixName(): string
    {
        return '';
    }

    /**
     * Return the entry name translated to CP/M conventions.
     *
     * This is a single name within its parent directory, not a full path, so
     * it never contains '.' (the Acorn path separator is not valid inside a
     * name).  The only translation required is '\' → '.': a '\' that is a
     * legitimate part of an Acorn filename must become '.' so that CP/M does
     * not misinterpret it as a directory separator.  As a side effect this
     * maps cleanly onto CP/M's 8.3 extension separator (e.g. Acorn "FILE\COM"
     * becomes CP/M "FILE.COM").
     */
    public function getCpmName(): string
    {
        return str_replace('\\', '.', $this->sEconetName);
    }
}
