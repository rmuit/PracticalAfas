<?php
/**
 * @file
 * Contains AfasSoapConnection.
 */

//@TODO namespacing

/**
 * Class AfasSoapConnection.
 *
 * This contains general functions to make most AFAS SOAP calls and error
 * handling easier. No interface is officially defined, but the methods /
 * parameters are expected to remain stable.
 */
class AfasSoapConnection {

  /**
   * The client 'wrapper' class we use to execute actual SOAP calls.
   *
   * @var object
   */
  protected $client;

  /**
   * Ways of reporting errors.
   *
   * @var int
   */
  protected $errorReporting;

  /**
   * Info about the last call.
   *
   * @var array
   */
  protected $lastCallInfo;

  /**
   * Constructor function.
   *
   * @param string $client_class_name
   *   The name of the class to use which contains soap-client specific
   *   functionality in a call() method. It is almost never
   *   necessary to provide this argument, because 'default' uses the SoapClient
   *   class which comes bundled with PHP5.
   * @param array $options
   *   Configuration options for the class, and standard arguments to each SOAP
   *   call it will perform.
   */
  public function __construct($client_class_name = 'default', array $options = array()) {
    // By default, do not print errors to the screen.
    $this->errorReporting = AFAS_LOG_ERROR_DETAILS_WATCHDOG;

    if (empty($client_class_name) || $client_class_name == 'default') {
      // It is possible to override usage of the standard client by another
      // class (e.g. 'AfasNusoapClient'), in a site-config variable.
      $client_class_name = variable_get('afas_api_client_class', 'AfasSoapClient');
    }

    $this->client = new $client_class_name($options);
  }

  /**
   * Retrieves data from AFAS through a GET connector.
   *
   * @param string|int $data_id
   *   Identifier for the data. (Usually the name of the AFAS 'get connector',
   *   but this can differ with $data_type.)
   * @param array $filters
   *   (optional) Filters to apply before returning data
   * @param string $data_type
   *   (optional) Type of data to retrieve and, for get connectors, the format
   *   in which to return it.
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
   *   hardcoded in parseGetDataArguments() for convenience. Specifying these is
   *   usually unnecessary. To see what it can be used for, check the code in
   *   parseGetDataArguments() and/or the WSDL/documentation of the AFAS
   *   endpoint.
   *
   * @return string|array|SimpleXMLElement|bool
   *   See $data_type. FALSE for error.
   *
   * @throws \InvalidArgumentException
   *   If input arguments have an illegal value / unrecognized structure.
   * @throws \UnexpectedValueException
   *   If the SoapClient returned a response in an unknown format.
   * @throws \Exception
   *   If anything else went wrong. (a remote error could throw e.g. a SoapFault
   *   depending on the client class used.)
   *
   * @see parseGetDataArguments()
   */
  public function getData($data_id, array $filters = array(), $data_type = 'get', array $extra_arguments = array()) {
    list($function, $arguments, $connector_type) = $this->parseGetDataArguments($data_id, $filters, $data_type, $extra_arguments);

    // Check the arguments which influence the output format.
    if ($function === 'GetDataWithOptions' && isset($extra_arguments['options']['Outputmode'])
        && $extra_arguments['options']['Outputmode'] == 2) {
      // We don't support text output. There seems to be no reason for it, but
      // if you see one, feel free to create/test/send in a patch. (Possibly
      // implementing $data_type = 'get_text' in parseGetDataArguments()?)
      throw new \InvalidArgumentException('AfasSoapConnection::getData() cannot handle handle text output.', 30);
    }

    $data = $this->call($function, $arguments, $connector_type);
    if (!$data) {
      // UpdateConnector usually returns an empty string, but others don't. (An
      // empty response is still wrapped in XML.)
      // @todo get rid of the below return statement after we stop catching exceptions in call().
      return FALSE;
      throw new \UnexpectedValueException('Received empty response from AFAS call.', 31);
    }

    // What to return?
    switch (strtolower($data_type)) {
      case 'get':
        // Walk through the SimpleXMLElement to create array of arrays (items)
        // of string values (fields). We assume each first-level XML element
        // is a row containing fields without any further nested tags.
        $doc_element = new SimpleXMLElement($data);
        $items = array();

        if (isset($extra_arguments['options']['Outputoptions'])
            && $extra_arguments['options']['Outputoptions'] == 3) {
          // The XML may contain empty tags. These are empty SimpleXMLElements
          // but we want to convert them to empty strings.
          foreach ($doc_element as $row_element) {
            $items[] = array_map('strval', (array) $row_element);
          }
        }
        else {
          // All fields inside an 'item' are strings; we just need to convert
          // the item (SimpleXMLElement) itself.
          foreach ($doc_element as $row_element) {
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

  /**
   * Calls AFAS 'Update connector' with standard arguments and an XML string.
   *
   * @param $connector_name
   *   Name of the UpdateConnector
   * @param $xml
   *   XML string as specified by AFAS. See their XSD Schemas, or use
   *   AfasApiHelper::constructXML($connector_name, ...) as an argument to this
   *   method if you would rather pass custom arrays than an XML string.
   *
   * @return bool|mixed
   *   Response object from SOAP call, or FALSE for error. In the last case,
   *   error details can be retrieved through getLastCallInfo(), but
   *   they are printed/logged already (depending on getErrorReporting()).
   *   Be careful when checking only this return value for errors; most
   *   successful calls return an empty string. <-- @todo change
   *
   * @throws \UnexpectedValueException
   *   If the SoapClient returned a response in an unknown format.
   * @throws \Exception
   *   If anything else went wrong. (e.g. a client specific error.)
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
   *
   * @todo stop returning FALSE; move error handling elsewhere and make this
   *       class Drupal agnostic.
   */
  public function call($function, array $arguments, $connector_type) {

    // Clear error, remember current function and normalized arguments.
    $this->lastCallInfo = array(
      'function' => $function,
      'arguments' => $arguments,
    );

    try {
      $response = $this->client->callAfas($connector_type, $function, $arguments);
    }
    catch (\Exception $e) {
      $this->handleError(array(
        'error_code' => $e->getCode(),
        'error' => $e->getMessage(),
        'error_detail' => (string) $e,
      ));
      $response = FALSE;
    }

    return $response;
  }

  /**
   * Parses the arguments to getData().
   *
   * This is a separate function so a subclass can somewhat more easily change
   * the arguments to a 'getdata' SOAP call; not because it's expected to be
   * called from more than one place.
   *
   * @throws \InvalidArgumentException
   *   If the input arguments have an unrecognized structure.
   *
   * @see getData().
   */
  protected function parseGetDataArguments($data_id, array $filters = array(), $data_type = 'get', array $extra_arguments = array()) {
    $function = $connector_type = '';
    if (is_string($data_type)) {
      switch (strtolower($data_type)) {
        case 'get':
        case 'get_simplexml':
          $extra_arguments['connectorId'] = $data_id;
          if (!empty($filters)) {
            $extra_arguments['filtersXml'] = $this->parseFilters($filters);
          }
          $connector_type = 'get';
          $function = 'GetDataWithOptions';
          break;

        case 'report':
          $extra_arguments['reportID'] = $data_id;
          if (!empty($filters)) {
            $extra_arguments['filtersXml'] = $this->parseFilters($filters);
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
    if (!$function) {
      throw new \InvalidArgumentException('Unknown data_type value: ' . json_encode($data_type), 32);
    }

    // Process arguments that only apply to specific functions.
    if ($function === 'GetDataWithOptions') {
      // Turn 'options' argument from array into XML fragment. Always set it.
      // If $extra_arguments['options'] is not an array, it's silently ignored.
      $options = (isset($extra_arguments['options']) && is_array($extra_arguments['options'])) ? $extra_arguments['options'] : array();
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
      $extra_arguments['options'] = "<options>$options_str</options>";
    }

    return array($function, $extra_arguments, $connector_type);
  }

  /**
   * Constructs a 'FiltersXML' argument, usable by AFAS call.
   *
   * @param array $filters
   *   Filters in our own custom format. Various formats have been introduced
   *   over time:
   *   1) array(FIELD1 => VALUE1, ...) - to filter on one or several values.
   *      This is the simplest one, with a lot of use cases - and one which is
   *      too 'natural' for coders to stop supporting it.
   *   2) The same, but get the 'operator' from $arguments['filter_operator'].
   *      Is ok, but only allows one and the same operator for all filters.
   *   3) array(
   *        array(FIELD1 => VALUE1, ..., [ '#op' => operator1  ]),
   *        array(FIELD3 => VALUE3, ..., [ '#op' => operator2  ]),
   *      )
   *     This supports multiple operators but is harder to write/read. All
   *     sub-arrays are AND'ed together; AFAS get connectors do not support the
   *     'OR' operator here.
   *   We want to keep supporting 1 for easier readability (and 2 for backward
   *   compatibility), but to prevent strange errors, we'll also support '#op'
   *   in the first array level; this overrides 'filter_operator'. We also
   *   support mixed instances of both, meaning the following array has the same
   *   output as 3) above:
   *   array(
   *     FIELD1 => VALUE1,
   *     'key_is_ignored' => array(FIELD3 => VALUE3, ..., [ '#op' => operator2  ]),
   *     '#op' => operator1,
   *   )
   *   Operators can be numeric (AFAS like) as well as our custom values which
   *   are easier to work with (see source code).
   *
   * @return string
   *   The filters formatted as 'FiltersXML' argument.
   *
   * @throws \InvalidArgumentException
   *   If the input argument has an unrecognized structure.
   */
  protected function parseFilters(array $filters) {
    $filters_str = '';
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
      // The non-numeric array values below are added by us, to make the input
      // array less cryptic. To prevent errors, we'll have several 'signs'
      // resolve to the same op.
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
      $operator = !empty($filters['#op']) ? $filters['#op'] : '';
      if (!$operator) {
        $operator = !empty($arguments['filter_operator']) ? $arguments['filter_operator'] : 1;
      }
      if (is_numeric($operator)) {
        if (array_search($operator, $operators) === FALSE) {
          throw new \InvalidArgumentException("Unknown filter operator: $operator", 33);
        }
      }
      else {
        if (!isset($operators[$operator])) {
          throw new \InvalidArgumentException("Unknown filter operator: $operator", 33);
        }
        $operator = $operators[$operator];
      }

      // Some old code commented: we used to normalize the format of $filters.
      // We could still do that if a caller needs it, but not sure to what end.
//      unset($filters['#op']);
//      unset($filters['filter_operator']);
      foreach ($filters as $outerfield => $filter) { // &filter
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
              $filters_str .= '<Field FieldId="' . $key . '" OperatorType="' . $op . '">' . static::xmlValue($value) . '</Field>';
            }
          }
        }
        else {
          // Construct 1 filter in this section, with standard operator.
          $filters_str .= '<Field FieldId="' . $outerfield . '" OperatorType="' . $operator . '">' . static::xmlValue($filter) . '</Field>';
//          // Normalize $filters for reference by callers.
//          $filter = array(
//            $outerfield => $filter,
//            '#op' => $operator,
//          );
        }
      }
    }

    // There can be multiple 'Filter' tags with multiple FilterIds. We only need
    // to use one, it can contain all our filtered fields...
    return '<Filters><Filter FilterId="Filter1">' . $filters_str . '</Filter></Filters>';
  }

  /**
   * Returns meta info about the last SOAP call or other errors.
   * This is the way to get error information after a function returned FALSE.
   *
   * @param string $type
   *   Type of information to return. Can be:
   *   - empty: see return value
   *   - error:               string; error message
   *   - error_code:          int; error code
   *   - error_detail:        string; detailed error info
   *   - error_temporary:     bool: whether the error is temporary. (This is not
   *                          necessarily correct in 100% of cases; see code.)
   *   - function:            string; SOAP function name. Only filled for
   *                          error_source "wsdl" and "client".
   *   - arguments:           array; SOAP function arguments.
   *   - time:                string; timestamp for error.
   *
   * @return mixed
   *  If $type is empty, all these values are returned in an encompassing array.
   *  In that case, not all elements are guaranteed to be set (see param).
   *
   * @see handleError()
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

    // Set whether the error is temporary.
    if (!isset($error_info['error_temporary'])) {
      // Deduce 'temporary' ourselves. We don't have any error codes so we'll
      // need to be imperfect and just keep adding any descriptions we see.
      // Until now, we've erred on the side of marking "temporary". The most
      // probable effect, for processes checking this, is that less calls get
      // silently discarded, and more calls get silently regularly re-tried
      // when that doesn't really make sense.
      // To be re-evaluated whenever necessary.
      //
      // @todo we have no error_source anymore. Check what to do with this.
      $this->lastCallInfo['error_temporary'] =
        FALSE && //in_array($error_info['error_source'], array('client', 'wsdl'), TRUE) &&
        (strpos($error_info['error'], 'Operation timed out') !== FALSE
         || strpos($error_info['error'], 'connection timeout') !== FALSE
         || strpos($error_info['error'], 'error parsing WSDL') !== FALSE
         || strpos($error_info['error'], 'System.OutOfMemoryException') !== FALSE
         // MS SQL Server errors:
         || strpos($error_info['error'], 'insufficient disk space') !== FALSE
         || strpos($error_info['error'], 'tempdb is probably out of space') !== FALSE
         // We've observed (intermittent) 403s (access denied) on AFAS server/
         // load problems. 403s should not normally be encountered, so marking
         // 'temporary' should be safe.
         || strpos($error_info['error'], 'HTTP response status 403') !== FALSE
         // This one's more doubtful. (We've seen it on temporary errors, but
         // probably there are permanent errors too which cause this.
         || strpos($error_info['error'], 'Response not of type text/xml') !== FALSE
         // And this one's even more doubtful: [ANTA WARNING] are very generic.
         // We should really be looking at the error_detail instead, to make a
         // distinction there, because some [ANTA WARNING]s are permanent.
         || strpos($error_info['error'], '[ANTA WARNING]') !== FALSE
      );
    }

    // Log to watchdog, error plus (optionally) details into one message.
    if ($this->errorReporting & AFAS_LOG_ERROR_DETAILS_WATCHDOG) {
      $details = $this->getLastCallInfo('error_detail');
      $function = $this->getLastCallInfo('function');
      if ($function) {
        // $details contains newlines which we want to honor when viewing
        // watchdog logs on screen. But we also do not want to assume its value
        // is safe. So we cannot replace newlines by <br> because those will be
        // escaped with the rest of the value. (Unless we sanitize all of
        // $details before saving.) Therefore: enclose in <pre>.
        // Since we're already doing that for $details, do for $args too.
        watchdog('afas_api', "Error: @error<br>Function: @function<br>Arguments:<br><pre>@args</pre><br><strong>Error details:</strong><br><pre>@detail</pre>",
          array(
            '@error' => $this->getLastCallInfo('error'),
            '@function' => $this->getLastCallInfo('function'),
            '@args' => print_r($this->getLastCallInfo('arguments'), TRUE),
            '@detail' => $details ? $details : '-'),
          WATCHDOG_ERROR);
      }
      else {
        watchdog('afas_api', "Error: @error<br>Error details:<br><pre>@detail</pre>",
          array(
            '@error' => $this->getLastCallInfo('error'),
            '@detail' => $details ? $details : '-'),
          WATCHDOG_ERROR);
      }
    }
    elseif ($this->errorReporting & AFAS_LOG_ERRORS_WATCHDOG) {
      $function = $this->getLastCallInfo('function');
      if ($function) {
        watchdog('afas_api', "Error: @error<br/>Function: @function<br/>Arguments: @args",
          array(
            '@error' => $this->getLastCallInfo('error'),
            '@function' => $this->getLastCallInfo('function'),
            '@args' => print_r($this->getLastCallInfo('arguments'), TRUE)), WATCHDOG_ERROR);
      }
      else {
        // @todo: we removed 'error_source' since we don't have that distinction
        //        anymore but do we now have other info? Exception type?
        watchdog('afas_api', "Error: @error", array('@error' => $this->getLastCallInfo('error')), WATCHDOG_ERROR);
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
      $message = $function . check_plain($this->getLastCallInfo('error'));
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
        drupal_set_message('Error details:<br><pre>' . check_plain($details) . '</pre>', 'error');
      }
    }
  }


  /**
   * Prepare a value for inclusion in XML: trim and encode.
   * @param string $text
   * @return string
   */
  protected static function xmlValue($text) {
    // check_plain() / ENT_QUOTES converts single quotes to &#039; which is
    // illegal in XML so we can't use it for sanitizing.) The below is
    // equivalent to "htmlspecialchars($text, ENT_QUOTES | ENT_XML1)", but also
    // valid in PHP < 5.4.
    return str_replace("'", '&apos;', htmlspecialchars(trim($text)));
  }
}
