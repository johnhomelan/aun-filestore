<?php

namespace HomeLan\FileStore\Admin; 

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\BundleInterface;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Symfony\Component\Routing\RouteCollection;

use HomeLan\FileStore\Services\ServiceDispatcher;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    private const CONFIG_EXTS = '.{php,xml,yaml,yml}';
    private string $projectDir = __DIR__;

    public function getProjectDir(): string
    {
	return $this->projectDir;
    }

    /** @return iterable<BundleInterface> */
    public function registerBundles(): iterable
    {
        $contents = require $this->getProjectDir().'/config/bundles.php';
        if (!is_array($contents)) {
            return;
        }
        foreach ($contents as $class => $envs) {
            if (!is_string($class) || !is_array($envs)) {
                continue;
            }
            if ($envs[$this->environment] ?? $envs['all'] ?? false) {
                $oBundle = new $class();
                if (!($oBundle instanceof BundleInterface)) {
                    throw new \RuntimeException($class.' is not a valid Symfony bundle');
                }
                yield $oBundle;
            }
        }
    }

    public function getCacheDir(): string
    {
	    return $this->getProjectDir().'/../../../var/cache';
    }

    public function getLogDir(): string
    {
	    return $this->getProjectDir().'/../../../var/log';
    }

    protected function configureContainer(ContainerBuilder $container, LoaderInterface $loader): void
    {
        $container->addResource(new FileResource($this->getProjectDir().'/config/bundles.php'));
        $container->setParameter('container.dumper.inline_class_loader', true);
        $confDir = $this->getProjectDir().'/config';

        $loader->load($confDir.'/{packages}/*'.self::CONFIG_EXTS, 'glob');
        $loader->load($confDir.'/{packages}/'.$this->environment.'/**/*'.self::CONFIG_EXTS, 'glob');
        $loader->load($confDir.'/{services}'.self::CONFIG_EXTS, 'glob');
        $loader->load($confDir.'/{services}_'.$this->environment.self::CONFIG_EXTS, 'glob');
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
	$routes->import($this->getProjectDir().'/config/routes.yaml','yaml');
    }
}
