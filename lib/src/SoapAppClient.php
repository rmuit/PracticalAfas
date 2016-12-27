<?php
/**
 * This file is part of the SimpleAfas package.
 *
 * (c) Roderik Muit <rm@wyz.biz>
 *
 * For the full copyright and license information, please view the LICENSE file
 * that was distributed with this source code.
 */

namespace SimpleAfas;

use \SoapClient;
use \SoapParam;
use \SoapVar;

/**
 * Wrapper around client specific details of making a remote AFAS call.
 *
 * This class contains one public method: callAfas(), and uses
 * - the SOAP library bundled with PHP5;
 * - An 'app connetor' for authentication.
 */
class SoapAppClient {

  /**
   * Configuration options.
   *
   * @var array
   */
  protected $options;

  /**
   * The SOAP client.
   *
   * @var \SimpleAfas\NtlmSoapClient
   */
  protected $soapClient;

  /**
   * The AFAS connector type for which the current SOAP client is initialized.
   *
   * @var string
   */
  protected $connectorType;

  /**
   * Constructor.
   *
   * Since there is no other way of setting options, we check them inside the
   * constructor and throw an exception if we know any AFAS calls will fail.
   *
   * @param array $options
   *   Configuration options. Some of them are used for configuring the actual
   *   NtlmSoapClient class; some are used as standard arguments in each SOAP
   *   call, some are used for both. Keys used:
   *   Required:
   *   - urlBase:     AFAS endpoint URL, without the connector specific part.
   *   - appToken:    Token used for the App connector.
   *                  variable if not set.
@TODO work out if token is environment specific?
   *
   *   Optional:
   *   - useWSDL:     TRUE/FALSE for using WSDL; uses 'afas_api_use_wsdl' config
   *                  variable if not set. MUST be FALSE/unspecified, so far.
   *   Other options (which are usually not camelCased but under_scored) are
   *   client specific; for all valid ones, see initClient().
   *
   * @throws \InvalidArgumentException
   *   If some option values are missing / incorrect.
   * @throws \Exception
   *   If something else went wrong / option values are unsupported.
   */
  public function __construct(array $options) {
//@todo 'environmentId',
    foreach (array('urlBase', 'appToken') as $required_key) {
      if (empty($options[$required_key])) {
        $classname = get_class($this);
        throw new \InvalidArgumentException("Required configuration parameter for $classname missing: $required_key.", 1);
      }
    }

    $this->options = $options;
  }

  /**
   * Returns a SOAP client object, configured with options previously set.
   *
   * @param string $type
   *   Type of AFAS connector. (This determines the SOAP endpoint URL.)
   *
   * @return \SoapClient
   *   Initialized SoapClient object.
   *
   * @throws \Exception
   *   If we failed to construct a SoapClient class.
   */
  protected function getSoapClient($type) {
    // If we support the token connector, this needs to change.
    if ($type === 'token') {
      $connector_path = strtolower($type). 'connector';
    }
    else {
      $connector_path = 'appconnector' . strtolower($type);
    }
    $endpoint = trim($this->options['urlBase'], '/') . "/$connector_path.asmx";

    if (!empty($this->soapClient)) {
      // We can reuse the SOAP client object if we have the same connector type
      // as last time.
      if ($type === $this->connectorType) {
        return $this->soapClient;
      }
      if (empty($options['useWSDL'])) {
        $this->connectorType = $type;
        $this->soapClient->__setLocation($endpoint);
        return $this->soapClient;
      }
      // If we use WSDL we have no way to change the location, because every
      // connector uses its own WSDL definition (and we have no way to change
      // the WSDL for an existing object). So we create a new object.
    }

    // Get options from this class; add defaults for the SOAP client.
    $options = $this->options + array(
//      'login' => $this->options['domain'] . '\\' . $this->options['userId'],
//@TODO yes or no?
//      'login' => 'AOL\\53478.YGR',
      'encoding' => 'utf-8',
      // We did this in Drupal's afas_api v2.x, but are throwing exceptions now
      // because that's much cleaner. The caller will need to interpret them.
      //'exceptions' => FALSE,
    );

// @TODO change this? And reinstate caching?
    $wsdl_endpoint = NULL;
    if (empty($options['useWSDL'])) {
      $options += array(
        'location' => $endpoint,
        'uri' => 'urn:Afas.Profit.Services',
        'style' => SOAP_DOCUMENT,
        'use' => SOAP_LITERAL,
      );
    }
    else {
      // TRYING:
      $wsdl_endpoint = $endpoint . '?WSDL';

//      $wsdl_endpoint = "public://afas_api_app_wsdl/$connector_path.wsdl";
//      if (!file_exists($wsdl_endpoint)) {
//        $wsdl_endpoint = drupal_realpath($wsdl_endpoint);
//        throw new \Exception("$wsdl_endpoint not present. You must download the WSDL definition from $endpoint?WSDL to that location manually (or turn off WSDL, or extend the NTLMSoapClient class), because NTLMSoapClient unfortunately cannot deal with the needed authentication at the WSDL stage (yet).", 8);
//      }
      // If we ever start supporting WSDL, it will be through a locally cached
      // file, because NtlmSoapClient just cannot reach the WSDL file directly,
      // before it(s parent SoapClient) needs it. So setting caching option with
      // ini_set does not make sense.
      //if ($options['cacheWSDL']) {
      //  ini_set('soap.wsdl_cache_ttl', $options['cacheWSDL']);
      //}
      //@todo If we call curl to fetch the WSDL (and remove the above exception)
      //      then implement caching timeout here; remove the file ourselves.
    }

    // Since $options contains both SOAPClient options and call/argument options
    // used by this class, filter only known SoapClient options.
    // @todo There are probably still curl specific options between the below
    //       which are unnecessary. Filter out at some point?
    $this->soapClient = new TestSoapClient($wsdl_endpoint,
//    $this->soapClient = new SoapClient($wsdl_endpoint,
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

    $this->connectorType = $type;

    return $this->soapClient;
  }

  /**
   * 'normalizes' / completes arguments for an AFAS SOAP function call.
   *
   * Split out from callAfas() for more convenient subclassing. Not meant to be
   * called from anywhere except callAfas().
   *
   * @param array $arguments
   *   Arguments for function; passed by reference; will be normalized.
   * @param string $function
   *   SOAP function name to call.
   */
  protected function normalizeArguments(&$arguments, $function) {
    // To get a token, we don't need a token.
    if ($this->connectorType !== 'token') {
      $arguments['token'] = '<token><version>1</version><data>' . $this->options['appToken'] . '</data></token>';
    }
    //      //@TODO DELETE
    //      'environmentId' => 'O53478AA',
    //      'userId' => $this->options['userId'],
    //      'password' => $this->options['password'],
  //      ), $arguments);
  }

  /**
   * Sets up a SOAP connection to AFAS and calls a remote function.
   *
   * @param string $connector_type
   *   Type of connector: get / update / report / subject / data.
   * @param string $function
   *   Function name to call.
   * @param array $arguments
   *   Function arguments.
   *
   * @return string
   *   The response from the SOAP endpoint.
   *
   * @throws \InvalidArgumentException
   *   For invalid arguments. (Unknown connector type.)
   * @throws \UnexpectedValueException
   *   If the SoapClient returned a response in an unknown format.
   * @throws \RuntimeException
   *   If the curl call returned an error.
   * @throws \SoapFault
   *   If the SOAP function execution encountered an error.
   * @throws \Exception
   *   For anything else that went wrong, e.g. initializing the SoapClient.
   */
  public function callAfas($connector_type, $function, array $arguments) {
    // Even though this may not be necessary, we want to restrict the connector
    // types to those we know. When adding a new one, we want to carefully check
    // whether we're not missing any arguments that we should be preprocessing.
    // @todo support token? versioninfo?
    if (!in_array($connector_type, array('get', 'update', 'report', 'subject', 'data', 'token'))) {
      throw new \InvalidArgumentException("Invalid connector type $connector_type", 18);
    }
    // This might ideally be checked inside the constructor already but we do it
    // here for easier subclassing. It doesn't matter much in practice anyway.
    if (!function_exists('is_soap_fault')) {
      throw new \Exception('The SOAP extension is not compiled/enabled in PHP.', 19);
    }

    $client = $this->getSoapClient($connector_type);

    $this->normalizeArguments($arguments, $function);

    $params = array();
    foreach ($arguments as $name => $value) {
      $params[] = new SoapVar($value, XSD_STRING, NULL, NULL, $name, 'urn:Afas.Profit.Services');
    }
    $function_wrapper = new SoapVar($params, SOAP_ENC_OBJECT, NULL, NULL, $function, 'urn:Afas.Profit.Services');
    $function_param = new SoapParam($function_wrapper, $function);

    if (!empty($options['useWSDL'])) {
      $response = $client->$function($function_param);
    }
    else {
      // The above call would set the SOAPAction HTTP header to
      // "urn:Afas.Profit.Services#GetDataWithOptions". Call __soapCall()
      // directly (rather than indirectly through a 'magic function' as above)
      // so that we can modify arguments.
      $response = $client->__soapCall($function, array($function_param), array('soapaction' => 'urn:Afas.Profit.Services/' . $function));
    }

    // See the WSDL definition: Every AFAS call returns a single-value
    // response with the single value always a string named XXXResponse.
    if (is_object($response) && isset($response->{"{$function}Response"})) {
      return $response->{"{$function}Response"};
    }
    elseif (is_string($response)) {
      // WSDL-less call returns a string.
      return $response;
    }
    else {
      throw new \UnexpectedValueException('Unknown response format: ' . json_encode($response), 17);
    }
  }

}
class TestSoapClient extends \SoapClient {

  private $options;

  /**
   * @inheritdoc
   */
  public function __construct($wsdl, $options = array()) {
    $this->options = $options;
    parent::__construct($wsdl, $options);
  }

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
//      curl_setopt($handle, CURLOPT_PROXYAUTH, CURLAUTH_NTLM);
    }
//    elseif (!empty($this->options['login'])) {
//      curl_setopt($handle, CURLOPT_USERPWD, $this->options['login'] . ':' . $this->options['password']);
//      curl_setopt($handle, CURLOPT_HTTPAUTH, CURLAUTH_NTLM);
//    }

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
//    return $this->callCurl($request, $location, $action);
    return parent::__doRequest($request, $location, $action, $version, $one_way);
  }

}
