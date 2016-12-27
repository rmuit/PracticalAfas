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

use \SimpleXMLElement;

/**
 * General functions to make most AFAS calls easier.
 */
class Connection {

  /**
   * The SimpleAfas client we use to execute actual AFAS calls.
   *
   * @var object
   */
  protected $client;

  /**
   * Constructor function.
   *
   * @param object $client
   *   A SimpleAfas client object.
   */
  public function __construct($client) {
    $this->setClient($client);
  }

  /**
   * Returns the AFAS client object.
   *
   * @return object
   */
  public function getClient() {
    return $this->client;
  }

  /**
   * Sets the client used for the connection.
   *
   * @param object $client
   *   An AFAS client object.
   */
  public function setClient($client) {
    $this->client = $client;
  }

  /**
   * Calls AFAS 'Update connector' with standard arguments and an XML string.
   *
   * @param $connector_name
   *   Name of the UpdateConnector.
   * @param string $xml
   *   XML string as specified by AFAS. See their XSD Schemas, or use
   *   AfasApiHelper::constructXML($connector_name, ...) as an argument to this
   *   method if you would rather pass custom arrays than an XML string.
   *
   * @return string
   *   Response from SOAP call. Most successful calls return empty string.
   *
   * @throws \UnexpectedValueException
   *   If the SoapClient returned a response in an unknown format.
   * @throws \Exception
   *   If anything else went wrong. (e.g. a client specific error.)
   */
  function sendXml($connector_name, $xml) {
    // This is just a 'shorthand' to hide away all those confusing arguments
    // to callAfas() that we never want to see or change.
    return $this->client->callAfas(
      'update',
      'Execute',
      array(
        'logonAs' => '',
        'connectorType' => $connector_name,
        'connectorVersion' => '1',
        'dataXml' => $xml,
      )
    );
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
   *   (optional) Other arguments to pass to the soap call, besides the ones
   *   hardcoded in parseGetDataArguments() for convenience. Specifying these is
   *   usually unnecessary. To see what it can be used for, check the code in
   *   parseGetDataArguments() and/or the WSDL/documentation of the AFAS
   *   endpoint.
   *
   * @return string|array|SimpleXMLElement
   *   See $data_type.
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
    list($function, $arguments, $connector_type) = static::parseGetDataArguments($data_id, $filters, $data_type, $extra_arguments);

    // Check the arguments which influence the output format.
    if ($function === 'GetDataWithOptions' && isset($extra_arguments['options']['Outputmode'])
        && $extra_arguments['options']['Outputmode'] == 2) {
      // We don't support text output. There seems to be no reason for it, but
      // if you see one, feel free to create/test/send in a patch. (Possibly
      // implementing $data_type = 'get_text' in parseGetDataArguments()?)
      throw new \InvalidArgumentException('AfasSoapConnection::getData() cannot handle handle text output.', 30);
    }

    $data = $this->client->callAfas($connector_type, $function, $arguments);
    if (!$data) {
      // UpdateConnector usually returns an empty string, but others don't. (An
      // empty response is still wrapped in XML.)
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
  protected static function parseGetDataArguments($data_id, array $filters = array(), $data_type = 'get', array $extra_arguments = array()) {
    $function = $connector_type = '';
    if (is_string($data_type)) {
      switch (strtolower($data_type)) {
        case 'get':
        case 'get_simplexml':
          $extra_arguments['connectorId'] = $data_id;
          if (!empty($filters)) {
            $extra_arguments['filtersXml'] = static::parseFilters($filters);
          }
          $connector_type = 'get';
          $function = 'GetDataWithOptions';
          break;

        case 'report':
          $extra_arguments['reportID'] = $data_id;
          if (!empty($filters)) {
            $extra_arguments['filtersXml'] = static::parseFilters($filters);
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
  protected static function parseFilters(array $filters) {
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
