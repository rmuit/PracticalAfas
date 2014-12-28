<?php
/**
 * @file
 * Admin screens for AFAS API.
 */

/**
 * Form definition for global settings.
 */
function afas_api_settings_form($form, &$form_state) {

  $form['help'] = array(
    '#markup' => t('These configuration options are used by the standard AfasSoapConnection class.')
  );

  $info = module_invoke_all('afas_api_client_info');
  $form['class'] = array(
    '#type' => 'fieldset',
    '#title' => t('Client class'),
    '#collapsible' => TRUE,
    '#collapsed' => TRUE,
  );
  $form['class']['afas_api_client_class'] = array(
    '#type' => 'select',
    '#title' => t('The name of the AFAS soap client class to use'),
    '#options' => array_combine(array_keys($info), array_keys($info)),
    '#default_value' => variable_get('afas_api_client_class', 'AfasSoapClient'),
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

  $form['afas_api_url'] = array(
    '#type' => 'textfield',
    '#title' => t('URL of Web Service'),
    '#description' => t('Starting with http(s):// ; no trailing slash.'),
    '#default_value' => variable_get('afas_api_url', 'https://profitweb.afasonline.nl/profitservices'),
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

  $form['#validate'][] = 'afas_api_settings_form_validate';

  return system_settings_form($form);
}

/**
 * Validate function for our global settings form.
 */
function afas_api_settings_form_validate($form, &$form_state) {

  if (empty($form_state['values']['afas_api_pw'])) {
    // Do not let an empty password overwrite an already existing one.
    unset($form_state['values']['afas_api_pw']);
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
    '#required' => TRUE,
    '#weight' => 9,
  );
  $form['submit'] = array(
    '#type' => 'submit',
    '#value' => t('Get XSD'),
    '#weight' => 10,
  );

  return $form;
}

/**
 * Form submit callback for schema retriever form.
 */
function afas_api_schema_form_submit(&$form, &$form_state) {
  // Retrieve / display XSD.
  $connection = new AfasSoapConnection();
  $connection->setErrorReporting(AFAS_LOG_ERROR_DETAILS_SCREEN);
  $ret = $connection->getData($form_state['values']['function'], array(), 'data', array());
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
    $ok = FALSE;
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
    $form['result'] = array(
      '#markup' => $ok ? '<pre>' . check_plain($ret) . '</pre>' : check_plain($ret),
      '#weight' => -99,
    );
    // Do not redirect; print the value directly into the form.
    $form_state['redirect'] = FALSE;
  }
}
