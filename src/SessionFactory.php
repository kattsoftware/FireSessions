<?php

namespace FireSessions;

/**
 * Session static instance factory
 *
 * This content is released under the MIT License (MIT).
 * @see LICENSE file
 */
class SessionFactory
{
    /**
     * Available drivers
     */
    const FILES_DRIVER = 'Files';
    const MEMCACHED_DRIVER = 'Memcached';
    const REDIS_DRIVER = 'Redis';

    /**
     * @var Session Library static instance
     */
    private static $instance;

    /**
     * Creates (if doesn't exist) a static instance of the Session client.
     * Next calls can fetch this instance, without creating other ones.
     *
     * @param array $config Library config
     * @return Session Library instance
     */
    public static function getInstance(array $config = null)
    {
        if (self::$instance === null) {
            return self::$instance = new Session($config, new DriversFactory(), new SessionWrapper());
        }

        return self::$instance;
    }
}
