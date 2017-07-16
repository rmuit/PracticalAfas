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

use InvalidArgumentException;
use RuntimeException;
use SimpleXMLElement;
use UnexpectedValueException;

/**
 * General functions to make most AFAS calls easier.
 *
 * This is basically a wrapper around one of the client classes' callAfas().
 * Clients could be used standalone but their call structure is more tedious and
 * not (necessarily) the same across clients. Calling Connection methods instead
 * requires less knowledge of client specific details.
 */
class Connection
{
    // Constants for data type, named after connector names.
    const DATA_TYPE_GET = 'get';
    const DATA_TYPE_REPORT = 'report';
    const DATA_TYPE_SUBJECT = 'subject';
    const DATA_TYPE_DATA = 'data';
    const DATA_TYPE_TOKEN = 'token';
    const DATA_TYPE_VERSION_INFO = 'versioninfo';
    // 'alias' constants that are more descriptive than the connector names.
    const DATA_TYPE_ATTACHMENT = 'subject';
    // For SOAP:
    const DATA_TYPE_SCHEMA = 'data';
    // For REST:
    const DATA_TYPE_METAINFO_UPDATE = 'data';
    const DATA_TYPE_METAINFO_GET = 'metainfo_get';
    // We've defined 'filter types' as a subtype of REST GetConnector, which is
    // a more logical way of thinking about the 3rd getData() argument if you're
    // only calling GetConnectors (as is usual). This is the only way to do 'OR'
    // filters.
    const GET_FILTER_AND = 'get';
    const GET_FILTER_OR = 'get_or';

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

    // Constants for the 'Outputmode' option for SOAP GetDataWithOptions calls,
    // which we've added our own formats to. See setDataOutputFormat().
    const GET_OUTPUTMODE_ARRAY = 'Array';
    const GET_OUTPUTMODE_SIMPLEXML = 'SimpleXMLElement';
    // We've basically renamed 'XML' to 'Literal' because for a REST client,
    // this will return JSON instead of XML. (The original XML constant is now
    // 'quasi deprecated'.)
    const GET_OUTPUTMODE_LITERAL = 1;
    const GET_OUTPUTMODE_XML = 1;
    // TEXT is defined here, but not supported by this class and apparently also
    // not supported by the SOAP (App)Connector anymore!
    const GET_OUTPUTMODE_TEXT = 2;

    // Constants representing the (XML)'Outputoptions' option for GetConnectors.
    // EXCLUDE means that empty column values will not be present in the row
    // representation. (This goes for all XML output modes i.e. also for ARRAY.)
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
     * The name of the helper class we use for many sendData calls.
     *
     * There is no getter/setter for this. It's only injected in the
     * constructor.
     *
     * @var string
     */
    protected $helperClassName;

    /**
     * The PracticalAfas client we use to execute actual AFAS calls.
     *
     * @var object
     */
    protected $client;

    /**
     * The 'type of client': "REST" or "SOAP".
     *
     * @var string
     */
    protected $clientType;

    /**
     * Default output format by getData() calls. See setDataOutputFormat().
     *
     * @var int|string
     */
    protected $outputFormat;

    /**
     * Whether getData() includes metadata. See setDataIncludeMetadata().
     *
     * @var bool
     */
    protected $includeMetadata;

    /**
     * Whether getData() includes empty fields. See setDataIncludeEmptyFields().
     *
     * @var bool
     */
    protected $includeEmptyFields;

    /**
     * Constructor function.
     *
     * @param object $client
     *   A PracticalAfas client object.
     * @param string $helper_class_name
     *   (optional) name of a class containing helper methods. This is used in
     *   sendData(), for now; see there for the method names. We inject a class
     *   name, not an instantiated object, since the methods are static and not
     *   always used. The typical use case for passing this argument is to set
     *   a subclass of Helper, which has had objectTypeInfo() extended with
     *   custom field definitions.
     */
    public function __construct($client, $helper_class_name = '\PracticalAfas\Helper')
    {
        $this->setClient($client);
        $this->helperClassName = $helper_class_name;
    }

    /**
     * Returns the AFAS client object.
     *
     * @return object
     */
    public function getClient()
    {
        return $this->client;
    }

    /**
     * Sets the client used for the connection.
     *
     * When using this setter (rather than just passing the client through the
     * class constructor), please note that other class properties might be set
     * to a value that makes getData() calls throw an exception, so these
     * properties might need to be explicitly (re)set too.
     *
     * @param object $client
     *   An AFAS client object.
     */
    public function setClient($client)
    {
        $this->client = $client;
        $class = get_class($client);
        // Set the type so the rest of our code can decide on logic to follow.
        // Historically, SOAP classes have not had a type defined.
        $this->clientType = defined("$class::CLIENT_TYPE") ? $class::CLIENT_TYPE : 'SOAP';
        if (!in_array($this->clientType, ['REST', 'SOAP'], true)) {
            throw new InvalidArgumentException("Unrecognized client type {$this->clientType}.", 1);
        }
    }

    /**
     * Returns the 'type' of client set for this connection: "REST" or "SOAP".
     *
     * @return string
     */
    public function getClientType()
    {
        return $this->clientType;
    }

    /**
     * Indicates the default output format of getData() calls.
     *
     * This only has meaning for REST clients, and for SOAP GetConnectors.
     *
     * 'Default' means: unless specified in the 'options' argument for an
     * individual getData() call.
     *
     * @return int|string
     *
     * @see setDataOutputFormat()
     */
    public function getDataOutputFormat()
    {
        return isset($this->outputFormat) ? $this->outputFormat : self::GET_OUTPUTMODE_ARRAY;
    }

    /**
     * Sets the default output format for getData() calls.
     *
     * Constants for valid values are defined in this class, and are named
     * OUTPUTMODE_* (not OUTPUTFORMAT_*) since that is how AFAS used to name
     * its options for SOAP calls. Some (numeric) values are original options
     * passed to SOAP 'GetDataWithOptions' calls and influence the response from
     * the remote API; most values influence post processing of the response.
     *
     * @param int|string $format
     */
    public function setDataOutputFormat($format)
    {
        $this->outputFormat = $format;
    }

    /**
     * Indicates if getData() includes metadata in its return value by default.
     *
     * This only has meaning for calls to GetConnectors.
     * @todo this might change to "This only has meaning for REST clients, and for SOAP GetConnectors." if other connectors are tested.
     *
     * This has different meanings for different client types:
     * - for REST clients, false means that only the queried data from the
     *   GetConnector (the 'rows' value in the REST API response) is returned
     *   and true means the full REST API response is returned, including other
     *   properties like 'skip' and 'take' besides 'rows'.
     * - for SOAP clients, the value indicates whether GetConnector calls using
     *   LITERAL/XML output mode include the XML schema of the connector in
     *   their output. (Other calls never include it.)
     *
     * 'By default' means: unless specified in the 'options' argument for an
     * individual getData() call. If this value was never set explicitly, then
     * it depends on client type and output format: for REST clients where
     * output format is GET_OUTPUTMODE_LITERAL, it is true; otherwise false.
     *
     * @return bool
     */
    public function getDataIncludeMetadata()
    {
        if (isset($this->includeMetadata)) {
            $ret = $this->includeMetadata;
        } else {
            $ret = $this->clientType !== 'SOAP' && $this->getDataOutputFormat() == self::GET_OUTPUTMODE_LITERAL;
        }
        return $ret;
    }

    /**
     * Sets indicator to return metadata by some getData() calls.
     *
     * @param bool $include
     *
     * @see getDataIncludeMetadata()
     */
    public function setDataIncludeMetadata($include)
    {
        $this->includeMetadata = $include;
    }

    /**
     * Indicates if getData() has empty fields in its return value by default.
     *
     * This only has meaning for calls to GetConnectors.
     *
     * 'By default' means: unless specified in the 'options' argument for an
     * individual getData() call. If this value was never set explicitly, then
     * it depends on client type: for REST clients it's true, for SOAP false.
     *
     * @return bool
     */
    public function getDataIncludeEmptyFields()
    {
        return isset($this->includeEmptyFields) ? $this->includeEmptyFields : $this->clientType !== 'SOAP';
    }

    /**
     * Sets indicator to return empty fields in getData() return value.
     *
     * False is only valid for SOAP clients, so far.
     *
     * @param bool $include
     *
     * @see getDataIncludeEmptyFields()
     */
    public function setDataIncludeEmptyFields($include)
    {
        $this->includeEmptyFields = $include;
    }

    /**
     * Calls AFAS Update Connector with standard arguments and an XML string.
     *
     * @param $connector_name
     *   Name of the Update Connector.
     * @param string $xml
     *   XML string as specified by AFAS. See their XSD Schemas, or use
     *   AfasApiHelper::constructXML($connector_name, ...) as an argument to
     *     this method if you would rather pass custom arrays than an XML
     *     string.
     *
     * @return string
     *   Response from SOAP call. Most successful calls return empty string.
     *
     * @throws \RuntimeException
     *   If called for a REST client.
     * @throws \UnexpectedValueException
     *   If the SoapClient returned a response in an unknown format.
     * @throws \Exception
     *   If anything else went wrong. (e.g. a client specific error.)
     *
     * @deprecated We now have sendData which is more general and where you
     *   can provide an array as the second parameter.
     */
    function sendXml($connector_name, $xml)
    {
        if ($this->clientType !== 'SOAP') {
            throw new RuntimeException('sendXml() is not supported by REST clients.', 30);
        }
        return $this->client->callAfas('update', 'Execute', ['connectorType' => $connector_name, 'dataXml' => $xml]);
    }

    /**
     * Sends data to an AFAS Update Connector.
     *
     * @param $connector_name
     *   Name of the Update Connector.
     * @param string|array $data
     *   Data string to send in to the Update Connector; must be XML or JSON
     *   depending on the client type. If this is an array, it will be converted
     *   to a string using the helper class configured for this Connection.
     * @param string $rest_verb
     *   (optional) the HTTP verb representing the action to take: "POST" for
     *   insert, "PUT" for update, "DELETE" for delete. Must be in upper case;
     *   defaults to PUT. This also applies to SOAP clients if the $data
     *   argument is an array: specify "POST" for inserting new data, because
     *   this will influence how the XML is built.
     *
     * @return string
     *   Response from SOAP call. Most successful calls return empty string.
     *
     */
    function sendData($connector_name, $data, $rest_verb = 'PUT')
    {
        // We've specified a rest verb instead of "insert" / "update" / "delete"
        // because this way, it is still easier to change things if necessary
        // with regards to the $fields_action argument to the Helper methods.
        // For how, there is a direct relation between the verb and the action.
        // If there are ever situations in which this should change, then
        // (documented) PRs are welcomed.
        if (!is_string($data)) {
            $actions = ['PUT' => 'update', 'POST' => 'insert', 'DELETE' => 'delete'];
            $fields_action = isset($actions[$rest_verb]) ? $actions[$rest_verb] : '';
            $class = $this->helperClassName;
        }

        if ($this->clientType === 'SOAP') {
            if (!is_string($data)) {
                $data = $class::constructXml($connector_name, $data, $fields_action);
            }
            $response = $this->client->callAfas('update', 'Execute', ['connectorType' => $connector_name, 'dataXml' => $data]);
        } else {
            if (!is_string($data)) {
                $data = json_encode($class::normalizeDataToSend($connector_name, $data, $fields_action));
            }
            $response = $this->client->callAfas($rest_verb, "connectors/$connector_name", [], $data);
        }

        return $response;
    }

    /**
     * Retrieves data from AFAS.
     *
     * Admittedly the parameters are a bit overloaded; see README.md for
     * examples if confused.
     *
     * @param string|int $data_id
     *   Identifier for the data, dependent on $data_type:
     *   DATA_TYPE_GET (default)      : the name of a GetConnector.
     *   DATA_TYPE_REPORT             : report ID for a ReportConnector.
     *   DATA_TYPE_SUBJECT/ATTACHMENT : 'subject ID' (int) for SubjectConnector.
     *   DATA_TYPE_DATA/SCHEMA        : the function name for which to retrieve
     *                                  the XSD schema.
     *   DATA_TYPE_TOKEN              : the user ID to request the token for.
     *   DATA_TYPE_VERSION_INFO       : this argument is ignored.
     * @param array $filters
     *   (optional) Filters to apply before returning data.
     * @param string $data_type
     *   (optional) Type of data to retrieve / connector to call, and/or filter
     *   type. Defaults to GET_FILTER_AND / DATA_TYPE_GET (which is the same).
     *   Use GET_FILTER_OR to apply 'OR' to $filters instead of 'AND', for a
     *   GetConnector. Use other DATA_TYPE_ constants (see just above) to call
     *   other connectors.
     * @param array $extra_arguments
     *   (optional) Other arguments to pass to the API call, besides the ones
     *   in $filters / hardcoded for convenience. For GetConnectors this is
     *   typically 'Skip' and 'Take'. The 'options' arguments will not be sent
     *   to REST API calls but will still be interpreted, for compatibility with
     *   SOAP. Supported options are 'Outputmode, 'Metadata' and 'Outputoptions'
     *   whose valid values are GET_* constants defined in this class and which
     *   can also be influenced permanently instead of per getData() call; see
     *   setter methods setDataOutputFormat(), setDataIncludeMetadata() and
     *   setDataIncludeEmptyFields() respectively. Other supported options are
     *   'Skip' and 'Take', but it is recommended to not use these as options;
     *   set them at the root level of $extra_arguments instead. (If there are
     *   multiple arguments whose names only differ in case, then the value that
     *   is later in the array will override earlier arguments.)
     *
     * @return mixed
     *   Output; format is dependent on data type and extra arguments. The
     *   default output format is a two-dimensional array of rows/columns of
     *   data from the GetConnector. The array structure is not exactly the same
     *   for SOAP vs. REST clients:
     *   - If the 'take' argument is not specified, the REST API will (always?)
     *     return 100 rows maximum, and the SOAP client will return 1000 rows.
     *     (This is a default passed by the code, because if no 'take' argument
     *     is specified, the SOAP API will return no data. We do not want to
     *     lower the value to be the same default of 100, since that has a
     *     bigger chance of causing backward compatibility problems because
     *     pre-2017, the 'take' argument was unnecessary.
     *   - Empty fields will either not be returned by the SOAP endpoint or (if
     *     specified through 'Outputoptions'/ setDataIncludeEmptyFields())
     *     contain an empty string. Empty fields returned by the REST API will
     *     contain null.
     *
     * @throws \InvalidArgumentException
     *   If input arguments have an illegal value / unrecognized structure.
     * @throws \UnexpectedValueException
     *   If the SoapClient returned a response in an unknown format.
     * @throws \Exception
     *   If anything else went wrong. (a remote error could throw e.g. a
     *   SoapFault depending on the client class used.)
     *
     * @see parseGetDataArguments()
     */
    public function getData($data_id, array $filters = [], $data_type = self::DATA_TYPE_GET, array $extra_arguments = [])
    {
        // We're going to let AFAS report an error for wrong/empty scalar values
        // of data_id (e.g. connector), but at least throw here for any
        // non-scalar.
        if ((!is_string($data_id) || $data_id === '') && !is_int($data_id)) {
            throw new InvalidArgumentException("Invalid 'data_id' argument: " . json_encode($data_id), 32);
        }
        // Just in case the user specified something other than a constant:
        if (!is_string($data_type)) {
            throw new InvalidArgumentException("Invalid 'data_type' argument: " . json_encode($data_type), 32);
        }
        $data_type = strtolower($data_type);
        // Unify case of arguments, so we don't skip any validation. (If two
        // arguments with different case are in the array, the value that is
        // later in the array will override other indices.)
        $extra_arguments = array_change_key_case($extra_arguments);
        if (isset($extra_arguments['options']) && is_array($extra_arguments['options'])) {
            $extra_arguments['options'] = array_change_key_case($extra_arguments['options']);
        }

        // The SOAP GetDataWithOptions function supports an 'options' argument
        // with several sub values. This class initially supported three of them
        // ('Outputmode', 'Metadata' and 'Outputoptions') for SOAP calls, which
        // - we will also support for REST, to make switching from SOAP to REST
        //   clients easier;
        // - we have also made into setters/getters for this class, so they can
        //   be set once instead of being passed always;
        // - we will interpret for all calls where it makes sense (even though
        //   only GetConnectors for SOAP supported them initially).
        // Check these three values / defaults here, and validate them.
        // For more options: see further below.

        // 'Outputmode' only makes sense for SOAP GetConnectors (because for
        // other connectors, the output cannot be converted to arrays /
        // 'Outputmode' was never a thing) and for REST clients (because
        // supposedly all output is JSON, therefore can be converted to an
        // array? @todo doublecheck this... This will get clear when more PRs are sent in.)
        if ($this->clientType === 'SOAP' && $data_type !== self::DATA_TYPE_GET) {
            // We want to return the literal return value from the endpoint.
            $output_format = self::GET_OUTPUTMODE_LITERAL;
        } else {
            $output_format = isset($extra_arguments['options']['outputmode']) ? $extra_arguments['options']['outputmode']
                : $this->getDataOutputFormat();
            // 'Real' output modes as defined for SOAP, and which we piggyback
            // on with GET_OUTPUTMODE_LITERAL, are numeric. Only 1 other format
            // is defined: 'text' - and we do not support that. (There seems to
            // be no reason to support it; who disagrees, can send in a PR...)
            // 'Custom defined' output modes mean that we need to post-process
            // the returned body. There are Array and XML formats; the latter is
            // only supported for SOAP.
            if (is_numeric($output_format)) {
                $supported = $output_format == self::GET_OUTPUTMODE_LITERAL;
            } elseif ($this->clientType === 'SOAP') {
                $supported = in_array($output_format, [self::GET_OUTPUTMODE_ARRAY, self::GET_OUTPUTMODE_SIMPLEXML], true);
            } else {
                $supported = $output_format === self::GET_OUTPUTMODE_ARRAY;
            }
            if (!$supported) {
                throw new InvalidArgumentException("AfasSoapConnection::getData() cannot handle handle output mode $output_format.", 30);
            }
        }

        // 'Metadata' only makes sense for SOAP GetConnectors (where it
        // influences API return value) but may make sense for all REST API
        // calls (because we process the return value ourselves).
        if ($this->clientType !== 'SOAP' || $data_type === self::DATA_TYPE_GET) {
            if (isset($extra_arguments['options']['metadata'])) {
                $include_metadata = !empty($extra_arguments['options']['metadata']);
            } elseif ($this->clientType !== 'SOAP' && $output_format == self::GET_OUTPUTMODE_LITERAL) {
                // If the output format comes from $extra_arguments['options']['outputmode'],
                // this overrides the value to true even though
                // $this->getDataIncludeMetadata() will return false (because it
                // cannot see the actual output format).
                $include_metadata = true;
            } else {
                $include_metadata = $this->getDataIncludeMetadata();
            }
            if (!$include_metadata && $this->clientType !== 'SOAP' && $output_format !== self::GET_OUTPUTMODE_ARRAY) {
                // For SOAP, this is implemented at the server side. For REST we
                // have to process the output, which we only do for ARRAY.
                throw new InvalidArgumentException("The getData() call is set to not return metadata. This is not supported by REST clients unless they have 'ARRAY' output format set.", 35);
            }
            // @todo TEST ALL CONNECTORS!
            // If the actual data is contained in a sub-structure of the response
            // (just like 'rows' for GetConnectors), then it makes sense to support
            // only returning that sub-structure by default. But we can only decide
            // this after testing. Rather than introduce backward compatibility issues,
            // we throw an exception for all untested connector types when not having GET_OUTPUTMODE_LITERAL.
            // If you want a connector type to be supported, please send a PR or
            // an e-mail detailing the structure of the response!
            // I unfortunately do not have permission to test these anywhere right now --Roderik
            $untested_connectors = [
                self::DATA_TYPE_REPORT,
                self::DATA_TYPE_SUBJECT,
                self::DATA_TYPE_METAINFO_UPDATE,
                self::DATA_TYPE_METAINFO_GET,
                self::DATA_TYPE_VERSION_INFO,
            ];
            if (!$include_metadata && in_array($data_type, $untested_connectors, true)) {
                throw new \Exception("REST API '$data_type' connector is not tested yet. Please call it with 'Metadata' option set to true, or 'Outputformat' set to LITERAL, and/or send a Pull Request for the library including the structure of the output.");
            }
        }

        // 'Outputoptions' governs whether empty fields are present in output;
        // this really only makes sense for GetConnectors.
        if ($data_type === self::DATA_TYPE_GET || $data_type === self::GET_FILTER_OR) {
            $include_empty_fields = isset($extra_arguments['options']['outputoptions']) ?
                $extra_arguments['options']['outputoptions'] == self::GET_OUTPUTOPTIONS_XML_INCLUDE_EMPTY
                : $this->getDataIncludeEmptyFields();
            if (!$include_empty_fields && $this->clientType !== 'SOAP') {
                // We could support this for REST too, at least for
                // OUTPUTMODE_ARRAY which would mean processing output below.
                throw new InvalidArgumentException("The getData() call is set to not return empty fields. This is not supported by REST clients.", 36);
            }
        } else {
            // Skip logic below.
            $include_empty_fields = false;
        }

        // The SOAP GetDataWithOptions function's 'options' argument supports
        // more options than just above three. We have not seen these documented
        // in AFAS' online docs; they were probably added later (when AFAS added
        // a REST API) and this class did support them before v1.2. They are
        // - 'Take' and 'Skip'. That is right: the AFAS SOAP service recognizes
        //   these arguments as standalone arguments _and_ as 'options'
        //   arguments. They differ in behavior and the regular argument has
        //   stricter validation / does not auto-truncate. (For test results:
        //   see SoapAppclient::validateArguments().)
        foreach (['take', 'skip'] as $arg) {
            // We will accept both arguments as input (for REST clients too; why
            // not...) but warn if they are duplicate, because we don't want to
            // rely on the undocumented behavior of which one gets preference...
            if (isset($extra_arguments[$arg]) && isset($extra_arguments['options'][$arg])
                // Non-numeric arguments will fail later anyway; this slightly
                // strange comparison makes sure '0' is not replaced by '' just
                // below.
                && ($extra_arguments[$arg] != $extra_arguments['options'][$arg] || !is_numeric($extra_arguments['options'][$arg]))) {
                throw new InvalidArgumentException("Duplicate '$arg' argument found, both as regular argument and inside 'options'. One should be deleted (preferrably the option).", 38);
            }
            // ... and we'll always move the option to the regular argument,
            // because that one has less implicit/irregular behavior.
            if (isset($extra_arguments['options'][$arg])) {
                $extra_arguments[$arg] = $extra_arguments['options'][$arg];
                unset($extra_arguments['options'][$arg]);
            }
        }

        // We split up the parsing of further arguments into methods per client
        // type because it's so different.
        if ($this->clientType === 'SOAP') {
            if ($data_type === self::DATA_TYPE_GET) {
                // Always add the 'options' parameters which we just validated.
                if (isset($extra_arguments['options']) && !is_array($extra_arguments['options'])) {
                    throw new InvalidArgumentException("'options' argument must be an array value", 37);
                }
                $extra_arguments += ['options' => []];
                // If we have any output format not recognized by the SOAP call,
                // we actually want literal XML and we post process it later.
                if (!isset($extra_arguments['options']['outputmode']) || !is_numeric($extra_arguments['options']['outputmode'])) {
                    $extra_arguments['options']['outputmode'] = self::GET_OUTPUTMODE_LITERAL;
                }
                $extra_arguments['options'] += [
                    'metadata' => $include_metadata ? self::GET_METADATA_YES : self::GET_METADATA_NO,
                    'outputoptions' => $include_empty_fields ? self::GET_OUTPUTOPTIONS_XML_INCLUDE_EMPTY : self::GET_OUTPUTOPTIONS_XML_EXCLUDE_EMPTY,
                ];
            }
            list($type, $function, $arguments) = self::parseGetDataArguments($data_id, $filters, $data_type, $extra_arguments);
        } else {
            // If the 'options' argument holds an array, we've just preprocessed
            // those, to provide compatibility with a SOAP GetConnector. But we
            // don't want them to end up in the REST call.
            if (isset($extra_arguments['options']) && is_array($extra_arguments['options'])) {
                unset($extra_arguments['options']);
            }
            list($type, $function, $arguments) = self::parseGetDataRestArguments($data_id, $filters, $data_type, $extra_arguments);
        }

        // Get the data.
        $data = $this->client->callAfas($type, $function, $arguments);
        if (!$data) {
            // UpdateConnector usually returns an empty string but others don't.
            // (An empty response is still wrapped in XML / contains other meta
            // properties.)
            throw new UnexpectedValueException('Received empty response from AFAS call.', 31);
        }

        // Now, numeric format == GET_OUTPUTMODE_LITERAL. Others need to be
        // processed.
        if (!is_numeric($output_format)) {
            if ($this->clientType === 'SOAP') {
                $doc_element = new SimpleXMLElement($data);
                if ($output_format === self::GET_OUTPUTMODE_SIMPLEXML) {
                    $data = $doc_element;
                } else {
                    // Walk through the SimpleXMLElement to create array of
                    // arrays (items) of string values (fields). We assume each
                    // first-level XML element is a row containing fields
                    // without any further nested tags.
                    $data = [];

                    // The default for the SOAP calls is to _not_ return empty
                    // field values (which is different from REST calls).
                    if ($include_empty_fields) {
                        // The XML may contain empty tags. These are empty
                        // SimpleXMLElements which we convert to empty strings.
                        foreach ($doc_element as $row_element) {
                            $data[] = array_map('strval', (array)$row_element);
                        }
                    } else {
                        // All fields inside an 'item' are strings; we just
                        // need to convert the item (SimpleXMLElement) itself.
                        foreach ($doc_element as $row_element) {
                            $data[] = (array)$row_element;
                        }
                    }
                }
            } else {
                $data = json_decode($data, true);
                // @todo check the data structure for all non-"GET" types; we
                //   may want to do the below for all of them (i.e. get rid of
                //   the whole 'switch' statement, if indeed all types output the same structure.)
                if (!$include_metadata) {
                    switch ($data_type) {
                        case self::DATA_TYPE_GET:
                        case self::GET_FILTER_OR:
                            $data = $data['rows'];
                            break;
                    }
                }
            }
        }

        return $data;
    }

    /**
     * Parses the arguments to getData() for REST clients.
     *
     * This is a separate function so a subclass can somewhat more easily change
     * call arguments; not because it's expected to be called from more than one
     * place.
     *
     * @throws \InvalidArgumentException
     *   If the input arguments have an unrecognized structure.
     *
     * @see getData()
     */
    protected static function parseGetDataRestArguments($data_id, array $filters = [], $data_type = self::DATA_TYPE_GET, array $extra_arguments = [])
    {
        $http_verb = 'GET';
        $function = '';
        // $function (the URL) will not be escaped by callAfas() so we should do
        // that here, unlike with the values in $extra_arguments.
        switch ($data_type) {
            // GET_FILTER_AND equals DATA_TYPE_GET.
            case self::DATA_TYPE_GET:
            case self::GET_FILTER_OR:
                $function = 'connectors/' . rawurlencode($data_id);
                if (!empty($filters)) {
                    // We don't check whether the 3 arguments that make up a
                    // filter are present in $extra_arguments. If they are, they
                    // get overwritten silently. If not, they get used.
                    $extra_arguments = array_merge($extra_arguments, static::parseRestFilters($filters, $data_type === self::GET_FILTER_OR));
                }
                $data_type = self::DATA_TYPE_GET;
                break;

            case self::DATA_TYPE_REPORT:
                $function = 'reportconnector/' . rawurlencode($data_id);
                break;

            case self::DATA_TYPE_SUBJECT:
                $function = 'subjectconnector/' . rawurlencode($data_id);
                break;

            case self::DATA_TYPE_METAINFO_UPDATE:
                // All metainfo can be retrieved by specifying empty  $data_id.
                $function = 'metainfo' . (empty($data_id) ? '' : '/update/' . rawurlencode($data_id));
                break;

            case self::DATA_TYPE_METAINFO_GET:
                // Empty $data_id yields exactly the same as for METAINFO_UPDATE
                $function = 'metainfo' . (empty($data_id) ? '' : '/get/' . rawurlencode($data_id));
                break;

            case self::DATA_TYPE_VERSION_INFO:
                $function = "profitversion";
        }
        if (!$function) {
            throw new InvalidArgumentException('Unknown data_type value: ' . json_encode($data_type), 32);
        }

        return [$http_verb, $function, $extra_arguments];
    }

    /**
     * Parses the arguments to getData() for SOAP clients.
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
    protected static function parseGetDataArguments($data_id, array $filters = [], $data_type = self::DATA_TYPE_GET, array $extra_arguments = [])
    {
        // $data_id and arguments are going to be encoded in the SOAP message,
        // so on that level there are no security issues. But we have no idea
        // what AFAS itself will do with badly formed IDs... (Experience
        // suggests: probably give an unhelpful general error message.) So let's
        // assume that all arguments/options are expected to be ASCII only, and
        // encode them in some known format. We could take anything but let's
        // do XML because it's easy...
        $data_id = static::xmlValue($data_id);
        $function = '';
        switch ($data_type) {
            case self::DATA_TYPE_GET:
                // Throw an exception if any REST specific arguments are present
                // which are not supported by SOAP, because this can have bad
                // consequences if it goes unseen. (If someone wants to
                // explicitly ignore this, we should introduce a setter for it.)
                $args = array_map('strtolower', array_keys($extra_arguments));
                if (in_array('orderbyfieldids', $args, TRUE)) {
                  throw new InvalidArgumentException("Argument 'orderbyfieldids' is only supported by REST clients.", 38);
                }

                // We don't support the 'GetData' function. It seems to not have
                // any advantages.
                $function = 'GetDataWithOptions';
                $extra_arguments['connectorid'] = $data_id;
                if (!empty($filters)) {
                    $extra_arguments['filtersxml'] = static::parseFilters($filters);
                }
                // Turn 'options' argument (which is always set) into XML.
                $options_str = '';
                foreach ($extra_arguments['options'] as $option => $value) {
                    // Case of the options does not seem to matter (unlike the
                    // direct arguments, which the Client will take care of),
                    // but do what seems customary in the docs: one capital.
                    $options_str .= '<' . ucfirst($option) . '>' . static::xmlValue($value) . '</' . ucfirst($option) . '>';
                }
                $extra_arguments['options'] = "<options>$options_str</options>";
                // For get connectors that are called through App Connectors,
                // there are 'skip/take' arguments, and we provide a default
                // 'take' because if it is not specified, the output will be
                // empty. (Further general validation is done in the Client
                // class, but the job of hard coding a capped value does not
                // belong in there.)
                if (!isset($extra_arguments['take'])) {
                    // 1000 happens to be the default/maximum number of records
                    // returned if 'take' was specified as an 'options' argument
                    // (which we disallow) and was > 1000. A lower value seems
                    // too big a compatibility break with pre-App Connectors. It
                    // is different from the default for REST clients though,
                    // which is 100. (And we don't raise the REST default to
                    // 1000 because we prefer keeping the behavior close to the
                    // AFAS REST API, rather than close to the SOAP client.)
                    $extra_arguments['take'] = 1000;
                }
                break;

            case self::DATA_TYPE_REPORT:
                $function = 'Execute';
                $extra_arguments['reportid'] = $data_id;
                if (!empty($filters)) {
                    $extra_arguments['filtersxml'] = static::parseFilters($filters);
                }
                break;

            case self::DATA_TYPE_SUBJECT:
                $function = 'GetAttachment';
                $extra_arguments['subjectid'] = $data_id;
                break;

            case self::DATA_TYPE_DATA:
                // Oct 2014: I finally saw the first example of a DataConnector
                // in the latest version of the online documentation, at
                // http://profitdownload.afas.nl/download/Connector-XML/DataConnector_SOAP.xml
                // (on: Connectors > Call a Connector > SOAP call > UpdateConnector,
                //  which is https://static-kb.afas.nl/datafiles/help/2_9_5/SE/EN/index.htm#App_Cnnctr_Call_SOAP_Update.htm)
                // Funny thing is: there is NO reference of "DataConnector" in
                // the documentation anymore!
                // dataID is apparently hardcoded (as far as we know there is no
                // other function for the so-called 'DataConnector' than getting
                // XML schema)
                $function = 'Execute';
                $extra_arguments['dataid'] = 'GetXmlSchema';
                $extra_arguments['parametersxml'] = "<DataConnector><UpdateConnectorId>$data_id</UpdateConnectorId><EncodeBase64>false</EncodeBase64></DataConnector>";
                break;

            case self::DATA_TYPE_TOKEN:
                $function = 'GenerateOTP';
                // apiKey & environmentKey are required. AFAS is not famous for
                // its understandable error messages (e.g. if one of them is
                // missing then we get the notorious "Er is een onverwachte fout
                // opgetreden") so we take over that task and throw exceptions.
                if (empty($extra_arguments['apikey']) || empty($extra_arguments['environmentkey'])) {
                    throw new InvalidArgumentException("Required extra arguments 'apiKey' and 'environmentKey' not both provided.", 34);
                }
                if (!is_string($extra_arguments['apikey']) || strlen($extra_arguments['apikey']) != 32
                    || !is_string($extra_arguments['environmentkey']) || strlen($extra_arguments['environmentkey']) != 32
                ) {
                    throw new InvalidArgumentException("Extra arguments 'apiKey' and 'environmentKey' should both be 32-character strings.", 34);
                }
                $extra_arguments['userid'] = $data_id;
                break;

            case self::DATA_TYPE_VERSION_INFO:
                $function = 'GetProductVersion';
        }
        if (!$function) {
            throw new InvalidArgumentException('Unknown data_type value: ' . json_encode($data_type), 32);
        }

        return [$data_type, $function, $extra_arguments];
    }

    /**
     * Constructs filter options, usable by AFAS REST call.
     *
     * @param array $filters
     *   Filters in our own custom format; see parseFilters().
     * @param bool $or
     *   True if individual field-value pairs should be joined with OR instead
     *   of AND.
     *
     * @return array
     *   The query arguments that make up a filter. (These are always query
     *   arguments; this class will generate URLS of the more general form
     *   connector/CONN_ID?filterfieldids=FIELD1,FIELD2&filtervalues=VAL1,VAL2&operatortypes=1,1
     *   rather than connector/CONN_ID/FIELD1,FIELD2/VAL1,VAL2.)
     *
     * @throws \InvalidArgumentException
     *   If the input argument has an unrecognized structure.
     *
     * @see parseFilters()
     */
    protected static function parseRestFilters(array $filters, $or = false)
    {
        $arguments = $fields = $values = $operators = [];
        if ($filters) {
            $operator = !empty($filters['#op']) ? $filters['#op'] : self::OP_EQUAL;
            if (!is_numeric($operator) || $operator < 1 || $operator > 14) {
                throw new InvalidArgumentException('Unknown filter operator: ' . json_encode($operator), 33);
            }
            foreach ($filters as $outerfield => $filter) {
                if ($outerfield !== '#op') {

                    if (is_array($filter)) {
                        // Process all fields on an inner layer.
                        $op = !empty($filter['#op']) ? $filter['#op'] : self::OP_EQUAL;
                        if (!is_numeric($op) || $op < 1 || $op > 14) {
                            throw new InvalidArgumentException('Unknown filter operator: ' . json_encode($operator), 33);
                        }
                        foreach ($filter as $key => $value) {
                            if ($key !== '#op') {

                                if (is_array($value)) {
                                    throw new InvalidArgumentException('Filter has more than two array dimensions: ' . json_encode($filters), 33);
                                }
                                $fields[] = $key;
                                $values[] = $value;
                                $operators[] = $op;
                            }
                        }
                    } else {
                        // Process 1 field on the outer layer.
                        $fields[] = $outerfield;
                        $values[] = $filter;
                        $operators[] = $operator;
                    }
                }
            }
        }

        if ($fields) {
            // The glue is the same in all 3 arguments. Why? That's why.
            $glue = $or ? ';' : ',';
            $arguments = [
                'filterfieldids' => join($glue, $fields),
                'filtervalues' => join($glue, $values),
                'operatortypes' => join($glue, $operators),
            ];
        }

        return $arguments;
    }

    /**
     * Constructs a 'FiltersXML' argument, usable by AFAS SOAP call.
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
     *
     *   Mixing the formats up (that is: having array values in the _outer_
     *   array which are both scalars and arrays) is allowed. If the values are
     *   arrays, keys are ignored; if they are scalars, keys are the field
     *   values. The end result is the same, regardless whether a field-value
     *   pair is inside an inner array; the returned result is a one-dimensional
     *   list of field-value-operator combinations.
     *   If an (outer or inner) array only contains one single '#op' value, it's
     *   silently ignored.
     *   For the operator values, see the OP_* constants defined in this class.
     *
     * @return string
     *   The filters formatted as 'FiltersXML' argument. (If the input is empty
     *   or only contains an '#op',  then the XML will contain a few tags with
     *   no content; the effect of this is untested. It seems nothing will go
     *   wrong then, but it's better to just not call this with empty input.)
     *
     * @throws \InvalidArgumentException
     *   If the input argument has an unrecognized structure.
     */
    protected static function parseFilters(array $filters)
    {
        $filters_str = '';
        if ($filters) {
            $operator = !empty($filters['#op']) ? $filters['#op'] : self::OP_EQUAL;
            if (!is_numeric($operator) || $operator < 1 || $operator > 14) {
                throw new InvalidArgumentException('Unknown filter operator: ' . json_encode($operator), 33);
            }
            foreach ($filters as $outerfield => $filter) {
                if ($outerfield !== '#op') {

                    if (is_array($filter)) {
                        // Process all fields on an inner layer.
                        $op = !empty($filter['#op']) ? $filter['#op'] : self::OP_EQUAL;
                        if (!is_numeric($op) || $op < 1 || $op > 14) {
                            throw new InvalidArgumentException('Unknown filter operator: ' . json_encode($operator), 33);
                        }
                        foreach ($filter as $key => $value) {
                            if ($key !== '#op') {

                                if (is_array($value)) {
                                    throw new InvalidArgumentException('Filter has more than two array dimensions: ' . json_encode($filters), 33);
                                }
                                $filters_str .= '<Field FieldId="' . $key . '" OperatorType="' . $op . '">' . static::xmlValue($value) . '</Field>';
                            }
                        }
                    } else {
                        // Process 1 field on the outer layer.
                        $filters_str .= '<Field FieldId="' . $outerfield . '" OperatorType="' . $operator . '">' . static::xmlValue($filter) . '</Field>';
                    }
                }
            }
        }

        // There can be multiple 'Filter' tags with multiple FilterIds. We only
        // need to use one, it can contain all our filtered fields.
        return '<Filters><Filter FilterId="Filter1">' . $filters_str . '</Filter></Filters>';
    }

    /**
     * Prepare a value for inclusion in XML: trim and encode.
     *
     * @param string $text
     *
     * @return string
     */
    protected static function xmlValue($text)
    {
        // check_plain() / ENT_QUOTES converts single quotes to &#039; which is
        // illegal in XML so we can't use it for sanitizing.) The below is
        // equivalent to "htmlspecialchars($text, ENT_QUOTES | ENT_XML1)", but
        // also valid in PHP < 5.4.
        return str_replace("'", '&apos;', htmlspecialchars(trim($text)));
    }
}
