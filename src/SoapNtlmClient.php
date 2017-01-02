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

use \SoapParam;
use \SoapVar;

/**
 * Wrapper around client specific details of making a remote AFAS call.
 *
 * This class contains one public method: callAfas(), and uses
 * - the SOAP library bundled with PHP5;
 * - NTLM authentication, as opposed to a more modern "app connector". (NTLM
 *   authentication is supposedly phased out by AFAS on 2017-01-01.)
 */
class SoapNtlmClient extends SoapAppClient {

  /**
   * Constructor.
   *
   * Since there is no other way of setting options, we check them inside the
   * constructor and throw an exception if we know any AFAS calls will fail.
   *
   * @param array $options
   *   Configuration options. Some of them are used for configuring the actual
   *   NtlmSoapClient class; some are used as standard arguments in each SOAP
   *   call, some are used for both. Keys used:
   *   Required:
   *   - urlBase:       AFAS endpoint URL (without the variable last part).
   *   - environmentId: AFAS environment name.
   *   - domain         AFAS (NTLM) domain name.
   *   - userId:        AFAS user id.
   *   - password:      password.
   *   Optional:
   *   - soapClientClass: classname for the actual Soap client to use. Should be
   *                      compatible with PHP's SoapClient.
   *   - useWSDL:         boolean. (Suggestion: don't set it.)
   *   - cacheWSDL:       How long the WSDL should be cached locally in seconds.
   *   Other options (which are usually not camelCased but under_scored) are
   *   client specific; for all valid ones, see initClient().
   *
   * @throws \InvalidArgumentException
   *   If some option values are missing / incorrect.
   */
  public function __construct(array $options) {
    foreach (array('urlBase', 'environmentId', 'domain', 'userId', 'password') as $required_key) {
      if (empty($options[$required_key])) {
        $classname = get_class($this);
        throw new \InvalidArgumentException("Required configuration parameter for $classname missing: $required_key.", 1);
      }
    }

    // More (conditionally) required options.
    if (!empty($options['useWSDL'])) {
      if (empty($options['wsdl_local_file'])) {
        throw new \InvalidArgumentException("The 'wsdl_local_file' option is required if the 'useWSDL' option is enabled.", 2);
      }
      elseif (strpos($options['wsdl_local_file'], '[type]') === FALSE) {
        throw new \InvalidArgumentException("The 'wsdl_local_file' option must contain the literal string \"[type]\", as a replacement pattern to be able to store multiple WSDL files.", 2);
      }
    }

    // Add defaults for the SOAP client.
    $options += array(
      'encoding' => 'utf-8',
      'login' => $options['domain'] . '\\' . $options['userId'],
      'soapClientClass' => '\PracticalAfas\NtlmSoapClient',
    );

    $this->options = $options;
  }

  /**
   * {@inheritdoc}
   */
  protected function getSoapClient($type, $endpoint = '') {
    // Set our own endpoint default to override the parent's.
    if (!$endpoint) {
      $endpoint = trim($this->options['urlBase'], '/') . '/' . strtolower($type) . 'connector.asmx';
    }
    return parent::getSoapClient($type, $endpoint);

  }

  /**
   * {@inheritdoc}
   */
  protected function validateArguments($arguments, $function) {
    return array_merge(array(
      'environmentId' => $this->options['environmentId'],
      'userId' => $this->options['userId'],
      'password' => $this->options['password'],
    ), $arguments);
  }

}
