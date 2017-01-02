<?php

use SimpleAfas\SoapAppClient;

// Since we don't (necessarily) have an autoloader that handles namespaces:
module_load_include('php', 'afas_api', 'lib/src/SoapAppClient');

/**
 * Subclass of \SimpleAfas\NusoapNtlmClient, handling Drupal configuration.
 */
class DrupalSoapAppClient extends SoapAppClient {

  /**
   * {@inheritdoc}
   */
  public function __construct(array $options = array()) {
    // If options are not provided (which in practice will be always), fill them
    // from our global Drupal configuration variables.
    foreach (array(
               'customerId' => array('afas_api_customer_id', ''),
               'appToken' => array('afas_api_app_token', ''),
             ) as $required_key => $var) {
      if (!isset($options[$required_key])) {
        $options[$required_key] = variable_get($var[0], $var[1]);
      }
      // We know the parent will throw an exception. We'll set a more specific
      // message.
      if (empty($options[$required_key])) {
        $classname = get_class($this);
        throw new \InvalidArgumentException("Required configuration parameter for $classname missing: $required_key. Maybe you forgot to set the module configuration?", 1);
      }
    }

    if (!isset($options['urlBase'])) {
      $options['urlBase'] = "https://$options[customerId].afasonlineconnector.nl/profitservices";
    }

    if (!isset($options['useWSDL'])) {
      $options['useWSDL'] = variable_get('afas_api_use_wsdl', FALSE);
    }
    if ($options['useWSDL']) {
      if (!isset($options['cacheWSDL'])) {
        $options['cacheWSDL'] = variable_get('afas_api_cache_wsdl', 86400);
      }
    }

    parent::__construct($options);
  }

  /**
   * Adds class specific options to the settings form.
   *
   * @return array
   *   Extra form elements to merge into the settings form.
   */
  public static function settingsForm() {

    $form['afas_api_customer_id'] = array(
      '#type' => 'textfield',
      '#title' => t('Customer ID'),
      '#description' => t('This is usually 5 digits.'),
      '#default_value' => variable_get('afas_api_customer_id', ''),
      '#size' => 20,
    );
    $form['afas_api_app_token'] = array(
      '#type' => 'textfield',
      '#title' => t('The token for the app connector'),
      '#description' => t('A note: if it expires, this module has no automatic way yet of creating a new one.'),
      '#default_value' => variable_get('afas_api_app_token', ''),
    );

    $form['afas_api_use_wsdl'] = array(
      '#type' => 'checkbox',
      '#title' => t('Use WSDL'),
      '#description' => t('Not using WSDL is faster / simpler; AFAS calls are simple/static enough not to need WSDL.'),
      '#default_value' => variable_get('afas_api_use_wsdl', FALSE),
    );
    $form['afas_api_cache_wsdl'] = array(
      '#type' => 'select',
      '#title' => t('Cache WSDL file'),
      '#description' => t('How long to keep the WSDL file cached locally, before downloading a fresh copy from the server.'),
      '#options' => array(
        0 => 'Do not cache',
        300 => '5 minutes',
        1800 => '30 minutes',
        3600 => '1 hour',
        14400 => '4 hours',
        86400 => '1 day',
        604800 => '1 week',
        2502000 => '30 days',
      ),
      '#default_value' => variable_get('afas_api_cache_wsdl', 86400),
      '#states' => array('enabled' => array(':input[name="afas_api_use_wsdl"]' =>  array('checked' => TRUE)))
    );

    return $form;
  }

}
