<?php

namespace FireSessions\Tests\Drivers;

use FireSessions\Drivers\Redis;

class RedisTest extends \PHPUnit_Framework_TestCase
{
    private static $true;

    private static $false;

    /**
     * @var \PHPUnit_Framework_MockObject_MockObject|\Redis
     */
    private $redisMock;

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

    public function setUp()
    {
        $this->redisMock = $this->getMockBuilder('\Redis')
            ->disableOriginalConstructor()
            ->getMock();
    }

    public function testCreatingTheDriverWhenSavePathIsMissing()
    {
        $config = array();

        $this->setExpectedException(
            '\PHPUnit_Framework_Error',
            'FireSessions\Drivers\Redis: No or invalid "save_path" setting found.'
        );

        new Redis($config);
    }

    public function testCreatingTheDriverWhenSavePathDoesNotContainHost()
    {
        $config = array(
            'save_path' => 'port=1234'
        );

        $this->setExpectedException(
            '\PHPUnit_Framework_Error',
            'FireSessions\Drivers\Redis: No or invalid "host" setting in the "save_path" config.'
        );

        new Redis($config);
    }

    public function testOpenWhenRedisConnectFails()
    {
        $config = array(
            'cookie_name' => 'fs_session',
            'match_ip' => false,
            'save_path' => 'host=localhost,port=1234,timeout=30'
        );

        $redisDriver = new Redis($config);

        $redisDriver->instantiateRedis($this->redisMock);

        $this->redisMock->expects($this->once())
            ->method('connect')
            ->with('localhost', '1234', '30')
            ->willReturn(false);

        $this->setExpectedException(
            '\PHPUnit_Framework_Error',
            'FireSessions\Drivers\Redis: Unable to establish a Redis connection with provided settings.'
        );

        $redisDriver->open(session_save_path(), '1234');
    }

    public function testOpenWhenRedisAuthFails()
    {
        $config = array(
            'cookie_name' => 'fs_session',
            'match_ip' => false,
            'save_path' => 'host=localhost,port=1234,timeout=30,password=wrongPass'
        );

        $redisDriver = new Redis($config);

        $redisDriver->instantiateRedis($this->redisMock);

        $this->redisMock->expects($this->once())
            ->method('connect')
            ->with('localhost', '1234', '30')
            ->willReturn(true);

        $this->redisMock->expects($this->once())
            ->method('auth')
            ->with('wrongPass')
            ->willReturn(false);

        $this->setExpectedException(
            '\PHPUnit_Framework_Error',
            'FireSessions\Drivers\Redis: Unable to authenticate with the provided password.'
        );

        $redisDriver->open(session_save_path(), '1234');
    }

    public function testOpenWhenRedisSelectFails()
    {
        $config = array(
            'cookie_name' => 'fs_session',
            'match_ip' => false,
            'save_path' => 'host=localhost,port=1234,timeout=30,password=pass,database=-1'
        );

        $redisDriver = new Redis($config);

        $redisDriver->instantiateRedis($this->redisMock);

        $this->redisMock->expects($this->once())
            ->method('connect')
            ->with('localhost', '1234', '30')
            ->willReturn(true);

        $this->redisMock->expects($this->once())
            ->method('auth')
            ->with('pass')
            ->willReturn(true);

        $this->redisMock->expects($this->once())
            ->method('select')
            ->with('-1')
            ->willReturn(false);

        $this->setExpectedException(
            '\PHPUnit_Framework_Error',
            'FireSessions\Drivers\Redis: Unable to switch to provided Redis database: -1'
        );

        $redisDriver->open(session_save_path(), '1234');
    }

    public function testOpenOnSuccess()
    {
        $config = array(
            'cookie_name' => 'fs_session',
            'match_ip' => false,
            'save_path' => 'host=localhost,port=1234,timeout=30,password=pass,database=1'
        );

        $redisDriver = new Redis($config);

        $redisDriver->instantiateRedis($this->redisMock);

        $this->redisMock->expects($this->once())
            ->method('connect')
            ->with('localhost', '1234', '30')
            ->willReturn(true);

        $this->redisMock->expects($this->once())
            ->method('auth')
            ->with('pass')
            ->willReturn(true);

        $this->redisMock->expects($this->once())
            ->method('select')
            ->with('1')
            ->willReturn(true);

        $openResult = $redisDriver->open(session_save_path(), '1234');

        $this->assertEquals(self::$true, $openResult);
    }

    public function testReadWhenLockAcquiringFails()
    {
        $config = array(
            'cookie_name' => 'fs_session',
            'match_ip' => false,
            'save_path' => 'host=localhost,port=1234,timeout=30'
        );

        $lockKey = 'fs_session:1234:lock';

        $redisDriver = new Redis($config);

        $redisDriver->instantiateRedis($this->redisMock);

        $this->redisMock->expects($this->once())
            ->method('connect')
            ->with('localhost', '1234', '30')
            ->willReturn(true);

        $this->redisMock->expects($this->once())
            ->method('setex')
            ->with($lockKey, 300, 1)
            ->willReturn(false);

        $this->setExpectedException(
            '\PHPUnit_Framework_Error',
            'FireSessions\Drivers\Redis: Cannot acquire the lock ' . $lockKey
        );

        $redisDriver->open(session_save_path(), 'fs_session');
        $redisDriver->read('1234');
    }

    public function testReadFailOnMissingRedisAdapter()
    {
        $config = array(
            'cookie_name' => 'fs_session',
            'match_ip' => false,
            'save_path' => 'host=localhost,port=1234,timeout=30'
        );

        $redisDriver = new Redis($config);

        $this->assertEquals(self::$false, $redisDriver->read('1234'));
    }

    public function testReadOnSuccessWhenKeyExists()
    {
        $config = array(
            'cookie_name' => 'fs_session',
            'match_ip' => false,
            'save_path' => 'host=localhost,port=1234,timeout=30'
        );

        $lockKey = 'fs_session:1234:lock';
        $key = 'fs_session:1234';

        $redisDriver = new Redis($config);

        $redisDriver->instantiateRedis($this->redisMock);

        $this->redisMock->expects($this->once())
            ->method('connect')
            ->with('localhost', '1234', '30')
            ->willReturn(true);

        $this->redisMock->expects($this->once())
            ->method('setex')
            ->with($lockKey, 300, 1)
            ->willReturn(true);

        $this->redisMock->expects($this->once())
            ->method('get')
            ->with($key)
            ->willReturn('SESSION-DATA');

        $open = $redisDriver->open(session_save_path(), 'fs_session');
        $read = $redisDriver->read('1234');

        $this->assertEquals(self::$true, $open);
        $this->assertEquals('SESSION-DATA', $read);
    }

    public function testReadOnSuccessWhenKeyDoesNotExist()
    {
        $config = array(
            'cookie_name' => 'fs_session',
            'match_ip' => false,
            'save_path' => 'host=localhost,port=1234,timeout=30'
        );

        $lockKey = 'fs_session:1234:lock';
        $key = 'fs_session:1234';

        $redisDriver = new Redis($config);

        $redisDriver->instantiateRedis($this->redisMock);

        $this->redisMock->expects($this->once())
            ->method('connect')
            ->with('localhost', '1234', '30')
            ->willReturn(true);

        $this->redisMock->expects($this->once())
            ->method('setex')
            ->with($lockKey, 300, 1)
            ->willReturn(true);

        $this->redisMock->expects($this->once())
            ->method('get')
            ->with($key)
            ->willReturn(false);

        $open = $redisDriver->open(session_save_path(), 'fs_session');
        $read = $redisDriver->read('1234');

        $this->assertEquals(self::$true, $open);
        $this->assertEquals('', $read);
    }

    public function testWriteFailWhenLockKeyIsNotSet()
    {
        $config = array(
            'cookie_name' => 'fs_session',
            'match_ip' => false,
            'save_path' => 'host=localhost,port=1234,timeout=30'
        );

        $redisDriver = new Redis($config);

        $redisDriver->instantiateRedis($this->redisMock);

        $writeResult = $redisDriver->write('1234', 'SESSION-DATA');

        $this->assertEquals(self::$false, $writeResult);
    }
}
