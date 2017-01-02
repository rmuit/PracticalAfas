<?php

use SimpleAfas\SoapNtlmClient;

// Since we don't (necessarily) have an autoloader that handles namespaces:
module_load_include('php', 'afas_api', 'lib/src/SoapAppClient');
module_load_include('php', 'afas_api', 'lib/src/SoapNtlmClient');
module_load_include('php', 'afas_api', 'lib/src/NtlmSoapClient');

/**
 * Subclass of \SimpleAfas\NusoapNtlmClient, handling Drupal configuration.
 */
class DrupalSoapNtlmClient extends SoapNtlmClient {

  /**
   * {@inheritdoc}
   */
  public function __construct(array $options = array()) {
    // If options are not provided (which in practice will be always), fill them
    // from our global Drupal configuration variables.
    foreach (array(
               'urlBase' => array('afas_api_url', 'https://profitweb.afasonline.nl/profitservices'),
               'environmentId' => array('afas_api_environment', ''),
               'domain' => array('afas_api_domain', 'AOL'),
               'userId' => array('afas_api_user', ''),
               'password' => array('afas_api_pw', ''),
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

    if (!isset($options['useWSDL'])) {
      $options['useWSDL'] = variable_get('afas_api_use_wsdl', FALSE);
    }
    if ($options['useWSDL']) {
      if (empty($options['wsdl_local_file'])) {
        $options['wsdl_local_file'] = variable_get('afas_api_wsdl_local_file', '');
      }
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
    $form['afas_api_url'] = array(
      '#type' => 'textfield',
      '#title' => t('URL of Web Service'),
      '#description' => t('Starting with http(s):// ; no trailing slash.'),
      '#default_value' => variable_get('afas_api_url', 'https://profitweb.afasonline.nl/profitservices'),
    );
    $form['afas_api_environment'] = array(
      '#type' => 'textfield',
      '#title' => t('Environment ID'),
      '#default_value' => variable_get('afas_api_environment', ''),
      '#size' => 20,
    );
    $form['afas_api_domain'] = array(
      '#type' => 'textfield',
      '#title' => t('Domain'),
      '#default_value' => variable_get('afas_api_domain', 'AOL'),
      '#size' => 20,
    );
    $form['afas_api_user'] = array(
      '#type' => 'textfield',
      '#title' => t('User ID'),
      '#default_value' => variable_get('afas_api_user', ''),
      '#size' => 20,
    );
    $form['afas_api_pw'] = array(
      '#type' => 'password',
      '#title' => t('Password'),
      '#size' => 20,
    );
    $password = variable_get('afas_api_pw');
    if ($password) {
      $form['afas_api_pw']['#description'] = t('To change the password, enter the new password here.');
    }

    $form['afas_api_use_wsdl'] = array(
      '#type' => 'checkbox',
      '#title' => t('Use WSDL'),
      '#description' => t('Not using WSDL is faster / simpler; AFAS calls are simple/static enough not to need WSDL.'),
      '#default_value' => variable_get('afas_api_use_wsdl', FALSE),
    );
    $form['afas_api_wsdl_local_file'] = array(
      '#type' => 'textfield',
      '#title' => t('File location to cache the WSDL document locally.'),
      '#description' => t('This is required when using WSDL with this class. Must contain the string "[type]", and should be a path outside the webroot. (e.g.: starting with "private://" if private files are enabled.)'),
      '#default_value' => variable_get('afas_api_wsdl_local_file', ''),
      '#states' => array('enabled' => array(':input[name="afas_api_use_wsdl"]' =>  array('checked' => TRUE)))
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

  /**
   * Adds class specific validation for the settings form.
   *
   * @return array
   *   Extra form elements to merge into the settings form.
   */
  public static function settingsFormValidate($form, &$form_state) {
    if (empty($form_state['values']['afas_api_pw'])) {
      // Do not let an empty password overwrite an already existing one.
      unset($form_state['values']['afas_api_pw']);
    }
    if (!empty($form_state['values']['afas_api_use_wsdl']) && empty($form_state['values']['afas_api_wsdl_local_file'])) {
      // Note the 'class_specific' identifier here.
      form_error($form['class_specific']['afas_api_wsdl_local_file'], t("The '@option2' option is required if the '@option1' option is enabled.", array('@option1' => 'afas_api_use_wsdl', '@option2' => 'afas_api_wsdl_local_file')));
    }
    elseif (!empty($form_state['values']['afas_api_wsdl_local_file']) && strpos($form_state['values']['afas_api_wsdl_local_file'], '[type]') === FALSE) {
      form_error($form['class_specific']['afas_api_wsdl_local_file'], t('The file/location name must contain the literal string "[type]".'));
    }
  }

}
