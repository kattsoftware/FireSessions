<?php

namespace FireSessions\Drivers;

use FireSessions\BaseSessionDriver;

/**
 * Redis session driver
 *
 * This content is released under the MIT License (MIT).
 * @see LICENSE file
 */
class Redis extends BaseSessionDriver
{
    /**
     * @var \Redis The Redis instance
     */
    private $redis;

    /**
     * @var string The Redis key prefix; if not set, will default to $this->config['cookie_name'] . ':'
     */
    private $keyPrefix;

    /**
     * @var string Saved locking specific key for further comparisons
     */
    private $lockKey;

    /**
     * @var bool Whether the lock was acquired or not
     */
    private $lockAcquired = false;

    /**
     * @var bool Whether a previous session was found or not
     */
    private $hasKey = false;

    /**
     * Redis driver constructor.
     *
     * @param array $config
     */
    public function __construct(array $config)
    {
        parent::__construct($config);

        if (empty($this->config['save_path'])) {
            trigger_error(__CLASS__ . ': No or invalid "save_path" setting found.', E_USER_ERROR);

            return;
        }

        $savePathSettings = explode(',', $this->config['save_path']);
        $this->config['save_path'] = array();
        foreach ($savePathSettings as $savePathSetting) {
            $setting = explode('=', $savePathSetting);
            isset($setting[1]) || $setting[1] = null;
            $this->config['save_path'][$setting[0]] = $setting[1];
        }

        if (!isset($this->config['save_path']['host'])) {
            trigger_error(__CLASS__ . ': No or invalid "host" setting in the "save_path" config.', E_USER_ERROR);

            return;
        }

        isset($this->config['save_path']['port']) || ($this->config['save_path']['port'] = null);
        isset($this->config['save_path']['password']) || ($this->config['save_path']['password'] = null);
        isset($this->config['save_path']['database']) || ($this->config['save_path']['database'] = null);
        isset($this->config['save_path']['timeout']) || ($this->config['save_path']['timeout'] = null);

        if (isset($this->config['save_path']['prefix'])) {
            $this->keyPrefix = $this->config['save_path']['prefix'];
        } else {
            $this->keyPrefix = $this->config['cookie_name'] . ':';
        }

        if ($this->config['match_ip'] === true) {
            $this->keyPrefix .= $this->getIp() . ':';
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
        $redis = $this->instantiateRedis();

        if (!$redis->connect(
            $this->config['save_path']['host'],
            $this->config['save_path']['port'],
            $this->config['save_path']['timeout'])
        ) {
            trigger_error(__CLASS__ . ': Unable to establish a Redis connection with provided settings.', E_USER_ERROR);

            return self::false();
        }

        if (isset($this->config['save_path']['password']) && !$redis->auth($this->config['save_path']['password'])) {
            trigger_error(__CLASS__ . ': Unable to authenticate with the provided password.', E_USER_ERROR);

            return self::false();
        }

        if (isset($this->config['save_path']['database']) && !$redis->select($this->config['save_path']['database'])) {
            trigger_error(
                __CLASS__ . ': Unable to switch to provided Redis database: ' . $this->config['save_path']['database'],
                E_USER_ERROR
            );

            return self::false();
        }

        return self::true();
    }

    /**
     * Read the session data
     *
     * @link http://php.net/manual/en/sessionhandlerinterface.read.php
     * @param string $sessionId The session id to read data for.
     * @return string Returns an encoded string of the read data.
     * If nothing was read, it must return an empty string.
     */
    public function read($sessionId)
    {
        if ($this->redis !== null && $this->acquireLock($sessionId)) {
            $key = $this->keyPrefix . $sessionId;

            // write() could detect session_regenerate_id() calls
            $this->initialSessionId = $sessionId;

            $sessionData = $this->redis->get($key);

            if (is_string($sessionData)) {
                $this->hasKey = true;
            } else {
                $sessionData = '';
            }

            $this->sessionDataChecksum = md5($sessionData);

            return $sessionData;
        }

        return self::false();
    }

    /**
     * Write session data
     *
     * @link http://php.net/manual/en/sessionhandlerinterface.write.php
     * @param string $sessionId The session id.
     * @param string $sessionData The encoded session data.
     * @return bool
     */
    public function write($sessionId, $sessionData)
    {
        if ($this->redis === null || $this->lockKey === null) {
            return self::false();
        }

        // Was the ID regenerated?
        if ($sessionId !== $this->initialSessionId) {
            if (!$this->releaseLock() || !$this->acquireLock($sessionId)) {
                return self::false();
            }

            $this->hasKey = false;
            $this->initialSessionId = $sessionId;
        }

        $this->redis->setTimeout($this->lockKey, 300);
        $newDataChecksum = md5($sessionData);
        $newKey = $this->keyPrefix . $sessionId;

        if ($this->sessionDataChecksum !== $newDataChecksum || $this->hasKey === false) {
            if ($this->redis->set($newKey, $sessionData, $this->config['expiration'])) {
                $this->sessionDataChecksum = $newDataChecksum;
                $this->hasKey = true;
                return self::true();
            }

            return self::false();
        }

        return ($this->redis->setTimeout($newKey, $this->config['expiration']))
            ? self::true()
            : self::false();
    }

    /**
     * Close the session
     *
     * @link http://php.net/manual/en/sessionhandlerinterface.close.php
     * @return bool
     */
    public function close()
    {
        if ($this->redis !== null) {
            try {
                if ($this->redis->ping() === '+PONG') {
                    if (!$this->releaseLock() || $this->redis->close() === false) {
                        return self::false();
                    }
                }
            } catch (\RedisException $e) {
                trigger_error(__CLASS__ . ': RedisException encountered: ' . $e);
            }

            $this->redis = null;

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
        if ($this->redis !== null && $this->lockKey !== null) {
            $result = $this->redis->delete($this->keyPrefix . $sessionId);
            if ($result !== 1) {
                trigger_error(__CLASS__ . ': Redis::delete() returned ' . var_export($result, true) . ', instead of 1.');
            }

            $this->destroyCookie();

            return self::true();
        }

        return self::false();
    }

    /**
     * Cleans up old sessions
     *
     * @link http://php.net/manual/en/sessionhandlerinterface.gc.php
     * @param int $maxlifetime
     * @return bool
     */
    public function gc($maxlifetime)
    {
        // Not necessary for Redis :)
        return self::true();
    }

    /**
     * Instantiate the Redis.
     *
     * @param string|\Redis $class An extending instance of \Redis or a fully qualified class name
     * @return \Redis Redis instance
     */
    public function instantiateRedis($class = '\Redis')
    {
        if ($this->redis === null) {
            if ($class instanceof \Redis) {
                $this->redis = $class;
            } else {
                $this->redis = new $class;
            }
        }

        return $this->redis;
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
            return $this->redis->setTimeout($this->lockKey, 300);
        }

        // 30 attempts to obtain a lock (in case another request already has it)
        for ($attempt = 0; $attempt < 30; $attempt++) {
            $ttl = $this->redis->ttl($newLockKey);
            if ($ttl > 0) {
                sleep(1);
                continue;
            }

            if (!$this->redis->setex($newLockKey, 300, 1)) {
                trigger_error(__CLASS__ . ': Cannot acquire the lock ' . $newLockKey, E_USER_ERROR);

                return false;
            }

            $this->lockKey = $newLockKey;
            break;
        }

        // Last checks
        if ($attempt === 30) {
            trigger_error(__CLASS__ . ': Cannot acquire  the lock ' . $newLockKey . ' after 30 attempts.', E_USER_ERROR);

            return false;
        }

        if ($ttl === -1) {
            trigger_error(__CLASS__ . ': No TTL for ' . $newLockKey . ' lock. Overriding...', E_USER_NOTICE);
        }

        $this->lockAcquired = true;

        return true;
    }

    /**
     * Releases the obtained lock over a session instance.
     *
     * @return bool Whether the unlocking succeeded or not
     */
    protected function releaseLock()
    {
        if (isset($this->redis, $this->lockKey) && $this->lockAcquired) {
            if (!$this->redis->delete($this->lockKey)) {
                trigger_error(__CLASS__ . ': Could not release the lock ' . $this->lockKey, E_USER_ERROR);

                return false;
            }

            $this->lockKey = null;
            $this->lockAcquired = false;
        }

        return true;
    }
}
