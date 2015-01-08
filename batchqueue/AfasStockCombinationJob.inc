<?php
/**
 * @file
 * Class providing a job that sets stock values for products that are a
 * combination of other products. The 'main connector' contains fields for the
 * item nr and part-nr (with multiple rows per item), and no stock data.
 * Stock data is retrieved either from a second connector, or from Drupal,
 * depending on use case, e.g.:
 * - Update 'product combination' type products from AFAS. These do not have
 *   stock values in AFAS; only their individual parts do.
 * - Update your own custom 'combination products', consisting of individual
 *   part products.
 *
 * TODO: if we implement a configForm, this can be a non-abstract class.
 */
abstract class AfasStockCombinationJob extends AfasStockImportJob {

  // $this->connector will contain the item (sku) numbers for the combination
  // products in $this->sku_field, and the item numbers for individual parts
  // in $this->part_field, at least.
  //
  // Defining $this->connector as '#drupal' will get items to be updated from
  // Drupal instead of AFAS; all entities of $this->entity_type, (optionally)
  // restricted by $this->bundle, will be processed.
  // In this case, $this->part_field and $this-stock_connector must be nonempty,
  // as this is where the parts & stock data must come from.
  protected $part_field;

  // If the stock values (in $this->stock_field) can be in the same connector,
  // this is best; $this-stock_connector can be left empty. Otherwise, two
  // things can be done:
  // - Get a second AFAS connector to contain part numbers and their stock
  //   values (plus warehouse if needed); this is the same data set
  //   as $this->stock_connector would contain in a normal AfasStockImportJob.
  // - get the stock values for individual parts from Drupal.
  //
  // The second thing is OK when you're sure all individual parts are also
  // separate Drupal entities, with stored stock values. (For AFAS
  // 'product combination' type products, this is probably not the case.)
  // A regular stock import job should ideally have run just before this job.
  //
  // $this->stock_connector should contain the connector name, or '#drupal'
  // for retrieving stock values from Drupal entities. If those entities contain
  // several stock fields, this can be noted by setting $this->stock_dest to an
  // array, and unlike other cases, $this->warehouse_field doesn't need to be
  // filled. It, and the array keys in stock_dest, will be overwritten.)
  protected $stock_connector;
  // If defined, $this->stock_src_id_field is the item number (sku) of
  // the part in the stock connector. If empty, $this->src_id_field is taken.
  protected $stock_src_id_field;
  //
  // If $this->warehouse_field is filled, it's assumed to be present in
  // $this->stock_connector (if that's filled), not $this->connector.

  // 'extra filter' for stock connector.
  protected $stock_extra_filter;

  // Note RM: there is also untested support for a 'warehouse' in case of an
  // empty stock_connector. Stock in multiple warehouses for each part, will
  // produce an array (warehouse => [lowest stock for any part in this
  // warehouse]). But I haven't actually seen how to include a warehouse field!

  public function __construct($settings) {
    parent::__construct($settings);
  }

  /**
   * Extends AfasStockImportJob::settingsForm()
   */
  public function settingsForm() {
    // For connector '#drupal', 'src_id_field'; doesn't need to be defined by
    // the implementing class, because its value doesn't matter. We just set it
    // because it's needed for the code in init() (as a 'field alias').
    if (empty($this->src_id_field) || $this->connector == '#drupal') {
      $this->src_id_field = 'src_id';
    }

  }

  /**
   * Extends AfasStockImportJob::init()
   */
  public function init(&$context) {

    // Don't check _everything_ immediately so the class is still useful for
    // list_format = raw. More checks in processItem().
    if (!empty($this->connector)
        && !empty($this->part_field)
        && (!empty($this->src_id_field) || $this->connector == '#drupal')) {

      // Callers can set 'list_format' setting to 'raw' to retrieve unmodified
      // result from the AFAS query; this is not fit for processItem().
      // If 'raw', we let getAfasItems() convert the SimpleXMLElement
      // to an array for us. Otherwise we do the conversion to a
      // (differently structured) array ourselves.
      $raw = (!empty($this->settings['list_format'])
              && $this->settings['list_format'] == 'raw');
      if ($this->connector == '#drupal' && !empty($this->stock_connector)) {

        // Hardcode optional properties as needed:
        if (empty($this->src_id_field) && $this->connector == '#drupal') {
          $this->src_id_field = 'src_id';
        }
        $afas_items = $this->getDrupalItems();
        // $afas_items is an array of arrays, or a PDO query result set.
      }
      else {
        $afas_items = $this->getAfasItems($raw);
        // $afas_items is an array of arrays, or a SimpleXMLElement
      }

      if (!empty($afas_items) && !is_string($afas_items) && !$raw) {

        if (empty($this->src_id_dest_field) && $this->entity_type != 'commerce_product') {
          return "'src_id_dest_field' property not set (necessary for resolving source->Drupal items).";
        }

        $items = array();
        if (!empty($this->stock_connector)) {
          // Get stock values for parts.
          if ($this->stock_connector == '#drupal') {
            $parts_stock = $this->getDrupalStock($afas_items);
          }
          else {
            $parts_stock = $this->getAfasStock();
          }
        }

        // Now construct array of items we need, just like in parent::init().

        $i=0;
        foreach ($afas_items as $afas_item) {
          $i++;
          $sku = (string)$afas_item->{$this->src_id_field};
          $part_sku = (string)$afas_item->{$this->part_field};
          if (empty($this->stock_connector)) {
            $stock = (string)$afas_item->{$this->stock_field};
          }
          if ($sku === '') {
            $this->log('Source-id value for item #@nr is empty; skipping.',
              array('@nr' => $i));
          }
          elseif (empty($part_sku)) {
            $this->log('Part-id value for item @nr (id @sku) is empty; skipping.',
              array('@nr' => $i, '@sku' => $sku));
          }
          elseif (empty($this->stock_connector) && !is_numeric($stock)) {
            $this->log('Stock value for item @sku/part @part (@stock) is not a number; skipping.',
              array('@sku' => $sku, '@part' => $part_sku, '@stock' => $stock));
          }
          // Implement 'single item' filter. (for #drupal: part 2/2, and also
          // silently skip setting any stock if the part-product is not present
          // in the website.)
          elseif ((empty($this->stock_connector)
                   || $this->stock_connector != '#drupal'
                   || isset($parts_stock[$part_sku]))
                  && (empty($this->settings['single_item'])
                      || $sku == $this->settings['single_item'])) {

            // OK, we're done checking inputs...
            // Construct array of objects with src_id_field & stock values, which is
            // what the caller expects. But only include the lowest stock. (The
            // caller will not use array keys; we use them for determining lowest.)

            if (!isset($items[$sku])) {
              // New item (this is the first part we find)

              // Implement 'limit' filter
              if (!empty($this->settings['limit']) &&
                  $this->settings['limit'] > 0 &&
                  count($items) >= $this->settings['limit']) {
                // Skip this item. (We need to go through the whole loop to see
                // if there are more parts to existing items, though).
                continue;
              }

              if (!empty($this->stock_connector)) {
                // #1a: stock coming from another source, in $parts_stock, which
                //      has one row per source-id.

                if (empty($this->warehouse_field)) {

                  // 'stock' should be single value.  Value in
                  // $parts_stock may be numeric or NULL (because of the left join).
                  $items[$sku] = array(
                    'src_id' => $sku,
                    'stock'  => !empty($parts_stock[$part_sku]['s_1']) ?
                      $parts_stock[$part_sku]['s_1'] : 0
                  );

                  if (!empty($this->settings['list_format'])) {
                    // Add another field with per-part stock details.
                    $items[$sku]['stock_details'] = $part_sku . ': '
                      . (!empty($parts_stock[$part_sku]['s_1']) ?
                        $parts_stock[$part_sku]['s_1'] : '-');
                  }
                }
                else {
                  // 'stock' is array, equal to $parts_stock row...
                  $items[$sku] = array(
                    'src_id' => $sku,
                    'stock'  => isset($parts_stock[$part_sku]) ?
                      $parts_stock[$part_sku] : array(),
                  );
                  // ...but without 'sku'. (Which may be set for #drupal)
                  unset($items[$sku]['stock']['sku']);

                  if (!empty($this->settings['list_format'])) {
                    // Add another field with per-part stock details;
                    // warehouse names are not displayed (for now?)
                    $items[$sku]['stock_details'] = $part_sku . ': '
                      . implode('+', $items[$sku]['stock']);
                  }
                }
              }
              else {
                // #2a stock field in this connector, representing the stock of
                // each part product. This means we need to remember the lowest
                // stock value for each part product (possibly per warehouse)
                // as the value for the combination (per warehouse).

                if (empty($this->warehouse_field)) {

                  $items[$sku] = array('src_id' => $sku,
                                       'stock'  => $stock >= 0 ? $stock: 0);
                }
                else {

                  // One item can have stock values in several warehouses, which
                  // we have as separate rows. Build stock _array_ in one item.
                  $warehouse = (string)$afas_item->{$this->warehouse_field};
                  if (empty($warehouse)) {
                    $this->log('Warehouse value for item @sku/part @part (@w) is empty; skipping.',
                      array('@sku' => $sku, '@part' => $part_sku));
                  }
                  else {
                    $items[$sku] = array(
                      'src_id' => $sku,
                      'stock' => array(
                        $warehouse => $stock >= 0 ? $stock: 0
                      ));
                  }
                }

                if (!empty($this->settings['list_format'])) {
                  // Add another field with per-part stock details.
                  $items[$sku]['stock_details'] = $part_sku . ': ' . $stock;
                }
              }
            }
            else {
              if (!empty($this->stock_connector)) {
                // #1b non-new item; stock in $parts_stock

                if (empty($this->warehouse_field)) {
                  // Update stock value if it's lower for this 'part'.

                  if (empty($parts_stock[$part_sku]['s_1'])
                      || $parts_stock[$part_sku]['s_1'] < $items[$sku]['stock']) {
                    $items[$sku]['stock'] = !empty($parts_stock[$part_sku]['s_1']) ?
                      $parts_stock[$part_sku]['s_1'] : 0;
                  }

                  if (!empty($this->settings['list_format'])) {
                    // Add another field with per-part stock details.
                    $items[$sku]['stock_details'] .= '; ' . $part_sku . ': '
                      . (!empty($parts_stock[$part_sku]['s_1']) ?
                        $parts_stock[$part_sku]['s_1'] : '-');
                  }
                }
                else {
                  // Update stock values for 'all warehouses', being:
                  // - if $this->stock_dest is an array: all its keys; the other
                  //   warehouses won't be updated by processItem() anyway.
                  // - otherwise: all keys encountered either in previous
                  //   part-items or in this part-item.
                  foreach (array_keys((is_array($this->stock_dest)
                      ? $this->stock_dest
                      : $items[$sku]['stock'] + $parts_stock[$part_sku])
                           ) as $stock_field) {

                    if (empty($parts_stock[$part_sku][$stock_field]) ||
                        empty($items[$sku]['stock'][$stock_field]) ||
                        $parts_stock[$part_sku][$stock_field] < $items[$sku]['stock'][$stock_field]) {

                      $items[$sku]['stock'][$stock_field] =
                        (empty($parts_stock[$part_sku][$stock_field]) ||
                         empty($items[$sku]['stock'][$stock_field])) ?
                        0 : $parts_stock[$part_sku][$stock_field];
                    }
                  }

                  if (!empty($this->settings['list_format'])) {
                    // Add another field with per-part stock details;
                    // warehouse names are not displayed (for now?)
                    $items[$sku]['stock_details'] .= '; ' . $part_sku . ': '
                      . implode('+', $items[$sku]['stock']);
                  }
                }
              }
              else {
                // #2b non-new item; stock field in this connector.

                if (empty($this->warehouse_field)) {
                  // Update stock value if it's lower for this 'part'.
                  if ($stock < $items[$sku]['stock']) {
                    $items[$sku]['stock'] = $stock >= 0 ? $stock: 0;
                  }
                }
                else {
                  // Update stock value for this warehouse.
                  $warehouse = (string)$afas_item->{$this->warehouse_field};
                  if (empty($warehouse)) {
                    $this->log('Warehouse value for item @sku/part @part (@w) is empty; skipping.',
                      array('@sku' => $sku, '@part' => $part_sku));
                  }
                  elseif (!isset($items[$sku]['stock'][$warehouse]) ||
                          $stock < $items[$sku]['stock'][$warehouse]) {
                    $items[$sku]['stock'][$warehouse] = $stock >= 0 ? $stock: 0;
                  }
                }

                if (!empty($this->settings['list_format'])) {
                  // Add another field with per-part stock details.
                  $items[$sku]['stock_details'] .= '; ' . $part_sku . ': ' . $stock;
                }
              }

            } // nonnew item
          } // else (checks were ok)
        } // foreach

        // If used for display, 'flatten' the array.
        if (!empty($this->settings['list_format']) &&
            !empty($this->warehouse_field)) {
          foreach ($items as &$item) {
            $item = array_merge($item, $item['stock']);
            unset($item['stock']);
          }
        }
        return $items;
      }
      else {
        // Empty data set, error condition or raw format requested.
        return $afas_items;
      }
    }
    else {
      return 'At least one of connector / src_id_field / part_field properties are not defined; cannot fetch stock';
    }
  }

  /**
   * Returns stock values from external connector, as array of arrays.
   * Outer arrays are keyed by item-id (sku); inner arrays are stock values:
   *
   * - if warehouse_field is empty: key is 's_1' (for uniform-ness with
   *   drupalStock())
   * - otherwise, keys are warehouse values (just like the derived class)
   */
  protected function getAfasStock() {

    // Get stock data from separate connector.
    $connection = new AfasSoapConnection();
    $afas_data = $connection->getData($this->stock_connector, $this->stock_extra_filter, 'get_simplexml');

    $sku_field = !empty($this->stock_src_id_field) ? $this->stock_src_id_field :
      $this->src_id_field;
    // Get all data into an array with one line per item.
    $items = array();
    $i=0;
    foreach ($afas_data as $afas_item) {
      $i++;
      $sku = (string)$afas_item->$sku_field;
      if (empty($this->settings['single_item']) ||
        $sku == $this->settings['single_item']) {

        $stock = (string)$afas_item->{$this->stock_field};
        if (!is_numeric($stock)) {
          $this->log('Stock value for item @sku (@stock) is not a number; skipping.',
            array('@sku' => $sku, '@stock' => $stock));
        }
        else {
          if (empty($this->warehouse_field)) {
            if (isset($items[$sku])) {
              $this->log('Part-source-id value for item #@nr (@sku) already encountered in earlier item; skipping this item.',
                array('@nr' => $i, '@sku' => $sku), WATCHDOG_WARNING);
            }
            else {
              $items[$sku] = array('s_1' => $stock >= 0 ? $stock: 0);
            }
          }
          else {
            $warehouse = (string)$afas_item->{$this->warehouse_field};
            if (empty($warehouse)) {
              $this->log('Warehouse value for item @sku is empty; skipping.',
                array('@sku' => $sku));
            }
            elseif (isset($items[$sku]['stock'][$warehouse])) {
              $this->log('Part-source-id/warehouse combination for item #@nr (@sku/@warehouse) already encountered in earlier item; skipping this item.',
                array('@nr' => $i, '@sku' => $sku, '@warehouse' => $warehouse), WATCHDOG_WARNING);
            }
            else {
              $items[$sku][$warehouse] = $stock >= 0 ? $stock: 0;
            }
          }
        }
      }
    }
    return $items;
  }

  /**
   * Returns stock for entities in Drupal as array of arrays. Outer arrays are
   * keyed by item-id (sku); inner arrays are stock values:
   *
   * - if stock_dest is a string: keys are 'sku' and 's_1'.
   *   ('sku' value is not used but would be harder to filter out than keep.)
   * - otherwise, keys are 'sku' and 's_1' - 's_N'. $this->stock_dest will be
   *   changed accordingly (keys overwritten - and $this->warehouse_field too)
   */
  protected function getDrupalStock(&$afas_items) {
    // $afas_items only passed by reference because of less memory usage.
    // It isn't altered.

    // Collect SKUs of parts, without duplicates.
    $part_ids = array();
    foreach ($afas_items as $afas_item) {
      // Implement 'single item' filter (part 1/2; part 2 in caller).
      if (empty($this->settings['single_item']) ||
        (string)$afas_item->{$this->src_id_field} == $this->settings['single_item']) {

        $part_ids[ (string)$afas_item->{$this->part_field} ] = TRUE;
      }
    }

    // Use direct SQL; doing EntityFieldQuery and then loading full products
    // just to get the stock, seems too much overhead - especially on cron.)

    // For the 'source/SKU fields in Drupal'...
    $sku_field = $this->src_id_dest_field;
    if (empty($sku_field) && $this->entity_type == 'commerce_product') {
      $sku_field = 'sku';
    }
    $field_info = field_info_field($this->part_field);

    if (!isset($field_info[$sku_field])) {
      $query = db_select($this->entity_type, 'p');
      $query->condition('p.sku', array_keys($part_ids), 'IN');
      $query->addField('p', 'sku');
      $info = entity_get_info($this->entity_type);
      $join_field = $info['entity keys']['id'];
    }
    else {
      // We don't need the 'entity table'; we can link against the
      // src_id_field table.
      $query = db_select('field_data_' . $sku_field, 'p');
      $query->condition('p.' . $sku_field . '_value',
        array_keys($part_ids), 'IN');
      $query->condition('p.language', LANGUAGE_NONE);
      $query->condition('p.deleted', 0);
      $query->condition('p.delta', 0);
      $query->addField('p', $sku_field . '_value', 'sku');
      $join_field = 'entity_id';
    }

    // ...look up the stock values (can be multiple)...
    //   (which are commerce_stock_value or a FieldAPI field)
    //   NOTE: assumption we need delta=0 and language=und
    if (!is_array($this->stock_dest)
        && $this->entity_type == 'commerce_product'
        && empty($this->ignore_commerce_stock)
        && module_exists('commerce_stock')) {

      // We don't need to split things up into different figures for different
      // destination fields, and commerce_stock holds the total. Get that.
      $query->leftJoin('field_data_commerce_stock', 's',
        'p.' . $join_field . " = s.entity_id AND s.language='" . LANGUAGE_NONE
        . "' AND s.delta=0 AND s.deleted=0");
      $query->addField('s', 'commerce_stock_value', 's_1');
    }
    else {
      if (is_array($this->stock_dest)) {
        // The keys from $this->stock_dest (which represent internal
        // 'warehouse' values in AFAS, in our parent class) will be used as
        // fieldnames in the query we construct. Make sure they're
        // alphanumeric / do not clash with 'sku'.
        $stock_fields = array();
        $i = 0;
        foreach ($this->stock_dest as $dest_field) {
          $stock_fields['s_' . ++$i] = $dest_field;
        }
        // for the caller and processItem():
        $this->stock_dest = $stock_fields;
        // This makes sure an array of stock values is returned by the caller:
        $this->warehouse_field = 'x';
      }
      else {
        // Take fieldname s_1. (If warehouse_field is defined, it will
        // be returned by the caller but not used anywhere.)
        $stock_fields = array('s_1' => $this->stock_dest);
      }
      foreach ($stock_fields as $alias => $dest_field) {
        $query->leftJoin('field_data_' . $dest_field, $alias,
          'p.' . $join_field . " = $alias.entity_id AND $alias.language='"
          . LANGUAGE_NONE . "' AND $alias.delta=0 AND $alias.deleted=0");
        $query->addField($alias, $dest_field . '_value', $alias);
      }
    }
    return $query->execute()->fetchAllAssoc('sku', PDO::FETCH_ASSOC);
  }

  /**
   * Return mapping of item-id (sku) of main product -> item-id of product parts
   * assuming all that info is present in Drupal.
   */
  protected function getDrupalItems() {

    // Use direct SQL, as in getDrupalStock().

    $info = entity_get_info($this->entity_type);

    // Add field for item SKU. (With alias: $this->src_id_field.)
    $sku_field = $this->src_id_dest_field;
    if (empty($sku_field) && $this->entity_type == 'commerce_product') {
      $sku_field = 'sku';
    }
    $sku_field_info = field_info_field($this->part_field);

    if (!isset($sku_field_info[$sku_field])) {
      $query = db_select($this->entity_type, 'p');
      $query->addField('p', $sku_field, $this->src_id_field);
      $join_field = $info['entity keys']['id'];;
    }
    else {
      // We don't need the 'entity table'; we can link against the
      // src_id_field table.
      $query = db_select('field_data_' . $sku_field, 'p');
      $query->condition('p.language', LANGUAGE_NONE);
      $query->condition('p.deleted', 0);
      $query->condition('p.delta', 0);
      $query->addField('p', $sku_field . '_value', $this->src_id_field);
      $join_field = 'entity_id';
    }
    if (!empty($this->bundle)) {
      $query->condition('p.' . $info['entity keys']['bundle'], $this->bundle);
    }

    // Add field for part SKUs. (With alias: $this->part_field.)
    // We support the data being in Drupal text fields and product references.
    // Add a left join so we also catch products consisting of 0 parts; the
    // caller does nothing with them except generate a warning.

    $query->leftJoin('field_data_' . $this->part_field, 'pp',
      'p.' . $join_field . " = pp.entity_id AND pp.language='"
      . LANGUAGE_NONE . "' AND pp.delta=0 AND pp.deleted=0");
    $field_info = field_info_field($this->part_field);
    if (!isset($field_info['type'])) {
      $this->log('part_field property (%pf) is not a proper Drupal field.',
        array('%pf' => $this->part_field), WATCHDOG_ERROR);
      return array();
    }
    elseif ($field_info['type'] == 'text') {

      $query->addField('s', $this->part_field . '_value', $this->part_field);
    }
    elseif ($field_info['type'] == 'commerce_product_reference') {

      // Join either the entity table or the field table (see 'Add field for
      // item SKU' above) to the product_id.
      if (!isset($sku_field_info[$sku_field])) {
        $query->leftJoin($this->entity_type, 'ppf',
          'pp.' . $this->part_field . '_product_id = ppf.' . $join_field);
        $query->addField('ppf', $sku_field, $this->part_field);
      }
      else {
        $query->leftJoin('field_data_' . $sku_field, 'ppf',
        'pp.' . $this->part_field . "_product_id = ppf.entity_id AND ppf.language='"
        . LANGUAGE_NONE . "' AND ppf.delta=0 AND pp.deleted=0");
        $query->addField('p', $sku_field . '_value', $this->part_field);
      }
    }
    else {
      $this->log('part_field property (%pf) has unrecognized field type (%type); maybe the code should be extended.',
        array('%pf' => $this->part_field, '%type' => $field_info['type']), WATCHDOG_ERROR);
      return array();
    }

    if ($this->stock_connector == '#drupal') {
      // We need to loop over the query results twice; once in getDrupalStock()
      // and once in the caller. So turn the query result into an array.
      $rows = array();
      foreach ($query->execute() as $row) {
        $rows[] = $row;
      }
      return $rows;
    }
    return $query->execute();
  }

  /**
   * Overrides AfasStockImportJob::finish().
   */
  public function finish(&$results) {
    // Do not update any products to 0.
    // What should really be done is updating all product combinations which
    // were not encountered in the connector, to 0 here. And then
    // doing the same for _only_ all non-combination products in
    // AfasStockImportJob::finish() -- but we have no standard way of
    // distinguishing between the two types.
    //
    // This means that, with a 'bare' implementation of AfasStockImportJob
    // and AfasStockCombinationJob, the stock of combinations will always
    // alternate between 0 (set by AfasStockImportJob::finish()) and the
    // actual value (set by AfasStockCombinationJob) :(
    // This means AfasStockCombinationJob must be executed directly after
    // AfasStockImportJob. And there's no mechanism to enforce that :(
    //
    // So, again, OVERRIDE the finish() methods in your own implementations!

    // Return status message.
    // always 'updated', also if 0
    $message = format_plural($results['updated'], '1 product updated', '@count products updated');
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
