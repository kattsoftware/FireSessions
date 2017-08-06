<?php

namespace FireSessions;

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
        Session::FILES_DRIVER => '\FireSessions\Drivers\Files',
        Session::REDIS_DRIVER => '\FireSessions\Drivers\Redis',
        Session::MEMCACHED_DRIVER => '\FireSessions\Drivers\Memcached',
    );

    /**
     * Returns a new driver instance.
     *
     * @param string $driver Driver name to instantiate; if doesn't exist, Files driver will be used
     * @param array $config Processed array of settings
     *
     * @return BaseSessionDriver Instance of built driver
     */
    public function build($driver, array $config)
    {
        // Default driver
        in_array($driver, array_keys(self::$drivers)) || $driver = Session::FILES_DRIVER;

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
     * @return bool Whether the registering succeeded or not
     */
    public static function registerDriver($name, $class)
    {
        if (is_subclass_of($class, '\FireSessions\BaseSessionDriver')) {
            self::$drivers[$name] = $class;

            return true;
        }

        return false;
    }
}
