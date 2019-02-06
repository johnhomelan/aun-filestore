<?php

/*
 * @group unit-tests
*/
use HomeLan\FileStore\Aun\Messages\AunPacket;
use PHPUnit\Framework\TestCase;

include_once('include/system.inc.php');

class aunpacketTest extends TestCase {


	public function testGetAndSetMethods()
	{
		//Set type unicast
		$sBinaryPacket = pack('C',2);

		//Set port 0x99
		$sBinaryPacket = $sBinaryPacket . pack('C',0x99);
		$oPacket = new AunPacket();

		//Set flags 0
		$sBinaryPacket = $sBinaryPacket . pack('C',0);

		//Set pad 0
		$sBinaryPacket = $sBinaryPacket . pack('C',0);

		//Sequence 4
		$sBinaryPacket = $sBinaryPacket . pack('V',4);

		//Data 
		$sBinaryPacket = $sBinaryPacket . pack('CC',0x90,0);

		$oPacket->decode($sBinaryPacket);
		
		//Check header
		$this->assertEquals($oPacket->getPort(),0x99);
		$this->assertEquals($oPacket->getPortName(),'FileServerCommand');
		$this->assertEquals($oPacket->getPacketType(),'Unicast');

		//Check data 
		$sBinaryData = $oPacket->getData();
		$aBinaryArray = unpack('C*',$sBinaryData);
		$this->assertEquals($aBinaryArray[1],0x90);
		$this->assertEquals($aBinaryArray[2],0);

		//Check we build acks
		$sAck = $oPacket->buildAck();
		$aAck = unpack('C*',$sAck);
		$this->assertEquals($aAck[1],3);
		
		

		//Check IP stuff
		$oPacket->setDestinationIP('192.168.0.1');
		$oPacket->setSourceIP('192.168.0.2');
		$this->assertEquals($oPacket->getSourceIP(),'192.168.0.2');
		$this->assertEquals($oPacket->getDestinationIP(),'192.168.0.1');

	
	}

}

