<?php
/**
 * @file
 * Contains AfasNusoapClient.
 */

/**
 * Class AfasNusoapClient
 *
 * This overrides AfasSoapClient to work with the external (older) NuSOAP
 * library. It can be used if the (preferred) PHP SOAP extension is not
 * compiled/enabled on your server.
 *
 * Note v0.9.5 of the nuSOAP library cannot deal with AFAS servers without WSDL;
 * you need to do AfasSoapConnection::setOptions(array('useWSDL' => TRUE)) or
 * (since WSDL introduces overhead which is unnecessary with AFAS' simple call
 * structure) you need sources from https://github.com/rmuit/NuSOAP.
 */
class AfasNusoapClient {

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
   * @return nusoap_client|null
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

    if (!libraries_load('nusoap')) {
      $afas_soap_connection->handleError(array(
        'error_source' => 'config',
        'error' => t('The required NuSOAP library could not be loaded!'),
        'error_message_safe' => TRUE,
      ));
      return NULL;
    }

    // Make sure the aging nuSOAP code does not make PHP5.3 give strict timezone
    // warnings.
    // Note: date_default_timezone_set() is also called in D7's standard
    // drupal_session_initialize() / D8's drupal_set_configured_timezone().
    // So I don't think this is necessary... Still, to be 100% sure:
    if (!ini_get('date.timezone')) {
      if (!$timezone = variable_get('date_default_timezone')) {
        $timezone = @date_default_timezone_get();
      }
      date_default_timezone_set($timezone);
    }

    // Get options as possibly set by calling code and/or configuration,
    // and set some defaults.
    $options = $afas_soap_connection->checkOptions();
    if (!$options) {
      return NULL;
    }
    $options += array(
      'soap_defencoding' => 'utf-8',
      'xml_encoding' => 'utf-8',
      'decode_utf8' => FALSE,
    );

    // available: get/update/report/subject/dataconnector.
    $endpoint = trim($options['urlBase'], '/') . '/'
                . strtolower($type) . 'connector.asmx';

    if ($options['useWSDL']) {
      $endpoint .= '?WSDL';

      if ($options['cacheWSDL']) {
        // Get cached WSDL
        $cache = new wsdlcache(file_directory_temp(), $options['cacheWSDL']);
        $wsdl  = $cache->get($endpoint);
        if (is_null($wsdl)) {
          $wsdl = new wsdl();
          $wsdl->setCredentials($options['domain'] . '\\' . $options['userId'] , $options['password'], 'ntlm');
          $wsdl->fetchWSDL($endpoint);
          if ($error = $wsdl->getError()) {
            $afas_soap_connection->handleError(array(
              'error_source' => 'wsdl',
              'error' => $error,
              // At this point, getdebug() isn't completely horrible yet.
              'error_detail' => $wsdl->getDebug(),
            ));
            return NULL;
          }
          $cache->put($wsdl);
        }
        $endpoint = $wsdl;
      }
    }
    $client = new nusoap_client($endpoint, $options['useWSDL']);
    $client->setCredentials($options['domain'] . '\\' . $options['userId'], $options['password'], 'ntlm');
    $client->useHTTPPersistentConnection();

    // Specific connection properties can be set by the caller.
    // About timeouts:
    // AFAS has their 'timeout' value on the server set to 5 minutes, and gives no
    // response until it sends the result of a call back. So changing the
    // 'timeout' (default 0) has no effect; the 'response_timeout' can be upped to
    // max. 5 minutes.
    foreach (array(
               'soap_defencoding',
               'xml_encoding',
               'timeout',
               'response_timeout',
               'soap_defencoding',
               'decode_utf8'
             ) as $opt) {

      if (isset($options[$opt])) {
        $client->$opt = $options[$opt];
      }
    }
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

      if ($client->endpointType === 'wsdl') {
        $response = $client->call($function, $arguments);
      }
      else {
        $response = $client->call($function, $arguments, 'urn:Afas.Profit.Services', 'urn:Afas.Profit.Services/' . $function, FALSE, NULL, 'document', 'literal wrapped');
      }
      if ($error = $client->getError()) {
        if (isset($response->detail)) {
          // NuSOAP's $client->getDebug() is just unusable. It includes
          // duplicate info and lots of HTML font colors etc (or is that my
          // settings influencing var_dump output? That still doesn't change
          // the fact that it's unusable though).
          // There are some details in there that are not in $response, like the
          // parameters (but we already have those in
          // $afas_soap_connection->lastCallInfo) and HTTP headers sent/received
          // $response now actually is an array with 'faultcode', 'faultstring'
          // and 'detail' keys - 'detail' contains 'ProfitApplicationException'
          // containing 'ErrorNumber', 'Message' (== faultstring) and 'Detail'.
          $details = print_r($response, TRUE);
        }
        else {
          // Too bad; we don't have anything else than this...
          // (If ->detail isn't set, then probably $response is false. If it is
          // not false, we don't know yet which option is better.)
          $details = $client->getDebug();
        }
        $afas_soap_connection->handleError(array(
          'error_source' => 'client',
          'error' => $error,
          'error_detail' => $details,
        ));
      }
      elseif (isset($response[$function . 'Result'])) {
        return $response[$function . 'Result'];
      }
      else {
        $afas_soap_connection->handleError(array(
          'error_source' => 'code',
          'error' => 'NuSOAP response array has unexpected structure!',
          'error_message_safe' => TRUE,
          'error_detail' => print_r($response, TRUE),
        ));
      }
    }
    return NULL;
  }

}
