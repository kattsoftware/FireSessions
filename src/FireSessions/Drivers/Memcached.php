<?php

namespace FireSessions\Drivers;

use FireSessions\BaseSessionDriver;

/**
 * Memcached session driver
 *
 * This content is released under the MIT License (MIT).
 * @see LICENSE file
 */
class Memcached extends BaseSessionDriver
{
    /**
     * @var \Memcached The server instance
     */
    private $memcached;

    /**
     * @var string The Memcached key prefix; will be set to $this->config['cookie_name'] . ':'
     */
    private $keyPrefix = '';

    /**
     * @var string Saved locking specific key for further comparisons
     */
    private $lockKey;

    /**
     * @var bool Whether the lock was acquired or not
     */
    private $lockAcquired = false;

    /**
     * Memcached driver constructor.
     *
     * @param array $config
     * @param mixed $trueValue
     * @param mixed $falseValue
     */
    public function __construct(array $config, $trueValue, $falseValue)
    {
        parent::__construct($config, $trueValue, $falseValue);

        if (empty($this->config['save_path'])) {
            trigger_error(__CLASS__ . ': No or invalid "save_path" setting provided.', E_USER_ERROR);

            return;
        }

        $this->keyPrefix .= $this->config['cookie_name'] . ':';

        if ($this->config['match_ip'] === true) {
            $this->keyPrefix .= $_SERVER['REMOTE_ADDR'] . ':';
        }
    }

    /**
     * Initialize the session
     *
     * @link http://php.net/manual/en/sessionhandlerinterface.open.php
     * @param string $savePath The path where to store/retrieve the session.
     * @param string $name The session name.
     * @return bool
     */
    public function open($savePath, $name)
    {
        $this->memcached = new \Memcached();

        // required for touch-ing
        $this->memcached->setOption(\Memcached::OPT_BINARY_PROTOCOL, true);
        $serverList = array();

        if (!isset($this->config['fetch_pool_servers'])
            || (bool)$this->config['fetch_pool_servers'] === true
        ) {
            foreach ($this->memcached->getServerList() as $server) {
                $serverList[] = array(
                    'host'   => $server['host'],
                    'port'   => $server['port'],
                    'weight' => 0
                );
            }
        }

        foreach (explode(',', $this->config['save_path']) as $server) {
            $parts = explode(':', $server);

            if (!isset($parts[0], $parts[1])) {
                trigger_error(__CLASS__ . ': Invalid server: ' . $server, E_USER_ERROR);
                $this->memcached = null;

                return self::false();
            }

            $serverList[] = array(
                'host'   => $parts[0],
                'port'   => $parts[1],
                'weight' => isset($parts[2]) ? $parts[2] : 0
            );
        }

        foreach ($serverList as $key => $server) {
            if (!$this->memcached->addServer($server['host'], $server['port'], $server['weight'])) {
                trigger_error(__CLASS__ . ': Cannot add ' . var_export($server, true) . ' as server.', E_USER_ERROR);
                unset($serverList[$key]);
            }
        }

        if ($serverList === array()) {
            trigger_error(__CLASS__ . ': There is no server in the pool.', E_USER_ERROR);

            return self::false();
        }

        return self::true();
    }

    /**
     * Reads the session data
     *
     * @link http://php.net/manual/en/sessionhandlerinterface.read.php
     * @param string $sessionId The session id to read data for.
     * @return string Returns an encoded string of the read data.
     * If nothing was read, it must return an empty string.
     */
    public function read($sessionId)
    {
        if ($this->memcached !== null && $this->acquireLock($sessionId)) {
            // write() would detect session_regenerate_id() calls
            $this->initialSessionId = $sessionId;

            $sessionData = (string)$this->memcached->get($this->keyPrefix . $sessionId);
            $this->sessionDataChecksum = md5($sessionData);

            return $sessionData;
        }

        return self::false();
    }

    /**
     * Writes the session data
     *
     * @link http://php.net/manual/en/sessionhandlerinterface.write.php
     * @param string $sessionId The session id.
     * @param string $sessionData  The encoded session data.
     * @return bool
     */
    public function write($sessionId, $sessionData)
    {
        if ($this->memcached === null || $this->lockKey === null) {
            return self::false();
        }

        // Was the ID regenerated?
        if ($sessionId !== $this->initialSessionId) {
            if (!$this->releaseLock() || !$this->acquireLock($sessionId)) {
                return self::false();
            }

            $this->initialSessionId = md5('');
            $this->initialSessionId = $sessionId;
        }

        $key = $this->keyPrefix.$sessionId;

        $this->memcached->replace($this->lockKey, 1, 300);
        $dataChecksum = md5($sessionData);

        if ($this->sessionDataChecksum !== $dataChecksum) {
            if ($this->memcached->set($key, $sessionData, $this->config['expiration'])) {
                $this->sessionDataChecksum = $dataChecksum;

                return self::true();
            }

            return self::false();
        } elseif (
            $this->memcached->touch($key, $this->config['expiration'])
            || ($this->memcached->getResultCode() === \Memcached::RES_NOTFOUND
                && $this->memcached->set($key, $sessionData, $this->config['expiration']))
        ) {
            return self::true();
        }

        return self::false();
    }

    /**
     * Closes the session
     *
     * @link http://php.net/manual/en/sessionhandlerinterface.close.php
     * @return bool
     */
    public function close()
    {
        if ($this->memcached !== null) {
            $this->releaseLock();
            if (!$this->memcached->quit()) {
                return self::false();
            }

            $this->memcached = null;

            return self::true();
        }

        return self::true();
    }

    /**
     * Destroys a session
     *
     * @link http://php.net/manual/en/sessionhandlerinterface.destroy.php
     * @param string $sessionId The session ID being destroyed.
     * @return bool
     */
    public function destroy($sessionId)
    {
        if ($this->memcached !== null && $this->lockKey !== null) {
            $this->memcached->delete($this->keyPrefix . $sessionId);
            $this->destroyCookie();

            return self::true();
        }

        return self::false();
    }

    /**
     * Cleanup old sessions
     *
     * @link http://php.net/manual/en/sessionhandlerinterface.gc.php
     * @param int $maxlifetime
     * @return bool
     */
    public function gc($maxlifetime)
    {
        // Not necessary for Memcached :)
        return self::true();
    }

    /**
     * Lock acquiring for this implementation.
     *
     * @param string $sessionId If required, this can be the session ID
     * @return bool if the locking succeeded or not
     */
    protected function acquireLock($sessionId = null)
    {
        $newLockKey = $this->keyPrefix . $sessionId . ':lock';

        // PHP 7 reuses the SessionHandler object on regeneration,
        // so we need to check here if the lock key is for the
        // correct session ID.
        if ($this->lockKey === $newLockKey) {
            if (!$this->memcached->replace($this->lockKey, 1, 300)) {
                return ($this->memcached->getResultCode() === \Memcached::RES_NOTFOUND)
                    ? $this->memcached->set($this->lockKey, 1, 300)
                    : false;
            }
        }

        // 30 attempts to obtain a lock (in case another request already has it)
        for ($attempt = 0; $attempt < 30; $attempt++) {
            if ($this->memcached->get($newLockKey)) {
                sleep(1);
                continue;
            }

            if (!$this->memcached->set($newLockKey, 1, 300)) {
                trigger_error(__CLASS__ . ': Cannot acquire the lock ' . $newLockKey, E_USER_ERROR);

                return false;
            }

            $this->lockKey = $newLockKey;
            break;
        }

        if ($attempt === 30) {
            trigger_error(__CLASS__ . ': Cannot acquire  the lock ' . $newLockKey . ' after 30 attempts.',
                E_USER_ERROR);

            return false;
        }

        $this->lockAcquired = true;

        return true;
    }

    /**
     * Releases the obtained lock over a session instance.
     *
     * @return true whether the unlocking succeeded or not
     */
    protected function releaseLock()
    {
        if ($this->memcached !== null && $this->lockKey !== null && $this->lockAcquired) {
            if (!$this->memcached->delete($this->lockKey) && $this->memcached->getResultCode() !== \Memcached::RES_NOTFOUND) {
                trigger_error(__CLASS__ . ': Cannot free the lock ' . $this->lockKey);

                return false;
            }

            $this->lockKey = null;
            $this->lockAcquired = false;
        }

        return true;
    }
}
