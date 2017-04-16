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

use \SimpleXMLElement;

/**
 * General functions to make most AFAS calls easier.
 */
class Connection {

  // Constants for data type, named after connector names.
  const DATA_TYPE_GET = 'get';
  const DATA_TYPE_REPORT = 'report';
  const DATA_TYPE_SUBJECT = 'subject';
  const DATA_TYPE_DATA = 'data';
  const DATA_TYPE_TOKEN = 'token';
  const DATA_TYPE_VERSION_INFO = 'versioninfo';
  // 'alias' constants that are more descriptive than the connector names.
  const DATA_TYPE_ATTACHMENT = 'subject';
  const DATA_TYPE_SCHEMA = 'data';

  // Constants for filter operators.
  const OP_EQUAL = 1;
  const OP_LARGER_OR_EQUAL = 2;
  const OP_SMALLER_OR_EQUAL = 3;
  const OP_LARGER_THAN = 4;
  const OP_SMALLER_THAN = 5;
  const OP_LIKE = 6;
  const OP_NOT_EQUAL = 7;
  const OP_EMPTY = 8;
  const OP_NOT_EMPTY = 9;
  const OP_STARTS_WITH = 19;
  const OP_NOT_LIKE = 11;
  const OP_NOT_STARTS_WITH = 12;
  const OP_ENDS_WITH = 13;
  const OP_NOT_ENDS_WITH = 4;
  // 'alias' constants because "like" is a bit ambiguous.
  const OP_CONTAINS = 6;
  const OP_NOT_CONTAINS = 11;

  // Constants representing the 'Outputmode' option for GetConnectors. There are
  // numeric values which are supported as-is by the AFAS endpoint, and other
  // values which represent us needing to process the returned (XML) value to
  // some other format. Default is ARRAY.
  const GET_OUTPUTMODE_ARRAY = 'Array';
  const GET_OUTPUTMODE_SIMPLEXML = 'SimpleXMLElement';
  const GET_OUTPUTMODE_XML = 1;
  // TEXT is defined here, but not supported by this class!
  const GET_OUTPUTMODE_TEXT = 2;

  // Constants representing the (XML) 'Outputoptions' option for GetConnectors.
  // EXCLUDE means that empty column values will not be present in the row
  // representation. (This goes for all 'XML' output modes i.e. also for ARRAY.)
  const GET_OUTPUTOPTIONS_XML_EXCLUDE_EMPTY = 2;
  const GET_OUTPUTOPTIONS_XML_INCLUDE_EMPTY = 3;
  // For text mode, this is what the documentation says and what we have not
  // implemented:
  // "1 = Puntkomma (datums en getallen in formaat van regionale instellingen)
  //  2 = Tab       (datums en getallen in formaat van regionale instellingen)
  //  3 = Puntkomma (datums en getallen in vast formaat)
  //  4 = Tab       (datums en getallen in vast formaat)
  //  Vast formaat betekent: dd-mm-yy voor datums en punt als decimaal scheidingteken voor getallen."

  // Constants representing the 'Metadata' option for GetConnectors.
  const GET_METADATA_NO = 0;
  const GET_METADATA_YES = 1;

  /**
   * The PracticalAfas client we use to execute actual AFAS calls.
   *
   * @var object
   */
  protected $client;

  /**
   * Constructor function.
   *
   * @param object $client
   *   A PracticalAfas client object.
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
        'connectorType' => $connector_name,
        'dataXml' => $xml,
      )
    );
  }

  /**
   * Retrieves data from AFAS.
   *
   * @param string|int $data_id
   *   Identifier for the data, dependent on $data_type:
   *   DATA_TYPE_GET (default)      : the name of a GetConnector.
   *   DATA_TYPE_REPORT             : report ID for a ReportConnector.
   *   DATA_TYPE_SUBJECT/ATTACHMENT : 'subject ID' (int) for a SubjectConnector.
   *   DATA_TYPE_DATA/SCHEMA        : the function name for which to retrieve
   *                                  the XSD schema.
   *   DATA_TYPE_TOKEN              : the user ID to request the token for.
   *   DATA_TYPE_VERSION_INFO       : this argument is ignored.
   * @param array $filters
   *   (optional) Filters to apply before returning data
   * @param string $data_type
   *   (optional) Type of data to retrieve. See the DATA_TYPE_ constants.
   * @param array $extra_arguments
   *   (optional) Other arguments to pass to the soap call, besides the ones
   *   in $data_id / hardcoded in parseGetDataArguments() for convenience.
   *   Specifying these is usually unnecessary, except for DATA_TYPE_TOKEN. To
   *   see what it can be used for, check the code in parseGetDataArguments()
   *   and this function, and/or the WSDL/documentation of the AFAS endpoint.
   *
   * @return mixed
   *   Output; format is dependent on data type and extra arguments. The default
   *   output format is a two-dimensional array of rows/columns of data from the
   *   GetConnector; for other connectors it's a string. PLEASE NOTE that by
   *   default, a call to a GET connector returns maximum 1000 rows, which can
   *   be changed with the 'take' argument.
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
  public function getData($data_id, array $filters = array(), $data_type = self::DATA_TYPE_GET, array $extra_arguments = array()) {
    // Just in case the user specified something other than a constant:
    if (!is_string($data_type)) {
      throw new \InvalidArgumentException('Unknown data_type value: ' . json_encode($data_type), 32);
    }
    $data_type = strtolower($data_type);

    list($connector_type, $function, $arguments) = static::parseGetDataArguments($data_id, $filters, $data_type, $extra_arguments);

    // Check the arguments which influence the output format.
    if ($function === 'GetDataWithOptions' && isset($extra_arguments['options']['Outputmode']) && $extra_arguments['options']['Outputmode'] == self::GET_OUTPUTMODE_TEXT) {
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

    // Data needs to be processed if we provided any of our custom output modes
    // for a GetConnector. The default for Get is to return an array.
    if ($data_type === self::DATA_TYPE_GET && (!isset($extra_arguments['options']['Outputmode']) || !is_numeric($extra_arguments['options']['Outputmode']))) {
      $doc_element = new SimpleXMLElement($data);
      if (isset($extra_arguments['options']['Outputmode']) && $extra_arguments['options']['Outputmode'] === self::GET_OUTPUTMODE_SIMPLEXML) {
        $data = $doc_element;
      }
      else {
        // Walk through the SimpleXMLElement to create array of arrays (items)
        // of string values (fields). We assume each first-level XML element
        // is a row containing fields without any further nested tags.
        $data = array();

        if (isset($extra_arguments['options']['Outputoptions']) && $extra_arguments['options']['Outputoptions'] == self::GET_OUTPUTOPTIONS_XML_INCLUDE_EMPTY) {
          // The XML may contain empty tags. These are empty SimpleXMLElements
          // but we want to convert them to empty strings.
          foreach ($doc_element as $row_element) {
            $data[] = array_map('strval', (array) $row_element);
          }
        }
        else {
          // All fields inside an 'item' are strings; we just need to convert
          // the item (SimpleXMLElement) itself.
          foreach ($doc_element as $row_element) {
            $data[] = (array) $row_element;
          }
        }
      }
    }

    return $data;
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
   * @see getData()
   */
  protected static function parseGetDataArguments($data_id, array $filters = array(), $data_type = 'get', array $extra_arguments = array()) {
    $function = '';
    switch ($data_type) {
      case self::DATA_TYPE_GET:
        // We don't support the 'GetData' function. It seems to not have any
        // advantages.
        $function = 'GetDataWithOptions';
        $extra_arguments['connectorId'] = $data_id;
        if (!empty($filters)) {
          $extra_arguments['filtersXml'] = static::parseFilters($filters);
        }
        // For more on the 'options' argument, see below the switch statement.
        break;

      case self::DATA_TYPE_REPORT:
        $function = 'Execute';
        $extra_arguments['reportID'] = $data_id;
        if (!empty($filters)) {
          $extra_arguments['filtersXml'] = static::parseFilters($filters);
        }
        break;

      case self::DATA_TYPE_SUBJECT:
        $function = 'GetAttachment';
        $extra_arguments['subjectID'] = $data_id;
        break;

      case self::DATA_TYPE_DATA:
        // Oct 2014: I finally saw the first example of a 'DataConnector' in
        // the latest version of the online documentation, at
        // http://profitdownload.afas.nl/download/Connector-XML/DataConnector_SOAP.xml
        // (on: Connectors > Call a Connector > SOAP call > UpdateConnector,
        //  which is https://static-kb.afas.nl/datafiles/help/2_9_5/SE/EN/index.htm#App_Cnnctr_Call_SOAP_Update.htm)
        // Funny thing is: there is NO reference of "DataConnector" in the
        // documentation anymore!
        // dataID is apparently hardcoded (as far as we know there is no other
        // function for the so-called 'DataConnector' than getting XML schema)
        $function = 'Execute';
        $extra_arguments['dataID'] = 'GetXmlSchema';
        $extra_arguments['parametersXml'] = "<DataConnector><UpdateConnectorId>$data_id</UpdateConnectorId><EncodeBase64>false</EncodeBase64></DataConnector>";
        break;

      case 'token':
        $function = 'GenerateOTP';
        // apiKey & environmentKey are required. AFAS is not famous for its
        // understandable error messages (e.g. if one of them is missing then
        // we get the notorious "Er is een onverwachte fout opgetreden") so
        // we'll take over that task and throw exceptions.
        if (empty($extra_arguments['apiKey']) || empty($extra_arguments['environmentKey'])) {
          throw new \InvalidArgumentException("Required extra arguments 'apiKey' and 'environmentKey' not both provided.", 34);
        }
        if (!is_string($extra_arguments['apiKey']) || strlen($extra_arguments['apiKey']) != 32
            || !is_string($extra_arguments['environmentKey']) || strlen($extra_arguments['environmentKey']) != 32) {
          throw new \InvalidArgumentException("Extra arguments 'apiKey' and 'environmentKey' should both be 32-character strings.", 34);
        }
        $extra_arguments['userId'] = $data_id;
        break;

      case 'versioninfo':
        $function = 'GetProductVersion';
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
        'Outputmode' => self::GET_OUTPUTMODE_XML,
        'Metadata' => self::GET_METADATA_NO,
        'Outputoptions' => self::GET_OUTPUTOPTIONS_XML_EXCLUDE_EMPTY,
      );

      // See getData(); we may support our custom output formats. These are
      // based on XML output, so we adjust the option to "XML" here:
      if (!is_numeric($options['Outputmode'])) {
        $options['Outputmode'] = self::GET_OUTPUTMODE_XML;
      }

      $options_str = '';
      foreach ($options as $option => $value) {
        $options_str .= "<$option>$value</$option>";
      }
      $extra_arguments['options'] = "<options>$options_str</options>";

      // For get connectors that are called through App Connectors, there are
      // 'skip/take' arguments, and we make the 'take' argument mandatory: if
      // it is not specified, the output will be empty. (Further general
      // validation is done in the connector classes, but the job of hardcoding
      // a capped value does not belong in there. For NTLM Connectors, this is
      // not tested, but they are not functional anymore anyway.)
      if (!isset($extra_arguments['take'])) {
        $extra_arguments['take'] = 1000;
      }
    }

    return array($data_type, $function, $extra_arguments);
  }

  /**
   * Constructs a 'FiltersXML' argument, usable by AFAS call.
   *
   * @param array $filters
   *   Filters in our own custom format:
   *   1) [ FIELD1 => VALUE1, ...,  '#op' => operator ], to filter on one or
   *      several values. The '#op' key is optional and defaults to OP_EQUAL.
   *   2) [
   *        [ FIELD1 => VALUE1, ..., '#op' => operator1 ],
   *        [ FIELD2 => VALUE2, ..., '#op' => operator2 ],
   *      ]
   *     This supports different operators but is harder to write/read. All
   *     sub-arrays are AND'ed together; AFAS GetConnectors do not support the
   *     'OR' operator here.
   * @TODO or do they? I've seen mention of ORs in new examples...
   *   Mixing the formats up (that is: having array values _in the outer array_
   *   which are both scalars and arrays) is allowed. If the values are arrays,
   *   keys are ignored; if they are scalars, keys are the field values.
   *
   *   For the operator values, see the OP_* constants defined in this class.
   *
   * @return string
   *   The filters formatted as 'FiltersXML' argument. (If the input is empty,
   *   then the XML will contain a few tags with no content; the effect of this
   *   is untested. Just don't call this with empty input.)
   *
   * @throws \InvalidArgumentException
   *   If the input argument has an unrecognized structure.
   */
  protected static function parseFilters(array $filters) {
    $filters_str = '';
    if ($filters) {
      $operator = !empty($filters['#op']) ? $filters['#op'] : self::OP_EQUAL;
      if (!is_numeric($operator) || $operator < 1 || $operator > 14) {
        throw new \InvalidArgumentException('Unknown filter operator: ' . json_encode($operator), 33);
      }

      foreach ($filters as $outerfield => $filter) {
        if ($outerfield !== '#op') {

          if (is_array($filter)) {
            // Process extra layer.
            $op = (!empty($filter['#op'])) ? $filter['#op'] : $operator;
            foreach ($filter as $key => $value) {
              if ($key !== '#op') {
                $filters_str .= '<Field FieldId="' . $key . '" OperatorType="' . $op . '">' . static::xmlValue($value) . '</Field>';
              }
            }
          }
          else {
            // Construct 1 filter in this section, with standard operator.
            $filters_str .= '<Field FieldId="' . $outerfield . '" OperatorType="' . $operator . '">' . static::xmlValue($filter) . '</Field>';
          }
        }
      }
    }

    // There can be multiple 'Filter' tags with multiple FilterIds. We only need
    // to use one, it can contain all our filtered fields.
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
