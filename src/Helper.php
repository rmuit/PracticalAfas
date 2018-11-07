<?php
/**
 * This file is part of the PracticalAfas package.
 *
 * (c) Roderik Muit <rm@wyz.biz>
 *
 * For the full copyright and license information, please view the LICENSE file
 * that was distributed with this source code.
 */

namespace PracticalAfas;

use InvalidArgumentException;
use RuntimeException;

/**
 * A collection of standalone helper methods for AFAS data manipulation.
 *
 * At the moment:
 * - Fetch a large data set in batches;
 * - (The others are moved into various UpdateObjects as of v2.0.)
 *
 * All methods are static so far.
 */
class Helper
{
    /**
     * Calls a GetConnector to get one batch of a large data set.
     *
     * Getting data in batches can be useful to prevent timeouts / work with the
     * required 'take' parameter to AFAS GetConnector calls. For this to work,
     * we have to be able to order the data set by an immutable field*, so a
     * unique, immutable field should be added to the data set and its fieldname
     * must be specified in the 'sortable_id_field' argument. Note that we never
     * use the 'skip' parameter for fetching subsequent batches: 'skip' is fine
     * for 'human paging' but poses a risk of data loss if records are deleted
     * while we're getting data.
     * * The order-by field must be immutable but not necessarily unique; there
     *   is an argument to specify non-uniqueness. In this case, this method
     *   will check for duplicate records in subsequent fetched batches and weed
     *   out duplicates. This is however non-ideal, possibly slower (because of
     *   double-fetched records) and introduces unsupported edge cases where
     *   this method will simply throw an exception.
     * * Unlike uniqueness, immutability is just blindly assumed. 'mutable'
     *   fields will risk data loss (just like 'skip paging') if their values
     *   are changed while fetching data. If there really is no immutable field
     *   in the connector, the next best thing is a field that always increments
     *   so at least you don't lost records in your data set, only duplications.
     *   (One example of this is a 'last updated' field. The chance of
     *   duplicates for this is higher than you might think, though, because we
     *   need to work around AFAS filter bugs. See id_field_type='date' code.)
     *
     * Batched fetching does not work with 'OR type' filters, only with 'AND'.
     *
     * @param array $args
     *   Arguments used to create / process the getData() calls. These must stay
     *   the same over subsequent calls made to fetch a full data set.
     *   - connection (\PracticalAfas\Connetion; required):
     *     The connection object to use. (If we feel the need, we might remove
     *     the required-ness of this value and make this a non-static method
     *     later.)
     *   - connector (string; required):
     *     The connector name.
     *   - id_field (string; required):
     *     The (recommended) unique, (strongly recommended) immutable field that
     *     will be used for ordering. Can be preceded by '-' to force descending
     *     ordering (though the use for this is unclear).
     *   - id_field_type (string):
     *     Necessary when the value in the ID field is not suitable for using
     *     in the 'larger than' filter, so special code needed to be implemented
     *     to work around this fact. One value is recognized at the moment:
     *     "date". (Please don't use date fields as id_field though, or only as
     *     a last resort. See the code for issues.)
     *   - id_field_not_unique (bool):
     *     This must be true if the ID field does not contain unique values.
     *   - take (int; recommended):
     *     'take' argument to getData() call, i.e. the batch size. Testing shows
     *     that the default for Rest clients is 100; for SOAP clients it must be
     *     specified (because the default is to return no records). Setting this
     *     is not required but will make for one less getData() call (because if
     *     it is not set, we're only done if a call returns 0 items).
     *   - take_total (int):
     *     The maximum total number of records to return for this data set (over
     *     one or several calls). If this is provided, then 'take' is required.
     *   - skip (string):
     *     'skip' argument to the first getData() call; ignored on further calls.
     *   - filters (array):
     *     Any (extra) filter parameter to getData().
     *   - options (array):
     *     Any 'options' argument to pass to getData(). This method only works
     *     when the return value is an array, so setting a 'Outputformat' option
     *     that results in anything else will cause an exception.
     * @param array $context
     *   An array with context data that will be modified by the call, and that
     *   should be passed unmodified to every next call to get the next batch in
     *   a data set. Two properties are important:
     *   - subtotal: should be empty for calls which fetch the first batch of a
     *     data set.
     *   - finished: should be checked after every call; true means that the
     *     full data set is returned and no more calls must be made (except to
     *     perhaps get a new data set, after unsetting 'subtotal').
     *
     * @return array
     *   A batch of records.
     *
     * @throws \InvalidArgumentException
     *   If values inside the arguments / context have an illegal value / type.
     * @throws \RuntimeException
     *   If we cannot process the records returned by getData().
     *
     * @see \PracticalAfas\Connection::getData()
     * @throws \Exception
     */
    public static function getDataBatch(array $args, array &$context)
    {
        if (!isset($args['connection'])) {
            throw new InvalidArgumentException("'connection' argument not provided.", 32);
        }
        if (!($args['connection'] instanceof Connection)) {
            throw new InvalidArgumentException("Invalid 'connection' argument.", 32);
        }
        if (!isset($args['connector'])) {
            throw new InvalidArgumentException("'connector' argument not provided.", 32);
        }
        if (empty($args['connector']) || !is_string($args['connector'])) {
            throw new InvalidArgumentException("Invalid 'connector' argument: " . var_export($args['connector'], true), 32);
        }
        if (!isset($args['id_field'])) {
            throw new InvalidArgumentException("'id_field' argument not provided.", 32);
        }
        if (empty($args['id_field']) || !is_string($args['id_field'])) {
            throw new InvalidArgumentException("Invalid 'id_field' argument: " . var_export($args['id_field'], true), 32);
        }
        if (isset($args['filters']) && !is_array($args['filters'])) {
            throw new InvalidArgumentException("'filters' argument must be an array.", 32);
        }
        $filters = !empty($args['filters']) ? $args['filters'] : [];

        // We have two indicators for repeated fetches: the number of records
        // fetched previously and the last ID value fetched. The first time this
        // method is called, both are expected to be empty (though we only check
        // emptiness of the former). If the former is nonempty, then the latter
        // must be nonempty.
        $first_run = empty($context['subtotal']);
        if ($first_run) {
            $context['subtotal'] = 0;
        } else {
            if (!is_numeric($context['subtotal'])) {
                throw new InvalidArgumentException("Context value 'subtotal' is not numeric; this should never happen.", 29);
            }
            if (empty($context['next_start'])) {
                throw new InvalidArgumentException("Context value 'next_start' was emptied out; this should never happen.", 29);
            }
            // If we have a non-unique ID field, there is a third indicator:
            if (!empty($args['id_field_not_unique']) && empty($context['last_records'])) {
                throw new RuntimeException("Context value 'last_records' was emptied out; this should never happen.", 29);
            }

            // Convert value to filter field if necessary.
            $filter_value = $context['next_start'];
            if (isset($args['id_field_type']) && $args['id_field_type'] === 'date') {
                // Three odd things about date values:
                // 1. Dates expressed in Microsoft's  "Universal Sortable" date
                //    format are not recognized as filter values, even though
                //    this is the format that AFAS returns for REST clients.
                //    (Fitler values need to have the trailing 'Z' removed.)
                if (substr($filter_value, -1) === 'Z') {
                    $filter_value = substr($filter_value, 0, strlen($filter_value) - 1);
                }
                // 2. Even though date values have a 'Z' at the end, they are
                //    not expressed in UTC; it seems to be the local timezone
                //    (or, more likely, AFAS' own fixed timezone, CET/CEST
                //    (UTC+1/2)). This is easy for us because we don't have to
                //    do conversion; filter values are also expressed in the
                //    local timezone. But it's confusing.
                // CODE NOTE: if it is / becomes possible to specify the
                // timezone in a date field value, this will probably need
                // to be changed.
                // 3. Testing reveals that OP_LARGER_OR_EQUAL doesn't work
                //    reliably; filtering on '>= DATEVAL' will consistently
                //    include only half the records that display as DATEVAL.
                //    (Explanation is in README.md, bugs section.) This means
                //    that the current code has a considerable chance of
                //    missing records. Sorting descending instead of
                //    ascending won't change that.
                if (substr($args['id_field'], 0, 1) === '-') {
                    // Increase the filter value by one, so we are sure we
                    // never miss any records. This increases the chance of
                    // having duplicate records returned that were also in the
                    // previous data set. (And our code below that will remove
                    // those previous records, will not work as long as it only
                    // checks for _one_ specific value.) We still choose to err
                    // on the side of 'duplicate records' rather than 'missed
                    // records', partly because date values are 'sparse':
                    // probability is not high that a record with a date value
                    // of ($filter_value + 1 second), which would be a
                    // not-removed duplicate record, actually exists.
                    $filter_value = date('Y-m-d\TH:i:s', strtotime($filter_value) + 1);
                } else {
                    $filter_value = date('Y-m-d\TH:i:s', strtotime($filter_value) - 1);
                }
            }

            $filters[] = [
                $args['id_field'] => $filter_value,
                '#op' => substr($args['id_field'], 0, 1) === '-' ?
                    (empty($args['id_field_not_unique']) ? Connection::OP_SMALLER_THAN : Connection::OP_SMALLER_OR_EQUAL) :
                    (empty($args['id_field_not_unique']) ? Connection::OP_LARGER_THAN : Connection::OP_LARGER_OR_EQUAL),
            ];
        }

        $getdata_args = [];
        if (isset($args['take_total']) && (!is_numeric($args['take_total']) || $args['take_total'] <= 0)) {
            throw new InvalidArgumentException("'take_total' argument must be a postive number.", 32);
        }
        if (isset($args['take']) && (!is_numeric($args['take']) || $args['take'] <= 0)) {
            throw new InvalidArgumentException("'take' argument must be a postive number.'", 32);
        }
        if (isset($args['take_total']) && !isset($args['take'])) {
            // We throw this exception because otherwise we don't know whether
            // to set the 'take' argument in getData(). There are other
            // solutions to counter that, but this is the most consistent.
            throw new InvalidArgumentException("With 'take_total' argument set, 'take' must also be set.", 32);
        }
        if (empty($args['id_field_not_unique']) && isset($args['take_total']) && $args['take_total'] - $context['subtotal'] < $args['take']) {
            $getdata_args['take'] = $args['take_total'] - $context['subtotal'];
        } elseif (isset($args['take'])) {
            $getdata_args['take'] = $args['take'];
        }

        if (!empty($args['skip']) && $first_run) {
            $getdata_args['skip'] = $args['skip'];
        }
        $getdata_args['orderbyfieldids'] = $args['id_field'];
        if (isset($args['options'])) {
            if (!is_array($args['options'])) {
                throw new InvalidArgumentException("'options' argument must be an array.", 32);
            }
            $getdata_args['options'] = $args['options'];
        }

        $records = $args['connection']->getData($args['connector'], $filters, Connection::DATA_TYPE_GET, $getdata_args);
        if (!is_array($records)) {
            throw new RuntimeException('Afas GetConnector returned a non-array value. (Has an unsupported Outputmode option been set?)', 28);
        }
        $count = count($records);
        if (!empty($getdata_args['take']) && $count > $getdata_args['take']) {
            throw new RuntimeException("Afas GetConnector returned more records ($count) than the 'take' parameter specified ($getdata_args[take]). This is impossible.", 28);
        }
        $orig_count = $count;

        $id_field = substr($args['id_field'], 0, 1) === '-' ? substr($args['id_field'], 1) : $args['id_field'];
        if (!empty($args['id_field_not_unique']) && !$first_run && $records) {
            // The last record(s) from the previous batch are the same as the
            // first record(s) from this batch, so remove them. Loop as long as
            // the ID field value is equal to what we remembered from last time.
            $key = null;
            foreach ($records as $key => $item) {
                if (empty($item[$id_field])) {
                    throw new RuntimeException("A returned item does not have the '$id_field' value populated, so we cannot reliably fetch items over multiple invocations of start().", 27);
                }
                if ($item[$id_field] !== $context['next_start']) {
                    // We're done. $key acts as a flag that we're OK.
                    $key = null;
                    break;
                }
                // Check if the item occurs in last_records. If so, remove it.
                foreach ($context['last_records'] as $last_key => $queued_item) {
                    if ($item == $queued_item) {
                        unset($records[$key]);
                        unset($context['last_records'][$last_key]);
                        continue 2;
                    }
                }
            }
            if ($key !== null) {
                // If this ever happens: tough luck. This whole code block is
                // just a hack anyway.
                throw new RuntimeException("All items in a returned batch have the same ID value. This cannot be supported. Please set a unique 'id_field'.", 26);
            }

            $count = count($records);
            // Check whether we don't have too much records. We could not do
            // this beforehand as we do when the ID field is unique.
            if (isset($args['take_total']) && $args['take_total'] - $context['subtotal'] < $count) {
                $records = array_slice($records, 0, $args['take_total'] - $context['subtotal']);
                $count = $args['take_total'] - $context['subtotal'];
            }
        }

        $context['subtotal'] += $count;
        // We assume that if we got a smaller amount than 'take', then this is
        // the last batch. If 'take' is empty, then we continue fetching until
        // we get 0 items.
        $context['finished'] =
            $orig_count == 0
            || (!empty($getdata_args['take']) && $orig_count != $getdata_args['take'])
            || (!empty($args['take_total']) && $args['take_total'] == $context['subtotal']);

        if (!$context['finished']) {
            // Remember where to start at the next call.
            $item = end($records);
            if (empty($item[$id_field])) {
                throw new RuntimeException("A returned item does not have the '$id_field' value populated, so we cannot reliably fetch items over multiple invocations of start().", 27);
            }
            $context['next_start'] = $item[$id_field];
            if (!empty($args['id_field_not_unique'])) {
                // Remember all items with this field value, to compare next time.
                $context['last_records'] = [];
                while ($item[$id_field] == $context['next_start']) {
                    $context['last_records'][] = $item;
                    $item = prev($records);
                    if ($item === false) {
                        throw new RuntimeException("All items in a returned batch have the same ID value. This cannot be supported. Please set a unique 'id_field'.", 26);
                    }
                    if (empty($item[$id_field])) {
                        throw new RuntimeException("A returned item does not have the '$id_field' value populated, so we cannot reliably fetch items over multiple invocations of start().", 27);
                    }
                }
            }
        }

        return $records;
    }
}
