<?php

namespace PracticalAfas;

/**
 * A subclass of SoapClient with support for NTLM authentication, using curl.
 *
 * The standard SOAPClient's __doRequest() cannot deal with NTLM authentication
 * so we extend it to make the request call using curl, which can do NTLM.
 *
 * If the WSDL document is needed, however, this is (often) also behind NTLM
 * authentication, and the call to get that document does not seem to be
 * overridable. (It does not go through __doRequest().) So we need to cache it
 * locally.
 *
 * Found on http://php.net/manual/en/soapclient.soapclient.php and modified.
 *
 * @author Meltir <meltir@meltir.com>
 * @author Roderik Muit <rm@wyz.biz>
 */
class NtlmSoapClient extends \SoapClient {
  private $options;

  /**
   * Constructor.
   *
   * @param string|null $wsdl
   *   The URL for the WSDL description document. NULL/empty if not using WSDL.
   * @param array $options
   *   Options for the SoapClient class, plus:
   *   - wsdl_local_file: the filename for the local WSDL cache; this is also
   *     the $wsdl argument given to the parent constructor, if our $wsdl
   *     argument is nonempty. If it is empty, this option does nothing.
   *   - login: username for NTLM login.
   *   - password: password for NTLM login.
   *   - proxy_host:
   *   - proxy_port:
   *   - proxy_login:
   *   - proxy_password: Optional proxy options.
   *
   */
  public function __construct($wsdl, array $options = array()) {
    $this->options = $options;

    if ($wsdl) {
      if (empty($options['wsdl_local_file'])) {
        throw new \InvalidArgumentException("Constructing a NtlmSoapClient that uses WSDL, needs a 'wsdl_local' option.", 1);
      }
      $exists = file_exists($options['wsdl_local_file']);
      if ($exists) {
        // Check whether we should flush the cache.
        $cache_ttl = ini_get('soap.wsdl_cache_ttl');
        if ($cache_ttl && (int) filectime($options['wsdl_local_file']) < time() - $cache_ttl) {
          $exists = FALSE;
        }
      }
      if (!$exists) {
        $curl = curl_init();
        $wsdl_doc = $this->callCurlHandle($curl, $wsdl);
        if (empty($wsdl_doc)) {
          throw new \RuntimeException("Could not open local WSDL cache file '$options[wsdl_local_file]' for writing; connecting with WSDL is impossible.");
        }
        if ($handle = fopen($options['wsdl_local_file'], 'w')) {
          fwrite($handle, $wsdl_doc);
          fclose($handle);
        }
        else {
          throw new \RuntimeException("Could not open local WSDL cache file '$options[wsdl_local_file]' for writing; connecting with WSDL is impossible.", 3);
        }
      }
      $wsdl = $options['wsdl_local_file'];
      unset($options['wsdl_local_file']);
    }

    parent::__construct($wsdl, $options);
  }

  /**
   * Performs a HTTP call using curl with NTLM authentication.
   *
   * @param resource $curl
   *   A Curl handle.
   * @param string $url
   *
   * @return string
   *
   * @throws \RuntimeException
   *   on curl connection error
   */
  protected function callCurlHandle($curl, $url) {
    curl_setopt($curl, CURLOPT_URL, $url);
    curl_setopt($curl, CURLOPT_HEADER, FALSE);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, TRUE);
    if (!empty($this->options['proxy_login'])) {
      curl_setopt($curl, CURLOPT_PROXYUSERPWD, $this->options['proxy_login'] . ':' . $this->options['proxy_password']);
      $host = (empty($this->options['proxy_host']) ? 'localhost' : $this->options['proxy_host']);
      $port = (empty($this->options['proxy_port']) ? 8080 : $this->options['proxy_port']);
      curl_setopt($curl, CURLOPT_PROXY, "$host:$port");
      curl_setopt($curl, CURLOPT_PROXYAUTH, CURLAUTH_NTLM);
    }
    elseif (!empty($this->options['login'])) {
      curl_setopt($curl, CURLOPT_USERPWD, $this->options['login'] . ':' . $this->options['password']);
      curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_NTLM);
    }

    $response = curl_exec($curl);
    if (empty($response)) {
      throw new \RuntimeException('CURL error: '. curl_error($curl), curl_errno($curl));
    }
    curl_close($curl);
    return $response;
  }

  /**
   * Performs a SOAP call using curl with NTLM authentication.
   *
   * @param string $data
   * @param string $url
   * @param string $action
   *
   * @return string
   */
  protected function callCurl($data, $url, $action) {
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_HTTPHEADER, array(
      'User-Agent: PHP SOAP-NTLM Client',
      // This is always utf-8, does not follow $this->options['encoding']:
      'Content-Type: text/xml; charset=utf-8',
      "SOAPAction: $action",
      'Content-Length:' . strlen($data),
    ) );
    curl_setopt($curl, CURLOPT_POSTFIELDS, $data);

    return $this->callCurlHandle($curl, $url);
  }

  /**
   * @inheritdoc
   */
  public function __doRequest($request, $location, $action, $version, $one_way = 0) {
    return $this->callCurl($request, $location, $action);
  }

}
