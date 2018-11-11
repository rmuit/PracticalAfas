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

use InvalidArgumentException;
use SoapParam;
use SoapVar;
use RuntimeException;
use UnexpectedValueException;

/**
 * Wrapper around client specific details of making a remote AFAS call.
 *
 * This class contains one public method: callAfas(), and uses
 * - the SOAP library bundled with PHP5;
 * - An 'app connector' for authentication.
 */
class SoapAppClient
{
    /**
     * Configuration options.
     *
     * @var array
     */
    protected $options;

    /**
     * Options to pass into the SOAP client.
     *
     * @var array
     */
    protected $soapClientOptions;

    /**
     * The SOAP client.
     *
     * @var \SoapClient
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
     *   Configuration options used by this class.
     *   Required:
     *   - customerId:      Customer ID, as used in the AFAS endpoint URL.
     *   - appToken:        Token used for the App connector.
     *   Optional:
     *   - environment:     Which AFAS environment to connect to. Can be 'test'
     *                      or 'accept'; if not specified, the client connects
     *                      to the live environment.
     *   - soapClientClass: classname for the actual Soap client to use. Should
     *     be compatible with PHP's SoapClient.
     *   - useWSDL:         boolean. (Suggestion: don't set it.)
     *   - cacheWSDL:       How long the WSDL should be cached locally in
     *     seconds. Other options (which are usually not camelCased but
     *     under_scored) are specific to the actual Soap client.
     * @param array $soap_client_options
     *   (Optional) Configuration options for the SOAP client class.
     * @throws \InvalidArgumentException
     *   If some option values are missing / incorrect.
     * @throws \Exception
     *   If something else went wrong / option values are unsupported.
     */
    public function __construct(array $options, $soap_client_options = [])
    {
        foreach (['customerId', 'appToken'] as $required_key) {
            if (empty($options[$required_key])) {
                $classname = get_class($this);
                throw new InvalidArgumentException("Required configuration parameter for $classname missing: $required_key.", 1);
            }
        }

        $options += [
            'soapClientClass' => '\SoapClient',
        ];
        // Add defaults for the SOAP client. (Some more defaults which are only
        // set if the client does not use WSDL, are in getSoapClient().)
        $soap_client_options += [
            'encoding' => 'utf-8',
        ];
        // From ~november 2018, AFAS has a new endpoint that forces TLS 1.2 as
        // a minimum. We know how to force a specific TLS version but
        // apparently cannot specify '1.2 or higher'. If people want TLS 1.3 or
        // higher, they will have to pass their own stream_context option.
        if ($options['soapClientClass'] === '\SoapClient'
            && !isset($soap_client_options['stream_context'])) {
            if (!defined('CURL_SSLVERSION_TLSv1_2')) {
                throw new RuntimeException("PHP's OpenSSL extension does not support TLS v1.2, which AFAS requires.");
            }
            $soap_client_options['stream_context'] = stream_context_create(
                ['ssl' => ['crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT]]
            );
        }

        $this->options = $options;
        $this->soapClientOptions = $soap_client_options;
    }

    /**
     * Returns a SOAP client object, configured with options previously set.
     *
     * @param string $type
     *   Type of AFAS connector. (This determines the SOAP endpoint URL.)
     * @param string $endpoint
     *   (optional) The SOAP endpoint URL to use. (It's generally not necessary
     *   to set this because AFAS has a well defined structure for its endpoint
     *   URLs. If this somehow changes, it's possible to create a child class
     *   that overrides getSoapClient() to pass an endpoint to this parent.
     *
     * @return \SoapClient
     *   Initialized SoapClient object.
     *
     * @throws \Exception
     *   If we failed to construct a SoapClient class.
     */
    protected function getSoapClient($type, $endpoint = '')
    {
        if (!$endpoint) {
            if ($type === 'token') {
                $connector_path = strtolower($type) . 'connector';
            } else {
                $connector_path = 'appconnector' . strtolower($type);
            }
            $env = !empty($this->options['environment']) ? $this->options['environment'] : '';
            $endpoint = 'https://' . $this->options['customerId'] . ".soap$env.afas.online/profitservices/$connector_path.asmx";
        }

        if (!empty($this->soapClient)) {
            // We can reuse the SOAP client object if we have the same
            // connector type as last time.
            if ($type === $this->connectorType) {
                return $this->soapClient;
            }
            if (empty($this->options['useWSDL'])) {
                $this->connectorType = $type;
                $this->soapClient->__setLocation($endpoint);
                return $this->soapClient;
            }
            // If we use WSDL we have no way to change the location, because
            // every connector uses its own WSDL definition (and we have no way
            // to change the WSDL for an object). So we create a new object.
        }

        $soap_client_options = $this->soapClientOptions;
        $wsdl_endpoint = null;
        if (empty($this->options['useWSDL'])) {
            // 'location' cannot be overridden. This keeps things consistent
            // with the code just above here.
            $soap_client_options['location'] = $endpoint;
            // It would be remarkable if the following options needed to be
            // overridden (by passing them into the constructor) but in case
            // AFAS changes their endpoint for some reason... you can try...
            $soap_client_options += [
                'uri' => 'urn:Afas.Profit.Services',
                'style' => SOAP_DOCUMENT,
                'use' => SOAP_LITERAL,
            ];
        } else {
            $wsdl_endpoint = $endpoint . '?WSDL';
            if ($this->options['cacheWSDL']) {
                ini_set('soap.wsdl_cache_ttl', $this->options['cacheWSDL']);
            }
        }

        $this->soapClient = new $this->options['soapClientClass']($wsdl_endpoint, $soap_client_options);
        $this->connectorType = $type;

        return $this->soapClient;
    }

    /**
     * Validates / completes arguments for an AFAS SOAP function call.
     *
     * Split out from callAfas() for more convenient subclassing.
     *
     * This class is not meant to make decisions about any actual data sent.
     * (That kind of code would belong in Connection.) So while we can
     * _validate_ many arguments here, _setting_ them is discouraged; the
     * arguments set here would typically be for e.g. authentication rather than
     * data manipulation.
     *
     * @param array $arguments
     *   Arguments for function. All argument names must be lower case.
     * @param string $function
     *   SOAP function name to call.
     *
     * @return array
     *   The arguments, possibly changed.
     *
     * @throws \InvalidArgumentException
     *   For invalid function arguments.
     */
    protected function validateArguments($arguments, $function)
    {
        // To get a token, we don't need a token.
        if ($this->connectorType !== 'token') {
            $arguments['token'] = '<token><version>1</version><data>' . $this->options['appToken'] . '</data></token>';
        }
        if ($this->connectorType === 'get') {
            // There are two issues with the 'skip' / 'take' arguments:
            // - (For both getData and getDataWithOptions), the WSDL suggests
            //   they are both required, though testing says that it's perfectly
            //   OK to not specify 'skip'. However if 'take' is left out,
            //   nothing is returned, which suggests that it defaults to '0'
            //   (which returns no data).
            // - There are actually two arguments which work: 'take', and a
            //   'take' option inside the 'option' argument (which means it's
            //   encoded as "<take>N</take>"). Same for 'skip'. Testing shows
            //   different behavior for the two variations:
            //   - If the 'options' 'take' argument is -1, 1000 records are
            //     returned. Also if a value > 1000 is specified, the output is
            //     truncated silently to 1000 records. (If 0 is specified, no
            //     data is returned, just like the regular argument.)
            //   - If the regular 'take' argument is -1, a "Unexpected backend
            //     error" is returned. If a value > 1000 is specified, the
            //     specified number of records is returned.
            //   - If both are specified, the regular 'take' argument is used.
            if (empty($arguments['take']) && (empty($arguments['options']) || stripos($arguments['options'], '<take>') === false)) {
                // This class generally does not want to force any logic on the
                // specified arguments, but since the behavior of returning
                // nothing by default is confusing, we'll throw an exception if
                // this is about to happen (which we do here, not in Connection,
                // so people can't miss it).
                throw new InvalidArgumentException("'take' argument must not be empty/zero, otherwise no results are returned.", 41);
            }
            if (isset($arguments['take']) && (!is_numeric($arguments['take']) || $arguments['take'] <= 0)) {
                throw new InvalidArgumentException("'take' argument must be a positive number.", 42);
            }
            if (isset($arguments['skip']) && (!is_numeric($arguments['skip']) || $arguments['skip'] < 0)) {
                throw new InvalidArgumentException("'skip' argument must be zero or larger.", 43);
            }
        }

        return $arguments;
    }

    /**
     * Sets up a SOAP connection to AFAS and calls a remote function.
     *
     * @param string $type
     *   Type of connector: get / update / report / subject / data.
     * @param string $function
     *   Function name to call.
     * @param array $arguments
     *   Named function arguments. All values must be scalars. (Case of argument
     *   names gets changed; if there are multiple arguments whose names only
     *   differ in case, then the value that is later in the array will override
     *   earlier arguments.)
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
    public function callAfas($type, $function, array $arguments)
    {
        $type = strtolower($type);
        // Unify case of arguments, so we don't miss any mis-cased ones. (For
        // instance, a mis-cased 'filtersXml' will not filter anything). If two
        // arguments with different case are in the array, the value that is
        // later in the array will override other indices.
        $arguments = array_change_key_case($arguments);

        // Even though this may not be necessary, we want to restrict the
        // connector types to those we know. When adding a new one, we want to
        // carefully check whether we're not missing any arguments that we
        // should be preprocessing.
        if (!in_array($type, ['get', 'update', 'report', 'subject', 'data', 'token', 'versioninfo'])) {
            throw new InvalidArgumentException("Invalid connector type '$type'", 40);
        }

        $client = $this->getSoapClient($type);

        $arguments = $this->validateArguments($arguments, $function);

        // The SOAP argument names are case sensitive so we need to turn them
        // back to valid ones.
        $correct_params = ['connectorid' => 'connectorId', 'filtersxml' => 'filtersXml'];
        $params = [];
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
            if (isset($correct_params[$name])) {
                $name = $correct_params[$name];
            }
            $params[] = new SoapVar($value, XSD_STRING, null, null, $name, 'urn:Afas.Profit.Services');
        }
        $function_wrapper = new SoapVar($params, SOAP_ENC_OBJECT, null, null, $function, 'urn:Afas.Profit.Services');
        $function_param = new SoapParam($function_wrapper, $function);

        if (!empty($this->options['useWSDL'])) {
            $response = $client->$function($function_param);
        } else {
            // The above call would set the SOAPAction HTTP header to
            // "urn:Afas.Profit.Services#GetDataWithOptions". Call __soapCall()
            // directly (rather than indirectly through a 'magic function' as
            // above) so that we can modify arguments.
            $response = $client->__soapCall($function, [$function_param], ['soapaction' => 'urn:Afas.Profit.Services/' . $function]);
        }

        // See the WSDL definition: Every AFAS call returns a single-value
        // response with the single value always a string named XXXResult.
        if (is_object($response) && isset($response->{"{$function}Result"})) {
            return $response->{"{$function}Result"};
        } elseif (is_string($response)) {
            // WSDL-less call returns a string.
            return $response;
        } else {
            throw new UnexpectedValueException('Unknown response format: ' . var_export($response, true), 24);
        }
    }
}
