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
   * URL template
   */
  const ENDPOINT_URL_TEMPLATE = 'https://@host.amazonaws.com';

  /**
   * AWS/S3 regions
   * http://docs.aws.amazon.com/general/latest/gr/rande.html#s3_region
   */
  const REGION_AUSTRALIA      = 'ap-southeast-2';
  const REGION_BRAZIL         = 'sa-east-1';
  const REGION_CALIFORNIA     = 'us-west-1';
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
   * AWS/S3 encryption algo
   * http://docs.aws.amazon.com/AmazonS3/latest/dev/SSEUsingRESTAPI.html
   */
  const ENCRYPTION_AES256     = 'AES256';

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
  const HEADER_ENCRYPTION     = 'x-amz-server-side-encryption';
  const HEADER_REDUNDANCY     = 'x-amz-storage-class';
  const HEADER_SHA256         = 'x-amz-content-sha256';


  /**
   * @var string
   */
  protected $accessKey;

  /**
   * @var string
   */
  protected $secretKey;

  /**
   * @var string
   */
  protected $bucket;

  /**
   * @var string
   */
  protected $region;

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
    $this->accessKey = $accessKey;
    $this->secretKey = $secretKey;
    $this->bucket = $bucket;
    $this->region = $region;

    $host = ($region === self::REGION_VIRGINIA) ? 's3' : "s3-$region";
    $this->endpoint = str_replace('@host', $host, self::ENDPOINT_URL_TEMPLATE);
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
    $path = sprintf('/%s/%s', $this->bucket, ltrim($key, '/'));
    $closeHandle = !is_resource($file);

    // @var handle, hash, checksum, length, type
    extract($this->process($file));

    // prepare headers
    $cache = (0 === strpos($acl, 'public')) ? 'public' : 'private';
    $headers = array_replace(
      array(
        self::HEADER_ACL          => $acl,
        self::HEADER_REDUNDANCY   => $redundancy,
        self::HEADER_SHA256       => $hash,
        'Content-MD5'             => $checksum,
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
    $response = $this->request('PUT', $path, $headers, $options);

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
    $path = sprintf('/%s/%s', $this->bucket, ltrim($key, '/'));

    // prepare download target
    $closeHandle = true;
    if (is_resource($file)) {
      $closeHandle = false;
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
    $response = $this->request('GET', $path, array(), $options);

    // prepare result
    rewind($handle);
    $response['result'] = $file ? $file : stream_get_contents($handle);

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
    $path = sprintf('/%s/%s', $this->bucket, ltrim($key, '/'));

    return $this->request('DELETE', $path);
  }


  /**
   * http://docs.aws.amazon.com/AmazonS3/latest/API/RESTBucketGET.html
   * @param array $params
   * @return array
   */
  public function ls($params = array())
  {
    $path = sprintf('/%s?%s', $this->bucket, http_build_query($params));
    $response = $this->request('GET', $path);

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
   * http://docs.aws.amazon.com/AmazonS3/latest/API/sigv4-query-string-auth.html
   *
   * @param string $key
   * @param int $ttl
   * @param string $method
   * @return string
   */
  public function tmpurl($key, $ttl = 3600, $method = 'GET')
  {
    // prepare url parameters
    $date = gmdate('Ymd\THis\Z');
    $path = sprintf('/%s/%s', $this->bucket, ltrim($key, '/'));
    $scope = sprintf('%s/%s/s3/aws4_request', gmdate('Ymd'), $this->region);
    $params = array(
      'X-Amz-Algorithm'     => 'AWS4-HMAC-SHA256',
      'X-Amz-Credential'    => sprintf('%s/%s', $this->accessKey, $scope),
      'X-Amz-Date'          => $date,
      'X-Amz-Expires'       => $ttl,
      'X-Amz-SignedHeaders' => 'host'
    );
    $query = http_build_query($params);
    $host = parse_url($this->endpoint, PHP_URL_HOST);

    // generate request checksum
    $request  = sprintf(
      "%s\n%s\n%s\nhost:%s\n\nhost\nUNSIGNED-PAYLOAD",
      $method, $path, $query, $host
    );
    $checksum = hash('sha256', $request);

    // prepare signature
    $string = sprintf(
      "AWS4-HMAC-SHA256\n%s\n%s\n%s",
      $date, $scope, $checksum
    );

    // calculate signature
    $params['X-Amz-Signature'] = hash_hmac('sha256', $string, $this->keygen());

    // assemble url
    return sprintf('%s%s?%s', $this->endpoint, $path, http_build_query($params));
  }


  /**
   * http://docs.aws.amazon.com/AmazonS3/latest/API/sig-v4-header-based-auth.html
   *
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
    $format = '';
    $args = $keys = array();
    foreach ($headers as $header => $value) {
      $format .= "%s:%s\n";
      $key = strtolower($header);
      array_push($args, $key, $value);
      array_push($keys, $key);
    }
    $headerList = rtrim(vsprintf($format, $args), "\n");
    $keyList = implode(';', $keys);
    $hash = $headers[self::HEADER_SHA256];
    $date = $headers['Date'];

    // generate request checksum
    $request = sprintf(
      "%s\n%s\n%s\n%s\n\n%s\n%s",
      $method, $path, $query, $headerList, $keyList, $hash
    );
    $checksum = hash('sha256', $request);

    // prepare signature
    $scope = sprintf('%s/%s/s3/aws4_request', gmdate('Ymd'), $this->region);
    $string = sprintf("AWS4-HMAC-SHA256\n%s\n%s\n%s", $date, $scope, $checksum);
    $signature = hash_hmac('sha256', $string, $this->keygen());

    // calculate signature
    return sprintf(
      'AWS4-HMAC-SHA256 Credential=%s/%s,SignedHeaders=%s,Signature=%s',
      $this->accessKey, $scope, $keyList, $signature
    );
  }


  /**
   * @return string
   */
  protected function keygen()
  {
    $secretKey  = "AWS4$this->secretKey";
    $dateKey    = hash_hmac('sha256', gmdate('Ymd'),  $secretKey,   true);
    $regionKey  = hash_hmac('sha256', $this->region,  $dateKey,     true);
    $serviceKey = hash_hmac('sha256', 's3',           $regionKey,   true);
    $signingKey = hash_hmac('sha256', 'aws4_request', $serviceKey,  true);

    return $signingKey;
  }


  /**
   * @param mixed $file
   * @return array
   */
  protected function process($file)
  {
    if (is_string($file) && is_file($file)) {
      $handle = fopen($file, 'r');
      $hash = hash_file('sha256', $file);
      $checksum = base64_encode(hash_file('md5', $file, true));
      $length = filesize($file);
      $type = $this->mime($file);
    }
    elseif (is_resource($file)) {
      $handle = $file;
      $meta = stream_get_meta_data($handle);
      $file = $meta['uri'];
      $hash = hash_file('sha256', $file);
      $checksum = base64_encode(hash_file('md5', $file, true));
      $length = filesize($file);
      $type = $this->mime($file);
    }
    else {
      $handle = fopen('php://temp', 'w+');
      $hash = hash('sha256', $file);
      $checksum = base64_encode(hash('md5', $file, true));
      $length = strlen($file);
      $type = 'text/plain';
      fwrite($handle, $file, $length);
    }
    return compact('handle', 'hash', 'checksum', 'length', 'type');
  }


  /**
   * @param string $file
   * @return string
   */
  protected function mime($file)
  {
    if (function_exists('finfo_open')) {
      $finfo = finfo_open(FILEINFO_MIME_TYPE);
      $type = finfo_file($finfo, $file);
      finfo_close($finfo);
    }
    else {
      $type = mime_content_type($file);
    }
    return $type;
  }


  /**
   * @param string $method (optional)
   * @param string $path (optional)
   * @param array $headers (optional)
   * @param array $options (optional)
   * @return array
   */
  protected function request($method = 'GET', $path = '/', $headers = array(), $options = array())
  {
    $path = sprintf('/%s', ltrim($path, '/'));
    $url = sprintf('%s%s', $this->endpoint, $path);

    $headers = array_replace(
      array(
        'Date' => gmdate('D, d M Y H:i:s \G\M\T'),
        'Host' => parse_url($url, PHP_URL_HOST),
        self::HEADER_SHA256 => hash('sha256', '')
      ),
      $headers
    );
    ksort($headers);
    $headers['Authorization'] = $this->sign($method, $url, $headers);

    $formatted = array();
    foreach ($headers as $key => $value) {
      // prevent duplicate content-length header, i.e. let curl handle it
      if ($key !== 'Content-Length') {
        $formatted[] = "$key: $value";
      }
    }

    return $this->curl($method, $url, $formatted, $options);
  }


  /**
   * @param string $url
   * @param string $method
   * @param string $headers
   * @param array $options
   * @return array
   */
  protected function curl($method, $url, $headers, $options)
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
    $result = curl_exec($handle);
    $error = curl_error($handle);
    $info = curl_getinfo($handle);

    // close curl handle
    curl_close($handle);

    // process response data
    return array_merge($info ? $info : array(), compact('result', 'error'));
  }
}
