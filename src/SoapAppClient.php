<?php
/**
 * This file is part of the PracticalAfas package.
 *
 * (c) Roderik Muit <rm@wyz.biz>
 *
 * For the full copyright and license information, please view the LICENSE file
 * that was distributed with this source code.
 */

namespace PracticalAfas;

use \SoapParam;
use \SoapVar;

/**
 * Wrapper around client specific details of making a remote AFAS call.
 *
 * This class contains one public method: callAfas(), and uses
 * - the SOAP library bundled with PHP5;
 * - An 'app connector' for authentication.
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
   * @var \PracticalAfas\NtlmSoapClient
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
   *   - customerId:      Customer ID, as used in the AFAS endpoint URL.
   *   - appToken:        Token used for the App connector.
   *   Optional:
   *   - soapClientClass: classname for the actual Soap client to use. Should be
   *                      compatible with PHP's SoapClient.
   *   - useWSDL:         boolean. (Suggestion: don't set it.)
   *   - cacheWSDL:       How long the WSDL should be cached locally in seconds.
   *   Other options (which are usually not camelCased but under_scored) are
   *   specific to the actual Soap client.
   *
   * @throws \InvalidArgumentException
   *   If some option values are missing / incorrect.
   * @throws \Exception
   *   If something else went wrong / option values are unsupported.
   */
  public function __construct(array $options) {
    foreach (array('customerId', 'appToken') as $required_key) {
      if (empty($options[$required_key])) {
        $classname = get_class($this);
        throw new \InvalidArgumentException("Required configuration parameter for $classname missing: $required_key.", 1);
      }
    }

    // Add defaults for the SOAP client.
    $options += array(
      'encoding' => 'utf-8',
      'soapClientClass' => '\SoapClient',
    );

    $this->options = $options;
  }

  /**
   * Returns a SOAP client object, configured with options previously set.
   *
   * @param string $type
   *   Type of AFAS connector. (This determines the SOAP endpoint URL.)
   * @param string $endpoint
   *   (optional) The SOAP endpoint URL to use. (It's generally not necessary to
   *   set this because AFAS has a well defined structure for its endpoint URLs.
   *   If this somehow changes, it's possible to pass this argument in a
   *   subclass to override the defaults.)
   *
   * @return \SoapClient
   *   Initialized SoapClient object.
   *
   * @throws \Exception
   *   If we failed to construct a SoapClient class.
   */
  protected function getSoapClient($type, $endpoint = '') {
    if (!$endpoint) {
      if ($type === 'token') {
        $connector_path = strtolower($type). 'connector';
      }
      else {
        $connector_path = 'appconnector' . strtolower($type);
      }
      $endpoint = 'https://' . $this->options['customerId'] . ".afasonlineconnector.nl/profitservices/$connector_path.asmx";
    }

    if (!empty($this->soapClient)) {
      // We can reuse the SOAP client object if we have the same connector type
      // as last time.
      if ($type === $this->connectorType) {
        return $this->soapClient;
      }
      if (empty($this->options['useWSDL'])) {
        $this->connectorType = $type;
        $this->soapClient->__setLocation($endpoint);
        return $this->soapClient;
      }
      // If we use WSDL we have no way to change the location, because every
      // connector uses its own WSDL definition (and we have no way to change
      // the WSDL for an existing object). So we create a new object.
    }

    $options = $this->options;
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
      $wsdl_endpoint = $endpoint . '?WSDL';
      if ($options['cacheWSDL']) {
        ini_set('soap.wsdl_cache_ttl', $options['cacheWSDL']);
      }
    }

    // $options contains both SoapClient options and call/argument options used
    // by this class. We shouldn't be passing the latter ones to our client, so
    // we should be doing some filtering at this point. But since that's a bit
    // hard to do generically now that we have a configurable soapClientClass,
    // we'll try to get away with just passing everything, for now. (A previous
    // version of the code contained a list of SoapClient / Curl options.)
    $this->soapClient = new $options['soapClientClass']($wsdl_endpoint, $options);
    $this->connectorType = $type;

    return $this->soapClient;
  }

  /**
   * Validates / completes arguments for an AFAS SOAP function call.
   *
   * Split out from callAfas() for more convenient subclassing. Not meant to be
   * called from anywhere except callAfas().
   *
   * This class is not meant to make decisions about any actual data sent. (That
   * kind of code would belong in Connection.) The arguments set here would
   * typically be for e.g. authentication rather than data manipulation.
   *
   * @param array $arguments
   *   Arguments for function.
   * @param string $function
   *   SOAP function name to call.
   *
   * @return array
   *   The arguments, possibly changed.
   *
   * @throws \InvalidArgumentException
   *   For invalid function arguments.
   */
  protected function validateArguments($arguments, $function) {
    // To get a token, we don't need a token.
    if ($this->connectorType !== 'token') {
      $arguments['token'] = '<token><version>1</version><data>' . $this->options['appToken'] . '</data></token>';
    }
    if ($this->connectorType === 'get') {
      if (empty($arguments['take'])) {
        // For get connectors (both getData and getDataWithOptions), there is an
        // issue with the skip & take arguments. The WSDL suggests they are both
        // required, though testing says that the 'skip' argument is perfectly
        // OK to leave out. However if 'take' is left out, nothing is returned,
        // which suggests that it defaults to '0' (which returns no data). This
        // class is not in the business of forcing any logic on the arguments,
        // but since the behavior of returning nothing by default is so
        // confusing, we'll throw an exception if this is about to happen (which
        // we do here, not in Connection, so people can't miss it).
        throw new \InvalidArgumentException("'take' argument must not be empty/zero, otherwise no results are returned.", 41);
      }
      if (!is_numeric($arguments['take']) || $arguments['take'] < 0) {
        throw new \InvalidArgumentException("'take' argument must be a positive number.", 42);
      }
      if (!empty($arguments['skip']) && (!is_numeric($arguments['skip']) || $arguments['skip'] < 0)) {
        throw new \InvalidArgumentException("'skip' argument must be a positive number.", 43);
      }
    }

    return $arguments;
  }

  /**
   * Sets up a SOAP connection to AFAS and calls a remote function.
   *
   * @param string $connector_type
   *   Type of connector: get / update / report / subject / data.
   * @param string $function
   *   Function name to call.
   * @param array $arguments
   *   Function arguments. Integer values should actually be integers, not
   *   strings.
   *
   * @return string
   *   The response from the SOAP endpoint.
   *
   * @throws \InvalidArgumentException
   *   For invalid function arguments or unknown connector type.
   * @throws \UnexpectedValueException
   *   If the SoapClient returned a response in an unknown format.
   * @throws \SoapFault
   *   If the SOAP function execution encountered an error.
   * @throws \Exception
   *   For anything else that went wrong, e.g. initializing the SoapClient.
   */
  public function callAfas($connector_type, $function, array $arguments) {
    // Even though this may not be necessary, we want to restrict the connector
    // types to those we know. When adding a new one, we want to carefully check
    // whether we're not missing any arguments that we should be preprocessing.
    if (!in_array($connector_type, array('get', 'update', 'report', 'subject', 'data', 'token', 'versioninfo'))) {
      throw new \InvalidArgumentException("Invalid connector type $connector_type", 40);
    }

    $client = $this->getSoapClient($connector_type);

    $arguments = $this->validateArguments($arguments, $function);

    $params = array();
    foreach ($arguments as $name => $value) {
// We could specify integer values as 'int' like the below, but the examples
// from AFAS' documentation do not do this either. It just bloats the XML with
// namespaces. We can start doing it if ever necessary.
//      if (is_int($value)) {
//        $params[] = new SoapVar($value, XSD_STRING, 'int', 'http://www.w3.org/2001/XMLSchema', $name, 'urn:Afas.Profit.Services');
//      }
//      else {
//        $params[] = new SoapVar($value, XSD_STRING, 'string', 'http://www.w3.org/2001/XMLSchema', $name, 'urn:Afas.Profit.Services');
//      }
        $params[] = new SoapVar($value, XSD_STRING, NULL, NULL, $name, 'urn:Afas.Profit.Services');
    }
    $function_wrapper = new SoapVar($params, SOAP_ENC_OBJECT, NULL, NULL, $function, 'urn:Afas.Profit.Services');
    $function_param = new SoapParam($function_wrapper, $function);

    if (!empty($this->options['useWSDL'])) {
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
    // response with the single value always a string named XXXResult.
    if (is_object($response) && isset($response->{"{$function}Result"})) {
      return $response->{"{$function}Result"};
    }
    elseif (is_string($response)) {
      // WSDL-less call returns a string.
      return $response;
    }
    else {
      throw new \UnexpectedValueException('Unknown response format: ' . json_encode($response), 24);
    }
  }

}
