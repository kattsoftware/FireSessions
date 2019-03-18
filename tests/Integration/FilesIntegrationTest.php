<?php

namespace FireSessions\Tests\Drivers;

use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;

class FilesIntegrationTest extends TestCase
{
    /**
     * @var Client
     */
    private static $guzzle;

    public static function setUpBeforeClass()
    {
        self::$guzzle = new Client(['base_uri' => WEBROOT_URL,'cookies' => true]);
    }

    public function testImplementation()
    {
        $response = self::$guzzle->request('GET', 'start.php', [
            'query' => ['driver' => 'files']
        ]);

        $this->assertEquals('OK', (string)$response->getBody());

        $response = self::$guzzle->request('GET', 'get.php', [
            'query' => ['driver' => 'files']
        ]);

        $json = json_decode((string)$response->getBody(), true);

        // Check for individual userdata (myUdata2)
        $this->assertArrayHasKey('myUdata2', $json);
        $this->assertEquals('myUdataValue2', $json['myUdata2']);

        // Check for individual flashdata (myFdata2)
        $this->assertArrayHasKey('myFdata2', $json);
        $this->assertEquals('myFdataValue2', $json['myFdata2']);

        // Check for individual tempdata (myTdata2)
        $this->assertArrayHasKey('myTdata2', $json);
        $this->assertEquals('myTdataValue2', $json['myTdata2']);

        // Check for all userdata
        $this->assertEquals(['myUdata1', 'myUdata2'], array_keys($json['allUserdata']));
        $this->assertEquals('myUdataValue1', $json['allUserdata']['myUdata1']);
        $this->assertEquals('myUdataValue2', $json['allUserdata']['myUdata2']);

        // Check for all flashdata
        $this->assertEquals(['myFdata1', 'myFdata2'], array_keys($json['allFlashdata']));
        $this->assertEquals('myFdataValue1', $json['allFlashdata']['myFdata1']);
        $this->assertEquals('myFdataValue2', $json['allFlashdata']['myFdata2']);

        // Check for all tempdata
        $this->assertEquals(['myTdata1', 'myTdata2'], array_keys($json['allTempdata']));
        $this->assertEquals('myTdataValue1', $json['allTempdata']['myTdata1']);
        $this->assertEquals('myTdataValue2', $json['allTempdata']['myTdata2']);

        $response = self::$guzzle->request('GET', 'get.php', [
            'query' => ['driver' => 'files']
        ]);

        // Second request
        // In this request: userdata should be the same, flashdata should be deleted by now
        // and tempdata should be the same
        $json = json_decode((string)$response->getBody(), true);

        // Check for individual userdata (myUdata2)
        $this->assertArrayHasKey('myUdata2', $json);
        $this->assertEquals('myUdataValue2', $json['myUdata2']);

        // Check for individual flashdata (myFdata2)
        $this->assertNull($json['myFdata2']);

        // Check for individual tempdata (myTdata2)
        $this->assertArrayHasKey('myTdata2', $json);
        $this->assertEquals('myTdataValue2', $json['myTdata2']);

        // Check for all userdata
        $this->assertEquals(['myUdata1', 'myUdata2'], array_keys($json['allUserdata']));
        $this->assertEquals('myUdataValue1', $json['allUserdata']['myUdata1']);
        $this->assertEquals('myUdataValue2', $json['allUserdata']['myUdata2']);

        // Check for flashdata
        $this->assertEquals(array(), $json['allFlashdata']);

        // Check for all tempdata
        $this->assertEquals(['myTdata1', 'myTdata2'], array_keys($json['allTempdata']));
        $this->assertEquals('myTdataValue1', $json['allTempdata']['myTdata1']);
        $this->assertEquals('myTdataValue2', $json['allTempdata']['myTdata2']);

        sleep(11);

        $response = self::$guzzle->request('GET', 'get.php', [
            'query' => ['driver' => 'files']
        ]);

        // Third request
        // In this request: userdata should be the same, flashdata should be deleted
        // and tempdata should contain only myTdata2
        $json = json_decode((string)$response->getBody(), true);

        // Check for individual userdata (myUdata2)
        $this->assertArrayHasKey('myUdata2', $json);
        $this->assertEquals('myUdataValue2', $json['myUdata2']);

        // Check for individual flashdata (myFdata2)
        $this->assertNull($json['myFdata2']);

        // Check for individual tempdata (myTdata2)
        $this->assertArrayHasKey('myTdata2', $json);
        $this->assertEquals('myTdataValue2', $json['myTdata2']);

        // Check for all userdata
        $this->assertEquals(['myUdata1', 'myUdata2'], array_keys($json['allUserdata']));
        $this->assertEquals('myUdataValue1', $json['allUserdata']['myUdata1']);
        $this->assertEquals('myUdataValue2', $json['allUserdata']['myUdata2']);

        // Check for flashdata
        $this->assertEquals(array(), $json['allFlashdata']);

        // Check for all tempdata
        $this->assertEquals(['myTdata2'], array_keys($json['allTempdata']));
        $this->assertEquals('myTdataValue2', $json['allTempdata']['myTdata2']);
    }
}
