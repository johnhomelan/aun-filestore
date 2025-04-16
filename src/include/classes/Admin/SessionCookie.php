<?php

declare(strict_types=1);

namespace HomeLan\FileStore\Admin; 

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Session\Session as SessionSystem;

final readonly class SessionCookie implements EventSubscriberInterface{

	private const SESSION_NAME = 'PHPSESSID';

	public function start(RequestEvent $oRequestEvent): void
	{
	        if (!$oRequestEvent->isMainRequest()) {
        	    return;
       		 }
		$oRequest = $oRequestEvent->getRequest();

		$sSessionName = session_name();
		if(is_null($sSessionName)){
			$sSessionName = self::SESSION_NAME;
			session_name($sSessionName);
		}
	
		if(is_null($oRequest->cookies->get($sSessionName))){
			//No session cookie is set, so we need to redirect and set the cookie
			$oRedirect = new RedirectResponse($oRequest->getPathInfo());
			$oRedirect->headers->setCookie(Cookie::create($sSessionName,session_create_id()));
			$oRequestEvent->setResponse($oRedirect);
			
		}
	}
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['start', 1],
        ];
    }

}
