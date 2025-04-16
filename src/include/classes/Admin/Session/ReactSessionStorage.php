<?php

namespace HomeLan\FileStore\Admin\Session; 

use Symfony\Component\HttpFoundation\Session\SessionBagInterface;
use Symfony\Component\HttpFoundation\Session\Storage\Handler\StrictSessionHandler;
use Symfony\Component\HttpFoundation\Session\Storage\Proxy\AbstractProxy;
use Symfony\Component\HttpFoundation\Session\Storage\Proxy\SessionHandlerProxy;
use Symfony\Component\HttpFoundation\Session\Storage\NativeSessionStorage;
use Symfony\Component\HttpFoundation\Session\Storage\SessionStorageInterface;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;

// Help opcache.preload discover always-needed symbols
class_exists(MetadataBag::class);
class_exists(StrictSessionHandler::class);
class_exists(SessionHandlerProxy::class);

/**
 * This provides a base class for session attribute storage.
 *
 * @author Drak <drak@zikula.org>
 */
class ReactSessionStorage extends NativeSessionStorage implements SessionStorageInterface
{

    static  array $SESSION = [];
    private Request $oRequest;
    private string $sessionId = "setme";

    public function start(): bool
    {
        if ($this->started) {
            return true;
        }

        $this->sessionId = $this->oRequest->cookies->get(session_name());
	if(array_key_exists($this->sessionId,self::$SESSION)){
		self::$SESSION[$this->sessionId]=[];
	}

        $this->loadSession();

        return true;
    }

    public function setRequest(?Request $oRequest)
    {
	$this->oRequest = $oRequest;
    }

    protected function loadSession(?array &$session = null): void
    {
	if(file_exists('/tmp/session-'.$this->sessionId.'.dat')){
		self::$SESSION[$this->sessionId] = unserialize(file_get_contents('/tmp/session-'.$this->sessionId.'.dat'));
	}
        if (null === $session) {
            $session = &self::$SESSION[$this->sessionId];
        }

        $bags = array_merge($this->bags, [$this->metadataBag]);

        foreach ($bags as $bag) {
            $key = $bag->getStorageKey();
            $session[$key] = isset($session[$key]) && \is_array($session[$key]) ? $session[$key] : [];
            $bag->initialize($session[$key]);
        }

        $this->started = true;
        $this->closed = false;
    }

    public function save(): void
    {
        $sSessionData = serialize(self::$SESSION[$this->sessionId]);
	self::$SESSION[$this->sessionId]=[];
	file_put_contents('/tmp/session-'.$this->sessionId.'.dat',$sSessionData);
        $this->closed = true;
        $this->started = false;
    }
    public function clear(): void
    {
        // clear out the bags
        foreach ($this->bags as $bag) {
            $bag->clear();
        }

        // clear out the session
        self::$SESSION[$this->sessionId] = [];

        // reconnect the bags to the session
        $this->loadSession();
    }

    public function getId(): string
    {
        return $this->sessionId;
    }

    public function setId(string $id): void
    {
    }

    public function getName(): string
    {
        return 'ReactSessionStorage';
    }

    public function setName(string $name): void
    {
    }

}
