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
use PracticalAfas\UpdateConnector\UpdateObject;
use RuntimeException;
use /** @noinspection PhpComposerExtensionStubsInspection */
    SimpleXMLElement;
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
    const OP_NOT_ENDS_WITH = 14;
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
     * The PracticalAfas client we use to execute actual AFAS calls.
     *
     * @var \PracticalAfas\Client\RestCurlClient|object
     */
    protected $client;

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
     *
     * @throws \RuntimeException
     *   If the object is not recognized as a PracticalAfas client.
     */
    public function __construct($client)
    {
        $this->setClient($client);
    }

    /**
     * Returns the PracticalAfas client object.
     *
     * @return \PracticalAfas\Client\RestCurlClient|object
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
     * The object type for a client is not strictly defined. (It does not have
     * an interface.) This is on purpose; as long as the 'interface' is as
     * simple as it is (i.e. two methods callAfas() and getClientType()) and
     * testing is not impaired, we can enable anyone to use those client
     * classes standalone in their PHP experiments, without even using an
     * autoloader.
     *
     * @param object $client
     *   An AFAS client object.
     *
     * @throws \InvalidArgumentException
     *   If the object is not recognized as a PracticalAfas client.
     * @throws \RuntimeException
     *   If the system is not capable of executing AFAS calls with this client.
     */
    public function setClient($client)
    {
        if (!is_callable([$client, 'callAfas']) || !is_callable([$client, 'getClientType'])) {
            throw new InvalidArgumentException('Object is not a PracticalAfas client class.', 2);
        }
        $this->client = $client;

        $type = $this->getClientType();
        if (!in_array($type, ['REST', 'SOAP'], true)) {
            throw new InvalidArgumentException("Unrecognized client type $type.", 1);
        }

        $required_extensions = $type === 'SOAP'
            ? ['openssl', 'simplexml', 'soap'] : ['curl', 'json', 'openssl'];
        $missing_extensions = [];
        foreach ($required_extensions as $extension) {
            if (!extension_loaded($extension)) {
                $missing_extensions[] = $extension;
            }
        }
        if ($missing_extensions) {
            throw new RuntimeException('The following PHP extension(s) must be installed to work with the AFAS client: ' . implode(',', $missing_extensions) . '.');
        }
    }

    /**
     * Returns the 'type' of client set for this connection: "REST" or "SOAP".
     *
     * This is not exactly necessary to exist in the Connection class, but
     * getting it from the client type is not a one liner / may be confusing
     * for some people, since it's a static method.
     *
     * @return string
     */
    public function getClientType()
    {
        $client =  $this->client;
        // We shouldn't need the strtoupper() but just for security's sake...
        return strtoupper($client::getClientType());
    }

    /**
     * Indicates the data format accepted / returned by this client's endpoint.
     *
     * @return string
     */
    public function getDataFormat()
    {
        return $this->getClientType() === 'SOAP' ? 'xml' : 'json';
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
     * @see Connection::setDataOutputFormat()
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
            $ret = $this->getClientType() !== 'SOAP' && $this->getDataOutputFormat() == self::GET_OUTPUTMODE_LITERAL;
        }
        return $ret;
    }

    /**
     * Sets indicator to return metadata by some getData() calls.
     *
     * @param bool $include
     *
     * @see Connection::getDataIncludeMetadata()
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
        return isset($this->includeEmptyFields) ? $this->includeEmptyFields : $this->getClientType() !== 'SOAP';
    }

    /**
     * Sets indicator to return empty fields in getData() return value.
     *
     * False is only valid for SOAP clients, so far.
     *
     * @param bool $include
     *
     * @see Connection::getDataIncludeEmptyFields()
     */
    public function setDataIncludeEmptyFields($include)
    {
        $this->includeEmptyFields = $include;
    }

    /**
     * Sends data to an AFAS Update Connector.
     *
     * The first and second parameter could also be switched, but that usage
     * is deprecated. (Note that the connector name is the first argument in
     * getData(), and the second argument in sendData().)
     *
     * @param string|array|\PracticalAfas\UpdateConnector\UpdateObject $data
     *   The data to send in to the Update Connector; can be:
     *   - a string, which will be sent into the connector as-is. Must be JSON
     *     or XML depending on the client type.
     *   - an UpdateObject instance containing the data to send.
     *   - an array, which will be passed into an UpdateObject to generate
     *     the data to send.
     * @param string $connector_name
     *   (Optional) The name of the Update Connector. It is required if the
     *   data is an array or a string.
     * @param string $action
     *   (Optional) The action to take on the data: "insert", "update" or
     *   "delete". It is required if the data is an array, or if it's a string
     *   and we are connecting to the REST API.
     *
     * @return string
     *   Response from SOAP call. Most successful calls return empty string.
     *   (If a call is not successful, it is expected to throw an exception;
     *   the details depend on the client.)
     *
     * @throws \InvalidArgumentException
     *   If any of the arguments are invalid (which includes invalid keys/
     *   values in the array).
     */
    public function sendData($data, $connector_name = '', $action = '')
    {
        // If $data is a simple string and $connector_name is not, then
        // they are switched. We support this for backward compatibility;
        // it was defined that way in v2.0.
        if (
            is_string($data) && (!is_string($connector_name) || !preg_match('/^\w+$/', $connector_name))
            && preg_match('/^\w+$/', $data)
        ) {
            $temp = $connector_name;
            $connector_name = $data;
            $data = $temp;
        }

        $action = strtolower($action);
        // We accept REST verbs POST/PUT/DELETE too, since this was the
        // argument in v1 of this method. Implicitly convert by array_search.
        $rest_verbs = ['update' => 'PUT', 'insert' => 'POST', 'delete' => 'DELETE'];
        if ($action && !isset($rest_verbs[$action]) && !($action = array_search(strtoupper($action), $rest_verbs, true))) {
            throw new InvalidArgumentException('Invalid action ' . var_export($action, true) . '.');
        }

        if ($data instanceof UpdateObject && $connector_name !== $data->getType()) {
            throw new InvalidArgumentException("Provided connector name argument ($connector_name) differs from the type of UpdateObject ({$data->getType()}).");
        } elseif (is_array($data)) {
            // We'll require the action also for SOAP/XML. (The UpdateObject
            // in practice will treat an empty string the same as "update" but
            // it wants you to specify a nonempty string.)
            if (!$action) {
                throw new InvalidArgumentException('Action must be specified.');
            }
            $data = UpdateObject::create($connector_name, $data, $action);
        }
        if (!is_string($data) && !($data instanceof UpdateObject)) {
            throw new InvalidArgumentException('Data is of invalid type.');
        }

        if ($this->getClientType() === 'SOAP') {
            // $action is ignored, except (earlier) if an array was passed. We
            // should not check it against the UpdateObject because in theory
            // multiple actions can be defined for multiple elements present in
            // the UpdateObject.
            if (!is_string($data)) {
                $data = $data->output($this->getDataFormat());
            }
            $response = $this->client->callAfas('update', 'Execute', ['connectorType' => $connector_name, 'dataXml' => $data]);
        } else {
            if (is_string($data)) {
                if (!$action) {
                    throw new InvalidArgumentException('Action argument is required (insert, update or delete).');
                }
            } else {
                // If we passed an UpdateObject into here, check it against the
                // action argument. We do not support elements with different
                // actions being sent over REST, because the action is tied to
                // the verb - so if getAction() throws an exception, we want
                // that to happen.
                try {
                    $temp = $data->getAction();
                } catch (UnexpectedValueException $e) {
                    throw new InvalidArgumentException('Data argument is an UpdateObject with several different actions set. This is not supported by REST clients.');
                }
                if ($action && $temp !== $action) {
                    // We could just ignore $action but this seems like a
                    // potentially dangerous mistake.
                    throw new InvalidArgumentException("Provided action argument ($action) differs from action specified in the UpdateObject ($temp).");
                }
                $data = $data->output($this->getDataFormat());
            }
            $response = $this->client->callAfas($rest_verbs[$action], 'connectors/' . rawurlencode($connector_name), [], $data);
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
     *   DATA_TYPE_GET (default),
     *   DATA_TYPE_METAINFO_GET          : The name of a Get connector.
     *   DATA_TYPE_SCHEMA/METAINFO_UPDATE: The Update connector for which to
     *                                     retrieve the XSD schema.
     *   DATA_TYPE_REPORT                : Report ID for a Report connector.
     *   DATA_TYPE_SUBJECT/ATTACHMENT    : ID (int) for Subject connector.
     *   DATA_TYPE_TOKEN                 : The user ID to request the token for.
     *   DATA_TYPE_VERSION_INFO          : This argument is ignored.
     * @param array $filters
     *   (optional) Filters in our own custom format; one (or a combination) of:
     *   1) [ FIELD1 => VALUE1, FIELD2 => VALUE2, ...,  '#op' => operator ],
     *   2) [
     *        [ FIELD1 => VALUE1, ..., '#op' => operator1 ],
     *        [ FIELD2 => VALUE2, FIELD3 => VALUE3, ..., '#op' => operator2 ],
     *      ]
     *   FIELD2/VALUE2 and '#op' are optional; '#op' defaults to
     *   Connection::OP_EQUAL. Both formats can represent one or more filters
     *   on several fields (and formats can be mixed up); the second format
     *   enables filtering on different operators. (For REST clients, all
     *   operations can be either AND'ed or OR'ed together; this depends on the
     *   $data_type parameter. For SOAP clients, only AND works.)
     *   For the operator values, see the OP_* constants defined in this class.
     * @param string $data_type
     *   (optional) Type of data to retrieve / connector to call, and/or filter
     *   type. Defaults to GET_FILTER_AND / DATA_TYPE_GET (which is the same).
     *   Use GET_FILTER_OR to apply 'OR' to $filters instead of 'AND', for a
     *   GetConnector (REST only). Use other DATA_TYPE_ constants (see just
     *   above) to call other connectors.
     * @param array $extra_arguments
     *   (optional) Other arguments to pass to the API call, besides the ones
     *   in $filters / hardcoded for convenience. For GetConnectors these are:
     *   - 'Skip' and 'Take': at least one of these needs to be passed if the
     *     data set is not known to be small (see return value docs): pass
     *     'Skip' = -1 to return the full data set (at the risk of timeouts)
     *     or pass the maximum number of rows to return in 'Take'.
     *   - 'OrderByFieldIds', to apply sorting, Syntax is
     *     "Fieldname1,-Fieldname2,..." (hyphen for descending order). This also
     *     works with SOAP clients. (It will be converted to an 'Index' option.)
     *   - 'options'. These will not be sent to REST API calls but will still be
     *     interpreted, for compatibility with SOAP. Supported options are
     *     'Outputmode, 'Metadata' and 'Outputoptions' whose valid values are
     *     GET_* constants defined in this class and which can also be
     *     influenced permanently instead of per getData() call; see setter
     *     methods setDataOutputFormat(), setDataIncludeMetadata() and
     *     setDataIncludeEmptyFields() respectively. Other supported options are
     *     'Skip', 'Take' and 'Index', but it is recommended to not use these as
     *     options; set them at the root level of $extra_arguments instead.
     *     ('Index' only works with SOAP Clients and its syntax is an XML
     *     snippet; use 'OrderByFieldIds' instead which is portable and has
     *     easier syntax.)
     *
     * @return mixed
     *   Output; format is dependent on data type and extra arguments. The
     *   default output format is a two-dimensional array of rows/columns of
     *   data from the GetConnector. The array structure is not exactly the
     *   same for SOAP vs. REST clients:
     *   - If the 'take' argument is not specified (and 'skip' is not -1), the
     *     REST API endpoint will return 100 rows maximum; this is 1000 for the
     *     SOAP API endpoint (for (backward compatibility; see comments at
     *     parseGetDataArguments()).
     *   - Empty fields will either not be returned by the SOAP API endpoint or
     *     (if specified through 'Outputoptions'/ setDataIncludeEmptyFields())
     *     contain an empty string. Empty fields returned by the REST API
     *     endpoint will contain null.
     *
     * @throws \InvalidArgumentException
     *   If input arguments have an illegal value / unrecognized structure.
     * @throws \UnexpectedValueException
     *   If the SoapClient returned a response in an unknown format.
     *
     * @see Connection::parseFilters()
     */
    public function getData($data_id, array $filters = [], $data_type = self::DATA_TYPE_GET, array $extra_arguments = [])
    {
        // We're going to let AFAS report an error for wrong/empty scalar values
        // of data_id (e.g. connector), but at least throw here for any
        // non-scalar.
        if ((!is_string($data_id) || $data_id === '') && !is_int($data_id) && $data_type !== self::DATA_TYPE_VERSION_INFO) {
            if (!is_scalar($data_id)) {
                $data_id = is_array($data_id) ? '[object]' : '[array]';
            }
            throw new InvalidArgumentException("Invalid 'data_id' argument: " . var_export($data_id, true), 32);
        }
        // Just in case the user specified something other than a constant:
        if (!is_string($data_type)) {
            if (!is_scalar($data_type)) {
                $data_type = is_array($data_type) ? '[object]' : '[array]';
            }
            throw new InvalidArgumentException("Invalid 'data_type' argument: " . var_export($data_type, true), 32);
        }
        $data_type = strtolower($data_type);
        // Unify case of arguments, so we don't skip any validation. (If two
        // arguments with different case are in the array, the value that is
        // later in the array will override other indices.)
        $extra_arguments = array_change_key_case($extra_arguments);
        if (isset($extra_arguments['options']) && is_array($extra_arguments['options'])) {
            $extra_arguments['options'] = array_change_key_case($extra_arguments['options']);
        }

        if ($this->getClientType() === 'SOAP') {
            if ($data_type === self::GET_FILTER_OR) {
                throw new InvalidArgumentException("SOAP clients do not support 'OR' filters.", 32);
            }
            if ($data_type === self::DATA_TYPE_METAINFO_GET) {
                throw new InvalidArgumentException("SOAP clients do not support getting meta info / schema for Get Connectors.", 32);
            }
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
        // array?
        if ($this->getClientType() === 'SOAP' && $data_type !== self::DATA_TYPE_GET) {
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
            } elseif ($this->getClientType() === 'SOAP') {
                $supported = in_array($output_format, [self::GET_OUTPUTMODE_ARRAY, self::GET_OUTPUTMODE_SIMPLEXML], true);
            } else {
                $supported = $output_format === self::GET_OUTPUTMODE_ARRAY;
            }
            if (!$supported) {
                if (!is_scalar($output_format)) {
                    $output_format = is_array($output_format) ? '[object]' : '[array]';
                }
                throw new InvalidArgumentException("AfasSoapConnection::getData() cannot handle handle output mode $output_format.", 30);
            }
        }

        // 'Metadata' only makes sense for SOAP GetConnectors (where it
        // influences API return value) but may make sense for all REST API
        // calls (because we process the return value ourselves).
        if ($this->getClientType() !== 'SOAP' || $data_type === self::DATA_TYPE_GET) {
            if (isset($extra_arguments['options']['metadata'])) {
                $include_metadata = !empty($extra_arguments['options']['metadata']);
            } elseif ($this->getClientType() !== 'SOAP' && $output_format == self::GET_OUTPUTMODE_LITERAL) {
                // If the output format comes from $extra_arguments['options']['outputmode'],
                // this overrides the value to true even though
                // $this->getDataIncludeMetadata() will return false (because it
                // cannot see the actual output format).
                $include_metadata = true;
            } else {
                $include_metadata = $this->getDataIncludeMetadata();
            }
            if (!$include_metadata && $this->getClientType() !== 'SOAP' && $output_format !== self::GET_OUTPUTMODE_ARRAY) {
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
                throw new InvalidArgumentException("REST API '$data_type' connector is not tested yet. Please call it with 'Metadata' option set to true, or 'Outputformat' set to LITERAL, and/or send a Pull Request for the library including the structure of the output.");
            }
        }

        // 'Outputoptions' governs whether empty fields are present in output;
        // this really only makes sense for GetConnectors.
        if ($data_type === self::DATA_TYPE_GET || $data_type === self::GET_FILTER_OR) {
            $include_empty_fields = isset($extra_arguments['options']['outputoptions']) ?
                $extra_arguments['options']['outputoptions'] == self::GET_OUTPUTOPTIONS_XML_INCLUDE_EMPTY
                : $this->getDataIncludeEmptyFields();
            if (!$include_empty_fields && $this->getClientType() !== 'SOAP') {
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
        // a REST API) and this class did not support them before v1.2. They are
        // - 'Index', which is equivalent to REST's 'orderbyfieldids' except the
        //   value is XML. (We are now supporting this for SOAP but _not_ for
        //   REST clients. for writing portable getData() calls, use
        //   'orderbyfieldids' instead, which we now support for SOAP clients
        //   too, by converting it to a proper 'Index' argument.)
        if ($this->getClientType() !== 'SOAP' && !empty($extra_arguments['options']['index'])) {
            // We won't continue silently, because that might mean the caller
            // gets too much data returned without realizing it.
            throw new InvalidArgumentException("Non-SOAP clients do not support 'Index' option. Use the 'OrderByFieldIds' argument as an alternative that is portable from REST to SOAP.", 32);
        }
        // - 'Take' and 'Skip'. That is right: the AFAS SOAP service recognizes
        //   these arguments as standalone arguments _and_ as 'options'
        //   arguments. They differ in behavior and the regular argument has
        //   stricter validation / does not auto-truncate. (For test results:
        //   see SoapAppclient::validateArguments().)
        foreach (['take', 'skip'] as $arg) {
            // We will accept both arguments as input (for REST clients too; why
            // not...) but warn if they are duplicate, because we don't want to
            // rely on the undocumented behavior of which one gets preference...
            if (
                isset($extra_arguments[$arg]) && isset($extra_arguments['options'][$arg])
                // Non-numeric arguments will fail later anyway; this slightly
                // strange comparison makes sure '0' is not replaced by '' just
                // below.
                && ($extra_arguments[$arg] != $extra_arguments['options'][$arg] || !is_numeric($extra_arguments['options'][$arg]))
            ) {
                throw new InvalidArgumentException("Duplicate '$arg' argument found, both as regular argument and inside 'options'. One should be deleted (preferrably the option).", 38);
            }
            // ... and we'll always move the option to the regular argument,
            // because that one has less implicit/irregular behavior.
            if (isset($extra_arguments['options'][$arg])) {
                $extra_arguments[$arg] = $extra_arguments['options'][$arg];
                unset($extra_arguments['options'][$arg]);
            }
        }

        // Validate some non-options arguments:
        // Make sure 'orderbyfieldids' is a string / noone passed an array by
        // accident because they got confused.
        if (isset($extra_arguments['orderbyfieldids']) && !is_string($extra_arguments['orderbyfieldids'])) {
            throw new InvalidArgumentException("Invalid 'orderbyfieldids' value; it should be a string.)", 39);
        }

        // We split up the parsing of further arguments into methods per client
        // type because it's so different.
        if ($this->getClientType() === 'SOAP') {
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
            list($type, $function, $arguments) = static::parseGetDataArguments($data_id, $filters, $data_type, $extra_arguments);
        } else {
            // If the 'options' argument holds an array, we've just preprocessed
            // those, to provide compatibility with a SOAP GetConnector. But we
            // don't want them to end up in the REST call.
            if (isset($extra_arguments['options']) && is_array($extra_arguments['options'])) {
                unset($extra_arguments['options']);
            }
            list($type, $function, $arguments) = static::parseGetDataRestArguments($data_id, $filters, $data_type, $extra_arguments);
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
            if ($this->getClientType() === 'SOAP') {
                /** @noinspection PhpComposerExtensionStubsInspection */
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
                /** @noinspection PhpComposerExtensionStubsInspection */
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
     * @see Connection::getData()
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
                // All metainfo can be retrieved by specifying empty $data_id.
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
            throw new InvalidArgumentException('Unknown data_type value: ' . var_export($data_type, true), 32);
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
     * @see Connection::getData()
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
                // We don't support the 'GetData' function. It seems to not have
                // any advantages.
                $function = 'GetDataWithOptions';
                $extra_arguments['connectorid'] = $data_id;
                if (!empty($filters)) {
                    $extra_arguments['filtersxml'] = static::parseFilters($filters);
                }

                // For ordering, we recommend using the OrderbyFieldIds argument
                // which is a bit counter intuitive because we usually port the
                // SOAP options to REST instead of the other way around. Reason:
                // - We didn't even know that the 'Index' option existed, before
                //   library v1.2 - and already supported 'OrderbyFieldIds'
                //   (only for REST). So we need to keep supporting that.
                // - The format of 'Index' is an XML snippet. We'll support that
                //   (like we silently support the 'FilterXml' arg in the case
                //   that no filters are specified) but we want to be able to
                //   convert from a 'portable' argument to that XML. And
                //   'OrderbyFieldIds' is just what we need.
                if (isset($extra_arguments['options']['index']) && isset($extra_arguments['orderbyfieldids'])) {
                    throw new InvalidArgumentException("Both 'Index' option and 'OrderbyFieldIds' argument are specified. One should be deleted. (Hint: OrderbyFieldIds is simpler and portable.)", 38);
                }
                if (isset($extra_arguments['orderbyfieldids'])) {
                    $extra_arguments['options']['index'] = '';
                    $order = array_map('trim', explode(',', $extra_arguments['orderbyfieldids']));
                    foreach ($order as $value) {
                        // Ascending = 1, descending = 0
                        $asc = 1;
                        if (substr($value, 0, 1) === '-') {
                            $asc = 0;
                            $value = substr($value, 1);
                        }
                        $extra_arguments['options']['index'] .= '<Field FieldId="' . static::xmlValue($value) . '" OperatorType="' . $asc . '"/>';
                    }
                    unset($extra_arguments['orderbyfieldids']);
                } elseif (
                    isset($extra_arguments['options']['index'])
                    // Assume the 'index' option is 'old style', an XML snippet.
                    // We don't escape it later on (because it's part of the
                    // XML message) but then we need to validate it.
                    && (!preg_match(
                        '/^\s*
                              (?: <Field\s+     # match 1 or more <Field tags
                                (?:             # containing 1 or more FieldId/OperatorType values
                                  (?: FieldId|OperatorType) \s* = \s* "[^"]+" \s*
                                )+
                              \/ \s* > \s*  )+
                            $/ix',
                        $extra_arguments['options']['index']
                    )
                        // Above does not ensure >0 FieldId values, so:
                        || stripos($extra_arguments['options']['index'], 'FieldId') === false)
                ) {
                    throw new InvalidArgumentException("Invalid 'Index' value.)", 39);
                }
                // Turn 'options' argument (which is always set) into XML.
                $options_str = '';
                foreach ($extra_arguments['options'] as $option => $value) {
                    // Case of the options does not seem to matter (unlike the
                    // direct arguments, which the Client will take care of),
                    // but do what seems customary in the docs: one capital.
                    $options_str .= '<' . ucfirst($option) . '>'
                        . ($option === 'index' ? $value : static::xmlValue($value))
                        . '</' . ucfirst($option) . '>';
                }
                $extra_arguments['options'] = "<options>$options_str</options>";
                // We provide a default 'take' value because if it is not
                // specified, the output will be empty. (Further general
                // validation is done in the Client class, but it throws an
                // exception if no value is specified. We don't want that here,
                // so we stay at least a bit in sync with the REST endpoint
                // which does return rows when no 'take' is specified.)
                if (!isset($extra_arguments['take'])) {
                    // 1000 happens to be the default/maximum number of rows
                    // returned if 'take' was specified as an 'options' argument
                    // (which we disallow) and was > 1000. A lower value seems
                    // too big a compatibility break with pre-App Connectors
                    // (i.e. the SOAP endpoint before 2017). It is different
                    // from the default for REST clients though, which is 100.
                    // (And we don't raise the REST default to 1000 because we
                    // prefer keeping the behavior close to the default REST
                    // endpoint behavior, rather than close to the SOAP client.)
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
                if (
                    !is_string($extra_arguments['apikey']) || strlen($extra_arguments['apikey']) != 32
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
            throw new InvalidArgumentException('Unknown data_type value: ' . var_export($data_type, true), 32);
        }

        return [$data_type, $function, $extra_arguments];
    }

    // phpcs:disable Squiz.WhiteSpace.ControlStructureSpacing.SpacingAfterOpen
    /**
     * Constructs filter options, usable by AFAS REST call.
     *
     * @param array $filters
     *   Filters in our own custom format; see getData().
     * @param bool $or
     *   (optional) True if individual field-value pairs should be joined with
     *   OR instead of AND.
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
     * @see Connection::getData()
     */
    protected static function parseRestFilters(array $filters, $or = false)
    {
        $arguments = $fields = $values = $operators = [];
        if ($filters) {
            $operator = !empty($filters['#op']) ? $filters['#op'] : self::OP_EQUAL;
            if (!is_numeric($operator) || $operator < 1 || $operator > 14) {
                if (!is_scalar($operator)) {
                    $operator = is_array($operator) ? '[object]' : '[array]';
                }
                throw new InvalidArgumentException("Unknown filter operator: $operator.", 33);
            }
            // Mixing filter formats (that is: having array values in the outer
            // array which are both scalars and arrays) is allowed. If the
            // values are arrays, keys are ignored; if they are scalars, keys
            // are the field values. The end result is the same, regardless
            // whether a field-value pair is inside an inner array. If an
            // (outer or inner) array only contains one single '#op' value,
            // it's  silently ignored.
            foreach ($filters as $outerfield => $filter) {
                if ($outerfield !== '#op') {

                    if (is_array($filter)) {
                        // Process all fields on an inner layer.
                        $op = !empty($filter['#op']) ? $filter['#op'] : self::OP_EQUAL;
                        if (!is_numeric($op) || $op < 1 || $op > 14) {
                            if (!is_scalar($op)) {
                                $op = is_array($op) ? '[object]' : '[array]';
                            }
                            throw new InvalidArgumentException("Unknown filter operator: $op (for key: $outerfield).", 33);
                        }
                        foreach ($filter as $key => $value) {
                            if ($key !== '#op') {

                                if (is_array($value)) {
                                    throw new InvalidArgumentException("Filter has more than two array dimensions (for key: $outerfield; field: $key).", 33);
                                }
                                if (!is_scalar($value)) {
                                    throw new InvalidArgumentException("Filter contains a non-scalar value (for key: $outerfield; field: $key).", 33);
                                }
                                $fields[] = $key;
                                $values[] = $value;
                                $operators[] = $op;
                            }
                        }
                    } elseif (!is_scalar($filter)) {
                        throw new InvalidArgumentException("Filter contains a non-scalar value (for field: $outerfield).", 33);
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
                'filterfieldids' => implode($glue, $fields),
                'filtervalues' => implode($glue, $values),
                'operatortypes' => implode($glue, $operators),
            ];
        }

        return $arguments;
    }
    // phpcs:enable

    /**
     * Constructs a 'FiltersXML' argument, usable by AFAS SOAP call.
     *
     * @param array $filters
     *   Filters in our own custom format; see getData().
     * @return string
     *   The filters formatted as 'FiltersXML' argument. (If the input is empty
     *   or only contains an '#op', then the XML will contain a few tags with
     *   no content; the effect of this is untested. It seems nothing will go
     *   wrong then, but it's better to just not call this with empty input.)
     *
     * @throws \InvalidArgumentException
     *   If the input argument has an unrecognized structure.
     *
     * @see Connection::getData()
     */
    protected static function parseFilters(array $filters)
    {
        $filters_str = '';
        // Prevent code duplication. That means we have to explode the
        // just-imploded strings again, but we can live with that. (An
        // alternative is to provide an extra internal-type argument...)
        $args = static::parseRestFilters($filters);
        if ($args) {
            // We know all 3 will yield equally long arrays.
            $fields = explode(',', $args['filterfieldids']);
            $values = explode(',', $args['filtervalues']);
            $operators = explode(',', $args['operatortypes']);
            foreach ($fields as $key => $field) {
                $filters_str .= '<Field FieldId="' . $field . '" OperatorType="' . $operators[$key] . '">'
                    . static::xmlValue($values[$key]) . '</Field>';
            }
        }
        // There can be multiple 'Filter' tags with multiple FilterIds. We only
        // need to use one, it can contain all our filtered fields.
        return '<Filters><Filter FilterId="Filter1">' . $filters_str . '</Filter></Filters>';
    }

    /**
     * Encode a value for inclusion in XML.
     *
     * @param string $text
     *
     * @return string
     */
    protected static function xmlValue($text)
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_XML1);
    }
}
