<?php
/**
 * @file
 * Example of GetConnector, useful for fetching and viewing data;
 * processItem() does nothing.
 */
class AfasGetConnectorDisplay extends AfasGetConnector {

  public function __construct($settings = array()) {
    parent::__construct($settings);

    // Set 'Display' option (which is the only practical use of this class).
    // Add 'connector' to the options that get passed to 'Display'.
    $this->opt_list = TRUE;
    $this->form_settings_display_filter[] = 'connector';

    $this->opt_adv_filter = TRUE;
    $this->opt_limit = TRUE;
  }

  /**
   * Extends AfasGetConnector::settingsForm()
   */
  public function settingsForm() {
    $form = parent::settingsForm();

    $form['connector'] = array(
      '#type'        => 'textfield',
      '#title'       => t('Connector name'),
      '#description' => t('The name of a GET connector, as defined in your AFAS enviroment'),
      '#weight'      => 6,
    );
    return $form;
  }

  /**
   * Implements BatchQueueJobInterface::init()
   */
  public function init($settings = array()) {

    // Fill connector property from settingsForm.
    if (empty($this->settings['connector'])) {
      return 'No connector name specified.';
    }
    $this->connector = $this->settings['connector'];
    return $this->getAfasItems();
  }

  /**
   * Implements BatchQueueJobInterface::initContext() + (renamed) AFASGetConnector::initContext()
   */
  public function initContext(&$items, &$context) {
    $context['results']['counter'] = 0;
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
  public function finish($results) {
    return format_plural($results['counter'],
      '1 item was not processed.', '@count items were not processed.');
  }
}
