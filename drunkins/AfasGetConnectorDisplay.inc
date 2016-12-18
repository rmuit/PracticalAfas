<?php

/**
 * Example GetConnector, useful for fetching / viewing data; empty processItem()
 *
 * At the moment this is a Job class, implementing DrunkinsJob because that
 * still has the 'display' options/settingsFormSubmit() functionality;
 * - the UI does not yet support fetching / displaying data from fetcher
 *   classes
 * - we do not have settingsFormSubmit (with the Display button) moved into
 *   another (ui_fetcher?) class
 * @todo But we want to do at least one of those, probably the 2nd.
 * When we fix this, this class can be deleted because we'll be able to use
 * AfasGetConnector for its purpose.
 */
class AfasGetConnectorDisplay extends DrunkinsJob {

  /**
   * {@inheritdoc}
   */
  public function __construct(array $settings = array()) {

    if (!isset($this->settings['drunkins_fetcher_class'])) {
      // We're an AFAS job. Obviously we want to set the AFAS Fetcher, at least
      // as a default.
      $this->settings['drunkins_fetcher_class'] = 'AfasGetConnector';
    }

    parent::__construct($settings);

    $this->opt_list = TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm() {

    $form = parent::settingsForm();

    $form['source'] = array(
      '#type'   => 'fieldset',
      '#title'  => t('Data source'),
      '#weight' => 2,
    );
    if (!empty($form['selection']['cache_items']) && module_exists('ctools')) {
      ctools_include('object-cache');
      $items = ctools_object_cache_get('drunkins_items', $this->settings['job_id']);
      if (is_array($items) && $items) {
        $form['source']['#description'] = t('This section will not have effect if using cached items.');
      }
    }

    $form['source']['afas_connector'] = array(
      '#type'        => 'textfield',
      '#title'       => t('Connector name'),
      '#description' => t('The name of a GET connector, as defined in your AFAS enviroment'),
      '#weight'      => 1,
    );

    $form['source']['afas_include_empty_fields'] = array(
      '#type'          => 'checkbox',
      '#title'         => t('Include empty fields'),
      '#default_value' => TRUE,
      '#weight'        => 3,
    );

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($item, array &$context) {
    // Do nothing.
    $context['counter']++;
  }

  /**
   * {@inheritdoc}
   */
  public function finish(array &$context) {
    return format_plural($context['counter'], '1 item was not processed.', '@count items were not processed.')
      . ' ' . t('Press "Display" instead.');
  }
}
