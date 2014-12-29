<?php
/**
 * @file
 * Contains AfasGetConnectorDisplay.
 */

/**
 * Class AfasGetConnectorDisplay
 *
 * Example of GetConnector, useful for fetching and viewing data;
 * processItem() does nothing.
 *
 * At the moment this is a Job class, implementing BatchQueueJob because that
 * still has the 'display' options/settingsFormSubmit() functionality;
 * - the UI does not yet support fetching / displaying data from fetcher
 *   classes
 * - we do not have settingsFormSubmit (with the Display button) moved into
 *   another (ui_fetcher?) class
 * @todo But we want to do at least one of those, probably the 2nd.
 */
class AfasGetConnectorDisplay extends BatchQueueJob {

  public function __construct($settings = array()) {

    if (!isset($this->settings['batchqueue_fetcher_class'])) {
      // We're an AFAS job. Obviously we want to set the AFAS Fetcher, at least
      // as a default.
      $this->settings['batchqueue_fetcher_class'] = 'AfasGetConnector';
    }

    parent::__construct($settings);

    $this->opt_list = TRUE;
  }

  /**
   * Extends AfasGetConnector::settingsForm().
   *
   * The settings are used by the Fetcher's fetchItems() method, which is called
   * from parent::init() -> Fetcher::init().
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
      $items = ctools_object_cache_get('batchqueue_items', $this->settings['job_id']);
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
   * Implements BatchQueueJobInterface::processItem
   */
  public function processItem($item, &$context) {
    // Do nothing.
    $context['results']['counter']++;
  }

  /**
   * Implements BatchQueueJobInterface::finish()
   */
  public function finish(&$results) {
    return format_plural($results['counter'],
      '1 item was not processed.', '@count items were not processed.');
  }
}
