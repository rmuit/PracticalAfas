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

use UnexpectedValueException;

/**
 * An UpdateObject containing definitions for all types with a country field.
 *
 * We have special handling for converting ISO country codes to AFAS country
 * codes, since it is expected that not many people will want to use AFAS codes
 * directly.
 *
 * AFAS codes are completely custom, as far as I know. We want to be able to
 * use (e.g. while importing) more universally recognized codes, so this class
 * implements a way to add fake fields to object types, which can hold an
 * ISO3166 country code that will be converted into an AFAS code in the actual
 * field on output/validation. (The ALLOW_CHANGES bit does not need to
 * be specified for this to work, because converting to an equivalent code is
 * not considered an actual change.) It is recommended to always leave the AFAS
 * field empty and use the ISO 'fake field' instead, though that's not
 * required: validation will only throw an exception if both fields contain
 * non-equivalent values.
 *
 * I admit that it is not good practice to do such quasi arbitrary subclassing
 * for object types that don't necessarily have much else in common, but on
 * the other hand I didn't want to merge this custom-ish code into the base
 * object and wanted to make it at least possible to cancel out this behavior.
 * It was a judgment call and we'll see how it plays out.
 *
 * (Actually this implements only one object type now: FbSales - because others
 * are split out into child classes for their own reasons.)
 */
class ObjectWithCountry extends UpdateObject
{
    /**
     * {@inheritdoc}
     */
    public function getPropertyDefinitions(array $element = null, $element_index = null)
    {
        // There are lots of Dutch comment lines in this function; these were
        // gathered from an online knowledge base page around 2012 when that
        // was the only form/language of documentation.


        switch ($this->getType()) {
            case 'FbSales':
                $definitions = [
                    // Define here, which fields are 'fake country fields',
                    // with the real equivalents they are connected to. Both
                    // fields also still need to be defined inside 'fields'.
                    // (validateFields() doesn't need the ISO country
                    // definition but addElements() does.)
                    'iso_country_fields' => [
                        'dest_country_iso' => 'CoId',
                    ],
                    'objects' => [
                        'FbSalesLines' => [
                            'alias' => 'line_items',
                            'multiple' => true,
                        ],
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
                            'type' => 'integer',
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
                            'type' => 'integer',
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
                        'CoId' => [
                           'alias' =>'dest_country_afas',
                        ],
                        // Fake ISO field for CoId:
                        'dest_country_iso' => [],
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
                            'type' => 'integer',
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
                            'type' => 'integer',
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
                            'type' => 'integer',
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

            default:
                throw new UnexpectedValueException("No property definitions found for '{$this->getType()}' object in " . get_class() . ' class.');
        }

        return $definitions;
    }

    /**
     * {@inheritdoc}
     */
    protected function validateFields(array $element, $element_index, $change_behavior = self::DEFAULT_CHANGE, $validation_behavior = self::DEFAULT_VALIDATION)
    {
        // Convert ISO codes to AFAS codes, and empty the ISO code fields.
        // 'iso_country_fields' must be present; if not, this type should not
        // have / extend class ObjectWithCountry.
        $definitions = $this->cachedPropertyDefinitions = $this->getPropertyDefinitions($element, $element_index);
        if (!isset($definitions['iso_country_fields']) || !is_array($definitions['iso_country_fields'])) {
            throw new UnexpectedValueException("'{$this->getType()}' object has no / a non-array 'iso_country_fields' property definition.");
        }
        foreach ($definitions['iso_country_fields'] as $iso_field_name => $afas_field_name) {
            if (!is_string($afas_field_name)) {
                throw new UnexpectedValueException("'iso_country_fields' property definition for '{$this->getType()}' object contains a non-string value.");
            }
            $element = $this->convertIsoCountryCodeField($element, $element_index, $iso_field_name, $afas_field_name);
        }

        $element = parent::validateFields($element, $element_index, $change_behavior, $validation_behavior);

        return $element;
    }

    /**
     * Converts ISO country code in one field to AFAS code in another field.
     *
     * This is suitable to call from validateFields() for any object type
     * which has a 'fake field' defined that can hold an ISO country code.
     *
     * @param array $element
     *   The element (usually the single one contained in $this->elements)
     *   whose fields should be validated.
     * @param int $element_index
     *   (optional) The index of the element in our object data; usually there
     *   is one element and the index is 0. Used in exception messages.
     * @param $iso_field_name
     *   The name of the 'fake field' (which should be emptied out after
     *   conversion so it is not sent into AFAS).
     * @param $afas_field_name
     *   The name of the actual AFAS field, which should be populated with an
     *   AFAS country code.
     *
     * @return array
     *   The element with its fields changed if appropriate.
     */
    protected function convertIsoCountryCodeField(array $element, $element_index, $iso_field_name, $afas_field_name)
    {
        if (!empty($element['Fields'][$iso_field_name])) {
            $element_descr = "'{$this->getType()}' element" . ($element_index ? ' with index ' . ($element_index + 1) : '');

            $afas_code = static::convertIsoCountryCode($element['Fields'][$iso_field_name]);
            if (!$afas_code) {
                throw new UnexpectedValueException("Unknown ISO country code '{$element['Fields'][$iso_field_name]}' in $element_descr.");
            }
            // The CoId field should not be filled, but if it's the same as
            // the converted ISO code, we allow that.
            if (!empty($element['Fields'][$afas_field_name])) {
                if ($element['Fields'][$afas_field_name] !== $afas_code) {
                    throw new UnexpectedValueException("Inconsistent ISO country code '{$element['Fields'][$iso_field_name]}' and AFAS code '{$element['Fields'][$afas_field_name]}'' found in $element_descr.");
                }
            } else {
                $element['Fields'][$afas_field_name] = $afas_code;
            }
            unset($element['Fields'][$iso_field_name]);
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
     *   The corresponding AFAS country code.
     */
    public static function convertIsoCountryCode($iso_code)
    {
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
        // is allowed to call convertCountryName() with an ISO code, because
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
