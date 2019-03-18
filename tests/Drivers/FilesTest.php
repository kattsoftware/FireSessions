<?php

namespace FireSessions;

namespace FireSessions\Tests\Drivers;

use FireSessions\Drivers\Files;
use FireSessions\Exceptions\DriverFaultException;
use FireSessions\Session;
use FireSessions\SessionFactory;
use org\bovigo\vfs\vfsStream;
use phpmock\phpunit\PHPMock;
use Psr\Log\LoggerInterface;

class FilesTest extends BaseDriverTest
{
    use PHPMock;

    /**
     * @var callable
     */
    public static $initSetCallable;

    /**
     * @var \org\bovigo\vfs\vfsStreamFile
     */
    private static $genericSessionFile;

    /**
     * @var \org\bovigo\vfs\vfsStreamDirectory
     */
    private static $fs;

    /**
     * @var LoggerInterface|\PHPUnit_Framework_MockObject_MockObject
     */
    private $loggerMock;

    public function setUp()
    {
        self::$fs = vfsStream::setup('sessions', 0666);
        vfsStream::newFile('fs_sessionno-read', 0222)->at(self::$fs);
        vfsStream::newFile('fs_sessionno-write', 0444)->at(self::$fs);
        vfsStream::newFile('fs_sessionno-permissions', 0000)->at(self::$fs);
        vfsStream::newFile('fs_sessioninvalid', 0000)->at(self::$fs);
        vfsStream::newDirectory('fs_sessioninvalid', 0000)->at(self::$fs);

        self::$genericSessionFile = vfsStream::newFile('fs_session1234', 0666);
        self::$genericSessionFile->at(self::$fs)->setContent('SESSION-DATA');

        $this->loggerMock = $this->createMock(LoggerInterface::class);
    }

    /**
     * @dataProvider trailingSlashesProvider
     * @param string $trailingChar
     */
    public function testDriverCreationWhenIniSetFails($trailingChar)
    {
        // Given
        $savePath = self::$fs->url() . '/fs_session1234' . $trailingChar;
        $config = ['save_path' => $savePath];
        $exceptionMessage = 'Cannot set value';

        // When
        $time = $this->getFunctionMock('FireSessions', 'ini_set');
        $time->expects($this->once())->willThrowException(new \InvalidArgumentException($exceptionMessage));

//        $this->expectException(DriverFaultException::class);

        new Files($config);
    }
    
    public function testOpenWhenSavePathCannotBeCreated()
    {
        $config = array(
            'driver' => SessionFactory::FILES_DRIVER,
            'save_path' =>  self::$fs->url() . '/fs_sessioninvalid',
            'match_ip' => false,
        );

        self::$fs->chmod(0000);

        $filesDriver = new Files($config);

        $this->expectExceptionMessage('FireSessions\Drivers\Files: "' . session_save_path() . '" is not a directory, doesn\'t exist or cannot be created.');

        $filesDriver->open($config['save_path'], 'fs_session');
    }

    public function testOpenWhenSavePathCannotBeCreatedWithoutErrorConversion()
    {
        $config = array(
            'driver' => Session::FILES_DRIVER,
            'save_path' => session_save_path() . '/anotherpath',
            'match_ip' => false,
        );

        self::$fs->chmod(0000);

        $filesDriver = new Files($config);

        set_error_handler($this->createEUserErrorHandler(), E_USER_ERROR);

        $result = $filesDriver->open($config['save_path'], 'fs_session');

        restore_error_handler();

        $this->assertEquals(self::$false, $result);
    }

    public function testOpenWhenSavePathIsNotWritable()
    {
        $config = array(
            'driver' => Session::FILES_DRIVER,
            'save_path' => session_save_path(),
            'match_ip' => false,
        );

        self::$fs->chmod(0000);

        $filesDriver = new Files($config);

        $this->setExpectedException(
            '\PHPUnit_Framework_Error',
            'FireSessions\Drivers\Files: "' . session_save_path() . '" is not writable by the PHP executable.'
        );

        $filesDriver->open($config['save_path'], 'fs_session');
    }

    public function testOpenWhenSavePathIsNotWritableWithoutErrorConversion()
    {
        $config = array(
            'driver' => Session::FILES_DRIVER,
            'save_path' => session_save_path(),
            'match_ip' => false,
        );

        self::$fs->chmod(0000);

        $filesDriver = new Files($config);

        set_error_handler($this->createEUserErrorHandler(), E_USER_ERROR);

        $result = $filesDriver->open($config['save_path'], 'fs_session');

        restore_error_handler();

        $this->assertEquals(self::$false, $result);
    }

    public function testOpenOnSuccess()
    {
        $config = array(
            'driver' => Session::FILES_DRIVER,
            'save_path' => session_save_path(),
            'match_ip' => false,
        );

        $filesDriver = new Files($config);

        $result = $filesDriver->open($config['save_path'], 'sampleName');

        $this->assertEquals(self::$true, $result);
    }

    public function testReadWhenSessionIdIsInvalidToTriggerFopenError()
    {
        $config = array(
            'driver' => Session::FILES_DRIVER,
            'save_path' => session_save_path(),
            'match_ip' => false
        );

        $filesDriver = new Files($config);

        $openResult = $filesDriver->open($config['save_path'], 'fs_session');

        $this->setExpectedException(
            '\PHPUnit_Framework_Error',
            'FireSessions\Drivers\Files: Unable to open the session file: ' . session_save_path() . DIRECTORY_SEPARATOR . 'fs_sessionno-permissions'
        );

        $filesDriver->read('no-permissions');

        $this->assertEquals(self::$true, $openResult);
    }

    public function testReadWhenSessionIdIsInvalidToTriggerFopenErrorWithoutErrorConversion()
    {
        $config = array(
            'driver' => Session::FILES_DRIVER,
            'save_path' => session_save_path(),
            'match_ip' => false
        );

        $filesDriver = new Files($config);

        $openResult = $filesDriver->open($config['save_path'], 'fs_session');

        set_error_handler($this->createEUserErrorHandler(), E_USER_ERROR);

        $result = $filesDriver->read('no-permissions');

        restore_error_handler();

        $this->assertEquals(self::$true, $openResult);
        $this->assertEquals(self::$false, $result);
    }

    public function testReadWhenAcquiringLockFails()
    {
        $config = array(
            'driver' => Session::FILES_DRIVER,
            'save_path' => session_save_path(),
            'match_ip' => false
        );

        $filesDriver = new Files($config);

        $openResult = $filesDriver->open($config['save_path'], 'fs_session');

        // lock the file
        $handler = @fopen($config['save_path'] . DIRECTORY_SEPARATOR . 'fs_session1234', 'c+b');
        flock($handler, LOCK_EX);

        $this->setExpectedException(
            '\PHPUnit_Framework_Error',
            'FireSessions\Drivers\Files: Unable to acquire a lock for ' . session_save_path() . DIRECTORY_SEPARATOR . 'fs_session1234'
        );

        $filesDriver->read('1234');

        $this->assertEquals(self::$true, $openResult);

        flock($handler, LOCK_UN);
        fclose($handler);
    }

    public function testReadWhenAcquiringLockFailsWithoutErrorConversion()
    {
        $config = array(
            'driver' => Session::FILES_DRIVER,
            'save_path' => session_save_path(),
            'match_ip' => false
        );

        $filesDriver = new Files($config);

        $openResult = $filesDriver->open($config['save_path'], 'fs_session');

        // lock the file
        $handler = @fopen($config['save_path'] . DIRECTORY_SEPARATOR . 'fs_session1234', 'c+b');
        flock($handler, LOCK_EX);

        set_error_handler($this->createEUserErrorHandler(), E_USER_ERROR);

        $result = $filesDriver->read('1234');

        restore_error_handler();

        $this->assertEquals(self::$true, $openResult);
        $this->assertEquals(self::$false, $result);

        flock($handler, LOCK_UN);
        fclose($handler);
    }

    public function testReadWhenFileIsNewCreated()
    {
        $config = array(
            'driver' => Session::FILES_DRIVER,
            'save_path' => session_save_path(),
            'match_ip' => false
        );

        $filesDriver = new Files($config);

        $openResult = $filesDriver->open($config['save_path'], 'fs_session');
        $readResult = $filesDriver->read('12345');

        $this->assertEquals(self::$true, $openResult);
        $this->assertEquals('', $readResult);
    }

    public function testReadOnSuccess()
    {
        $config = array(
            'driver' => Session::FILES_DRIVER,
            'save_path' => session_save_path(),
            'match_ip' => false
        );

        $filesDriver = new Files($config);

        $openResult = $filesDriver->open($config['save_path'], 'fs_session');
        $readResult = $filesDriver->read('1234');

        $this->assertEquals(self::$true, $openResult);
        $this->assertEquals('SESSION-DATA', $readResult);
    }

    public function testWriteWhenSessionIdChangesAndNewSessionFileHasNoPermissions()
    {
        $config = array(
            'driver' => Session::FILES_DRIVER,
            'save_path' => session_save_path(),
            'match_ip' => false
        );

        $filesDriver = new Files($config);

        $openResult = $filesDriver->open($config['save_path'], 'fs_session');
        $readResult = $filesDriver->read('1234');

        self::$fs->getChild('fs_session1234')->chmod(0000);

        $this->setExpectedException(
            '\PHPUnit_Framework_Error',
            'FireSessions\Drivers\Files: Unable to open the session file: ' . session_save_path() . DIRECTORY_SEPARATOR . 'fs_sessionno-permissions'
        );

        $filesDriver->write('no-permissions', 'Session-data-2');

        $this->assertEquals(self::$true, $openResult);
        $this->assertEquals('SESSION-DATA', $readResult);
    }

    public function testWriteOnSuccessWhenSessionDataAreTheSame()
    {
        $config = array(
            'driver' => Session::FILES_DRIVER,
            'save_path' => session_save_path(),
            'match_ip' => false
        );

        $filesDriver = new Files($config);

        $openResult = $filesDriver->open($config['save_path'], 'fs_session');
        $readResult = $filesDriver->read('1234');
        $writeResult = $filesDriver->write('1234', 'SESSION-DATA');

        $this->assertEquals(self::$true, $writeResult);
        $this->assertEquals(self::$true, $openResult);
        $this->assertEquals('SESSION-DATA', $readResult);
    }


    public function testWriteOnSuccessWhenSessionDataAreDifferent()
    {
        $config = array(
            'driver' => Session::FILES_DRIVER,
            'save_path' => session_save_path(),
            'match_ip' => false
        );

        $filesDriver = new Files($config);

        $openResult = $filesDriver->open($config['save_path'], 'fs_session');
        $readResult = $filesDriver->read('1234');
        $writeResult = $filesDriver->write('1234', 'SESSION-DATA2');
        clearstatcache();
        $secondReadResult = $filesDriver->read('1234');

        $this->assertEquals('SESSION-DATA2', $secondReadResult);
        $this->assertEquals(self::$true, $writeResult);
        $this->assertEquals(self::$true, $openResult);
        $this->assertEquals('SESSION-DATA', $readResult);
    }

    public function testDestroyOnSuccess()
    {
        $config = array(
            'driver' => Session::FILES_DRIVER,
            'cookie_name' => 'fs_session',
            'cookie_path' => '/',
            'cookie_domain' => '',
            'cookie_secure' => true,
            'save_path' => session_save_path(),
            'match_ip' => false
        );

        $filesDriver = new Files($config);

        $openResult = $filesDriver->open($config['save_path'], 'fs_session');
        $readResult = $filesDriver->read('1234');

        $this->assertInstanceOf('org\bovigo\vfs\vfsStreamFile', self::$fs->getChild('fs_session1234'));

        $filesDriver->destroy('1234');

        $this->assertNull(self::$fs->getChild('fs_session1234'));

        $this->assertEquals(self::$true, $openResult);
        $this->assertEquals('SESSION-DATA', $readResult);
    }

    public function testGcOnSuccess()
    {
        $config = array(
            'driver' => Session::FILES_DRIVER,
            'cookie_name' => 'fs_session',
            'cookie_path' => '/',
            'cookie_domain' => '',
            'cookie_secure' => true,
            'save_path' => session_save_path(),
            'match_ip' => false,
            'sid_regexp' => '[0-9a-f]{32}',
        );

        $filesDriver = new Files($config);

        vfsStream::newFile('fs_session' . md5('1'), 0666)->lastModified(time() - 300)->at(self::$fs);
        vfsStream::newFile('fs_session' . md5('2'), 0666)->lastModified(time() - 350)->at(self::$fs);
        vfsStream::newFile('fs_session' . md5('3'), 0666)->lastModified(time() - 100)->at(self::$fs);

        $this->assertInstanceOf('org\bovigo\vfs\vfsStreamFile', self::$fs->getChild('fs_session' . md5('1')));
        $this->assertInstanceOf('org\bovigo\vfs\vfsStreamFile', self::$fs->getChild('fs_session' . md5('2')));
        $this->assertInstanceOf('org\bovigo\vfs\vfsStreamFile', self::$fs->getChild('fs_session' . md5('3')));

        $filesDriver->gc(250);

        $this->assertNull(self::$fs->getChild('fs_session' . md5('1')));
        $this->assertNull(self::$fs->getChild('fs_session' . md5('2')));
        $this->assertInstanceOf('org\bovigo\vfs\vfsStreamFile', self::$fs->getChild('fs_session' . md5('3')));
    }

    public function trailingSlashesProvider()
    {
        return [['/'], ['\\']];
    }

    private function createEUserErrorHandler()
    {
        return function ($errType, $errString) {
            $this->assertEquals(E_USER_ERROR, $errType);
        };
    }
}
