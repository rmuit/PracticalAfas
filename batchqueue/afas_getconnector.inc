<?php
/**
 * @file
 * Class providing some helper functions for Batch/Queue jobs retrieving data
 * through an AFAS GET connector. Other modules can create jobs by
 * - installing the 'batchqueue' Drupal contrib module;
 * - extending this class and exposing their extending classes through
 *   hook_queue_info().
 */
abstract class AfasGetConnector extends BatchQueueJob {

  /// Keys in $this->settings that are used by this class:
  //
  // 'response_timeout':  response_timeout to set for the nusoap_client object.

  /// Class variables which an extending class must set in order to make use of
  /// some of the helper functions. (Most simple way: hardcode in __construct();
  /// see also BatchQueueImportJob variables.)

  // Name of the GET Connector.
  protected $connector;

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

  /// Extra methods for extending classes to call!

  /**
   * Replacement for BatchQueueImportJob::getItems().
   * (Cannot override it because of different call signature.)
   *
   * Returns AFAS data, after calling predefined filter/caching functions.
   *
   * This is a convenience/example function; in many situations, you only need a
   * 'return $this->getAfasItems()' from the extending class' init() function.
   *
   * @param bool $return_array
   *   If FALSE, returns a SimpleXMLElement object, otherwise an array of items.
   * @param array $extra_arguments
   *   Extra parameters to pass to the AFAS SOAP call (GetDataWithOptions). This
   *   is only very occasionally useful. Known parameters:
   *   array('options' => array('Outputoptions' => 3)) to also return empty
   *   fields; see AfasSoapConnection::normalizeArguments().
   *
   * @return array|string
   *   Items from AFAS, or error message.
   */
  protected function getAfasItems($return_array = TRUE, $extra_arguments = array()) {

    $items = array();
    // Check if we need to use cached items.
    // SimpleXMLElement cannot be cached, so if we're supposed to get one,
    // we'll silently ignore cache settings.
    if ($return_array) {
      $items = $this->checkCache();
    }
    if (!$items) {

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
        $items = $connection->getData($this->connector, $this->inputFilter(),
          $return_array ? 'get' : 'get_simplexml', $extra_arguments);

        if ($items === FALSE) {
          // Retry if timeout
          $error = $connection->getLastCallInfo('error');
          if (strpos($error, 'cURL ERROR: 28') !== FALSE) {
            if ($tries_left) {
              // Do 'error' only because it positions this message in between the other messages,
              // if this is called from the batch form.
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
        $items = $connection->getLastCallInfo('error') . ' (See Recent Log Messages for more details.)';
      }
      elseif ($return_array) {
        // Check if we need to cache items.
        $this->checkCacheSet($items);
      }
    }

    // If $return_array is FALSE, it's up to the extending class to implement
    // 'limit'. (Probably while/after it converts items into an array.)
    if (!empty($this->settings['limit']) && $this->settings['limit'] > 0
        && count($items) > $this->settings['limit'] && $return_array) {
      $items = array_slice($items, 0, $this->settings['limit']);
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
  protected function inputFilter() {
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

  /// Interface functions

  public function __construct($settings = array()) {
    parent::__construct($settings);

    // In case an extending class sets $this->opt_list, we also pass the
    // following settings as an URL argument; see settingsForm().
    $this->form_settings_display_filter[] = 'filters';
    $this->form_settings_display_filter[] = 'all_fields';
  }

  /**
   * Extends BatchQueueImportJob::settingsForm()
   */
  public function settingsForm() {
    $form = parent::settingsForm();

    if (!empty($this->opt_adv_filter)) {
      // Potential security hole; this edit screen must only be available for admins
      $form['filters'] = array(
        '#type'          => 'textarea',
        '#rows'          => 5,
        '#title'         => t('Filter'),
        '#description'   => "Fill a comma separated set of array definitions if you want to apply other filters.<br/>
One example, to get only products that changed in the past week:<br/><em>array(<br/>
'SOME-CHANGED-DATE' => '" . date('Y-m-d\TH:i:s', time() - 7 * 86400) . "',<br/>
'#op' => '>',<br/>
),</em>",
        '#default_value' => "array(
),",
        '#weight'        => 12,
      );
    }

    $form['tries'] = array(
      '#type'          => 'textfield',
      '#title'         => t('Tries'),
      '#description'   => 'The number of times to repeatedly try fetching data, in case of timeouts',
      '#default_value' => 1,
      '#size'          => 3,
      '#weight'        => 97,
    );

    /* TODO check: could this influence the OutputOptions argument for a soap call?
    $form['export']['include_empty'] = array(
      '#type' => 'checkbox',
      '#value' => t('Include empty fields'),
    );*/
    return $form;
  }

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
