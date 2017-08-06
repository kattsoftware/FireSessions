<?php

use FireSessions\Drivers\Files;
use FireSessions\Session;
use org\bovigo\vfs\vfsStream;

class FilesTest extends PHPUnit_Framework_TestCase
{
    private static $true;

    private static $false;

    /**
     * @var org\bovigo\vfs\vfsStreamDirectory
     */
    private static $fs;

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
        self::$fs = vfsStream::setup('sessions', 0666);
        vfsStream::newFile('fs_session1234', 0666)->at(self::$fs)->setContent('SESSION-DATA');
        vfsStream::newFile('fs_sessionno-read', 0222)->at(self::$fs);
        vfsStream::newFile('fs_sessionno-write', 0444)->at(self::$fs);
        vfsStream::newFile('fs_sessionno-permissions', 0000)->at(self::$fs);
        vfsStream::newFile('fs_sessioninvalid', 0000)->at(self::$fs);
        vfsStream::newDirectory('fs_sessioninvalid', 0000)->at(self::$fs);

        session_save_path(self::$fs->url());
    }

    public function testOpenWhenSavePathCannotBeCreated()
    {
        $config = array(
            'driver' => Session::FILES_DRIVER,
            'save_path' => session_save_path() . '/anotherpath',
            'match_ip' => false,
        );

        self::$fs->chmod(0000);

        $filesDriver = new Files($config);

        $this->setExpectedException(
            '\PHPUnit_Framework_Error',
            'FireSessions\Drivers\Files: "' . session_save_path() . '" is not a directory, doesn\'t exist or cannot be created.'
        );

        $filesDriver->open($config['save_path'], 'fs_session');
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
}
