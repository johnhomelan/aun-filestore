<?php
declare(strict_types=1);

/*
 * "attempt point 1": can the real symfony/http-foundation *Response* side
 * (Response / RedirectResponse / ResponseHeaderBag / HeaderBag / Cookie /
 * ParameterBag / InputBag) compile + run under tpc mode:bin, to replace the
 * shim in packaging/typephp/shims/symfony_httpfoundation.php?
 */

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\InputBag;

function main(int $argc, array $argv): void
{
    // plain Response, exactly as an admin controller builds one
    $oResp = new Response('<h1>hi</h1>', 200, ['Content-Type' => 'text/html; charset=utf-8']);
    $oResp->headers->set('X-Test', 'yes');
    $sBody   = $oResp->getContent();
    $iStatus = $oResp->getStatusCode();
    $aHeads  = $oResp->headers->all();

    // 404 with a plain body
    $o404 = new Response('nope', Response::HTTP_NOT_FOUND);

    // redirect (Location header set by the class)
    $oRedir = new RedirectResponse('/users?msg=created');
    $sLoc = $oRedir->headers->get('Location');
    $iRc  = $oRedir->getStatusCode();

    // InputBag as used by the dispatcher's Request
    $oIn = new InputBag(['port' => '8080', 'name' => 'fred']);
    $sPort = $oIn->get('port');
    $sMiss = $oIn->get('absent', 'dflt');
    $aAll  = $oIn->all();

    // Cookie (ResponseHeaderBag pulls it in)
    $oCookie = Cookie::create('sid', 'abc123');

    $bOk = $sBody === '<h1>hi</h1>'
        && $iStatus === 200
        && ($aHeads['x-test'][0] ?? null) === 'yes'
        && $o404->getStatusCode() === 404
        && $sLoc === '/users?msg=created'
        && $iRc === 302
        && $sPort === '8080'
        && $sMiss === 'dflt'
        && ($aAll['name'] ?? null) === 'fred'
        && $oCookie->getName() === 'sid';

    echo "status-line phrase for 404: " . Response::$statusTexts[404] . "\n";
    echo "redirect body:\n" . $oRedir->getContent() . "\n";
    echo ($bOk ? 'PASS' : 'FAIL') . "\n";
}
