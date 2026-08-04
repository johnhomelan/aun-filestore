<?php

namespace HomeLan\FileStore\Messages;

/**
 * Builds outbound TorchNet response packets.
 *
 * Each helper method sets $this->sPkt to the correctly formatted binary
 * payload for that response type, as specified by the TorchNet wire protocol.
 * Call buildEconetpacket() (inherited from Reply) to wrap it in an
 * EconetPacket addressed back to the requesting station.
 *
 * @package coreprotocol
 */
class TorchnetReply extends Reply
{
    /** Generic success response (status 0x00). */
    public function ok(): void
    {
        $this->sPkt = pack('C', 0x00);
    }

    /** Generic error response. */
    public function error(int $iStatus = 0xFF): void
    {
        $this->sPkt = pack('C', $iStatus);
    }

    /** Open / Create success: status 0x00 + assigned file handle. */
    public function openOk(int $iHandle): void
    {
        $this->sPkt = pack('CC', 0x00, $iHandle);
    }

    /** Open / Create failure: status 0xFF + zero handle. */
    public function openError(): void
    {
        $this->sPkt = pack('CC', 0xFF, 0x00);
    }

    /**
     * Read Block success: status + actual byte count + raw sector data.
     *
     * Status 0x00 = valid data; 0x01 = EOF reached.
     */
    public function readOk(string $sData, bool $bEof = false): void
    {
        $iStatus = $bEof ? 0x01 : 0x00;
        $this->sPkt = pack('CC', $iStatus, strlen($sData)) . $sData;
    }

    /** EOF with zero bytes returned. */
    public function readEof(): void
    {
        $this->sPkt = pack('CC', 0x01, 0x00);
    }

    /**
     * Search match: status 0x00, 11-byte space-padded 8+3 name, record count
     * (file size in 128-byte sectors), 4-byte allocation bitmask.
     */
    public function searchFound(string $sName, string $sExt, int $iRecordCount): void
    {
        $sCpmName   = str_pad(substr($sName, 0, 8), 8, ' ')
                    . str_pad(substr($sExt,  0, 3), 3, ' ');
        $this->sPkt = pack('C', 0x00)
                    . $sCpmName
                    . pack('C', $iRecordCount & 0xFF)
                    . pack('V', 0xFFFFFFFF);
    }

    /** End of directory / no match: status 0xFF. */
    public function searchEnd(): void
    {
        $this->sPkt = pack('C', 0xFF);
    }

    /** Memory peek response: raw bytes (only meaningful client-side). */
    public function memPeekResult(string $sData): void
    {
        $this->sPkt = $sData;
    }
}
