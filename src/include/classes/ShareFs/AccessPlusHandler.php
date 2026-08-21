<?php

namespace HomeLan\FileStore\ShareFs;

use HomeLan\FileStore\ShareFs\Messages\AccessPlusPacket;
use React\Datagram\SocketInterface;

/**
 * Handles Access+ per-share password authentication (UDP port 32771).
 *
 * Matches andrewtimmins/riscos-access-server's src/accessplus.c: a client wanting a protected
 * share folds that share's password into a PIN (AccessPlusPacket::foldPassword()) and sends it
 * as a "share key" request. This server checks the key against every configured protected
 * share's own folded password; each match records the requester's IP as authenticated for
 * that share in ShareAuthTable (10-minute sliding window) and replies with the share's info.
 * A non-matching key gets silence, not an error reply - matching the reference server exactly,
 * since a real client is expected to just keep trying different guesses.
 *
 * There is no username anywhere in this exchange - see docs/protocols/sharefs.md.
 */
class AccessPlusHandler
{
    private const int ATTR_PROTECTED = 0x01;
    private const int ATTR_READONLY  = 0x02;
    private const int ATTR_HIDDEN    = 0x04;

    private SocketInterface $oSocket;

    public function __construct(private readonly \Psr\Log\LoggerInterface $oLogger)
    {
    }

    public function setSocket(SocketInterface $oSocket): void
    {
        $this->oSocket = $oSocket;
    }

    public function receive(string $sMessage, string $sSrcAddress): void
    {
        try {
            $oPacket = AccessPlusPacket::decode($sMessage);
        } catch (\Exception $oException) {
            $this->oLogger->warning("ShareFs Access+: discarding malformed packet from {$sSrcAddress}: " . $oException->getMessage());
            return;
        }

        if (!$oPacket->isShareKeyRequest()) {
            $this->oLogger->debug("ShareFs Access+: ignoring message type {$oPacket->getMessageType()} from {$sSrcAddress}");
            return;
        }

        $sClientIp = self::addressToIp($sSrcAddress);
        $iClientKey = $oPacket->getClientKey();

        foreach (ShareList::getProtectedShares() as $oShare) {
            $iShareKey = AccessPlusPacket::foldPassword($oShare->getPassword());
            if ($iShareKey !== $iClientKey) {
                continue;
            }

            $this->oLogger->info("ShareFs Access+: {$sClientIp} authenticated for share \"{$oShare->getName()}\"");
            ShareAuthTable::add($sClientIp, $oShare->getName());

            $iAttrs = self::ATTR_PROTECTED
                | ($oShare->isReadOnly() ? self::ATTR_READONLY : 0)
                | ($oShare->isHidden() ? self::ATTR_HIDDEN : 0);

            $this->oSocket->send(
                AccessPlusPacket::encodeProtectedShareReply($iShareKey, $oShare->getName(), $iAttrs),
                $sSrcAddress
            );
        }
    }

    public static function addressToIp(string $sAddress): string
    {
        $iColon = strrpos($sAddress, ':');
        return $iColon === false ? $sAddress : substr($sAddress, 0, $iColon);
    }
}
