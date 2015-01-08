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
 * - installing the 'batchqueue' Drupal contrib module;
 * - using this class as a fetcher inside their job class (by e.g. subclassing
 *   BatchQueueJob), or subclassing this class,
 * - exposing their class through hook_queue_info().
 */
class AfasGetConnector extends BatchQueueFetcher {

  /// Keys in $this->settings that are used by this class:
  //
  // 'afas_connector'  : the name of the GET connector to use for fetching data.
  // 'response_timeout': response_timeout to set for AfasNusoapClient.
  //                     (Has no effect on AfasSoapClient. It doesn't matter, as
  //                     long as we keep using CURL for that one.)
  // 'tries'           : number of times to try connecting at timeout.
  // 'afas_include_empty_fields': includes empty fields in AFAS output.

  /// Class variables which an extending class must set in order to make use of
  /// some of the helper functions. (Most simple way: hardcode in __construct();
  /// see also BatchQueueImportJob variables.)

  // Optional extra filter. (Added for convenience; it's perfectly possible for
  // classes to do their own filtering - but this way they can 'hardcode' an
  // extra filter for their connector in their __construct function.)
  // Needs to be a single-level array (i.e. can contain multiple fields but only
  // one operator).
  protected $extra_filter;

  /// Class variables enabling 'optional' functionality we want to
  /// provide here for convenience, but not always enable by default.

  // Boolean: Advanced filter input element, which reads array structures
  // understood by AfasSoapConnection::getData(). Set to TRUE to enable in
  // settingsForm.
  protected $opt_adv_filter;

  public function __construct($settings = array()) {
    parent::__construct($settings);

    $this->opt_limit = TRUE;
    // Note $this->opt_single_item is not enabled. It doesn't have a lot of
    // added value if we don't also add a 'pkey' setting.

    // @todo implement permission?
    $this->opt_adv_filter = TRUE;
  }

  /// Interface functions

  /**
   * Extends BatchQueueImportJob::settingsForm()
   */
  public function settingsForm() {
    $form = parent::settingsForm();

    if (!empty($this->opt_adv_filter)) {

      // There's a slight chance that the fieldset has not been defined yet
      // (creating #weight isseus), see BatchQueueFetcher.
      if (!isset($form['selection'])) {
        $form['selection'] = array(
          '#type'   => 'fieldset',
          '#title'  => t('Data selection'),
          '#weight' => 10,
        );
      }

      // Potential security hole; this edit screen must only be available for admins.
      // TODO implement extra permission?
      $form['selection']['filters'] = array(
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

      // Extend the description of the 'cache' options (from BatchQueueFetcher),
      // IF there are cached items.
      if (!empty($form['selection']['cache_items']) && module_exists('ctools')) {
        ctools_include('object-cache');
        $items = ctools_object_cache_get('batchqueue_items', $this->settings['job_id']);
        if (is_array($items) && $items) {
          if (!empty($form['selection']['single_item'])) {
            $form['selection']['cache_items']['#description']
              .= ' ' . t('One cached item can be processed by with the \'!single_item\' option.');
          }
          $form['selection']['cache_items']['#description']
            .= ' ' . t('The Advanced filter will not have any effect when using cached items.',
            array('!single_item' => t('Update only one specific item')));
        }
      }
    }

    $form['tries'] = array(
      '#type'          => 'textfield',
      '#title'         => t('Tries'),
      '#description'   => 'The number of times to repeatedly try fetching data, in case of timeouts',
      '#default_value' => 1,
      '#size'          => 3,
      '#weight'        => 97,
    );

    return $form;
  }

  /// Extra methods for extending classes to call!

  /**
   * Implements BatchQueueFetcher::fetchItems(), extending the function with
   * some arguments.
   *
   * @param bool $return_simplexml
   *   If TRUE, returns a SimpleXMLElement object, otherwise an array of items.
   * @param array $extra_arguments
   *   Extra parameters to pass to the AFAS SOAP call (GetDataWithOptions). This
   *   is only very occasionally useful. Known parameters:
   *   array('options' => array('Outputoptions' => 3)) to also return empty
   *   fields; see AfasSoapConnection::normalizeArguments().
   *
   * @return array|string
   *   Items from AFAS, or error message.
   */
  protected function fetchItems($return_simplexml = FALSE, $extra_arguments = array()) {

    // Use property for 2nd function argument, if set.
    if (!$extra_arguments && !empty($this->settings['afas_include_empty_fields'])) {
      $extra_arguments = array('options' => array('Outputoptions' => 3));
    }

    $items = FALSE;
    $tries_left = !empty($this->settings['tries']) ? $this->settings['tries'] : 1;
    while ($items === FALSE && --$tries_left >= 0) {

      $connection = new AfasSoapConnection();
      if (!empty($this->settings['response_timeout'])
          && is_numeric($this->settings['response_timeout'])) {
        $connection->setOptions(
          array('response_timeout' => $this->settings['response_timeout'])
        );
      }
      $items = $connection->getData($this->settings['afas_connector'], $this->inputFilter(),
        $return_simplexml ?  'get_simplexml' : 'get', $extra_arguments);

      if ($items === FALSE) {
        // Retry if timeout
        $error = $connection->getLastCallInfo('error');
        if (strpos($error, 'cURL ERROR: 28') !== FALSE) {
          if ($tries_left) {
            // Do 'error' only because it positions this message in between
            // the other messages, if this is called from the batch form.
            $this->log('AFAS timeout occurred; retrying, #@try', array('@try' => $this->settings['tries'] - $tries_left), WATCHDOG_ERROR);
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
      $items = $connection->getLastCallInfo('error');
      $er = $connection->getErrorReporting();
      if ($er & AFAS_LOG_ERROR_DETAILS_WATCHDOG
          && !($er & AFAS_LOG_ERROR_DETAILS_SCREEN)) {
        // @todo this is better than not checking the above, but we do not know
        // if there are any details (like with 'AFAS environment is not set',
        // there are none.) Maybe set a counter for number of logs, or remember
        // all? Think this over.
        $items .= ' (' . t('See Recent Log Messages for more details.') . ')';
      }
    }

    return $items;
  }

  /**
   * Returns values to filter items on, according to user input. You might
   * call this from your init(), before fetching items in whatever way.
   * (Or check the properties by yourself.)
   *
   * @return array
   *   'filters array' that can be used for calling
   *   AfasSoapConnection::getData().
   */
  public function inputFilter() {
    $filters = array();

    // Add this filter 1 layer deeper than the 'simple'
    // filter arguments. AfasSoapConnection::getData() will sort it out.
    if (!empty($this->extra_filter)) {
      $filters[] = $this->extra_filter;
    }

    // Add filters from 'free text filter box' 1 layer deeper than the 'simple'
    // filter arguments. AfasSoapConnection::getData() will sort it out.
    foreach ($this->evalFilterInput() as $filter) {
      $filters[] = $filter;
    }

    return $filters;
  }

  /// Private helper functions

  /**
   * Checks for/evaluates a comma separated list of arrays in
   * $this->settings['filters'].
   * This is used for the 'Filter' textarea value in the batch form.
   *
   * @return Array of filters, or empty array.
   */
  private function evalFilterInput() {
    static $filters;

    if (!isset($filters)) {
      $filters = array();

      if (!empty($this->settings['filters'])) {
        $eval = '';
        eval('$eval = array(' . $this->settings['filters'] . ');');
        if (is_array($eval)) {
          foreach ($eval as $filter) {
            if (is_array($filter)) {
              if (!empty($filter)) {
                $filters[] = $filter;
              }
              // Just disregard filter-parts which are empty arrays.
            }
            else {
              $this->log("Invalid 'filter' (part) setting passed to inputFilter(): %p",
                array('%p' => print_r($filter, TRUE)), WATCHDOG_ERROR, FALSE);
            }
          }
        }
        else {
          $this->log("Invalid 'filters' setting passed to inputFilter(): %p",
            array('%p' => print_r($this->settings['filters'], TRUE)), WATCHDOG_ERROR, FALSE);
        }
      }
    }
    return $filters;
  }

}
