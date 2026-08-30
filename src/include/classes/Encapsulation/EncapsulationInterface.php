<?php
/**
 * Interface for all the methods of encapsulating Econet packets 
 *
 * @author John Brown <john@home-lan.co.uk>
 * @package core
*/
namespace HomeLan\FileStore\Encapsulation; 

/**
 * This is the interface all encapsulations must implelement 
 *
 * @package core
*/
interface EncapsulationInterface {

        public function getPort(): int;

        public function getPacketType(): string;

        /**
         * The AUN-style sequence number this packet carries, echoed back by an Ack so
         * ServiceDispatcher::ackEvents() can tell which outstanding request it belongs to.
         * Null for encapsulations with no such concept (real hardware Econet, or a bridge
         * peer not carrying one) — those fall back to (network,station)-only matching.
        */
        public function getSequence(): ?int;

        public function getData(): string;

        public function decode(string $sBinaryString): void;

        public function buildEconetPacket(): \HomeLan\FileStore\Messages\EconetPacket;

        public function asString(): string;

} 
