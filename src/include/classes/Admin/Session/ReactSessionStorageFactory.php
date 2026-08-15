<?php

namespace HomeLan\FileStore\Admin\Session; 

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Storage\Proxy\AbstractProxy;
use Symfony\Component\HttpFoundation\Session\Storage\SessionStorageFactoryInterface;
use Symfony\Component\HttpFoundation\Session\Storage\SessionStorageInterface;
use Symfony\Component\HttpFoundation\Session\Storage\MetadataBag;

class_exists(ReactSessionStorage::class);

class ReactSessionStorageFactory implements SessionStorageFactoryInterface
{
    /**
     * @see NativeSessionStorage constructor.
     * @param array<string,mixed> $options
     */
    public function __construct(
        private array $options = [],
        private AbstractProxy|\SessionHandlerInterface|null $handler = null,
        private ?MetadataBag $metaBag = null,
        private bool $secure = false,
    ) {
    }

    public function createStorage(?Request $request): SessionStorageInterface
    {
        $storage = new ReactSessionStorage($this->options, $this->handler, $this->metaBag);
	$storage->setRequest($request);
        if ($this->secure && $request?->isSecure()) {
            $storage->setOptions(['cookie_secure' => true]);
        }

        return $storage;
    }
}
