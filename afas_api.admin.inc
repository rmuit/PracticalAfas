<?php
/**
 * @file
 * Admin screens for AFAS API.
 */

/**
 * Form definition for global settings.
 */
function afas_api_settings_form($form, &$form_state) {

  $form['afas_api_url'] = array(
    '#type' => 'textfield',
    '#title' => t('URL of Web Service'),
    '#description' => t('Starting with http(s):// ; no trailing slash.'),
    '#default_value' => variable_get('afas_api_url', 'https://profitweb.afasonline.nl/profitservices'),
  );
  $form['afas_api_use_wsdl'] = array(
    '#type' => 'checkbox',
    '#title' => t('Use WSDL'),
    '#description' => t('Not using WSDL is faster / simpler. However, v0.9.5 of the nuSOAP library cannot deal with AFAS servers without WSDL; you need sources from https://github.com/rmuit/NuSOAP'),
    '#default_value' => variable_get('afas_api_use_wsdl', TRUE),
  );
  $form['afas_api_cache_wsdl'] = array(
    '#type' => 'select',
    '#title' => t('Cache WSDL file'),
    '#description' => t('How long to keep the WSDL file cached locally / before downloading a fresh copy from the server. This setting has effect if \'Use WSDL\' is on.'),
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
  );
  $form['afas_api_environment'] = array(
    '#type' => 'textfield',
    '#title' => t('Environment ID'),
    '#default_value' => variable_get('afas_api_environment', ''),
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

  return system_settings_form($form);
}

/**
 * Form definition for global settings.
 */
function afas_api_schema_form($form, &$form_state) {

  // This simple form does not have a submit function defined. It does its
  // actions inside this form definition.
  if (empty($form_state['input'])) {
    // No action.
    $form['desc'] = array(
      '#markup' => t('<p>This screen retrieves information about the XML which should be sent to AFAS as a parameter to UpdateConnector and ReportConnector functions. (See afas_api_send_data().)</p><p>This does not have anything directly to do with existing AFAS functionality that can be used through this Drupal site, but it can provide useful information for developers writing their own Update Connectors.</p>'),
    );
    $form['desc2'] = array(
      '#markup' => '<div class="description">' . t('(Information about all possible SOAP functions to call, with their call parameters, can be retrieved from '
                     . check_plain(variable_get('afas_api_url')) . '/XXXconnector.asmx - with XXX being update/report/get/data. But you probably don\'t need that and can just call <em>afas_api_send_data(function_name, XML)</em> instead of calling the lower level SOAP functions.)') . '</div>',
    );
  }
  else {
    // Retrieve / display XSD.
    // There's no validate/submit function, which apparently makes 'values' be not present. We don't care.

    $ret = afas_api_get_data($form_state['input']['function'], array(), 'data', array(), array('print_errors' => TRUE));
    if ($ret) {
      $form['result'] = array(
        '#markup' => check_plain($ret),
      );
    }
  }

  $form['function'] = array(
    '#type' => 'textfield',
    '#title' => 'UpdateConnector / Function name',
    '#required' => TRUE,
  );
  $form['submit'] = array(
    '#type' => 'submit',
    '#value' => t('Get XSD'),
  );

  return $form;
}
