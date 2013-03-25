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
 */
abstract class AfasStockCombinationJob extends AfasImportJob {

  // Fill this if the connector does not contain a data set with SKU & stock
  // TODO comment
  protected $part_field;

  // Field containing stock value in the source items.
  // TODO unify with above?
  // TODO merge usage of this this field into preprocessCombinations().?
  protected $stock_field;

  // Field containing warehouse values. If you have stock in only one place
  // (warehouse), you don't need to define this field.
  // Note that in this case, an item code is assumed to occur only once in the
  // source/connector data set. If there are multiple rows with stock values
  // (one per warehouse) for the same item, but $warehouse_field is left empty,
  // not all stock will be counted / you will likely need to change the query to
  // only get the stock values you need. (e.g. by setting $this->extra_filter.)
  // TODO document: should we fill this in case of part stuff?
  protected $warehouse_field;

  // Destination field(s) in entity, which should be filled _besides_ the
  // commerce_stock field. May be left empty.
  // If not empty:
  // If $warehouse_field is undefined, $stock_dest must be a string.
  // If $warehouse_field is defined, $stock_dest can be an array, where
  //   key = any value inside $warehouse_field in the AFAS data. (Values for
  //         warehouses not defined as keys in $stock_dest will be ignored!)
  //   value = destination field for that particular warehouse.
  // If $warehouse_field is defined and $stock_dest is a string, all stock
  //   amounts will be added up and filled in this field.
  protected $stock_dest;

  // Usually 'sku'. Document better; see initContext().
  // TODO document?
  protected $src_id_dest;

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
      drupal_set_message("'Stock field' property is not defined in the AFASStockConnector class! The process will not run until this is fixed!", 'warning');
    }
    if (empty($this->stock_dest) && !module_exists('commerce_stock')) {
      drupal_set_message("'Destination field' is not defined in the AFASStockConnector class (and commerce_stock module is not enabled). The process will not run until this is fixed!", 'warning');
    }
    if (empty($this->src_id_dest) && $this->entity_type != 'commerce_product') {
      drupal_set_message("'src_id_dest' property not set (necessary for resolving source->Drupal items). The process will not run until this is fixed!", 'warning');
    }

    return $form;
  }

  /**
   * Implements BatchQueueJobInterface::init()
   */
  public function init() {

    // Don't check _everything_ here so the class is still useful for
    // list_format = raw. More checks in processItem().
    if (!empty($this->src_id_field) && !empty($this->stock_field)) {

      // Callers can set 'list_format' setting to 'raw' to retrieve unmodified
      // result from the AFAS query; this is not fit for processItem().
      // If 'raw', we get getAfasItems() convert the SimpleXMLElement
      // to an array for us. Otherwise we do the conversion to a
      // (differently structured) array ourselves.
      $raw = (!empty($this->settings['list_format'])
              && $this->settings['list_format'] == 'raw');
      $afas_items = $this->getAfasItems($raw);

      if (!empty($afas_items) && !is_string($afas_items) && !$raw) {

        if (!empty($this->part_field)) {
          // $afas_items does not contain stock, only the SKUs of the
          // individual part products. Get the stock from Drupal and
          // transform $afas_items into something resembling a regular data set.
          $afas_items = $this->preprocessCombinations($afas_items);
        }

        $items = array();
        foreach ($afas_items as $afas_item) {
          $sku = (string)$afas_item->{$this->src_id_field};
          // Implement 'single item' filter
          if (empty($this->settings['single_item']) ||
                  $sku == $this->settings['single_item']) {

            $stock = (string)$afas_item->{$this->stock_field};
            if (!is_numeric($stock)) {
              $this->log('Stock vaule for item @sku (@stock) is not a number; skipping.',
                array('@sku' => $sku, '@stock' => $stock));
            }
            else {
              if (empty($this->warehouse_field)) {
                // We have what we need: an array with sku & stock values.
                // But since we want to prevent superfluous data being moved around in
                // queue tables (and who knows what fields are defined by the connector)
                // let's make an array with only sku + stock fields. Hardcoded keys.

                $items[] = array('src_id' => $sku,
                                 'stock'  => $stock >= 0 ? $stock: 0);
              }
              else {

                // One item can have stock values in several warehouses, which we received
                // as separate rows. Regroup the array with unique rows per SKU:
                // key = SKU, value = array (warehouse => stock, ...)
                $warehouse = (string)$afas_item->{$this->warehouse_field};
                if (!isset($products[$sku])) {
                  $products[$sku] = array();
                }
                $items[$sku][$warehouse] = $stock >= 0 ? $stock: 0;
              }
            }
          }

          // Implement 'limit' filter
          if (!empty($this->settings['limit']) && $this->settings['limit'] > 0
              && count($items) >= $this->settings['limit']) {
            break;
          }
        }

        if (empty($this->warehouse_field)) {
          $afas_items = $items;
        }
        else {
          // Now make this array into a numerically keyed one, with SKUs in the
          // values again. (The caller ignores keys.)
          $afas_items = array();
          foreach ($items as $sku => $stock) {
            $afas_items[] = array('src_id' => $sku, 'stock' => $stock);
          }
        }
      }
      return $afas_items;
    }
    else {
      return 'src_id_field / stock_field properties (one or both) not defined; cannot fetch stock';
    }
  }

  /**
   * Implements BatchQueueJobInterface::processItem()
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


    // We need the 'destination field for the source id', in order to resolve
    // items to Drupal entities. (in processItem().)
    // Other connectors have mappings for that, but we don't... so we made src_id_dest.
    // For entity type 'commerce_product' we can assume it's "sku". Otherwise we must fill it.
    // Check it here so we can still abort.
    if (empty($this->src_id_dest) && $this->entity_type != 'commerce_product') {
      $this->log("'src_id_dest' property not set (necessary for resolving source->Drupal items)!", array(), WATCHDOG_WARNING);
      $items = array();
    }

  }

  /**
   * Implements BatchQueueJobInterface::processItem()
   */
  public function processItem($item, &$context) {

    // Check for properties needed to run properly.
    if ((!empty($this->stock_dest) || module_exists('commerce_stock'))
        && !empty($this->entity_type)) {

      if (empty($this->src_id_dest)) {
        if ($this->entity_type == 'commerce_product') {
          $this->src_id_dest = 'sku';
        }
        else {
          return;
        }
      }

      // parent::resolveDestIds() gets this value from a mapping. We'll create
      // a fake mapping so we don't have to duplicate that code.
      // TODO: if field_mapping is deprecated in favor of dest_field_mapping, change this too.
      $this->field_mapping = array($this->src_id_field => array('dest' => $this->src_id_dest));

      $dest_ids = $this->resolveDestIds(array($item['src_id']));

      if ($dest_ids) {
        // remember encountered entities for finish()
        $context['results']['dest_ids'] = array_merge($context['results']['dest_ids'], $dest_ids);

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
   * Implements BatchQueueJobInterface::finish()
   */
  public function finish($results) {

    $updated_to_0 = 0;
    if (!empty($results['dest_ids'])) {

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
        //->propertyCondition('status', 1) //no, this means that if unpublished products get published, their stock is not OK.

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

  /**
   * Assuming the data set represents a set of 'product combinations'
   * (samenstellingen), which does not hold stock values, get the stock values
   * from the individual projects from Drupal and construct a 'normal' data set.
   */
  private function preprocessCombinations(&$afas_items) {

    if (empty($this->src_id_dest) && $this->entity_type != 'commerce_product') {
      // We log this as an invalid condition elsewhere; just return.
      return array();
    }

    // We'll get all stock values for the individual parts in the 'init' phase,
    // in one DB query. Two reasons:
    // - though it's more processing on 'init', overall it's a lot less than if
    //   we'd need to query stock values in every single processItem() call;
    // - we don't need to modify processItem() to deal with product combinations
    //   (or even init() very much) if we isola

    // Collect SKUs of parts, without duplicates
    $part_ids = array();
    foreach ($afas_items as $afas_item) {
      $sku = (string)$afas_item->{$this->part_field};
      // Implement 'single item' filter
      if (empty($this->settings['single_item']) ||
          $sku == $this->settings['single_item']) {

        $part_ids[$sku] = TRUE;
      }
    }

    // Look up stock values for those parts. (Use direct SQL; doing
    // EntityFieldQuery and then loading full products just to get the stock,
    // seems too much overhead - especially on cron.

    // For the 'source/SKU fields in Drupal'...
    // (which are the product.sku or a FieldAPI field)
    if (empty($this->src_id_dest)) {
      $query = db_select($this->entity_type, 'p');
      $query->condition('p.sku', array_keys($part_ids));
      $query->addField('p', 'sku');
      $join_field = 'product_id';
    }
    else {
      // We don't need the 'entity table'; we can link against the
      // src_id_field table.
      $query = db_select('field_data_' . $this->src_id_dest, 'p');
      $query->condition('p.' . $this->src_id_dest . '_value',
        array_keys($part_ids));
      $query->addField('p', $this->src_id_dest . '_value', 'sku');
      $join_field = 'entity_id';
    }
    // ...look up the stock values (can be multiple)...
    // (which are commerce_stock_value or a FieldAPI field)
    // NOTE: assumption we need delta=0 and language=und
    if ($this->entity_type == 'commerce_product'
        && empty($this->ignore_commerce_stock)
        && module_exists('commerce_stock')) {
      $query->leftJoin('field_data_commerce_stock', 's',
        'p.' . $join_field . " = s.entity_id AND s.language='" . LANGUAGE_NONE
        . "' AND s.delta=0 AND s.deleted=0");
      $query->addField('s', 'commerce_stock_value', 'stock');
      $stock_fields = array('stock' => 'commerce_stock');
    }
    else {
      if (is_array($this->stock_dest)) {
        $stock_fields = $this->stock_dest;
      }
      else {
        $stock_fields = array('stock' => $this->stock_dest);
      }
      foreach ($stock_fields as $stock_field => $dest_field) {
        $query->leftJoin('field_data_' . $dest_field, 's',
          'p.' . $join_field . " = s.entity_id AND s.language='" . LANGUAGE_NONE
          . "' AND s.delta=0 AND s.deleted=0");
        $query->addField('s', $dest_field . '_value', $stock_field);
      }
    }

    $parts_stock = $query->execute()->fetchAllAssoc('sku');

    // Construct array of objects with src_id_field, warehouse & stock values,
    // which is what the caller expects. But only include the lowest stock.
    // (The caller will not use array keys; we use them for determining lowest.)
    $items = array();
    if (empty($this->warehouse_field)) {
      // warehouse_field is the field in the warehouse column, and also
      // - among the field keys of $this->stock_dest
      // - among the fieldnames (keys) in $parts_stock which we fetched from
      //    the db; we made sure to choose the fieldnames that way.
      // Because of the last point, we must fill $warehouse with 'stock'.
      $warehouse = 'stock';
    }
    foreach ($afas_items as $afas_item) {

      $sku = (string)$afas_item->{$this->src_id_field};
      if (!empty($this->warehouse_field)) {
        $warehouse = (string)$afas_item->{$this->warehouse_field};
      }
      // Stock value can be numeric or NULL (because of above left join).
      $stock = $parts_stock[$warehouse] >= 0 ? $parts_stock[$warehouse]: 0;

      if (!isset($items[$sku . ':' . $warehouse])) {
        // New item (this is the first part we find)
        $item = new stdClass();
        $item->{$this->src_id_field} = $sku;
        if (!empty($this->warehouse_field)) {
          $item->{$this->warehouse_field} = $warehouse;
        }
        $item->{$this->stock_field} = $stock;
        $items[$sku . ':' . $warehouse] = $item;
      }
      elseif ($stock < $items[$sku . ':' . $warehouse]->{$this->stock_field}) {
        $items[$sku . ':' . $warehouse]->{$this->stock_field} = $stock;
      }
    }
  }
}
