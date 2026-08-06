<?php
namespace HomeLan\FileStore\Admin\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use HomeLan\FileStore\Admin\Service\Smarty;
use HomeLan\FileStore\Services\ServiceDispatcher;
use HomeLan\FileStore\Services\Provider\Torchnet;

class TorchnetController extends AbstractController
{
    public function browse(Smarty $oSmartyService, Request $oRequest): Response
    {
        $oServices = ServiceDispatcher::create();
        $oService  = $oServices->getServiceByPort((int) $oRequest->query->get('port'));

        if (!($oService instanceof Torchnet)) {
            $oSmarty = $oSmartyService->getSmarty();
            $oSmarty->assign('error', 'TorchNet service not found on port ' . (int) $oRequest->query->get('port'));
            return new Response($oSmarty->fetch('error.tpl'), 404);
        }

        $iPort   = (int) $oRequest->query->get('port');
        $sPath   = (string) $oRequest->query->get('path', '');
        $aDrives = $oService->getConfiguredDrives();

        $oSmarty = $oSmartyService->getSmarty();
        $oSmarty->assign('oService', $oService);
        $oSmarty->assign('iPort', $iPort);
        $oSmarty->assign('aDrives', $aDrives);

        if ($sPath === '') {
            $oSmarty->assign('sCurrentPath', '');
            $oSmarty->assign('aBreadcrumbs', [['label' => 'Drives', 'path' => null]]);
            $oSmarty->assign('aEntries', []);
        } else {
            if (!$this->_isValidPath($sPath, $aDrives)) {
                $oSmarty->assign('error', 'Path is outside configured drives');
                return new Response($oSmarty->fetch('error.tpl'), 400);
            }

            $oSmarty->assign('sCurrentPath', $sPath);
            $oSmarty->assign('aBreadcrumbs', $this->_buildBreadcrumbs($sPath, $aDrives));
            $oSmarty->assign('aEntries', $oService->getAdminDirectoryListing($sPath));
        }

        return new Response($oSmarty->fetch('torchnet-browse.tpl'));
    }

    public function fileDownload(Request $oRequest): Response
    {
        $oServices = ServiceDispatcher::create();
        $oService  = $oServices->getServiceByPort((int) $oRequest->query->get('port'));

        if (!($oService instanceof Torchnet)) {
            return new Response('TorchNet service not found', 404);
        }

        $sPath = (string) $oRequest->query->get('path', '');
        if ($sPath === '') {
            return new Response('No path specified', 400);
        }

        $aDrives = $oService->getConfiguredDrives();
        if (!$this->_isValidPath($sPath, $aDrives)) {
            return new Response('Path is outside configured drives', 400);
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

    private function _isValidPath(string $sPath, array $aDrives): bool
    {
        foreach ($aDrives as $sDrivePath) {
            if ($sPath === $sDrivePath || str_starts_with($sPath, $sDrivePath . '.')) {
                return true;
            }
        }
        return false;
    }

    private function _buildBreadcrumbs(string $sPath, array $aDrives): array
    {
        $aBreadcrumbs = [['label' => 'Drives', 'path' => '']];

        $sDriveLetter = null;
        $sDriveRoot   = null;
        foreach ($aDrives as $sLetter => $sDrivePath) {
            if ($sPath === $sDrivePath || str_starts_with($sPath, $sDrivePath . '.')) {
                $sDriveLetter = $sLetter;
                $sDriveRoot   = $sDrivePath;
                break;
            }
        }

        if ($sDriveLetter === null) {
            return $aBreadcrumbs;
        }

        if ($sPath === $sDriveRoot) {
            $aBreadcrumbs[] = ['label' => 'Drive ' . $sDriveLetter, 'path' => null];
            return $aBreadcrumbs;
        }

        $aBreadcrumbs[] = ['label' => 'Drive ' . $sDriveLetter, 'path' => $sDriveRoot];

        $sRelative = substr($sPath, strlen($sDriveRoot) + 1);
        $aParts    = explode('.', $sRelative);
        $sCumPath  = $sDriveRoot;

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
