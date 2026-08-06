<?php
namespace HomeLan\FileStore\Admin\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use HomeLan\FileStore\Admin\Service\Smarty;
use HomeLan\FileStore\Services\ServiceDispatcher;
use HomeLan\FileStore\Services\Provider\FileServer;

class FileServerController extends AbstractController
{
    public function browse(Smarty $oSmartyService, Request $oRequest): Response
    {
        $oServices = ServiceDispatcher::create();
        $oService  = $oServices->getServiceByPort((int) $oRequest->query->get('port'));

        if (!($oService instanceof FileServer)) {
            $oSmarty = $oSmartyService->getSmarty();
            $oSmarty->assign('error', 'FileServer service not found on port ' . (int) $oRequest->query->get('port'));
            return new Response($oSmarty->fetch('error.tpl'), 404);
        }

        $iPort = (int) $oRequest->query->get('port');
        $sPath = (string) $oRequest->query->get('path', '$');

        if (!str_starts_with($sPath, '$')) {
            $sPath = '$';
        }

        $oSmarty = $oSmartyService->getSmarty();
        $oSmarty->assign('oService', $oService);
        $oSmarty->assign('iPort', $iPort);
        $oSmarty->assign('sCurrentPath', $sPath);
        $oSmarty->assign('aBreadcrumbs', $this->_buildBreadcrumbs($sPath));
        $oSmarty->assign('aEntries', $oService->getAdminDirectoryListing($sPath));

        return new Response($oSmarty->fetch('fileserver-browse.tpl'));
    }

    public function fileDownload(Request $oRequest): Response
    {
        $oServices = ServiceDispatcher::create();
        $oService  = $oServices->getServiceByPort((int) $oRequest->query->get('port'));

        if (!($oService instanceof FileServer)) {
            return new Response('FileServer service not found', 404);
        }

        $sPath = (string) $oRequest->query->get('path', '');
        if ($sPath === '' || !str_starts_with($sPath, '$')) {
            return new Response('Invalid path', 400);
        }

        $sData = $oService->getAdminFileContents($sPath);
        if ($sData === null) {
            return new Response('File not found', 404);
        }

        $aPathParts = explode('.', $sPath);
        $sFilename  = end($aPathParts);

        $oResponse = new Response($sData);
        $oResponse->headers->set('Content-Type', 'application/octet-stream');
        $oResponse->headers->set('Content-Disposition', 'attachment; filename="' . $sFilename . '"');
        return $oResponse;
    }

    private function _buildBreadcrumbs(string $sPath): array
    {
        if ($sPath === '$') {
            return [['label' => '$', 'path' => null]];
        }

        $aBreadcrumbs = [['label' => '$', 'path' => '$']];

        // Strip leading '$.' and split the rest
        $sRelative = substr($sPath, 2);
        $aParts    = explode('.', $sRelative);
        $sCumPath  = '$';

        foreach ($aParts as $i => $sPart) {
            $sCumPath .= '.' . $sPart;
            $aBreadcrumbs[] = [
                'label' => $sPart,
                'path'  => ($i === count($aParts) - 1) ? null : $sCumPath,
            ];
        }

        return $aBreadcrumbs;
    }
}
