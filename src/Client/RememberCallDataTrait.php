<?php

/**
 * This file is part of the PracticalAfas package.
 *
 * (c) Roderik Muit <rm@wyz.biz>
 *
 * For the full copyright and license information, please view the LICENSE file
 * that was distributed with this source code.
 */

namespace PracticalAfas\Client;

/**
 * Implements methods that remember the last AFAS call's data.
 *
 * This can be useful for code that e.g. tries to handle errors and needs the
 * data of the last failing call. The client classes themselves are lightweight
 * on purpose and do not remember details that they don't need to, so this is
 * implemented in a separate trait. For using this, create a subclass of any
 * of the clients with the first line inside the class definition being:
 * use \PracticalAfas\Client\RememberCallDataTrait;
 */
trait RememberCallDataTrait
{
    /**
     * 'Type' of the last call endpoint/function. Values differ per client type.
     *
     * @var string
     */
    protected $lastCallType;

    /**
     * The REST endpoint / SOAP function that was last called.
     *
     * @var string
     */
    protected $lastCallEndpoint;

    /**
     * Arguments to the last call. (Except body for REST UpdateConnectors.)
     *
     * @var array
     */
    protected $lastCallArguments;

    /**
     * Timestamp when the last call was initiated.
     *
     * @var int
     */
    protected $lastCallTime;

  /**
     * Sets the 'Type' of the last call endpoint/function.
     *
     * @var string $type
     */
    protected function setLastCallType($type)
    {
        $this->lastCallType = $type;
    }

    /**
     * Sets the endpoint/function that was last called.
     *
     * @var string $endpoint
     */
    protected function setLastCallEndpoint($endpoint)
    {
        $this->lastCallEndpoint = $endpoint;
    }

    /**
     * Sets the arguments provided to the last call.
     *
     * @var array $arguments
     */
    protected function setLastCallArguments(array $arguments)
    {
        $this->lastCallArguments = $arguments;
    }

    /**
     * Sets the arguments provided to the last call.
     *
     * @var int $timestamp
     */
    protected function setLastCallTime($timestamp)
    {
        $this->lastCallTime = $timestamp;
    }

    /**
     * Returns the 'Type' of the last call endpoint/function.
     *
     * Values differ per client type. getLastCallConnectorType() returns a
     * possible alternative value which is the same across client types.
     *
     * @return string
     */
    public function getLastCallType()
    {
        return $this->lastCallType;
    }

    /**
     * Returns the endpoint/function that was last called.
     *
     * Values differ per client type. getLastCallConnectorName() returns a
     * possible alternative value which is the same across client types.
     *
     * @return string
     */
    public function getLastCallEndpoint()
    {
        return $this->lastCallEndpoint;
    }

    /**
     * Returns the endpoint/function that was last called.
     *
     * @return array
     */
    public function getLastCallArguments()
    {
        return $this->lastCallArguments;
    }

    /**
     * Returns the timestamp when the last call was initiated.
     *
     * @return int
     */
    public function getLastCallTime()
    {
        return $this->lastCallTime;
    }

    /**
     * Returns the connector type that was last called.
     *
     * Example return values: "get", "update", "report", "subject", "token",
     * "data", "versioninfo". ('Meta info' == 'XSD Schema' types return "data"
     * because AFAS calls or called this a Data Connector.)
     *
     * @return string
     *
     * @todo test. I really haven't done that yet.
     */
    public function getLastCallConnectorType()
    {
        if ($this->getClientType() === 'SOAP') {
            if (in_array(strtolower($this->lastCallType), ['update'], true)) {
                return strtolower($this->lastCallType);
            }
            switch (strtolower($this->lastCallEndpoint)) {
                case 'getdata':
                case 'getdatawithoptions':
                    return 'get';

                case 'getattachment':
                    return 'subject';

                case 'generateotp':
                    return 'token';

                case 'getproductversion':
                    return 'versioninfo';
            }
            // We're assuming endpoint 'Execute'.
            $args = array_change_key_case($this->lastCallArguments);
            if (!empty($args['reportid'])) {
                return 'report';
            }
            if (!empty($args['subjectid'])) {
                return 'subject';
            }
            if (!empty($args['dataid'])) {
                // The only possible value for a Data Connector is
                // 'GetXmlSchema' but we'll always return 'data' here.
                return 'data';
            }
            return '?';
        } else {
            // REST client.
            if (strtolower($this->lastCallType) !== 'get') {
                return 'update';
            }
            $endpoint = strtolower($this->lastCallEndpoint);
            $pos = strpos($endpoint, '/');
            if ($pos === false) {
                // The only endpoint we know here is 'profitversion'.
                return $endpoint === 'profitversion' ? 'versioninfo' : $endpoint;
            }
            $connector_type = substr($endpoint, 0, $pos);
            if ($connector_type === 'connectors') {
                return 'get';
            }
            if (substr($connector_type, -9) === 'connector') {
                // e.g. subject, report
                return strpos($connector_type, 0, strlen($connector_type) - 9);
            }
            // The only endpoint we know here is 'metainfo'.
            return $connector_type === 'metainfo' ? 'data' : $connector_type;
        }
    }

    /**
     * Returns the name of the connector that was last called.
     *
     * @return string
     *
     * @todo test. I really haven't done that yet.
     */
    public function getLastCallConnectorName()
    {
        if ($this->getClientType() === 'SOAP') {
            $args = array_change_key_case($this->getLastCallArguments());
            switch ($this->getLastCallConnectorType()) {
                case 'update':
                    return $args['connectortype'];

                case 'get':
                    // We may very well need to XML-decode this value but I'm
                    // not totally sure (though the connector-class encodes
                    // it). Let's just assume it contains no decodable chars.
                    return $args['connectorid'];

                case 'report':
                    return $args['reportid'];

                case 'subject':
                    return $args['subjectid'];

                case 'data':
                    if (substr($args['parametersxml'], 0, 34) !== '<DataConnector><UpdateConnectorId>') {
                        return '?';
                    }
                    $pos = strpos($args['parametersxml'], '<', 34);
                    if ($pos === false) {
                        return '?';
                    }
                    return htmlspecialchars_decode(substr($args['parametersxml'], 34, $pos - 34), ENT_QUOTES | ENT_XML1);

                default:
                  // 'token', 'versioninfo':
                    return '';
            }
        } else {
            // The connector name is always in the URL.
            $url_parts = explode('/', $this->getLastCallEndpoint());
            switch ($this->getLastCallConnectorType()) {
                case 'token':
                case 'versioninfo':
                    return '';

                case 'data':
                case 'get':
                    $i = 2;
                    break;

                default:
                    $i = 1;
            }
            return isset($url_parts[$i]) ? rawurldecode($url_parts[$i]) : '?';
        }
    }

    /**
     * Calls an AFAS endpoint.
     *
     * @param string $type
     *   HTTP verb / type of connector
     * @param string $endpoint
     *   The API endpoint URL / SOAP function to call.
     * @param array $arguments
     *   Named arguments.
     * @param string $request_body
     *   (Optional) request body to send for POST/PUT requests. This argument
     *   is not implemented for SOAP type clients.
     *
     * @return string
     *   The response from the API endpoint.
     *
     * @see RestCurlClient::callAfas()
     * @see SoapAppClient::callAfas()
     */
    public function callAfas($type, $endpoint, array $arguments, $request_body = '')
    {
        // The call has a different signature for REST/SOAP clients: the 4th
        // arguments only exists for REST clients. Luckily that doesn't
        // preclude us from using this in SOAP type connectors.
        $this->setLastCallType($type);
        $this->setLastCallEndpoint($endpoint);
        $this->setLastCallArguments($arguments);
        $this->setLastCallTime(time());
        return parent::callAfas($type, $endpoint, $arguments, $request_body);
    }
}
