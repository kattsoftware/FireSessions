<?php

namespace FireSessions;

use FireSessions\Exceptions\FireSessionsException;
use InvalidArgumentException;

/**
 * Session main file
 *
 * This content is released under the MIT License (MIT).
 * @see LICENSE file
 * @version 1.0.0
 */
class Session
{
    /** @deprecated Use the SessionFactory constants instead */
    const FILES_DRIVER = SessionFactory::FILES_DRIVER;

    /** @deprecated Use the SessionFactory constants instead */
    const MEMCACHED_DRIVER = SessionFactory::MEMCACHED_DRIVER;

    /** @deprecated Use the SessionFactory constants instead */
    const REDIS_DRIVER = SessionFactory::REDIS_DRIVER;

    const TEMP_BAG_KEY = '_temp_bag';
    const FLASHES_BAG_KEY = '_flashes_bag';
    const LAST_REGENERATE_KEY = '_last_regenerate';

    /**
     * @var array Internal session variables
     */
    private static $reservedSessionKeys = array(
        self::TEMP_BAG_KEY,
        self::FLASHES_BAG_KEY,
        self::LAST_REGENERATE_KEY,
    );

    /**
     * @var SessionWrapper Class for wrapping PHP session functions
     */
    private $sessionWrapper;

    /**
     * Session constructor.
     *
     * @param array $config Configuration array
     * @param DriversFactory $driversFactory The factory of drivers
     * @param SessionWrapper $sessionWrapper PHP session functions wrapper
     * @throws FireSessionsException if the service can't be initialized
     */
    public function __construct(array $config, DriversFactory $driversFactory, SessionWrapper $sessionWrapper)
    {
        if (empty($config['skip_init'])) {
            if (is_cli()) {
                throw new FireSessionsException('FireSessions cannot start in CLI mode.');
            }

            if ((bool)ini_get('session.auto_start')) {
                throw new FireSessionsException('"session.auto_start" INI setting is enabled; FireSessions cannot start.');
            }

            $this->sessionWrapper = $sessionWrapper;

            try {
                $config = $this->processConfiguration($config);
            } catch (\InvalidArgumentException $e) {
                throw new FireSessionsException('InvalidArgumentException encountered: ' . $e->getMessage(), 0, $e);
            }

            $this->sessionWrapper->setCookieParams(
                $config['cookie_lifetime'],
                $config['cookie_path'],
                $config['cookie_domain'],
                $config['cookie_secure'],
                true
            );

            $handler = $driversFactory->create(
                isset($config['driver']) ? $config['driver'] : SessionFactory::FILES_DRIVER, $config
            );

            if (isset($config['logger'])) {
                $handler->setLogger($config['logger']);
            } else {
                $handler->setLogger(new \Psr\Log\NullLogger());
            }

            $this->sessionWrapper->setHandler($handler);

            // Sanitize the cookie, PHP doesn't do that for custom handlers
            if (isset($_COOKIE[$config['cookie_name']])
                && (!is_string($_COOKIE[$config['cookie_name']])
                    || !preg_match('#\A' . $config['sid_regexp'] . '\z#', $_COOKIE[$config['cookie_name']]))
            ) {
                unset($_COOKIE[$config['cookie_name']]);
            }

            $this->sessionWrapper->start();
        }

        // Is session ID auto-regeneration configured? (AJAX requests are ignored)
        if (!is_ajax_request() && ($regenerateTime = $config['regenerate_time']) > 0) {
            $currentTime = time();

            if (!isset($_SESSION['_last_regenerate'])) {
                $_SESSION['_last_regenerate'] = $currentTime;
            } elseif ($_SESSION['_last_regenerate'] < ($currentTime - $regenerateTime)) {
                $this->regenerate((bool)$config['destroy_on_regenerate']);
            }
        }

        // PHP doesn't seem to send the session cookie unless it is being currently created or regenerated
        if (isset($_COOKIE[$config['cookie_name']]) && $_COOKIE[$config['cookie_name']] === $this->sessionWrapper->getId()) {
            setcookie(
                $config['cookie_name'],
                $this->sessionWrapper->getId(),
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
        return $this->userdata($key);
    }

    /**
     * __isset()
     *
     * @param string $key Session data key
     * @return bool
     */
    public function __isset($key)
    {
        return $this->hasUserdata($key);
    }

    /**
     * __set()
     *
     * @param string $key Session data key
     * @param mixed $value Session data value
     */
    public function __set($key, $value)
    {
        $this->setUserdata($key, $value);
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
            return $this->hasUserdata($index)
                ? $_SESSION[$index]
                : null;
        }

        // Return all session items, excepting flashdata, tempdata and reserved items
        return array_diff_key(
            $_SESSION,
            $this->checkBagIntegrity(self::FLASHES_BAG_KEY) ? $_SESSION[self::FLASHES_BAG_KEY] : array(),
            $this->checkBagIntegrity(self::TEMP_BAG_KEY) ? $_SESSION[self::TEMP_BAG_KEY] : array(),
            array_flip(self::$reservedSessionKeys)
        );
    }

    /**
     * Fetches a specific flashdata entry or ALL flashdata entries, if no $index is specified.
     *
     * @param string|null $index Which flashdata to be returned
     * @return mixed
     */
    public function flashdata($index = null)
    {
        if ($index !== null) {
            return $this->checkBagIntegrity(self::FLASHES_BAG_KEY) && $this->isInBag(self::FLASHES_BAG_KEY, $index)
                ? $_SESSION[$index]
                : null;
        }

        if (!$this->checkBagIntegrity(self::FLASHES_BAG_KEY)) {
            return array();
        }

        $allFlashData = array();

        foreach (array_keys($_SESSION[self::FLASHES_BAG_KEY]) as $key) {
            isset($_SESSION[$key]) && $allFlashData[$key] = $_SESSION[$key];
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
        if ($index !== null) {
            return $this->checkBagIntegrity(self::TEMP_BAG_KEY) && $this->isInBag(self::TEMP_BAG_KEY, $index)
                ? $_SESSION[$index]
                : null;
        }

        if (!$this->checkBagIntegrity(self::TEMP_BAG_KEY)) {
            return array();
        }

        $allTempdata = array();

        foreach (array_keys($_SESSION[self::TEMP_BAG_KEY]) as $key) {
            isset($_SESSION[$key]) && $allTempdata[$key] = $_SESSION[$key];
        }

        return $allTempdata;
    }

    /**
     * Sets one or more userdata entries.
     *
     * @param string|array $index Userdata name or associative array of userdata names and values
     * @param mixed $value Userdata value, or null if $index is array.
     */
    public function setUserdata($index, $value = null)
    {
        if (is_array($index)) {
            $this->checkIfIndexesAreAllowed($index);

            foreach ($index as $key => $value) {
                $_SESSION[$key] = $value;
            }
        } else {
            $this->checkIfIndexIsAllowed($index);

            $_SESSION[$index] = $value;
        }
    }

    /**
     * Sets one or more flashdata entries.
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
        return
            !($this->checkBagIntegrity(self::FLASHES_BAG_KEY) && $this->isInBag(self::FLASHES_BAG_KEY, $index)) &&
            !($this->checkBagIntegrity(self::TEMP_BAG_KEY) && $this->isInBag(self::TEMP_BAG_KEY, $index)) &&
            !in_array($index, self::$reservedSessionKeys) &&
            array_key_exists($index, $_SESSION);
    }

    /**
     * Check if a given flashdata exists.
     *
     * @param string $index Flashdata key to check for
     * @return bool
     */
    public function hasFlashdata($index)
    {
        return $this->checkBagIntegrity(self::FLASHES_BAG_KEY) && $this->isInBag(self::FLASHES_BAG_KEY, $index);
    }

    /**
     * Check if a given tempdata exists.
     *
     * @param string $index Tempdata key to check for
     * @return bool
     */
    public function hasTempdata($index)
    {
        return $this->checkBagIntegrity(self::TEMP_BAG_KEY) && $this->isInBag(self::TEMP_BAG_KEY, $index);
    }

    /**
     * Returns the expiration
     *
     * @param $index
     * @return null
     */
    public function getTempdataExpiration($index)
    {
        if ($this->hasTempdata($index)) {
            return ($expiration = $_SESSION[self::TEMP_BAG_KEY][$index] - time()) < 0 ? 0 : $expiration;
        }

        return null;
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
                if ($this->hasUserdata($key)) {
                    unset($_SESSION[$key]);
                }
            }
        } else {
            if ($this->hasUserdata($index)) {
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
        if (!$this->checkBagIntegrity(self::FLASHES_BAG_KEY)) {
            return;
        }

        if (is_array($index)) {
            foreach ($index as $key) {
                if ($this->isInBag(self::FLASHES_BAG_KEY, $key)) {
                    unset($_SESSION[self::FLASHES_BAG_KEY][$key], $_SESSION[$key]);
                }
            }
        } elseif ($this->isInBag(self::FLASHES_BAG_KEY, $index)) {
            unset($_SESSION[self::FLASHES_BAG_KEY][$index], $_SESSION[$index]);
        }

        $this->cleanupBag(self::FLASHES_BAG_KEY);
    }

    /**
     * Removes one or more tempdata variables.
     *
     * @param string|array $index Tempdata name or array of tempdata names for being removed
     */
    public function unsetTempdata($index)
    {
        if (!$this->checkBagIntegrity(self::TEMP_BAG_KEY)) {
            return;
        }

        if (is_array($index)) {
            foreach ($index as $key) {
                if ($this->isInBag(self::TEMP_BAG_KEY, $key)) {
                    unset($_SESSION[self::TEMP_BAG_KEY][$key], $_SESSION[$key]);
                }
            }
        } elseif ($this->isInBag(self::TEMP_BAG_KEY, $index)) {
            unset($_SESSION[self::TEMP_BAG_KEY][$index], $_SESSION[$index]);
        }

        $this->cleanupBag(self::TEMP_BAG_KEY);
    }
    
    /**
     * Destroys the session (alias for session_destroy()).
     */
    public function destroy()
    {
        return $this->sessionWrapper->destroy();
    }

    /**
     * Regenerates manually the session ID.
     *
     * @param bool $destroy Whether to destroy or not the previous session data
     */
    public function regenerate($destroy = true)
    {
        $_SESSION['_last_regenerate'] = time();
        $this->sessionWrapper->regenerateId($destroy);
    }

    /**
     * Adds one or more userdata to flash bag.
     *
     * @param array|string $index Flash index(es).
     */
    private function markAsFlash($index)
    {
        if (!$this->checkBagIntegrity(self::FLASHES_BAG_KEY)) {
            $_SESSION[self::FLASHES_BAG_KEY] = array();
        }

        if (is_array($index)) {
            foreach ($index as $key) {
                if (isset($_SESSION[$key])) {
                    // If it's a temp, then it will be converted to flash
                    $this->removeFromBag(self::TEMP_BAG_KEY, $key);
                    $_SESSION[self::FLASHES_BAG_KEY][$key] = 1;
                }
            }
        } else {
            if (isset($_SESSION[$index])) {
                // Same as above
                $this->removeFromBag(self::TEMP_BAG_KEY, $index);
                $_SESSION[self::FLASHES_BAG_KEY][$index] = 1;
            }
        }

        $this->cleanupBag(self::FLASHES_BAG_KEY);
    }

    /**
     * Marks one or more userdata as temp.
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
                        // If it's a flash, then it will be converted to temp
                        $this->removeFromBag(self::FLASHES_BAG_KEY, $key);
                        $_SESSION[self::TEMP_BAG_KEY][$key] = time() + $ttl[$key];
                    }
                }
            } else {
                foreach ($index as $key) {
                    if (isset($_SESSION[$key])) {
                        // Same as above
                        $this->removeFromBag(self::FLASHES_BAG_KEY, $key);
                        $_SESSION[self::TEMP_BAG_KEY][$key] = time() + $ttl;
                    }
                }
            }
        } else {
            if (isset($_SESSION[$index])) {
                $this->removeFromBag(self::FLASHES_BAG_KEY, $index);
                $_SESSION[self::TEMP_BAG_KEY][$index] = time() + $ttl;
            }
        }

        $this->cleanupBag(self::TEMP_BAG_KEY);
    }

    /**
     * Internal configuration array processing.
     *
     * @param array $config Associative array of settings
     * @return array Modified (if necessary) array of settings
     */
    private function processConfiguration(array $config)
    {
        // Expiration time
        $config['expiration'] = isset($config['expiration']) ? (int)$config['expiration'] : 7200;

        // Cookie lifetime (0 = expire on exiting)
        $config['cookie_lifetime'] = $config['expiration'];

        // Cookie name
        if (!isset($config['cookie_name'])) {
            $config['cookie_name'] = 'SESSIONID';
        }

        ini_set('session.name', $config['cookie_name']);

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
        $config['save_path'] = isset($config['save_path']) ? $config['save_path'] : $this->sessionWrapper->sessionSavePath();

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
     *
     * @param array $config Associative array of settings
     * @return array Modified array of settings
     */
    private function computeSidLength(array $config)
    {
        // If we are running a lower version than 7.1,
        // then the INI setting session.hash_function is our bet
        if (PHP_VERSION_ID < 70100) {
            $hashFunction = ini_get('session.hash_function');
            $bits = 160;

            if (ctype_digit($hashFunction) && $hashFunction !== '1') {
                ini_set('session.hash_function', 1);
            } elseif (!in_array($hashFunction, hash_algos(), true)) {
                ini_set('session.hash_function', 1);
            } elseif (($bits = strlen(hash($hashFunction, 'dummy', false)) * 4) < 160) {
                ini_set('session.hash_function', 1);
            }

            $bitsPerCharacter = (int)ini_get('session.hash_bits_per_character');
            $sidLength = (int)ceil($bits / $bitsPerCharacter);
        } else {
            $bitsPerCharacter = (int)ini_get('session.sid_bits_per_character');
            $sidLength = (int)ini_get('session.sid_length');
            if (($bits = $sidLength * $bitsPerCharacter) < 160) {

                // Add as many more characters as necessary to reach at least 160 bits
                $sidLength += (int)ceil((160 % $bits) / $bitsPerCharacter);
                ini_set('session.sid_length', (string)$sidLength);
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
        if (isset($_SESSION[self::FLASHES_BAG_KEY])) {
            foreach ($_SESSION[self::FLASHES_BAG_KEY] as $key => $status) {
                $status--;

                if ($status === -1) {
                    unset($_SESSION[self::FLASHES_BAG_KEY][$key], $_SESSION[$key]);
                } else {
                    $_SESSION[self::FLASHES_BAG_KEY][$key] = $status;
                }
            }

            $this->cleanupBag(self::FLASHES_BAG_KEY);
        }

        // Tempdata
        if (isset($_SESSION[self::TEMP_BAG_KEY])) {
            $currentTime = time();

            foreach ($_SESSION[self::TEMP_BAG_KEY] as $key => $expiration) {
                if ($expiration < $currentTime) {
                    unset($_SESSION[self::TEMP_BAG_KEY][$key], $_SESSION[$key]);
                }
            }

            $this->cleanupBag(self::TEMP_BAG_KEY);
        }
    }

    /**
     * Check if a given string it's not a reserved keyword.
     *
     * @param string $index
     * @throws InvalidArgumentException
     */
    private function checkIfIndexIsAllowed($index)
    {
        if (in_array($index, self::$reservedSessionKeys)) {
            throw new InvalidArgumentException("$index is a reserved key");
        }
    }

    /**
     * Checks for an array of strings if any of them is not a reserved keyword.
     *
     * @param array $indexes
     * @throws InvalidArgumentException
     */
    private function checkIfIndexesAreAllowed(array $indexes)
    {
        if (count(array_intersect($indexes, self::$reservedSessionKeys)) > 0) {
            throw new InvalidArgumentException('At least one key of the provided array is a reserved key');
        }
    }

    /**
     * Unsets the bag's data array if it's empty.
     *
     * @param string $bagKey
     */
    private function cleanupBag($bagKey)
    {
        if (isset($_SESSION[$bagKey]) && $_SESSION[$bagKey] === array()) {
            unset($_SESSION[$bagKey]);
        }
    }
    
    /**
     * Checks, for a given bag name, that its data array exists and it's an array.
     *
     * @param string $bagKey
     * @return bool
     */
    private function checkBagIntegrity($bagKey)
    {
        return isset($_SESSION[$bagKey]) && is_array($_SESSION[$bagKey]);
    }

    /**
     * @param string $bagKey
     * @param string $index
     */
    private function removeFromBag($bagKey, $index)
    {
        if ($this->checkBagIntegrity($bagKey) && $this->isInBag($bagKey, $index)) {
            unset($_SESSION[$bagKey][$index]);
        }

        $this->cleanupBag($bagKey);
    }

    /**
     * Checks if a given session entry is in a bag.
     *
     * @param string $bagKey The bag key in _SESSION global
     * @param mixed $index Key of the session variable
     * @return bool
     */
    private function isInBag($bagKey, $index)
    {
        return array_key_exists($index, $_SESSION[$bagKey]);
    }
}
