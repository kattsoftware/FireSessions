<?php

namespace FireSessions;

/**
 * Base class for defining session drivers.
 *
 * This content is released under the MIT License (MIT).
 * @see LICENSE file
 */
abstract class BaseSessionDriver implements \SessionHandlerInterface
{
    /**
     * @var array Associative array of setting names and values
     */
    protected $config;

    /**
     * @var string Checksum of read session data, for avoiding unnecessary savings
     */
    protected $sessionDataChecksum;

    /**
     * @var string The session ID when being opened; used for noticing session regenerations
     */
    protected $initialSessionId;

    /**
     * @var mixed What value should the instance return in case of success
     */
    private static $trueValue;

    /**
     * @var mixed What value should the instance return in case of failure
     */
    private static $falseValue;

    /**
     * BaseSessionDriver constructor.
     *
     * @param array $config
     */
    public function __construct(array $config)
    {
        $this->config = $config;

        if (version_compare(PHP_VERSION, '7.0.0') >= 0) {
            self::$trueValue = true;
            self::$falseValue = false;
        } else {
            self::$trueValue = 0;
            self::$falseValue = -1;
        }
    }

    /**
     * Lock acquiring for this implementation.
     *
     * @param string $sessionId If required, this can be the session ID
     * @return bool if the locking succeeded or not
     */
    abstract protected function acquireLock($sessionId = null);

    /**
     * Releases the obtained lock over a session instance.
     *
     * @return bool Whether the unlocking succeeded or not
     */
    abstract protected function releaseLock();

    /**
     * Destroys a session cookie.
     *
     * @return bool
     */
    protected function destroyCookie()
    {
        return @setcookie(
            $this->config['cookie_name'],
            null,
            1,
            $this->config['cookie_path'],
            $this->config['cookie_domain'],
            $this->config['cookie_secure'],
            true
        );
    }

    /**
     * Returns the user IP, used if 'match_ip' setting is enabled.
     *
     * @return string User IP
     */
    protected function getIp()
    {
        return $_SERVER['REMOTE_ADDR'];
    }

    /**
     * Success return value.
     *
     * @return mixed
     */
    protected static function true()
    {
        return self::$trueValue;
    }

    /**
     * Failure return value.
     *
     * @return mixed
     */
    protected static function false()
    {
        return self::$falseValue;
    }
}
