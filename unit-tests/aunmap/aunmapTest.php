<?

/*
 * @group unit-tests
*/

include_once('include/system.inc.php');

class aunmapTest extends PHPUnit_Framework_TestCase {

	public function setup()
	{
		$sMapFile = "192.168.0.0/24 127\n192.168.0.40 127.254\n192.168.2.20 129.29\n192.168.1.0/24 128\n192.168.0.41\n192.168.2.0/24\n";
		aunmap::loadMap($sMapFile);
	}

	public function testLookUpByIp()
	{
		//Test subnet map
		$this->assertEquals(aunmap::lookUpByIP('192.168.0.1'),'127.1');
		$this->assertEquals(aunmap::lookUpByIP('192.168.0.2'),'127.2');
		$this->assertEquals(aunmap::lookUpByIP('192.168.1.5'),'128.5');
		$this->assertEquals(aunmap::lookUpByIP('192.168.1.55'),'128.55');

		//Test host map
		$this->assertEquals(aunmap::lookUpByIP('192.168.2.20'),'129.29');
	
		//Test host map overides subnet map
		$this->assertEquals(aunmap::lookUpByIP('192.168.0.40'),'127.254');
		
	}
}
?>
