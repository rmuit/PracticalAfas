<?php
/**
 * @file
 * Contains AfasSoapClient and NtlmSoapClient.
 */

/**
 * Class AfasSoapClient.
 *
 * This contains the callAfas method, so this can be extended depending
 * on a specific library used, while the AfasSoapConnection class can be
 * extended with arguments/behavior specific to certain functionality.
 *
 * We pass the AfasSoapConnection class as a function argument in order to
 * prevent cyclic references in class properties.
 *
 * The below standard implementation uses the SOAP library bundled with PHP5,
 * but does not work with WSDL yet. (Which is fine because we don't need it.)
 */
class AfasSoapClient {

  // @todo turn this into a static create() function if this means we can
  // subclass SoapClient. That last thing means it should not be a generic constructor (used from global code) though.
  // so document that you should not call AfasSoapClient::create() from anywhere?
  // @todo look up: you can only do this if you can call "$selected_client_class::create()" in PHP5.2.

  /**
   * Initializes a SOAP client object and other config values, after doing some
   * initial checks. Reads Drupal configuration variables.
   *
   * See AfasSoapConnection::setOptions() for various options that can/should be
   * set.
   *
   * @param AfasSoapConnection $afas_soap_connection
   *   AfasSoapConnection class.
   * @param string $type
   *   Type of AFAS connector. (This determines the SOAP endpoint URL.)
   *   Valid values: get / update / report / subject / data
   *
   * @return SoapClient|null
   *   Initialized client object, or null in which case errors may be retrieved
   *   from AfasSoapConnection::getLastCallInfo('error').
   *
   * @see AfasSoapConnection::setOptions()
   */
  protected function initClient($afas_soap_connection, $type) {
    if (!in_array($type, array('get', 'update', 'report', 'subject', 'data'))) {
      $afas_soap_connection->handleError(array(
        'error_source' => 'code',
        'error' => t("Invalid connector type %type", array('%type' => $type)),
        'error_message_safe' => TRUE,
      ));
      return NULL;
    }

    if (!function_exists('is_soap_fault')) {
      $afas_soap_connection->handleError(array(
        'error_source' => 'config',
        'error' => t('The SOAP extension is not compiled/enabled in PHP!'),
        'error_message_safe' => TRUE,
      ));
      return NULL;
    }

    // Get options as possibly set by calling code and/or configuration,
    // and set some defaults.
    $options = $afas_soap_connection->checkOptions();
    if (!$options) {
      return NULL;
    }
    $options += array(
      'login' => $options['domain'] . '\\' . $options['userId'],
      'encoding' => 'utf-8',
      // By default, don't throw exceptions on call. $afas_soap_connection may
      // throw exceptions during handleError() instead.
      'exceptions' => FALSE,
    );

    $endpoint = trim($options['urlBase'], '/') . '/'
                . strtolower($type) . 'connector.asmx';
    $wsdl_endpoint = NULL;
    if ($options['useWSDL']) {
      $wsdl_endpoint = $endpoint . '?WSDL';
      if ($options['cacheWSDL']) {
        // @todo note that this may not work when object is already instantiated (which is what happens after we redo the code? or not, because static?)
        ini_set('soap.wsdl_cache_ttl', $options['cacheWSDL']);
      }
    }
    else {
      $options += array(
        'location' => $endpoint,
        'uri' => 'urn:Afas.Profit.Services',
        'style' => SOAP_DOCUMENT,
        'use' => SOAP_LITERAL,
      );
    }
    // Since $options contains both client options and call / initialization /
    // ... arguments, filter only known client options.
    $client = new NtlmSoapClient($wsdl_endpoint,
      array_intersect_key($options, array_flip(array(
        'location',
        'uri',
        'style',
        'use',
        'soap_version',
        'cache_wsdl',
        'ssl_method',
        'login',
        'password',
        'proxy_host',
        'proxy_port',
        'proxy_login',
        'proxy_password',
        'connection_timeout',
        'keep_alive',
        'user_agent',
        'compression',
        'encoding',
        'classmap',
        'typemap',
        'exceptions',
        'trace',
        'stream_context',
        'features',
      ))));

    return $client;
  }

  /**
   * Sets up a SOAP connection to AFAS and calls a remote function. It should
   * not usually be necessary to call this function; it gets called from others.
   *
   * @param AfasSoapConnection $afas_soap_connection
   *   AfasSoapConnection class.
   * @param string $function
   *   Function name to call.
   * @param array $arguments
   *   Function arguments.
   * @param string $connector_type
   *   Type of connector: get / update / report / subject / data.
   *
   * @return string|null
   *   Response, or NULL for error. In the last case, error details can be
   *   retrieved through getLastCallInfo(), but they are probably printed/logged
   *   already.
   *   Be careful when checking only this return value for errors; some
   *   successful calls return an empty string.
   */
  public function callAfas($afas_soap_connection, $function, $arguments, $connector_type) {


    if ($client = $this->initClient($afas_soap_connection, $connector_type)) {

      $params = array();
      foreach ($arguments as $name => $value) {
        $params[] = new SoapParam($value, $name);
      }
      $response = $client->__soapCall($function, $params, array('soapaction' => 'urn:Afas.Profit.Services/' . $function));
      if (is_soap_fault($response)) {
        if (isset($response->detail)) {
          // 'detail' contains 'ProfitApplicationException', containing
          // 'ErrorNumber', 'Message' (== faultstring) and 'Detail'.
          $details = print_r($response->detail, TRUE);
        }
        else {
          // This contains the backtrace but wouldn't contain "details".
          $details = (string)$response;
        }
        $afas_soap_connection->handleError(array(
          'error_source' => 'client',
          'error' => $response->getMessage(),
          'error_detail' => $details,
        ));
      }
      else {
        return $response;
      }
    }
    return NULL;
  }

}

/**
 * A child of SoapClient with support for ntlm authentication
 *
 * Found on http://php.net/manual/en/soapclient.soapclient.php and modified.
 *
 * @author Meltir <meltir@meltir.com>
 *
 * @todo document some more (why we use this, and why the above client is not subclassing SoapClient)
 */
class NtlmSoapClient extends SoapClient {

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
    parent::__construct($wsdl, $options);
  }

  /**
   * Perform a SOAP call using curl with ntlm auth
   *
   * @param string $data
   * @param string $url
   * @param string $action
   *
   * @return string
   *
   * @throws SoapFault on curl connection error
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
      throw new SoapFault('CURL error: '. curl_error($handle), curl_errno($handle));
    }
    curl_close($handle);
    return $response;
  }

  /**
   * @inheritdoc
   */
  public function __doRequest($request, $location, $action, $version, $one_way = 0) {

    if (isset($this->options['style']) && isset($this->options['use'])
        && $this->options['style'] == SOAP_DOCUMENT
        && $this->options['use'] == SOAP_LITERAL) {
      // Based on http://www.ibm.com/developerworks/webservices/library/ws-whichwsdl/:
      // This server does 'document/literal wrapped', not 'document/literal'.
      // So we need to wrap the body in the function call. Which makes for
      // this ugly code which makes assumptions about $request and $action:
      $p1 = strpos($request, '<SOAP-ENV:Body>');
      if ($p1 !== FALSE) {
        $p1 += strlen('<SOAP-ENV:Body>');
        $p2 = strpos($request, '</SOAP-ENV:Body>');
        if ($p2 !== FALSE && $p2 > $p1) {
          $function = substr($action, strlen('urn:Afas.Profit.Services/'));
          $request = substr($request, 0, $p1) . "<$function xmlns=\"urn:Afas.Profit.Services\">"
                     . substr($request, $p1, $p2 - $p1) . "</$function>"
                     . substr($request, $p2);
        }
      }
    }
    return $this->callCurl($request, $location, $action);
  }

}
