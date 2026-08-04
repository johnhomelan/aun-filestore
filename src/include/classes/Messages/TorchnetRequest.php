<?php

namespace HomeLan\FileStore\Messages;

/**
 * Decodes an inbound TorchNet request packet (ports 0x90 / 0x91).
 *
 * The TorchNet payload begins immediately with a 1-byte command action code;
 * there is no reply-port field (unlike FsRequest).  The reply port is taken
 * from the EconetPacket port field — the client expects the server to reply
 * on the same port the request arrived on.
 *
 * After decode(), $this->sData contains everything that follows the command
 * byte.  getByte(1) / parseCpmFilename() etc. are therefore 1-indexed into
 * the per-command payload, matching the byte positions in the spec tables.
 *
 * @package coreprotocol
 */
class TorchnetRequest extends Request
{
    private int $iCommand = 0;
    private int $iReplyPort = 0x90;

    private array $aCommandMap = [
        0x01 => 'TORCH_OPEN',
        0x02 => 'TORCH_CLOSE',
        0x03 => 'TORCH_READ_BLOCK',
        0x04 => 'TORCH_WRITE_BLOCK',
        0x05 => 'TORCH_DELETE',
        0x06 => 'TORCH_SEARCH_FIRST',
        0x07 => 'TORCH_SEARCH_NEXT',
        0x08 => 'TORCH_CONSOLE_NOTIFY',
        0x09 => 'TORCH_PRINT_REDIRECT',
        0x0D => 'TORCH_CREATE',
        0x0E => 'TORCH_RENAME',
        0x10 => 'TORCH_MEM_PEEK',
        0x11 => 'TORCH_MEM_POKE',
        0x1A => 'TORCH_CONTROL_ACTION',
    ];

    public function __construct($oEconetPacket, \Psr\Log\LoggerInterface $oLogger)
    {
        parent::__construct($oEconetPacket, $oLogger);
        $this->iReplyPort = $oEconetPacket->getPort();
        $this->decode((string) $oEconetPacket->getData());
    }

    public function getReplyPort(): int
    {
        return $this->iReplyPort;
    }

    public function getCommand(): string
    {
        return $this->aCommandMap[$this->iCommand]
            ?? ('TORCH_UNKNOWN_' . sprintf('0x%02X', $this->iCommand));
    }

    public function getRawCommand(): int
    {
        return $this->iCommand;
    }

    /**
     * Parse an 11-byte space-padded CP/M 8+3 filename from the per-command
     * payload at the given 1-based byte offset.
     *
     * Returns ['name' => '...', 'ext' => '...'] with trailing spaces stripped.
     */
    public function parseCpmFilename(int $iOffset): array
    {
        $sRaw  = substr((string) $this->sData, $iOffset - 1, 11);
        $sName = rtrim(substr($sRaw, 0, 8));
        $sExt  = rtrim(substr($sRaw, 8, 3));
        return ['name' => $sName, 'ext' => $sExt];
    }

    public function buildReply(): TorchnetReply
    {
        return new TorchnetReply($this);
    }

    private function decode(string $sBinaryData): void
    {
        if ($sBinaryData === '') {
            return;
        }
        $aHdr = unpack('C', $sBinaryData);
        $this->iCommand = $aHdr[1];
        $this->sData    = substr($sBinaryData, 1);
    }
}
