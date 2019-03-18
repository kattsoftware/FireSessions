<?php

namespace FireSessions\Tests\Drivers;

use FireSessions\Drivers\Redis as RedisDriver;

class RedisTest extends BaseDriverTest
{
    /**
     * @var \PHPUnit_Framework_MockObject_MockObject|\Redis
     */
    private $redisMock;

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

        new RedisDriver($config);
    }

    public function testCreatingTheDriverWhenSavePathIsMissingWithoutErrorConversion()
    {
        $config = array();

        set_error_handler($this->createEUserErrorHandler(), E_USER_ERROR);

        new RedisDriver($config);

        restore_error_handler();
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

        new RedisDriver($config);
    }

    public function testCreatingTheDriverWhenSavePathDoesNotContainHostWithoutErrorConversion()
    {
        $config = array(
            'save_path' => 'port=1234'
        );

        set_error_handler($this->createEUserErrorHandler(), E_USER_ERROR);

        new RedisDriver($config);

        restore_error_handler();
    }

    public function testCreatingTheDriverWithPrefixAndMatchIpConfig()
    {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';

        $config = array(
            'save_path' => 'host=localhost,port=1234,prefix=mytest',
            'match_ip' => true
        );

        $driver = new RedisDriver($config);

        $reflectionObj = new \ReflectionObject($driver);
        $keyPrefixProp = $reflectionObj->getProperty('keyPrefix');
        $keyPrefixProp->setAccessible(true);

        $this->assertEquals('mytest127.0.0.1:', $keyPrefixProp->getValue($driver));
    }

    public function testOpenWhenRedisConnectFails()
    {
        $config = array(
            'cookie_name' => 'fs_session',
            'match_ip' => false,
            'save_path' => 'host=localhost,port=1234,timeout=30'
        );

        $redisDriver = new RedisDriver($config);

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

        $redisDriver = new RedisDriver($config);

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

        $redisDriver = new RedisDriver($config);

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

        $redisDriver = new RedisDriver($config);

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

        $redisDriver = new RedisDriver($config);

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

        $redisDriver = new RedisDriver($config);

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

        $redisDriver = new RedisDriver($config);

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

        $redisDriver = new RedisDriver($config);

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

        $redisDriver = new RedisDriver($config);

        $redisDriver->instantiateRedis($this->redisMock);

        $writeResult = $redisDriver->write('1234', 'SESSION-DATA');

        $this->assertEquals(self::$false, $writeResult);
    }

    public function testWriteFailWhenRedisDriverIsMissing()
    {
        $config = array(
            'cookie_name' => 'fs_session',
            'match_ip' => false,
            'save_path' => 'host=localhost,port=1234,timeout=30'
        );

        $redisDriver = new RedisDriver($config);

        $writeResult = $redisDriver->write('1234', 'SESSION-DATA');

        $this->assertEquals(self::$false, $writeResult);
    }

    public function testWriteFailWhenSessionIdIsDifferentAndReleasingOldLockFails()
    {
        $lockKey = 'fs_session:OLD_SESSION1234:lock';

        $config = array(
            'cookie_name' => 'fs_session',
            'match_ip' => false,
            'save_path' => 'host=localhost,port=1234,timeout=30'
        );

        $redisDriver = new RedisDriver($config);

        $redisDriver->instantiateRedis($this->redisMock);

        // - start of read(OLD_SESSION1234)
        // Acquiring first lock
        $this->redisMock->expects($this->once())
            ->method('ttl')
            ->with($lockKey)
            ->willReturn(0);

        $this->redisMock->expects($this->once())
            ->method('setex')
            ->with($lockKey, 300, 1)
            ->willReturn(true);

        // Getting first session data
        $this->redisMock->expects($this->once())
            ->method('get')
            ->with('fs_session:OLD_SESSION1234')
            ->willReturn('OLD-SESSION-DATA');
        // - end of read() call.

        // - start of write(SESSION1234, 'SESSION-DATA')
        $this->redisMock->expects($this->once())
            ->method('delete')
            ->with($lockKey)
            ->willReturn(false);

        $this->setExpectedException(
            '\PHPUnit_Framework_Error',
            'FireSessions\Drivers\Redis: Could not release the lock ' . $lockKey
        );

        $readResult = $redisDriver->read('OLD_SESSION1234');
        $this->assertEquals('OLD-SESSION-DATA', $readResult);

        $redisDriver->write('SESSION1234', 'SESSION-DATA');
    }

    public function testWriteFailWhenSessionIdIsDifferentAndAcquiringNewLockFails()
    {
        $lockKey = 'fs_session:OLD_SESSION1234:lock';
        $newLockKey = 'fs_session:SESSION1234:lock';

        $config = array(
            'cookie_name' => 'fs_session',
            'match_ip' => false,
            'save_path' => 'host=localhost,port=1234,timeout=30'
        );

        $redisDriver = new RedisDriver($config);

        $redisDriver->instantiateRedis($this->redisMock);

        // - start of read(OLD_SESSION1234)
        // Acquiring first lock
        $this->redisMock->expects($this->exactly(2))
            ->method('ttl')
            ->willReturnMap(array(
                array($lockKey, 0),
                array($newLockKey, 0)
            ));

        $this->redisMock->expects($this->exactly(2))
            ->method('setex')
            ->willReturnMap(array(
                array($lockKey, 300, 1, true),
                array($newLockKey, 300, 1, false)
            ));

        // Getting first session data
        $this->redisMock->expects($this->once())
            ->method('get')
            ->with('fs_session:OLD_SESSION1234')
            ->willReturn('OLD-SESSION-DATA');
        // - end of read() call.

        // write(SESSION1234, 'SESSION-DATA')
        $this->redisMock->expects($this->once())
            ->method('delete')
            ->with($lockKey)
            ->willReturn(true);

        $this->setExpectedException(
            '\PHPUnit_Framework_Error',
            'FireSessions\Drivers\Redis: Cannot acquire the lock ' . $newLockKey
        );

        $readResult = $redisDriver->read('OLD_SESSION1234');
        $this->assertEquals('OLD-SESSION-DATA', $readResult);

        $redisDriver->write('SESSION1234', 'SESSION-DATA');
    }

    public function testWriteFailWhenSettingTheNewSessionDataFails()
    {

        $lockKey = 'fs_session:OLD_SESSION1234:lock';
        $newLockKey = 'fs_session:SESSION1234:lock';

        $config = array(
            'cookie_name' => 'fs_session',
            'match_ip' => false,
            'save_path' => 'host=localhost,port=1234,timeout=30',
            'expiration' => 7200
        );

        $redisDriver = new RedisDriver($config);

        $redisDriver->instantiateRedis($this->redisMock);

        // - start of read(OLD_SESSION1234)
        // Acquiring first lock
        $this->redisMock->expects($this->exactly(2))
            ->method('ttl')
            ->willReturnMap(array(
                array($lockKey, 0),
                array($newLockKey, 0)
            ));

        $this->redisMock->expects($this->exactly(2))
            ->method('setex')
            ->willReturnMap(array(
                array($lockKey, 300, 1, true),
                array($newLockKey, 300, 1, true)
            ));

        // Getting first session data
        $this->redisMock->expects($this->once())
            ->method('get')
            ->with('fs_session:OLD_SESSION1234')
            ->willReturn('OLD-SESSION-DATA');
        // - end of read() call.

        // write(SESSION1234, 'SESSION-DATA')
        $this->redisMock->expects($this->once())
            ->method('delete')
            ->with($lockKey)
            ->willReturn(true);

        $this->redisMock->expects($this->once())
            ->method('setTimeout')
            ->with($newLockKey, 300)
            ->willReturn(true);

        $this->redisMock->expects($this->once())
            ->method('set')
            ->with('fs_session:SESSION1234', 'SESSION-DATA', 7200)
            ->willReturn(false);

        $readResult = $redisDriver->read('OLD_SESSION1234');
        $writeResult = $redisDriver->write('SESSION1234', 'SESSION-DATA');

        $this->assertEquals('OLD-SESSION-DATA', $readResult);
        $this->assertEquals(self::$false, $writeResult);
    }

    public function testWriteSuccessWhenSessionIdsAreDifferent()
    {

        $lockKey = 'fs_session:OLD_SESSION1234:lock';
        $newLockKey = 'fs_session:SESSION1234:lock';

        $config = array(
            'cookie_name' => 'fs_session',
            'match_ip' => false,
            'save_path' => 'host=localhost,port=1234,timeout=30',
            'expiration' => 7200
        );

        $redisDriver = new RedisDriver($config);

        $redisDriver->instantiateRedis($this->redisMock);

        // - start of read(OLD_SESSION1234)
        // Acquiring first lock
        $this->redisMock->expects($this->exactly(2))
            ->method('ttl')
            ->willReturnMap(array(
                array($lockKey, 0),
                array($newLockKey, 0)
            ));

        $this->redisMock->expects($this->exactly(2))
            ->method('setex')
            ->willReturnMap(array(
                array($lockKey, 300, 1, true),
                array($newLockKey, 300, 1, true)
            ));

        // Getting first session data
        $this->redisMock->expects($this->once())
            ->method('get')
            ->with('fs_session:OLD_SESSION1234')
            ->willReturn('OLD-SESSION-DATA');
        // - end of read() call.

        // write(SESSION1234, 'SESSION-DATA')
        $this->redisMock->expects($this->once())
            ->method('delete')
            ->with($lockKey)
            ->willReturn(true);

        $this->redisMock->expects($this->once())
            ->method('setTimeout')
            ->with($newLockKey, 300)
            ->willReturn(true);

        $this->redisMock->expects($this->once())
            ->method('set')
            ->with('fs_session:SESSION1234', 'SESSION-DATA', 7200)
            ->willReturn(true);

        $readResult = $redisDriver->read('OLD_SESSION1234');
        $writeResult = $redisDriver->write('SESSION1234', 'SESSION-DATA');

        $this->assertEquals('OLD-SESSION-DATA', $readResult);
        $this->assertEquals(self::$true, $writeResult);
    }

    public function testWriteFailWhenSessionIdsAreTheSameAndExtendingSessionTtlFails()
    {
        $lockKey = 'fs_session:SESSION1234:lock';

        $config = array(
            'cookie_name' => 'fs_session',
            'match_ip' => false,
            'save_path' => 'host=localhost,port=1234,timeout=30',
            'expiration' => 7200
        );

        $redisDriver = new RedisDriver($config);

        $redisDriver->instantiateRedis($this->redisMock);

        // - start of read(OLD_SESSION1234)
        // Acquiring first lock
        $this->redisMock->expects($this->once())
            ->method('ttl')
            ->with($lockKey)
            ->willReturn(0);

        $this->redisMock->expects($this->once())
            ->method('setex')
            ->with($lockKey, 300, 1)
            ->willReturn(true);

        // Getting first session data
        $this->redisMock->expects($this->once())
            ->method('get')
            ->with('fs_session:SESSION1234')
            ->willReturn('SESSION-DATA');
        // - end of read() call.

        // write(SESSION1234, 'SESSION-DATA')
        $this->redisMock->expects($this->exactly(2))
            ->method('setTimeout')
            ->willReturnMap(array(
                array($lockKey, 300, true),
                array('fs_session:SESSION1234', 7200, false)
            ));

        $readResult = $redisDriver->read('SESSION1234');
        $writeResult = $redisDriver->write('SESSION1234', 'SESSION-DATA');

        $this->assertEquals('SESSION-DATA', $readResult);
        $this->assertEquals(self::$false, $writeResult);
    }

    public function testWriteSuccessWhenSessionIdsAreTheSame()
    {
        $lockKey = 'fs_session:SESSION1234:lock';

        $config = array(
            'cookie_name' => 'fs_session',
            'match_ip' => false,
            'save_path' => 'host=localhost,port=1234,timeout=30',
            'expiration' => 7200
        );

        $redisDriver = new RedisDriver($config);

        $redisDriver->instantiateRedis($this->redisMock);

        // - start of read(OLD_SESSION1234)
        // Acquiring first lock
        $this->redisMock->expects($this->once())
            ->method('ttl')
            ->with($lockKey)
            ->willReturn(0);

        $this->redisMock->expects($this->once())
            ->method('setex')
            ->with($lockKey, 300, 1)
            ->willReturn(true);

        // Getting first session data
        $this->redisMock->expects($this->once())
            ->method('get')
            ->with('fs_session:SESSION1234')
            ->willReturn('SESSION-DATA');
        // - end of read() call.

        // write(SESSION1234, 'SESSION-DATA')
        $this->redisMock->expects($this->exactly(2))
            ->method('setTimeout')
            ->willReturnMap(array(
                array($lockKey, 300, true),
                array('fs_session:SESSION1234', 7200, true)
            ));

        $readResult = $redisDriver->read('SESSION1234');
        $writeResult = $redisDriver->write('SESSION1234', 'SESSION-DATA');

        $this->assertEquals('SESSION-DATA', $readResult);
        $this->assertEquals(self::$true, $writeResult);
    }

    public function testCloseOnSuccess()
    {
        // Perform a read first
        $config = array(
            'cookie_name' => 'fs_session',
            'match_ip' => false,
            'save_path' => 'host=localhost,port=1234,timeout=30'
        );

        $lockKey = 'fs_session:1234:lock';
        $key = 'fs_session:1234';

        $redisDriver = new RedisDriver($config);

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

        // close() required calls
        $this->redisMock->expects($this->once())
            ->method('ping')
            ->willReturn('+PONG');

        $this->redisMock->expects($this->once())
            ->method('close')
            ->willReturn(true);

        // lock releasing
        $this->redisMock->expects($this->once())
            ->method('delete')
            ->with($lockKey)
            ->willReturn(true);

        $open = $redisDriver->open(session_save_path(), 'fs_session');
        $read = $redisDriver->read('1234');
        $close = $redisDriver->close();

        $this->assertEquals(self::$true, $open);
        $this->assertEquals('SESSION-DATA', $read);
        $this->assertEquals(self::$true, $close);
    }

    public function testCloseFailOnPingException()
    {
        // Perform a read first
        $config = array(
            'cookie_name' => 'fs_session',
            'match_ip' => false,
            'save_path' => 'host=localhost,port=1234,timeout=30'
        );

        $lockKey = 'fs_session:1234:lock';
        $key = 'fs_session:1234';

        $redisDriver = new RedisDriver($config);

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

        // close() required calls
        $this->redisMock->expects($this->once())
            ->method('ping')
            ->willThrowException(new \RedisException());

        $this->setExpectedException(
            '\PHPUnit_Framework_Error',
            'FireSessions\Drivers\Redis: RedisException encountered:'
        );

        $open = $redisDriver->open(session_save_path(), 'fs_session');
        $read = $redisDriver->read('1234');

        $this->assertEquals(self::$true, $open);
        $this->assertEquals('SESSION-DATA', $read);

        $redisDriver->close();
    }

    public function testCloseFailureWhenClosingRedisConnectionFails()
    {
        // Perform a read first
        $config = array(
            'cookie_name' => 'fs_session',
            'match_ip' => false,
            'save_path' => 'host=localhost,port=1234,timeout=30'
        );

        $lockKey = 'fs_session:1234:lock';
        $key = 'fs_session:1234';

        $redisDriver = new RedisDriver($config);

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

        // close() required calls
        $this->redisMock->expects($this->once())
            ->method('ping')
            ->willReturn('+PONG');

        $this->redisMock->expects($this->once())
            ->method('close')
            ->willReturn(false);

        // lock releasing
        $this->redisMock->expects($this->once())
            ->method('delete')
            ->with($lockKey)
            ->willReturn(true);

        $open = $redisDriver->open(session_save_path(), 'fs_session');
        $read = $redisDriver->read('1234');
        $close = $redisDriver->close();

        $this->assertEquals(self::$true, $open);
        $this->assertEquals('SESSION-DATA', $read);
        $this->assertEquals(self::$false, $close);
    }

    public function testDestroyFailWhenRedisDeleteReturnsZero()
    {
        $config = array(
            'cookie_name' => 'fs_session',
            'match_ip' => false,
            'save_path' => 'host=localhost,port=1234,timeout=30'
        );

        $lockKey = 'fs_session:1234:lock';
        $key = 'fs_session:1234';

        $redisDriver = new RedisDriver($config);

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

        // delete() fail
        $this->redisMock->expects($this->once())
            ->method('delete')
            ->with($key)
            ->willReturn(0);

        $this->setExpectedException(
            '\PHPUnit_Framework_Error',
            'FireSessions\Drivers\Redis: Redis::delete() returned'
        );

        $open = $redisDriver->open(session_save_path(), 'fs_session');
        $read = $redisDriver->read('1234');

        $this->assertEquals(self::$true, $open);
        $this->assertEquals('SESSION-DATA', $read);

        $redisDriver->destroy('1234');
    }
    
    public function testDestroyFailWhenRedisIsNotInstantiated()
    {
        $config = array(
            'cookie_name' => 'fs_session',
            'match_ip' => false,
            'save_path' => 'host=localhost,port=1234,timeout=30'
        );

        $redisDriver = new RedisDriver($config);

        $this->assertEquals(self::$false, $redisDriver->destroy('1234'));
    }

    public function testDestroyOnSuccess()
    {
        $config = array(
            'cookie_name' => 'fs_session',
            'match_ip' => false,
            'save_path' => 'host=localhost,port=1234,timeout=30'
        );

        $lockKey = 'fs_session:1234:lock';
        $key = 'fs_session:1234';

        $redisDriver = new RedisDriver($config);

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

        // delete() fail
        $this->redisMock->expects($this->once())
            ->method('delete')
            ->with($key)
            ->willReturn(1);

        $open = $redisDriver->open(session_save_path(), 'fs_session');
        $read = $redisDriver->read('1234');
        $destroy = $redisDriver->destroy('1234');

        $this->assertEquals(self::$true, $open);
        $this->assertEquals('SESSION-DATA', $read);
        $this->assertEquals(self::$true, $destroy);
    }

    public function testGcOnSuccess()
    {
        $config = array(
            'cookie_name' => 'fs_session',
            'match_ip' => false,
            'save_path' => 'host=localhost,port=1234,timeout=30'
        );

        $redisDriver = new RedisDriver($config);

        $this->assertEquals(self::$true, $redisDriver->gc(0));
    }

    private function createEUserErrorHandler()
    {
        return function ($errType, $errString) {
            $this->assertEquals(E_USER_ERROR, $errType);
        };
    }
}
