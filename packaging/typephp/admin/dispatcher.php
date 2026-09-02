<?php

declare(strict_types=1);

/*
 * Native admin HTTP dispatcher for the TypePHP build (see PORTING-REACT.md
 * "Stage 10d"). Replaces the Symfony HttpKernel + DI container + Routing stack -
 * none of which compile under tpc mode:bin (Stage 10b) - with:
 *
 *   - a fixed path -> controller/method switch (routes.yaml has 27 static
 *     paths, zero {placeholders}, so no matcher component is needed)
 *   - a react/http PSR-7 ServerRequest -> Symfony\...\Request shim adapter
 *   - a Symfony\...\Response shim -> react/http Response adapter
 *
 * The 8 Admin\Controller\* classes are compiled from build/typephp/stage/admin-ctl/
 * (a build-time copy with `extends AbstractController` stripped - they never call
 * an AbstractController helper). Their `Smarty` / `Request` / `Response`
 * type-hints resolve to the shims (packaging/typephp/shims/{smarty_runtime,
 * symfony_httpfoundation}.php).
 *
 * No session handling: the interpreted daemon's SessionCookie subscriber only
 * sets a PHPSESSID cookie that nothing reads - dropped here.
 */

namespace HomeLan\FileStore\Admin\Native {

    use HomeLan\FileStore\Admin\Controller\EncapsulationController;
    use HomeLan\FileStore\Admin\Controller\FileServerController;
    use HomeLan\FileStore\Admin\Controller\IndexController;
    use HomeLan\FileStore\Admin\Controller\MaceMailController;
    use HomeLan\FileStore\Admin\Controller\ServiceController;
    use HomeLan\FileStore\Admin\Controller\TeletextController;
    use HomeLan\FileStore\Admin\Controller\TorchnetController;
    use HomeLan\FileStore\Admin\Controller\UserController;
    use HomeLan\FileStore\Admin\Service\Smarty;
    use Psr\Http\Message\ServerRequestInterface;
    use React\Http\Message\Response as ReactResponse;
    use Symfony\Component\HttpFoundation\Request as SfRequest;
    use Symfony\Component\HttpFoundation\Response as SfResponse;

    final class Dispatcher
    {
        /** react/http request handler: PSR-7 in, react/http Response out. */
        public static function handle(ServerRequestInterface $oPsr): ReactResponse
        {
            $sPath = \ltrim($oPsr->getUri()->getPath(), '/');

            try {
                $oResp = self::route($sPath, $oPsr);
            } catch (\Throwable $oError) {
                $oResp = new SfResponse(
                    "Admin: internal error handling /{$sPath}: " . $oError->getMessage(),
                    500,
                );
            }

            $aHeaders = self::reactHeaders($oResp->headers->all());
            // IndexController::favicon() returns new Response($bytes) with no
            // content type - the real Response then defaults it to text/html.
            if ($sPath === 'favicon.ico') {
                $aHeaders['content-type'] = 'image/x-icon';
            }

            return new ReactResponse($oResp->getStatusCode(), $aHeaders, (string) $oResp->getContent());
        }

        /**
         * Symfony ResponseHeaderBag::all() is [name => list<?string>]; React's
         * Response rejects null values and prefers a scalar for single-valued
         * headers (cf. Command\React::adminService()).
         *
         * @param array<string, list<string|null>> $aAll
         * @return array<string, string|list<string>>
         */
        private static function reactHeaders(array $aAll): array
        {
            $aOut = [];
            foreach ($aAll as $sName => $aValues) {
                $aClean = \array_values(\array_filter($aValues, static fn ($m) => $m !== null));
                if ($aClean !== []) {
                    $aOut[$sName] = \count($aClean) === 1 ? $aClean[0] : $aClean;
                }
            }
            return $aOut;
        }

        private static function makeRequest(string $sPath, ServerRequestInterface $oPsr): SfRequest
        {
            $mQuery = $oPsr->getQueryParams();
            $mBody  = $oPsr->getParsedBody();
            return new SfRequest(
                \is_array($mQuery) ? $mQuery : [],
                \is_array($mBody) ? $mBody : [],
                $oPsr->getMethod(),
                '/' . $sPath,
            );
        }

        private static function route(string $sPath, ServerRequestInterface $oPsr): SfResponse
        {
            $oSmarty = new Smarty();

            switch ($sPath) {
                case '':
                    return (new IndexController())->index($oSmarty);
                case 'favicon.ico':
                    return (new IndexController())->favicon();
                case 'kube/live':
                case 'kube/ready':
                    return (new IndexController())->kube();

                case 'service':
                    return (new ServiceController())->index($oSmarty, self::makeRequest($sPath, $oPsr));
                case 'service/download':
                    return (new ServiceController())->download(self::makeRequest($sPath, $oPsr));

                case 'service/fileserver/browse':
                    return (new FileServerController())->browse($oSmarty, self::makeRequest($sPath, $oPsr));
                case 'service/fileserver/download':
                    return (new FileServerController())->fileDownload(self::makeRequest($sPath, $oPsr));

                case 'service/torchnet/browse':
                    return (new TorchnetController())->browse($oSmarty, self::makeRequest($sPath, $oPsr));
                case 'service/torchnet/download':
                    return (new TorchnetController())->fileDownload(self::makeRequest($sPath, $oPsr));

                case 'encapsulation':
                    return (new EncapsulationController())->index($oSmarty, self::makeRequest($sPath, $oPsr));

                case 'users':
                    return (new UserController())->index($oSmarty, self::makeRequest($sPath, $oPsr));
                case 'users/create':
                    return (new UserController())->create($oSmarty, self::makeRequest($sPath, $oPsr));
                case 'users/edit':
                    return (new UserController())->edit($oSmarty, self::makeRequest($sPath, $oPsr));
                case 'users/delete':
                    return (new UserController())->delete($oSmarty, self::makeRequest($sPath, $oPsr));
                case 'users/setpassword':
                    return (new UserController())->setPassword($oSmarty, self::makeRequest($sPath, $oPsr));

                case 'service/macemail/slots/assign':
                    return (new MaceMailController())->assignSlot($oSmarty, self::makeRequest($sPath, $oPsr));
                case 'service/macemail/slots/unassign':
                    return (new MaceMailController())->unassignSlot($oSmarty, self::makeRequest($sPath, $oPsr));
                case 'service/macemail/logoff':
                    return (new MaceMailController())->forceLogoff($oSmarty, self::makeRequest($sPath, $oPsr));
                case 'service/macemail/broadcast':
                    return (new MaceMailController())->broadcast($oSmarty, self::makeRequest($sPath, $oPsr));

                case 'service/teletext/teefax-refresh':
                    return (new TeletextController())->teefaxRefresh($oSmarty, self::makeRequest($sPath, $oPsr));
                case 'service/teletext/weather-refresh':
                    return (new TeletextController())->weatherRefresh($oSmarty, self::makeRequest($sPath, $oPsr));
                case 'service/teletext/news-refresh':
                    return (new TeletextController())->newsRefresh($oSmarty, self::makeRequest($sPath, $oPsr));
                case 'service/teletext/webfax-refresh':
                    return (new TeletextController())->webfaxRefresh($oSmarty, self::makeRequest($sPath, $oPsr));
                case 'service/teletext/tvguide-refresh':
                    return (new TeletextController())->tvGuideRefresh($oSmarty, self::makeRequest($sPath, $oPsr));
                case 'service/teletext/browse':
                    return (new TeletextController())->browse($oSmarty, self::makeRequest($sPath, $oPsr));
                case 'service/teletext/page-data':
                    return (new TeletextController())->pageData(self::makeRequest($sPath, $oPsr));
                case 'static/teletext-render.js':
                    return (new TeletextController())->renderScript();

                default:
                    return new SfResponse("Page \"/{$sPath}\" not found.", 404);
            }
        }
    }
}
