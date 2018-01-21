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
 * - Various mapping and validation functinos for countries, addresses, phone
 *   numbers
 * - Convert a custom array into XML for updating via SOAP or REST API. (REST
 *   API is not tested yet, but the converted structure looks OK and is ready
 *   for testing.)
 *
 * If someone defined custom fields in their AFAS environment, they probably
 * want to extend objectTypeInfo() in a subclass, before using
 * normalizeDataToSend() or constructXml().
 *
 * All methods are static so far.
 */
class Helper
{
    const XML_TAB_WIDTH = 2;

    /**
     * Call a GetConnector to get one batch of a large data set.
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
        if (!($args['connection'] instanceof \PracticalAfas\Connection)) {
            throw new InvalidArgumentException("Invalid 'connection' argument.", 32);
        }
        if (!isset($args['connector'])) {
            throw new InvalidArgumentException("'connector' argument not provided.", 32);
        }
        if (empty($args['connector']) || !is_string($args['connector'])) {
            throw new InvalidArgumentException("Invalid 'connector' argument: " . json_encode($args['connector']), 32);
        }
        if (!isset($args['id_field'])) {
            throw new InvalidArgumentException("'id_field' argument not provided.", 32);
        }
        if (empty($args['id_field']) || !is_string($args['id_field'])) {
            throw new InvalidArgumentException("Invalid 'id_field' argument: " . json_encode($args['id_field']), 32);
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
                }
                else {
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
            $key = NULL;
            foreach ($records as $key => $item) {
                if (empty($item[$id_field])) {
                    throw new RuntimeException("A returned item does not have the '$id_field' value populated, so we cannot reliably fetch items over multiple invocations of start().", 27);
                }
                if ($item[$id_field] !== $context['next_start']) {
                    // We're done. $key acts as a flag that we're OK.
                    $key = NULL;
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
            if ($key !== NULL) {
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
                    if ($item === FALSE) {
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

    /**
     * Maps ISO to AFAS country code.
     * (Note: this function is not complete yet, it only does Europe correctly.)
     *
     * @param string $isocode
     *   ISO9166 2-letter country code
     *
     * @return string
     *   AFAS country code
     */
    public static function convertIsoCountryCode($isocode)
    {
        // European codes we know to NOT match the 2-letter ISO codes:
        $cc = [
            'AT' => 'A',
            'BE' => 'B',
            'DE' => 'D',
            'ES' => 'E',
            'FI' => 'FIN',
            'FR' => 'F',
            'HU' => 'H',
            'IT' => 'I',
            'LU' => 'L',
            'NO' => 'N',
            'PT' => 'P',
            'SE' => 'S',
            'SI' => 'SLO',
        ];
        if (!empty($cc[strtoupper($isocode)])) {
            return $cc[strtoupper($isocode)];
        }
        // Return the input string (uppercased), or '' if the code is unknown.
        return static::convertCountryName($isocode, 1);
    }

    /**
     * Maps country name to AFAS country code.
     *
     * @param string $name
     *   Country name
     * @param int $default_behavior
     *   Code for default behavior if name is not found:
     *   0: always return empty string
     *   1: if $name itself is equal to a country code, return that code (always
     *      uppercased). So the function accepts codes as well as names.
     *   2: return the (non-uppercased) original string as default, even though
     *      it is apparently not a legal code.
     *   3: 1 + 2.
     *   4: return NL instead of '' as the default. (Because AFAS is used in NL
     *      primarily.)
     *   5: 1 + 4.
     *
     * @return string
     *   Country name, or NL / '' if not found.
     */
    public static function convertCountryName($name, $default_behavior = 0)
    {
        // We define a flipped array here because it looks nicer / I just don't want
        // to bother changing it around :p. In the future we could have this array
        // map multiple names to the same country code, in which case we need to
        // flip the keys/values.
        $codes = array_flip(array_map('strtolower', [
            'AFG' => 'Afghanistan',
            'AL' => 'Albania',
            'DZ' => 'Algeria',
            'ASM' => 'American Samoa',
            'AND' => 'Andorra',
            'AO' => 'Angola',
            'AIA' => 'Anguilla',
            'AG' => 'Antigua and Barbuda',
            'RA' => 'Argentina',
            'AM' => 'Armenia',
            'AUS' => 'Australia',
            'A' => 'Austria',
            'AZ' => 'Azerbaijan',
            'BS' => 'Bahamas',
            'BRN' => 'Bahrain',
            'BD' => 'Bangladesh',
            'BDS' => 'Barbados',
            'BY' => 'Belarus',
            'B' => 'België',
            'BH' => 'Belize',
            'BM' => 'Bermuda',
            'DY' => 'Benin',
            'BT' => 'Bhutan',
            'BOL' => 'Bolivia',
            'BA' => 'Bosnia and Herzegowina',
            'RB' => 'Botswana',
            'BR' => 'Brazil',
            'BRU' => 'Brunei Darussalam',
            'BG' => 'Bulgaria',
            'BU' => 'Burkina Faso',
            'RU' => 'Burundi',
            'K' => 'Cambodia',
            'TC' => 'Cameroon',
            'CDN' => 'Canada',
            'CV' => 'Cape Verde',
            'RCA' => 'Central African Republic',
            'TD' => 'Chad',
            'RCH' => 'Chile',
            'CN' => 'China',
            'CO' => 'Colombia',
            'KM' => 'Comoros',
            'RCB' => 'Congo',
            'CR' => 'Costa Rica',
            'CI' => 'Cote D\'Ivoire',
            'HR' => 'Croatia',
            'C' => 'Cuba',
            'CY' => 'Cyprus',
            'CZ' => 'Czech Republic',
            'DK' => 'Denmark',
            'DJI' => 'Djibouti',
            'WD' => 'Dominica',
            'DOM' => 'Dominican Republic',
            'TLS' => 'East Timor',
            'EC' => 'Ecuador',
            'ET' => 'Egypt',
            'EL' => 'El Salvador',
            'CQ' => 'Equatorial Guinea',
            'ERI' => 'Eritrea',
            'EE' => 'Estonia',
            'ETH' => 'Ethiopia',
            'FLK' => 'Falkland Islands (Malvinas)',
            'FRO' => 'Faroe Islands',
            'FJI' => 'Fiji',
            'FIN' => 'Finland',
            'F' => 'France',
            'GF' => 'French Guiana',
            'PYF' => 'French Polynesia',
            'ATF' => 'French Southern Territories',
            'GA' => 'Gabon',
            'WAG' => 'Gambia',
            'GE' => 'Georgia',
            'D' => 'Germany',
            'GH' => 'Ghana',
            'GIB' => 'Gibraltar',
            'GR' => 'Greece',
            'GRO' => 'Greenland',
            'WG' => 'Grenada',
            'GP' => 'Guadeloupe',
            'GUM' => 'Guam',
            'GCA' => 'Guatemala',
            'GN' => 'Guinea',
            'GW' => 'Guinea-bissau',
            'GUY' => 'Guyana',
            'RH' => 'Haiti',
            'HMD' => 'Heard and Mc Donald Islands',
            'HON' => 'Honduras',
            'HK' => 'Hong Kong',
            'H' => 'Hungary',
            'IS' => 'Iceland',
            'IND' => 'India',
            'RI' => 'Indonesia',
            'IR' => 'Iran (Islamic Republic of)',
            'IRQ' => 'Iraq',
            'IRL' => 'Ireland',
            'IL' => 'Israel',
            'I' => 'Italy',
            'JA' => 'Jamaica',
            'J' => 'Japan',
            'HKJ' => 'Jordan',
            'KZ' => 'Kazakhstan',
            'EAK' => 'Kenya',
            'KIR' => 'Kiribati',
            'KO' => 'Korea, Democratic People\'s Republic of',
            'ROK' => 'Korea, Republic of',
            'KWT' => 'Kuwait',
            'KG' => 'Kyrgyzstan',
            'LAO' => 'Lao People\'s Democratic Republic',
            'LV' => 'Latvia',
            'RL' => 'Lebanon',
            'LS' => 'Lesotho',
            'LB' => 'Liberia',
            'LAR' => 'Libyan Arab Jamahiriya',
            'FL' => 'Liechtenstein',
            'LT' => 'Lithuania',
            'L' => 'Luxembourg',
            'MO' => 'Macau',
            'MK' => 'Macedonia, The Former Yugoslav Republic of',
            'RM' => 'Madagascar',
            'MW' => 'Malawi',
            'MAL' => 'Malaysia',
            'MV' => 'Maldives',
            'RMM' => 'Mali',
            'M' => 'Malta',
            'MAR' => 'Marshall Islands',
            'MQ' => 'Martinique',
            'RIM' => 'Mauritania',
            'MS' => 'Mauritius',
            'MYT' => 'Mayotte',
            'MEX' => 'Mexico',
            'MIC' => 'Micronesia, Federated States of',
            'MD' => 'Moldova, Republic of',
            'MC' => 'Monaco',
            'MON' => 'Mongolia',
            'MSR' => 'Montserrat',
            'MA' => 'Morocco',
            'MOC' => 'Mozambique',
            'BUR' => 'Myanmar',
            'SWA' => 'Namibia',
            'NR' => 'Nauru',
            'NL' => 'Nederland',
            'NPL' => 'Nepal',
            'NA' => 'Netherlands Antilles',
            'NCL' => 'New Caledonia',
            'NZ' => 'New Zealand',
            'NIC' => 'Nicaragua',
            'RN' => 'Niger',
            'WAN' => 'Nigeria',
            'NIU' => 'Niue',
            'NFK' => 'Norfolk Island',
            'MNP' => 'Northern Mariana Islands',
            'N' => 'Norway',
            'OMA' => 'Oman',
            'PK' => 'Pakistan',
            'PLW' => 'Palau',
            'PSE' => 'Palestina',
            'PA' => 'Panama',
            'PNG' => 'Papua New Guinea',
            'PY' => 'Paraguay',
            'PE' => 'Peru',
            'RP' => 'Philippines',
            'PCN' => 'Pitcairn',
            'PL' => 'Poland',
            'P' => 'Portugal',
            'PR' => 'Puerto Rico',
            'QA' => 'Qatar',
            'REU' => 'Reunion',
            'RO' => 'Romania',
            'RUS' => 'Russian Federation',
            'RWA' => 'Rwanda',
            'KN' => 'Saint Kitts and Nevis',
            'WL' => 'Saint Lucia',
            'WV' => 'Saint Vincent and the Grenadines',
            'WSM' => 'Samoa',
            'RSM' => 'San Marino',
            'ST' => 'Sao Tome and Principe',
            'AS' => 'Saudi Arabia',
            'SN' => 'Senegal',
            'SRB' => 'Serbia',
            'SY' => 'Seychelles',
            'WAL' => 'Sierra Leone',
            'SGP' => 'Singapore',
            'SK' => 'Slovakia (Slovak Republic)',
            'SLO' => 'Slovenia',
            'SB' => 'Solomon Islands',
            'SP' => 'Somalia',
            'ZA' => 'South Africa',
            'GS' => 'South Georgia and the South Sandwich Islands',
            'E' => 'Spain',
            'CL' => 'Sri Lanka',
            'SHN' => 'St. Helena',
            'SPM' => 'St. Pierre and Miquelon',
            'SUD' => 'Sudan',
            'SME' => 'Suriname',
            'SJM' => 'Svalbard and Jan Mayen Islands',
            'SD' => 'Swaziland',
            'S' => 'Sweden',
            'CH' => 'Switzerland',
            'SYR' => 'Syrian Arab Republic',
            'RC' => 'Taiwan',
            'TAD' => 'Tajikistan',
            'EAT' => 'Tanzania, United Republic of',
            'T' => 'Thailand',
            'TG' => 'Togo',
            'TK' => 'Tokelau',
            'TO' => 'Tonga',
            'TT' => 'Trinidad and Tobago',
            'TN' => 'Tunisia',
            'TR' => 'Turkey',
            'TMN' => 'Turkmenistan',
            'TCA' => 'Turks and Caicos Islands',
            'TV' => 'Tuvalu',
            'EAU' => 'Uganda',
            'UA' => 'Ukraine',
            'AE' => 'United Arab Emirates',
            'GB' => 'United Kingdom',
            'USA' => 'United States',
            'UMI' => 'United States Minor Outlying Islands',
            'ROU' => 'Uruguay',
            'OEZ' => 'Uzbekistan',
            'VU' => 'Vanuatu',
            'VAT' => 'Vatican City State (Holy See)',
            'YV' => 'Venezuela',
            'VN' => 'Viet Nam',
            'VGB' => 'Virgin Islands (British)',
            'VIR' => 'Virgin Islands (U.S.)',
            'WLF' => 'Wallis and Futuna Islands',
            'ESH' => 'Western Sahara',
            'YMN' => 'Yemen',
            'Z' => 'Zambia',
            'ZW' => 'Zimbabwe',
        ]));

        if (isset($codes[strtolower($name)])) {
            return $codes[$name];
        }
        if ($default_behavior | 1) {
            // Search for code inside array. If found, $name is a code.
            if (in_array(strtoupper($name), $codes, true)) {
                return strtoupper($name);
            }
        }
        if ($default_behavior | 2) {
            return $name;
        }
        if ($default_behavior | 4) {
            return 'NL';
        }
        return '';
    }

    /**
     * Checks if a string can be interpreted as a valid Dutch phone number.
     * (There's only a "Dutch" function since AFAS will have 99% Dutch clients.
     * Extended helper functionality can be added as needed.)
     *
     * @param string $phonenumber
     *   Phone number to be validated.
     *
     * @return array
     *   If not recognized, empty array. If recognized: 2-element array with
     *     area/ mobile code and local part - as input; not uniformly
     *     re-formatted yet.
     */
    public static function validateDutchPhoneNr($phonenumber)
    {
        /*
          Accepts:
              06-12345678
              06 123 456 78
              010-1234567
              +31 10-1234567
              +31-10-1234567
              +31 (0)10-1234567
              +3110-1234567
              020 123 4567
              (020) 123 4567
              0221-123456
              0221 123 456
              (0221) 123 456
          Rejects:
              010-12345678
              05-12345678
              061-2345678
              (06) 12345678
              123-4567890
              123 456 7890
              +31 010-1234567
        */

        // Area codes start with 0, +31 or the (now deprecated) '+31 (0)'.
        // Non-mobile area codes starting with 0 may be surrounded by brackets.
        foreach (
            [
                '((?:\+31[-\s]?(?:\(0\))?\s?|0)6)            # mobile
        [-\s]* ([1-9]\s*(?:[0-9]\s*){7})',

                '((?:\+31[-\s]?(?:\(0\))?\s?|0)[1-5789][0-9] # 3-digit area code...
        | \(0[1-5789][0-9]\))                        # (possibly between brackets...)
        [-\s]* ([1-9]\s*(?:[0-9]\s*){6})             # ...plus local number.',

                '((?:\+31[-\s]?(?:\(0\))?\s?|0)[1-5789][0-9]{2} # 4-digit area code...
        |\(0[1-5789][0-9]{2}\))                         # (possibly between brackets...)
        [-\s]* ([1-9]\s*(?:[0-9]\s*){5})                # ...plus local number.',
            ] as $regex) {

            if (preg_match('/^\s*' . $regex . '\s*$/x', $phonenumber, $matches)) {
                $return = [
                    strtr($matches[1], [' ' => '', '-' => '', '+31' => '0']),
                    $matches[2],
                ];
                // $return[0] is a space-less area code now, with or without trailing 0.
                // $return[1] is not formatted.
                if ($return[0][0] !== '0') {
                    $return[0] = "0$return[0]";
                }
                return $return;
            }
        }
        return [];
    }

    /**
     * Normalizes country_code, last_name, extracts search_name for use in
     * update connectors. This function can be called for an array containing
     * person data, address data, or both. See code details; it contains 'Dutch
     * specific' logic, which can be a nice time saver but is partly arbitrary
     * and not necessarily complete.
     *
     * This function only works if the keys in $data are all aliases (like
     * first_name), not original AFAS tag names (like FiNm)!
     *
     * Phone number reformatting has not been incorporated into this function,
     * because there is no uniform standard for it. (The 'official' standard
     * of (012) 3456789 is not what most people want, i.e. 012-3456789.) You'll
     * need to do this yourself additionally, using validateDutchPhoneNr().
     *
     * @param $data
     *   Array with person and/or address data.
     */
    public static function normalizePersonAddress(&$data)
    {

        if (!empty($data['country_code'])) {
            // country_code can contain names as well as ISO9166 country codes;
            // normalize it to AFAS code.
            // NOTE: country_code is assumed NOT to contain an AFAS 1/3 letter country
            // code (because who uses these anyway?); these would be emptied out!
            if (strlen($data['country_code']) > 3) {
                $data['country_code'] = static::convertCountryName($data['country_code'], 3);
            } else {
                $data['country_code'] = static::convertIsoCountryCode($data['country_code']);
            }
        }

        $matches = [];
        if (!empty($data['street']) && empty($data['house_number']) &&
            empty($data['house_number_ext'])
            // Split off house number and possible extension from street,
            // because AFAS has separate fields for those. We do this _only_ for
            // defined countries where the splitting of house numbers is common.
            // (This is a judgment call, and the list of countries is arbitrary,
            // but there's slightly less risk of messing up foreign addresses
            // that way.) 'No country' is assumed to be 'NL' since AFAS is
            // NL-centric.
            // This code comes from addressfield_tfnr module and was adjusted
            // later to conform to AFAS' definition of "extension".
            && (empty($data['country_code']) || in_array($data['country_code'],
                    ['B', 'D', 'DK', 'F', 'FIN', 'H', 'NL', 'NO', 'S']))
            && preg_match('/^
          (.*?\S) \s+ (\d+) # normal thoroughfare, followed by spaces and a number;
                            # non-greedy because for STREET NR1 NR2, "nr1" should
                            # end up in the number field, not "nr2".
          (?:\s+)?          # optionally separated by spaces
          (\S.{0,29})?      # followed by optional suffix of at most 30 chars (30 is the maximum in the AFAS UI)
          \s* $/x', $data['street'], $matches)
        ) { // x == extended regex pattern
            // More notes about the pattern:
            // - theoretically a multi-digit number could be split into
            //   $matches[2/3]; this does not happen because the 3rd match is
            //   non-greedy.
            // - for numbers like 2-a and 2/a, we include the -// into
            //   $matches[3] on purpose: if AFAS has suffix "-a" or "/a" it
            //   prints them like "2-a" or "2/a" when printing an address. On
            //   the other hand, if AFAS has suffix "a" or "3", it prints them
            //   like "2 a" or "2 3".
            $data['street'] = ltrim($matches[1]);
            $data['house_number'] = $matches[2];
            if (!empty($matches[3])) {
                $data['house_number_ext'] = rtrim($matches[3]);
            }
        } elseif (!empty($data['house_number']) && empty($data['house_number_ext'])) {
            // Split off extension from house number
            $matches = [];
            if (preg_match('/^ \s* (\d+) (?:\s+)? (\S.{0,29})? \s* $/x', $data['house_number'], $matches)) {
                // Here too, the last ? means $matches[2] may be empty, but
                // prevents a multi-digit number from being split into
                // $matches[1/2].
                if (!empty($matches[2])) {
                    $data['house_number'] = $matches[1];
                    $data['house_number_ext'] = rtrim($matches[2]);
                }
            }
        }

        if (!empty($data['last_name']) && empty($data['prefix'])) {
            // Split off (Dutch) prefix from last name.
            // NOTE: creepily hardcoded stuff. Spaces are necessary, and sometimes
            // ordering matters! ('van de' before 'van')
            $name = strtolower($data['last_name']);
            foreach ([
                         'de ',
                         'v.',
                         'v ',
                         'v/d ',
                         'v.d.',
                         'van de ',
                         'van der ',
                         'van ',
                         "'t "
                     ] as $value) {
                if (strpos($name, $value) === 0) {
                    $data['prefix'] = rtrim($value);
                    $data['last_name'] = trim(substr($data['last_name'], strlen($value)));
                    break;
                }
            }
        }

        // Set search name
        if (!empty($data['last_name']) && empty($data['search_name'])) {
            // Zoeknaam: we got no request for a special definition of this, so:
            $data['search_name'] = strtoupper($data['last_name']);
            // Max length is 10, and we don't need to be afraid of duplicates.
            if (strlen($data['search_name']) > 10) {
                $data['search_name'] = substr($data['search_name'], 0, 10);
            }
        }

        if (!empty($data['first_name']) && empty($data['initials'])) {
            $data['first_name'] = trim($data['first_name']);

            // Check if first name is really only initials. If so, move it.
            // AFAS' automatic resolving code in its new-(contact)person UI
            // thinks anything is initials if it contains a dot. It will thenx
            // prevents a place spaces in between every letter, but we won't do
            // that last part. (It may be good for user UI input, but coded data
            // does not expect it.)
            if (strlen($data['first_name']) == 1
                || strlen($data['first_name']) < 16
                && strpos($data['first_name'], '.') !== false
                && strpos($data['first_name'], ' ') === false
            ) {
                // Dot but no spaces, or just one letter: all initials; move it.
                $data['initials'] = strlen($data['first_name']) == 1 ?
                    strtoupper($data['first_name']) . '.' : $data['first_name'];
                unset($data['first_name']);
            } elseif (preg_match('/^[A-Za-z \-]+$/', $data['first_name'])) {
                // First name only contains letters, spaces and hyphens. In this
                // case (which is probeably stricter than the AFAS UI), create
                // initials.
                $data['initials'] = '';
                foreach (preg_split('/[- ]+/', $data['first_name']) as $part) {
                    // Don't separate initials by spaces, only dot.
                    $data['initials'] .= strtoupper(substr($part, 0, 1)) . '.';
                }
            }
            // Note if there's both a dot and spaces in 'first_name' we skip it.
        }
    }

    /**
     * Construct XML representing one or more AFAS objects.
     *
     * The only reason this has not been officially deprecated yet is that we're
     * afraid callers might forget passing $embed_action=true (which is
     * essential).
     *
     * @param $type
     *   See normalizeDataToSend().
     * @param array $data
     *   See normalizeDataToSend().
     * @param string $fields_action
     *   See normalizeDataToSend().
     * @param string $parent_type
     *   (optional) Leave empty.
     * @param int $indent
     *   (optional) Add spaces before each tag and end each line except the last
     *   one with newline, unless $indent < 0 (then do not add anything).
     *
     * @return string
     *   XML payload to send to an Update Connector on a SOAP API/Connection.
     *
     * @see normalizeDataToSend()
     * @see xmlEncodeNormalizedData()
     */
    public static function constructXml($type, array $data, $fields_action = '', $parent_type = '', $indent = -1)
    {
        return static::xmlEncodeNormalizedData(
            static::normalizeDataToSend($type, $data, $fields_action, true, $parent_type),
            $indent,
            $parent_type
        );
    }

    /**
     * Encode already normalized data as XML, suitable for sending through SOAP.
     *
     * @param array $data
     *   Data which is already normalized, i.e. the return value from
     *   normalizeDataToSend(). (Generally speaking, this must have #action keys
     *   so the $embed_action parameter to normalizeDataToSend() must have been
     *   true.
     * @param int $indent
     *   (optional) Add spaces before each tag and end each line except the last
     *   one with newline. By default / if $indent < 0, do not add any spacing.
     * @param string $parent_type
     *   (optional) In practice, this is only set by recursive calls (and it's
     *   effectively used as a boolean, to indicate that we're creating an XML
     *   snippet for embedding inside a larger string).
     *
     * @return string
     *   XML payload to send to an Update Connector on a SOAP API/Connection.
     */
    public static function xmlEncodeNormalizedData(array $data, $indent = -1, $parent_type = '') {
        // Data is always a one-element array with inside it a one-element array
        // whose key is 'Element'. We can be this strict because we assume our
        // value is always a normalizeDataToSend() return value.
        if (count($data) != 1) {
            throw new InvalidArgumentException("Data argument must be a single array value, keyed by the object/Update Connector name. (Which again must be one array value, keyed by 'Element'");
        }
        $type = key($data);
        $data = reset($data);
        $expected_key = key($data);
        if (count($data) != 1 || $expected_key !== 'Element') {
            throw new InvalidArgumentException("Data argument must be a single array value containing yet another single array value whose key is 'Element'.");
        }
        $data = reset($data);
        // $data is now either an array of elements or a single element, which
        // must have either a 'Fields' or 'Objects' key defined, at least.
        if (!is_array($data)) {
            // We're not accepting objects for individual Elements; only
            // alphanumerically keyed arrays, like the normalizeDataToSend()
            // return value.
            throw new InvalidArgumentException("'Element' entry for $type is not an array.");
        }
        if (isset($data['Fields']) || isset($data['Objects'])) {
            // No checks; we'll do that in below foreach.
            $data = array($data);
        }

        // Object header
        $xml = $indent_str1 = $indent_str2 = $indent_str3 = '';
        if ($indent >= 0) {
            // This is how the XML starts, and also the number of spaces before
            // the 'type end tag' below. We won't use a $indent_str0 for that:
            // we'll always need an if/then anyway since this has no LF.
            $xml = str_repeat(' ', $indent);
            $extra_spaces = str_repeat(' ', static::XML_TAB_WIDTH);
            // LF + Indentation before Element tag:
            $indent_str1 = "\n" . $xml . $extra_spaces;
            // LF + Indentation before Fields/Objects tag:
            $indent_str2 = $indent_str1 . $extra_spaces;
            // LF + Indentation before individual field values:
            $indent_str3 = $indent_str2 . $extra_spaces;
        }
        $xml .= '<' . $type . ($parent_type ? '>' : ' xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">');

        $expected_key = 0;
        foreach ($data as $element_key => $element) {
            // Be strict and allow only zero-based arrays of elements.
            if ($element_key !== $expected_key) {
                throw new InvalidArgumentException("'Element' entry for $type contains an array of elements whose keys are not (sequentially) zero-based: key '$element_key' was found in the wrong place'.");
            }
            $expected_key++;

            $fields = isset($element['Fields']) ? $element['Fields'] : array();
            $objects = isset($element['Objects']) ? $element['Objects'] : array();
            $id_attribute = '';
            $action_attribute = '';
            if (isset($element['#action'])) {
                if (!is_string($element['#action'])) {
                    throw new InvalidArgumentException("'#action' key in inside Element must hold string value.");
                }
                if ($element['#action']) {
                    // Add the Action attribute if it's explicitly specified;
                    // otherwise don't. (In this method, we make no assumptions
                    // on what it does or whether its behavior recurses into
                    // child elements; we only assume that "" is not a valid
                    // Action value so we should not set that.)
                    $action_attribute = ' Action="' . $element['#action'] . '"';
                }
                unset($element['#action']);
            }
            if (count($element) > ($fields ? 1 : 0) + ($objects ? 1 : 0)) {
                // We'll have to do more checking. There can be maximum 4 keys,
                // and the fourth must be the ID.
                unset($element['Fields']);
                unset($element['Objects']);
                if (count($element) > 1) {
                    // We'll ignore #action in this message; that's a strange
                    // construct that might have been inserted by
                    // normalizeDataToSend() rather than the calling code.
                    throw new InvalidArgumentException("Element must hold maximum one ID value besides Fields/Objects, but we found more than one; keys are " . implode(', ', $element) . '.');
                }
                $value = reset($element);
                $key = (string) key($element);
                if (substr($key, 0, 1) !== '@') {
                    throw new InvalidArgumentException("Illegal key '$key' found inside Element, which can only hold keys 'Fields', 'Objects' and a special @Id key.");
                }
                if (!is_int($value) && !is_string($value)) {
                    throw new InvalidArgumentException("'$key' key in inside Element must hold integer/string value.");
                }
                $id_attribute = ' ' . substr($key, 1) . '="' . $value . '"';
            }

            // Each element is in its own 'Element' tag (unlike the input
            // argument which has an array of elements in one 'Element' key,
            // because multiple 'Element' keys cannot exist in one object or
            // JSON string),
            $xml .= "$indent_str1<Element$id_attribute>";

            // Always add Fields tag, also if it's empty. (No idea if that's
            // necessary, but that's apparently how I tested it 5 years ago.)
            $xml .= "$indent_str2<Fields$action_attribute>";
            if (!is_array($fields)) {
                throw new InvalidArgumentException("'Fields' property of '$type' object must be an array.");
            }
            foreach ($fields as $name => $value) {
                if (!is_scalar($value)) {
                    throw new InvalidArgumentException("'$name' field of '$type' object must be scalar.");
                }
                if (is_bool($value)) {
                    // Boolean values are encoded as 0/1 in AFAS XML.
                    $value = $value ? '1' : '0';
                }
                $xml .= $indent_str3 . (isset($value)
                        ? "<$name>" . htmlspecialchars($value, ENT_QUOTES | ENT_XML1) . "</$name>"
                        // Value is passed but null or default value is null
                        : "<$name xsi:nil=\"true\"/>");
            }
            $xml .= "$indent_str2</Fields>";

            if (!is_array($objects)) {
                throw new InvalidArgumentException("'Objects' property of '$type' object must be an array.");
            }
            if ($objects) {
                $xml .= "$indent_str2<Objects>";
                foreach ($objects as $name => $value) {
                    if (!is_array($value)) {
                        throw new InvalidArgumentException("Value for '$name' object embedded inside '$type' object must be array.");
                    }
                    // We'll do more checks on $value in the recursive call.
                    // (This is again supposed to be a one-element array with
                    // 'Element' as key, and object(s) as value.)
                    $xml .= ($indent < 0 ? '' : "\n") . static::xmlEncodeNormalizedData(
                            [ $name => $value ],
                            $indent < 0 ? $indent : $indent + 3 * static::XML_TAB_WIDTH,
                            $type
                        );
                }
                $xml .= "$indent_str2</Objects>";
            }
            $xml .= "$indent_str1</Element>";
        }
        // Add closing XML tag.
        if ($indent >= 0) {
            // Do not end the whole string with newline.
            $xml .= "\n" . str_repeat(' ', $indent) . "</$type>";
        } else {
            $xml .= "</$type>";
        }

        return $xml;
    }


    /**
     * 'Normalizes' AFAS object representation to send in insert/update queries.
     *
     * TL/DR: Always pass $action ("insert" or "update"; "update" should not be
     * necessary for REST, but still); always set $embed_action to true when
     * wanting to output XML; pass ID values in '#id' key, not '@__Id'.
     *
     * 'Normalizing' means validating the structure, adding default values where
     * necessary, converting 'more readable' aliases' to system field names used
     * by AFAS.
     *
     * At the time of writing this, REST/JSON is untested and it is not known
     * whether all the arguments make sense in the REST/JSON world. However, at
     * least the structure of the output seems suitable for sending in to the
     * REST API, so it's ready for extensive testing by whoever wants to. The
     * output has been tested with the SOAP API: when $embed_action is true,
     * it can be converted to XML which will work correctly for updating /
     * inserting objects.
     *
     * AFAS installations with custom fields will typically want to extend
     * objectTypeInfo() in a subclass, and call this method either through that
     * subclass or through Connection::sendData() after injecting the subclass
     * name into the Connection. That should make the custom fields available.
     *
     * We hope that the below code catches all strange/dangerous combinations of
     * 'id' /  $action / AutoNum / MatchXXX values and 'embedding
     * objects'. AFAS behavior and our assumptions are explained in
     * update-payloads.md, and in code comments in objectTypeInfo(). We can't be
     * totally sure, though. Please read the ocumentation before dealing with
     * knPerson/knOrganisation objects; it may save lots of time and wrong
     * assumptions.
     *
     * @param $type
     *   The type of object, i.e. the 'Update Connector' name to send this data
     *   into. See objectTypeInfo() for possible values.
     * @param array $data
     *   Data to normalize, representing one or more objects; see
     *   objectTypeInfo() for possible values in an object. If any value in the
     *   (first dimension of the) array is scalar, it's assumed to be a single
     *   object; if it contains only non-scalars (which must be arrays), it's
     *   assumed to be several objects. Note that it's possible to pass one
     *   object containing no fields and only embedded 'child' objects, only by
     *   passing it as an 'array containing one object'. The keys inside a
     *   single object can be:
     *   - field names or aliases (as defined in objectTypeInfo());
     *   - names of object types (see $type argument)
     *   - '#id', which holds the 'id value' for an object. (For REST payloads,
     *     this will be converted to a '@__Id' key; passing this key itself as
     *     a field name is not allowed here. For XML/SOAP payloads, this will be
     *     converted to an id attribute in the Element tag.)
     *   - #action: this is for fringe cases: it's only allowed as part of
     *     'child' objects, to specify a $action for the child object which
     *     differs from its parents. See update-payloads for one of the very few
     *     examples currently known.
     *   The format is fairly strict: this method will throw exceptions when
     *   e.g. required data is not present, present data is not recognized, ...
     * @param string $action
     *   (optional) Allowed values: "insert", "update", "delete", "". These
     *   can influence elements' fields in the return value, by e.g. adding
     *   default values. (Until now, only "insert" is known to have an effect
     *   for REST/JSON generation. For XML, the difference between "update" and
     *   "" is only the Action attribute included in the XML. While XML without
     *   an 'Action' attribute is not known to make any sense, we allow "" for
     *   at least being able to generate it.
     * @todo the above is not actually true and should probably be marked as a bug.
     * @param bool $embed_action
     *   (optional) If true, embed '#action' values in all elements in the
     *   return value, containing the $action value (or an existing '#action'
     *   value of an embedded element). The return value will not be suitable
     *   for passing into the REST API but suitable for passing into
     *   xmlEncodeNormalizedData(), for rendering correct Action attributes.
     *   In practice,  when generating an XML message for the SOAP API, it is
     *   required to pass true here, or to insert #action values into the
     *   returned objects by yourself.
     * @param string $parent_type
     *   (optional) If nonempty, the return value will be suitable for embedding
     *   inside the parent type, which in some cases is a little different from
     *   a 'standalone' value.
     *
     * @return array
     *   An array which is suitable either for sending in to POST/PUT(/DELETE?)
     *   REST API requests, after converting it to JSON - or for converting it
     *   to XML for the SOAP API, depending on the value of $embed_action.
     *
     * @throws \InvalidArgumentException
     *   If arguments have an unrecognized / invalid format.
     *
     * @see objectTypeInfo()
     */
    public static function normalizeDataToSend($type, array $data, $action = '', $embed_action = false, $parent_type = '')
    {
        if (!in_array($action, ['insert', 'update', 'delete', ''], true)) {
            throw new InvalidArgumentException("Unknown value $action for fields_action parameter.");
        }
        if (!$data) {
            throw new InvalidArgumentException("'$type' object holds no data.");
        }

        // Determine if $element holds a single object or an array of objects:
        // we assume the latter if all values are arrays.
        foreach ($data as $key => $element) {
            if (is_scalar($element)) {
                // Normalize $data to an array of objects.
                $data = [$data];
                break;
            }
        }

        $normalized_elements = [];
        foreach ($data as $key => $element) {
            // Get type info. We do this for each element inside the loop,
            // because $info can differ with $element. Notes:
            // - We don't know fore sure that $action makes sense for the REST
            //   API; we've never tried it. This method is also used for
            //   generating o structure that will be converted into XML for the
            //   SOAP API, so we'll keep it.
            // - It's unclear to me (even though I wrote this code myself 5
            //   years ago) why $element['#action'] is apparently not used for
            //   deriving type info for this element, but only for child
            //   elements. (It _is_ used for inserting the 'action' parameter
            //   inside _this_ element's 'Fields' XML tag, though.) I did use
            //   this structure successfully so I'll keep it the same, but still
            //   this complicates things - and it feels like this could
            //   necessitate a (incompatible) change at some point. (See also
            //   the exception with empty $parent_type below.)
            // @todo think some more about changing this now. We probably never actually _used_ this, given $element['#action'] cannot be on the 1st level, right?
            $info = static::objectTypeInfo($type, $parent_type, $element, $action);
            // @todo FIX: we seem to have a bug with ViKc default not being set when insert-inside-update?
            // @todo FIX: (and also with the KnBasicAddressAdr:PbAd being set when it shouldn't, when update-inside-update?)
            // @todo FIX: (also check why default values are still added to embedded person object when $action is empty. Not a clear bug, but potentially confusing because it requires us to pass "update" for JSON.)
            if (empty($info)) {
                throw new InvalidArgumentException("'$type' object has no type info.");
            }

            // Construct new element with an optional id + fields + objects for
            // this type (and #action, if specified by the caller).
            $normalized_element = [];

            // Derive $element_action. The only thing that this does (except for
            // including it in the element if requested; see just below) is set
            // the $action parameter to a recursive call, so it only has a
            // possible effect on the return values of objectTypeInfo() calls
            // made by those recursive calls.
            if (isset($element['#action'])) {
                if (empty($parent_type)) {
                    // This really is an override and we want to keep it that
                    // way. When possible, people must specify a correct
                    // $action instead of #action, because it's much more
                    // readable. This was the assumption in the 5 year old code
                    // that constructs XML, so we kept it so far. Note however:
                    // - '#action' will be set on the first level of the
                    //   _return value_, if $action is specified.
                    // - $element['#action'] is currently not fully equivalent
                    //   to $action, because -as noted above-
                    //   $element['#action'] does not influence the current
                    //   $info, yet influences child elements. So this last
                    //   thing is extra likely to be "better off changed,
                    //   because that seems more logical".
                    throw new InvalidArgumentException('#action override is only allowed in embedded objects.');
                }
                // Not sure whether '' makes sense as an override?
                // Also maybe we should disallow deletes inside inserts etc?
                if (!in_array($element['#action'], ['insert', 'update', 'delete'], true)) {
                    throw new InvalidArgumentException("Unknown value '{$element['#action']}' for #action inside '$type' object.");
                }
                $element_action = $element['#action'];
                unset($element['#action']);
            } else {
                $element_action = $action;
            }
            // Include #action if requested by the caller (typically because
            // it's needed inside XML). Rules:
            // - The original 5 year old code, constructing XML, passed
            //   $element_action up into recursive calls, meaning child elements
            //   without #action defined explicitly, would still have the parent
            //   value set explicitly. We're going to keep doing this, in the
            //   structure we create in this method. (It probably makes sense in
            //   practice, especially since an empty $element_action does not
            //   really 'mean' anything, and we mostly want to act as if an
            //   action is always defined, unless it's explicitly set to "" in a
            //   child object.)
            // - (Unlike the old code) if we have an empty $element_action,
            //   we'll also include that. (This will lead to action _not_ being
            //   set in the resulting XML, because 'Action=""' is not a valid
            //   thing. This also allows 'emptying the action in child elements'
            //   to make it technically possible to construct the corresponding
            //   XML... even though we assume it to be senseless.)
            // Both things mean we won't need to make assumptions about whether/
            // how the separate new XML construction code implements
            // 'inheritance' of actions; the XML will always end up the same as
            // with the old code.
            if ($embed_action) {
                $normalized_element['#action'] = $element_action;
            }

            if (!empty($element['#id'])) {
                if (empty($info['id_field'])) {
                    throw new InvalidArgumentException("Id value provided but no id-field defined for '$type' object.");
                }
                $normalized_element['@' . $info['id_field']] = $element['#id'];
            }
            unset($element['#id']);

            // Convert our element data into fields, check required fields, and
            // add default values for fields (where defined). About definitions:
            // - if required = true and default is given, then
            //   - the default value is sent if no data value is passed
            //   - an exception is (only) thrown if the passed value is null.
            // - if the default is null (or value given is null & not
            //   'required'), then null is passed.
            foreach ($info['fields'] as $name => $map_properties) {
                $value_present = true;

                // Get value from the property equal to the field name (case
                // sensitive!), or the alias. If two values are present with
                // both field name and alias, we throw an exception.
                $value_exists_by_alias = isset($map_properties['alias']) && array_key_exists($map_properties['alias'], $element);
                if (array_key_exists($name, $element)) {
                    if ($value_exists_by_alias) {
                        throw new InvalidArgumentException("'$type' object has a value provided by both its field name $name and alias $map_properties[alias].");
                    }
                    $value = $element[$name];
                    unset($element[$name]);
                } elseif ($value_exists_by_alias) {
                    $value = $element[$map_properties['alias']];
                    unset($element[$map_properties['alias']]);
                } elseif (array_key_exists('default', $map_properties)) {
                    $value = $map_properties['default'];
                } else {
                    $value_present = false;
                }

                // Required fields will disallow non-passed values, or passed
                // null values.
                if (!empty($map_properties['required'])
                    && (!$value_present || !isset($value))
                ) {
                    $property = $name . (isset($map_properties['alias']) ? " ({$map_properties['alias']})" : '');
                    throw new InvalidArgumentException("No value given for required '$property' field of '$type' object.");
                }

                if ($value_present) {
                    if (isset($value)) {
                        if (!is_scalar($value)) {
                            $property = $name . (isset($map_properties['alias']) ? " ({$map_properties['alias']})" : '');
                            throw new InvalidArgumentException("'$property' property of '$type' object must be scalar.");
                        }
                        if (!empty($map_properties['type'])) {
                            switch ($map_properties['type']) {
                                case 'boolean':
                                    $value = (bool) $value;
                                    break;
                                case 'long':
                                case 'decimal':
                                    if (!is_numeric($value)) {
                                        $property = $name . (isset($map_properties['alias']) ? " ({$map_properties['alias']})" : '');
                                        throw new InvalidArgumentException("'$property' property of '$type' object must be numeric.");
                                    }
                                    if ($map_properties['type'] === 'long' && strpos((string)$value, '.') !== false) {
                                        $property = $name . (isset($map_properties['alias']) ? " ({$map_properties['alias']})" : '');
                                        throw new InvalidArgumentException("'$property' field value of '$type' object must be a 'long'.");
                                    }
                                    // For decimal, we could also check digits,
                                    // but we're not going that far yet.
                                    break;
                                case 'date':
                                    // @todo format in standard way, once we know that's necessary
                                    break;
                                default:
                                    $value = trim($value);
                            }
                        } else {
                            $value = trim($value);
                        }
                    }
                    $normalized_element['Fields'][$name] = $value;
                }
            }

            if (!empty($element)) {
                // Add other embedded objects. (We assume all remaining element
                // values are indeed objects. If not, an error will be thrown.)
                $normalized_element['Objects'] = [];

                foreach ($info['objects'] as $name => $alias) {
                    $value_present = true;

                    // Get value from the property equal to the tag (case
                    // sensitive!), or the alias. If two values are present with
                    // both tag and alias, we throw an exception.
                    if (array_key_exists($name, $element)) {
                        if (array_key_exists($alias, $element)) {
                            throw new InvalidArgumentException("'$type' object has a value provided by both its property name $name and alias $alias.");
                        }
                        $value = $element[$name];
                        unset($element[$name]);
                    } elseif (array_key_exists($alias, $element)) {
                        $value = $element[$alias];
                        unset($element[$alias]);
                    } else {
                        $value_present = false;
                    }

                    if ($value_present) {
                        if (!is_array($value)) {
                            $property = $name . (isset($alias) ? " ($alias)" : '');
                            throw new InvalidArgumentException("Value for '$property' object embedded inside '$type' object must be array.");
                        }
                        // Since normalizeDataToSend always adds a one-element
                        // array with $name as the key: we array_merge it
                        // instead of appending it (which would add an extra
                        // layer).
                        $normalized_element['Objects'] = array_merge(
                            $normalized_element['Objects'],
                            static::normalizeDataToSend($name, $value, $element_action, $embed_action, $type)
                        );
                    }
                }
            }

            // Throw error for unknown element data (for which we have not seen
            // a field/object definition).
            if (!empty($element)) {
                $keys = "'" . implode(', ', array_keys($element)) . "'";
                throw new InvalidArgumentException("Unmapped element values provided for '$type' object: keys are $keys.");
            }

            $normalized_elements[] = $normalized_element;
        }

        // 'Element' can hold a single object or an array. Apparently this is
        // arbitrary. If we have a single object, 'de-normalize' it to make
        // make notation simpler. (Note it's not proven to be arbitrary: some
        // locations in an array structure might require 'Element' to be a
        // single object or array of objects. But we don't know about this so we
        // don't test this. In other words: this is a place where the current
        // code is _not_ strict, despite what the function documentation says.)
        return [$type => ['Element' => count($normalized_elements) == 1 ? $normalized_elements[0] : $normalized_elements]];
    }

    /**
     * Return info for a certain type definition. (A certain Update Connector.)
     *
     * This definition is based on what AFAS calls the 'XSD Schema' for SOAP,
     * which you can get though a Data Connector, and is amended with extra info
     * like more understandable aliases for the field names, and default values.
     *
     * AFAS installations with custom fields will typically want to extend this
     * method in a subclass. Its name can be injected into the Connection class,
     * for using Connection::sendData() with those custom fields. The same goes
     * for standard object types which have not been included yet below - and
     * everyone's willing to send PRs to add those to the library code.
     *
     * @param string $type
     *   The type of object / Update Connector.
     * @param string $parent_type
     *   (optional) If nonempty, the generated info will be tailored for
     *   embedding within the parent type; this can influence the presence of
     *   some fields.
     * @param array $data
     *   (optional) Input data to 'normalize' using the returned info. This can
     *   influence e.g. some defaults.
     * @param string $action
     *   (optional) Action to fill in 'fields' tag; can be "insert", "update",
     *   "delete", "". This can influence e.g. some defaults.
     *
     * @return array
     *   Array with possible keys: 'id_field', 'fields' and 'objects'. See
     *   the code. Empty array if the type is unknown.
     *
     * @see constructXml()
     * @see normalizeDataToSend()
     */
    public static function objectTypeInfo($type, $parent_type = '', array $data = [], $action = '')
    {

        $inserting = $action === 'insert';

        $info = [];
        switch ($type) {
            // Even though they are separate types, there is no standalone
            // updateConnector for addresses.
            case 'KnBasicAddressAdr':
            case 'KnBasicAddressPad':
                $info = [
                    'fields' => [
                        // Land (verwijzing naar: Land => AfasKnCountry)
                        'CoId' => [
                            'alias' => 'country_code',
                        ],
                        /*   PbAd = 'is postbusadres' (if True, HmNr has number of P.O. box)
                         *   Ad, HmNr, ZpCd are required.
                         *      (and a few lines below, the docs say:)
                         *   Rs is _also_ " 'essential', even if ResZip==true, because if Zip
                         *      could not be resolved, the specified value of Rs is taken."
                         *      So we'll make it required too.
                         */
                        'PbAd' => [
                            'alias' => 'is_po_box',
                            'type' => 'boolean',
                            'required' => true,
                            'default!' => false,
                        ],
                        // Toev. voor straat
                        'StAd' => [],
                        // Straat
                        'Ad' => [
                            'alias' => 'street',
                            'required' => true,
                        ],
                        // Huisnummer
                        'HmNr' => [
                            'alias' => 'house_number',
                            'type' => 'long',
                        ],
                        // Toev. aan huisnr.
                        'HmAd' => [
                            'alias' => 'house_number_ext',
                        ],
                        // Postcode
                        'ZpCd' => [
                            'alias' => 'zip_code',
                            'required' => true,
                        ],
                        // Woonplaats (verwijzing naar: Woonplaats => AfasKnResidence)
                        'Rs' => [
                            'alias' => 'town',
                            'required' => true,
                        ],
                        // Adres toevoeging
                        'AdAd' => [],
                        // From "Organisaties toevoegen en wijzigen (UpdateConnector KnOrganisation)":
                        // Bij het eerste adres (in de praktijk bij een nieuw record) hoeft u geen begindatum aan te leveren in het veld 'BeginDate' genegeerd.
                        // Als er al een adres bestaat, geeft u met 'BeginDate' de ingangsdatum van de adreswijziging aan.
                        // Ingangsdatum adreswijziging (wordt genegeerd bij eerste datum)
                        'BeginDate' => [
                            'type' => 'date',
                            'default!' => date('Y-m-d', REQUEST_TIME),
                        ],
                        'ResZip' => [
                            'alias' => 'resolve_zip',
                            'type' => 'boolean',
                            'default!' => false,
                        ],
                    ],
                ];
                break;

            case 'KnContact':
                // This has no id_field. Updating standalone knContact values is
                // possible by passing BcCoOga + BcCoPer in an update structure.
                $info = [
                    'objects' => [
                        'KnBasicAddressAdr' => 'address',
                        'KnBasicAddressPad' => 'postal_address',
                    ],
                    'fields' => [
                        // Code organisatie
                        'BcCoOga' => [
                            'alias' => 'organisation_code',
                        ],
                        // Code persoon
                        'BcCoPer' => [
                            'alias' => 'person_code',
                        ],
                        // Postadres is adres
                        'PadAdr' => [
                            'type' => 'boolean',
                        ],
                        // Afdeling contact
                        'ExAd' => [],
                        // Functie (verwijzing naar: Tabelwaarde,Functie contact => AfasKnCodeTableValue)
                        'ViFu' => [],
                        // Functie op visitekaart
                        'FuDs' => [
                            // Abbreviates 'function description', but that seems too Dutch.
                            'alias' => 'job_title',
                        ],
                        // Correspondentie
                        'Corr' => [
                            'type' => 'boolean',
                        ],
                        // Voorkeursmedium (verwijzing naar: Tabelwaarde,Medium voor correspondentie => AfasKnCodeTableValue)
                        'ViMd' => [],
                        // Telefoonnr. werk
                        'TeNr' => [
                            'alias' => 'phone',
                        ],
                        // Fax werk
                        'FaNr' => [
                            'alias' => 'fax',
                        ],
                        // Mobiel werk
                        'MbNr' => [
                            'alias' => 'mobile',
                        ],
                        // E-mail werk
                        'EmAd' => [
                            'alias' => 'email',
                        ],
                        // Homepage
                        'HoPa' => [
                            'alias' => 'homepage',
                        ],
                        // Toelichting
                        'Re' => [
                            'alias' => 'comment',
                        ],
                        // Geblokkeerd
                        'Bl' => [
                            'alias' => 'blocked',
                            'type' => 'boolean',
                        ],
                        // T.a.v. regel
                        'AtLn' => [],
                        // Briefaanhef
                        'LeHe' => [],
                        // Sociale netwerken
                        'SocN' => [],
                        // Facebook
                        'Face' => [
                            'alias' => 'facebook',
                        ],
                        // LinkedIn
                        'Link' => [
                            'alias' => 'linkedin',
                        ],
                        // Twitter
                        'Twtr' => [
                            'alias' => 'twitter',
                        ],
                        // Persoon toegang geven tot afgeschermde deel van de portal(s)
                        'AddToPortal' => [
                            'type' => 'boolean',
                        ],
                        // E-mail toegang
                        'EmailPortal' => [],
                    ],
                ];
                if ($parent_type === 'KnOrganisation' || $parent_type === 'KnPerson') {
                    $info['fields'] += [
                        // Soort Contact
                        // Values:  AFD:Afdeling bij organisatie   AFL:Afleveradres
                        // if inside knOrganisation: + PRS:Persoon bij organisatie (alleen mogelijk i.c.m. KnPerson tak)
                        //
                        // The description in 'parent' update connectors' (KnOrganisation, knContact) KB pages is:
                        // "Voor afleveradressen gebruikt u de waarde 'AFL': <ViKc>AFL</ViKc>"
                        'ViKc' => [
                            'alias' => 'contact_type',
                        ],
                    ];

                    // According to the XSD, a knContact can contain a knPerson
                    // if it's inside a knOrganisation, but not if it's
                    // standalone.
                    if ($parent_type === 'KnOrganisation') {
                        $info['objects']['KnPerson'] = 'person';

                        // If we specify a person in the data too, 'Persoon' is
                        // the default.
                        if (!empty($data['KnPerson']) || !empty($data['person'])) {
                            $info['fields']['ViKc']['default'] = 'PRS';
                        }
                    }

                    unset($info['fields']['BcCoOga']);
                    unset($info['fields']['BcCoPer']);
                    unset($info['fields']['AddToPortal']);
                    unset($info['fields']['EmailPortal']);
                }
                break;

            case 'KnPerson':
                $info = [
                    'objects' => [
//            'KnBankAccount' => 'bank_account',
                        'KnBasicAddressAdr' => 'address',
                        'KnBasicAddressPad' => 'postal_address',
                        'KnContact' => 'contact',
                    ],
                    'fields' => [
                        // Postadres is adres
                        'PadAdr' => [
                            'type' => 'boolean',
                        ],
                        'AutoNum' => [
                            'type' => 'boolean',
                            // See below for a dynamic default
                        ],
                        /**
                         * If you specify MatchPer and if the corresponding fields have
                         * values, the difference between $action "update" and
                         * "insert" falls away: if there is a match (but only one) the
                         * existing record is updated. If there isn't, a new one is
                         * inserted. If there are multiple matches, or a wrong match method
                         * is specified, AFAS throws an error.
                         *
                         * We make sure that you must explicitly specify a value for this
                         * with $field_action "update" (and get an error if you don't), by
                         * setting the default - see further down.
                         *
                         * NOTE 20150215: updating/inserting a contact/person inside an
                         * organization is only possible by sending in an embedded
                         * knOrganisation -> knContact -> knPerson XML (as far as I know).
                         * But updating existing data is tricky.
                         * Updates-or-inserts work when specifying non-zero match_method, no
                         * BcCo numbers and no $action (if there are no multiple
                         * matches; those will yield an error).
                         * Specifying MatchPer=0 and BcCo for an existing org+person, and no
                         * $action, yields an AFAS error "Object variable or With
                         * block variable not set" (which is a Visual Basic error, pointing
                         * to an error in AFAS' program code). To bypass this error,
                         * $action "update" must be explicitly specified.
                         * When inserting new contact/person objects into an existing
                         * organization (without risking the 'multiple matches' error above)
                         * $action "update" + BcCo + MatchPer=0 must be specified for
                         * the organization, and $action "insert" must be specified
                         * for the contact/person object. (In normalizeDataToSend() use '#action'.)
                         *
                         * NOTE - for Qoony sources in 2011 (which inserted KnPerson objects
                         *   inside KnSalesRelationPer), BcCo value 3 had the comment
                         *   "match customer by mail". They used 3 until april 2014, when
                         *   suddenly updates broke, giving "organisation vs person objects"
                         *   and "multiple person objects found for these search criteria"
                         *   errors. So apparently the official description (below) was not
                         *   accurate until 2014, and maybe the above was implemented?
                         *   While fixing the breakage, AFAS introduced an extra value for
                         *   us:
                         * 9: always update the knPerson objects (which are at this moment
                         *    referenced by the outer object) with the given data.
                         *    (When inserting instead of updating data, I guess this falls
                         *    back to behavior '7', given our usage at Qoony.)
                         */
                        // Persoon vergelijken op
                        // Values:  0:Zoek op BcCo (Persoons-ID)   1:Burgerservicenummer   2:Naam + voorvoegsel + initialen + geslacht   3:Naam + voorvoegsel + initialen + geslacht + e-mail werk   4:Naam + voorvoegsel + initialen + geslacht + mobiel werk   5:Naam + voorvoegsel + initialen + geslacht + telefoon werk   6:Naam + voorvoegsel + initialen + geslacht + geboortedatum   7:Altijd nieuw toevoegen
                        'MatchPer' => [
                            'alias' => 'match_method',
                        ],
                        // Organisatie/persoon (intern)
                        // From "Organisaties toevoegen en wijzigen (UpdateConnector KnOrganisation)":
                        // "Do not deliver the 'BcId' field."
                        // (Because it really is internal. So why should we define it?)
                        //'BcId' => [
                        //  'type' => 'long',
                        //),
                        // Nummer, 1-15 chars
                        'BcCo' => [
                            // 'ID' would be more confusing because it's not the internal ID.
                            'alias' => 'number',
                        ],
                        'SeNm' => [
                            'alias' => 'search_name',
                        ],
                        // Roepnaam
                        'CaNm' => [
                            'alias' => 'name',
                        ],
                        // Voornaam
                        'FiNm' => [
                            'alias' => 'first_name',
                            'required' => true,
                        ],
                        // initials
                        'In' => [
                            'alias' => 'initials',
                        ],
                        'Is' => [
                            'alias' => 'prefix',
                        ],
                        'LaNm' => [
                            'alias' => 'last_name',
                            'required' => true,
                        ],
                        // Geboortenaam apart vastleggen
                        'SpNm' => [
                            'type' => 'boolean',
                            'default' => false,
                        ],
                        // Voorv. geb.naam
                        'IsBi' => [],
                        // Geboortenaam
                        'NmBi' => [],
                        // Voorvoegsel partner
                        'IsPa' => [],
                        // Geb.naam partner
                        'NmPa' => [],
                        // Naamgebruik (verwijzing naar: Tabelwaarde,Naamgebruik (meisjesnaam etc.) => AfasKnCodeTableValue)
                        // Values:  0:Geboortenaam   1:Geb. naam partner + Geboortenaam   2:Geboortenaam partner   3:Geboortenaam + Geb. naam partner
                        'ViUs' => [],
                        // Sex (M = Man, V = Vrouw, O = Onbekend)
                        'ViGe' => [
                            'alias' => 'gender',
                            'default' => 'O',
                            // The default is only for explicit inserts; see below. This means
                            // that for data which is ambiguous about being an insert or
                            // update, you must specify a value yourself, otherwise you get an
                            // error "Bij een persoon is het geslacht verplicht.".
                            // There is no other way; if we set a default here for non-inserts
                            // we risk silently overwriting the gender value present in AFAS.
                        ],
                        // Nationaliteit (verwijzing naar: Tabelwaarde,Nationaliteit (NEN1888) => AfasKnCodeTableValue)
                        // Values:  000:Onbekend   NL:Nederlandse   DZ:Algerijnse   AN:Angolese   RU:Burundische   RB:Botswaanse   BU:Burger van Burkina Faso   RCA:Centrafrikaanse   KM:Comorese   RCB:Kongolese   DY:Beninse   ET:Egyptische   EQ:Equatoriaalguinese   ETH:Etiopische   DJI:Djiboutiaanse   GA:Gabonese   WAG:Gambiaanse   GH:Ghanese   GN:Guinese   CI:Ivoriaanse   CV:Kaapverdische   TC:Kameroense   EAK:Kenyaanse   CD:ZaÃ¯rese   LS:Lesothaanse   LB:Liberiaanse   LAR:Libische   RM:Malagassische   MW:Malawische   RMM:Malinese   MA:Marokkaanse   RIM:Burger van Mauritanië   MS:Burger van Mauritius   MOC:Mozambiquaanse   SD:Swazische   RN:Burger van Niger   WAN:Burger van Nigeria   EAU:Ugandese   GW:Guineebissause   ZA:Zuidafrikaanse   ZW:Zimbabwaanse   RWA:Rwandese   ST:Burger van SÃ£o TomÃ© en Principe   SN:Senegalese   WAL:Sierraleoonse   SUD:Soedanese   SP:Somalische   EAT:Tanzaniaanse   TG:Togolese   TS:Tsjadische   TN:Tunesische   Z:Zambiaanse   ZSUD:Zuid-Soedanese   BS:Bahamaanse   BH:Belizaanse   CDN:Canadese   CR:Costaricaanse   C:Cubaanse   DOM:Burger van Dominicaanse Republiek   EL:Salvadoraanse   GCA:Guatemalteekse   RH:HaÃ¯tiaanse   HON:Hondurese   JA:Jamaicaanse   MEX:Mexicaanse   NIC:Nicaraguaanse   PA:Panamese   TT:Burger van Trinidad en Tobago   USA:Amerikaans burger   RA:Argentijnse   BDS:Barbadaanse   BOL:Boliviaanse   BR:Braziliaanse   RCH:Chileense   CO:Colombiaanse   EC:Ecuadoraanse   GUY:Guyaanse   PY:Paraguayaanse   PE:Peruaanse   SME:Surinaamse   ROU:Uruguayaanse   YV:Venezolaanse   WG:Grenadaanse   KN:Burger van Saint Kitts-Nevis   SK:Slowaakse   CZ:Tsjechische   BA:Burger van Bosnië-Herzegovina   GE:Burger van Georgië   AFG:Afgaanse   BRN:Bahreinse   BT:Bhutaanse   BM:Burmaanse   BRU:Bruneise   K:Kambodjaanse   CL:Srilankaanse   CN:Chinese   CY:Cyprische   RP:Filipijnse   TMN:Burger van Toerkmenistan   RC:Taiwanese   IND:Burger van India   RI:Indonesische   IRQ:Iraakse   IR:Iraanse   IL:Israëlische   J:Japanse   HKJ:Jordaanse   TAD:Burger van Tadzjikistan   KWT:Koeweitse   LAO:Laotiaanse   RL:Libanese   MV:Maldivische   MAL:Maleisische   MON:Mongolische   OMA:Omanitische   NPL:Nepalese   KO:Noordkoreaanse   OEZ:Burger van Oezbekistan   PK:Pakistaanse   KG:Katarese   AS:Saoediarabische   SGP:Singaporaanse   SYR:Syrische   T:Thaise   AE:Burger van de Ver. Arabische Emiraten   TR:Turkse   UA:Burger van Oekraine   ROK:Zuidkoreaanse   VN:Viëtnamese   BD:Burger van Bangladesh   KYR:Burger van Kyrgyzstan   MD:Burger van Moldavië   KZ:Burger van Kazachstan   BY:Burger van Belarus (Wit-Rusland)   AZ:Burger van Azerbajdsjan   AM:Burger van Armenië   AUS:Australische   PNG:Burger van Papua-Nieuwguinea   NZ:Nieuwzeelandse   WSM:Westsamoaanse   RUS:Burger van Rusland   SLO:Burger van Slovenië   AG:Burger van Antigua en Barbuda   VU:Vanuatuse   FJI:Fijische   GB4:Burger van Britse afhankelijke gebieden   HR:Burger van Kroatië   TO:Tongaanse   NR:Nauruaanse   USA2:Amerikaans onderdaan   LV:Letse   SB:Solomoneilandse   SY:Seychelse   KIR:Kiribatische   TV:Tuvaluaanse   WL:Sintluciaanse   WD:Burger van Dominica   WV:Burger van Sint Vincent en de Grenadinen   EW:Estnische   IOT:British National (overseas)   ZRE:ZaÃ¯rese (Congolese)   TLS:Burger van Timor Leste   SCG:Burger van Servië en Montenegro   SRB:Burger van Servië   MNE:Burger van Montenegro   LT:Litouwse   MAR:Burger van de Marshalleilanden   BUR:Myanmarese   SWA:Namibische   499:Staatloos   AL:Albanese   AND:Andorrese   B:Belgische   BG:Bulgaarse   DK:Deense   D:Duitse   FIN:Finse   F:Franse   YMN:Jemenitische   GR:Griekse   GB:Brits burger   H:Hongaarse   IRL:Ierse   IS:IJslandse   I:Italiaanse   YU:Joegoslavische   FL:Liechtensteinse   L:Luxemburgse   M:Maltese   MC:Monegaskische   N:Noorse   A:Oostenrijkse   PL:Poolse   P:Portugese   RO:Roemeense   RSM:Sanmarinese   E:Spaanse   VAT:Vaticaanse   S:Zweedse   CH:Zwitserse   GB2:Brits onderdaan   ERI:Eritrese   GB3:Brits overzees burger   MK:Macedonische   XK:Kosovaar
                        //
                        'PsNa' => [],
                        // Geboortedatum
                        'DaBi' => [],
                        // Geboorteland (verwijzing naar: Land => AfasKnCountry)
                        'CoBi' => [],
                        // Geboorteplaats (verwijzing naar: Woonplaats => AfasKnResidence)
                        'RsBi' => [],
                        // BSN
                        'SoSe' => [
                            'alias' => 'bsn',
                        ],
                        // Burgerlijke staat (verwijzing naar: Tabelwaarde,Burgerlijke staat => AfasKnCodeTableValue)
                        'ViCs' => [],
                        // Huwelijksdatum
                        'DaMa' => [],
                        // Datum scheiding
                        'DaDi' => [],
                        // Overlijdensdatum
                        'DaDe' => [],
                        // Titel/aanhef (verwijzing naar: Titel => AfasKnTitle)
                        'TtId' => [
                            // ALG was given in Qoony (where person was inside knSalesRelationPer).
                            // in newer environment where it's inside knOrganisation > knContact,
                            // I don't even see this one in an entry screen.
                            //'default' => 'ALG',
                        ],
                        // Tweede titel (verwijzing naar: Titel => AfasKnTitle)
                        'TtEx' => [],
                        // Briefaanhef
                        'LeHe' => [],
                        // Telefoonnr. werk
                        'TeNr' => [
                            // Note aliases change for KnSalesRelationPer, see below.
                            'alias' => 'phone',
                        ],
                        // Telefoonnr. privé
                        'TeN2' => [],
                        // Fax werk
                        'FaNr' => [
                            'alias' => 'fax',
                        ],
                        // Mobiel werk
                        'MbNr' => [
                            'alias' => 'mobile',
                        ],
                        // Mobiel privé
                        'MbN2' => [],
                        // E-mail werk
                        'EmAd' => [
                            'alias' => 'email',
                        ],
                        'EmA2' => [],
                        // Homepage
                        'HoPa' => [
                            'alias' => 'homepage',
                        ],
                        // Correspondentie
                        'Corr' => [
                            'type' => 'boolean',
                            'default' => false,
                        ],
                        // Voorkeursmedium (verwijzing naar: Tabelwaarde,Medium voor correspondentie => AfasKnCodeTableValue)
                        'ViMd' => [],
                        // Opmerking
                        'Re' => [
                            'alias' => 'comment',
                        ],
                        // Status (verwijzing naar: Tabelwaarde,Status verkooprelatie => AfasKnCodeTableValue)
                        'StId' => [],
                        // Sociale netwerken
                        'SocN' => [],
                        // Facebook
                        'Face' => [
                            'alias' => 'facebook',
                        ],
                        // LinkedIn
                        'Link' => [
                            'alias' => 'linkedin',
                        ],
                        // Twitter
                        'Twtr' => [
                            'alias' => 'twitter',
                        ],
                        // Naam bestand
                        'FileName' => [],
                        // Afbeelding (base64Binary field)
                        'FileStream' => [],
                        // Persoon toegang geven tot afgeschermde deel van de portal(s)
                        'AddToPortal' => [
                            'type' => 'boolean',
                        ],
                        // E-mail toegang
                        'EmailPortal' => [],
                    ],
                ];

                // First name is not required if initials are filled.
                if (!empty($data['In']) || !empty($data['initials'])) {
                    unset($info['fields']['FiNm']['required']);
                }

                // We're sure that the record will be newly inserted if MatchPer
                // specifies this, and the 'fields action' does not say otherwise.
                // @todo test: what about MatchPer 7 and "update"? What overrides what?
                $inserting = (!$action || $action === 'insert')
                    && (!isset($data['match_method']) && !isset($data['MatchPer'])
                        || isset($data['match_method']) && $data['match_method'] == 7
                        || isset($data['MatchPer']) && $data['MatchPer'] == 7);

                // MatchPer defaults: Our principle is we would rather insert duplicate
                // data than silently overwrite data by accident.
                if (!empty($data['BcCo'])) {
                    // ...but it seems very unlikely that someone would specify BcCo when
                    // they don't explicitly want the corresponding record overwritten.
                    // So we match on BcCo in that case. This means there is no difference
                    // between $action "insert" and "update"!
                    // If we do _not_ set MatchPer while BcCo _is_ specified, with
                    // $action "insert" we get error "Unsupported match value!!"
                    $info['fields']['MatchPer']['default!'] = '0';
                } elseif (!empty($data['SoSe']) || !empty($data['bsn'])) {
                    // I guess we can assume the same logic (we never want duplicate
                    // records so just update everything silently, even for inserts), for
                    // BSN...
                    $info['fields']['MatchPer']['default!'] = '1';
                }
                // @todo we can surely assume the same logic for 2-6 but this would take
                // a lot of testing. Do this later. We will feel entitled to change this
                // part of this function's behavior silently.
                // @todo test and decide:
                //   We've inserted a block for MatchOga in the corresponding
                //   logic for KnOrganisation, so shouldn't we do the same here.
                //   even if just to keep from being non-confusing? (Or remove
                //   the block for KnOrganisation too, because testing has shown
                //   that MatchPer/MatchOga=0 together with 'insert' does the
                //   same: it always inserts. I guess inserting this block here
                //   is slightly nicer, but I won't do it without testing.
//                elseif ($action === 'insert') {
//                    // Since we can get an error if not setting MatchOga in some
//                    // circumstances (see 0 above), explicitly set 'always insert'.
//                    // (Note we haven't actually seen an error that this would
//                    // solve; it's "just to be sure" and doesn't hurt anything.)
//                    $info['fields']['MatchPer']['default!'] = '7';
//                }
                else {
                    // Probably even with $action "update", a new record will be
                    // inserted if there is no match... but we do not know this for sure!
                    // Since our principle is to prevent silent overwrites of data, we
                    // here force an error for "update" if MatchPer is not explicitly
                    // specified in $data.
                    // (If you disagree / encounter circumstances where this is not OK,
                    // tell me so we can refine this. --Roderik.)
                    //
                    // If we set MatchPer=0 if BcCo is not specified,
                    // - we get error "Voer een waarde in bij 'Nummer'" at "update";
                    // - a record is always inserted at "insert" (so I guess in this case
                    //   '0' is equal to '7').
                    // (NOTE we haven't actually tested this until now, just assumed that
                    // it works the same way as for knOrganisation, which was tested...)
                    $info['fields']['MatchPer']['default!'] = '0';
                }

                if ($parent_type === 'KnContact' || $parent_type === 'KnSalesRelationPer') {
                    // Note: a knPerson cannot be inside a knContact directly. So far we
                    // know only of the situation where that knContact is again inside a
                    // knOrganisation.

                    $info['fields'] += [
                        // This field applies to a knPerson inside a knContact inside a
                        // knOrganisation:
                        // Land wetgeving (verwijzing naar: Land => AfasKnCountry)
                        'CoLw' => [],
                    ];
                }
                if ($parent_type === 'KnSalesRelationPer') {
                    // Usually, phone/mobile/e-mail aliases are set to the business
                    // ones, and these are the ones you see on the screen in the UI.
                    // Inside KnSalesRelationPer, you see the private equivalents in the
                    // UI. (At least that was the case for Qoony.) So it's those you want
                    // to fill by default.
                    $info['fields']['TeN2']['alias'] = $info['fields']['TeNr']['alias'];
                    unset($info['fields']['TeNr']['alias']);
                    $info['fields']['MbN2']['alias'] = $info['fields']['MbNr']['alias'];
                    unset($info['fields']['MbNr']['alias']);
                    $info['fields']['EmA2']['alias'] = $info['fields']['EmAd']['alias'];
                    unset($info['fields']['EmAd']['alias']);
                }
                break;

            case 'KnSalesRelationPer':
                // NOTE - not checked against XSD yet, only taken over from Qoony example
                // Fields:
                // ??? = Overheids Identificatienummer, which an AFAS expert recommended
                //       for using as a secondary-unique-id, when we want to insert an
                //       auto-numbered object and later retrieve it to get the inserted ID.
                //       I don't know what this is but it's _not_ 'OIN', I tried that.
                //       (In the end we never used this field.)
                $info = [
                    'id_field' => 'DbId',
                    'objects' => [
                        'KnPerson' => 'person',
                    ],
                    'fields' => [

                        // 'is debtor'?
                        'IsDb' => [
                            'type' => 'boolean',
                            'default' => true,
                        ],
                        // According to AFAS docs, PaCd / VaDu "are required if IsDb==True" ...
                        // no further specs. Heh, VaDu is not even in our inserted XML.
                        'PaCd' => [
                            'default' => '14',
                        ],
                        'CuId' => [
                            'alias' => 'currency_code',
                            'default' => 'EUR',
                        ],
                        'Bl' => [
                            'default' => 'false',
                        ],
                        'AuPa' => [
                            'default' => '0',
                        ],
                        // Verzamelrekening Debiteur -- apparently these just need to be
                        // specified by whoever is setting up the AFAS administration?
                        'ColA' => [
                            'alias' => 'verzamelreking_debiteur',
                        ],
                        // ?? Doesn't seem to be required, but we're still setting default to
                        // the old value we're used to, until we know what this field means.
                        'VtIn' => [
                            'default' => '1',
                        ],
                        'PfId' => [
                            'default' => '*****',
                        ],
                    ],
                ];
                break;

            case 'KnOrganisation':
                $info = [
                    'objects' => [
//            'KnBankAccount' => 'bank_account',
                        'KnBasicAddressAdr' => 'address',
                        'KnBasicAddressPad' => 'postal_address',
                        'KnContact' => 'contact',
                    ],
                    'fields' => [
                        // Postadres is adres
                        'PbAd' => [
                            'alias' => 'is_po_box',
                            'type' => 'boolean',
                            'default' => false,
                        ],
                        'AutoNum' => [
                            'alias' => 'auto_num',
                            'type' => 'boolean',
                        ],
                        /**
                         * If you specify MatchOga and if the corresponding fields have
                         * values, the difference between $action "update" and
                         * "insert" falls away: if there is a match (but only one) the
                         * existing record is updated. If there isn't, a new one is
                         * inserted. If there are multiple matches, or a wrong match method
                         * is specified, AFAS throws an error.
                         *
                         * We make sure that you must explicitly specify a value for this
                         * with $field_action "update" (and get an error if you don't), by
                         * setting the default - see further down.
                         */
                        // Organisatie vergelijken op
                        // Values:  0:Zoek op BcCo   1:KvK-nummer   2:Fiscaal nummer   3:Naam   4:Adres   5:Postadres   6:Altijd nieuw toevoegen
                        'MatchOga' => [
                            'alias' => 'match_method',
                        ],
                        // Organisatie/persoon (intern)
                        // From "Organisaties toevoegen en wijzigen (UpdateConnector KnOrganisation)":
                        // "Do not deliver the 'BcId' field."
                        // (Because it really is internal. So why should we define it?)
                        //'BcId' => [
                        //),
                        // Nummer, 1-15 chars
                        'BcCo' => [
                            // 'ID' would be more confusing because it's not the internal ID.
                            'alias' => 'number',
                        ],
                        'SeNm' => [
                            'alias' => 'search_name',
                        ],
                        // Name. Is not required officially, but I guess you must fill in either
                        // BcCo, SeNm or Nm to be able to find the record back. (Or maybe you get an
                        // error if you don't specify any.)
                        'Nm' => [
                            'alias' => 'name',
                        ],
                        // Rechtsvorm (verwijzing naar: Tabelwaarde,Rechtsvorm => AfasKnCodeTableValue)
                        'ViLe' => [
                            'alias' => 'org_type',
                        ],
                        // Branche (verwijzing naar: Tabelwaarde,Branche => AfasKnCodeTableValue)
                        'ViLb' => [
                            'alias' => 'branche',
                        ],
                        // KvK-nummer
                        'CcNr' => [
                            'alias' => 'coc_number',
                        ],
                        // Datum KvK
                        'CcDa' => [
                            'type' => 'date',
                        ],
                        // Naam (statutair)
                        'NmRg' => [],
                        // Vestiging (statutair)
                        'RsRg' => [],
                        // Titel/aanhef (verwijzing naar: Titel => AfasKnTitle)
                        'TtId' => [],
                        // Briefaanhef
                        'LeHe' => [],
                        // Organisatorische eenheid (verwijzing naar: Organisatorische eenheid => AfasKnOrgUnit)
                        'OuId' => [],
                        // Telefoonnr. werk
                        'TeNr' => [
                            'alias' => 'phone',
                        ],
                        // Fax werk
                        'FaNr' => [
                            'alias' => 'fax',
                        ],
                        // Mobiel werk
                        'MbNr' => [
                            'alias' => 'mobile',
                        ],
                        // E-mail werk
                        'EmAd' => [
                            'alias' => 'email',
                        ],
                        // Homepage
                        'HoPa' => [
                            'alias' => 'homepage',
                        ],
                        // Correspondentie
                        'Corr' => [
                            'type' => 'boolean',
                        ],
                        // Voorkeursmedium (verwijzing naar: Tabelwaarde,Medium voor correspondentie => AfasKnCodeTableValue)
                        'ViMd' => [],
                        // Opmerking
                        'Re' => [
                            'alias' => 'comment',
                        ],
                        // Fiscaalnummer
                        'FiNr' => [
                            'alias' => 'fiscal_number',
                        ],
                        // Status (verwijzing naar: Tabelwaarde,Status verkooprelatie => AfasKnCodeTableValue)
                        'StId' => [],
                        // Sociale netwerken
                        'SocN' => [],
                        // Facebook
                        'Face' => [
                            'alias' => 'facebook',
                        ],
                        // LinkedIn
                        'Link' => [
                            'alias' => 'linkedin',
                        ],
                        // Twitter
                        'Twtr' => [
                            'alias' => 'twitter',
                        ],
                        // Onderdeel van organisatie (verwijzing naar: Organisatie/persoon => AfasKnBasicContact)
                        'BcPa' => [],
                    ],
                ];

                // We're sure that the record will be newly inserted if MatchOga
                // specifies this, and the 'fields action' does not say otherwise.
                // @todo test: what about MatchOga 6 and "update"? What overrides what?
                $inserting = (!$action || $action === 'insert')
                    && (!isset($data['match_method']) && !isset($data['MatchOga'])
                        || isset($data['match_method']) && $data['match_method'] == 6
                        || isset($data['MatchOga']) && $data['MatchOga'] == 6);

                // MatchOga defaults: Our principle is we would rather insert duplicate
                // data than silently overwrite data by accident.
// @todo So then, do we really want to keep doing the below also for $inserting? Seems better to only fill defaults for updates?

//@todo test: what is up with 'number' not being explicitly set here? That's a bug, only covered by the default-default being 0 too, right?
                if (!empty($data['BcCo'])) {
                    // ...but it seems very unlikely that someone would specify BcCo when
                    // they don't explicitly want the corresponding record overwritten.
                    // So we match on BcCo in that case. This means there is no difference
                    // between $action "insert" and "update"!
                    // If we do _not_ set MatchOga while BcCo _is_ specified, with
                    // $action "insert" we get error "Unsupported match value!!"
                    $info['fields']['MatchOga']['default!'] = '0';
                } elseif (!empty($data['CcNr']) || !empty($data['coc_number'])) {
                    // I guess we can assume the same logic (we never want duplicate
                    // records so just update everything silently, even for inserts), for
                    // KvK number...
                    $info['fields']['MatchOga']['default!'] = '1';
                } elseif (!empty($data['FiNr']) || !empty($data['fiscal_number'])) {
                    // ...and fiscal number.
                    $info['fields']['MatchOga']['default!'] = '2';
                } elseif ($action === 'insert') {
                    // Since we can get an error if not setting MatchOga in some
                    // circumstances (see 0 above), explicitly set 'always insert'.
                    // (Note we haven't actually seen an error that this would
                    // solve; it's "just to be sure" and doesn't hurt anything.)
                    $info['fields']['MatchOga']['default!'] = '6';
                } else {
                    // Probably even with $action "update", a new record will be
                    // inserted if there is no match... but we do not know this for sure!
                    // Since our principle is to prevent silent overwrites of data, we
                    // here force an error for "update" if MatchOga is not explicitly
                    // specified in $data.
                    // (If you disagree / encounter circumstances where this is not OK,
                    // tell me so we can refine this. --Roderik.)
                    //
                    // If we set MatchOga=0 if BcCo is not specified,
                    // - we get error "Voer een waarde in bij 'Nummer'" at "update";
                    // - a record is always inserted at "insert" (so I guess in this case
                    //   '0' is equal to '6').
                    $info['fields']['MatchOga']['default!'] = '0';
                }
                break;

            case 'KnSubject':
                $info = [
                    'id_field' => 'SbId',
                    'objects' => [
                        'KnSubjectLink' => 'subject_link',
                        'KnS01' => 'subject_link_1',
                        'KnS02' => 'subject_link_2',
                        // If there are more KnSNN, they have all custom fields?
                    ],
                    'fields' => [
                        // Type dossieritem (verwijzing naar: Type dossieritem => AfasKnSubjectType)
                        'StId' => [
                            'alias' => 'type',
                            'type' => 'long',
                            'required' => true,
                        ],
                        // Onderwerp
                        'Ds' => [
                            'alias' => 'description',
                        ],
                        // Toelichting
                        'SbTx' => [
                            'alias' => 'comment',
                        ],
                        // Instuurdatum
                        'Da' => [
                            'alias' => 'date',
                            'type' => 'date',
                        ],
                        // Verantwoordelijke (verwijzing naar: Medewerker => AfasKnEmployee)
                        'EmId' => [
                            'alias' => 'responsible',
                        ],
                        // Aanleiding (verwijzing naar: Dossieritem => AfasKnSubject)
                        'SbHi' => [
                            'type' => 'long',
                        ],
                        // Type actie (verwijzing naar: Type actie => AfasKnSubjectActionType)
                        'SaId' => [
                            'alias' => 'action_type',
                        ],
                        // Prioriteit (verwijzing naar: Tabelwaarde,Prioriteit actie => AfasKnCodeTableValue)
                        'ViPr' => [],
                        // Bron (verwijzing naar: Brongegevens => AfasKnSourceData)
                        'ScId' => [
                            'alias' => 'source',
                        ],
                        // Begindatum
                        'DtFr' => [
                            'alias' => 'start_date',
                            'type' => 'date',
                        ],
                        // Einddatum
                        'DtTo' => [
                            'alias' => 'end_date',
                            'type' => 'date',
                        ],
                        // Afgehandeld
                        'St' => [
                            'alias' => 'done',
                            'type' => 'boolean',
                        ],
                        // Datum afgehandeld
                        'DtSt' => [
                            'alias' => 'done_date',
                            'type' => 'date',
                        ],
                        // Waarde kenmerk 1 (verwijzing naar: Waarde kenmerk => AfasKnFeatureValue)
                        'FvF1' => [
                            'type' => 'long',
                        ],
                        // Waarde kenmerk 2 (verwijzing naar: Waarde kenmerk => AfasKnFeatureValue)
                        'FvF2' => [
                            'type' => 'long',
                        ],
                        // Waarde kenmerk 3 (verwijzing naar: Waarde kenmerk => AfasKnFeatureValue)
                        'FvF3' => [
                            'type' => 'long',
                        ],
                        // Geblokkeerd
                        'SbBl' => [
                            'alias' => 'blocked',
                            'type' => 'boolean',
                        ],
                        // Bijlage
                        'SbPa' => [
                            'alias' => 'attachment',
                        ],
                        // Save file with subject
                        'FileTrans' => [
                            'type' => 'boolean',
                        ],
                        // File as byte-array
                        'FileStream' => [],
                    ],
                ];
                break;

            case 'KnSubjectLink':
                $info = [
                    'id_field' => 'SbId',
                    'fields' => [
                        // Save in CRM Subject
                        'DoCRM' => [
                            'type' => 'boolean',
                        ],
                        // Organisatie/persoon
                        'ToBC' => [
                            'alias' => 'is_org_person',
                            'type' => 'boolean',
                        ],
                        // Medewerker
                        'ToEm' => [
                            'alias' => 'is_employee',
                            'type' => 'boolean',
                        ],
                        // Verkooprelatie
                        'ToSR' => [
                            'alias' => 'is_sales_relation',
                            'type' => 'boolean',
                        ],
                        // Inkooprelatie
                        'ToPR' => [
                            'alias' => 'is_purchase_relation',
                            'type' => 'boolean',
                        ],
                        // Cliënt IB
                        'ToCl' => [
                            'alias' => 'is_client_ib',
                            'type' => 'boolean',
                        ],
                        // Cliënt Vpb
                        'ToCV' => [
                            'alias' => 'is_client_vpb',
                            'type' => 'boolean',
                        ],
                        // Werkgever
                        'ToEr' => [
                            'alias' => 'is_employer',
                            'type' => 'boolean',
                        ],
                        // Sollicitant
                        'ToAp' => [
                            'alias' => 'is_applicant',
                            'type' => 'boolean',
                        ],
                        // Type bestemming
                        // Values:  1:Geen   2:Medewerker   3:Organisatie/persoon   4:Verkooprelatie   8:Cliënt IB   9:Cliënt Vpb   10:Werkgever   11:Inkooprelatie   17:Sollicitant   30:Campagne   31:Item   32:Cursusevenement-->
                        'SfTp' => [
                            'alias' => 'destination_type',
                            'type' => 'long',
                        ],
                        // Bestemming
                        'SfId' => [
                            'alias' => 'destination_id',
                        ],
                        // Organisatie/persoon (verwijzing naar: Organisatie/persoon => AfasKnBasicContact)
                        'BcId' => [
                            'alias' => 'org_person',
                        ],
                        // Contact (verwijzing naar: Contact => AfasKnContactData)
                        'CdId' => [
                            'alias' => 'contact',
                            'type' => 'long',
                        ],
                        // Administratie (Verkoop) (verwijzing naar: Administratie => AfasKnUnit)
                        'SiUn' => [
                            'type' => 'long',
                        ],
                        // Factuurtype (verkoop) (verwijzing naar: Type factuur => AfasFiInvoiceType)
                        'SiTp' => [
                            'alias' => 'sales_invoice_type',
                            'type' => 'long',
                        ],
                        // Verkoopfactuur (verwijzing naar: Factuur => AfasFiInvoice)
                        'SiId' => [
                            'alias' => 'sales_invoice',
                        ],
                        // Administratie (Inkoop) (verwijzing naar: Administratie => AfasKnUnit)
                        'PiUn' => [
                            'type' => 'long',
                        ],
                        // Factuurtype (inkoop) (verwijzing naar: Type factuur => AfasFiInvoiceType)
                        'PiTp' => [
                            'alias' => 'purchase_invoice_type',
                            'type' => 'long',
                        ],
                        // Inkoopfactuur (verwijzing naar: Factuur => AfasFiInvoice)
                        'PiId' => [
                            'alias' => 'purchase_invoice',
                        ],
                        // Fiscaal jaar (verwijzing naar: Aangiftejaren => AfasTxDeclarationYear)
                        'FiYe' => [
                            'alias' => 'fiscal_year',
                            'type' => 'long',
                        ],
                        // Project (verwijzing naar: Project => AfasPtProject)
                        'PjId' => [
                            'alias' => 'project',
                        ],
                        // Campagne (verwijzing naar: Campagne => AfasCmCampaign)
                        'CaId' => [
                            'alias' => 'campaign',
                            'type' => 'long',
                        ],
                        // Actief (verwijzing naar: Vaste activa => AfasFaFixedAssets)
                        'FaSn' => [
                            'type' => 'long',
                        ],
                        // Voorcalculatie (verwijzing naar: Voorcalculatie => AfasKnQuotation)
                        'QuId' => [],
                        // Dossieritem (verwijzing naar: Dossieritem => AfasKnSubject)
                        'SjId' => [
                            'type' => 'long',
                        ],
                        // Abonnement (verwijzing naar: Abonnement => AfasFbSubscription
                        'SuNr' => [
                            'alias' => 'subscription',
                            'type' => 'long',
                        ],
                        // Dienstverband
                        'DvSn' => [
                            'type' => 'long',
                        ],
                        // Type item (verwijzing naar: Tabelwaarde,Itemtype => AfasKnCodeTableValue)
                        // Values:  Wst:Werksoort   Pid:Productie-indicator   Deg:Deeg   Dim:Artikeldimensietotaal   Art:Artikel   Txt:Tekst   Sub:Subtotaal   Tsl:Toeslag   Kst:Kosten   Sam:Samenstelling   Crs:Cursus-->
                        'VaIt' => [
                            'alias' => 'item_type',
                        ],
                        // Itemcode (verwijzing naar: Item => AfasFbBasicItems)
                        'BiId' => [
                            'alias' => 'item_code',
                        ],
                        // Cursusevenement (verwijzing naar: Evenement => AfasKnCourseEvent)
                        'CrId' => [
                            'alias' => 'course_event',
                            'type' => 'long',
                        ],
                        // Verzuimmelding (verwijzing naar: Verzuimmelding => AfasHrAbsIllnessMut)
                        'AbId' => [
                            'type' => 'long',
                        ],
                        // Forecast (verwijzing naar: Forecast => AfasCmForecast)
                        'FoSn' => [
                            'type' => 'long',
                        ],
                    ],
                ];
                break;

            // Subject link #1 (after KnSubjectLink), to be sent inside KnSubject.
            // The field names are not custom fields, but are the definitions general?
            // Not 100% sure.
            case 'KnS01':
                $info = [
                    'id_field' => 'SbId',
                    'fields' => [
                        // Vervaldatum
                        'U001' => [
                            'alias' => 'end_date',
                            'type' => 'date',
                        ],
                        // Identiteitsnummer
                        'U002' => [
                            'alias' => 'id_number',
                        ],
                    ],
                ];
                break;

            case 'KnS02':
                $info = [
                    'id_field' => 'SbId',
                    'fields' => [
                        // Contractnummer
                        'U001' => [
                            'alias' => 'contract_number',
                        ],
                        // Begindatum contract
                        'U002' => [
                            'alias' => 'start_date',
                            'type' => 'date',
                        ],
                        // Einddatum contract
                        'U003' => [
                            'alias' => 'start_date',
                            'type' => 'date',
                        ],
                        // Waarde
                        'U004' => [
                            'alias' => 'value',
                            'type' => 'decimal',
                        ],
                        // Beëindigd
                        'U005' => [
                            'alias' => 'ended',
                            'type' => 'boolean',
                        ],
                        // Stilzwijgend verlengen
                        'U006' => [
                            'alias' => 'recurring',
                            'type' => 'boolean',
                        ],
                        // Opzegtermijn (verwijzing naar: Tabelwaarde,(Afwijkende) opzegtermijn => AfasKnCodeTableValue)
                        'U007' => [
                            'alias' => 'cancel_term',
                        ],
                    ],
                ];
                break;

            case 'FbSales':
                $info = [
                    'objects' => [
                        'FbSalesLines' => 'line_item',
                    ],
                    'fields' => [
                        // Nummer
                        'OrNu' => [],
                        // Datum
                        'OrDa' => [
                            'alias' => 'date',
                            'type' => 'date',
                        ],
                        // Verkooprelatie (verwijzing naar: Verkooprelatie => AfasKnSalRelation)
                        'DbId' => [
                            'alias' => 'sales_relation',
                        ],
                        // Gewenste leverdatum
                        'DaDe' => [
                            'alias' => 'delivery_date_req',
                            'type' => 'date',
                        ],
                        // Datum levering (toegezegd)
                        'DaPr' => [
                            'alias' => 'delivery_date_ack',
                            'type' => 'date',
                        ],
                        // Valutacode (verwijzing naar: Valuta => AfasKnCurrency)
                        'CuId' => [
                            'alias' => 'currency_code',
                        ],
                        // Valutakoers
                        'Rate' => [
                            'alias' => 'currency_rate',
                        ],
                        // Backorder
                        'BkOr' => [
                            'type' => 'boolean',
                        ],
                        // Verkoopkanaal (verwijzing naar: Tabelwaarde,Verkoopkanaal => AfasKnCodeTableValue)
                        'SaCh' => [
                            'alias' => 'sales_channel',
                        ],
                        // Btw-plicht (verwijzing naar: Btw-plicht => AfasKnVatDuty)
                        'VaDu' => [
                            'alias' => 'vat_due',
                        ],
                        // Prijs incl. btw
                        'InVa' => [
                            'alias' => 'includes_vat',
                        ],
                        // Betalingsvoorwaarde (verwijzing naar: Betalingsvoorwaarde => AfasKnPaymentCondition)
                        'PaCd' => [],
                        // Betaalwijze (verwijzing naar: Betaalwijze => AfasKnPaymentType)
                        'PaTp' => [
                            'alias' => 'payment_type',
                        ],
                        // Opmerking
                        'Re' => [
                            'alias' => 'comment',
                        ],
                        // Administratie (verwijzing naar: Administratieparameters Algemeen => AfasKnUnitPar)
                        'Unit' => [
                            'type' => 'long',
                        ],
                        // Incasseren
                        'Coll' => [
                            'type' => 'boolean',
                        ],
                        // Creditorder
                        'CrOr' => [
                            'type' => 'boolean',
                        ],
                        // Code route (verwijzing naar: Tabelwaarde,Routes => AfasKnCodeTableValue)
                        'Rout' => [],
                        // Magazijn (verwijzing naar: Magazijn => AfasFbWarehouse)
                        'War' => [
                            'alias' => 'warehouse',
                        ],
                        // Verzamelpakbon
                        'CoDn' => [
                            'type' => 'boolean',
                        ],
                        // Verzamelfactuur
                        'CoIn' => [
                            'type' => 'boolean',
                        ],
                        // Prioriteit levering
                        'DlPr' => [
                            'alias' => 'delivery_prio',
                            'type' => 'long',
                        ],
                        // Taal (verwijzing naar: Talen => AfasKnLanguage)
                        'LgId' => [
                            'alias' => 'language',
                        ],
                        // Leveringsconditie (verwijzing naar: Tabelwaarde,Leveringvoorwaarde => AfasKnCodeTableValue)
                        // Values:  0:Deellevering toestaan   1:Regel volledig uitleveren   2:Order volledig uitleveren   3:Geen backorders leveren
                        'DeCo' => [
                            'alias' => 'delivery_cond',
                        ],
                        // CBS-typen (verwijzing naar: CBS-typen => AfasFbCBSType)
                        'CsTy' => [
                            'alias' => 'cbs_type',
                        ],
                        // Type vervoer CBS (verwijzing naar: Tabelwaarde,CBS Vervoerswijze => AfasKnCodeTableValue)
                        // Values:  1:Zeevaart   2:Spoorvervoer   3:Wegvervoer   4:Luchtvaart   5:Postzendingen   7:Pijpleidingvervoer   8:Binnenvaart   9:Eigen vervoer
                        'VaTr' => [],
                        // Statistisch stelsel CBS (verwijzing naar: Tabelwaarde,CBS Statistisch stelsel => AfasKnCodeTableValue)
                        // Values:  00:Reguliere invoer/ICV en uitvoer/ICL   01:Doorlevering (ICL) van onbewerkte goederen naar een andere Eu-lidstaat   02:Wederverkoop (ICL of uitvoer) van onbewerkte goederen   03:Invoer (al of niet via douane-entrepot) van goederen   04:Verwerving/levering vÃ³Ã³r eigen voorraadverplaatsing (fictieve zending)   05:Verwerving/levering nÃ¡ eigen voorraadverplaatsing (fictieve zending)   10:Actieve douaneveredeling met toepassing van het terugbetalingssysteem
                        'VaSt' => [],
                        // Goederenstroom CBS (verwijzing naar: Tabelwaarde,CBS Goederenstroom => AfasKnCodeTableValue)
                        // 6:Invoer/intra-cummunautaire verwerving (ICV)   7:Uitvoer/intra-communautaire levering (ICL)
                        'VaGs' => [],
                        // Transactie CBS (verwijzing naar: Tabelwaarde,CBS Transactie => AfasKnCodeTableValue)
                        // Values:  1:Koop, verkoop of huurkoop (financiële leasing)   2:Retourzending (excl. retour tijdelijke in- en uitvoer, zie code 6)   3:Gratis zending   4:Ontvangst of verzending vÃ³Ã³r loonveredeling   5:Ontvangst of verzending nÃ¡ loonveredeling   6:Tijdelijke in- en uitvoer en retour tijdelijke in- en uitvoer   7:Ontvangst of verzending in het kader van gecoÃ¶rdineerde fabrikage   8:Levering i.v.m. bouwmaterialen c.q. bouwkunde onder algemeen contract
                        'VaTa' => [],
                        // Land bestemming CBS (verwijzing naar: Land => AfasKnCountry)
                        'CoId' => [],
                        // Factuurkorting (%)
                        'InPc' => [
                            'type' => 'decimal',
                        ],
                        // Kredietbeperking inclusief btw
                        'VaCl' => [
                            'type' => 'boolean',
                        ],
                        // Kredietbeperking (%)
                        'ClPc' => [
                            'type' => 'decimal',
                        ],
                        // Betalingskorting (%)
                        'PaPc' => [
                            'type' => 'decimal',
                        ],
                        // Betalingskorting incl. btw
                        'VaPa' => [
                            'type' => 'boolean',
                        ],
                        // Afwijkende btw-tariefgroep
                        'VaYN' => [
                            'type' => 'boolean',
                        ],
                        // Type barcode (verwijzing naar: Tabelwaarde,Type barcode => AfasKnCodeTableValue)-->
                        // Values:  0:Geen controle   1:Barcode EAN8   2:Barcode UPC   3:Barcode EAN13   4:Barcode EAN14   5:Barcode SSCC   6:Code 128   7:Interleaved 2/5   8:Interleaved 2/5 (controlegetal)
                        'VaBc' => [
                            'alias' => 'barcode_type',
                        ],
                        // Barcode
                        'BaCo' => [
                            'alias' => 'barcode',
                        ],
                        // Rapport (verwijzing naar: Definitie => AfasKnMetaDefinition)
                        'PrLa' => [],
                        // Dagboek factuur (verwijzing naar: Dagboek => AfasKnJournal)
                        'JoCo' => [
                            'alias' => 'journal',
                        ],
                        // Factureren aan (verwijzing naar: Verkooprelatie => AfasKnSalRelation)
                        'FaTo' => [
                            'alias' => 'invoice_to',
                        ],
                        // Toekomstige order
                        'FuOr' => [
                            'alias' => 'future_order',
                            'type' => 'boolean',
                        ],
                        // Type levering (verwijzing naar: Type levering => AfasFbDeliveryType)
                        'DtId' => [
                            'alias' => 'delivery_type',
                            'type' => 'long',
                        ],
                        // Project (verwijzing naar: Project => AfasPtProject)
                        'PrId' => [
                            'alias' => 'project',
                        ],
                        // Projectfase (verwijzing naar: Projectfase => AfasPtProjectStage)
                        'PrSt' => [
                            'alias' => 'project_stage',
                        ],
                        // Status verzending (verwijzing naar: Tabelwaarde,Verzendstatus => AfasKnCodeTableValue)
                        // Values:  0:Niet aanbieden aan vervoerder   1:Aanbieden aan vervoerder   2:Aangeboden aan vervoerder   3:Verzending correct ontvangen   4:Fout bij aanbieden verzending
                        'SeSt' => [
                            'alias' => 'delivery_state',
                        ],
                        // Verzendgewicht
                        'SeWe' => [
                            'alias' => 'weight',
                            'type' => 'decimal',
                        ],
                        // Aantal colli
                        'QuCl' => [
                            'type' => 'long',
                        ],
                        // Verpakking (verwijzing naar: Tabelwaarde,Verpakkingssoort => AfasKnCodeTableValue)
                        'PkTp' => [
                            'alias' => 'package_type',
                        ],
                        // Vervoerder (verwijzing naar: Vervoerder => AfasKnTransporter)
                        'TrPt' => [
                            'alias' => 'shipping_company',
                        ],
                        // Dienst (verwijzing naar: Dienst => AfasKnShippingService)
                        'SsId' => [
                            'alias' => 'shipping_service',
                        ],
                        // Verwerking order (verwijzing naar: Tabelwaarde,Verwerking order => AfasKnCodeTableValue)
                        // Values:  1:Pakbon, factuur na levering   2:Pakbon en factuur   3:Factuur, levering na vooruitbetaling   4:Pakbon, geen factuur   5:Pakbon, factuur via nacalculatie   6:Pakbon en factuur, factuur niet afdrukken of verzenden   7:Aanbetalen, levering na aanbetaling
                        'OrPr' => [
                            'alias' => 'order_processing',
                        ],
                        // Bedrag aanbetalen
                        'AmDp' => [
                            'type' => 'decimal',
                        ],
                        // Vertegenwoordiger (verwijzing naar: Vertegenwoordiger => AfasKnRepresentative)
                        'VeId' => [],
                        // Afleveradres (verwijzing naar: Adres => AfasKnBasicAddress)
                        'DlAd' => [
                            'type' => 'long',
                        ],
                        // Omschrijving afleveradres
                        'ExAd' => [
                            'alias' => '',
                        ],
                        // Order blokkeren
                        'FxBl' => [
                            'alias' => 'block_order',
                            'type' => 'boolean',
                        ],
                        // Uitleverbaar
                        'DlYN' => [
                            'type' => 'boolean',
                        ],
                    ],
                ];
                break;

            case 'FbSalesLines':
                $info = [
                    'objects' => [
                        'FbOrderBatchLines' => 'batch_line_item',
                        'FbOrderSerialLines' => 'serial_line_item',
                    ],
                    'fields' => [
                        // Type item (verwijzing naar: Tabelwaarde,Itemtype => AfasKnCodeTableValue)
                        // Values:  1:Werksoort   10:Productie-indicator   11:Deeg   14:Artikeldimensietotaal   2:Artikel   3:Tekst   4:Subtotaal   5:Toeslag   6:Kosten   7:Samenstelling   8:Cursus
                        'VaIt' => [
                            'alias' => 'item_type',
                        ],
                        // Itemcode
                        'ItCd' => [
                            'alias' => 'item_code',
                        ],
                        // Omschrijving
                        'Ds' => [
                            'alias' => 'description',
                        ],
                        // Btw-tariefgroep (verwijzing naar: Btw-tariefgroep => AfasKnVatTarifGroup)
                        'VaRc' => [
                            'alias' => 'vat_type',
                        ],
                        // Eenheid (verwijzing naar: Eenheid => AfasFbUnit)
                        'BiUn' => [
                            'alias' => 'unit_type',
                        ],
                        // Aantal eenheden
                        'QuUn' => [
                            'alias' => 'quantity',
                            'type' => 'decimal',
                        ],
                        // Lengte
                        'QuLe' => [
                            'alias' => 'length',
                            'type' => 'decimal',
                        ],
                        // Breedte
                        'QuWi' => [
                            'alias' => 'width',
                            'type' => 'decimal',
                        ],
                        // Hoogte
                        'QuHe' => [
                            'alias' => 'height',
                            'type' => 'decimal',
                        ],
                        // Aantal besteld
                        'Qu' => [
                            'alias' => 'quantity_ordered',
                            'type' => 'decimal',
                        ],
                        // Aantal te leveren
                        'QuDl' => [
                            'alias' => 'quantity_deliver',
                            'type' => 'decimal',
                        ],
                        // Prijslijst (verwijzing naar: Prijslijst verkoop => AfasFbPriceListSale)
                        'PrLi' => [
                            'alias' => 'price_list',
                        ],
                        // Magazijn (verwijzing naar: Magazijn => AfasFbWarehouse)
                        'War' => [
                            'alias' => 'warehouse',
                        ],
                        // Dienstenberekening
                        'EUSe' => [
                            'type' => 'boolean',
                        ],
                        // Gewichtseenheid (verwijzing naar: Tabelwaarde,Gewichtseenheid => AfasKnCodeTableValue)
                        // Values:  0:Geen gewicht   1:Microgram (Âµg)   2:Milligram (mg)   3:Gram (g)   4:Kilogram (kg)   5:Ton
                        'VaWt' => [
                            'alias' => 'weight_unit',
                        ],
                        // Nettogewicht
                        'NeWe' => [
                            'alias' => 'weight_net',
                            'type' => 'decimal',
                        ],
                        //
                        'GrWe' => [
                            'alias' => 'weight_gross',
                            'type' => 'decimal',
                        ],
                        // Prijs per eenheid
                        'Upri' => [
                            'alias' => 'unit_price',
                            'type' => 'decimal',
                        ],
                        // Kostprijs
                        'CoPr' => [
                            'alias' => 'cost_price',
                            'type' => 'decimal',
                        ],
                        // Korting toestaan (verwijzing naar: Tabelwaarde,Toestaan korting => AfasKnCodeTableValue)
                        // Values:  0:Factuur- en regelkorting   1:Factuurkorting   2:Regelkorting   3:Geen factuur- en regelkorting
                        'VaAD' => [],
                        // % Regelkorting
                        'PRDc' => [
                            'type' => 'decimal',
                        ],
                        // Bedrag regelkorting
                        'ARDc' => [
                            'type' => 'decimal',
                        ],
                        // Handmatig bedrag regelkorting
                        'MaAD' => [
                            'type' => 'boolean',
                        ],
                        // Opmerking
                        'Re' => [
                            'alias' => 'comment',
                        ],
                        // GUID regel
                        'GuLi' => [
                            'alias' => 'guid',
                        ],
                        // Artikeldimensiecode 1 (verwijzing naar: Artikeldimensiecodes => AfasFbStockDimLines)
                        'StL1' => [
                            'alias' => 'dimension_1',
                        ],
                        // Artikeldimensiecode 2 (verwijzing naar: Artikeldimensiecodes => AfasFbStockDimLines)
                        'StL2' => [
                            'alias' => 'dimension_2',
                        ],
                        // Direct leveren vanuit leverancier
                        'DiDe' => [
                            'alias' => 'direct_delivery',
                            'type' => 'boolean',
                        ],
                    ],
                ];
                break;

            case 'FbOrderBatchLines':
                $info = [
                    'fields' => [
                        // Partijnummer
                        'BaNu' => [
                            'alias' => 'batch_number',
                        ],
                        // Eenheid (verwijzing naar: Eenheid => AfasFbUnit)
                        'BiUn' => [
                            'alias' => 'unit_type',
                        ],
                        // Aantal eenheden
                        'QuUn' => [
                            'alias' => 'quantity_units',
                            'type' => 'decimal',
                        ],
                        // Aantal
                        'Qu' => [
                            'alias' => 'quantity',
                            'type' => 'decimal',
                        ],
                        // Factuuraantal
                        'QuIn' => [
                            'alias' => 'quantity_invoice',
                            'type' => 'decimal',
                        ],
                        // Opmerking
                        'Re' => [
                            'alias' => 'comment',
                        ],
                        // Lengte
                        'QuLe' => [
                            'alias' => 'length',
                            'type' => 'decimal',
                        ],
                        // Breedte
                        'QuWi' => [
                            'alias' => 'width',
                            'type' => 'decimal',
                        ],
                        // Hoogte
                        'QuHe' => [
                            'alias' => 'height',
                            'type' => 'decimal',
                        ],
                    ],
                ];
                break;

            case 'FbOrderSerialLines':
                $info = [
                    'fields' => [
                        // Serienummer
                        'SeNu' => [
                            'alias' => 'serial_number',
                        ],
                        // Eenheid (verwijzing naar: Eenheid => AfasFbUnit)
                        'BiUn' => [
                            'alias' => 'unit_type',
                        ],
                        // Aantal eenheden
                        'QuUn' => [
                            'alias' => 'quantity_units',
                            'type' => 'decimal',
                        ],
                        // Aantal
                        'Qu' => [
                            'alias' => 'quantity',
                            'type' => 'decimal',
                        ],
                        // Factuuraantal
                        'QuIn' => [
                            'alias' => 'quantity_invoice',
                            'type' => 'decimal',
                        ],
                        // Opmerking
                        'Re' => [
                            'alias' => 'comment',
                        ],
                    ],
                ];
                break;
        }

        // If we are not sure that the record will be newly inserted, we do not
        // want to have default values - because those will risk silently
        // overwriting existing values in AFAS.
        // Exception: those marked with '!'.
        if (!empty($info['fields'])) {
            foreach ($info['fields'] as $field => &$definition) {
                if (isset($definition['default!'])) {
                    // This is always the default
                    $definition['default'] = $definition['default!'];
                    unset($definition['default!']);
                } elseif (!$inserting) {
                    unset($definition['default']);
                }
            }
        }

        // If no ID is specified, default AutoNum to True for inserts.
        if (isset($info['fields']['AutoNum'])
            && $action === 'insert' && !isset($data['#id'])
        ) {
            $info['fields']['AutoNum']['default'] = true;
        }

        // If this type is being rendered inside a parent type, then it cannot
        // contain its parent type. (Example: knPerson can be inside knContact
        // and it can also contain knContact... except when it is being rendered
        // inside knContact.)
        if (isset($info['objects'][$parent_type])) {
            unset($info['objects'][$parent_type]);
        }

        // If the definition has address and postal address defined, and the
        // data has an address but no postal address set, then the default
        // becomes PadAdr = true.
        if (isset($info['fields']['PadAdr'])
            && isset($info['objects']['KnBasicAddressAdr'])
            && isset($info['objects']['KnBasicAddressPad'])
            && (!empty($data['KnBasicAddressAdr'])
                || !empty($data[$info['objects']['KnBasicAddressAdr']]))
            && (empty($data['KnBasicAddressPad'])
                || empty($data[$info['objects']['KnBasicAddressPad']]))
        ) {
            $info['fields']['PadAdr']['default'] = true;
        }

        return $info;
    }

    /**
     * Return info for a certain type (dataConnectorId) definition.
     *
     * @deprecated Since REST/JSON appeared, this was renamed to objectTypeInfo.
     */
    protected static function xmlTypeInfo($type, $parent_type, $data, $action)
    {
        return static::objectTypeInfo($type, $parent_type, $data, $action);
    }
}
