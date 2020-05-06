<?php
namespace HomeLan\FileStore\Admin\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;

use HomeLan\FileStore\Admin\Service\Smarty;
use HomeLan\FileStore\Services\ServiceDispatcher;

class ServiceController extends AbstractController 
{

	public function index(Smarty $oSmartyService, Request $oRequest): \Symfony\Component\HttpFoundation\Response
	{
		$oServices = ServiceDispatcher::create();

		$oSmarty = $oSmartyService->getSmarty();

		$oService = $oServices->getServiceByPort((int) $oRequest->query->get('port'));
		if(!is_object($oService)){	
			$oSmarty->assign('error',"There was no service on port ".$oRequest->query->get('port'));
			return new Response($oSmarty->fetch('error.tpl'));
		}
		$oSmarty->assign('oService',$oService);
		$oSmarty->assign('oAdmin',$oService->getAdminInterface());
		try{
			$sHtml = $oSmarty->fetch('service.tpl');
		}catch(\Throwable $oException){
			$sHtml = $oException->getMessage();
		}
		return new Response($sHtml);

	}

}
