<?php

declare(strict_types=1);

/*
 * Native admin HTTP dispatcher for the TypePHP `sharefsd` build - sharefsd's own
 * (small) Symfony admin micro-app (ShareFs\Admin\Kernel), same treatment as
 * filestored's (packaging/typephp/admin/dispatcher.php, PORTING-REACT.md
 * "Stage 10d"). 4 static routes, 2 controllers.
 *
 * Reuses, unchanged: the compile-only Smarty shim (shims/smarty_runtime.php -
 * incl. its ShareFs\Admin\Service\Smarty), the HttpFoundation shim
 * (shims/symfony_httpfoundation.php), and the build-time template transform
 * (build-admin-templates.php -> stage/sharefs-admin-templates/). The 2
 * controllers are compiled from stage/sharefs-admin-ctl/ (a build-time copy with
 * `extends AbstractController` stripped).
 */

namespace HomeLan\FileStore\ShareFs\Admin\Native {

    use HomeLan\FileStore\ShareFs\Admin\Controller\ComponentController;
    use HomeLan\FileStore\ShareFs\Admin\Controller\IndexController;
    use HomeLan\FileStore\ShareFs\Admin\Service\Smarty;
    use Psr\Http\Message\ServerRequestInterface;
    use React\Http\Message\Response as ReactResponse;
    use Symfony\Component\HttpFoundation\Request as SfRequest;
    use Symfony\Component\HttpFoundation\Response as SfResponse;

    final class Dispatcher
    {
        public static function handle(ServerRequestInterface $oPsr): ReactResponse
        {
            $sPath = \ltrim($oPsr->getUri()->getPath(), '/');

            try {
                $oResp = self::route($sPath, $oPsr);
            } catch (\Throwable $oError) {
                $oResp = new SfResponse(
                    "sharefsd admin: internal error handling /{$sPath}: " . $oError->getMessage(),
                    500,
                );
            }

            return new ReactResponse(
                $oResp->getStatusCode(),
                self::reactHeaders($oResp->headers->all()),
                (string) $oResp->getContent(),
            );
        }

        /**
         * Symfony ResponseHeaderBag::all() is [name => list<?string>]; React's
         * Response rejects null values and prefers a scalar for single-valued
         * headers.
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
                case 'kube/live':
                case 'kube/ready':
                    return (new IndexController())->kube();
                case 'component':
                    return (new ComponentController())->index($oSmarty, self::makeRequest($sPath, $oPsr));
                default:
                    return new SfResponse("Page \"/{$sPath}\" not found.", 404);
            }
        }
    }
}
