<?php

namespace FireSessions\Tests\Drivers;

abstract class BaseDriverTest extends \PHPUnit_Framework_TestCase
{
    protected static $true;
    protected static $false;

    public static function setUpBeforeClass()
    {
        if (version_compare(PHP_VERSION, '7.0.0') >= 0) {
            self::$true = true;
            self::$false = false;
        } else {
            self::$true = 0;
            self::$false = -1;
        }
    }
}
