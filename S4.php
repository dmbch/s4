<?php
/**
 * @copyright 2014 Daniel Dembach
 * @license https://raw.github.com/dmbch/s4/master/LICENSE
 * @package dmbch/s4
 */


/**
 * S4 - Stupidly Simple Storage Service
 *
 * Minimal php/curl client for Amazon S3 using AWS Signature Version 4
 *
 * http://docs.aws.amazon.com/AmazonS3/latest/API/APIRest.html
 */
class S4
{
  /**
   * AWS/S3 regions
   * http://docs.aws.amazon.com/general/latest/gr/rande.html#s3_region
   */
  const REGION_AUSTRALIA      = 'ap-southeast-2';
  const REGION_BRAZIL         = 'sa-east-1';
  const REGION_CALIFORNIA     = 'us-west-1';
  const REGION_GERMANY        = 'eu-central-1';
  const REGION_IRELAND        = 'eu-west-1';
  const REGION_JAPAN          = 'ap-northeast-1';
  const REGION_OREGON         = 'us-west-2';
  const REGION_SINGAPORE      = 'ap-southeast-1';
  const REGION_VIRGINIA       = 'us-east-1';

  /**
   * AWS/S3 canned acls
   * http://docs.aws.amazon.com/AmazonS3/latest/dev/ACLOverview.html#CannedACL
   */
  const ACL_PRIVATE           = 'private';
  const ACL_PUBLIC_READ       = 'public-read';
  const ACL_PUBLIC_FULL       = 'public-read-write';
  const ACL_AUTH_READ         = 'authenticated-read';
  const ACL_OWNER_READ        = 'bucket-owner-read';
  const ACL_OWNER_FULL        = 'bucket-owner-full-control';
  const ACL_LOG_WRITE         = 'log-delivery-write';

  /**
   * AWS/S3 storage redundancy
   * http://docs.aws.amazon.com/AmazonS3/latest/dev/UsingMetadata.html
   */
  const REDUNDANCY_STANDARD   = 'STANDARD';
  const REDUNDANCY_REDUCED    = 'REDUCED_REDUNDANCY';

  /**
   * AWS/S3 request headers
   * http://docs.aws.amazon.com/AmazonS3/latest/API/RESTCommonRequestHeaders.html
   */
  const HEADER_ACL            = 'x-amz-acl';
  const HEADER_REDUNDANCY     = 'x-amz-storage-class';
  const HEADER_SHA256         = 'x-amz-content-sha256';


  /**
   * @var string
   */
  protected $accessKey;

  /**
   * @var string
   */
  protected $signingKey;

  /**
   * @var string
   */
  protected $bucket;

  /**
   * @var string
   */
  protected $scope;

  /**
   * @var string
   */
  protected $endpoint;


  /**
   * @param string $accessKey
   * @param string $secretKey
   * @param string $bucket
   * @param string $region (optional)
   * @param S4
   */
  public function __construct($accessKey, $secretKey, $bucket, $region = self::REGION_VIRGINIA)
  {
    $this->bucket = $bucket;
    $this->accessKey = $accessKey;
    $this->signingKey = array_reduce(
      array(gmdate('Ymd'), $region, 's3', 'aws4_request'),
      function ($key, $value) {
        return hash_hmac('sha256', $value, $key, true);
      },
      "AWS4$secretKey"
    );
    $this->scope = sprintf(
      '%s/%s/s3/aws4_request',
      gmdate('Ymd'), $region
    );
    $this->endpoint = sprintf(
      'https://%s.amazonaws.com',
      ($region === static::REGION_VIRGINIA) ? 's3' : "s3-$region"
    );
  }


  /**
   * @param string $key
   * @param mixed $file
   * @param array $headers (optional)
   * @param string $acl (optional)
   * @param string $redundancy (optional)
   * @return array
   */
  public function put($key, $file, $headers = array(), $acl = self::ACL_PRIVATE, $redundancy = self::REDUNDANCY_STANDARD)
  {
    // analyze file data
    if (!$closeHandle = !is_resource($file)) {
      $handle = $file;
      $meta = stream_get_meta_data($handle);
      $file = $meta['uri'];
      $hash = hash_file('sha256', $file);
      $length = filesize($file);
      $type = static::finfo($file);
    }
    elseif (is_string($file) && is_file($file)) {
      $handle = fopen($file, 'r');
      $hash = hash_file('sha256', $file);
      $length = filesize($file);
      $type = static::finfo($file);
    }
    else {
      $handle = fopen('php://temp', 'w+');
      $hash = hash('sha256', $file);
      $length = strlen($file);
      $type = 'text/plain';
      fwrite($handle, $file, $length);
    }

    // prepare headers
    $cache = (0 === strpos($acl, 'public')) ? 'public' : 'private';
    $headers = array_replace(
      array(
        static::HEADER_ACL        => $acl,
        static::HEADER_REDUNDANCY => $redundancy,
        static::HEADER_SHA256     => $hash,
        'Content-Length'          => $length,
        'Content-Type'            => $type,
        'Cache-Control'           => $cache
      ),
      $headers
    );

    // prepare curl options
    rewind($handle);
    $options = array(
      CURLOPT_PUT         => true,
      CURLOPT_INFILE      => $handle,
      CURLOPT_INFILESIZE  => $length
    );

    // execute curl request
    $response = $this->req($key, 'PUT', $headers, $options);

    // handle handle
    if ($closeHandle) { fclose($handle); }

    return $response;
  }


  /**
   * @param string $key
   * @param mixed $file
   * @return array
   */
  public function get($key, $file = null)
  {
    // prepare download target
    if (!$closeHandle = !is_resource($file)) {
      $handle = $file;
      $meta = stream_get_meta_data($handle);
      $file = $meta['uri'];
    }
    elseif (is_string($file)) {
      $handle = fopen($file, 'w+');
    }
    else {
      $handle = fopen('php://temp', 'w+');
      $file = null;
    }
    $options = array(CURLOPT_FILE => $handle);

    // perform curl request
    $response = $this->req($key, 'GET', array(), $options);

    // prepare result
    rewind($handle);
    $response['result'] = $file ?: stream_get_contents($handle);

    // handle handle
    if ($closeHandle) { fclose($handle); } else { fflush($handle); }

    return $response;
  }


  /**
   * @param string $key
   * @return array
   */
  public function del($key)
  {
    return $this->req($key, 'DELETE');
  }


  /**
   * http://docs.aws.amazon.com/AmazonS3/latest/API/RESTBucketGET.html
   *
   * @param array $params
   * @return array
   */
  public function ls($params = array())
  {
    $response = $this->req(sprintf('?%s', http_build_query($params)));

    if ($response['http_code'] === 200) {
      $dom = new DOMDocument();
      $dom->loadXML($response['result']);

      $nodes = array();
      foreach ($dom->getElementsByTagName('Contents') as $contents) {
        $node = array();
        foreach ($contents->childNodes as $child) {
          $node[strtolower($child->nodeName)] = $child->nodeValue;
        }
        array_push($nodes, $node);
      }
      $response['result'] = $nodes;
    }

    return $response;
  }


  /**
   * @param string $key (optional)
   * @param string $method (optional)
   * @param array $headers (optional)
   * @param array $options (optional)
   * @return array
   */
  public function req($key = '/', $method = 'GET', $headers = array(), $options = array())
  {
    // build url using key and endpoint
    $url = sprintf('%s/%s/%s', $this->endpoint, $this->bucket, ltrim($key, '/'));

    // assemble headers
    $headers = array_replace(
      array(
        'Date' => gmdate('Ymd\THis\Z'),
        'Host' => parse_url($url, PHP_URL_HOST),
        static::HEADER_SHA256 => hash('sha256', '')
      ),
      $headers
    );
    $headers['Authorization'] = $this->auth($method, $url, $headers);

    // format headers
    $curlHeaders = array();
    foreach ($headers as $key => $value) {
      // prevent duplicate content-length header, i.e. let curl handle it
      if ($key !== 'Content-Length') {
        $curlHeaders[] = "$key: $value";
      }
    }

    // perform curl request
    return static::curl($method, $url, $curlHeaders, $options);
  }


  /**
   * http://docs.aws.amazon.com/AmazonS3/latest/API/sigv4-query-string-auth.html
   *
   * @param string $key
   * @param int $ttl
   * @param string $method
   * @return string
   */
  public function url($key, $ttl = 3600, $method = 'GET')
  {
    // prepare url parameters
    $path = sprintf('/%s/%s', $this->bucket, ltrim($key, '/'));
    $params = array(
      'X-Amz-Algorithm'     => 'AWS4-HMAC-SHA256',
      'X-Amz-Credential'    => sprintf('%s/%s', $this->accessKey, $this->scope),
      'X-Amz-Date'          => gmdate('Ymd\THis\Z'),
      'X-Amz-Expires'       => $ttl,
      'X-Amz-SignedHeaders' => 'host'
    );
    $host = parse_url($this->endpoint, PHP_URL_HOST);
    $url = sprintf('%s%s?%s', $this->endpoint, $path, http_build_query($params));

    // add signature param
    $params['X-Amz-Signature'] = $this->sign($method, $url, compact('host'));

    // assemble url
    return sprintf('%s%s?%s', $this->endpoint, $path, http_build_query($params));
  }


  /**
   * http://docs.aws.amazon.com/AmazonS3/latest/API/sig-v4-header-based-auth.html
   *
   * @internal
   * @param string $method
   * @param string $url
   * @param array $headers
   * @return string
   */
  protected function auth($method, $url, $headers)
  {
    ksort($headers);

    return sprintf(
      'AWS4-HMAC-SHA256 Credential=%s/%s,SignedHeaders=%s,Signature=%s',
      $this->accessKey,
      $this->scope,
      implode(';', array_map('strtolower', array_keys($headers))),
      $this->sign($method, $url, $headers)
    );
  }


  /**
   * @internal
   * @param string $method
   * @param string $url
   * @param array $headers
   * @return string
   */
  protected function sign($method, $url, $headers)
  {
    // extract and format url data
    $path = parse_url($url, PHP_URL_PATH);
    $query = parse_url($url, PHP_URL_QUERY);
    parse_str($query, $params);
    ksort($params);
    $query = http_build_query($params);

    // process headers
    ksort($headers);
    $format = '';
    $keys = $args = array();
    foreach ($headers as $header => $value) {
      $format .= "%s:%s\n";
      $key = strtolower($header);
      array_push($args, $key, $value);
      array_push($keys, $key);
    }
    $keyList = implode(';', $keys);
    $headerList = rtrim(vsprintf($format, $args), "\n");

    if (isset($headers['Date'])) {
      $date = $headers['Date'];
      $bodyHash = $headers[static::HEADER_SHA256];
    }
    else {
      $date = $params['X-Amz-Date'];
      $bodyHash = 'UNSIGNED-PAYLOAD';
    }

    // generate request checksum
    $request = sprintf(
      "%s\n%s\n%s\n%s\n\n%s\n%s",
      $method, $path, $query, $headerList, $keyList, $bodyHash
    );
    $reqHash = hash('sha256', $request);

    // prepare signature
    $string = sprintf(
      "AWS4-HMAC-SHA256\n%s\n%s\n%s",
      $date, $this->scope, $reqHash
    );
    return hash_hmac('sha256', $string, $this->signingKey);
  }


  /**
   * @internal
   * @param string $url
   * @param string $method
   * @param string $headers
   * @param array $options
   * @return array
   */
  protected static function curl($method, $url, $headers, $options)
  {
    // obtain and configure curl handle
    $handle = curl_init();

    // configure curl
    curl_setopt_array($handle, array_replace(
      array(
        CURLOPT_URL             => $url,
        CURLOPT_CUSTOMREQUEST   => $method,
        CURLOPT_HTTPHEADER      => $headers,
        CURLOPT_RETURNTRANSFER  => true,
        CURLOPT_FOLLOWLOCATION  => true
      ),
      $options
    ));

    // perform request, gather response data
    $result = curl_exec($handle) ?: null;
    $error = curl_error($handle) ?: null;
    $info = curl_getinfo($handle) ?: array();

    // close curl handle
    curl_close($handle);

    // process response data
    return array_merge($info, compact('result', 'error'));
  }


  /**
   * @internal
   * @param string $file
   * @param int $options
   */
  protected static function finfo($file, $options = FILEINFO_MIME_TYPE)
  {
    $finfo = finfo_open($options);
    $result = finfo_file($finfo, $file);
    finfo_close($finfo);

    return $result;
  }
}
