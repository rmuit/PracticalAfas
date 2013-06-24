<?php
/**
 * @file
 * Class providing some helper functions for Batch/Queue jobs importing data
 * through an AFAS GET connector. Other modules can create import jobs by
 * - installing the 'batchqueue' Drupal contrib module;
 * - extending this class and exposing their extending classes through
 *   hook_queue_info().
 *
 * This class is a companion class of AfasGetConnector (and unfortunately has
 * all code from AfasGetConnector copied into here, because there's no multiple
 * inheritance in PHP5). Use AfasImportJob or AfasGetConnector, depending on
 * whether you need the import functionality.
 */
abstract class AfasImportJob extends BatchQueueImportJob {

// AfasGetConnector 1 start
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
  // understood by afas_api_get_data(). Set to TRUE to enable in settingsForm.
  protected $opt_adv_filter;
// AfasGetConnector 1 end -- Custom 1 start

  // Boolean: Checkbox 'skip existing images'. Set to TRUE to enable in
  // settingsForm.
  // Note this option only determines presence of the checkbox. The actual
  // setting governing runtime behavior is $setting['skip_images'].
  // (This is not remembered anywhere in $context, so cron runs will never
  // have it set.) The default behavior (if option/setting is not set) is TRUE.
  protected $opt_skip_images;

  // Class-wide variables which are filled once per processItem() run
  // and used by its various called functions to make the code easier:
  //
  // Cache for image data retrieved from another connector in transformApiFieldValue().
  protected $imgcache;
// Custom 1 end -- AfasGetConnector 2 start

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
   *   Extra parameters to pass to the AFAS SOAP call (GetDataWithOptions). This is
   *   only very occasionally useful. Known parameters:
   *   'Metadata' and 'OutputOptions'; see _afas_api_normalize_arguments().
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

        $connection_options = array();
        if (!empty($this->settings['response_timeout'])
            && is_numeric($this->settings['response_timeout'])) {
          $connection_options =
            array('response_timeout' => $this->settings['response_timeout']);
        }

        $items = afas_api_get_data($this->connector, $this->inputFilter(),
          $return_array, $extra_arguments, $connection_options);

        if ($items === FALSE) {
          // Retry if timeout
          $error = afas_api_last_call_info('error');
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
        // our own drupal_set_message. Don't set 'print_errors' in afas_api; just
        // ignore $this->print_errors.
        // More details are always logged to watchdog.
        $items = afas_api_last_call_info('error') . ' (See Recent Log Messages for more details.)';
      }
      elseif ($return_array) {
        // Check if we need to cache items.
        $this->checkCacheSet($items);
      }
    }
// AfasGetConnector 2 end -- Custom 2 start
    elseif (!empty($this->settings['single_item'])
            && !empty($this->src_id_field)) {

      // Filter cached items. There could theoretically be multiple items
      // with the same source-id field value, so loop through all items.
      $new_items = array();
      foreach ($items as $item) {
        if (!empty($item[$this->src_id_field]) &&
            $item[$this->src_id_field] == $this->settings['single_item']
        ) {
          $new_items[] = $item;
        }
      }
      $items = $new_items;
    }
// Custom 2 end -- AfasGetConnector 3 start

    // If $return_array is FALSE, it's up to the extending class to implement
    // 'limit'. (Probably while/after it converts items into an array.)
    if (!empty($this->settings['limit']) && $this->settings['limit'] > 0
        && count($items) > $this->settings['limit'] && $return_array) {
      $items = array_slice($items, 0, $this->settings['limit']);
    }
    return $items;
  }

  /**
   * Extends BatchQueueImportJob::inputFilter() with AFAS specific filters.
   *
   * @return array
   *  'filters array' that can be used for calling afas_api_get_data().
   */
  protected function inputFilter() {
    $filters = array();
// AfasGetConnector 3 end -- Custom 3 start
    $filters = parent::inputFilter();
// Custom 3 end -- AfasGetConnector 4 start

    // Add this filter 1 layer deeper than the 'simple'
    // filter arguments. afas_api_get_data() will sort it out.
    if (!empty($this->extra_filter)) {
      $filters[] = $this->extra_filter;
    }

    // Add filters from 'free text filter box' 1 layer deeper than the 'simple'
    // filter arguments. afas_api_get_data() will sort it out.
    foreach ($this->evalFilterInput() as $filter) {
      $filters[] = $filter;
    }

    return $filters;
  }

  /// Interface functions

  public function __construct($settings = array()) {
    parent::__construct($settings);

    // AFAS is typically a system where
    // - you are bound to input data with trailing spaces (apparently
    //   at least the 'predefined lists' do not protect against this);
    // - all your data are strings (XML input stream)
    // so trim data to prevent obscure bugs.
    $this->trim_item_values = TRUE;

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
// AfasGetConnector 4 end -- Custom 4 start

    if ($this->opt_skip_images) {
      $form['behavior']['skip_images'] = array(
        '#type'          => 'checkbox',
        '#title'         => t('Skip existing images'),
        '#description'   => t('If checked, only images which do not exist yet on this website, will be transferred from the source system. This saves time. If want to (re)transfer & update all images, uncheck this option.'),
        '#default_value' => 1,
        '#weight'        => 2,
      );
    }
// Custom 4 end -- AfasGetConnector 5 start BUT CHANGED next form element

    if (!empty($this->opt_adv_filter)) {
      $field = !empty($this->changed_field) ? $this->changed_field : 'SOME-CHANGED-DATE';
      // Potential security hole; this edit screen must only be available for admins
      $form['selection']['filters'] = array(
        '#type'          => 'textarea',
        '#rows'          => 5,
        '#title'         => 'Filter',
        '#description'   => "Fill a comma separated set of array definitions if you want to apply other filters.<br/>
One example, to get only products that changed in the past week:<br/><em>array(<br/>
'$field' => '" . date('Y-m-d\TH:i:s', time() - 7 * 86400) . "',<br/>
'#op' => '>',<br/>
),</em>",
        '#default_value' => "array(
),",
        '#weight'        => 12,
      );
    }

    $form['tries'] = array(
      '#type'          => 'textfield',
      '#title'         => 'Tries',
      '#description'   => 'The number of times to repeatedly try fetching data, in case of timeouts',
      '#default_value' => 1,
      '#size'          => 3,
      '#weight'        => 97,
    );

    return $form;
  }
// AfasGetConnector 5 end -- Custom 5 start

  /**
   * Extends BatchQueueImportJob::initContext().
   */
  public function initContext(&$items, &$context) {
    parent::initContext($items, $context);

    if ($context['results']['unpub_after_finish']) {
      // Like BatchQueueImportJob: only do this for unfiltered feeds.
      $filters = $this->evalFilterInput();
      $context['results']['unpub_after_finish'] = empty($filters);
    }
  }

  /**
   * Extends BatchQueueImportJob::processItem
   */
  public function processItem($item, &$context) {
    $this->imgcache = array();
    parent::processItem($item, $context);
  }

  /// Extending non-interface functions

  /**
   * Extends BatchQueueImportJob::addApiFieldValue().
   */
  protected function addApiFieldValue(&$added_values, $value, $source_field) {

    // We handle type 'image' here, and refer to the parent for other types.

    $dst = $this->current_dst;
    if (!isset($this->field_info[$dst])) {
      // The caller is responsible for making sure we never end up here!
      $this->log('addApiValue() was called for destination field %dst, which is not an API-Field for entity type %t. Skipping. (source field: %src)',
        array('%t' => $this->entity_type, '%dst' => $dst, '%src' => $source_field), WATCHDOG_ERROR);
      return;
    }
    $field_info = $this->field_info[$dst];

    // Mapping may not be present for 'unknown source' (e.g. default) values.
    if (isset($this->dest_field_mapping[$dst])) {
      $dst_mapping = $this->dest_field_mapping[$dst];
    }
    else {
      $dst_mapping = array();
      if ($source_field) {
        // The caller is responsible for making sure we never end up here!
        $this->log('addApiValue() was called for destination field %dst, which has no field mapping defined. Continuing because the value is already known, but this may be an error in your code. (values passed: source field: %src; value %val)',
          array('%val' => $value, '%dst' => $dst, '%src' => $source_field), WATCHDOG_WARNING);
      }
    }

    if (isset($dst_mapping["process:$source_field"])) {
      $process = $dst_mapping["process:$source_field"];
    }
    else {
      // This will only happen if $source_field = '', for default values.
      $process = array();
    }
    $src = $source_field ? $source_field : '<none>';

    switch ($field_info['type']) {

      case 'image':
        $file = FALSE;
        if (empty($process['folder'])) {
          $this->log("No 'folder' property defined in mapping for the %dst destination field. (source field: %src)",
            array('%dst' => $dst, '%src' => $src), WATCHDOG_ERROR);
        }
        elseif (empty($process['data_src'])) {
          $this->log("No 'data_src' property defined in mapping for the %dst destination field. (source field: %src)",
            array('%dst' => $dst, '%src' => $src), WATCHDOG_ERROR);
        }
        else {
          // We need an index to make the filename unique, because the source
          // filenames may be duplicate, and there are multiple source fields.
          // We'll turn FILENAME into SRC-ID_sourcefieldindex_FILENAME.
          $index = 1;
          foreach ($this->dest_field_mapping[$dst]['src'] as $s) {
            if ($source_field == $s) {
              break;
            }
            $index++;
          }

          $dest_uri = file_default_scheme() . '://' . $process['folder'] . '/'
                      . $this->src_item[$this->src_id_field] . '_' . $index
                      . '_' . $value;
          // If a file with a certain name already exists on disk, it is not
          // written again. But then its data does not need to be retrieved.
          if ((isset($this->settings['skip_images'])
               && empty($this->settings['skip_images']))
              || !$file = $this->checkFile($dest_uri)
          ) {
            // We need to save the file.

            // 'data_src' represents a field in either this, or a separate,
            //  data set. Fetch data with separate connector if not done yet.
            if (empty($process['data_connector'])) {
              // File data should come from this same get connector.
              // If it's not here, that's an error (since the filename _is_
              // defined.)
              if (!isset($this->src_item[$process['data_src']])) {
                $this->log("Image data field %datafield not found (for item %item), and no 'data_connector' property defined in mapping for %dst destination field. (source field: %src)",
                  array(
                    '%datafield' => $process['data_src'],
                    '%item' => $this->src_item[$this->src_id_field],
                    '%dst' => $dst,
                    '%src' => $src
                  ), WATCHDOG_ERROR);
                return;
              }
              $data = $this->src_item[$process['data_src']];
            }
            else {
              // File data should come from different get connector.

              if (!isset($this->imgcache[$process['data_connector']])) {
                // Image data for this item is not cached yet; get it.

                if (!$items = afas_api_get_data($process['data_connector'],
                  array('Itemcode' => $this->src_item[$this->src_id_field]),
                  FALSE)
                ) {
                  // AFAS error was already logged (probably to watchdog; we
                  // do not change print_error settings), but we don't know
                  // for which image.
                  $this->log('Error while fetching image data for file %file (for item %item, field %src); see system log for details.',
                    array(
                      '%file' => $value,
                      '%item' => $this->src_item[$this->src_id_field],
                      '%src' => $src
                    ), WATCHDOG_ERROR);
                  // Perform 'negative caching' (see below).
                  $this->imgcache[$process['data_connector']] = FALSE;
                  return;
                }
                // Assume you're receiving only one item (from SimpleXMLElement
                // with unknown key). Cast this item (which is also Element)
                // to array so we don't need to cast to strings later.
                $this->imgcache[$process['data_connector']] =
                  (array) reset($items);
              }

              if ($this->imgcache[$process['data_connector']] === FALSE) {
                // afas_api_get_data() failed earlier; don't try again.
                return;
              }
              if (!isset($this->imgcache[$process['data_connector']][$process['data_src']])) {
                $this->log('Image data field %datafield not found in connector %con (for item %item, field %src)',
                  array(
                    '%datafield' => $process['data_src'],
                    '%con' => $process['data_connector'],
                    '%item' => $this->src_item[$this->src_id_field],
                    '%src' => $src
                  ), WATCHDOG_ERROR);
                return;
              }
              $data = $this->imgcache[$process['data_connector']][$process['data_src']];
            }

            if ($data) {
              // Prepare directory. file_save_data()->file_unmanaged_copy()
              // does not create the directory by default.
              $dirname = drupal_dirname($dest_uri);
              if (!file_prepare_directory($dirname, FILE_MODIFY_PERMISSIONS + FILE_CREATE_DIRECTORY)) {
                $this->log('Directory %dir could not be created; file %file (from Item %item, field %src) could not be saved to destination %dest.',
                  array(
                    '%dir'  => $dirname,
                    '%item' => $this->src_item[$this->src_id_field],
                    '%file' => $value,
                    '%dest' => $dest_uri,
                    '%src' => $src
                  ), WATCHDOG_ERROR);
              }
              else {
                // FILE_EXISTS_REPLACE, because the file might exist even
                // though_afas_get_products_checkFile() returns FALSE (if it
                // is not present in [file_managed]). In that case, the file
                // will be bluntly overwritten; we assume the folder is 'ours'
                // exclusively.
                $file = file_save_data(base64_decode($data), $dest_uri, FILE_EXISTS_REPLACE);
                if ($file) {
                  // In case an old image existed in this location:
                  // Delete any image derivatives.
                  image_path_flush($file->uri);
                }
                else {
                  $this->log('File %file (from Item %item, field %source_field) could not be saved to destination %dest. See other errors for details.',
                    array(
                      '%item' => $this->src_item[$this->src_id_field],
                      '%file' => $value,
                      '%dest' => $dest_uri,
                      '%src' => $src
                    ), WATCHDOG_ERROR);
                }
              }
            }

          } // endif $file does not exist yet
        } // endif 'folder' defined (else fall through)

        if ($file) {
          $added_values[] = array(
            'fid'   => $file->fid,
            // alt & title attributes are apparently '' by default, even though the fields are nullable.
            // So always fill them.
            // TODO if fid already exists, then the default value should not be <empty>, but
            // <the value currently in Drupal>
            'alt'   => !empty($process['alt_src']) && isset($this->src_item[$process['alt_src']])
              ? $this->src_item[$process['alt_src']] : '',
            'title' => !empty($process['title_src']) && isset($this->src_item[$process['title_src']])
              ? $this->src_item[$process['title_src']] : '',
          );
        }
        break;

      default:

        parent::addApiFieldValue($added_values, $value, $source_field);

    }
  }

  /// Private helper functions.

  /**
   * Checks if a file in a certain folder exists; return file object.
   *
   * @param string $dest_uri
   *   Destination uri (file name as per Drupal's scheme/naming convention).
   *
   * @return bool|object
   *   Drupal file object (from database table), or false.
   */
  private function checkFile($dest_uri) {
    $file = FALSE;

    // Check if the file is already managed by Drupal.
    $fid = db_select('file_managed', 'fm')
      ->fields('fm', array('fid'))
      ->condition('fm.uri', $dest_uri)
      ->execute()
      ->fetchColumn();

    // If it is managed by Drupal, we will try to load the full file object.
    if ($fid) {
      if (file_exists($dest_uri)) {
        $file = file_load($fid);
      }
      else {
        // File doesn't exist on disk: clear stale records from DB.
        db_delete('file_managed')->condition('fid', $fid)->execute();
        db_delete('file_usage')->condition('fid', $fid)->execute();
      }
      if (!empty($file) && $file->filesize < 1) {
        // Currently stored file is no good: delete it.
        file_delete($file);
        // From older version. I don't know why a resetCache would be necessary -
        // supposedly file_delete() would take care not to leave things in a strange state?
        //entity_get_controller('file')->resetCache();
        $file = FALSE;
      }
    }

    return $file;
  }
// Custom 5 end -- AfasGetConnector 6 start

  /**
   * Checks for/evaluates a comma separated list of arrays in
   * $this->settings['filters'].
   * This is used for the 'Filter' textarea value in the batch form.
   *
   * @return array
   *   Array of filters, or empty array.
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
