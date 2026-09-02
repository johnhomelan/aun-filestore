<?php

declare(strict_types=1);

/*
 * Thin residue of the Symfony HttpFoundation shim for the TypePHP native admin
 * UI (see PORTING-REACT.md "Stage 10d").
 *
 * The Response side is now the REAL symfony/http-foundation, compiled:
 *   Response / RedirectResponse / ResponseHeaderBag / HeaderBag / Cookie /
 *   ParameterBag / InputBag / Exception\*  - listed in project{,.sharefsd}.yml,
 *   with two hand-patched copies (shims/symfony_response.php,
 *   shims/symfony_headerutils.php) for a file-scope preload hint, a PHP 8.4
 *   property hook, and two typed-local reassignments tpc rejects.
 *
 * Only two pieces stay shimmed:
 *   - Request  : the real one needs a PHP 8.4 property hook, request_parse_body(),
 *     and the File\ / Session\ subtrees - all dead weight for an input carrier
 *     the dispatcher populates itself. This is just query/request InputBags +
 *     getMethod()/getPathInfo(), which is all the Admin controllers touch.
 *   - BinaryFileResponse : the real one streams (getContent() returns false);
 *     the dispatcher needs the bytes in hand, so this subclass of the real
 *     Response slurps the file. Used once (ServiceController::download).
 */

namespace Symfony\Component\HttpFoundation {

    class Request
    {
        public InputBag $query;
        public InputBag $request;

        /**
         * @param array<string, mixed> $aQuery parsed query string
         * @param array<string, mixed> $aBody  parsed form body
         */
        public function __construct(
            array $aQuery = [],
            array $aBody = [],
            private string $sMethod = 'GET',
            private string $sPath = '/',
        ) {
            $this->query = new InputBag($aQuery);
            $this->request = new InputBag($aBody);
        }

        public function getMethod(): string
        {
            return \strtoupper($this->sMethod);
        }

        public function getPathInfo(): string
        {
            return $this->sPath;
        }
    }

    class BinaryFileResponse extends Response
    {
        public function __construct(string $sPath, int $iStatus = 200, array $aHeaders = [])
        {
            $sData = \is_file($sPath) ? \file_get_contents($sPath) : false;
            if ($sData === false) {
                parent::__construct('File not found', 404, []);
                return;
            }
            $aHeaders['Content-Type'] = 'application/octet-stream';
            $aHeaders['Content-Disposition'] = 'attachment; filename="' . \basename($sPath) . '"';
            parent::__construct($sData, $iStatus, $aHeaders);
        }
    }
}
