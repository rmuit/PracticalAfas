<?php

namespace SimpleAfas;

/**
 * A subclass of SoapClient with support for NTLM authentication, using curl.
 *
 * The standard SOAPClient's __doRequest() cannot deal with NTLM authentication
 * so we extend it to make the request call using curl, which can do NTLM. This
 * means that at this moment, WSDL does not work (because we need the WSDL
 * _before_ __doRequest() is called, but cannot reach it because it is behind
 * an NTLM authenticated URL). We could probably extend this class to deal with
 * that.
 *
 * Found on http://php.net/manual/en/soapclient.soapclient.php and modified.
 *
 * @author Meltir <meltir@meltir.com>
 */
class NtlmSoapClient extends \SoapClient {

  private $options;

  /**
   * @inheritdoc
   */
  public function __construct($wsdl, $options = array()) {
    $this->options = $options;
    // If WSDL is turned on, this will generate a hard un-catch()able error.
    // Drupal will log a PHP error saying there was a '401 Unauthorized'.
    // It seems we cannot override this yet like we can override the actual call
    // below -- 20141201
    // @todo call curl here for the WSDL and cache it locally.
    parent::__construct($wsdl, $options);
  }

  /**
   * Performs a SOAP call using curl with NTLM authentication.
   *
   * @param string $data
   * @param string $url
   * @param string $action
   *
   * @return string
   *
   * @throws \RuntimeException
   *   on curl connection error
   */
  protected function callCurl($data, $url, $action) {
    $handle = curl_init();
    curl_setopt($handle, CURLOPT_HEADER, FALSE);
    curl_setopt($handle, CURLOPT_URL, $url);
    curl_setopt($handle, CURLOPT_HTTPHEADER, array(
      'User-Agent: PHP SOAP-NTLM Client',
      // This is always utf-8, does not follow $this->options['encoding']:
      'Content-Type: text/xml; charset=utf-8',
      "SOAPAction: $action",
      'Content-Length:' . strlen($data),
    ) );
    curl_setopt($handle, CURLOPT_RETURNTRANSFER, TRUE);
    curl_setopt($handle, CURLOPT_POSTFIELDS, $data);
    if (!empty($this->options['proxy_login'])) {
      curl_setopt($handle, CURLOPT_PROXYUSERPWD, $this->options['proxy_login'] . ':' . $this->options['proxy_password']);
      $host = (empty($this->options['proxy_host']) ? 'localhost' : $this->options['proxy_host']);
      $port = (empty($this->options['proxy_port']) ? 8080 : $this->options['proxy_port']);
      curl_setopt($handle, CURLOPT_PROXY, "$host:$port");
      curl_setopt($handle, CURLOPT_PROXYAUTH, CURLAUTH_NTLM);
    }
    elseif (!empty($this->options['login'])) {
      curl_setopt($handle, CURLOPT_USERPWD, $this->options['login'] . ':' . $this->options['password']);
      curl_setopt($handle, CURLOPT_HTTPAUTH, CURLAUTH_NTLM);
    }

    $response = curl_exec($handle);
    if (empty($response)) {
      throw new \RuntimeException('CURL error: '. curl_error($handle), curl_errno($handle));
    }
    curl_close($handle);
    return $response;
  }

  /**
   * @inheritdoc
   */
  public function __doRequest($request, $location, $action, $version, $one_way = 0) {
    return $this->callCurl($request, $location, $action);
  }

}
