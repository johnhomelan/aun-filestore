<?php
/**
 * This file contains the Interface all service proviers must implement to get loaded 
 *
 * @author John Brown <john@home-lan.co.uk>
 * @package core
*/
namespace HomeLan\FileStore\Services; 

use HomeLan\FileStore\Services\ServiceDispatcher; 
use HomeLan\FileStore\Messages\EconetPacket; 

/**
 * This class is the interface all services must provide 
 *
 * @package core
*/
interface ServiceInterface {


	public function unicastPacketIn(EconetPacket $oPacket): void;

	public function broadcastPacketIn(EconetPacket $oPacket): void;

	public function getServicePorts(): array; 

	public function registerService(ServiceDispatcher $oServiceDispatcher): void;

	public function getReplies(): array;
} 
