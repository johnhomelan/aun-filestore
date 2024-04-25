<?php
/**
 * This file contains the aun handler class 
 *
 * @author John Brown <john@home-lan.co.uk>
 * @package corenet
*/
namespace HomeLan\FileStore\Aun; 

use HomeLan\FileStore\Messages\EconetPacket; 
use React\Datagram\Socket;


Interface HandleInterface {


	public function setSocket(Socket $oAunServer):void;

	public function onClose():void;

	public function receive(string $sMessage, string $sSrcAddress, string $sDstAddress):void;

	public function timer():void;

	public function send(EconetPacket $oPacket, int $iRetries = 3):void;

}
