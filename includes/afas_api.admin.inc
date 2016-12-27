<?php
/**
 * @file
 * Admin screens for AFAS API.
 */

/**
 * Form definition for global settings.
 */
function afas_api_settings_form($form, &$form_state) {

  $form['logging'] = array(
    '#type' => 'fieldset',
    '#title' => t('Logging'),
    '#description' => t('These logging settings are followed by code which uses the standard functions in afas_api.module.'),
  );
  // These are various combinations of the 'watchdog' + 'watchdog_extra' options
  // for the logger class.
  $form['logging']['afas_api_log_watchdog'] = array(
    '#type' => 'select',
    '#title' => t('Log AFAS errors to watchdog'),
    '#options' => array(
      0 => t('No'),
      AFAS_LOG_ERRORS_WATCHDOG => t('Log errors'),
      AFAS_LOG_ERRORS_WATCHDOG+AFAS_LOG_ERROR_DETAILS_WATCHDOG => t('Log all messages including all context info + backtrace'),
    ),
    '#default_value' => variable_get('afas_api_log_watchdog', 1),
  );
  $form['logging']['afas_api_log_screen'] = array(
    '#type' => 'select',
    '#title' => t('Show AFAS errors on screen'),
    '#options' => array(
      0 => t('No'),
      AFAS_LOG_ERRORS_SCREEN => t('Log errors'),
      AFAS_LOG_ERRORS_SCREEN+AFAS_LOG_ERROR_DETAILS_SCREEN => t('Log all messages including all context info + backtrace'),
    ),
    '#default_value' => variable_get('afas_api_log_screen', 0),
  );

  $form['class'] = array(
    '#type' => 'fieldset',
    '#title' => t('Client class'),
    '#collapsible' => TRUE,
    '#collapsed' => TRUE,
  );
  // Get class name from submitted AJAX form on reload, so the right form gets
  // displayed.
  if (!empty($form_state['values']['afas_api_client_class'])) {
    $current_classname = $form_state['values']['afas_api_client_class'];
  }
  else {
    $current_classname = variable_get('afas_api_client_class', 'DrupalSoapAppClient');
  }
  $info = module_invoke_all('afas_api_client_info');
  $form['class']['afas_api_client_class'] = array(
    '#type' => 'select',
    '#title' => t('The name of the AFAS soap client class to use'),
    '#options' => array_combine(array_keys($info), array_keys($info)),
    '#default_value' => $current_classname,
    '#ajax' => array(
      'callback' => 'afas_api_admin_class_options_callback',
      'wrapper' => 'class-specific',
      'method' => 'replace',
    ),
  );
  $form['class']['info'] = array(
    '#markup' => '<p>' . t('More information:') . '</p><p>',
    '#prefix' => '<div class="description">',
    '#suffix' => '</div>',
  );
  foreach ($info as $classname => $description) {
    $form['class']['info']['#markup'] .= '<li>' . $classname . ': '
                                         . $description . '</li>';
  }
  $form['class']['info']['#markup'] .= '</p>';

  $form['class_specific'] = array(
    '#type' => 'fieldset',
    '#prefix' => '<div id="class-specific">',
    '#suffix' => '</div>',
    '#title' => t('Client specific options'),
    '#collapsible' => FALSE,
    '#description' => t("Changing an option for one class may have an effect on other classes' configuration too."),
  );
  if (method_exists($current_classname, 'settingsForm')) {
    $form['class_specific'] += $current_classname::settingsForm();
  }

  $form['#validate'][] = 'afas_api_settings_form_validate';

  return system_settings_form($form);
}

/**
 * AJAX form callback for the settings form.
 */
function afas_api_admin_class_options_callback($form, $form_state) {
  return $form['class_specific'];
}

/**
 * Validate function for our global settings form.
 */
function afas_api_settings_form_validate($form, &$form_state) {
  if (!empty($form_state['values']['afas_api_client_class'])) {
    $current_classname = $form_state['values']['afas_api_client_class'];
  }
  else {
    $current_classname = variable_get('afas_api_client_class', 'DrupalSoapAppClient');
  }
  if (method_exists($current_classname, 'settingsFormValidate')) {
    $current_classname::settingsFormValidate($form, $form_state);
  }
}

/**
 * Form definition for schema retriever form.
 */
function afas_api_schema_form($form, &$form_state) {

  // This help text is not needed if we are submitting data.
  // Note if the submission generates an error, the form will redirect back
  // to itself and you will see the text.
  if (empty($form_state['input'])) {
    $form['desc'] = array(
      '#markup' => '<p>' . t('This screen retrieves information about the XML which should be sent to AFAS as a parameter to UpdateConnector and ReportConnector functions. (See afas_api_send_data().)</p><p>This can provide useful information for developers writing their own Update Connectors.') . '</p>',
      '#weight' => 1,
    );
    $form['desc2'] = array(
      '#markup' => t('(Information about all possible SOAP functions to call, with their call parameters, can be retrieved from '
                     . check_plain(variable_get('afas_api_url')) . '/XXXconnector.asmx - with XXX being update/report/get/data. But you probably don\'t need that and can just call <em>afas_api_send_data(function_name, XML)</em> instead of calling the lower level SOAP functions.)'),
      '#prefix' => '<div class="description">',
      '#suffix' => '</div>',
      '#weight' => 2,
    );
  }

  $form['function'] = array(
    '#type' => 'textfield',
    '#title' => 'UpdateConnector name',
//    '#required' => TRUE,
    '#weight' => 9,
  );
  $form['submit'] = array(
    '#type' => 'submit',
    '#value' => t('Get XSD'),
    '#weight' => 10,
  );

  $form['get_token'] = array(
    '#type' => 'fieldset',
    '#title' => t('Get new token'),
    '#description' => t("Only works with newer 'app connector' clients."),
    '#weight' => 11,
  );
  $form['get_token']['get_token_user_id'] = array(
    '#type' => 'textfield',
    '#title' => t('User ID'),
  );
  $form['get_token']['get_token_api_key'] = array(
    '#type' => 'textfield',
    '#title' => t('API key'),
  );
  $form['get_token']['get_token_environment_key'] = array(
    '#type' => 'textfield',
    '#title' => t('Environment key'),
  );
  $form['get_token']['get_token_description'] = array(
    '#type' => 'textfield',
    '#title' => t('Description'),
  );
  $form['get_token']['get_token_submit'] = array(
    '#type' => 'submit',
    '#value' => t('Get Token'),
  );

  return $form;
}

/**
 * Form submit callback for schema retriever form.
 */
function afas_api_schema_form_submit(&$form, &$form_state) {
  $ok = $ret = FALSE;

  switch ($form_state['clicked_button']['#value']) {
    case t('Get Token'):
      $extra_args = array(
        'apiKey' => $form_state['values']['get_token_api_key'],
        'environmentKey' => $form_state['values']['get_token_environment_key'],
        'description' => $form_state['values']['get_token_description'],
      );
      $ret = afas_api_get_data($form_state['values']['get_token_user_id'], array(), 'token', $extra_args, AFAS_LOG_ERROR_DETAILS_SCREEN);
      break;

    case t('Get XSD'):
  // Retrieve / display XSD;
  $ret = afas_api_get_data($form_state['values']['function'], array(), 'data', array(), AFAS_LOG_ERROR_DETAILS_SCREEN);
  if ($ret) {

    /* We got an XML string returned, with the XSD encoded into one of the
     * attributes. This is an example string we got back:
<AfasDataConnector>
  <xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema">
    <xs:element name="AfasDataConnector">
      <xs:complexType>
        <xs:choice maxOccurs="unbounded">
          <xs:element name="ConnectorData">
            <xs:complexType>
              <xs:sequence>
                <xs:element name="Id" type="xs:string" minOccurs="0"/>
                <xs:element name="Schema" type="xs:string" minOccurs="0"/>
              </xs:sequence>
            </xs:complexType>
          </xs:element>
        </xs:choice>
      </xs:complexType>
    </xs:element>
  </xs:schema>
  <ConnectorData>
    <Id>knOrganisation</Id>
    <Schema> [ THE SCHEMA DATA ] </Schema>
  </ConnectorData>
</AfasDataConnector>
     * Let's decode that. If we find the schema, we will only print that and
     * silently ignore the rest.
     */
      $doc_element = new SimpleXMLElement($ret);
      if (isset($doc_element->ConnectorData->Schema)) {
        $ret = strval($doc_element->ConnectorData->Schema);
        // This value was not already interpreted as XML. So to pretty print it,
        // we need to make a document from it.
        $doc_element = new DomDocument('1,0');
        if ($doc_element->loadXML($ret)) {
          $doc_element->formatOutput = TRUE;
          $ret = $doc_element->saveXML();
          $ok = TRUE;
        }
      }
    }
    break;
  }

  if ($ret) {
    $form['result'] = array(
      '#markup' => $ok ? '<pre>' . check_plain($ret) . '</pre>' : check_plain($ret),
      '#weight' => -99,
    );
    // Do not redirect; print the value directly into the form.
    $form_state['redirect'] = FALSE;

  }
}
