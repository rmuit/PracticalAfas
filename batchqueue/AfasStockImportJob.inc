<?php
/**
 * @file
 * Class providing a functional implementation of import for stock data from
 * AFAS into commerce_stock and/or any FieldAPI field.
 *
 * The only thing that is missing (except for defining the GET connector for
 * stock inside AFAS) is initialization of all variables (connector, source and
 * destination fields) by the constructor.
 * TODO: if we implement a configForm, this can be a non-abstract class.
 *
 * TODO: if we implement the 'fetch' part of imports into a different class:
 *       reevaluate whether this needs depencency on AfasImportJob. We only use
 *       __construct(), (part of) settingsForm(), and a few properties.
 */
abstract class AfasStockImportJob extends AfasImportJob {

  // Field containing stock value in the source items.
  protected $stock_field;

  // Field containing warehouse values. If you have stock in only one place
  // (warehouse), you don't need to define this field.
  protected $warehouse_field;
  //
  // Note that only one stock value is processed for each source item/warehouse
  // combination. If there are multiple rows per item/warehouse, you'll likely
  // need to change the query to AFAS to get only the stock values you need.
  // (e.g. by setting $this->extra_filter.)

  // Destination field(s) in entity, which should be filled _besides_ the
  // commerce_stock field. May be left empty.
  // If not empty:
  // If $warehouse_field is undefined, $stock_dest must be a string.
  //    (providing an array will _currently_ not give an error, but only the
  //    first array value == destination field is used.)
  // If $warehouse_field is defined, $stock_dest can be an array, where
  //   key = any value inside $warehouse_field in the AFAS data. (Values for
  //         warehouses not defined as keys in $stock_dest will be ignored!)
  //   value = destination field for that particular warehouse.
  // If $warehouse_field is defined and $stock_dest is a string, all stock
  //   amounts will be added up and filled in this field.
  protected $stock_dest;

  // If the commerce_stock module is enabled AND $this->entity_type is
  // 'commerce_product', then the stock amounts (added up if there are multiple
  // values per destination entity) will be filled automatically.
  // Set this property to TRUE if you don't want that.
  protected $ignore_commerce_stock;

  public function __construct($settings) {
    parent::__construct($settings);

    ///(Didn't place the follosing in __construct because this is an abstract class now.
    // if abstract classes can have constructors, then change this.)

    // Our parent sets src_id_field and changed_field; we don't have the latter
    // so we need to unset it, to suppress the 'Skip unchanged' settingsForm item.
    unset($this->changed_field);
    // We fetch SimpleXMLElement, not array (for speed reasons), but that can't be cached.
    $this->opt_cache_items = FALSE;

    $this->opt_list = array(
      'raw'  => 'Raw data as received from AFAS',
      'used' => 'Data used for updating'
    );
  }

  /**
   * Extends AfasImportJob::settingsForm()
   */
  public function settingsForm() {

    $form = parent::settingsForm();

    // Since this run/settings form is the one interactive thing in the process,
    // it's also a good place for global checks that should generate a user error.

    // Check properties/mappings that are absolutely necessary for the code to run;
    // see processItem(). Don't use log() here, since we never want this to end up
    // in watchdog.
    // BatchQueueImportJob checks for entity_type / bundle / src_id_field already.
    if (empty($this->src_id_field)) {
      drupal_set_message("'Source ID field' property is not defined in the AFASStockConnector class! The process will not run until this is fixed!", 'warning');
    }
    if (empty($this->stock_dest) && !module_exists('commerce_stock')) {
      drupal_set_message("'Destination field' is not defined in the AFASStockConnector class (and commerce_stock module is not enabled). The process will not run until this is fixed!", 'warning');
    }
    if (empty($this->src_id_dest_field) && $this->entity_type != 'commerce_product') {
      drupal_set_message("'src_id_dest_field' property not set (necessary for resolving source->Drupal items). The process will not run until this is fixed!", 'warning');
    }

    return $form;
  }

  /**
   * Implements BatchQueueJobInterface::init()
   */
  public function init(&$context) {

    // Don't check _everything_ here so the class is still useful for
    // list_format = raw. More checks in processItem().
    if (!empty($this->connector)
        && !empty($this->src_id_field) && !empty($this->stock_field)) {

      // Callers can set 'list_format' setting to 'raw' to retrieve unmodified
      // result from the AFAS query; this is not fit for processItem().
      // If 'raw', we get getAfasItems() to convert the SimpleXMLElement
      // to an array for us. Otherwise we do the conversion to a
      // (differently structured) array ourseleves.
      $raw = (!empty($this->settings['list_format'])
              && $this->settings['list_format'] == 'raw');
      $afas_items = $this->getAfasItems($raw);

      if (!empty($afas_items) && !is_string($afas_items) && !$raw) {

        // Construct an array of items, in a structure as expected by
        // processitem(). (The structure differs depending on warehouse_field
        // being filled - not on dest_id being an array.)
        $items = array();
        $i=0;
        foreach ($afas_items as $afas_item) {
          $i++;
          $sku = (string)$afas_item->{$this->src_id_field};
          if ($sku === '') {
            $this->log('Source-id value for item #@nr is empty; skipping.',
              array('@nr' => $i));
          }
          // Implement 'single item' filter
          elseif (empty($this->settings['single_item']) ||
                  $sku == $this->settings['single_item']) {

            $stock = (string)$afas_item->{$this->stock_field};
            if (!is_numeric($stock)) {
              $this->log('Stock value for item @sku (@stock) is not a number; skipping.',
                array('@sku' => $sku, '@stock' => $stock));
            }
            else {
              if (empty($this->warehouse_field)) {
                // We have what we need: an array with sku & stock values.
                // But since we want to prevent superfluous data being moved around in
                // queue tables (and who knows what fields are defined by the connector)
                // let's make an array with only sku + stock fields. Hardcoded keys.
                // The keys will not be used by the caller; we use them to warn about
                // duplicate data rows.
                if (isset($items[$sku])) {
                  $this->log('Source-id value for item #@nr (@sku) already encountered in earlier item; skipping this item.',
                    array('@nr' => $i, '@sku' => $sku), WATCHDOG_WARNING);
                }
                else {
                  $items[$sku] = array('src_id' => $sku,
                                       'stock'  => $stock >= 0 ? $stock: 0);
                }
              }
              else {

                // One item can have stock values in several warehouses, which
                // we have as separate rows. Build stock _array_ in one item.
                $warehouse = (string)$afas_item->{$this->warehouse_field};
                if (empty($warehouse)) {
                  $this->log('Warehouse value for item @sku is empty; skipping.',
                    array('@sku' => $sku));
                }
                elseif (isset($items[$sku]['stock'][$warehouse])) {
                  $this->log('Source-id/warehouse combination for item #@nr (@sku/@warehouse) already encountered in earlier item; skipping this item.',
                    array('@nr' => $i, '@sku' => $sku, '@warehouse' => $warehouse), WATCHDOG_WARNING);
                }
                else {
                  if (!isset($items[$sku])) {
                    $items[$sku] = array('src_id' => $sku);
                  }
                  if (!empty($this->settings['list_format'])) {
                    // For display: make flat array.
                    $items[$sku][$warehouse] = $stock >= 0 ? $stock: 0;
                  }
                  else {
                    $items[$sku]['stock'][$warehouse] = $stock >= 0 ? $stock: 0;
                  }
                }
              }
            }
          }

          // Implement 'limit' filter
          if (!empty($this->settings['limit']) && $this->settings['limit'] > 0
              && count($items) >= $this->settings['limit']) {
            break;
          }
        }

        // Now, $items may or may not have alphanumeric keys. That's OK; the
        // BatchQueue API module explicitly ignores/discards those.
        return $items;
      }
      else {
        // Empty data set, error condition or raw format requested.
        return $afas_items;
      }
    }
    else {
      return 'At least one of connector / src_id_field / stock_field properties are not defined; cannot fetch stock';
    }
  }

  /**
   * Overrides AfasImportJob::initContext()
   */
  public function initContext(&$items, &$context) {

    // Add all keys we're using to the context array.
    $context['results'] = array_merge($context['results'],
      array(
        'dest_ids'   => array(),
        'updated'    => 0,
        'skipped'    => 0,
        'notpresent' => 0,
        'error'      => 0,
      )
    );

    // Note: no call to parent. (Mainly because we have no 'mapping'.)

    // This is why we need to check the following explicitly:
    if (empty($this->src_id_dest_field) && $this->entity_type != 'commerce_product') {
      $this->log("'src_id_dest_field' property not set (necessary for resolving source->Drupal items)!", array(), WATCHDOG_WARNING);
      $items = array();
    }
  }

  /**
   * Overrides AfasImportJob::processItem()
   */
  public function processItem($item, &$context) {

    // Check for properties needed to run properly.
    if ((!empty($this->stock_dest) || module_exists('commerce_stock'))
        && !empty($this->entity_type)) {

      if (empty($this->src_id_dest_field)) {
        if ($this->entity_type == 'commerce_product') {
          $this->src_id_dest_field = 'sku';
        }
        else {
          return;
        }
      }

      $dest_ids = $this->resolveDestIds(array($item['src_id']));

      if ($dest_ids) {
        // Remember encountered entities for finish().
        // Note resolveDestIDs does not cache things by itself, since we did not
        // set $this->destIds. We won't use that caching because:
        // - we never re-query the same ID again anyway;
        // - we need a simpler structure in finish (a flat array, not a two-
        //   dimensional array keyed by source ID)
        // - minor: the called code inside resolveDestIDs needs to do more work
        //   to cache the less-simple structure, and then convert to the simple
        //   structure for its return value.
        // A subclass can set $this->results to activate caching, but then needs
        // to rewrite the use of dest_ids in finish().
        foreach ($dest_ids as $id) {
          // This is faster than an array_merge, because $dest_ids is small.
          $context['results']['dest_ids'][] = $id;
        }

        $entities = entity_load($this->entity_type, $dest_ids);
        if ($entities) {
          foreach ($entities as $entity) {
            $product_wrapper = entity_metadata_wrapper($this->entity_type, $entity);

            // This code assumes there are _always_ field values to update, and
            // _optionally_ a dedicated commerce_stock value.

            // We'll check current values before overwriting. It's more code
            // intensive but saves having to save the product unnecessarily.
            $changed = FALSE;

            if (is_array($item['stock'])) {
              if (is_array($this->stock_dest)) {
                // Update values for multiple warehouses into multiple fields.
                foreach ($this->stock_dest as $warehouse => $dest_field) {
                  $stock = empty($item['stock'][$warehouse]) ? 0 : $item['stock'][$warehouse];
                  if (isset($product_wrapper->$dest_field) &&
                      $product_wrapper->{$dest_field}->value() != $stock) {
                    $product_wrapper->$dest_field->set($stock);
                    $changed = TRUE;
                  }
                }
              }
              elseif (!empty($this->stock_dest)) {
                // We have (possibly) multiple stock values but only one destination.
                // See comment about amount just below.
                if (isset($product_wrapper->{$this->stock_dest}) &&
                    $product_wrapper->{$this->stock_dest}->value() != array_sum($item['stock'])) {
                  $product_wrapper->{$this->stock_dest}->set( array_sum($item['stock']) );
                  $changed = TRUE;
                }
              }

              // Update commerce stock. If there are stock values in $item whose
              // keys are not in $this->stock_dest, they will still be counted in
              // the total stock, while not being updated into a separate field.
              if ($this->entity_type == 'commerce_product'
                  && empty($this->ignore_commerce_stock)
                  && module_exists('commerce_stock')) {
                if (isset($product_wrapper->commerce_stock) &&
                    $product_wrapper->commerce_stock->value() != array_sum($item['stock'])) {
                  $product_wrapper->commerce_stock->set( array_sum($item['stock']) );
                  $changed = TRUE;
                }

              }
            }

            else {
              if (is_array($this->stock_dest)) {
                // One stock value, multiple destination fields. Hmm... take the
                // first field which exists.
                foreach ($this->stock_dest as $dest_field) {
                  if (isset($product_wrapper->{$dest_field})) {
                    if ($product_wrapper->{$dest_field}->value() != $item['stock']) {
                      $product_wrapper->{$dest_field}->set( $item['stock'] );
                      $changed = TRUE;
                    }
                    break;
                  }
                }
              }
              elseif (!empty($this->stock_dest)) {
                // Set one stock value in one destination field.
                if (isset($product_wrapper->{$this->stock_dest}) &&
                    $product_wrapper->{$this->stock_dest}->value() != $item['stock']) {
                  $product_wrapper->{$this->stock_dest}->set( $item['stock'] );
                  $changed = TRUE;
                }
              }

              // Update commerce stock
              if ($this->entity_type == 'commerce_product'
                  && empty($this->ignore_commerce_stock)
                  && module_exists('commerce_stock')) {
                if (isset($product_wrapper->commerce_stock) &&
                    $product_wrapper->commerce_stock->value() != $item['stock']) {
                  $product_wrapper->commerce_stock->set($item['stock']);
                  $changed = TRUE;
                }
              }
            }

            if ($changed) {
              entity_save($this->entity_type, $entity);
              $context['results']['updated']++;
            }
            else {
              $context['results']['skipped']++;
            }
          }
        }
        else {
          $context['results']['error']++;
        }
      }
      else {
        $context['results']['notpresent']++;
      }
    }
  }

  /**
   * Overrides AfasImportJob::finish()
   */
  public function finish(&$results) {

    $updated_to_0 = 0;
    if (!empty($results['dest_ids']) && empty($this->settings['single_item'])
        && empty($this->settings['limit'])) {

      if (empty($this->bundle)) {
        $this->log("'bundle' property not set; the stock for items missing in the AFAS feed cannot be updated to 0.", array(), WATCHDOG_WARNING);
      }
      else {
        // only for code readability:
        $type = $this->entity_type;

        // Update stock field(s) of entities not encountered in feed.
        if (is_array($this->stock_dest)) {
          $fields = $this->stock_dest;
        }
        elseif (!empty($this->stock_dest)) {
          $fields = array($this->stock_dest);
        }
        else {
          $fields = array();
        }
        if ($type == 'commerce_product' && empty($this->ignore_commerce_stock)
            && module_exists('commerce_stock')) {
          $fields[] = 'commerce_stock';
        }

        $qstub = new EntityFieldQuery();
        $qstub->entityCondition('entity_type', $type)
          ->entityCondition('bundle', $this->bundle);
        //->propertyCondition('status', 1)
        //No, this would mean that stock of re-published products is not OK.

        // Get all the entities that have ANY of the fields non-zero.
        // EFQ has no 'OR', so use multiple queries.
        $to_load = array();
        foreach ($fields as $field) {
          $query = clone $qstub;
          $qres = $query->entityCondition('entity_id', $results['dest_ids'], 'NOT IN')
            ->fieldCondition($field, 'value', 0, '!=')
            ->execute();
          if ($qres) {
            // Remember entities to load. Add them to $results['dest_ids']
            // to prevent duplicates.
            $to_load = array_merge($to_load, array_keys($qres[$type]));
            $results['dest_ids'] = array_merge($results['dest_ids'], array_keys($qres[$type]));
          }
        }
        if ($to_load) {
          // Update all fields in all entities to 0.
          foreach (entity_load($type, $to_load) as $entity) {
            $p_wrapper = entity_metadata_wrapper($type, $entity);

            foreach ($fields as $field) {
              $p_wrapper->{$field}->set(0);
            }
            entity_save($type, $entity);
            $updated_to_0++;
          }
        }
      }
    }

    // Return status message.

    // always 'updated', also if 0
    $results['updated'] += $updated_to_0;
    $message = format_plural($results['updated'], '1 product updated', '@count products updated');
    if ($updated_to_0) {
      $message .= ' (of which ' . $updated_to_0 . ' are now 0)';
    }
    if ($results['skipped']) {
      $message .= ', ' . $results['skipped'] . ' unchanged';
    }
    if ($results['error']) {
      $message .= ', ' . $results['error'] . ' load errors';
    }
    if ($results['notpresent']) {
      $message .= ', ' . $results['notpresent'] . ' not present';
    }
    $message .= '.';
    return $message;
  }
}
