<?php
/**
 * @file
 * Contains AfasSoapConnection and AfasSoapClient.
 */

/**
 * Class AfasSoapConnection.
 *
 * This contains general functions to make most AFAS SOAP calls and error
 * handling easier.
 * It also contains horribly long field definitions for update connectors, but
 * since this class isn't expected to be used in resource critical situations,
 * it has not been split up yet.
 */
class AfasSoapConnection {

  /**
   * Wrapper class around the SOAP client library.
   *
   * @var string
   */
  private $clientClassName;

  /**
   * Options for the SOAP client / call arguments. (These are merged into one
   * array, because they actually overlap - some are used for both.)
   *
   * @var array
   */
  private $options;

  /**
   * Ways of reporting errors.
   *
   * @var int
   */
  private $errorReporting;

  /**
   * Info about the last call.
   *
   * @var array
   */
  private $lastCallInfo;

  /**
   * Constructor function.
   *
   * @param string $client_class_name
   *   The name of the class to use which contains soap-client specific
   *   functionality in a call() method. It is almost never
   *   necessary to provide this argument, because 'default' uses the SoapClient
   *   class which comes bundled with PHP5.
   */
  public function __construct($client_class_name = 'default') {
    // By default, do not print errors to the screen.
    $this->errorReporting = AFAS_LOG_ERROR_DETAILS_WATCHDOG;

    if (empty($client_class_name) || $client_class_name == 'default') {
      // It is possible to override usage of the standard client by another
      // class (e.g. 'AfasNusoapClient'), in a site-config variable. This is
      // supposedly used so infrequently that there is no config UI for this.
      $this->clientClassName = variable_get('afas_api_client_class', 'AfasSoapClient');
    }
    else {
      $this->clientClassName = $client_class_name;
    }
  }

  /**
   * Retrieve data from AFAS through a GET connector.
   *
   * @param string|int $data_id
   *   Identifier for the data. (Usually the name of the AFAS 'get connector',
   *   but this can differ with $data_type.)
   * @param array $filters
   *   Filters to apply before returning data
   * @param string $data_type
   *   Type of data to retrieve and, for get connectors, the format in which to
   *   return it.
   *   'get':        $data_id is a get connector; return data as array.
   *   'get_simplexml: $data_id is a get connector; return data as
   *                   SimpleXMLElement. This is slightly faster than 'get'
   *   'report':     $data_id is the report ID for a report connector.
   *   'attachment': $data_id is the 'subject ID' (int) for a subject connector.
   *   'data':       $data_id is the function name for which to retrieve the XSD
   *                 schema. (This used to be called "DataConnector"; in 2014
   *                 that term has disappeared from the online documentation.)
   * @param array $extra_arguments
   *   Other arguments to pass to the soap call, besides the ones which are
   *   hardcoded in this function for convenience. (For get connectors, these
   *   are 'connectorId' and 'filters'; for others, see code below.)
   *   Specifying this argument usually unnecessary. To see what it can be used
   *   for, check the code in normalizeArguments() and/or the WSDL/documentation
   *   of the AFAS SOAP endpoint.
   *
   * @return string|array|bool|SimpleXMLElement
   *   See $data_type; FALSE when error occurred.
   */
  function getData($data_id, $filters = array(), $data_type = 'get', $extra_arguments = array()) {

    if (!is_array($extra_arguments)) {
      return FALSE;
    }

    $function = '';
    if (is_string($data_type)) {
      switch (strtolower($data_type)) {
        case 'get':
        case 'get_simplexml':
          $extra_arguments['connectorId'] = $data_id;
          if (!empty($filters)) {
            $extra_arguments['filters'] = $filters;
          }
          $connector_type = 'get';
          $function = 'GetDataWithOptions';
          break;

        case 'report':
          $extra_arguments['reportID'] = $data_id;
          if (!empty($filters)) {
            $extra_arguments['filters'] = $filters;
          }
          $connector_type = 'report';
          $function = 'Execute';
          break;

        case 'attachment':
          $extra_arguments['subjectID'] = $data_id;
          $connector_type = 'subject';
          $function = 'GetAttachment';
          break;

        case 'data':
          // Oct 2014: I finally saw the first example of a 'DataConnector' in the
          // latest version of the online documentation, at
          // http://profitdownload.afas.nl/download/Connector-XML/DataConnector_SOAP.xml
          // (on: Connectors > Call a Connector > SOAP call > UpdateConnector,
          //  which is https://static-kb.afas.nl/datafiles/help/2_9_5/SE/EN/index.htm#App_Cnnctr_Call_SOAP_Update.htm)
          // Funny thing is: there is NO reference of "DataConnector" in the
          // documentation anymore!
          // dataID is apparently hardcoded (as far as we know there is no other
          // function for the so-called 'DataConnector' that getting XML schema):
          $extra_arguments['dataID'] = 'GetXmlSchema';
          $extra_arguments['parametersXml'] = "<DataConnector><UpdateConnectorId>$data_id</UpdateConnectorId><EncodeBase64>false</EncodeBase64></DataConnector>";
          $connector_type = 'data';
          $function = 'Execute';
      }
    }
    else {
      $this->handleError(array(
        'error_source' => 'call',
        'error' => t('AFASSoapConnection::getData() called with unknown data_type argument %type!',
          array('%type' => is_scalar($data_type) ? strval($data_type) : '<non-scalar>')),
        'error_message_safe' => TRUE,
      ));
    }

    if ($function) {
      $data = $this->call($function, $extra_arguments, $connector_type);
      if ($data) {

        // Check the (normalized) options which influence the output format.
        // See normalizeArguments() for default option values which are set in
        // $extra_arguments.
        if ($function == 'GetDataWithOptions') {
          $extra_arguments = $this->getlastCallInfo('arguments');
          if ($extra_arguments['options_array']['Outputmode'] == 2) {
            // We don't support text output. There seems to be no reason for it,
            // but if you see one, feel free to create/test/send in a patch.
            // (Possibly implementing $data_type = 'get_text' and overwriting
            // Outputmode above?)
            //
            // We've made the SOAP call for nothing. That's just to keep this
            // check inside this function, while still allowing subclasses to
            // override Outputmode.
            $this->handleError(array(
              'error_source' => 'call',
              'error' => 'The afas_api_get_data() function cannot handle text output!'
            ));
            return FALSE;
          }
        }

        // What to return?
        switch (strtolower($data_type)) {
          case 'get':
            // Walk through the SimpleXMLElement to create array of arrays (items)
            // of string values (fields). We assume each first-level XML element
            // is a row containing fields without any further nested tags.
            $doc_element = new SimpleXMLElement($data);
            $items = array();

            if ($function == 'GetDataWithOptions' &&
                $extra_arguments['options_array']['Outputoptions'] == 3) {

              foreach ($doc_element as $row_element) {
                // The XML may contain empty tags. These are empty SimpleXMLElements,
                // but we want to convert them to empty strings.
                $items[] = array_map('strval', (array) $row_element);
              }
            }
            else {
              foreach ($doc_element as $row_element) {
                // All fields inside an 'item' are strings; we just need to
                // convert the item (SimpleXMLElement) itself.
                $items[] = (array) $row_element;
              }
            }
            return $items;

          case 'get_simplexml':
            return new SimpleXMLElement($data);

          default:
            return $data;
        }
      }
    }
    return FALSE;
  }

  /**
   * Calls AFAS 'Update connector' with standard arguments, and XML constructed
   * from a data array in a (strict) format by this class.
   *
   * @param $connector_name
   *   Name of the UpdateConnector
   * @param array $data
   *   Data to construct the XML from. See constructXml().
   * @param string $fields_action
   *   Action to fill in 'fields' tag; can be 'insert', 'update', 'delete', ''.
   *
   * @return bool|mixed
   *   Response object from SOAP call, or FALSE for error. In the last case,
   *   error details can be retrieved through getLastCallInfo(), but
   *   they are printed/logged already (depending on getErrorReporting()).
   *   Be careful when checking only this return value for errors; some
   *   successful calls return an empty string.
   *
   * @see constructXml()
   * @see xmlTypeInfo()
   */
  public function sendData($connector_name, $data, $fields_action = '') {
    return $this->call(
      'Execute',
      array(
        'logonAs' => '',
        'connectorType' => $connector_name,
        'connectorVersion' => '1',
        'dataXml' => $this->constructXml($connector_name, $data, $fields_action),
      ),
      'update'
    );
  }

  /**
   * Calls AFAS 'Update connector' with standard arguments and an XML string.
   *
   * @param $connector_name
   *   Name of the UpdateConnector
   * @param $xml
   *   XML string as specified by AFAS. (See their XSD Schemas.)
   *
   * @return bool|mixed
   *   Response object from SOAP call, or FALSE for error. In the last case,
   *   error details can be retrieved through getLastCallInfo(), but
   *   they are printed/logged already (depending on getErrorReporting()).
   *   Be careful when checking only this return value for errors; some
   *   successful calls return an empty string.
   */
  function sendXml($connector_name, $xml) {
    // This is just a 'shorthand' to hide away all those confusing arguments
    // to call() that we never want to see or change.
    return $this->call(
      'Execute',
      array(
        'logonAs' => '',
        'connectorType' => $connector_name,
        'connectorVersion' => '1',
        'dataXml' => $xml,
      ),
      'update'
    );
  }

  /**
   * Sets up a SOAP connection to AFAS and calls a remote function. It should
   * not usually be necessary to call this function; it gets called from others.
   *
   * @param string $function
   *   Function name to call.
   * @param array $arguments
   *   Function arguments.
   * @param string $connector_type
   *   Type of connector: get / update / report / subject / data.
   *
   * @return bool|mixed
   *   Response object from NuSOAP call, or FALSE for error. In the last case,
   *   error details can be retrieved through getLastCallInfo(), but
   *   they are printed/logged already.
   *   Be careful when checking only this return value for errors; some
   *   successful calls return an empty string.
   */
  public function call($function, $arguments, $connector_type) {

    // Process remote function arguments into something the remote system
    // expects.
    $this->normalizeArguments($arguments, $function, $connector_type);

    // Clear error, remember current function and normalized arguments.
    $this->lastCallInfo = array(
      'function' => $function,
      'arguments' => $arguments,
    );
    unset($arguments['options_array']);
    unset($arguments['filters']);

    try {
      $class = new $this->clientClassName;
      return $class->callAfas($this, $function, $arguments, $connector_type);
    }
    catch (exception $e) {
      $this->handleError(array(
        'error_source' => 'code',
        'error' => t('SOAP call threw exception: @error',
          array('@error' => $e->getMessage())),
        'error_message_safe' => TRUE,
        'error_detail' => $e->getFile() . ':' . $e->getLine(),
      ));
    }
  }

  /**
   * Returns meta info about the last SOAP call or other errors.
   * This is the way to get error information after a function returned FALSE.
   *
   * @param string $type
   *   Type of information to return. Can be:
   *   - empty: see return value
   *   - error_message_safe - boolean; if nonempty, this specifies 'error' is
   *                          sanitized already
   *   - error:               string; error message
   *   - error_detail:        string; detailed error info
   *   - error_source:        string; can be "code" (should never happen),
   *      "call" (invalid parameters etc), "config" (missing configuration),
   *      "wsdl" and "client" (probably remote errors).
   *   - function:            string; SOAP function name. Only filled for
   *                          error_source "wsdl" and "client".
   *   - arguments:           array; SOAP function arguments.
   *   - time:                string; timestamp for error.
   *
   * @return mixed
   *  If $type is empty, all these values are returned in an encompassing array.
   *  In that case, not all elements are guaranteed to be set (see param).
   */
  public function getLastCallInfo($type = '') {
    $last_call = $this->lastCallInfo;
    if (!empty($type)) {
      return isset($last_call[$type]) ? $last_call[$type] : '';
    }
    return $last_call;
  }

  /**
   * Returns the current methods through which errors are reported.
   *
   * @return int
   *   Methods mask
   *
   * @see setErrorReporting()
   */
  public function getErrorReporting() {
    return $this->errorReporting;
  }

  /**
   * Sets error reporting methods
   *
   * @param int $methods_mask
   *   Ways to log/display errors reported by the AFAS endpoint. The value acts
   *   as a bit mask; usually you will want to set AFAS_LOG_ERRORS_WATCHDOG or
   *   AFAS_LOG_ERROR_DETAILS_WATCHDOG, and optionally add
   *   "| AFAS_LOG_ERRORS_SCREEN" or "| AFAS_LOG_ERROR_DETAILS_SCREEN".
   *
   */
  public function setErrorReporting($methods_mask) {
    $this->errorReporting = $methods_mask;
  }

  /**
   * Handles errors: stores them for later retrieval by other code and does
   * logging/printing on screen according to earlier set properties.
   *
   * @param array $error_info
   *   Error info to remember/handle; see getLastCallInfo() for details.
   *
   * @see getLastCallInfo()
   *
   * @todo option to throw errors in case of error, instead of logging/printing.
   */
  public function handleError($error_info) {

    // Set info for other code. Keep existing function name if it's set; the
    // class may have detected an error after another call was done.
    if (!$this->lastCallInfo) {
      $this->lastCallInfo = array();
    }
    $this->lastCallInfo = array_intersect_key($this->lastCallInfo, array_flip(array(
        'function',
        'arguments',
      ))) + $error_info;
    // This is equivalent to date('r') except it does not emit warnings if
    // the current timezone is not set in php.ini.
    $this->lastCallInfo['time'] = date_format(date_create(), 'r');

    // Log to watchdog, error plus (optionally) details into one message.
    if ($this->errorReporting & AFAS_LOG_ERROR_DETAILS_WATCHDOG) {
      // Do not double-escape error messages.
      $message_placeholder = $this->getLastCallInfo('error_message_safe')
        ? '!error' : '@error';
      $details = $this->getLastCallInfo('error_detail');
      $function = $this->getLastCallInfo('function');
      if ($function) {
        // $details contains newlines which we want to honor when viewing
        // watchdog logs on screen. But we also do not want to assume its value
        // is safe. So we cannot replace newlines by <br> because those will be
        // escaped with the rest of the value. (Unless we sanitize all of
        // $details before saving.) Therefore: enclose in <pre>.
        // Since we're already doing that for $details, do for $args too.
        watchdog('afas_api', "Error: $message_placeholder<br>Function: @function<br>Arguments:<br><pre>@args</pre><br><strong>Error details:</strong><br><pre>@detail</pre>",
          array(
            $message_placeholder => $this->getLastCallInfo('error'),
            '@function' => $this->getLastCallInfo('function'),
            '@args' => print_r($this->getLastCallInfo('arguments'), TRUE),
            '@detail' => $details ? $details : '-'),
          WATCHDOG_ERROR);
      }
      else {
        watchdog('afas_api', "Source: @source<br>Error: $message_placeholder<br>Error details:<br><pre>@detail</pre>",
          array(
            '@source' => $this->getLastCallInfo('error_source'),
            $message_placeholder => $this->getLastCallInfo('error'),
            '@detail' => $details ? $details : '-'),
          WATCHDOG_ERROR);
      }
    }
    elseif ($this->errorReporting & AFAS_LOG_ERRORS_WATCHDOG) {
      $message_placeholder = $this->getLastCallInfo('error_message_safe')
        ? '!error' : '@error';
      $function = $this->getLastCallInfo('function');
      if ($function) {
        watchdog('afas_api', "Error: $message_placeholder<br/>Function: @function<br/>Arguments: @args",
          array(
            $message_placeholder => $this->getLastCallInfo('error'),
            '@function' => $this->getLastCallInfo('function'),
            '@args' => print_r($this->getLastCallInfo('arguments'), TRUE)), WATCHDOG_ERROR);
      }
      else {
        watchdog('afas_api', "Source: @source<br>Error: $message_placeholder",
          array(
            '@source' => $this->getLastCallInfo('error_source'),
            $message_placeholder => $this->getLastCallInfo('error')), WATCHDOG_ERROR);
      }
    }

    // Log to screen,(optionally) details in separate message.
    if ($this->errorReporting & (AFAS_LOG_ERRORS_SCREEN | AFAS_LOG_ERROR_DETAILS_SCREEN)) {
      // You can't just print arguments; one argument may be the full XML which is too long.
      $details = $this->getLastCallInfo('error_detail');
      $function = $this->getLastCallInfo('function');
      if ($function) {
        $args = $this->getLastCallInfo('arguments');
        if ($args) {
          $arg = implode(', ', array_diff_key($args, array_flip(array(
            'environmentId',
            'userId',
            'password',
            'logonAs',
            'connectorVersion'
          ))));
          if (strlen($arg) > 100) {
            $arg = substr($arg, 0, 100) . '...';
          }
          $function .= '(' . check_plain($arg) . '): ';
        }
      }
      $message = $function . ($this->getLastCallInfo('error_message_safe')
          ? $this->getLastCallInfo('error')
          : check_plain($this->getLastCallInfo('error')));
      if ($details
          && $this->errorReporting & AFAS_LOG_ERROR_DETAILS_WATCHDOG
          && !($this->errorReporting & AFAS_LOG_ERROR_DETAILS_SCREEN)) {
        $message .= '<br/>See Recent Log Messages for more details.';
      }
      drupal_set_message($message, 'error');

      if ($details && $this->errorReporting & AFAS_LOG_ERROR_DETAILS_SCREEN) {
        // We can replace newlines by <br> but since some of the AFAS error
        // details are formatted for display in a monospace font, enclose in
        // <pre> instead.
        drupal_set_message('Error details:<br><pre>'
                           . check_plain($details) . '</pre>',
          'error');
      }
    }
  }

  /**
   * Sets options for the SOAP client and function call. This function probably
   * only has effect before making a SOAP call.
   *
   * Some of the options are set on the SOAP client object; some are (also)
   * used as standard arguments to a SOAP call, some are used in other ways.
   * These are merged into one array, because they actually overlap - some need
   * to be used for both client options and call arguments.
   *
   * No error checking is done inside this function; this is done when init() is
   * called from within another function that makes a SOAP call.
   *
   * The class should be fully usable without calling this function, as long
   * as some site-wide AFAS configuration is done.
   *
   * @param array $options
   *   Array of options which will be used to set up client object. Keys used:
   *   - urlBase:     AFAS endpoint URL (without the variable last part).
   *                  uses 'afas_api_url' config variable if not set.
   *   - environment: AFAS environment name; uses 'afas_api_environment' config
   *                  variable if not set.
   *   - domain       AFAS (NTLM) domain name; uses 'afas_api_domain' config
   *                  variable if not set.
   *   - userId:      user id; uses 'afas_api_user' config variable if not set.
   *   - password:    password; uses 'afas_api_pw' config variable if not set.
   *   - useWSDL:     TRUE/FALSE for using WSDL; uses 'afas_api_use_wsdl' config
   *                  variable if not set.
   *   Other options (which are usually not camelCased but under_scored) are
   *   client specific classes; for all valid ones, see the initClient() method
   *   of the specific soap client's class.
   *
   * @see AfasSoapClient::initClient()
   * @see AfasNusoapClient::initClient()
   */
  public function setOptions($options) {
    $this->options = $options;
  }

  /**
   * Checks and initializes options used by init() / SOAP calls (which were
   * optionally set before by setOptions(). This function is not meant to be
   * called directly; it's split out from initClient() only to ease subclassing.
   *
   * @return array
   *   The initialized options. Empty array means an error was encountered.
   */
  public function checkOptions() {

    // Environment is not used here for setting values on the client object;
    // it's used as a standard argument to every SOAP call. We still check it
    // here, together with user/password, because we need to check those here:
    // They are both set on the client object and used as standard arguments.
    if (!isset($this->options['urlBase'])) {
      $this->options['urlBase'] = variable_get('afas_api_url',
                      'https://profitweb.afasonline.nl/profitservices');
    }
    if (empty($this->options['urlBase'])) {
      $this->handleError(array(
        'error_source' => 'config',
        'error' => t('AFAS endpoint URL(base) is not set. Maybe you forgot to set the module configuration?'),
        'error_message_safe' => TRUE,
      ));
      return array();
    }
    if (!isset($this->options['environment'])) {
      $this->options['environment'] = variable_get('afas_api_environment', '');
    }
    if (empty($this->options['environment'])) {
      $this->handleError(array(
        'error_source' => 'config',
        'error' => t('AFAS environment is not set. Maybe you forgot to set the module configuration?'),
        'error_message_safe' => TRUE,
      ));
      return array();
    }
    if (!isset($this->options['domain'])) {
      $this->options['domain'] = variable_get('afas_api_domain', 'AOL');
    }
    if (empty($this->options['domain'])) {
      $this->handleError(array(
        'error_source' => 'config',
        'error' => t('AFAS domain is not set.'),
        'error_message_safe' => TRUE,
      ));
      return array();
    }
    if (!isset($this->options['userId'])) {
      $this->options['userId'] = variable_get('afas_api_user', '');
    }
    if (empty($this->options['userId'])) {
      $this->handleError(array(
        'error_source' => 'config',
        'error' => t('AFAS user ID is not set. Maybe you forgot to set the module configuration?'),
        'error_message_safe' => TRUE,
      ));
      return array();
    }
    if (!isset($this->options['password'])) {
      $this->options['password'] = variable_get('afas_api_pw', '');
    }
    if (empty($this->options['password'])) {
      $this->handleError(array(
        'error_source' => 'config',
        'error' => t('AFAS user password is not set. Maybe you forgot to set the module configuration?'),
        'error_message_safe' => TRUE,
      ));
      return array();
    }
    if (!isset($this->options['useWSDL'])) {
      $this->options['useWSDL'] = variable_get('afas_api_use_wsdl', FALSE);
    }
    if (!isset($this->options['cacheWSDL'])) {
      $this->options['cacheWSDL'] = variable_get('afas_api_cache_wsdl', 86400);
    }

    return $this->options;
  }

  /**
   * 'normalizes' arguments for an AFAS SOAP function call.
   * After calling this function, the arguments will be complete, and there will
   * be two arguments which should not be passed to the function, but which are
   * only useful for inspection to see what's been done:
   * - 'filters': a normalized / expanded array of filters;
   * - 'options_array': all options for the GetDataWithOptions function, as an
   *    array. ('options' now contains a corresponding XML string.)
   *
   * @param array $arguments
   *   Arguments for function name; will be added to / normalized.
   * @param string $function
   *   SOAP function name to call.
   * @param string $connector_type
   *   Connector type.
   */
  protected static function normalizeArguments(&$arguments, $function, $connector_type) {

    // Arguments that only apply to all functions:
    // (try to keep arguments in specified order of function definition.
    // Not that it matters, but it reads better while debugging.)
    $arguments = array_merge(array(
      'environmentId' => variable_get('afas_api_environment'),
      'userId' => variable_get('afas_api_user'),
      'password' => variable_get('afas_api_pw'),
    ), $arguments);

    // Process arguments that only apply to specific functions.

    // options
    if ($function == 'GetDataWithOptions') {

      // Turn 'options' argument into XML fragment. Always set options.
      // If $arguments['options'] is not an array, it's silently ignored.
      $options = (isset($arguments['options']) && is_array($arguments['options'])) ?
        $arguments['options'] : array();
      $options += array(
        // From AFAS docs:
        // Outputmode: 1=XML, 2=Text
        'Outputmode' => 1,
        // Metadata: 0=No, 1=Yes
        'Metadata' => 0,
        // Outputoptions: For XML: 2(Microsoft Data set) or 3(Data set including empty values). Default value is 2.
        /* For text, "outputoption 1, 2 ,3 and 4 are valid values, just like in the existing GetData:
          1 = Puntkomma (datums en getallen in formaat van regionale instellingen)
          2 = Tab       (datums en getallen in formaat van regionale instellingen)
          3 = Puntkomma (datums en getallen in vast formaat)
          4 = Tab       (datums en getallen in vast formaat)
          Vast formaat betekent: dd-mm-yy voor datums en punt als decimaal scheidingteken voor getallen."
        */
        'Outputoptions' => 2,
      );
      $options_str = '';
      foreach ($options as $option => $value) {
        $options_str .= "<$option>$value</$option>";
      }
      $arguments['options_array'] = $options;
      $arguments['options'] = "<options>$options_str</options>";
    }

    // filtersXML
    if ($function == 'GetDataWithOptions' || $function == 'GetData'
        || ($function == 'Execute' && $connector_type == 'report')) {

      // Turn 'filters' input argument (array) into 'filtersXML' argument accepted
      // by AFAS (XML string).
      // v1 of this module used 'filtersXML' to pass filter arguments - which were
      // not XML. v2 used 'filters'. We now accept both, but will process
      // 'filtersXML' only if 'filters' is empty.
      // If the argument is not an array, it will be silently ignored.
      $filters = array();
      if (!empty($arguments['filters'])) {
        $filters = is_array($arguments['filters']) ? $arguments['filters'] : array();
        unset($arguments['filters']);
      }
      elseif (!empty($arguments['filtersXml'])) {
        $filters = is_array($arguments['filtersXml']) ? $arguments['filtersXml'] : array();
      }
      if ($filters) {

        /* Operators from AFAS documentation:
          1 = Gelijk aan
          2 = Groter dan of gelijk aan
          3 = Kleiner dan of gelijk aan
          4 = Groter dan
          5 = Kleiner dan
          6 = Bevat
          7 = Ongelijk aan
          8 = Moet leeg zijn
          9 = Mag niet leeg zijn
          10 = Begint met
          11 = Bevat niet
          12 = Begint niet met
          13 = eindigt met tekst
          14 = eindigt niet met tekst
        */
        // The non-numeric array values are added by us, to make the input arguments less cryptic.
        // To prevent errors, we'll have several 'signs' resolve to the same op.
        $operators = array(
          '=' => 1,
          '==' => 1,
          '>=' => 2,
          '<=' => 3,
          '>' => 4,
          '<' => 5,
          'LIKE' => 6,      // Note: does NOT resolve to 'starts with'!
          'CONTAINS' => 6,
          '!=' => 7,
          '<>' => 7,
          'NULL' => 8,
          'IS NULL' => 8,
          'NOT NULL' => 9,
          'IS NOT NULL' => 9,
          'STARTS' => 10,
          'STARTS WITH' => 10,
          'NOT LIKE' => 11,
          'NOT CONTAINS' => 11,
          'DOES NOT CONTAIN' => 11,
          'NOT STARTS' => 12,
          'DOES NOT START WITH' => 12,
          'ENDS' => 13,
          'ENDS WITH' => 13,
          'NOT ENDS' => 14,
          'DOES NOT END WITH' => 14,
        );

        /* Different syntaxes for $filters have been introduced over time:
         *
         * 1) array(FIELD1 => VALUE1, ...) - to filter on one or several values.
         *     This is the simplest one, with a lot of use cases - and one which
         *     is too 'natural' for coders, to stop supporting it.
         *
         * 2) the same, but get the 'operator' from $arguments['filter_operator'].
         *     Is ok, but only allows one and the same operator for all filters.
         *
         * 3) array(
         *     array(FIELD1 => VALUE1, ..., [ '#op' => operator1  ]),
         *     array(FIELD3 => VALUE3, ..., [ '#op' => operator2  ]),
         *    )
         *     This supports multiple operators but is harder to write/read.
         *
         * We want to keep supporting 1 for easier readability (and 2 for backward
         * compatibility), but to prevent strange errors, we'll also support '#op'
         * in the first array level; this overrides 'filter_operator'.
         * Operators can be numeric (AFAS like) as well as the values above.
         * Anything unresolved will default to '='.
         */
        $filters_str = '';
        $operator = !empty($filters['#op']) ? $filters['#op'] : '';
        if (!$operator) {
          $operator = !empty($arguments['filter_operator']) ? $arguments['filter_operator'] : 1;
        }
        if (!is_numeric($operator)) {
          $operator = !empty($operators[$operator]) ? $operators[$operator] : 1;
        }
        foreach ($filters as $outerfield => &$filter) {
          if (is_array($filter)) {
            // Process extra layer

            // Get operator; normalize $filters for reference by callers.
            $op = (!empty($filter['#op'])) ? $filter['#op'] : $operator;
            if (!is_numeric($op)) {
              $op = !empty($operators[$op]) ? $operators[$op] : 1;
            }
            $filter['#op'] = $op;

            // Construct filter(s) in this sections
            foreach ($filter as $key => $value) {
              if ($key != '#op') {
                $filters_str .= '<Field FieldId="' . $key . '" OperatorType="' . $op . '">' . check_plain($value) . '</Field>';
              }
            }
          }
          else {
            // Construct 1 filter in this section, with standard operator.
            $filters_str .= '<Field FieldId="' . $outerfield . '" OperatorType="' . $operator . '">' . check_plain($filter) . '</Field>';

            // Normalize $filters for reference by callers.
            $filter = array(
              $outerfield => $filter,
              '#op' => $operator,
            );
          }
        }
        unset($filters['#op']);
        unset($filters['filter_operator']);
        $arguments['filters'] = $filters;

        // There can be multiple 'Filter' tags with FilterID. We only need to use
        // one, it can contain all our filtered fields...
        $arguments['filtersXml'] = '<Filters><Filter FilterId="Filter1">' . $filters_str . '</Filter></Filters>';
      }
    }
  }

  /**
   * Construct XML representing one or more AFAS 'items', which is suitable for
   * sending through an AFAS UpdateConnector.
   *
   * This is an evolving function containing lots of (maybe incomplete)
   * hardcoded logic and comment fragments from incomplete info in AFAS'
   * knowledge base. Because of this volatility, $data must adhere to a strict
   * structure; the function will throw exceptions when e.g. required data is
   * not present, present data is not recognized, mixed array/string values are
   * passed in $data, ...
   *
   * See admin/config/services/afas/schema for getting more XSD schema info to
   * make this function more robust. The information from those XSD schemas is
   * more complete, so if you want to use those schemas to construct XML for
   * sending through sendXml() instead of using this function, that's fine. This
   * function exists for those who would like to construct array data with more
   * descriptive keys, instead of an XML string with hard to remember tag names.
   *
   * We hope that the below code catches all strange/dangerous combinations of
   * 'id' /  $fields_action / AutoNum / MatchXXX values and 'embedding items',
   * and made enough comments in xmlTypeInfo() to explain AFAS' behavior. But we
   * can't be totally sure.
   * Please read the comments at MatchPer/MachOga details before dealing with
   * knPerson/knOrganisation objects; it may save lots of time and wrong
   * assumptions.
   *
   * @param string $type
   *   The type of item; this (usually?) corresponds to the outer tag in the XML
   *   string / an 'updateConnectorId' The function throws an exception if the
   *   type is not known by this function.
   * @param array $data
   *   Data to construct the XML from.
   *   This can represent one item, in which case all keys in this array are XML
   *   tags or aliases (defined in this function, see the code) and all values
   *   except 'objects' must be scalars. $data can also represent an array of
   *   (one or more) items; in that case all values must be arrays (representing
   *   a single item).
   *   'objects' must contain one or more items or arrays-of-items (i.e. arrays
   *   which could be standalone $data arguments), keyed by their type (again:
   *   XML tag or alias). These items' XML will be embedded inside the XML of
   *   the object.
   *   An item can have two other key-value pairs that do not represent real
   *   item values:
   *   - #id: the 'id attribute' of an item in XML. Only some item types have
   *     'id attributes' (most have id numbers in separate tags).
   *   - #action: the 'fields action' (see $fields_action) to perform for a
   *     nested item, if that is different from $fields_action. This must never
   *     be set for an 'outer' item; use $fields_action parameter instead.
   * @param string $fields_action
   *   Action to fill in 'fields' tag; can be 'insert', 'update', 'delete', ''.
   *   Combination of $fields_action == 'insert' & non-empty id is allowed
   *   (probably you have autonumbering turned off, if you do this).
   *   Combination of $fields_action == 'update' & empty id is logical only
   *   when the item has a 'MatchXXX' property; see definitions.
   * @param string $parent_type
   *   If nonempty, the generated XML will be a fragment suitable for embedding
   *   within the parent type, which is slightly different from standalone XML.
   * @param int $indent
   *   Add spaces before each tag and end each line except the last one with
   *   newline, unless $indent < 0 (then do not add any spaces or newlines).
   *   (Default: -1.)
   *
   * @return string
   *   The constructed XML.
   *
   * @throws Exception
   *
   * @see xmlTypeInfo()
   */
  public function constructXml($type, $data, $fields_action = '', $parent_type = '', $indent = -1) {
    // This can be a static function later, when we know PHP5.2 is dead and
    // we can call static::xmlTypeInfo(). (self::xmlTypeInfo() is not good
    // because we need to call the method in extending classes.)

    if (!in_array($fields_action, array('insert', 'update', 'delete', ''), TRUE)) {
      throw new Exception(t('Unknown value %value for fields_action parameter',
        array('%value' => $fields_action, '@type' => $type)));
    }

    // We set tab width in a variable even though we never change it.
    $tab_width = 2;

    // Item header
    $xml = '';
    $indent_str = '';
    $extra_spaces = '';
    if ($indent >= 0) {
      // This will be used to add $tab_width spaces to $indent_str, each time we
      // enter inside a tag. See below.
      $extra_spaces = str_repeat(' ', $tab_width);

      // This is the initial value _after_ the outer XML line. While being built,
      // the last line in $xml will not end with a newline:
      $indent_str = "\n" . str_repeat(' ', $indent + $tab_width);

      $xml = str_repeat(' ', $indent);
    }
    $xml .= '<' . $type . ($parent_type ? '>'
        : ' xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">');

    // Determine if $element holds a single item or an array of items:
    // if one item, all item-values except 'object' must be scalars (or at least
    // cast-able to a string?); if several, all values must be arrays.
    // We test for an array value. (Only one; we just assume $data has a correct
    // structure.) Then we 'normalize' to a multi-item array.
    $value = reset($data);
    while (key($data) === 'objects') {
      $value = next($data);
    }
    if (!is_array($value)) {
      $data = array($data);
    }

    foreach ($data as $key => $element) {

      // Construct element with fields within the XML. (For each element inside
      // the loop, because $info can differ with $data.)
      $info = $this->xmlTypeInfo($type, $parent_type, $data, $fields_action);
      if (empty($info)) {
        throw new Exception(t('No XML definition present for item type @type.',
          array('@type' => $type)));
      }
      /* About the field definitions:
       * - if required = TRUE and default is given, then
       *   - the default value is sent if no data value is passed
       *   - an exception is (only) thrown if the passed value is NULL.
       * - if the default is NULL (or value given is NULL & not 'required') then
       *   <$tag xsi:nil=\"true\"/> is passed.
       */

      // Action and Id are set inside 'fake properties' in the data array; use
      // them and unset them.
      if (isset($data['#action'])) {
        if (empty($parent_type)) {
          // This really is an override and we want to keep it that way.
          // When possible, people must specify a correct $fields_action.
          throw new Exception(t('#action override is only allowed in embedded objects.'));
        }
        // Not sure whether '' makes sense as an override?
        // Also maybe we should disallow deletes inside inserts etc?
        if (!in_array($data['#action'], array('insert', 'update', 'delete'), TRUE)) {
          throw new Exception(t('Unknown value %value for #action inside @type',
            array('%value' => $data['#action'], '@type' => $type)));
        }
        $action = $data['#action'];
        unset($data['#action']);
      }
      else {
        $action = $fields_action;
      }

      if (!empty($data['#id']) && empty($info['id_field'])) {
        throw new Exception(t('Id value provided but no id-field defined for item @type.',
          array('@type' => $type)));
      }

      $xml .= $indent_str . '<Element'
              . (empty($data['#id']) ? '' : ' ' . $info['id_field'] . '="'
                                            . $data['#id'] . '"')
              . '>';
      unset($data['#id']);
      $indent_str .= $extra_spaces;

      $xml .= $indent_str . '<Fields'
              . ($action ? " Action=\"$action\"" : '') . '>';

      // Convert all of our item data into tags, check required tags, and add
      // default values for tags (where defined).
      foreach ($info['fields'] as $tag => $map_properties) {
        $value_present = TRUE;

        // Get value from the property equal to the tag (case sensitive!), or the
        // alias. If two values are present with both tag and alias, we throw an
        // exception.
        if (array_key_exists($tag, $element)) {
          $value = $element[$tag];
          unset($element[$tag]);
        }
        elseif (isset($map_properties['alias'])
                && array_key_exists($map_properties['alias'], $element)) {
          $value = $element[$map_properties['alias']];
          unset($element[$map_properties['alias']]);
        }
        elseif (array_key_exists('default', $map_properties)) {
          $value = $map_properties['default'];
        }
        else {
          $value_present = FALSE;
        }

        // Required fields will disallow non-passed values, or passed null values.
        if (!empty($map_properties['required'])
            && (!$value_present || !isset($value))) {
          throw new Exception(t('No value given for item @type, required property @property.',
            array('@type' => $type, '@property' => $tag . (isset($map_properties['alias']) ? " ({$map_properties['alias']})" : ''))));
        }

        if ($value_present) {
          if (isset($value) && !empty($map_properties['type'])) {
            switch ($map_properties['type']) {
              case 'boolean':
                $value = $value ? '1' : '0';
                break;
              case 'long':
              case 'decimal':
                if (!is_numeric($value)) {
                  throw new Exception(t('Property @property in item @type must be numeric.',
                    array('@type' => $type, '@property' => $tag . (isset($map_properties['alias']) ? " ({$map_properties['alias']})" : ''))));
                }
                if ($map_properties['type'] == 'long' && strpos((string) $value, '.') !== FALSE) {
                  throw new Exception(t("Property @property in item @type must be a 'long'.",
                    array('@type' => $type, '@property' => $tag . (isset($map_properties['alias']) ? " ({$map_properties['alias']})" : ''))));
                }
                // For decimal, we could also check total/fraction digits, but
                // we're not going that far yet.
                break;
              case 'date':
                // @todo format in standard way, once we know that's necessary
                break;
            }
          }
          $xml .= $indent_str . $extra_spaces . (isset($value)
              ? "<$tag>" . self::xmlEncode($value) . "</$tag>"
              // Value is passed but NULL, or default value is null:
              : "<$tag xsi:nil=\"true\"/>");
        }
      }
      $xml .= $indent_str . '</Fields>';

      // Add other embedded objects
      if (isset($element['objects'])) {
        if (!is_array($element['objects'])) {
          throw new Exception(t("'objects' property in item type @type must be array.",
            array('@type' => $type)));
        }

        foreach ($element['objects'] as $data_key => $embedded_data) {

          // Like tags, we allow objects to be defined by their official type name
          // or our self-defined alias.
          if (isset($info['objects'][$data_key])) {
            $object_type = $data_key;
          }
          elseif (!isset($info['objects'])
                  || !$object_type = array_search($data_key, $info['objects'], TRUE)) {
            throw new Exception(t('Embedding @embedded_type inside item type @type is not supported.',
              array('@type' => $type, '@embedded_type' => $data_key)));
          }
          $xml .= $indent_str . "<Objects>" . ($indent < 0 ? '' : "\n")
                  . $this->constructXml(
              $object_type,
              $embedded_data,
              $action,
              $type,
              $indent < 0 ? $indent : $indent + 3 * $tab_width
            ) . $indent_str . '</Objects>';
        }
        unset($element['objects']);
      }

      // Throw error for unknown item data (for which we have not seen a tag).
      if (!empty($element)) {
        throw new Exception(t('Unused data values provided for item type @type: keys are @keys.',
          array('@type' => $type, '@keys' => "'" . implode(', ', array_keys($element)) . "'")));
      }

      // Add closing XML tags.
      if ($indent >= 0) {
        $xml .=  "\n" . str_repeat(' ', $indent + $tab_width) . '</Element>';
      }
      else {
        $xml .= '</Element>';
      }
    }

    // Add closing XML tag.
    if ($indent >= 0) {
      // Do not end the whole string with newline.
      $xml .=  "\n" . str_repeat(' ', $indent) . "</$type>";
    }
    else {
      $xml .= "</$type>";
    }

    return $xml;
  }

  /**
   * Encode text for inclusion inside XML tags.
   * @param string $text
   * @param string $encoding
   * @return string
   */
  private static function xmlEncode($text, $encoding = 'utf-8') {
    // check_plain() / ENT_QUOTES converts single quotes to &#039; which is
    // illegal in XML so we can't use it for sanitizing.) The below is
    // equivalent to "htmlspecialchars($text, ENT_XML1)", but also valid in
    // PHP < 5.4.
    return str_replace("'", '&apos;',
      htmlspecialchars($text, ENT_COMPAT, $encoding));
  }

  /**
   * Return info for a certain XML type (dataConnectorId) definition.
   *
   * @param string $type
   *   The type of item; this (usually?) corresponds to the outer tag in the XML
   *   string / an 'updateConnectorId' The function throws an exception if the
   *   type is not known by this function.
   * @param string $parent_type
   *   If nonempty, the generated XML will be a fragment suitable for embedding
   *   within the parent type; this can influence the presence of some fields.
   * @param array $data
   *   Data to construct the XML from. This can influence e.g. some defaults.
   * @param string $fields_action
   *   Action to fill in 'fields' tag; can be 'insert', 'update', 'delete', ''.
   *   This can influence e.g. some defaults.
   *
   * @return array
   *   Array with possible keys: 'id_field', 'fields' and 'objects'. See
   *   the code.
   *
   * @throws Exception
   *
   * @see constructXml()
   */
  protected static function xmlTypeInfo($type, $parent_type, $data, $fields_action) {

    $info = array();
    switch ($type) {
      // Even though they are separate types, there is no standalone
      // updateConnector for addresses.
      case 'KnBasicAddressAdr':
      case 'KnBasicAddressPad':
        $info = array(
          'fields' => array(
            // Land (verwijzing naar: Land => AfasKnCountry)
            'CoId' => array(
              'alias' => 'country_code',
            ),
            /*   PbAd = 'is postbusadres' (if True, HmNr has number of P.O. box)
             *   Ad, HmNr, ZpCd are required.
             *      (and a few lines below, the docs say:)
             *   Rs is _also_ " 'essential', even if ResZip==true, because if Zip
             *      could not be resolved, the specified value of Rs is taken."
             *      So we'll make it required too.
             */
            'PbAd' => array(
              'alias' => 'is_po_box',
              'type' => 'boolean',
              'default' => FALSE,
            ),
            // Toev. voor straat
            'StAd' => array(
            ),
            // Straat
            'Ad' => array(
              'alias' => 'street',
              'required' => TRUE,
            ),
            // Huisnummer
            'HmNr' => array(
              'alias' => 'house_number',
              'type' => 'long',
              'required' => TRUE,
            ),
            // Toev. aan huisnr.
            'HmAd' => array(
              'alias' => 'house_number_add',
            ),
            // Postcode
            'ZpCd' => array(
              'alias' => 'zip_code',
              'required' => TRUE,
            ),
            // Woonplaats (verwijzing naar: Woonplaats => AfasKnResidence)
            'Rs' => array(
              'alias' => 'town',
              'required' => TRUE,
            ),
            // Adres toevoeging
            'AdAd' => array(
            ),
            // From "Organisaties toevoegen en wijzigen (UpdateConnector KnOrganisation)":
            // Bij het eerste adres (in de praktijk bij een nieuw record) hoeft u geen begindatum aan te leveren in het veld 'BeginDate' genegeerd.
            // Als er al een adres bestaat, geeft u met 'BeginDate' de ingangsdatum van de adreswijziging aan.
            // Ingangsdatum adreswijziging (wordt genegeerd bij eerste datum)
            'BeginDate' => array(
              'type' => 'date',
              'default' => date('Y-m-d', REQUEST_TIME),
            ),
            'ResZip' => array(
              'alias' => 'resolve_zip',
              'type' => 'boolean',
              'default' => FALSE,
            ),
          ),
        );
        break;

      case 'KnContact':
        $info = array(
          'objects' => array(
            'KnBasicAddressAdr' => 'address',
            'KnBasicAddressPad' => 'postal_address',
          ),
          'fields' => array(
            // Code organisatie
            'BcCoOga' => array(
            ),
            // Code persoon
            'BcCoPer' => array(
            ),
            // Postadres is adres
            'PadAdr' => array(
              'type' => 'boolean',
            ),
            // Afdeling contact
            'ExAd' => array(
            ),
            // Functie (verwijzing naar: Tabelwaarde,Functie contact => AfasKnCodeTableValue)
            'ViFu' => array(
            ),
            // Functie op visitekaart
            'FuDs' => array(
            ),
            // Correspondentie
            'Corr' => array(
              'type' => 'boolean',
            ),
            // Voorkeursmedium (verwijzing naar: Tabelwaarde,Medium voor correspondentie => AfasKnCodeTableValue)
            'ViMd' => array(
            ),
            // Telefoonnr. werk
            'TeNr' => array(
              'alias' => 'phone',
            ),
            // Fax werk
            'FaNr' => array(
              'alias' => 'fax',
            ),
            // Mobiel werk
            'MbNr' => array(
              'alias' => 'mobile',
            ),
            // E-mail werk
            'EmAd' => array(
              'alias' => 'email',
            ),
            // Homepage
            'HoPa' => array(
              'alias' => 'homepage',
            ),
            // Toelichting
            'Re' => array(
              'alias' => 'comment',
            ),
            // Geblokkeerd
            'Bl' => array(
              'alias' => 'blocked',
              'type' => 'boolean',
            ),
            // T.a.v. regel
            'AtLn' => array(
            ),
            // Briefaanhef
            'LeHe' => array(
            ),
            // Sociale netwerken
            'SocN' => array(
            ),
            // Facebook
            'Face' => array(
              'alias' => 'facebook',
            ),
            // LinkedIn
            'Link' => array(
              'alias' => 'linkedin',
            ),
            // Twitter
            'Twtr' => array(
              'alias' => 'twitter',
            ),
            // Persoon toegang geven tot afgeschermde deel van de portal(s)
            'AddToPortal' => array(
              'type' => 'boolean',
            ),
            // E-mail toegang
            'EmailPortal' => array(
            ),
          ),
        );
        if ($parent_type == 'KnOrganisation' || $parent_type == 'KnPerson') {
          $info['fields'] += array(
            // Soort Contact
            // Values:  AFD:Afdeling bij organisatie   AFL:Afleveradres
            // if inside knOrganisation: + PRS:Persoon bij organisatie (alleen mogelijk i.c.m. KnPerson tak)
            //
            // The description in 'parent' update connectors' (KnOrganisation, knContact) KB pages is:
            // "Voor afleveradressen gebruikt u de waarde 'AFL': <ViKc>AFL</ViKc>"
            'ViKc' => array(
              'alias' => 'contact_type',
            ),
          );

          // According to the XSD, a knContact can contain a knPerson if it's
          // inside a knOrganisation, but not if it's stendalone.
          if ($parent_type == 'KnOrganisation') {
            $info['objects']['KnPerson'] = 'person';

            // If we specify a person in the data too, 'Persoon' is the default.
            if (!empty($data['objects']['KnPerson']) || !empty($data['objects']['person'])) {
              $info['fields']['ViKc']['default'] = 'PRS';
            }
          }

          unset($info['fields']['BcCoOga']);
          unset($info['fields']['BcCoPer']);
          unset($info['fields']['AddToPortal']);
          unset($info['fields']['EmailPortal']);
        }
        break;

      case 'KnPerson':
        $info = array(
          'objects' => array(
//            'KnBankAccount' => 'bank_account',
            'KnBasicAddressAdr' => 'address',
            'KnBasicAddressPad' => 'postal_address',
            'KnContact' => 'contact',
          ),
          'fields' => array(
            // Postadres is adres
            'PadAdr' => array(
              'type' => 'boolean',
            ),
            'AutoNum' => array(
              'type' => 'boolean',
              // See below for a dynamic default
            ),
            /**
             * If you specify MatchPer, the difference between $fields_action
             * 'update' and 'insert' falls away: if there is a match (but only
             * one), the existing record is updated. If there isn't, a new one
             * is inserted. If there are multiple matches, or a wrong match
             * method is specified, AFAS throws an error.
             *
             * We make sure that you must explicitly specify a value for this
             * with $field_action 'update' (and otherwise get an error), by
             * setting the default - see further down.
             *
             * NOTE - for Qoony sources in 2011 (which inserted KnPerson objects
             *   inside KnSalesRelationPer), 3 had the comment
             *   "match customer by mail". They used 3 until april 2014, when
             *   suddenly updates broke, giving "organisation vs person objects"
             *   and "multiple person objects found for these search criteria"
             *   errors. So apparently the official description (below) was not
             *   accurate until 2014, and maybe the above was implemented?
             *   While fixing the breakage, AFAS introduced an extra value for
             *   us:
             * 9: always update the knPerson objects (which are at this moment
             *    referenced by the outer object) with the given data.
             *    (When inserting instead of updating data, I guess this falls
             *    back to behavior '7', given our usage at Qoony.)
             */
            // Persoon vergelijken op
            // Values:  0:Zoek op BcCo (Persoons-ID)   1:Burgerservicenummer   2:Naam + voorvoegsel + initialen + geslacht   3:Naam + voorvoegsel + initialen + geslacht + e-mail werk   4:Naam + voorvoegsel + initialen + geslacht + mobiel werk   5:Naam + voorvoegsel + initialen + geslacht + telefoon werk   6:Naam + voorvoegsel + initialen + geslacht + geboortedatum   7:Altijd nieuw toevoegen
            'MatchPer' => array(
              'alias' => 'match_method',
              'default' => '0',
            ),
            // Organisatie/persoon (intern)
            // From "Organisaties toevoegen en wijzigen (UpdateConnector KnOrganisation)":
            // "Do not deliver the 'BcId' field."
            // (Because it really is internal. So why should we define it?)
            //'BcId' => array(
            //  'type' => 'long',
            //),
            // Nummer, 1-15 chars
            'BcCo' => array(
              // 'ID' would be more confusing because it's not the internal ID.
              'alias' => 'number',
            ),
            'SeNm' => array(
              'alias' => 'search_name',
            ),
            // Roepnaam
            'CaNm' => array(
              'alias' => 'name',
            ),
            // Voornaam
            'FiNm' => array(
              'alias' => 'first_name',
              'required' => TRUE,
            ),
            // initials
            'In' => array(
              'alias' => 'initials',
            ),
            'Is' => array(
              'alias' => 'prefix',
            ),
            'LaNm' => array(
              'alias' => 'last_name',
              'required' => TRUE,
            ),
            // Geboortenaam apart vastleggen
            'SpNm' => array(
              'type' => 'boolean',
              'default' => FALSE,
            ),
            // Voorv. geb.naam
            'IsBi' => array(
            ),
            // Geboortenaam
            'NmBi' => array(
            ),
            // Voorvoegsel partner
            'IsPa' => array(
            ),
            // Geb.naam partner
            'NmPa' => array(
            ),
            // Naamgebruik (verwijzing naar: Tabelwaarde,Naamgebruik (meisjesnaam etc.) => AfasKnCodeTableValue)
            // Values:  0:Geboortenaam   1:Geb. naam partner + Geboortenaam   2:Geboortenaam partner   3:Geboortenaam + Geb. naam partner
            'ViUs' => array(
            ),
            // Sex (M = Man, V = Vrouw, O = Onbekend)
            'ViGe' => array(
              'alias' => 'sex',
              'default' => 'O',
            ),
            // Nationaliteit (verwijzing naar: Tabelwaarde,Nationaliteit (NEN1888) => AfasKnCodeTableValue)
            // Values:  000:Onbekend   NL:Nederlandse   DZ:Algerijnse   AN:Angolese   RU:Burundische   RB:Botswaanse   BU:Burger van Burkina Faso   RCA:Centrafrikaanse   KM:Comorese   RCB:Kongolese   DY:Beninse   ET:Egyptische   EQ:Equatoriaalguinese   ETH:Etiopische   DJI:Djiboutiaanse   GA:Gabonese   WAG:Gambiaanse   GH:Ghanese   GN:Guinese   CI:Ivoriaanse   CV:Kaapverdische   TC:Kameroense   EAK:Kenyaanse   CD:ZaÃ¯rese   LS:Lesothaanse   LB:Liberiaanse   LAR:Libische   RM:Malagassische   MW:Malawische   RMM:Malinese   MA:Marokkaanse   RIM:Burger van Mauritanië   MS:Burger van Mauritius   MOC:Mozambiquaanse   SD:Swazische   RN:Burger van Niger   WAN:Burger van Nigeria   EAU:Ugandese   GW:Guineebissause   ZA:Zuidafrikaanse   ZW:Zimbabwaanse   RWA:Rwandese   ST:Burger van SÃ£o TomÃ© en Principe   SN:Senegalese   WAL:Sierraleoonse   SUD:Soedanese   SP:Somalische   EAT:Tanzaniaanse   TG:Togolese   TS:Tsjadische   TN:Tunesische   Z:Zambiaanse   ZSUD:Zuid-Soedanese   BS:Bahamaanse   BH:Belizaanse   CDN:Canadese   CR:Costaricaanse   C:Cubaanse   DOM:Burger van Dominicaanse Republiek   EL:Salvadoraanse   GCA:Guatemalteekse   RH:HaÃ¯tiaanse   HON:Hondurese   JA:Jamaicaanse   MEX:Mexicaanse   NIC:Nicaraguaanse   PA:Panamese   TT:Burger van Trinidad en Tobago   USA:Amerikaans burger   RA:Argentijnse   BDS:Barbadaanse   BOL:Boliviaanse   BR:Braziliaanse   RCH:Chileense   CO:Colombiaanse   EC:Ecuadoraanse   GUY:Guyaanse   PY:Paraguayaanse   PE:Peruaanse   SME:Surinaamse   ROU:Uruguayaanse   YV:Venezolaanse   WG:Grenadaanse   KN:Burger van Saint Kitts-Nevis   SK:Slowaakse   CZ:Tsjechische   BA:Burger van Bosnië-Herzegovina   GE:Burger van Georgië   AFG:Afgaanse   BRN:Bahreinse   BT:Bhutaanse   BM:Burmaanse   BRU:Bruneise   K:Kambodjaanse   CL:Srilankaanse   CN:Chinese   CY:Cyprische   RP:Filipijnse   TMN:Burger van Toerkmenistan   RC:Taiwanese   IND:Burger van India   RI:Indonesische   IRQ:Iraakse   IR:Iraanse   IL:Israëlische   J:Japanse   HKJ:Jordaanse   TAD:Burger van Tadzjikistan   KWT:Koeweitse   LAO:Laotiaanse   RL:Libanese   MV:Maldivische   MAL:Maleisische   MON:Mongolische   OMA:Omanitische   NPL:Nepalese   KO:Noordkoreaanse   OEZ:Burger van Oezbekistan   PK:Pakistaanse   KG:Katarese   AS:Saoediarabische   SGP:Singaporaanse   SYR:Syrische   T:Thaise   AE:Burger van de Ver. Arabische Emiraten   TR:Turkse   UA:Burger van Oekraine   ROK:Zuidkoreaanse   VN:Viëtnamese   BD:Burger van Bangladesh   KYR:Burger van Kyrgyzstan   MD:Burger van Moldavië   KZ:Burger van Kazachstan   BY:Burger van Belarus (Wit-Rusland)   AZ:Burger van Azerbajdsjan   AM:Burger van Armenië   AUS:Australische   PNG:Burger van Papua-Nieuwguinea   NZ:Nieuwzeelandse   WSM:Westsamoaanse   RUS:Burger van Rusland   SLO:Burger van Slovenië   AG:Burger van Antigua en Barbuda   VU:Vanuatuse   FJI:Fijische   GB4:Burger van Britse afhankelijke gebieden   HR:Burger van Kroatië   TO:Tongaanse   NR:Nauruaanse   USA2:Amerikaans onderdaan   LV:Letse   SB:Solomoneilandse   SY:Seychelse   KIR:Kiribatische   TV:Tuvaluaanse   WL:Sintluciaanse   WD:Burger van Dominica   WV:Burger van Sint Vincent en de Grenadinen   EW:Estnische   IOT:British National (overseas)   ZRE:ZaÃ¯rese (Congolese)   TLS:Burger van Timor Leste   SCG:Burger van Servië en Montenegro   SRB:Burger van Servië   MNE:Burger van Montenegro   LT:Litouwse   MAR:Burger van de Marshalleilanden   BUR:Myanmarese   SWA:Namibische   499:Staatloos   AL:Albanese   AND:Andorrese   B:Belgische   BG:Bulgaarse   DK:Deense   D:Duitse   FIN:Finse   F:Franse   YMN:Jemenitische   GR:Griekse   GB:Brits burger   H:Hongaarse   IRL:Ierse   IS:IJslandse   I:Italiaanse   YU:Joegoslavische   FL:Liechtensteinse   L:Luxemburgse   M:Maltese   MC:Monegaskische   N:Noorse   A:Oostenrijkse   PL:Poolse   P:Portugese   RO:Roemeense   RSM:Sanmarinese   E:Spaanse   VAT:Vaticaanse   S:Zweedse   CH:Zwitserse   GB2:Brits onderdaan   ERI:Eritrese   GB3:Brits overzees burger   MK:Macedonische   XK:Kosovaar
            //
            'PsNa' => array(
            ),
            // Geboortedatum
            'DaBi' => array(
            ),
            // Geboorteland (verwijzing naar: Land => AfasKnCountry)
            'CoBi' => array(
            ),
            // Geboorteplaats (verwijzing naar: Woonplaats => AfasKnResidence)
            'RsBi' => array(
            ),
            // BSN
            'SoSe' => array(
              'alias' => 'bsn',
            ),
            // Burgerlijke staat (verwijzing naar: Tabelwaarde,Burgerlijke staat => AfasKnCodeTableValue)
            'ViCs' => array(
            ),
            // Huwelijksdatum
            'DaMa' => array(
            ),
            // Datum scheiding
            'DaDi' => array(
            ),
            // Overlijdensdatum
            'DaDe' => array(
            ),
            // Titel/aanhef (verwijzing naar: Titel => AfasKnTitle)
            'TtId' => array(
              // ALG was given in Qoony (where person was inside knSalesRelationPer).
              // in newer environment where it's inside knOrganisation > knContact,
              // I don't even see this one in an entry screen.
              //'default' => 'ALG',
            ),
            // Tweede titel (verwijzing naar: Titel => AfasKnTitle)
            'TtEx' => array(
            ),
            // Briefaanhef
            'LeHe' => array(
            ),
            // Telefoonnr. werk
            'TeNr' => array(
              // Note aliases change for KnSalesRelationPer, see below.
              'alias' => 'phone',
            ),
            // Telefoonnr. privé
            'TeN2' => array(
            ),
            // Fax werk
            'FaNr' => array(
              'alias' => 'fax',
            ),
            // Mobiel werk
            'MbNr' => array(
              'alias' => 'mobile',
            ),
            // Mobiel privé
            'MbN2' => array(
            ),
            // E-mail werk
            'EmAd' => array(
              'alias' => 'email',
            ),
            'EmA2' => array(
            ),
            // Homepage
            'HoPa' => array(
              'alias' => 'homepage',
            ),
            // Correspondentie
            'Corr' => array(
              'type' => 'boolean',
              'default' => FALSE,
            ),
            // Voorkeursmedium (verwijzing naar: Tabelwaarde,Medium voor correspondentie => AfasKnCodeTableValue)
            'ViMd' => array(
            ),
            // Opmerking
            'Re' => array(
              'alias' => 'comment',
            ),
            // Status (verwijzing naar: Tabelwaarde,Status verkooprelatie => AfasKnCodeTableValue)
            'StId' => array(
            ),
            // Sociale netwerken
            'SocN' => array(
            ),
            // Facebook
            'Face' => array(
              'alias' => 'facebook',
            ),
            // LinkedIn
            'Link' => array(
              'alias' => 'linkedin',
            ),
            // Twitter
            'Twtr' => array(
              'alias' => 'twitter',
            ),
            // Naam bestand
            'FileName' => array(
            ),
            // Afbeelding (base64Binary field)
            'FileStream' => array(
            ),
            // Persoon toegang geven tot afgeschermde deel van de portal(s)
            'AddToPortal' => array(
              'type' => 'boolean',
            ),
            // E-mail toegang
            'EmailPortal' => array(
            ),
          ),
        );

        // MatchPer defaults: Our principle is we would rather insert duplicate
        // data than silently overwrite data by accident.
        if (!empty($data['BcCo'])) {
          /* ...but it seems very unlikely that someone would specify BcCo when
           * they don't explicitly want the corresponding record overwritten.
           * So we match on BcCo in that case. This means there is no difference\
           * between $fields_action 'insert' and 'update'!
           * If we do _not_ set MatchPer while BcCo _is_ specified, with
           * $fields_action 'insert' we get error "Unsupported match value!!"
           *
           * If we set MatchPer=0 if BcCo is not specified,
           * - we get error "Voer een waarde in bij 'Nummer'" at 'update';
           * - a record is always inserted at 'insert' (so I guess in this case
           *   '0' is equal to '6').
           * (NOTE we haven't actually tested this until now, just assumed that
           * it works the same way as for knOrganisation, which was tested...)
           */
          $info['fields']['MatchPer']['default'] = '0';
        }
        elseif (!empty($data['SoSe']) || !empty($data['bsn'])) {
          // I guess we can assume the same logic (we never want duplicate
          // records so just update everything silently, even for inserts), for
          // BSN...
          $info['fields']['MatchPer']['default'] = '1';
        }
        // TODO we can surely assume the same logic for 2-6 but this would take
        // a lot of testing. Do this later. We will feel entitled to change this
        // part of this function's behavior silently.
        else {
          // Probably even with $fields_action 'update', a new record will be
          // inserted if there is no match... but we do not know this for sure!
          // Since our principle is to prevent silent overwrites of data, we
          // here force an error for 'update' (see 0 above) if MatchPer is not
          // explicitly specified in $data.
          // (If you disagree / encounter circumstances where this is not OK,
          // tell me so we can refine this. --Roderik.)
          // For 'insert', this will insert a new record - see comment above.
          $info['fields']['MatchPer']['default'] = '0';
        }

        if ($parent_type == 'KnContact' || $parent_type == 'KnSalesRelationPer') {
          // Note: a knPerson cannot be inside a knContact directly. So far we
          // know only of the situation where that knContact is again inside a
          // knOrganisation.

          $info['fields'] += array(
            // This field applies to a knPerson inside a knContact inside a
            // knOrganisation:
            // Land wetgeving (verwijzing naar: Land => AfasKnCountry)
            'CoLw' => array(
            ),
          );
        }
        if ($parent_type == 'KnSalesRelationPer') {
          // Usually, phone/mobile/e-mail aliases are set to the business
          // ones, and these are the ones you see on the screen in the UI.
          // Inside KnSalesRelationPer, you see the private equivalents in the
          // UI. (At least that was the case for Qoony.) So it's those you want
          // to fill by default.
          $info['fields']['TeN2']['alias'] = $info['fields']['TeNr']['alias'];
          unset($info['fields']['TeNr']['alias']);
          $info['fields']['MbN2']['alias'] = $info['fields']['MbNr']['alias'];
          unset($info['fields']['MbNr']['alias']);
          $info['fields']['EmA2']['alias'] = $info['fields']['EmAd']['alias'];
          unset($info['fields']['EmAd']['alias']);
        }
        break;

      case 'KnSalesRelationPer':
        // NOTE - not checked against XSD yet, only taken over from Qoony example
        // Fields:
        // ??? = Overheids Identificatienummer, which an AFAS expert recommended
        //       for using as a secondary-unique-id, when we want to insert an
        //       auto-numbered item and later retrieve it to get the inserted ID.
        //       I don't know what this is but it's _not_ 'OIN', I tried that.
        //       (In the end we never used this field.)
        $info = array(
          'id_field' => 'DbId',
          'objects' => array(
            'KnPerson' => 'person',
          ),
          'fields' => array(

            // 'is debtor'?
            'IsDb' => array(
              'type' => 'boolean',
              'default' => TRUE,
            ),
            // According to AFAS docs, PaCd / VaDu "are required if IsDb==True" ...
            // no further specs. Heh, VaDu is not even in our inserted XML.
            'PaCd' => array(
              'default' => '14',
            ),
            'CuId' => array(
              'alias' => 'currency_code',
              'default' => 'EUR',
            ),
            'Bl' => array(
              'default' => 'false',
            ),
            'AuPa' => array(
              'default' => '0',
            ),
            // Verzamelrekening Debiteur -- apparently these just need to be
            // specified by whoever is setting up the AFAS administration?
            'ColA' => array(
              'alias' => 'verzamelreking_debiteur',
            ),
            // ?? Doesn't seem to be required, but we're still setting default to
            // the old value we're used to, until we know what this field means.
            'VtIn' => array(
              'default' => '1',
            ),
            'PfId' => array(
              'default' => '*****',
            ),
          ),
        );
        break;

      case 'KnOrganisation':
        $info = array(
          'objects' => array(
//            'KnBankAccount' => 'bank_account',
            'KnBasicAddressAdr' => 'address',
            'KnBasicAddressPad' => 'postal_address',
            'KnContact' => 'contact',
          ),
          'fields' => array(
            // Postadres is adres
            'PbAd' => array(
              'alias' => 'is_po_box',
              'type' => 'boolean',
              'default' => FALSE,
            ),
            'AutoNum' => array(
              'alias' => 'auto_num',
              'type' => 'boolean',
            ),
            /**
             * If you specify MatchOga, the difference between $fields_action
             * 'update' and 'insert' falls away: if there is a match (but only
             * one), the existing record is updated. If there isn't, a new one
             * is inserted. If there are multiple matches, or a wrong match
             * method is specified, AFAS throws an error.
             *
             * We make sure that you must explicitly specify a value for this
             * with $field_action 'update' (and otherwise get an error), by
             * setting the default - see further down.
             */
            // Organisatie vergelijken op
            // Values:  0:Zoek op BcCo   1:KvK-nummer   2:Fiscaal nummer   3:Naam   4:Adres   5:Postadres   6:Altijd nieuw toevoegen
            'MatchOga' => array(
              'alias' => 'match_method',
            ),
            // Organisatie/persoon (intern)
            // From "Organisaties toevoegen en wijzigen (UpdateConnector KnOrganisation)":
            // "Do not deliver the 'BcId' field."
            // (Because it really is internal. So why should we define it?)
            //'BcId' => array(
            //),
            // Nummer, 1-15 chars
            'BcCo' => array(
              // 'ID' would be more confusing because it's not the internal ID.
              'alias' => 'number',
            ),
            'SeNm' => array(
              'alias' => 'search_name',
            ),
            // Name. Is not required officially, but I guess you must fill in either
            // BcCo, SeNm or Nm to
            'Nm' => array(
              'alias' => 'name',
            ),
            // Rechtsvorm (verwijzing naar: Tabelwaarde,Rechtsvorm => AfasKnCodeTableValue)
            'ViLe' => array(
              'alias' => 'org_type',
            ),
            // Branche (verwijzing naar: Tabelwaarde,Branche => AfasKnCodeTableValue)
            'ViLb' => array(
              'alias' => 'branche',
            ),
            // KvK-nummer
            'CcNr' => array(
              'alias' => 'coc_number',
            ),
            // Datum KvK
            'CcDa' => array(
              'type' => 'date',
            ),
            // Naam (statutair)
            'NmRg' => array(
            ),
            // Vestiging (statutair)
            'RsRg' => array(
            ),
            // Titel/aanhef (verwijzing naar: Titel => AfasKnTitle)
            'TtId' => array(
            ),
            // Briefaanhef
            'LeHe' => array(
            ),
            // Organisatorische eenheid (verwijzing naar: Organisatorische eenheid => AfasKnOrgUnit)
            'OuId' => array(
            ),
            // Telefoonnr. werk
            'TeNr' => array(
              'alias' => 'phone',
            ),
            // Fax werk
            'FaNr' => array(
              'alias' => 'fax',
            ),
            // Mobiel werk
            'MbNr' => array(
              'alias' => 'mobile',
            ),
            // E-mail werk
            'EmAd' => array(
              'alias' => 'email',
            ),
            // Homepage
            'HoPa' => array(
              'alias' => 'homepage',
            ),
            // Correspondentie
            'Corr' => array(
              'type' => 'boolean',
            ),
            // Voorkeursmedium (verwijzing naar: Tabelwaarde,Medium voor correspondentie => AfasKnCodeTableValue)
            'ViMd' => array(
            ),
            // Opmerking
            'Re' => array(
              'alias' => 'comment',
            ),
            // Fiscaalnummer
            'FiNr' => array(
              'alias' => 'fiscal_number',
            ),
            // Status (verwijzing naar: Tabelwaarde,Status verkooprelatie => AfasKnCodeTableValue)
            'StId' => array(
            ),
            // Sociale netwerken
            'SocN' => array(
            ),
            // Facebook
            'Face' => array(
              'alias' => 'facebook',
            ),
            // LinkedIn
            'Link' => array(
              'alias' => 'linkedin',
            ),
            // Twitter
            'Twtr' => array(
              'alias' => 'twitter',
            ),
            // Onderdeel van organisatie (verwijzing naar: Organisatie/persoon => AfasKnBasicContact)
            'BcPa' => array(
            ),
          ),
        );
        // MatchOga defaults: Our principle is we would rather insert duplicate
        // data than silently overwrite data by accident.
        if (!empty($data['BcCo'])) {
          /* ...but it seems very unlikely that someone would specify BcCo when
           * they don't explicitly want the corresponding record overwritten.
           * So we match on BcCo in that case. This means there is no difference\
           * between $fields_action 'insert' and 'update'!
           * If we do _not_ set MatchOga while BcCo _is_ specified, with
           * $fields_action 'insert' we get error "Unsupported match value!!"
           *
           * If we set MatchOga=0 if BcCo is not specified,
           * - we get error "Voer een waarde in bij 'Nummer'" at 'update';
           * - a record is always inserted at 'insert' (so I guess in this case
           *   '0' is equal to '6').
           */
          $info['fields']['MatchOga']['default'] = '0';
        }
        elseif (!empty($data['CcNr']) || !empty($data['coc_number'])) {
          // I guess we can assume the same logic (we never want duplicate
          // records so just update everything silently, even for inserts), for
          // KvK number...
          $info['fields']['MatchOga']['default'] = '1';
        }
        elseif (!empty($data['FiNr']) || !empty($data['fiscal_number'])) {
          // ...and fiscal number.
          $info['fields']['MatchOga']['default'] = '2';
        }
        elseif ($fields_action == 'insert') {
          // Since we can get an error if not setting MatchOga in some
          // circumstances (see 0 above), explicitly set 'always insert'.
          $info['fields']['MatchOga']['default'] = '6';
        }
        else {
          // Probably even with $fields_action 'update', a new record will be
          // inserted if there is no match... but we do not know this for sure!
          // Since our principle is to prevent silent overwrites of data, we
          // here force an error for 'update' (see 0 above) if MatchOga is not
          // explicitly specified in $data.
          // (If you disagree / encounter circumstances where this is not OK,
          // tell me so we can refine this. --Roderik.)
          // For 'insert', this will insert a new record - see comment above.
          $info['fields']['MatchOga']['default'] = '0';
        }
        break;

      case 'KnSubject':
        $info = array(
          'id_field' => 'SbId',
          'objects' => array(
            'KnSubjectLink' => 'subject_link',
            'KnS01' => 'subject_link_1',
            'KnS02' => 'subject_link_2',
            // If there are more KnSNN, they have all custom fields?
          ),
          'fields' => array(
            // Type dossieritem (verwijzing naar: Type dossieritem => AfasKnSubjectType)
            'StId' => array(
              'alias' => 'type',
              'type' => 'long',
              'required' => TRUE,
            ),
            // Onderwerp
            'Ds' => array(
              'alias' => 'description',
            ),
            // Toelichting
            'SbTx' => array(
              'alias' => 'comment',
            ),
            // Instuurdatum
            'Da' => array(
              'alias' => 'date',
              'type' => 'date',
            ),
            // Verantwoordelijke (verwijzing naar: Medewerker => AfasKnEmployee)
            'EmId' => array(
              'alias' => 'responsible',
            ),
            // Aanleiding (verwijzing naar: Dossieritem => AfasKnSubject)
            'SbHi' => array(
              'type' => 'long',
            ),
            // Type actie (verwijzing naar: Type actie => AfasKnSubjectActionType)
            'SaId' => array(
              'alias' => 'action_type',
            ),
            // Prioriteit (verwijzing naar: Tabelwaarde,Prioriteit actie => AfasKnCodeTableValue)
            'ViPr' => array(
            ),
            // Bron (verwijzing naar: Brongegevens => AfasKnSourceData)
            'ScId' => array(
              'alias' => 'source',
            ),
            // Begindatum
            'DtFr' => array(
              'alias' => 'start_date',
              'type' => 'date',
            ),
            // Einddatum
            'DtTo' => array(
              'alias' => 'end_date',
              'type' => 'date',
            ),
            // Afgehandeld
            'St' => array(
              'alias' => 'done',
              'type' => 'boolean',
            ),
            // Datum afgehandeld
            'DtSt' => array(
              'alias' => 'done_date',
              'type' => 'date',
            ),
            // Waarde kenmerk 1 (verwijzing naar: Waarde kenmerk => AfasKnFeatureValue)
            'FvF1' => array(
              'type' => 'long',
            ),
            // Waarde kenmerk 2 (verwijzing naar: Waarde kenmerk => AfasKnFeatureValue)
            'FvF2' => array(
              'type' => 'long',
            ),
            // Waarde kenmerk 3 (verwijzing naar: Waarde kenmerk => AfasKnFeatureValue)
            'FvF3' => array(
              'type' => 'long',
            ),
            // Geblokkeerd
            'SbBl' => array(
              'alias' => 'blocked',
              'type' => 'boolean',
            ),
            // Bijlage
            'SbPa' => array(
              'alias' => 'attachment',
            ),
            // Save file with subject
            'FileTrans' => array(
              'type' => 'boolean',
            ),
            // File as byte-array
            'FileStream' => array(
            ),
          ),
        );
        break;

      case 'KnSubjectLink':
        $info = array(
          'id_field' => 'SbId',
          'fields' => array(
            // Save in CRM Subject
            'DoCRM' => array(
              'type' => 'boolean',
            ),
            // Organisatie/persoon
            'ToBC' => array(
              'alias' => 'is_org_person',
              'type' => 'boolean',
            ),
            // Medewerker
            'ToEm' => array(
              'alias' => 'is_employee',
              'type' => 'boolean',
            ),
            // Verkooprelatie
            'ToSR' => array(
              'alias' => 'is_sales_relation',
              'type' => 'boolean',
            ),
            // Inkooprelatie
            'ToPR' => array(
              'alias' => 'is_purchase_relation',
              'type' => 'boolean',
            ),
            // Cliënt IB
            'ToCl' => array(
              'alias' => 'is_client_ib',
              'type' => 'boolean',
            ),
            // Cliënt Vpb
            'ToCV' => array(
              'alias' => 'is_client_vpb',
              'type' => 'boolean',
            ),
            // Werkgever
            'ToEr' => array(
              'alias' => 'is_employer',
              'type' => 'boolean',
            ),
            // Sollicitant
            'ToAp' => array(
              'alias' => 'is_applicant',
              'type' => 'boolean',
            ),
            // Type bestemming
            // Values:  1:Geen   2:Medewerker   3:Organisatie/persoon   4:Verkooprelatie   8:Cliënt IB   9:Cliënt Vpb   10:Werkgever   11:Inkooprelatie   17:Sollicitant   30:Campagne   31:Item   32:Cursusevenement-->
            'SfTp' => array(
              'alias' => 'destination_type',
              'type' => 'long',
            ),
            // Bestemming
            'SfId' => array(
              'alias' => 'destination_id',
            ),
            // Organisatie/persoon (verwijzing naar: Organisatie/persoon => AfasKnBasicContact)
            'BcId' => array(
              'alias' => 'org_person',
            ),
            // Contact (verwijzing naar: Contact => AfasKnContactData)
            'CdId' => array(
              'alias' => 'contact',
              'type' => 'long',
            ),
            // Administratie (Verkoop) (verwijzing naar: Administratie => AfasKnUnit)
            'SiUn' => array(
              'type' => 'long',
            ),
            // Factuurtype (verkoop) (verwijzing naar: Type factuur => AfasFiInvoiceType)
            'SiTp' => array(
              'alias' => 'sales_invoice_type',
              'type' => 'long',
            ),
            // Verkoopfactuur (verwijzing naar: Factuur => AfasFiInvoice)
            'SiId' => array(
              'alias' => 'sales_invoice',
            ),
            // Administratie (Inkoop) (verwijzing naar: Administratie => AfasKnUnit)
            'PiUn' => array(
              'type' => 'long',
            ),
            // Factuurtype (inkoop) (verwijzing naar: Type factuur => AfasFiInvoiceType)
            'PiTp' => array(
              'alias' => 'purchase_invoice_type',
              'type' => 'long',
            ),
            // Inkoopfactuur (verwijzing naar: Factuur => AfasFiInvoice)
            'PiId' => array(
              'alias' => 'purchase_invoice',
            ),
            // Fiscaal jaar (verwijzing naar: Aangiftejaren => AfasTxDeclarationYear)
            'FiYe' => array(
              'alias' => 'fiscal_year',
              'type' => 'long',
            ),
            // Project (verwijzing naar: Project => AfasPtProject)
            'PjId' => array(
              'alias' => 'project',
            ),
            // Campagne (verwijzing naar: Campagne => AfasCmCampaign)
            'CaId' => array(
              'alias' => 'campaign',
              'type' => 'long',
            ),
            // Actief (verwijzing naar: Vaste activa => AfasFaFixedAssets)
            'FaSn' => array(
              'type' => 'long',
            ),
            // Voorcalculatie (verwijzing naar: Voorcalculatie => AfasKnQuotation)
            'QuId' => array(
            ),
            // Dossieritem (verwijzing naar: Dossieritem => AfasKnSubject)
            'SjId' => array(
              'type' => 'long',
            ),
            // Abonnement (verwijzing naar: Abonnement => AfasFbSubscription
            'SuNr' => array(
              'alias' => 'subscription',
              'type' => 'long',
            ),
            // Dienstverband
            'DvSn' => array(
              'type' => 'long',
            ),
            // Type item (verwijzing naar: Tabelwaarde,Itemtype => AfasKnCodeTableValue)
            // Values:  Wst:Werksoort   Pid:Productie-indicator   Deg:Deeg   Dim:Artikeldimensietotaal   Art:Artikel   Txt:Tekst   Sub:Subtotaal   Tsl:Toeslag   Kst:Kosten   Sam:Samenstelling   Crs:Cursus-->
            'VaIt' => array(
              'alias' => 'item_type',
            ),
            // Itemcode (verwijzing naar: Item => AfasFbBasicItems)
            'BiId' => array(
              'alias' => 'item_code',
            ),
            // Cursusevenement (verwijzing naar: Evenement => AfasKnCourseEvent)
            'CrId' => array(
              'alias' => 'course_event',
              'type' => 'long',
            ),
            // Verzuimmelding (verwijzing naar: Verzuimmelding => AfasHrAbsIllnessMut)
            'AbId' => array(
              'type' => 'long',
            ),
            // Forecast (verwijzing naar: Forecast => AfasCmForecast)
            'FoSn' => array(
              'type' => 'long',
            ),
          ),
        );
        break;

      // Subject link #1 (after KnSubjectLink), to be sent inside KnSubject.
      // The field names are not custom fields, but are the definitions general?
      // Not 100% sure.
      case 'KnS01':
        $info = array(
          'id_field' => 'SbId',
          'fields' => array(
            // Vervaldatum
            'U001' => array(
              'alias' => 'end_date',
              'type' => 'date',
            ),
            // Identiteitsnummer
            'U002' => array(
              'alias' => 'id_number',
            ),
          ),
        );
        break;

      case 'KnS02':
        $info = array(
          'id_field' => 'SbId',
          'fields' => array(
            // Contractnummer
            'U001' => array(
              'alias' => 'contract_number',
            ),
            // Begindatum contract
            'U002' => array(
              'alias' => 'start_date',
              'type' => 'date',
            ),
            // Einddatum contract
            'U003' => array(
              'alias' => 'start_date',
              'type' => 'date',
            ),
            // Waarde
            'U004' => array(
              'alias' => 'value',
              'type' => 'decimal',
            ),
            // Beëindigd
            'U005' => array(
              'alias' => 'ended',
              'type' => 'boolean',
            ),
            // Stilzwijgend verlengen
            'U006' => array(
              'alias' => 'recurring',
              'type' => 'boolean',
            ),
            // Opzegtermijn (verwijzing naar: Tabelwaarde,(Afwijkende) opzegtermijn => AfasKnCodeTableValue)
            'U007' => array(
              'alias' => 'cancel_term',
            ),
          ),
        );
        break;

      case 'FbSales':
        $info = array(
          'objects' => array(
            'FbSalesLines' => 'line_item',
          ),
          'fields' => array(
            // Nummer
            'OrNu' => array(
            ),
            // Datum
            'OrDa' => array(
              'alias' => 'date',
              'type' => 'date',
            ),
            // Verkooprelatie (verwijzing naar: Verkooprelatie => AfasKnSalRelation)
            'DbId' => array(
              'alias' => 'sales_relation',
            ),
            // Gewenste leverdatum
            'DaDe' => array(
              'alias' => 'delivery_date_req',
              'type' => 'date',
            ),
            // Datum levering (toegezegd)
            'DaPr' => array(
              'alias' => 'delivery_date_ack',
              'type' => 'date',
            ),
            // Valutacode (verwijzing naar: Valuta => AfasKnCurrency)
            'CuId' => array(
              'alias' => 'currency_code',
            ),
            // Valutakoers
            'Rate' => array(
              'alias' => 'currency_rate',
            ),
            // Backorder
            'BkOr' => array(
              'type' => 'boolean',
            ),
            // Verkoopkanaal (verwijzing naar: Tabelwaarde,Verkoopkanaal => AfasKnCodeTableValue)
            'SaCh' => array(
              'alias' => 'sales_channel',
            ),
            // Btw-plicht (verwijzing naar: Btw-plicht => AfasKnVatDuty)
            'VaDu' => array(
              'alias' => 'vat_due',
            ),
            // Prijs incl. btw
            'InVa' => array(
              'alias' => 'includes_vat',
            ),
            // Betalingsvoorwaarde (verwijzing naar: Betalingsvoorwaarde => AfasKnPaymentCondition)
            'PaCd' => array(
            ),
            // Betaalwijze (verwijzing naar: Betaalwijze => AfasKnPaymentType)
            'PaTp' => array(
              'alias' => 'payment_type',
            ),
            // Opmerking
            'Re' => array(
              'alias' => 'comment',
            ),
            // Administratie (verwijzing naar: Administratieparameters Algemeen => AfasKnUnitPar)
            'Unit' => array(
              'type' => 'long',
            ),
            // Incasseren
            'Coll' => array(
              'type' => 'boolean',
            ),
            // Creditorder
            'CrOr' => array(
              'type' => 'boolean',
            ),
            // Code route (verwijzing naar: Tabelwaarde,Routes => AfasKnCodeTableValue)
            'Rout' => array(
            ),
            // Magazijn (verwijzing naar: Magazijn => AfasFbWarehouse)
            'War' => array(
              'alias' => 'warehouse',
            ),
            // Verzamelpakbon
            'CoDn' => array(
              'type' => 'boolean',
            ),
            // Verzamelfactuur
            'CoIn' => array(
              'type' => 'boolean',
            ),
            // Prioriteit levering
            'DlPr' => array(
              'alias' => 'delivery_prio',
              'type' => 'long',
            ),
            // Taal (verwijzing naar: Talen => AfasKnLanguage)
            'LgId' => array(
              'alias' => 'language',
            ),
            // Leveringsconditie (verwijzing naar: Tabelwaarde,Leveringvoorwaarde => AfasKnCodeTableValue)
            // Values:  0:Deellevering toestaan   1:Regel volledig uitleveren   2:Order volledig uitleveren   3:Geen backorders leveren
            'DeCo' => array(
              'alias' => 'delivery_cond',
            ),
            // CBS-typen (verwijzing naar: CBS-typen => AfasFbCBSType)
            'CsTy' => array(
              'alias' => 'cbs_type',
            ),
            // Type vervoer CBS (verwijzing naar: Tabelwaarde,CBS Vervoerswijze => AfasKnCodeTableValue)
            // Values:  1:Zeevaart   2:Spoorvervoer   3:Wegvervoer   4:Luchtvaart   5:Postzendingen   7:Pijpleidingvervoer   8:Binnenvaart   9:Eigen vervoer
            'VaTr' => array(
            ),
            // Statistisch stelsel CBS (verwijzing naar: Tabelwaarde,CBS Statistisch stelsel => AfasKnCodeTableValue)
            // Values:  00:Reguliere invoer/ICV en uitvoer/ICL   01:Doorlevering (ICL) van onbewerkte goederen naar een andere Eu-lidstaat   02:Wederverkoop (ICL of uitvoer) van onbewerkte goederen   03:Invoer (al of niet via douane-entrepot) van goederen   04:Verwerving/levering vÃ³Ã³r eigen voorraadverplaatsing (fictieve zending)   05:Verwerving/levering nÃ¡ eigen voorraadverplaatsing (fictieve zending)   10:Actieve douaneveredeling met toepassing van het terugbetalingssysteem
            'VaSt' => array(
            ),
            // Goederenstroom CBS (verwijzing naar: Tabelwaarde,CBS Goederenstroom => AfasKnCodeTableValue)
            // 6:Invoer/intra-cummunautaire verwerving (ICV)   7:Uitvoer/intra-communautaire levering (ICL)
            'VaGs' => array(
            ),
            // Transactie CBS (verwijzing naar: Tabelwaarde,CBS Transactie => AfasKnCodeTableValue)
            // Values:  1:Koop, verkoop of huurkoop (financiële leasing)   2:Retourzending (excl. retour tijdelijke in- en uitvoer, zie code 6)   3:Gratis zending   4:Ontvangst of verzending vÃ³Ã³r loonveredeling   5:Ontvangst of verzending nÃ¡ loonveredeling   6:Tijdelijke in- en uitvoer en retour tijdelijke in- en uitvoer   7:Ontvangst of verzending in het kader van gecoÃ¶rdineerde fabrikage   8:Levering i.v.m. bouwmaterialen c.q. bouwkunde onder algemeen contract
            'VaTa' => array(
            ),
            // Land bestemming CBS (verwijzing naar: Land => AfasKnCountry)
            'CoId' => array(
            ),
            // Factuurkorting (%)
            'InPc' => array(
              'type' => 'decimal',
            ),
            // Kredietbeperking inclusief btw
            'VaCl' => array(
              'type' => 'boolean',
            ),
            // Kredietbeperking (%)
            'ClPc' => array(
              'type' => 'decimal',
            ),
            // Betalingskorting (%)
            'PaPc' => array(
              'type' => 'decimal',
            ),
            // Betalingskorting incl. btw
            'VaPa' => array(
              'type' => 'boolean',
            ),
            // Afwijkende btw-tariefgroep
            'VaYN' => array(
              'type' => 'boolean',
            ),
            // Type barcode (verwijzing naar: Tabelwaarde,Type barcode => AfasKnCodeTableValue)-->
            // Values:  0:Geen controle   1:Barcode EAN8   2:Barcode UPC   3:Barcode EAN13   4:Barcode EAN14   5:Barcode SSCC   6:Code 128   7:Interleaved 2/5   8:Interleaved 2/5 (controlegetal)
            'VaBc' => array(
              'alias' => 'barcode_type',
            ),
            // Barcode
            'BaCo' => array(
              'alias' => 'barcode',
            ),
            // Rapport (verwijzing naar: Definitie => AfasKnMetaDefinition)
            'PrLa' => array(
            ),
            // Dagboek factuur (verwijzing naar: Dagboek => AfasKnJournal)
            'JoCo' => array(
              'alias' => 'journal',
            ),
            // Factureren aan (verwijzing naar: Verkooprelatie => AfasKnSalRelation)
            'FaTo' => array(
              'alias' => 'invoice_to',
            ),
            // Toekomstige order
            'FuOr' => array(
              'alias' => 'future_order',
              'type' => 'boolean',
            ),
            // Type levering (verwijzing naar: Type levering => AfasFbDeliveryType)
            'DtId' => array(
              'alias' => 'delivery_type',
              'type' => 'long',
            ),
            // Project (verwijzing naar: Project => AfasPtProject)
            'PrId' => array(
              'alias' => 'project',
            ),
            // Projectfase (verwijzing naar: Projectfase => AfasPtProjectStage)
            'PrSt' => array(
              'alias' => 'project_stage',
            ),
            // Status verzending (verwijzing naar: Tabelwaarde,Verzendstatus => AfasKnCodeTableValue)
            // Values:  0:Niet aanbieden aan vervoerder   1:Aanbieden aan vervoerder   2:Aangeboden aan vervoerder   3:Verzending correct ontvangen   4:Fout bij aanbieden verzending
            'SeSt' => array(
              'alias' => 'delivery_state',
            ),
            // Verzendgewicht
            'SeWe' => array(
              'alias' => 'weight',
              'type' => 'decimal',
            ),
            // Aantal colli
            'QuCl' => array(
              'type' => 'long',
            ),
            // Verpakking (verwijzing naar: Tabelwaarde,Verpakkingssoort => AfasKnCodeTableValue)
            'PkTp' => array(
              'alias' => 'package_type',
            ),
            // Vervoerder (verwijzing naar: Vervoerder => AfasKnTransporter)
            'TrPt' => array(
              'alias' => 'shipping_company',
            ),
            // Dienst (verwijzing naar: Dienst => AfasKnShippingService)
            'SsId' => array(
              'alias' => 'shipping_service',
            ),
            // Verwerking order (verwijzing naar: Tabelwaarde,Verwerking order => AfasKnCodeTableValue)
            // Values:  1:Pakbon, factuur na levering   2:Pakbon en factuur   3:Factuur, levering na vooruitbetaling   4:Pakbon, geen factuur   5:Pakbon, factuur via nacalculatie   6:Pakbon en factuur, factuur niet afdrukken of verzenden   7:Aanbetalen, levering na aanbetaling
            'OrPr' => array(
              'alias' => 'order_processing',
            ),
            // Bedrag aanbetalen
            'AmDp' => array(
              'type' => 'decimal',
            ),
            // Vertegenwoordiger (verwijzing naar: Vertegenwoordiger => AfasKnRepresentative)
            'VeId' => array(
            ),
            // Afleveradres (verwijzing naar: Adres => AfasKnBasicAddress)
            'DlAd' => array(
              'type' => 'long',
            ),
            // Omschrijving afleveradres
            'ExAd' => array(
              'alias' => '',
            ),
            // Order blokkeren
            'FxBl' => array(
              'alias' => 'block_order',
              'type' => 'boolean',
            ),
            // Uitleverbaar
            'DlYN' => array(
              'type' => 'boolean',
            ),
          ),
        );
        break;

      case 'FbSalesLines':
        $info = array(
          'objects' => array(
            'FbOrderBatchLines' => 'batch_line_item',
            'FbOrderSerialLines' => 'serial_line_item',
          ),
          'fields' => array(
            // Type item (verwijzing naar: Tabelwaarde,Itemtype => AfasKnCodeTableValue)
            // Values:  1:Werksoort   10:Productie-indicator   11:Deeg   14:Artikeldimensietotaal   2:Artikel   3:Tekst   4:Subtotaal   5:Toeslag   6:Kosten   7:Samenstelling   8:Cursus
            'VaIt' => array(
              'alias' => 'item_type',
            ),
            // Itemcode
            'ItCd' => array(
              'alias' => 'item_code',
            ),
            // Omschrijving
            'Ds' => array(
              'alias' => 'description',
            ),
            // Btw-tariefgroep (verwijzing naar: Btw-tariefgroep => AfasKnVatTarifGroup)
            'VaRc' => array(
              'alias' => 'vat_type',
            ),
            // Eenheid (verwijzing naar: Eenheid => AfasFbUnit)
            'BiUn' => array(
              'alias' => 'unit_type',
            ),
            // Aantal eenheden
            'QuUn' => array(
              'alias' => 'quantity',
              'type' => 'decimal',
            ),
            // Lengte
            'QuLe' => array(
              'alias' => 'length',
              'type' => 'decimal',
            ),
            // Breedte
            'QuWi' => array(
              'alias' => 'width',
              'type' => 'decimal',
            ),
            // Hoogte
            'QuHe' => array(
              'alias' => 'height',
              'type' => 'decimal',
            ),
            // Aantal besteld
            'Qu' => array(
              'alias' => 'quantity_ordered',
              'type' => 'decimal',
            ),
            // Aantal te leveren
            'QuDl' => array(
              'alias' => 'quantity_deliver',
              'type' => 'decimal',
            ),
            // Prijslijst (verwijzing naar: Prijslijst verkoop => AfasFbPriceListSale)
            'PrLi' => array(
              'alias' => 'price_list',
            ),
            // Magazijn (verwijzing naar: Magazijn => AfasFbWarehouse)
            'War' => array(
              'alias' => 'warehouse',
            ),
            // Dienstenberekening
            'EUSe' => array(
              'type' => 'boolean',
            ),
            // Gewichtseenheid (verwijzing naar: Tabelwaarde,Gewichtseenheid => AfasKnCodeTableValue)
            // Values:  0:Geen gewicht   1:Microgram (Âµg)   2:Milligram (mg)   3:Gram (g)   4:Kilogram (kg)   5:Ton
            'VaWt' => array(
              'alias' => 'weight_unit',
            ),
            // Nettogewicht
            'NeWe' => array(
              'alias' => 'weight_net',
              'type' => 'decimal',
            ),
            //
            'GrWe' => array(
              'alias' => 'weight_gross',
              'type' => 'decimal',
            ),
            // Prijs per eenheid
            'Upri' => array(
              'alias' => 'unit_price',
              'type' => 'decimal',
            ),
            // Kostprijs
            'CoPr' => array(
              'alias' => 'cost_price',
              'type' => 'decimal',
            ),
            // Korting toestaan (verwijzing naar: Tabelwaarde,Toestaan korting => AfasKnCodeTableValue)
            // Values:  0:Factuur- en regelkorting   1:Factuurkorting   2:Regelkorting   3:Geen factuur- en regelkorting
            'VaAD' => array(
            ),
            // % Regelkorting
            'PRDc' => array(
              'type' => 'decimal',
            ),
            // Bedrag regelkorting
            'ARDc' => array(
              'type' => 'decimal',
            ),
            // Handmatig bedrag regelkorting
            'MaAD' => array(
              'type' => 'boolean',
            ),
            // Opmerking
            'Re' => array(
              'alias' => 'comment',
            ),
            // GUID regel
            'GuLi' => array(
              'alias' => 'guid',
            ),
            // Artikeldimensiecode 1 (verwijzing naar: Artikeldimensiecodes => AfasFbStockDimLines)
            'StL1' => array(
              'alias' => 'dimension_1',
            ),
            // Artikeldimensiecode 2 (verwijzing naar: Artikeldimensiecodes => AfasFbStockDimLines)
            'StL2' => array(
              'alias' => 'dimension_2',
            ),
            // Direct leveren vanuit leverancier
            'DiDe' => array(
              'alias' => 'direct_delivery',
              'type' => 'boolean',
            ),
          ),
        );
        break;

      case 'FbOrderBatchLines':
        $info = array(
          'fields' => array(
            // Partijnummer
            'BaNu' => array(
              'alias' => 'batch_number',
            ),
            // Eenheid (verwijzing naar: Eenheid => AfasFbUnit)
            'BiUn' => array(
              'alias' => 'unit_type',
            ),
            // Aantal eenheden
            'QuUn' => array(
              'alias' => 'quantity_units',
              'type' => 'decimal',
            ),
            // Aantal
            'Qu' => array(
              'alias' => 'quantity',
              'type' => 'decimal',
            ),
            // Factuuraantal
            'QuIn' => array(
              'alias' => 'quantity_invoice',
              'type' => 'decimal',
            ),
            // Opmerking
            'Re' => array(
              'alias' => 'comment',
            ),
            // Lengte
            'QuLe' => array(
              'alias' => 'length',
              'type' => 'decimal',
            ),
            // Breedte
            'QuWi' => array(
              'alias' => 'width',
              'type' => 'decimal',
            ),
            // Hoogte
            'QuHe' => array(
              'alias' => 'height',
              'type' => 'decimal',
            ),
          ),
        );
        break;

      case 'FbOrderSerialLines':
        $info = array(
          'fields' => array(
            // Serienummer
            'SeNu' => array(
              'alias' => 'serial_number',
            ),
            // Eenheid (verwijzing naar: Eenheid => AfasFbUnit)
            'BiUn' => array(
              'alias' => 'unit_type',
            ),
            // Aantal eenheden
            'QuUn' => array(
              'alias' => 'quantity_units',
              'type' => 'decimal',
            ),
            // Aantal
            'Qu' => array(
              'alias' => 'quantity',
              'type' => 'decimal',
            ),
            // Factuuraantal
            'QuIn' => array(
              'alias' => 'quantity_invoice',
              'type' => 'decimal',
            ),
            // Opmerking
            'Re' => array(
              'alias' => 'comment',
            ),
          ),
        );
        break;
    }

    // If no ID is specified, default AutoNum to TRUE for inserts.
    if (isset($info['fields']['AutoNum'])
        && $fields_action == 'insert' && !isset($data['#id'])) {
      $info['fields']['AutoNum']['default'] = TRUE;
    }

    // If this type is being rendered inside a parent type, then it cannot
    // contain its parent type. (Example: knPerson can be inside knContact and
    // it can also contain knContact... except when it is being rendered inside
    // knContact.)
    if (isset($info['objects'][$parent_type])) {
      unset($info['objects'][$parent_type]);
    }

    // If the definition has address and postal address defined, and the data
    // has an address but no postal address set, then the default becomes
    // PadAdr = TRUE.
    if (isset($info['fields']['PadAdr'])
        && isset($info['objects']['KnBasicAddressAdr'])
        && isset($info['objects']['KnBasicAddressPad'])
        && (!empty($data['objects']['KnBasicAddressAdr'])
            || !empty($data['objects'][$info['objects']['KnBasicAddressAdr']]))
        && (empty($data['objects']['KnBasicAddressPad'])
            || empty($data['objects'][$info['objects']['KnBasicAddressPad']]))
    ) {
      $info['fields']['PadAdr']['default'] = TRUE;
    }

    return $info;
  }

}

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
