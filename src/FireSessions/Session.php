<?php

namespace FireSessions;

/**
 * Session main file
 *
 * This content is released under the MIT License (MIT).
 * @see LICENSE file
 * @version 1.0.0
 */
class Session
{
    /**
     * Available drivers
     */
    const FILES_DRIVER = 'Files';
    const MEMCACHED_DRIVER = 'Memcached';
    const REDIS_DRIVER = 'Redis';

    /**
     * Internal session variables
     */
    const RESERVED_SESSION_KEYS = array(
        '_temp_bag',
        '_flashes_bag',
        '_last_regenerate',
    );

    /**
     * @var DriversFactory Class for building drivers instances
     */
    private $driversFactory;

    /**
     * Session constructor.
     *
     * @param array $config Configuration array
     */
    public function __construct(array $config)
    {
        if (strpos(PHP_SAPI, 'cli') === 0 || defined('STDIN')) {
            trigger_error('FireSession cannot start in CLI mode.', E_USER_NOTICE);

            return;
        }

        if ((bool)ini_get('session.auto_start')) {
            trigger_error('"session.auto_start" INI setting is enabled; FireSession cannot start.', E_USER_ERROR);

            return;
        }

        $this->driversFactory = new DriversFactory();

        $config = $this->processConfiguration($config);

        session_set_cookie_params(
            $config['cookie_lifetime'],
            $config['cookie_path'],
            $config['cookie_domain'],
            $config['cookie_secure'],
            true
        );

        if (!interface_exists('\SessionHandlerInterface')) {
            class_alias(SessionHandlerInterface::class, '\SessionHandlerInterface');
        }

        if (version_compare(PHP_VERSION, '7.0.0') >= 0) {
            $trueValue = true;
            $falseValue = false;
        } else {
            $trueValue = 0;
            $falseValue = -1;
        }

        /** @var BaseSessionDriver $handler */
        $handler = $this->driversFactory->build($config['driver'], $config, $trueValue, $falseValue);

        if (version_compare(PHP_VERSION, '5.4.0') >= 0) {
            session_set_save_handler($handler, true);
        } else {
            session_set_save_handler(
                array($handler, 'open'),
                array($handler, 'close'),
                array($handler, 'read'),
                array($handler, 'write'),
                array($handler, 'destroy'),
                array($handler, 'gc')
            );

            register_shutdown_function('session_write_close');
        }

        // Sanitize the cookie, PHP doesn't do that for custom handlers
        if (isset($_COOKIE[$config['cookie_name']])
            && (!is_string($_COOKIE[$config['cookie_name']])
            || !preg_match('#\A' . $config['sid_regexp'] . '\z#', $_COOKIE[$config['cookie_name']]))
        ) {
            unset($_COOKIE[$config['cookie_name']]);
        }

        session_start();

        // Is session ID auto-regeneration configured? (AJAX requests are ignored)
        if (!$this->isAjaxRequest() && ($regenerate_time = $config['regenerate_time']) > 0) {
            $currentTime = time();

            if (!isset($_SESSION['_last_regenerate'])) {
                $_SESSION['_last_regenerate'] = $currentTime;
            } elseif ($_SESSION['_last_regenerate'] < ($currentTime - $regenerate_time)) {
                $this->regenerate((bool)$config['destroy_on_regenerate']);
            }
        } elseif (isset($_COOKIE[$config['cookie_name']]) && $_COOKIE[$config['cookie_name']] === session_id()) {
            // PHP doesn't seem to send the session cookie unless it is being currently created or regenerated
            setcookie(
                $config['cookie_name'],
                session_id(),
                $config['cookie_lifetime'] === 0 ? 0 : $config['cookie_lifetime'] + time(),
                $config['cookie_path'],
                $config['cookie_domain'],
                $config['cookie_secure'],
                true
            );
        }

        $this->prepareInternalVars();
    }

    /**
     * __get()
     *
     * @param string $key Session data key
     * @return mixed
     */
    public function __get($key)
    {
        if (array_key_exists($key, $_SESSION)) {
            return $_SESSION[$key];
        } elseif ($key === 'session_id') {
            return session_id();
        }

        return null;
    }

    /**
     * __isset()
     *
     * @param string $key Session data key
     * @return bool
     */
    public function __isset($key)
    {
        if ($key === 'session_id') {
            return (session_status() === PHP_SESSION_ACTIVE);
        }

        return isset($_SESSION[$key]);
    }

    /**
     * __set()
     *
     * @param string $key Session data key
     * @param mixed $value Session data value
     */
    public function __set($key, $value)
    {
        $_SESSION[$key] = $value;
    }

    /**
     * Fetches a specific userdata entry or ALL userdata entries, if no $index is specified
     *
     * @param string|null $index Which userdata to be returned
     * @return mixed
     */
    public function userdata($index = null)
    {
        if ($index !== null) {
            return isset($_SESSION[$index]) && !in_array($index, self::RESERVED_SESSION_KEYS)
                ? $_SESSION[$index]
                : null;
        }

        $allUserdata = $_SESSION;

        $exceptions = array_merge(
            self::RESERVED_SESSION_KEYS,
            array_keys($_SESSION['_temp_bag']),
            array_keys($_SESSION['_flashes_bag'])
        );

        foreach ($allUserdata as $key => $userdata) {
            if (in_array($key, $exceptions)) {
                unset($allUserdata[$key]);
            }
        }

        return $allUserdata;
    }

    /**
     * Fetches a specific flashdata entry or ALL flashdata entries, if no $index is specified.
     *
     * @param string|null $index Which flashdata to be returned
     * @return mixed
     */
    public function flashdata($index = null)
    {
        if (!isset($_SESSION['_flashes_bag'])) {
            return null;
        }

        if ($index !== null) {
            return isset($_SESSION[$index]) && in_array($index, array_keys($_SESSION['_flashes_bag']))
                ? $_SESSION[$index]
                : null;
        }

        $allFlashData = array();

        foreach ($_SESSION['_flashes_bag'] as $key => $status) {
            $allFlashData[] = $_SESSION[$key];
        }

        return $allFlashData;
    }

    /**
     * Fetches a specific tempdata entry or ALL tempdata entries, if no $index is specified.
     *
     * @param string|null $index Which tempdata to be returned
     * @return mixed
     */
    public function tempdata($index = null)
    {
        if (!isset($_SESSION['_temp_bag'])) {
            return null;
        }

        if ($index !== null) {
            return isset($_SESSION[$index]) && in_array($index, array_keys($_SESSION['_temp_bag']))
                ? $_SESSION[$index]
                : null;
        }

        $allTempdata = array();

        foreach ($_SESSION['_temp_bag'] as $key => $expiration) {
            $allTempdata[] = $_SESSION[$key];
        }

        return $allTempdata;
    }

    /**
     * Sets a userdata entry.
     * Can be used to set multiple entries, if $index is an associative array
     * and $value is null.
     *
     * @param string|array $index Userdata name or associative array of userdata names and values
     * @param mixed $value Userdata value, or null if $index is array.
     */
    public function setUserdata($index, $value = null)
    {
        if (is_array($index)) {
            foreach ($index as $key => $value) {
                $_SESSION[$key] = $value;
            }
        } else {
            $_SESSION[$index] = $value;
        }
    }

    /**
     * Sets a flashdata entry.
     * Can be used to set multiple entries, if $index is an associative array
     * and $value is null.
     *
     * @param string|array $index Flashdata name or associative array of flashdata names and values
     * @param mixed $value Flashdata value, or null if $index is array.
     */
    public function setFlashdata($index, $value = null)
    {
        $this->setUserdata($index, $value);
        $this->markAsFlash(is_array($index) ? array_keys($index) : $index);
    }

    /**
     * Sets a tempdata entry.
     * Can be used to set multiple entries, if $index is an associative array
     * and $value is null.
     *
     * @param string|array $index Tempdata name or associative array of tempdata names and values
     * @param mixed $value Tempdata value, or null if $index is array.
     * @param int|array $ttl Time-to-live for this tempdata (can be associative array
     *                       of tempdata keys => ttl if $index is array too)
     */
    public function setTempdata($index, $value = null, $ttl = 300)
    {
        $this->setUserdata($index, $value);
        $this->markAsTemp(is_array($index) ? array_keys($index) : $index, $ttl);
    }

    /**
     * Check if a given userdata exists.
     *
     * @param string $index Userdata key to check for
     * @return bool
     */
    public function hasUserdata($index)
    {
        return !$this->isFlashdata($index) && !$this->isTempdata($index) && array_key_exists($index, $_SESSION);
    }

    /**
     * Check if a given flashdata exists.
     *
     * @param string $index Flashdata key to check for
     * @return bool
     */
    public function hasFlashdata($index)
    {
        return $this->isFlashdata($index);
    }

    /**
     * Check if a given tempdata exists.
     *
     * @param string $index Tempdata key to check for
     * @return bool
     */
    public function hasTempdata($index)
    {
        return $this->isTempdata($index);
    }

    /**
     * Preserves flash (or more, if array supplied) to be available on the next request as well.
     *
     * @param $index string|array Flash index(es)
     */
    public function keepFlashdata($index)
    {
        $this->markAsFlash($index);
    }

    /**
     * Changes the TTL for one or more tempdata entries.
     *
     * @param string|array $index List of one or more tempdata
     * @param int|array $ttl Time-to-live for this tempdata (can be associative array
     *                       of tempdata keys => ttl if $index is array too)
     */
    public function renewTempdata($index, $ttl)
    {
        $this->markAsTemp($index, $ttl);
    }

    /**
     * Removes one or more userdata variables.
     *
     * @param string|array $index Userdata name or array of userdata names for being removed
     */
    public function unsetUserdata($index)
    {
        if (is_array($index)) {
            foreach ($index as $key) {
                if (!$this->isFlashdata($key) && !$this->isTempdata($index)) {
                    unset($_SESSION[$key]);
                }
            }

            return;
        } else {
            if (!$this->isFlashdata($index) && !$this->isTempdata($index)) {
                unset($_SESSION[$index]);
            }
        }
    }

    /**
     * Removes one or more flashdata variables.
     *
     * @param string|array $index Flashdata name or array of flashdata names for being removed
     */
    public function unsetFlashdata($index)
    {
        if (is_array($index)) {
            foreach ($index as $key) {
                if ($this->isFlashdata($key)) {
                    unset($_SESSION['_flashes_bag'][$key], $_SESSION[$key]);
                }
            }
        } elseif ($this->isFlashdata($index)) {
            unset($_SESSION['_flashes_bag'][$index], $_SESSION[$index]);
        }

        if ($_SESSION['_flashes_bag'] === array()) {
            unset($_SESSION['_flashes_bag']);
        }
    }

    /**
     * Removes one or more tempdata variables.
     *
     * @param string|array $index Tempdata name or array of tempdata names for being removed
     */
    public function unsetTempdata($index)
    {
        if (is_array($index)) {
            foreach ($index as $key) {
                if ($this->isTempdata($key)) {
                    unset($_SESSION['_temp_bag'][$key], $_SESSION[$key]);
                }
            }
        } elseif ($this->isTempdata($index)) {
            unset($_SESSION['_temp_bag'][$index], $_SESSION[$index]);
        }

        if ($_SESSION['_temp_bag'] === array()) {
            unset($_SESSION['_temp_bag']);
        }
    }
    
    /**
     * Destroys the session (alias for session_destroy()).
     */
    public function destroy()
    {
        session_destroy();
    }

    /**
     * Regenerates manually the session ID.
     *
     * @param bool $destroy Whether to destroy or not the previous session data
     */
    public function regenerate($destroy = true)
    {
        $_SESSION['_last_regenerate'] = time();
        session_regenerate_id($destroy);
    }

    /**
     * Adds one or more userdata to flash bag.
     *
     * @param array|string $index Flash index(es).
     */
    private function markAsFlash($index)
    {
        if (!isset($_SESSION['_flashes_bag'])) {
            $_SESSION['_flashes_bag'] = array();
        }

        if (is_array($index)) {
            foreach ($index as $key) {
                if (isset($_SESSION[$key])) {
                    $_SESSION['_flashes_bag'][$key] = 1;
                }
            }
        } else {
            if (isset($_SESSION[$index])) {
                $_SESSION['_flashes_bag'][$index] = 1;
            }
        }

        if ($_SESSION['_flashes_bag'] === array()) {
            unset($_SESSION['_flashes_bag']);
        }
    }

    /**
     * Adds one or more userdata to temp bag.
     *
     * @param string|array $index One or more userdata index(es)
     * @param int|array $ttl One generic or more TTLs
     */
    private function markAsTemp($index, $ttl)
    {
        if (is_array($index)) {
            if (is_array($ttl)) {
                foreach ($index as $key) {
                    if (isset($_SESSION[$key])) {
                        $_SESSION['_temp_bag'][$key] = time() + $ttl[$key];
                    }
                }
            } else {
                foreach ($index as $key) {
                    if (isset($_SESSION[$key])) {
                        $_SESSION['_temp_bag'][$key] = time() + $ttl;
                    }
                }
            }
        } else {
            if (isset($_SESSION[$index])) {
                $_SESSION['_temp_bag'][$index] = time() + $ttl;
            }
        }

        if ($_SESSION['_temp_bag'] === array()) {
            unset($_SESSION['_temp_bag']);
        }
    }

    /**
     * Internal configuration array processing.
     *
     * @param array $config Associative array of settings
     * @return array Modified (if necessary) array of settings
     */
    private function processConfiguration(array $config)
    {
        // Expiration default
        $config['expiration'] = isset($config['expiration']) ? (int)$config['expiration'] : 7200;

        // Cookie lifetime (0 = expire on exiting)
        $config['cookie_lifetime'] = $config['expiration'];

        // Cookie name
        if (isset($config['cookie_name'])) {
            ini_set('session.name', (string)$config['cookie_name']);
        } else {
            $config['cookie_name'] = 'fs_session';
            ini_set('session.name', $config['cookie_name']);
        }

        // Cookie path, domain and HTTPs trigger
        $config['cookie_path'] = isset($config['cookie_path']) ? $config['cookie_path'] : '/';
        $config['cookie_domain'] = isset($config['cookie_domain']) ? $config['cookie_domain'] : '';
        $config['cookie_secure'] = isset($config['cookie_secure']) ? (bool)$config['cookie_secure'] : false;


        if ($config['expiration'] === 0) {
            $config['expiration'] = (int)ini_get('session.gc_maxlifetime');
        } else {
            ini_set('session.gc_maxlifetime', $config['expiration']);
        }

        $config['destroy_on_regenerate'] = isset($config['destroy_on_regenerate']) ? (bool)$config['destroy_on_regenerate'] : false;
        $config['regenerate_time'] = isset($config['regenerate_time']) ? (int)$config['regenerate_time'] : 300;
        $config['match_ip'] = isset($config['match_ip']) ? (bool)$config['match_ip'] : false;
        $config['save_path'] = isset($config['save_path']) ? $config['save_path'] : session_save_path();

        // Security considerations for INI
        ini_set('session.use_trans_sid', 0);
        ini_set('session.use_strict_mode', 1);
        ini_set('session.use_cookies', 1);
        ini_set('session.use_only_cookies', 1);

        $config = $this->computeSidLength($config);

        return $config;
    }

    /**
     * Computes the sid length according to PHP installation.
     * Sets the sid_regex setting value in $config.
     *
     * @param array $config Associative array of settings
     * @return array Modified array of settings
     */
    private function computeSidLength(array $config)
    {
        if (PHP_VERSION_ID < 70100) {
            $hashFunction = ini_get('session.hash_function');
            if (ctype_digit($hashFunction)) {
                if ($hashFunction !== '1') {
                    ini_set('session.hash_function', 1);
                }

                $bits = 160;
            } elseif (!in_array($hashFunction, hash_algos(), true)) {
                ini_set('session.hash_function', 1);
                $bits = 160;
            } elseif (($bits = strlen(hash($hashFunction, 'dummy', false)) * 4) < 160) {
                ini_set('session.hash_function', 1);
                $bits = 160;
            }

            $bitsPerCharacter = (int)ini_get('session.hash_bits_per_character');
            $sidLength = (int)ceil($bits / $bitsPerCharacter);
        } else {
            $bitsPerCharacter = (int)ini_get('session.sid_bits_per_character');
            $sidLength = (int)ini_get('session.sid_length');
            if (($bits = $sidLength * $bitsPerCharacter) < 160) {
                // Add as many more characters as necessary to reach at least 160 bits
                $sidLength += (int)ceil((160 % $bits) / $bitsPerCharacter);
                ini_set('session.sid_length', $sidLength);
            }
        }

        // Possible no. of bits per character: 4, 5, 6
        switch ($bitsPerCharacter) {
            case 4:
                $config['sid_regexp'] = '[0-9a-f]';
                break;
            case 5:
                $config['sid_regexp'] = '[0-9a-v]';
                break;
            case 6:
                $config['sid_regexp'] = '[0-9a-zA-Z,-]';
                break;
        }

        $config['sid_regexp'] .= '{' . $sidLength . '}';

        return $config;
    }

    /**
     * Checks for flash and temp bags and removes expired entries.
     */
    private function prepareInternalVars()
    {
        // Flashes
        // Possible status values: -1: to be removed; 0: to be consumed; 1: just set
        if (isset($_SESSION['_flashes_bag'])) {
            foreach ($_SESSION['_flashes_bag'] as $key => $status) {
                $status--;

                if ($status === -1) {
                    unset($_SESSION['_flashes_bag'][$key], $_SESSION[$key]);
                } else {
                    $_SESSION['_flashes_bag'][$key] = $status;
                }
            }

            if ($_SESSION['_flashes_bag'] === array()) {
                unset($_SESSION['_flashes_bag']);
            }
        }

        // Tempdata
        if (isset($_SESSION['_temp_bag'])) {
            $currentTime = time();

            foreach ($_SESSION['_temp_bag'] as $key => $expiration) {
                if ($expiration < $currentTime) {
                    unset($_SESSION['_temp_bag'][$key], $_SESSION[$key]);
                }
            }

            if ($_SESSION['_temp_bag'] === array()) {
                unset($_SESSION['_temp_bag']);
            }
        }
    }

    /**
     * Check if a session var is flashdata or not.
     *
     * @param mixed $index Key of the session variable
     * @return bool
     */
    private function isFlashdata($index)
    {
        return isset($_SESSION['_flashes_bag']) && array_key_exists($index, $_SESSION['_flashes_bag']);
    }

    /**
     * Check if a session var is tempdata or not.
     *
     * @param mixed $index Key of the session variable
     * @return bool
     */
    private function isTempdata($index)
    {
        return isset($_SESSION['_temp_bag']) && array_key_exists($index, $_SESSION['_temp_bag']);
    }

    /**
     * @return bool Whether the current request is performed through AJAX or not
     */
    private function isAjaxRequest()
    {
        return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    }
}
