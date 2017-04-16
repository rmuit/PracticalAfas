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
use UnexpectedValueException;
use nusoap_client;
use wsdl;
use wsdlcache;

/**
 * Wrapper around client specific details of making a remote AFAS call.
 *
 * This class contains one public method: callAfas(), and uses:
 * - the external (older) NuSOAP library. It can be used if the (preferred) PHP
 *   SOAP extension is not compiled/enabled on your server.
 * - NTLM authentication, as opposed to a more modern "app connector". (NTLM
 *   authentication is supposedly phased out by AFAS on 2017-01-01. This code
 *   will stay around in case someone wants to port it to a Nusoap + App
 *   connector client.)
 *
 * $this->soapClient and $this->connectorType are not used. That is, we do not
 * cache the actual soapclient across calls but reinitialize it with each call
 * to callAfas(). Whoever feels inclined to change that: feel free.
 *
 * Note v0.9.5 of the nuSOAP library cannot deal with AFAS servers without WSDL;
 * you need to call the constructor with $options = ['useWSDL' => true] or
 * (since WSDL introduces overhead which is unnecessary with AFAS' simple call
 * structure) you need sources from https://github.com/rmuit/NuSOAP.
 */
class NusoapNtlmClient
{
    /**
     * Constructor.
     *
     * Since there is no other way of setting options, we check them inside the
     * constructor and throw an exception if we know any AFAS calls will fail.
     *
     * @param array $options
     *   Configuration options. see SoapNtlmClient::__construct() for values,
     *   except 'soapClientClass'.
     *
     * @throws \InvalidArgumentException
     *   If some option values are missing / incorrect.
     */
    public function __construct(array $options)
    {
        foreach (['urlBase', 'environmentId', 'domain', 'userId', 'password'] as $required_key) {
            if (empty($options[$required_key])) {
                $classname = get_class($this);
                throw new InvalidArgumentException("Required configuration parameter for $classname missing: $required_key.", 1);
            }
        }

        // Add defaults for the SOAP client.
        $options += [
            'soap_defencoding' => 'utf-8',
            'xml_encoding' => 'utf-8',
            'decode_utf8' => false,
        ];

        $this->options = $options;
    }

    /**
     * Returns a SOAP client object, configured with options previously set.
     *
     * @param string $type
     *   Type of AFAS connector. (This determines the SOAP endpoint URL.)
     *
     * @return \nusoap_client
     *   Initialized client object.
     *
     * @throws \Exception
     *   If we failed to construct a nusoap_client class.
     */
    protected function getSoapClient($type)
    {
        // Make sure the aging nuSOAP code does not make PHP5.3 give strict
        // timezone warnings.
        // Note: date_default_timezone_set() is also called in D7's standard
        // drupal_session_initialize() / D8's drupal_set_configured_timezone().
        // So I don't think this is necessary... Still, to be 100% sure:
        if (!ini_get('date.timezone')) {
            if (!$timezone = variable_get('date_default_timezone')) {
                $timezone = @date_default_timezone_get();
            }
            date_default_timezone_set($timezone);
        }

        // available: get/update/report/subject/dataconnector.
        $endpoint = trim($this->options['urlBase'], '/') . '/' . strtolower($type) . 'connector.asmx';

        $options = $this->options + ['useWSDL' => false];
        if ($options['useWSDL']) {
            $endpoint .= '?WSDL';

            if (!empty($this->options['cacheWSDL'])) {
                // Get cached WSDL
                $cache = new wsdlcache(file_directory_temp(), $this->options['cacheWSDL']);
                $wsdl = $cache->get($endpoint);
                if (is_null($wsdl)) {
                    $wsdl = new wsdl();
                    $wsdl->setCredentials($options['domain'] . '\\' . $options['userId'], $options['password'], 'ntlm');
                    $wsdl->fetchWSDL($endpoint);
                    if ($error = $wsdl->getError()) {
                        // We should ideally have an exception type where we can
                        // store debug details in a separate property. But let's
                        // face it, noone is going to use this anymore anyway.
                        throw new RuntimeException("Error getting WSDL: $error. Debug details: " . $wsdl->getDebug(), 24);
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
        // AFAS has their 'timeout' value on the server set to 5 minutes, and
        // gives no response until it sends the result of a call back. So
        // changing the 'timeout' (default 0) has no effect; the
        // 'response_timeout' can be upped to max. 5 minutes.
        foreach ([
                     'soap_defencoding',
                     'xml_encoding',
                     'timeout',
                     'response_timeout',
                     'soap_defencoding',
                     'decode_utf8'
                 ] as $opt) {
            if (isset($options[$opt])) {
                $client->$opt = $options[$opt];
            }
        }

        return $client;
    }

    /**
     * Validates / completes arguments for an AFAS SOAP function call.
     *
     * Same as SoapNtlmClient::normalizeArguments, but we didn't want to set up
     * too long an 'extends' chain just for this one.
     *
     * @see SoapAppClient::validateArguments()
     */
    protected function validateArguments($arguments, $function)
    {
        return array_merge([
            'environmentId' => $this->options['environmentId'],
            'userId' => $this->options['userId'],
            'password' => $this->options['password'],
        ], $arguments);
    }

    /**
     * Sets up a SOAP connection to AFAS and calls a remote function.
     *
     * @param string $connector_type
     *   Type of connector: get / update / report / subject / data.
     * @param string $function
     *   Function name to call.
     * @param array $arguments
     *   Function arguments.
     *
     * @return string
     *   The response from the SOAP endpoint.
     *
     * @throws \InvalidArgumentException
     *   For invalid arguments. (Unknown connector type.)
     * @throws \UnexpectedValueException
     *   If the SoapClient returned a response in an unknown format.
     * @throws \RuntimeException
     *   If the SOAP call returned an error.
     * @throws \Exception
     *   For anything else that went wrong, either initializing the SoapClient
     *   or calling the SOAP function.
     */
    public function callAfas($connector_type, $function, array $arguments)
    {
        // Even though this may not be necessary, we want to restrict the
        // connector types to those we know. When adding a new one, we want to
        // carefully check whether we're not missing any arguments that we
        // should be preprocessing.
        if (!in_array($connector_type, ['get', 'update', 'report', 'subject', 'data'])) {
            throw new InvalidArgumentException("Invalid connector type $connector_type", 40);
        }

        $client = $this->getSoapClient($connector_type);

        if ($client->endpointType === 'wsdl') {
            $response = $client->call($function, $arguments);
        } else {
            $response = $client->call($function, $arguments, 'urn:Afas.Profit.Services', 'urn:Afas.Profit.Services/' . $function, false, null, 'document', 'literal wrapped');
        }
        if ($error = $client->getError()) {
            if (isset($response->detail)) {
                // NuSOAP's $client->getDebug() is just unusable. It includes
                // duplicate info and lots of HTML font colors etc (or is that
                // my settings influencing var_dump output? That still doesn't
                // change the fact that it's unusable though).
                // There are some details in there that are not in $response,
                // like the parameters (but we already have those in
                // $afas_soap_connection->lastCallInfo) and HTTP headers sent /
                // received
                // $response now actually is an array with 'faultcode',
                // 'faultstring' and 'detail' keys - 'detail' contains
                // 'ProfitApplicationException' containing 'ErrorNumber',
                // 'Message' (== faultstring) and 'Detail'.
                $details = print_r($response, true);
            } else {
                // Too bad; we don't have anything else than this...
                // (If ->detail isn't set, then probably $response is false. If
                // it is not false, we don't know yet which option is better.)
                $details = $client->getDebug();
            }
            // We should ideally have an exception type where we can store debug
            // details in a separate property. But let's face it, noone is going
            // to use this anymore anyway.
            throw new RuntimeException("Error calling SOAP endpoint: $error. Debug details: $details", 23);
        }

        if (isset($response[$function . 'Result'])) {
            return $response[$function . 'Result'];
        }
        throw new UnexpectedValueException('Unknown response format: ' . json_encode($response), 24);
    }
}
