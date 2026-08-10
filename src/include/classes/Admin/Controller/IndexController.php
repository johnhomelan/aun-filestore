<?php
namespace HomeLan\FileStore\Admin\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;

use HomeLan\FileStore\Admin\Service\Smarty;
use HomeLan\FileStore\Services\ServiceDispatcher;
use HomeLan\FileStore\Aun\Admin as AunAdmin;
use HomeLan\FileStore\WebSocket\Admin as WebSocketAdmin;
use HomeLan\FileStore\Piconet\Admin as PiconetAdmin;
use HomeLan\FileStore\RemoteBridge\Admin as RemoteBridgeAdmin;

class IndexController extends AbstractController 
{
	public function index(Smarty $oSmartyService): \Symfony\Component\HttpFoundation\Response
	{
		$oServices = ServiceDispatcher::create();
		$oSmarty = $oSmartyService->getSmarty();
		$oSmarty->assign('aServices',$oServices->getServices());
		$oSmarty->assign('aEncapsulations', [
			new AunAdmin(),
			new WebSocketAdmin(),
			new PiconetAdmin(),
			new RemoteBridgeAdmin(),
		]);
		return new Response($oSmarty->fetch('index.tpl'));
	}

	public function kube(): \Symfony\Component\HttpFoundation\Response
	{
		return new Response(
			    '',
			    Response::HTTP_OK,
			    ['content-type' => 'text/html']
		);
	}

	public function favicon():  \Symfony\Component\HttpFoundation\Response
	{
		return new Response(file_get_contents(__DIR__."/../static/favicon.ico"));
	}
}
