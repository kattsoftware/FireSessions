<?php

namespace FireSessions;

use FireSessions\Drivers\Files;
use FireSessions\Drivers\Memcached;
use FireSessions\Drivers\Redis;

/**
 * Drivers factory
 *
 * This content is released under the MIT License (MIT).
 * @see LICENSE file
 */
class DriversFactory
{
    /**
     * @var array Associative array of driver names and their corresponding classes (or created instances)
     */
    private static $drivers = array(
        SessionFactory::FILES_DRIVER => Files::class,
        SessionFactory::REDIS_DRIVER => Redis::class,
        SessionFactory::MEMCACHED_DRIVER => Memcached::class,
    );

    /**
     * Returns a new driver instance.
     *
     * @param string $driver Driver name to instantiate; if doesn't exist, Files driver will be used
     * @param array $config Processed array of settings
     *
     * @return BaseSessionDriver Instance of built driver
     */
    public function create($driver, array $config)
    {
        if (is_string(self::$drivers[$driver])) {
            return new self::$drivers[$driver]($config);
        }

        return self::$drivers[$driver];
    }

    /**
     * Register a new driver
     *
     * @param string $name Driver name
     * @param string|BaseSessionDriver $class Class name or instance of a driver
     * @throws \InvalidArgumentException if the registering failed
     */
    public static function registerDriver($name, $class)
    {
        if (!is_subclass_of($class, BaseSessionDriver::class)) {
            throw new \InvalidArgumentException("The provided driver $name is not extending ");
        }

        self::$drivers[$name] = $class;
    }
}
