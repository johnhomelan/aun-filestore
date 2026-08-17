<?php

/*
 * @group unit-tests
*/
use HomeLan\FileStore\Aun\AunPacket;
use PHPUnit\Framework\TestCase;

include_once('include/system.inc.php');

class aunpacketTest extends TestCase {


	public function testGetAndSetMethods()
	{
		$oPacket = new AunPacket();
		$oPacket->setDestinationIP('192.168.0.1');
		$oPacket->setSourceIP('192.168.0.2');

		//Set type unicast
		$sBinaryPacket = pack('C',2);

		//Set port 0x99
		$sBinaryPacket = $sBinaryPacket . pack('C',0x99);

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
		$this->assertEquals($oPacket->getSourceIP(),'192.168.0.2');
		$this->assertEquals($oPacket->getDestinationIP(),'192.168.0.1');


	}

	public function testBuildAckImmediateMachineType():void
	{
		$oPacket = new AunPacket();
		$oPacket->setSourceIP('192.168.0.2');
		$oPacket->setDestinationIP('192.168.0.1');

		$sBinaryPacket  = pack('C', 5);  // type = Immediate
		$sBinaryPacket .= pack('C', 0);  // port = 0
		$sBinaryPacket .= pack('C', 0);  // cb = 0 (machine type query)
		$sBinaryPacket .= pack('C', 0);  // pad = 0
		$sBinaryPacket .= pack('V', 4);  // sequence = 4

		$oPacket->decode($sBinaryPacket);
		$sReply = $oPacket->buildAck();

		$this->assertNotNull($sReply);
		$aReply = unpack('C*', $sReply);
		$this->assertEquals(6,    $aReply[1]);  // ImmediateReply
		$this->assertEquals(0x80, $aReply[3]);  // flag echoes 0x80|cb, not a fixed 0
		$this->assertEquals(0x40, $aReply[9]);  // FS01 machine type
		$this->assertEquals(0x00, $aReply[10]);
		$this->assertEquals(0x00, $aReply[11]);
		$this->assertEquals(0x00, $aReply[12]);
	}

	public function testBuildAckImmediateOsVersion():void
	{
		config::overrideValue('version_major', 1);
		config::overrideValue('version_minor', 2);

		$oPacket = new AunPacket();
		$oPacket->setSourceIP('192.168.0.2');
		$oPacket->setDestinationIP('192.168.0.1');

		$sBinaryPacket  = pack('C', 5);  // type = Immediate
		$sBinaryPacket .= pack('C', 0);  // port = 0
		$sBinaryPacket .= pack('C', 1);  // cb = 1 (OS version query)
		$sBinaryPacket .= pack('C', 0);  // pad = 0
		$sBinaryPacket .= pack('V', 4);  // sequence = 4

		$oPacket->decode($sBinaryPacket);
		$sReply = $oPacket->buildAck();

		$this->assertNotNull($sReply);
		$aReply = unpack('C*', $sReply);
		$this->assertEquals(6, $aReply[1]);  // ImmediateReply
		$this->assertEquals(0x81, $aReply[3]); // flag echoes 0x80|cb, not a fixed 0
		$this->assertEquals(1, $aReply[9]);  // version_major
		$this->assertEquals(2, $aReply[10]); // version_minor
		$this->assertEquals(0, $aReply[11]);
		$this->assertEquals(0, $aReply[12]);

		config::resetValue('version_major');
		config::resetValue('version_minor');
	}

	public function testBuildAckImmediateEcho():void
	{
		$oPacket = new AunPacket();
		$oPacket->setSourceIP('192.168.0.2');
		$oPacket->setDestinationIP('192.168.0.1');

		$sBinaryPacket  = pack('C', 5);  // type = Immediate
		$sBinaryPacket .= pack('C', 0);  // port = 0
		$sBinaryPacket .= pack('C', 8);  // cb = 8 (echo request equiv / machine peek)
		$sBinaryPacket .= pack('C', 0);  // pad = 0
		$sBinaryPacket .= pack('V', 4);  // sequence = 4

		$oPacket->decode($sBinaryPacket);
		$sReply = $oPacket->buildAck();

		$this->assertNotNull($sReply);
		$aReply = unpack('C*', $sReply);
		$this->assertEquals(6,    $aReply[1]);  // ImmediateReply
		$this->assertEquals(0x88, $aReply[3]);  // flag echoes 0x80|cb, not a fixed 0
		$this->assertEquals(0x40, $aReply[9]);  // Peek Lo
		$this->assertEquals(0x66, $aReply[10]); // Peek Hi
	}

	public function testBuildAckImmediateUnknownCb():void
	{
		$oPacket = new AunPacket();
		$oPacket->setSourceIP('192.168.0.2');
		$oPacket->setDestinationIP('192.168.0.1');

		$sBinaryPacket  = pack('C', 5);   // type = Immediate
		$sBinaryPacket .= pack('C', 0);   // port = 0
		$sBinaryPacket .= pack('C', 99);  // cb = 99 (unknown operation)
		$sBinaryPacket .= pack('C', 0);   // pad = 0
		$sBinaryPacket .= pack('V', 4);   // sequence = 4

		$oPacket->decode($sBinaryPacket);
		$sReply = $oPacket->buildAck();

		$this->assertNull($sReply);
	}

}

