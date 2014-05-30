<?php

require 'S4.php';

class S4Test extends PHPUnit_Framework_TestCase
{
  const FILE_1 = 'file1.md';
  const FILE_2 = 'file2.md';
  const FILE_3 = 'file3.md';

  protected $s4;


  protected function setUp()
  {
    $region = getenv('S4_REGION') ? getenv('S4_REGION') : S4::REGION_IRELAND;

    if ($region === S4::REGION_VIRGINIA) {
      $xml = '';
    }
    else {
      $dom = new DOMDocument();
      $config = $dom->createElementNS(
        'http://s3.amazonaws.com/doc/2006-03-01/',
        'CreateBucketConfiguration'
      );
      $location = $dom->createElement('LocationConstraint', $region);
      $config->appendChild($location);
      $dom->appendChild($config);
      $xml = $dom->saveXML();
    }

    $this->s4 = new S4(
      getenv('S4_ACCESS_KEY'),
      getenv('S4_SECRET_KEY'),
      's4test-'. self::uuid(),
      $region
    );
    $response = $this->s4->put('', $xml);
  }


  protected function tearDown()
  {
    $response = $this->s4->ls();
    foreach ($response['result'] as $file) {
      $this->s4->del($file['key']);
    }
    $response = $this->s4->del('');
  }


  /**
   * @return string
   */
  protected static function uuid()
  {
    $handle = fopen('/dev/urandom', 'rb');
    $data = fread($handle, 16);
    fclose($handle);

    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

    return vsprintf(
      '%s%s-%s-%s-%s-%s%s%s',
      str_split(bin2hex($data), 4)
    );
  }


  public function testConstruct()
  {
    $this->assertInstanceOf('S4', $this->s4);

    $response = $this->s4->ls();
    $this->assertEquals(200, $response['http_code']);
    $this->assertEmpty($response['result']);
  }


  public function testPut()
  {
    $file = realpath('./Readme.md');
    $handle = fopen($file, 'r');
    $string  = file_get_contents($file);
    fwrite($handle, $string, filesize($file));

    $response = $this->s4->put(self::FILE_1, $file);
    $this->assertEquals(200, $response['http_code']);
    $response = $this->s4->put(self::FILE_2, $handle);
    $this->assertEquals(200, $response['http_code']);
    $response = $this->s4->put(self::FILE_3, $string);
    $this->assertEquals(200, $response['http_code']);

    $keys = array();
    $response = $this->s4->ls();
    foreach ($response['result'] as $row) {
      $keys[] = $row['key'];
    }
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
    $string = file_get_contents(realpath('./Readme.md'));
    $file = tempnam(sys_get_temp_dir(), '');
    $hfile = tempnam(sys_get_temp_dir(), ''); // tmpfile is buggy under hhvm
    $handle = fopen($hfile, 'w+');

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

    fclose($handle); // tmpfile is buggy under hhvm
    unlink($hfile);
    unlink($file);
  }


  public function testDel()
  {
    $response = $this->s4->put(self::FILE_1, realpath('./Readme.md'));
    $this->assertEquals(200, $response['http_code']);

    $response = $this->s4->del(self::FILE_1);
    $this->assertEquals(204, $response['http_code']);

    $response = $this->s4->ls();
    $this->assertEmpty($response['result']);
  }


  public function testLs()
  {
    $prefix = 'foo/';
    $response = $this->s4->put(self::FILE_1, realpath('./Readme.md'));
    $this->assertEquals(200, $response['http_code']);
    $response = $this->s4->put(self::FILE_2, realpath('./Readme.md'));
    $this->assertEquals(200, $response['http_code']);

    $response = $this->s4->put($prefix. self::FILE_1, realpath('./Readme.md'));
    $this->assertEquals(200, $response['http_code']);
    $response = $this->s4->put($prefix. self::FILE_2, realpath('./Readme.md'));
    $this->assertEquals(200, $response['http_code']);

    $response = $this->s4->ls(array('max-keys' => 2));
    $this->assertEquals(200, $response['http_code']);
    $this->assertEquals(2, count($response['result']));

    $response = $this->s4->ls(array('marker' => self::FILE_2));
    $this->assertEquals(200, $response['http_code']);
    $this->assertEquals(2, count($response['result']));

    $response = $this->s4->ls(array('prefix' => $prefix));
    $this->assertEquals(200, $response['http_code']);
    $this->assertEquals(2, count($response['result']));
  }


  /**
    * @expectedException ErrorException
    */
  public function testTmpurl()
  {
    $response = $this->s4->put(self::FILE_1, realpath('./Readme.md'));
    $this->assertEquals(200, $response['http_code']);

    $signed = $this->s4->tmpurl(self::FILE_1);
    $unsigned = substr($signed, 0, strpos($signed, '?'));

    $this->assertNotEmpty(file_get_contents($signed));
    try {
      $this->assertEmpty(file_get_contents($unsigned));
    }
    catch (Exception $e) { throw new ErrorException(); }
  }
}
