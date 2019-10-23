<?php

/**
 * This file is part of the PracticalAfas package.
 *
 * (c) Roderik Muit <rm@wyz.biz>
 *
 * For the full copyright and license information, please view the LICENSE file
 * that was distributed with this source code.
 */

namespace PracticalAfas\UpdateConnector;

use InvalidArgumentException;
use UnexpectedValueException;

/**
 * Helper code to introduce ISO country codes into objects.
 *
 * AFAS codes are completely custom, as far as I know. We want to be able to
 * use (e.g. while importing data from other sources) more universally
 * recognized codes.
 *
 * This trait provides a way to add fake fields to object
 * types, which can hold an ISO3166 country code that is converted into an AFAS
 * code in the actual country field on output/validation. (The ALLOW_CHANGES
 * bit does not need to be specified for this to work, because converting to an
 * equivalent code is not considered an actual change.)
 *
 * Extra property definitions used by this class: 'iso_country_fields'. See
 * below.
 */
trait IsoCountryTrait
{
    /**
     * Transfers ISO country values into AFAS values in the real field.
     *
     * This method can be called from validateFields(), before performing any
     * other logic on the AFAS fields or calling the parent validateFields().
     * Any class that calls it, must have an 'iso_country_fields' property
     * definition must be present which is an array; each key-value represents:
     * - The key is a 'fake' field name that can be populated with an ISO
     *   country code (through e.g. create()). This fake field needs to be
     *   defined in the 'fields' section too; this method will make sure all
     *   the fake country fields are emptied so that they never appear in
     *   output.
     * - The value is the actual AFAS field name attached to this fake field.
     *   This method will populate AFAS country values into this field,
     *   converted from ISO values in the fake field.
     * When using ISO country fields, it is recommended to always leave the
     * AFAS field empty, though that's not required: validation will only throw
     * an exception if both fields contain non-equivalent values.
     *
     * (We did not define this functionality as 'public validateFields' in this
     * trait, because this would mean we need to call the parent from this
     * method - which in turn means the implementing class cannot do anything
     * else after having converted the fields.)
     *
     * @param array $element
     *   The element (usually the single one contained in $this->elements)
     *   whose fields should be validated.
     * @param int|string $element_index
     *   The index of the element in our object data; usually there is one
     *   element and the index is 0.
     * @param int $change_behavior
     *   (Optional) see output().
     *
     * @return array
     *   The element with its ISO country fields emptied and corresponding AFAS
     *   country fields populated.
     *
     * @throws \InvalidArgumentException
     *   If an ISO or AFAS country code is unknown or invalid.
     * @throws \UnexpectedValueException
     *   If property definitions are invalid and validation could not be done.
     */
    protected function convertIsoCountryCodeFields(array $element, $element_index, $change_behavior = self::DEFAULT_CHANGE)
    {
        // We react to behavior ALLOW_REFORMAT (rather than ALLOW_CHANGES)
        // because 1) we want to do this by default and 2) we regard the
        // combination of iso+afas code to represent one field only.
        if ($change_behavior & UpdateObject::ALLOW_REFORMAT) {
            if (!isset($this->propertyDefinitions['iso_country_fields']) || !is_array($this->propertyDefinitions['iso_country_fields'])) {
                // If a definition is wrong, we throw an exception rather than
                // adding to $element['*errors'].
                throw new UnexpectedValueException("'{$this->getType()}' object has no / a non-array 'iso_country_fields' property definition.");
            }
            foreach ($this->propertyDefinitions['iso_country_fields'] as $iso_field_name => $afas_field_name) {
                if (!is_string($afas_field_name)) {
                    throw new UnexpectedValueException("'iso_country_fields' property definition for '{$this->getType()}' object contains a non-string value.");
                }
                try {
                    $element = $this->convertIsoCountryCodeField($iso_field_name, $afas_field_name, $element, $element_index);
                } catch (InvalidArgumentException $e) {
                    $element['*errors']["Fields:$afas_field_name"] = $e->getMessage();
                }
            }
        }

        return $element;
    }

    /**
     * Converts ISO country code in one field to AFAS code in another field.
     *
     * This is suitable to call from validateFields() for any object type
     * which has a 'fake field' defined that can hold an ISO country code.
     *
     * Also, make sure the AFAS code is uppercased. (Maybe AFAS itself doesn't
     * care and converts it... but unification is nice anyway. For instance
     * validation functions could benefit.)
     *
     * @param string $iso_field_name
     *   The name of the 'fake field' (which should be emptied out after
     *   conversion so it is not sent into AFAS).
     * @param string $afas_field_name
     *   The name of the actual AFAS field, which should be populated with an
     *   AFAS country code.
     * @param array $element
     *   The element whose fields should be validated.
     * @param int $element_index
     *   (Optional) The index of the element in our object data; usually there
     *   is one element and the index is 0. Used in exception messages.
     *
     * @return array
     *   The element with its fields changed if appropriate.
     *
     * @throws \InvalidArgumentException
     *   If ISO or AFAS country code is unknown or invalid.
     */
    protected function convertIsoCountryCodeField($iso_field_name, $afas_field_name, array $element, $element_index)
    {
        if (!empty($element['Fields'][$iso_field_name])) {
            // Make sure the fields are both strings. A quick way of doing this
            // is to call validateFieldValue() (which may be repeated later).
            $iso_value = $this->validateFieldValue($element['Fields'][$iso_field_name], $iso_field_name, UpdateObject::DEFAULT_CHANGE, UpdateObject::DEFAULT_VALIDATION, $element_index, $element);

            $afas_code = static::convertIsoCountryCode($iso_value);
            if (!$afas_code) {
                throw new InvalidArgumentException("Unknown ISO country code '{$element['Fields'][$iso_field_name]}'.");
            }
            // We expect the CoId field to not be populated, but if it's the
            // same as the converted ISO code, we allow that. (But uppercase it
            // just like if the ISO code is not filled.)
            if (!empty($element['Fields'][$afas_field_name])) {
                $afas_value = $this->validateFieldValue($element['Fields'][$afas_field_name], $afas_field_name, UpdateObject::DEFAULT_CHANGE, UpdateObject::DEFAULT_VALIDATION, $element_index, $element);
                if (strtoupper($afas_value) !== $afas_code) {
                    throw new InvalidArgumentException("Inconsistent ISO country code '$iso_value' and AFAS code '$afas_value'' found.");
                }
            }
            $element['Fields'][$afas_field_name] = $afas_code;
            unset($element['Fields'][$iso_field_name]);
        } elseif (!empty($element['Fields'][$afas_field_name])) {
            $afas_value = $this->validateFieldValue($element['Fields'][$afas_field_name], $afas_field_name, UpdateObject::DEFAULT_CHANGE, UpdateObject::DEFAULT_VALIDATION, $element_index, $element);
            $element['Fields'][$afas_field_name] = strtoupper($afas_value);
        }

        return $element;
    }

    /**
     * Converts ISO to AFAS country code.
     *
     * This function is not complete yet, it only does Europe correctly plus
     * a list of other quasi-random countries.
     *
     * @param string $iso_code
     *   ISO9166 2-letter country code. (Do not pass AFAS codes; there's a risk
     *   of those being wrongly converted into a different AFAS code.)
     *
     * @return string
     *   The corresponding AFAS country code, or '' if not found.
     */
    public static function convertIsoCountryCode($iso_code)
    {
        if (!is_string($iso_code)) {
            return '';
        }

        // This list is incomplete, but contains all:
        // - European codes we know to NOT match the 2-letter ISO codes
        //   (so we know Europe is correctly converted);
        // - 2-letter ISO codes which match a different 2-letter AFAS code
        //   (and as a result we're at least sure that this method will never
        //   return a code belonging to another country, though we're not sure
        //   it will always return a code).
        // So what is missing is most countries that map to a 1- or 3-letter
        // code; see the list in convertCountryName().
        //
        // The comments contain the reason that an AFAS country code is
        // invalid input for this function: every country that has an asterisk
        // at the end would be wrongly converted to another country (one with
        // an asterisk at the start).
        static $cc = [
            'AN' => 'NA',  // Netherlands Antilles *
            'AS' => 'ASM', // *American Samoa
            'AT' => 'A',
            'BE' => 'B',
            'BF' => 'BU',  // Burkina Faso
            'BH' => 'BRN', // *Bahrain
            'BI' => 'RU',  // Burundi *
            'BJ' => 'DY',  // Benin
            'BW' => 'RB',  // Botswana
            'BZ' => 'BH',  // Belize *
            'CL' => 'RCH', // *Chile
            'CM' => 'TC',  // Cameroon *
            'DE' => 'D',
            'DM' => 'WD',  // Dominica
            'EG' => 'ET',  // Egypt *
            'ET' => 'ETH', // *Ethiopia
            'ES' => 'E',   // Spain
            'FI' => 'FIN',
            'FR' => 'F',
            'GD' => 'WG',  // Grenada
            'GQ' => 'CQ',  // Equatorial Guinea
            'HU' => 'H',
            'HT' => 'RH',  // Haiti
            'IN' => 'RI',  // Indonesia
            'IT' => 'I',
            'JM' => 'JA',  // Jamaica
            'JP' => 'J',
            'KP' => 'KO',  // Korea (North)
            'KR' => 'ROK', // Korea (South)
            'LB' => 'RL',  // *Lebanon
            'LC' => 'WL',  // Saint Lucia
            'LI' => 'FL',  // Liechtenstein
            'LK' => 'CL',  // Sri Lanka *
            'LR' => 'LB',  // Liberia *
            'LU' => 'L',
            'MS' => 'MSR', // *Montserrat
            'MU' => 'MS',  // Mauritius *
            'NA' => 'SWA', // *Namibia
            'NE' => 'RN',  // Niger
            'NO' => 'N',
            'PH' => 'RP',  // Philippines
            'PT' => 'P',
            'RU' => 'RUS', // *Russian federation
            'SA' => 'AS',  // Saudi Arabia *
            'SC' => 'SY',  // Seychelles *
            'SD' => 'SUD', // *Sudan
            'SE' => 'S',   // Sweden
            'SI' => 'SLO', // Slovenia
            'SO' => 'SP',  // Somalia
            'SR' => 'SME', // Suriname
            'SV' => 'EL',  // El Salvador
            'SY' => 'SYR', // *Syria
            'SZ' => 'SD',  // Swaziland *
            'TC' => 'TCA', // *Turks and Caicos Islands
            'TW' => 'RC',  // Taiwan
            'US' => 'USA',
            'VC' => 'WV',  // Saint Vincent and the Grenadines
            'VE' => 'YV',  // Venezuela
        ];
        if (!empty($cc[strtoupper($iso_code)])) {
            return $cc[strtoupper($iso_code)];
        }
        // Return the input string (uppercased) if it's equal to an AFAS
        // country code (i.e. if two-letter ISO and AFAS codes are equal);
        // empty string if the code is unknown. (This is the only method that
        // is allowed to call convertCountryName() with an AFAS code, because
        // the ones that would be mis-assigned have already been filtered out.
        //
        // So... here we are being inconsistent:
        // - Our method documentation says "Do not pass AFAS codes" because if
        //   they would match any of the above list, they would be wrongly
        //   converted to a different code,
        // - but if we pass an AFAS code anyway which does not match the above,
        //   then we will get the same code returned even if the input was not
        //   actually an ISO code. That is: the below call will mean that
        //   'NL' and 'CH' get correctly returned (because ISO and AFAS are the
        //   same) but the non-ISO codes 'EL' and 'B' would also be returned
        //   unmodified (even though they are not ISO codes).
        return static::convertCountryName($iso_code, 1);
    }

    /**
     * Converts country name to AFAS code or checks that AFAS code is valid.
     *
     * It's not expected that we need a method for converting AGAS code to
     * country name, since most often the country name will be directly output
     * by a GetConnector. This method only converts names to codes, and can
     * also recognize whether the input string is a valid AFAS country code.
     *
     * @param string $name
     *   Country name (or, depending on $default_behavior, AFAS country code.
     *   Do not pass ISO codes; use convertIsoCountryCode() for that.)
     * @param int $default_behavior
     *   Code for default behavior if no matching country name is found:
     *   0: always return empty string
     *   1: if $name itself is equal to a country code, return that code
     *      (always uppercased). So the function accepts codes as well as names.
     *   2: return the (non-uppercased) original string as default, even if it
     *      is apparently not a legal code.
     *   3: 1 + 2.
     *   4: return NL instead of '' as the default. (Because AFAS is used in NL
     *      primarily.)
     *   5: 1 + 4.
     *
     * @return string
     *   Country name (or code depending on 2nd param), or NL / '' if not found.
     */
    public static function convertCountryName($name, $default_behavior = 0)
    {
        if (!is_string($name)) {
            return '';
        }

        // We define a flipped array here because it looks nicer. In the future
        // we could have this array map multiple names to the same country
        // code, in which case we need to flip the keys/values.
        //
        // I don't remember where I got the following array from but apparently
        // I pasted it from AFAS docs, around 2012. I have no idea how AFAS
        // determined the codes since they are not consistent with ISO. In the
        // comments below, I noted down all cases where:
        // - 2-letter AFAS codes are not equal to the country ISO codes: the
        //   ISO codes are noted, plus
        //   - if the AFAS code is used for an ISO code of another country
        //   - if the ISO code for this country is also used elsewhere in AFAS.
        // - 3-letter AFAS codes are not equal to the country ISO codes; the
        //   ISO codes are noted. (I also started checking for 'cross-use' of
        //   codes just like with the 2-letter ones, but stopped at G.)
        static $names_by_code = [
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
            'BRN' => 'Bahrain', // ISO-3 is BHR / ISO-3 BRN is Brunei
            'BD' => 'Bangladesh',
            'BDS' => 'Barbados', // ISO-3 is BDB
            'BY' => 'Belarus',
            'B' => 'België',
            'BH' => 'Belize', // ISO-2 is BZ / ISO-2 BH is Bahrain
            'BM' => 'Bermuda',
            'DY' => 'Benin', // ISO-2 is BJ
            'BT' => 'Bhutan',
            'BOL' => 'Bolivia',
            'BA' => 'Bosnia and Herzegowina',
            'RB' => 'Botswana', // ISO-2 is BW
            'BR' => 'Brazil',
            'BRU' => 'Brunei Darussalam', // ISO-3 is BRN (AFAS BRN is Bahrain)
            'BG' => 'Bulgaria',
            'BU' => 'Burkina Faso', // ISO-2 is BF
            'RU' => 'Burundi', // ISO-2 is BI / ISO-2 RU is Russia
            'K' => 'Cambodia',
            'TC' => 'Cameroon', // ISO-2 is CM / ISO-2 TC is Turks and Caicos Islands
            'CDN' => 'Canada', // ISO-3 is CAN
            'CV' => 'Cape Verde',
            'RCA' => 'Central African Republic', // ISO-3 is CAF
            'TD' => 'Chad',
            'RCH' => 'Chile', // ISO-3 is CHL
            'CN' => 'China',
            'CO' => 'Colombia',
            'KM' => 'Comoros',
            'RCB' => 'Congo', // I assume we mean COG which has Brazaville as capital, and the big one (COD, Kinshasa, former Zaire) is not even in this list?
            'CR' => 'Costa Rica',
            'CI' => "Cote D'Ivoire",
            'HR' => 'Croatia',
            'C' => 'Cuba',
            'CY' => 'Cyprus',
            'CZ' => 'Czech Republic',
            'DK' => 'Denmark',
            'DJI' => 'Djibouti',
            'WD' => 'Dominica', // ISO-2 is DM
            'DOM' => 'Dominican Republic',
            'TLS' => 'East Timor',
            'EC' => 'Ecuador',
            'ET' => 'Egypt', // ISO-2 is EG / ISO-2 ET is Ethiopia
            'EL' => 'El Salvador', // ISO-2 is SV
            'CQ' => 'Equatorial Guinea', // ISO-2 is GQ. (Is this a typo?)
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
            'WAG' => 'Gambia', // GMB
            'GE' => 'Georgia',
            'D' => 'Germany',
            'GH' => 'Ghana',
            'GIB' => 'Gibraltar',
            'GR' => 'Greece',
            'GRO' => 'Greenland', // GRL
            'WG' => 'Grenada', // ISO-2 is GD
            'GP' => 'Guadeloupe',
            'GUM' => 'Guam',
            'GCA' => 'Guatemala', // GTM
            'GN' => 'Guinea',
            'GW' => 'Guinea-bissau',
            'GUY' => 'Guyana',
            'RH' => 'Haiti', // ISO-2 is HT
            'HMD' => 'Heard and Mc Donald Islands',
            'HON' => 'Honduras', // HND
            'HK' => 'Hong Kong',
            'H' => 'Hungary',
            'IS' => 'Iceland',
            'IND' => 'India',
            'RI' => 'Indonesia', // ISO-2 is IN
            'IR' => 'Iran (Islamic Republic of)',
            'IRQ' => 'Iraq',
            'IRL' => 'Ireland',
            'IL' => 'Israel',
            'I' => 'Italy',
            'JA' => 'Jamaica', // ISO-2 is JM
            'J' => 'Japan',
            'HKJ' => 'Jordan', // JOR
            'KZ' => 'Kazakhstan',
            'EAK' => 'Kenya', // KEN
            'KIR' => 'Kiribati',
            'KO' => "Korea, Democratic People's Republic of", // ISO-2 is KP
            'ROK' => 'Korea, Republic of',
            'KWT' => 'Kuwait',
            'KG' => 'Kyrgyzstan',
            'LAO' => "Lao People's Democratic Republic",
            'LV' => 'Latvia',
            'RL' => 'Lebanon', // ISO-2 is LB (AFAS LB is Liberia)
            'LS' => 'Lesotho',
            'LB' => 'Liberia', // ISO-2 is LR
            'LAR' => 'Libyan Arab Jamahiriya', // LBY
            'FL' => 'Liechtenstein', // ISO-2 is LI
            'LT' => 'Lithuania',
            'L' => 'Luxembourg',
            'MO' => 'Macau',
            'MK' => 'Macedonia, The Former Yugoslav Republic of',
            'RM' => 'Madagascar', // ISO-2 is MG
            'MW' => 'Malawi',
            'MAL' => 'Malaysia', // MYS
            'MV' => 'Maldives',
            'RMM' => 'Mali', // MLI
            'M' => 'Malta',
            'MAR' => 'Marshall Islands', // MHL
            'MQ' => 'Martinique',
            'RIM' => 'Mauritania', // MRT
            'MS' => 'Mauritius', // ISO-2 is MU (AFAS MS is Montserrat)
            'MYT' => 'Mayotte',
            'MEX' => 'Mexico',
            'MIC' => 'Micronesia, Federated States of', // FSM
            'MD' => 'Moldova, Republic of',
            'MC' => 'Monaco',
            'MON' => 'Mongolia', // MNG
            'MSR' => 'Montserrat',
            'MA' => 'Morocco',
            'MOC' => 'Mozambique', // MOZ
            'BUR' => 'Myanmar', // MMR
            'SWA' => 'Namibia', // NAM
            'NR' => 'Nauru',
            'NL' => 'Nederland',
            'NPL' => 'Nepal',
            'NA' => 'Netherlands Antilles', // ISO-2 is AN / ISO-2 NA is Namibia
            'NCL' => 'New Caledonia',
            'NZ' => 'New Zealand',
            'NIC' => 'Nicaragua',
            'RN' => 'Niger', // ISO-2 is NE
            'WAN' => 'Nigeria', // NGA
            'NIU' => 'Niue',
            'NFK' => 'Norfolk Island',
            'MNP' => 'Northern Mariana Islands',
            'N' => 'Norway',
            'OMA' => 'Oman', // OMN
            'PK' => 'Pakistan',
            'PLW' => 'Palau',
            'PSE' => 'Palestina',
            'PA' => 'Panama',
            'PNG' => 'Papua New Guinea',
            'PY' => 'Paraguay',
            'PE' => 'Peru',
            'RP' => 'Philippines', // ISO-2 is PH
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
            'WL' => 'Saint Lucia', // ISO-2 is LC
            'WV' => 'Saint Vincent and the Grenadines', // ISO-2 is VC
            'WSM' => 'Samoa',
            'RSM' => 'San Marino', // SMR
            'ST' => 'Sao Tome and Principe',
            'AS' => 'Saudi Arabia', // ISO-2 is SA / ISO-2 AS is American Samoa
            'SN' => 'Senegal',
            'SRB' => 'Serbia',
            'SY' => 'Seychelles', // ISO-2 is SC / ISO-2 SY is Syria
            'WAL' => 'Sierra Leone', // SLE
            'SGP' => 'Singapore',
            'SK' => 'Slovakia (Slovak Republic)',
            'SLO' => 'Slovenia', // SVN
            'SB' => 'Solomon Islands',
            'SP' => 'Somalia', // ISO-2 is SO
            'ZA' => 'South Africa',
            'GS' => 'South Georgia and the South Sandwich Islands',
            'E' => 'Spain',
            'CL' => 'Sri Lanka', // ISO-2 is LK / ISO-2 CL is Chile
            'SHN' => 'St. Helena',
            'SPM' => 'St. Pierre and Miquelon',
            'SUD' => 'Sudan', // SDN
            'SME' => 'Suriname', // SUR
            'SJM' => 'Svalbard and Jan Mayen Islands',
            'SD' => 'Swaziland', // ISO-2 is SZ / ISO-2 SD is Sudan
            'S' => 'Sweden',
            'CH' => 'Switzerland',
            'SYR' => 'Syrian Arab Republic',
            'RC' => 'Taiwan', // ISO-2 is TW
            'TAD' => 'Tajikistan', // TJK
            'EAT' => 'Tanzania, United Republic of', // TZA
            'T' => 'Thailand',
            'TG' => 'Togo',
            'TK' => 'Tokelau',
            'TO' => 'Tonga',
            'TT' => 'Trinidad and Tobago',
            'TN' => 'Tunisia',
            'TR' => 'Turkey',
            'TMN' => 'Turkmenistan', // TKM
            'TCA' => 'Turks and Caicos Islands',
            'TV' => 'Tuvalu',
            'EAU' => 'Uganda', // UGA
            'UA' => 'Ukraine',
            'AE' => 'United Arab Emirates',
            'GB' => 'United Kingdom',
            'USA' => 'United States',
            'UMI' => 'United States Minor Outlying Islands',
            'ROU' => 'Uruguay', // URY
            'OEZ' => 'Uzbekistan', // UZB
            'VU' => 'Vanuatu',
            'VAT' => 'Vatican City State (Holy See)',
            'YV' => 'Venezuela', // ISO-2 is VE
            'VN' => 'Viet Nam',
            'VGB' => 'Virgin Islands (British)',
            'VIR' => 'Virgin Islands (U.S.)',
            'WLF' => 'Wallis and Futuna Islands',
            'ESH' => 'Western Sahara',
            'YMN' => 'Yemen', // YEM
            'Z' => 'Zambia',
            'ZW' => 'Zimbabwe',
        ];
        // Define two static arrays, so we don't need to array_search, or
        // array_flip on every call.
        static $codes_by_name;
        if (empty($codes_by_name)) {
            $codes_by_name = array_flip(array_map('strtolower', $names_by_code));
        }

        if (isset($codes_by_name[strtolower($name)])) {
            return $codes_by_name[$name];
        }
        if ($default_behavior | 1 && isset($names_by_code[strtoupper($name)])) {
            return strtoupper($name);
        }
        if ($default_behavior | 2) {
            return $name;
        }
        if ($default_behavior | 4) {
            return 'NL';
        }
        return '';
    }
}
