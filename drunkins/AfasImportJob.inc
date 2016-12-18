<?php

use SimpleAfas\Connection;

/**
 * Class providing some helper functions for Drunkins jobs importing AFAS data.
 *
 * See drunkins module.
 *
 * This should actually be some kind of 'plugin' functionality (in the way the
 * fetcher is plugin-ish), instead of a child class. Because we don't know
 * which class we want to extend. (Could be any of the Drunkins standard import
 * classes which by now do not form a straight line of inheritance, but a tree.)
 */
class AfasImportJob extends DrunkinsEntityImportJob {

  /* Settings in $this->settings, used by this class:
   *
   * - opt_skip_images (boolean, TRUE by default / if not set):
   *   Enables 'skip_images' setting in settingsForm.
   * - skip_images (boolean):
   *   See settingsForm() description.
   */

  // Class-wide variables which are filled once per processItem() run
  // and used by its various called functions to make the code easier:
  //
  // Cache for image data retrieved from a separate connector in
  // buildApiFieldValue().
  protected $imgcache;

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
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm() {
    $form = parent::settingsForm();

    if ($this->settings['opt_skip_images']) {
      $form['behavior']['skip_images'] = array(
        '#type'          => 'checkbox',
        '#title'         => t('Skip existing images'),
        '#description'   => t('If checked, only images which do not exist yet on this website, will be transferred from the source system. This saves time. If want to (re)transfer & update all images, uncheck this option.'),
        '#default_value' => 1,
        '#weight'        => 2,
      );
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($item, array &$context) {
    $this->imgcache = array();
    parent::processItem($item, $context);
  }

  /**
   * {@inheritdoc}
   */
  protected function buildApiFieldValues($value, array $current_built_value = array(), $source_field = '') {

    // We handle type 'image' here, and refer to the parent for other types.

    $dst = $this->current_dst;
    $field_info = field_info_field($this->current_dst_field);
    if (!isset($field_info)) {
      // The caller is responsible for making sure we never end up here!
      $this->log('addApiValue() was called for destination field %dst, which is not an API-Field for entity type %t. Skipping. (source field: %src)',
        array('%t' => $this->settings['import_entity_type'], '%dst' => $dst, '%src' => $source_field), WATCHDOG_ERROR);
      return array();
    }

    // Mapping may not be present for 'unknown source' (e.g. default) values.
    if (isset($this->context['import_field_mapping'][$dst])) {
      $dst_mapping = $this->context['import_field_mapping'][$dst];
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
          foreach ($this->context['import_field_mapping'][$dst]['src'] as $s) {
            if ($source_field == $s) {
              break;
            }
            $index++;
          }

          $dest_uri = file_default_scheme() . '://' . $process['folder'] . '/'
                      . $this->src_item[$this->context['import_src_pkey']] . '_' . $index
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
                    '%item' => $this->src_item[$this->context['import_src_pkey']],
                    '%dst' => $dst,
                    '%src' => $src
                  ), WATCHDOG_ERROR);
                return array();
              }
              $data = $this->src_item[$process['data_src']];
            }
            else {
              // File data should come from different get connector.

              if (!isset($this->imgcache[$process['data_connector']])) {
                // Image data for this item is not cached yet; get it.
                module_load_include('php', 'afas_api', 'lib/src/Connection');
                $client_class = !empty($this->settings['afas_client_class']) ? $this->settings['afas_client_class']
                  : variable_get('afas_api_client_class', 'DrupalSoapNtlmClient');
                $client_options = isset($this->settings['afas_client_options']) && is_array($this->settings['afas_client_options']) ? $this->settings['afas_client_options'] : array();
                if (!empty($this->settings['fetcher_timeout']) && is_numeric($this->settings['fetcher_timeout'])) {
                  // Option only works for AfasNusoapClient but that's fine.
                  $client_options['response_timeout'] = $this->settings['fetcher_timeout'];
                }
                $connection = new Connection($client_class, $client_options);

                if (!$items = $connection->getData($process['data_connector'],
                  array('Itemcode' => $this->src_item[$this->context['import_src_pkey']]),
                  'get_simplexml')
                ) {
                  // AFAS error was already logged (probably to watchdog; we
                  // do not change print_error settings), but we don't know
                  // for which image.
                  $this->log('Error while fetching image data for file %file (for item %item, field %src); see system log for details.',
                    array(
                      '%file' => $value,
                      '%item' => $this->src_item[$this->context['import_src_pkey']],
                      '%src' => $src
                    ), WATCHDOG_ERROR);
                  // Perform 'negative caching' (see below).
                  $this->imgcache[$process['data_connector']] = FALSE;
                  return array();
                }
                // Assume you're receiving only one item (from SimpleXMLElement
                // with unknown key). Cast this item (which is also Element)
                // to array so we don't need to cast to strings later.
                $this->imgcache[$process['data_connector']] =
                  (array) reset($items);
              }

              if ($this->imgcache[$process['data_connector']] === FALSE) {
                // AfasSoapConnection::getData() failed earlier; don't try again.
                return array();
              }
              if (!isset($this->imgcache[$process['data_connector']][$process['data_src']])) {
                $this->log('Image data field %datafield not found in connector %con (for item %item, field %src)',
                  array(
                    '%datafield' => $process['data_src'],
                    '%con' => $process['data_connector'],
                    '%item' => $this->src_item[$this->context['import_src_pkey']],
                    '%src' => $src
                  ), WATCHDOG_ERROR);
                return array();
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
                    '%item' => $this->src_item[$this->context['import_src_pkey']],
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
                      '%item' => $this->src_item[$this->context['import_src_pkey']],
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
          return array(array(
            'fid'   => $file->fid,
            // alt & title attributes are apparently '' by default, even though
            // the fields are nullable. So always fill them.
            // TODO if fid already exists, then the default value should not be
            //      <empty>, but <the value currently in Drupal>
            'alt'   => !empty($process['alt_src']) && isset($this->src_item[$process['alt_src']])
              ? $this->src_item[$process['alt_src']] : '',
            'title' => !empty($process['title_src']) && isset($this->src_item[$process['title_src']])
              ? $this->src_item[$process['title_src']] : '',
          ));
        }
        break;

      default:

        return parent::buildApiFieldValues($value, $current_built_value, $source_field);

    }

    return array();
  }

  /**
   * Checks if a file in a certain folder exists; return file object.
   *
   * @param string $dest_uri
   *   Destination uri (file name as per Drupal's scheme/naming convention).
   *
   * @return bool|object
   *   Drupal file object (from database table), or false.
   */
  protected function checkFile($dest_uri) {
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

}
