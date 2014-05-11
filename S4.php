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
   */
  public function __construct($accessKey, $secretKey, $bucket, $region = self::REGION_VIRGINIA)
  {
    $this->accessKey = $accessKey;
    $this->secretKey = $secretKey;
    $this->bucket = $bucket;
    $this->region = $region;

    $host = ($region === static::REGION_VIRGINIA) ? 's3' : "s3-$region";
    $this->endpoint = str_replace('@host', $host, static::ENDPOINT_URL_TEMPLATE);
  }


  /**
   * @param string $key
   * @param mixed $file
   * @param string $acl (optional)
   * @param string $redundancy (optional)
   * @param array $headers (optional)
   */
  public function put($key, $file, $acl = self::ACL_PRIVATE, $redundancy = self::REDUNDANCY_STANDARD, $headers = array())
  {
    $path = sprintf('/%s/%s', $this->bucket, ltrim($key, '/'));

    // @var handle, hash, checksum, length, type
    extract($this->analyze($file));

    // prepare headers
    $cache = (0 === strpos($acl, 'public')) ? 'public' : 'private';
    $headers = array_replace(
      array(
        static::HEADER_ACL        => $acl,
        static::HEADER_REDUNDANCY => $redundancy,
        static::HEADER_SHA256     => $hash,
        'Cache-Control'           => $cache,
        'Content-MD5'             => $checksum,
        'Content-Length'          => $length,
        'Content-Type'            => $type
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

    // clean up
    fclose($handle);

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
    $response['result'] = $file ?: stream_get_contents($handle);

    // handle handle
    if ($closeHandle) { fclose($handle); } else { fflush($handle); }

    return $response;
  }


  /**
   * http://docs.aws.amazon.com/AmazonS3/latest/API/RESTBucketGET.html
   * @param array $params
   * @return array
   */
  public function index($params = array())
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
   * @param string $method (optional)
   * @param string $path (optional)
   * @param array $headers (optional)
   * @param array $options (optional)
   * @return array
   */
  public function request($method = 'GET', $path = '/', $headers = array(), $options = array())
  {
    $path = sprintf('/%s', ltrim($path, '/'));
    $url = sprintf('%s%s', $this->endpoint, $path);

    $headers = array_replace(
      array(
        'Date'                => gmdate('D, d M Y H:i:s \G\M\T'),
        'Host'                => parse_url($url, PHP_URL_HOST),
        static::HEADER_SHA256 => hash('sha256', '')
      ),
      $headers
    );
    ksort($headers);
    $headers['Authorization'] = $this->sign($method, $url, $headers);

    $formatted = array();
    foreach ($headers as $key => $value) {
      // prevent duplicate content-length header
      if ($key !== 'Content-Length') {
        $formatted[] = "$key: $value";
      }
    }

    $options = array_replace(
      array(
        CURLOPT_URL           => $url,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER    => $formatted
      ),
      $options
    );

    return $this->curl($options);
  }


  /**
   * http://docs.aws.amazon.com/AmazonS3/latest/API/sigv4-query-string-auth.html
   *
   * @param string $key
   * @param int $ttl
   * @return string
   */
  public function presign($key, $ttl = 3600)
  {
    // generate date strings
    $amzdate  = gmdate('Ymd\THis\Z');

    // collect url components
    $path     = sprintf('/%s', ltrim($key, '/'));
    $host     = "$this->bucket.s3";
    if ($this->region !== static::REGION_VIRGINIA) {
      $host  .= "-$this->region";
    }
    $endpoint = str_replace('@host', $host, static::ENDPOINT_URL_TEMPLATE);

    // prepare query parameters
    $scope    = sprintf('%s/%s/s3/aws4_request', gmdate('Ymd'), $this->region);
    $params   = array(
      'X-Amz-Algorithm'     => 'AWS4-HMAC-SHA256',
      'X-Amz-Credential'    => sprintf('%s/%s', $this->accessKey, $scope),
      'X-Amz-Date'          => $amzdate,
      'X-Amz-Expires'       => $ttl,
      'X-Amz-SignedHeaders' => 'host'
    );
    $query    = http_build_query($params);

    // generate request checksum
    $request  = sprintf(
      "GET\n%s\n%s\nhost:%s\n\nhost\nUNSIGNED-PAYLOAD",
      $path, $query, parse_url($endpoint, PHP_URL_HOST)
    );
    $checksum = hash('sha256', $request);

    // prepare signature
    $string   = sprintf("AWS4-HMAC-SHA256\n%s\n%s\n%s", $amzdate, $scope, $checksum);
    $key      = $this->keygen();

    // calculate signature
    $params['X-Amz-Signature'] = hash_hmac('sha256', $string, $key);

    // assemble url
    return sprintf('%s%s?%s', $endpoint, $path, http_build_query($params));
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
    // extract info from arguments
    $path       = parse_url($url, PHP_URL_PATH);
    $query      = explode('&', parse_url($url, PHP_URL_QUERY));

    sort($query);
    $query = implode('&', $query);

    // process headers
    $canonical  = array();
    foreach ($headers as $key => $value) {
      $canonical[] = sprintf('%s:%s', strtolower($key), trim($value));
    }
    $canonical  = implode("\n", $canonical);
    $signed     = implode(';', array_map('strtolower', array_keys($headers)));

    // generate request checksum
    $request    = sprintf(
      "%s\n%s\n%s\n%s\n\n%s\n%s",
      $method, $path, $query, $canonical, $signed, $headers[static::HEADER_SHA256]
    );
    $checksum   = hash('sha256', $request);

    // prepare signature
    $scope      = sprintf('%s/%s/s3/aws4_request', gmdate('Ymd'), $this->region);
    $string     = sprintf("AWS4-HMAC-SHA256\n%s\n%s\n%s", $headers['Date'], $scope, $checksum);
    $key        = $this->keygen();

    // calculate signature
    return sprintf(
      'AWS4-HMAC-SHA256 Credential=%s/%s,SignedHeaders=%s,Signature=%s',
      $this->accessKey, $scope, $signed, hash_hmac('sha256', $string, $key)
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
  protected function analyze($file)
  {
    $finfo = finfo_open(FILEINFO_MIME_TYPE);

    if (is_string($file) && is_file($file)) {
      $handle = fopen($file, 'r');
      $hash = hash_file('sha256', $file);
      $checksum = base64_encode(hash_file('md5', $file, true));
      $length = filesize($file);
      $type = finfo_file($finfo, $file);
    }
    elseif (is_resource($file)) {
      $handle = $file;
      $meta = stream_get_meta_data($handle);
      $file = $meta['uri'];
      $hash = hash_file('sha256', $file);
      $checksum = base64_encode(hash_file('md5', $file, true));
      $length = filesize($file);
      $type = finfo_file($finfo, $file);
    }
    else {
      $handle = fopen('php://temp', 'w+');
      $hash = hash('sha256', $file);
      $checksum = base64_encode(hash('md5', $file, true));
      $length = strlen($file);
      $type = 'text/plain';
      fwrite($handle, $file, $length);
    }

    finfo_close($finfo);

    return compact('handle', 'hash', 'checksum', 'length', 'type');
  }


  /**
   * @param array $options
   * @return array
   */
  protected function curl($options)
  {
    // obtain curl handle
    $handle = curl_init();

    // configure curl
    curl_setopt_array($handle, array_replace(
      array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_SSL_VERIFYPEER => 1,
        CURLOPT_FOLLOWLOCATION => true
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
}
