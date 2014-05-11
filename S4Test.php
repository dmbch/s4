<?php

require 'S4.php';

class S4Test extends PHPUnit_Framework_TestCase
{
  const BUCKET_VIRGINIA = 's4-us-east-1';
  const BUCKET_IRELAND = 's4-eu-west-1';

  const FILE_1 = 'file1.md';
  const FILE_2 = 'file2.md';
  const FILE_3 = 'file3.md';

  protected static $bucket = self::BUCKET_IRELAND;
  protected static $region = S4::REGION_IRELAND;

  protected $s4;


  protected function setUp()
  {
    $this->s4 = new S4(
      getenv('S4_ACCESS_KEY'),
      getenv('S4_SECRET_KEY'),
      self::$bucket,
      self::$region
    );
    $response = $this->s4->index();
    foreach ($response['result'] as $file) {
      $this->s4->del($file['key']);
    }
  }


  public function testConstruct()
  {
    $this->assertInstanceOf('S4', $this->s4);

    $response = $this->s4->index();
    $this->assertEquals(200, $response['http_code']);
    $this->assertEmpty($response['result']);
  }


  public function testPut()
  {
    $file = realpath('./README.md');
    $handle = fopen($file, 'r');
    $string  = file_get_contents($file);
    fwrite($handle, $string, filesize($file));

    $response = $this->s4->put(self::FILE_1, $file);
    $this->assertEquals(200, $response['http_code']);
    $response = $this->s4->put(self::FILE_2, $handle);
    $this->assertEquals(200, $response['http_code']);
    $response = $this->s4->put(self::FILE_3, $string);
    $this->assertEquals(200, $response['http_code']);

    $response = $this->s4->index();
    $keys = array_map(
      function ($row) { return $row['key']; },
      $response['result']
    );
    $this->assertArrayHasKey(self::FILE_1, array_flip($keys));
    $this->assertArrayHasKey(self::FILE_2, array_flip($keys));
    $this->assertArrayHasKey(self::FILE_3, array_flip($keys));

    $response = $this->s4->get(self::FILE_1);
    $this->assertEquals($string, $response['result']);
    $response = $this->s4->get(self::FILE_2);
    $this->assertEquals($string, $response['result']);
    $response = $this->s4->get(self::FILE_3);
    $this->assertEquals($string, $response['result']);
  }


  public function testGet()
  {
    $string = file_get_contents(realpath('./README.md'));
    $file = tempnam(sys_get_temp_dir(), '');
    $handle = tmpfile();

    $response = $this->s4->put(self::FILE_1, $string);
    $this->assertEquals(200, $response['http_code']);
    $response = $this->s4->get(self::FILE_1);
    $this->assertEquals(200, $response['http_code']);
    $this->assertEquals($string, $response['result']);

    $response = $this->s4->put(self::FILE_2, $string);
    $this->assertEquals(200, $response['http_code']);
    $response = $this->s4->get(self::FILE_2, $file);
    $this->assertEquals(200, $response['http_code']);
    $this->assertEquals($string, file_get_contents($file));

    $response = $this->s4->put(self::FILE_2, $string);
    $this->assertEquals(200, $response['http_code']);
    $response = $this->s4->get(self::FILE_2, $handle);
    $this->assertEquals(200, $response['http_code']);
    $this->assertEquals($string, stream_get_contents($handle));
  }


  public function testDel()
  {
    $response = $this->s4->put(self::FILE_1, realpath('./README.md'));
    $this->assertEquals(200, $response['http_code']);

    $response = $this->s4->del(self::FILE_1);
    $this->assertEquals(204, $response['http_code']);

    $response = $this->s4->index();
    $this->assertEmpty($response['result']);
  }


  public function testIndex()
  {
    $prefix = 'foo/';
    $response = $this->s4->put(self::FILE_1, realpath('./README.md'));
    $this->assertEquals(200, $response['http_code']);
    $response = $this->s4->put(self::FILE_2, realpath('./README.md'));
    $this->assertEquals(200, $response['http_code']);

    $response = $this->s4->put($prefix. self::FILE_1, realpath('./README.md'));
    $this->assertEquals(200, $response['http_code']);
    $response = $this->s4->put($prefix. self::FILE_2, realpath('./README.md'));
    $this->assertEquals(200, $response['http_code']);

    $response = $this->s4->index(array('max-keys' => 2));
    $this->assertEquals(200, $response['http_code']);
    $this->assertEquals(2, count($response['result']));

    $response = $this->s4->index(array('marker' => self::FILE_2));
    $this->assertEquals(200, $response['http_code']);
    $this->assertEquals(2, count($response['result']));

    $response = $this->s4->index(array('prefix' => $prefix));
    $this->assertEquals(200, $response['http_code']);
    $this->assertEquals(2, count($response['result']));
  }


  // Stub test to switch to s3 us-east-1
  public function testVirginia()
  {
    self::$bucket = self::BUCKET_VIRGINIA;
    self::$region = S4::REGION_VIRGINIA;

    $this->assertEmpty(null);
  }

  public function testVConstruct() { $this->testConstruct(); }

  public function testVPut() { $this->testPut(); }

  public function testVGet() { $this->testGet(); }

  public function testVDel() { $this->testDel(); }

  public function testVIndex() { $this->testIndex(); }
}
