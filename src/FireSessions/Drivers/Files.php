<?php

namespace FireSessions\Drivers;

use FireSessions\BaseSessionDriver;

/**
 * File session driver
 *
 * This content is released under the MIT License (MIT).
 * @see LICENSE file
 */
class Files extends BaseSessionDriver
{
    /**
     * @var string full path of the session file except the session ID appended
     */
    private $fullPathPrefix;

    /**
     * @var resource Session file handle
     */
    private $fileHandler;

    /**
     * @var bool Was the session file just created?
     */
    private $isNewCreated;

    /**
     * Files driver constructor.
     *
     * @param array $config
     */
    public function __construct(array $config)
    {
        parent::__construct($config);

        if (isset($this->config['save_path'])) {
            $this->config['save_path'] = rtrim($this->config['save_path'], '/\\');
            ini_set('session.save_path', $this->config['save_path']);
        } else {
            $this->config['save_path'] = rtrim(ini_get('session.save_path'), '/\\');
        }
    }

    /**
     * Initialize session
     *
     * @link http://php.net/manual/en/sessionhandlerinterface.open.php
     * @param string $savePath The path where to store/retrieve the session.
     * @param string $name The session name.
     *
     * @return bool
     */
    public function open($savePath, $name)
    {
        if (!is_dir($savePath) && !@mkdir($savePath, 0700, true)) {
            trigger_error(__CLASS__ . ': "' . $savePath . '" is not a directory, doesn\'t exist or cannot be created.', E_USER_ERROR);

            return self::false();
        }

        if (!@is_writable($savePath)) {
            trigger_error(__CLASS__ . ': "' . $savePath . '" is not writable by the PHP executable.', E_USER_ERROR);

            return self::false();
        }

        $this->config['save_path'] = $savePath;

        $this->fullPathPrefix = $this->config['save_path'] . DIRECTORY_SEPARATOR
            . $name . ($this->config['match_ip'] ? md5($this->getIp()) : '');

        return self::true();
    }

    /**
     * Read session data
     *
     * @link http://php.net/manual/en/sessionhandlerinterface.read.php
     * @param string $sessionId The session id to read data for.
     *
     * @return string Returns an encoded string of the read data.
     * If nothing was read, it must return an empty string.
     */
    public function read($sessionId)
    {
        $fullPath = $this->fullPathPrefix . $sessionId;

        if ($this->fileHandler === null) {
            // First time opening the file, no session_reset() call.
            $this->isNewCreated = !file_exists($fullPath);

            $this->fileHandler = @fopen($fullPath, 'c+b');

            if ($this->fileHandler === false) {
                trigger_error(__CLASS__ . ': Unable to open the session file: ' . $fullPath, E_USER_ERROR);

                return self::false();
            }

            // File opened, trying to acquire a lock on it
            if ($this->acquireLock() === false) {
                trigger_error(__CLASS__ . ': Unable to acquire a lock for ' . $fullPath, E_USER_ERROR);
                fclose($this->fileHandler);
                $this->fileHandler = null;

                return self::false();
            }

            // write() may be called after session_regenerate_id() calls
            $this->initialSessionId = $sessionId;

            if ($this->isNewCreated) {
                chmod($fullPath, 0600);
                $this->sessionDataChecksum = md5('');

                return '';
            }
        } elseif ($this->fileHandler === false) {
            // Previously failed fopen() calls
            return self::false();
        } else {
            rewind($this->fileHandler);
        }

        $sessionData = '';

        $fileSize = filesize($fullPath);

        if ($fileSize > 0) {
            $buffer = fread($this->fileHandler, $fileSize);
            if ($buffer !== false) {
                $sessionData = $buffer;
            }
        }

        $this->sessionDataChecksum = md5($sessionData);

        return $sessionData;
    }

    /**
     * Write session data
     *
     * @link http://php.net/manual/en/sessionhandlerinterface.write.php
     * @param string $sessionId The session ID
     * @param string $sessionData The encoded session data
     *
     * @return bool
     */
    public function write($sessionId, $sessionData)
    {
        $fullPath = $this->fullPathPrefix . $sessionId;

        // If the two IDs don't match there was a session_regenerate_id() call
        if ($sessionId !== $this->initialSessionId
            && ($this->close() === self::false() || $this->read($sessionId) === self::false())
        ) {
            return self::false();
        }

        if (!is_resource($this->fileHandler)) {
            return self::false();
        }

        if ($this->sessionDataChecksum === md5($sessionData)) {
            return (!$this->isNewCreated && !touch($fullPath)) ? self::false() : self::true();
        }

        if (!$this->isNewCreated) {
            ftruncate($this->fileHandler, 0);
            rewind($this->fileHandler);
        }

        $length = strlen($sessionData);
        if ($length > 0) {
            $result = fwrite($this->fileHandler, $sessionData);

            if (!is_int($result)) {
                $this->sessionDataChecksum = md5('');
                trigger_error(__CLASS__ . ': Unable to write session data to ' . $fullPath, E_USER_ERROR);

                return self::false();
            }
        }

        $this->sessionDataChecksum = md5($sessionData);

        return self::true();
    }

    /**
     * Closes the session
     *
     * @link http://php.net/manual/en/sessionhandlerinterface.close.php
     *
     * @return bool
     */
    public function close()
    {
        if (is_resource($this->fileHandler)) {
            $this->releaseLock();
            fclose($this->fileHandler);

            $this->fileHandler = null;
            $this->isNewCreated = null;
            $this->initialSessionId = null;
        }

        return self::true();
    }

    /**
     * Destroy a session
     *
     * @link http://php.net/manual/en/sessionhandlerinterface.destroy.php
     * @param string $sessionId The session ID being destroyed.
     *
     * @return bool
     */
    public function destroy($sessionId)
    {
        $fullPath = $this->fullPathPrefix . $sessionId;

        $this->close();

        clearstatcache();
        if (file_exists($fullPath))
        {
            $this->destroyCookie();
            return unlink($fullPath)
                ? self::true()
                : self::false();
        }

        return self::true();
    }

    /**
     * Cleanup old sessions
     *
     * @link http://php.net/manual/en/sessionhandlerinterface.gc.php
     * @param int $maxlifetime Sessions that have not updated for
     * the last maxlifetime seconds will be removed.
     *
     * @return bool
     */
    public function gc($maxlifetime)
    {
        $directory = opendir($this->config['save_path']);

        if ( ! is_dir($this->config['save_path']) || $directory === FALSE)
        {
            trigger_error(__CLASS__ . ': gc couldn\'t list the directory ' . $this->config['save_path'], E_USER_NOTICE);

            return self::false();
        }

        $expirationTime = time() - $maxlifetime;

        $pattern = $this->config['match_ip'] === true ? '[0-9a-f]{32}' : '';

        $pattern = sprintf(
            '#\A%s' . $pattern . $this->config['sid_regexp'] . '\z#',
            preg_quote($this->config['cookie_name'])
        );

        while (($file = readdir($directory)) !== false) {
            $mtime = filemtime($this->config['save_path'] . DIRECTORY_SEPARATOR . $file);

            // If it doesn't match this pattern, it's either not a session file/is not ours
            if (!preg_match($pattern, $file)
                || !is_file($this->config['save_path'] . DIRECTORY_SEPARATOR . $file)
                || $mtime === false
                || $mtime > $expirationTime
            ) {
                continue;
            }

            unlink($this->config['save_path'] . DIRECTORY_SEPARATOR . $file);
        }

        closedir($directory);

        return self::true();
    }

    /**
     * {@inheritdoc}
     */
    protected function acquireLock($sessionId = null)
    {
        return flock($this->fileHandler, LOCK_EX);
    }

    /**
     * {@inheritdoc}
     */
    protected function releaseLock()
    {
        return flock($this->fileHandler, LOCK_UN);
    }
}
