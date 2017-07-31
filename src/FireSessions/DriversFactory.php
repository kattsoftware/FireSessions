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
        Session::FILES_DRIVER => Files::class,
        Session::REDIS_DRIVER => Redis::class,
        Session::MEMCACHED_DRIVER => Memcached::class,
    );

    /**
     * Returns a new driver instance.
     *
     * @param string $driver Driver name to instantiate; if doesn't exist, Files driver will be used
     * @param array $config Processed array of settings
     * @param mixed $trueValue True value according to PHP version
     * @param mixed $falseValue False value according to PHP version
     *
     * @return BaseSessionDriver Instance of built driver
     */
    public function build($driver, array $config, $trueValue, $falseValue)
    {
        // Default driver
        !in_array($driver, self::$drivers) && $driver = Session::FILES_DRIVER;

        if (is_string(self::$drivers[$driver])) {
            return new self::$drivers[$driver]($config, $trueValue, $falseValue);
        }

        return self::$drivers[$driver];
    }

    /**
     * Register a new driver
     *
     * @param string $name Driver name
     * @param string|BaseSessionDriver $class Class name or instance of a driver
     * @return bool Whether the registering succeeded or not
     */
    public static function registerDriver($name, $class)
    {
        if (is_subclass_of($class, BaseSessionDriver::class)) {
            self::$drivers[$name] = $class;

            return true;
        }

        return false;
    }
}
