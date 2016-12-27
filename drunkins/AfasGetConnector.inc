<?php

use SimpleAfas\Connection;

/**
 * Fetches data through an AFAS GET connector.
 *
 * Other modules can create jobs and make them available to cron / admin UI, by
 * - installing the 'drunkins' Drupal contrib module;
 * - using this class as a fetcher inside their job class (by e.g. subclassing
 *   DrunkinsJob), or subclassing this class,
 * - exposing their job through hook_queue_info().
 */
class AfasGetConnector extends DrunkinsFetcher {

  /* Settings in $this->settings, used by this class:
   * - job_id:              defined in parent
   *
   * - afas_api_client_class (string):
   *   The name of the client class to use. Optional; by default the one from
   *   Drupal's global AFAS API admin screen will be taken.
   * - afas_client_options (array):
   *   Configuration options for the client. Optional ONLY IF the class'
   *   constructor knows where to find its own configuration. (Which is the case
   *   for standard Drupal-admin configured client classes.)
   *
   * - afas_connector (string):
   *   The name of the GET connector to use for fetching data.
   * - afas_filter (array):
   *   Filter to use. See comments (all types are supported) at
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
   * - fetcher_timestamp_ignore (boolean): <-- @TODO might need to be moved to parent
   *   Set to TRUE to suppress the above filter on 'updated' and messages about
   *   a missing 'updated' field.
   *
   * - afas_include_empty_fields (boolean):
   *   include empty fields in AFAS output.
   *
   * - fetcher_timeout (int):
   *   response_timeout to set for AfasNusoapClient. (Has no effect on
   *   AfasSoapClient. It doesn't matter, as long as we keep using CURL for it.)
   * - fetcher_tries (int): <==== NOT FUNCTIONING AT THE MOMENT.
   *   number of times to try connecting at timeout.
   *
   */

  // Optional extra filter. (Added for convenience; it's perfectly possible for
  // classes to do their own filtering - but this way they can 'hardcode' an
  // extra filter for their connector in their __construct function.)
  // Needs to be a single-level array (i.e. can contain multiple fields but only
  // one operator).
  // @deprecated - use settings[afas_filter].
  protected $extra_filter;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $settings = array()) {
    parent::__construct($settings);

    if (!isset($this->settings['opt_afas_adv_filter'])) {
      $this->settings['opt_afas_adv_filter'] = user_access('access drunkins advanced');
    }
  }

  /// Interface functions

  /**
   * {@inheritdoc}
   */
  public function settingsForm() {
    $form = parent::settingsForm();

    // @todo think about whether this option should be general. And document it.
    $last_sync = variable_get('drunkins_fetcher_ts_' . $this->settings['job_id'], 0);
    $form['selection']['fetcher_timestamp_ignore'] = array(
      '#type' => 'checkbox',
      '#title' => t('Ignore "changed since"'),
      '#default_value' => empty($last_sync), '#weight' => 2,
    );
    $from_date_enabled = isset($form['selection']['fetcher_timestamp_from_date']);
    if ($from_date_enabled) {
      // 'enabled' state may or may not be set by the parent already. We are
      // just adding another 'and' condition to restrict enabled-ness.
      $form['selection']['fetcher_timestamp_from_date']['#states']['enabled'][':input[name="fetcher_timestamp_ignore"]'] =  array('checked' => FALSE);
    }

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
              .= ' ' . t('One cached item can be processed by with the \'!single_item\' option.',
                         array('!single_item' => $form['selection']['fetcher_single_item']['#title']));
          }
          $form['selection']['cache_items']['#description']
            .= ' ' . t('The Advanced filter will not have any effect when using cached items.');
        }
      }
    }

//    $form['fetcher_tries'] = array(
//      '#type'          => 'textfield',
//      '#title'         => t('Tries'),
//      '#description'   => 'The number of times to repeatedly try fetching AFAS data, in case of timeouts',
//      '#default_value' => 1,
//      '#size'          => 3,
//      '#weight'        => 97,
//    );

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
    module_load_include('php', 'afas_api', 'lib/src/Connection');
    $client_class = !empty($this->settings['afas_client_class']) ? $this->settings['afas_client_class']
      : variable_get('afas_api_client_class', 'DrupalSoapNtlmClient');
    $client_options = isset($this->settings['afas_client_options']) && is_array($this->settings['afas_client_options']) ? $this->settings['afas_client_options'] : array();
    if (!empty($this->settings['fetcher_timeout']) && is_numeric($this->settings['fetcher_timeout'])) {
      // Option only works for AfasNusoapClient but that's fine.
      $client_options['response_timeout'] = $this->settings['fetcher_timeout'];
    }
    $client = new $client_class($client_options);
    $connection = new Connection($client);

    // @todo while the retry-on-timeout was very necessary in 2012, we shouldn't
    // be doing retry-loops like this inside an HTTP request. We have
    // 'drunkins_process_more' since 2015 so we should set that instead. On top
    // of that, getData() throws exceptions instead of returning FALSE now. So
    // we'll just comment things out for now. If we start seeing timeouts again
    // that are so many they're unmanageable / just failing the job won't fly...
    // then we'll need to test handling them at that moment.
//    $items = FALSE;
//    $tries_left = !empty($this->settings['fetcher_tries']) ? $this->settings['fetcher_tries'] : 1;
//    while ($items === FALSE && --$tries_left >= 0) {
      $items = $connection->getData($this->settings['afas_connector'],
        $this->inputFilter($context),
        !empty($context['afas_return_xml']) ?  'get_simplexml' : 'get',
        !empty($this->settings['afas_include_empty_fields']) ? array('options' => array('Outputoptions' => 3)) : array()
      );

//      if ($items === FALSE) {
//        // Retry if timeout
//        $error = $connection->getLastCallInfo('error');
//        if (strpos($error, 'cURL ERROR: 28') !== FALSE) {
//          if ($tries_left) {
//            // Do 'error' only because it positions this message in between
//            // the other messages, if this is called from the batch form.
//            $this->log('AFAS timeout occurred; retrying, #@try', array('@try' => $this->settings['fetcher_tries'] - $tries_left), WATCHDOG_ERROR);
//          }
//        }
//        else {
//          $tries_left = 0;
//        }
//      }
//    }

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
    // (As long as 'fetcher_timestamp_ignore' is a setting from ourselves, not
    // from the parent, we will assume it does not suppress the existence of the
    // fetcher_timestamp_from context value. If we move this to the parent, we
    // might be doing that differently. I don't know yet. @TODO )
    if (!empty($context['fetcher_timestamp_from']) && empty($this->settings['fetcher_timestamp_ignore'])) {
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
