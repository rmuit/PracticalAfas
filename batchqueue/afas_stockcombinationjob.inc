<?php
/**
 * @file
 * Class providing a job that sets stock values for products that are a
 * combination of other products. The connector contains fields for the item nr
 * and part-nr (with multiple rows per item), and no stock data.
 * Stock data is retrieved from Drupal --i.e. a regular stock import job should
 * ideally have run just before this job-- and all parts are assumed to be
 * present in the website as individual products.
 * TODO: if we implement a configForm, this can be a non-abstract class.
 */
abstract class AfasStockCombinationJob extends AfasStockImportJob {

  // The fieldname containing the item (sku) numbers for individual parts.
  protected $part_field;

  // By filling $this->stock_field with '#drupal', stock values for the
  // individual parts will be retrieved from Drupal. This is not a preferred
  // solution, because it assumes that individual parts are also separate Drupal
  // entities, which is probably not the case.

  // Note: there is also untested support for a 'warehouse' field (stock in
  // multiple warehouses for each part, will produce an array
  // (warehouse => [lowest stock for any part in this warehouse]), but I
  // haven't actually seen how to include 'warehouse' in this connector --RM.

  /**
   * Extends AfasStockImportJob::init()
   */
  public function init() {

    // Don't check _everything_ immediately so the class is still useful for
    // list_format = raw. More checks in processItem().
    if (!empty($this->src_id_field)) {

      // Callers can set 'list_format' setting to 'raw' to retrieve unmodified
      // result from the AFAS query; this is not fit for processItem().
      // If 'raw', we get getAfasItems() convert the SimpleXMLElement
      // to an array for us. Otherwise we do the conversion to a
      // (differently structured) array ourselves.
      $raw = (!empty($this->settings['list_format'])
              && $this->settings['list_format'] == 'raw');
      $afas_items = $this->getAfasItems($raw);

      if (!empty($afas_items) && !is_string($afas_items) && !$raw) {


        if (empty($this->src_id_dest) && $this->entity_type != 'commerce_product') {
          return "'src_id_dest' property not set (necessary for resolving source->Drupal items).";
        }

        $items = array();
        if ($this->stock_field == '#drupal') {
          // Look up stock values for those parts.
          $parts_stock = $this->drupalStock($afas_items);
        }

        // Now construct array of items we need, just like in parent::init().

        $i=0;
        foreach ($afas_items as $afas_item) {
          $i++;
          $sku = (string)$afas_item->{$this->src_id_field};
          $part_sku = (string)$afas_item->{$this->part_field};
          if ($this->stock_field != '#drupal') {
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
          elseif ($this->stock_field != '#drupal' && !is_numeric($stock)) {
            $this->log('Stock value for item @sku/part @part (@stock) is not a number; skipping.',
              array('@sku' => $sku, '@part' => $part_sku, '@stock' => $stock));
          }
          // Implement 'single item' filter. (for #drupal: part 2/2, and also
          // silently skip setting any stock if the part-product is not present
          // in the website.)
          elseif (($this->stock_field != '#drupal' ||
                   isset($parts_stock[$part_sku])) &&
                  (empty($this->settings['single_item']) ||
                   $sku == $this->settings['single_item'])) {

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

              if ($this->stock_field == '#drupal') {
                // #1a Drupal is checked: stock in $parts_stock.

                $items[$sku] = array('src_id' => $sku);
                if (!is_array($this->stock_dest)) {

                  // 'stock' is single value.  Value in
                  // $parts_stock may be numeric or NULL (because of above left join).
                  $items[$sku] = array(
                    'src_id' => $sku,
                    'stock'  => !empty($parts_stock[$part_sku]['s_1']) ?
                      $parts_stock[$part_sku]['s_1'] : 0
                  );
                }
                else {
                  // 'stock' is array, equal to DB row but then without 'sku'.
                  $items[$sku] = array('src_id' => $sku,
                                       'stock'  => $parts_stock[$part_sku]);
                  unset($items[$sku]['stock']['sku']);
                }
              }
              else {
                // #2a stock field in this connector (the regular case),
                // representing the stock of each part product. This means we need to
                // remember the lowest stock value for each part product (possibly
                // per warehouse) as the value for the combination (per warehouse).

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
              }
            }
            else {
              if ($this->stock_field == '#drupal') {
                // #1b non-new item; Drupal is checked: stock in $parts_stock.

                if (!is_array($this->stock_dest)) {
                  // Update stock value if it's lower for this 'part'.

                  if (empty($parts_stock[$part_sku]['stock'])
                      || $parts_stock[$part_sku]['s_1'] < $items[$sku]['stock']) {
                    $items[$sku]['stock'] = !empty($parts_stock[$part_sku]['s_1']) ?
                      $parts_stock[$part_sku]['s_1'] : 0;
                  }
                }
                else {
                  // Update stock values for all mentioned warehouses.
                  foreach (array_keys($this->stock_dest) as $stock_field) {
                    if (empty($parts_stock[$part_sku][$stock_field])
                        || $parts_stock[$part_sku][$stock_field] < $items[$sku]['stock'][$stock_field]) {
                      $items[$sku]['stock'][$stock_field] = !empty($parts_stock[$part_sku][$stock_field]) ?
                        $parts_stock[$part_sku][$stock_field] : 0;
                    }
                  }
                }
              }
              else {
                // #2b non-new item; stock field in this connector (the regular case),

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
              }

            } // nonnew item
          } // else (checks were ok)
        } // foreach

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
      return 'src_id_field not defined; cannot fetch stock';
    }
  }

  /**
   * Returns stock for entities in Drupal, as an array of arrays.
   * Outer arrays are keyed by item-id (sku); inner arrays have
   * keys 'sku' and then 's_1' - 's_N' for stock fields.
   */
  protected function drupalStock(&$afas_items) {
    // $afas_items only passed by reference because of less memory usage.
    // It isn't altered.

    // We'll get all stock values for the individual parts in the 'init' phase,
    // in one DB query. Two reasons:
    // - though it's more processing on 'init', overall it's a lot less than if
    //   we'd need to query stock values in every single processItem() call;
    // - we don't need to modify processItem() to deal with product combinations.

    $part_ids = array();

    // Collect SKUs of parts, without duplicates
    if (!empty($this->settings['single_item'])) {
      $part_ids = array($this->settings['single_item'] => TRUE);
    }
    else {
      $part_ids = array();
      foreach ($afas_items as $afas_item) {
        $sku = (string)$afas_item->{$this->part_field};
        // Implement 'single item' filter (1/2)
        if (empty($this->settings['single_item']) ||
            $sku == $this->settings['single_item']) {

          $part_ids[$sku] = TRUE;
        }
      }
    }

    // Use direct SQL; doing EntityFieldQuery and then loading full products
    // just to get the stock, seems too much overhead - especially on cron.)

    // For the 'source/SKU fields in Drupal'...
    // (which are the product.sku or a FieldAPI field)
    if (empty($this->src_id_dest)) {
      $query = db_select($this->entity_type, 'p');
      $query->condition('p.sku', array_keys($part_ids), 'IN');
      $query->addField('p', 'sku');
      $join_field = 'product_id';
    }
    else {
      // We don't need the 'entity table'; we can link against the
      // src_id_field table.
      $query = db_select('field_data_' . $this->src_id_dest, 'p');
      $query->condition('p.' . $this->src_id_dest . '_value',
        array_keys($part_ids), 'IN');
      $query->condition('p.language', LANGUAGE_NONE);
      $query->condition('p.deleted', 0);
      $query->condition('p.delta', 0);
      $query->addField('p', $this->src_id_dest . '_value', 'sku');
      $join_field = 'entity_id';
    }
    // ...look up the stock values (can be multiple)...
    // (which are commerce_stock_value or a FieldAPI field)
    // NOTE: assumption we need delta=0 and language=und
    if (!is_array($this->stock_dest)
        && $this->entity_type == 'commerce_product'
        && empty($this->ignore_commerce_stock)
        && module_exists('commerce_stock')) {
      $query->leftJoin('field_data_commerce_stock', 's',
        'p.' . $join_field . " = s.entity_id AND s.language='" . LANGUAGE_NONE
        . "' AND s.delta=0 AND s.deleted=0");
      $query->addField('s', 'commerce_stock_value', 's_1');
      $stock_fields = array('s_1' => 'commerce_stock');
    }
    else {
      if (is_array($this->stock_dest)) {
        // The keys from $this->stock_dest (which represent internal
        // 'warehouse' values in AFAS, in our parent class) will be used as
        // fieldnames in the query we construct. Make sure they're
        // alphanumeric / do not clash with 'sku'.
        $stock_fields = array();
        $i = 0;
        foreach ($stock_fields as $dest_field) {
          $stock_fields['s_' . ++$i] = $dest_field;
        }
        // for the caller and processItem():
        $this->stock_dest = $stock_fields;
      }
      else {
        $stock_fields = array('s_1' => $this->stock_dest);
      }
      foreach ($stock_fields as $alias => $dest_field) {
        $query->leftJoin('field_data_' . $dest_field, $alias,
          'p.' . $join_field . " = $alias.entity_id AND $alias.language='"
          . LANGUAGE_NONE . "' AND $alias.delta=0 AND $alias.deleted=0");
        $query->addField('s', $dest_field . '_value', $alias);
      }
    }
    return $query->execute()->fetchAllAssoc('sku', PDO::FETCH_ASSOC);
  }

  /**
   * Implements BatchQueueJobInterface::finish()
   */
  public function finish($results) {
    // Do not update any products to 0.
    // We'll assume that if you enable a job like this, it's run straight
    // after the regular stock update - which has all product combination stock
    // updated to 0.
    // Ideally, you should override the regular finish() to exclude processing
    // of product combinations, and override this finish() to update
    // all un-encountered product combinations to 0.

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
