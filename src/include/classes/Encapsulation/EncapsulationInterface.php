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

	public function getReplies(): array;

        public function getPort(): int;

        public function getPacketType(): string;

        public function getData(): string;

        public function decode(string $sBinaryString): void;

        public function buildEconetPacket(): \HomeLan\FileStore\Messages\EconetPacket;

        public function toString(): string;

} 
