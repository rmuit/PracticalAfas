<?php
/**
 * @file
 * Contains AfasGetConnector.
 */

/**
 * Class AfasGetConnector
 *
 * Fetches data through an AFAS GET connector. Other modules can create jobs and
 * make them available to cron / the admin UI, by
 * - installing the 'drunkins' Drupal contrib module;
 * - using this class as a fetcher inside their job class (by e.g. subclassing
 *   DrunkinsJob), or subclassing this class,
 * - exposing their class through hook_queue_info().
 */
class AfasGetConnector extends DrunkinsFetcher {

  /* Settings in $this->settings, used by this class:
   * - job_id:              defined in parent
   *
   * - afas_connector (string):
   *   the name of the GET connector to use for fetching data.
   * - afas_filter (array):
   *   filter to use. See comments (all types are supported) at
   *   AfasSoapConnection::normalizeArguments().
   * - afas_adv_filter (string):
   *   Filter to use (one 'array level' only), as PHP code. This is only useful
   *   for the per-process settingsForm; use 'afas_filter' instead.
   * - opt_afas_adv_filter: (boolean):
   *   Enables 'afas_adv_filter' setting in settingsForm.
   *
   * - afas_updated_field (string):
   *   AFAS field containing 'updated' date values.
   *   Used only when 'fetcher_timestamp_from' is set in context (by the parent
   *   class), which signifies that a date filter should be added. This class'
   *   inputFilter() method does that automatically, to prevent any child class
   *   from forgetting this. But it needs to know the name of the 'updated'
   *   field for this.
   *   If not filled, a message will be logged.
   * - afas_disable_updated_auto_filter (boolean):
   *   Set to TRUE to suppress the above filter on 'updated' and messages about
   *   a missing 'updated' field.
   *
   * - afas_include_empty_fields (boolean): include empty fields in AFAS output.
   *
   * - fetcher_timeout (int): response_timeout to set for AfasNusoapClient.
   *   (Has no effect on AfasSoapClient. It doesn't matter, as long as we keep
   *   using CURL for that one.)
   * - fetcher_tries (int): number of times to try connecting at timeout.
   *
   */

  // Optional extra filter. (Added for convenience; it's perfectly possible for
  // classes to do their own filtering - but this way they can 'hardcode' an
  // extra filter for their connector in their __construct function.)
  // Needs to be a single-level array (i.e. can contain multiple fields but only
  // one operator).
  // @deprecated - use settings[afas_filter].
  protected $extra_filter;

  public function __construct(array $settings = array()) {
    parent::__construct($settings);

    if (!isset($this->settings['opt_afas_adv_filter'])) {
      $this->settings['opt_afas_adv_filter'] = user_access('access drunkins advanced');
    }
  }

  /// Interface functions

  /**
   * Extends DrunkinsImportJob::settingsForm()
   */
  public function settingsForm() {
    $form = parent::settingsForm();

    if (!empty($this->settings['opt_afas_adv_filter'])) {

      // There's a slight chance that the fieldset has not been defined yet
      // (creating #weight isseus), see DrunkinsFetcher.
      if (!isset($form['selection'])) {
        $form['selection'] = array(
          '#type'   => 'fieldset',
          '#title'  => t('Data selection'),
          '#weight' => 10,
        );
      }

      // Potential security hole; this edit screen must only be available for admins.
      $form['selection']['afas_adv_filter'] = array(
        '#type'          => 'textarea',
        '#rows'          => 5,
        '#title'         => t('Advanced filter'),
        '#description'   => "Fill a comma separated set of array definitions if you want to apply other filters.<br/>
One example, to get only products that changed in the past week:<br/><em>array(<br/>
'SOME-CHANGED-DATE' => '" . date('Y-m-d\TH:i:s', time() - 7 * 86400) . "',<br/>
'#op' => '>',<br/>
),</em>",
        '#default_value' => "array(
),",
        '#weight'        => 12,
      );

      // Extend the description of the 'cache' options (from DrunkinsFetcher),
      // IF there are cached items.
      if (!empty($form['selection']['cache_items']) && module_exists('ctools')) {
        ctools_include('object-cache');
        $items = ctools_object_cache_get('drunkins_items', $this->settings['job_id']);
        if (is_array($items) && $items) {
          if (!empty($form['selection']['fetcher_single_item'])) {
            $form['selection']['cache_items']['#description']
              .= ' ' . t('One cached item can be processed by with the \'!single_item\' option.');
          }
          $form['selection']['cache_items']['#description']
            .= ' ' . t('The Advanced filter will not have any effect when using cached items.',
            array('!single_item' => t('Update only one specific item')));
        }
      }
    }

    $form['fetcher_tries'] = array(
      '#type'          => 'textfield',
      '#title'         => t('Tries'),
      '#description'   => 'The number of times to repeatedly try fetching AFAS data, in case of timeouts',
      '#default_value' => 1,
      '#size'          => 3,
      '#weight'        => 97,
    );

    return $form;
  }

  /// Extra methods for extending classes to call!

  /**
   * {@inheritdoc}
   */
  public function feedIsComplete(array &$context) {
    return parent::feedIsComplete($context) && !$this->inputFilter($context);
  }

  /**
   * {@inheritdoc}
   *
   * Modifiers:
   * - afas_return_xml in context (nonempty value returns SimpleXML, not array)
   * - afas_include_empty_fields setting
   */
  protected function fetchItems(array &$context) {

    $connection = new AfasSoapConnection();
    $items = FALSE;
    $tries_left = !empty($this->settings['fetcher_tries']) ? $this->settings['fetcher_tries'] : 1;
    while ($items === FALSE && --$tries_left >= 0) {

      if (!empty($this->settings['fetcher_timeout'])
          && is_numeric($this->settings['fetcher_timeout'])) {
        $connection->setOptions(
          array('fetcher_timeout' => $this->settings['fetcher_timeout'])
        );
      }
      $items = $connection->getData($this->settings['afas_connector'],
        $this->inputFilter($context),
        !empty($context['afas_return_xml']) ?  'get_simplexml' : 'get',
        !empty($this->settings['afas_include_empty_fields'])
          ? array('options' => array('Outputoptions' => 3)) : array()
      );

      if ($items === FALSE) {
        // Retry if timeout
        $error = $connection->getLastCallInfo('error');
        if (strpos($error, 'cURL ERROR: 28') !== FALSE) {
          if ($tries_left) {
            // Do 'error' only because it positions this message in between
            // the other messages, if this is called from the batch form.
            $this->log('AFAS timeout occurred; retrying, #@try', array('@try' => $this->settings['fetcher_tries'] - $tries_left), WATCHDOG_ERROR);
          }
        }
        else {
          $tries_left = 0;
        }
      }
    }
    if ($items === FALSE) {
      // The caller always does a drupal_set_message (for Batch API form runs
      // that generate errors). So return a string to there, instead of doing
      // our own drupal_set_message. Don't set 'print_errors' in
      // AfasSoapConnection; just ignore $this->print_errors.
      // More details are always logged to watchdog.
      $error = $connection->getLastCallInfo('error');
      $er = $connection->getErrorReporting();
      if ($er & AFAS_LOG_ERROR_DETAILS_WATCHDOG
          && !($er & AFAS_LOG_ERROR_DETAILS_SCREEN)) {
        // @todo this is better than not checking the above, but we do not know
        // if there are any details (like with 'AFAS environment is not set',
        // there are none.) Maybe set a counter for number of logs, or remember
        // all? Think this over.
        $error .= ' (' . t('See Recent Log Messages for more details.') . ')';
      }
      throw new \Exception($error);
    }

    return $items;
  }

  /**
   * Returns values to filter items on, according to user input. You might
   * call this from your init(), before fetching items in whatever way.
   * (Or check the properties by yourself.)
   *
   * @param array $context
   *   The process context array.
   *
   * @return array
   *   'filters array' that can be used for calling
   *   AfasSoapConnection::getData().
   *
   * @throws \Exception
   *   If the filter or related settings are invalid.
   */
  public function inputFilter(array $context) {
    // This supports 'simple' (array key being the field name) and 'deeper'
    // array arguments.
    $filters = isset($this->settings['afas_filter']) && is_array($this->settings['afas_filter'])
      ? $this->settings['afas_filter'] : array();

    // Add this filter 1 layer deeper than the 'simple' filter arguments.
    // AfasSoapConnection::getData() will sort it out.
    if (!empty($this->extra_filter)) {
      $filters[] = $this->extra_filter;
    }

    // This filter too.
    if (!empty($context['fetcher_timestamp_from'])
        && empty($this->settings['afas_disable_updated_auto_filter'])) {
      if (empty($this->settings['afas_updated_field'])) {
        throw new \Exception(t('Updated-timestamp field is not specified for AFAS connector, even though we are supposed to be fitering.'));
      }
      else {
        // Since we won't use the timezone in our date expression below, we
        // 'lose' information and have to be careful we get dates in our own
        // timezone instead of UTC. Then it's simpler to use format_date().
        $filters[] = array(
          $this->settings['afas_updated_field'] => format_date($context['fetcher_timestamp_from'], 'custom', 'Y-m-d\TH:i:s'),
          '#op' => '>=',
        );
      }
    }

    foreach ($this->evalFilterInput() as $filter) {
      $filters[] = $filter;
    }

    return $filters;
  }

  /// Private helper functions

  /**
   * Checks for/evaluates a comma separated list of arrays in
   * $this->settings['afas_adv_filter']. This is used for the 'Filter' textarea
   * value in the batch form.
   *
   * @return array
   *   The evaluated filters, or empty array if nothing set / error encountered.
   *
   * @throws \Exception
   *   If this filter does not evaluate to valid PHP array structures.
   */
  private function evalFilterInput() {
    static $filters;

    if (!isset($filters)) {
      $filters = array();

      if (!empty($this->settings['afas_adv_filter'])) {
        $eval = '';
        eval('$eval = array(' . $this->settings['afas_adv_filter'] . ');');
        if (is_array($eval)) {
          foreach ($eval as $filter) {
            if (is_array($filter)) {
              if (!empty($filter)) {
                $filters[] = $filter;
              }
              // Just disregard filter-parts which are empty arrays.
            }
            else {
              // t() in Exception?
              throw new \Exception(t("Invalid 'filter' (part) setting passed to inputFilter(): %p",
                array('%p' => print_r($filter, TRUE))));
            }
          }
        }
        else {
          throw new \Exception(t("Invalid 'afas_adv_filter' setting passed to inputFilter(): %p",
            array('%p' => print_r($this->settings['afas_adv_filter'], TRUE))));
        }
      }
    }
    return $filters;
  }

}
