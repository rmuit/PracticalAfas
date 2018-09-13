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

/**
 * An UpdateObject containing definitions / logic for physical addresses.
 *
 * Implements AFAS object type KnBasicAddress. Also contains a generally useful
 * static method for reformatting addresses.
 *
 * There is no standalone AFAS UpdateConnector for addresses; they are only
 * embedded in KnContact / KnPerson / KnOrganisation object. This is apparently
 * why AFAS has been able to do 'automatic change tracking' with the BeginDate
 * field? This is also why it is not very clear that the name of the AFAS
 * object type is 'KnBasicAddress'; KnBasicAddressAdr and KnBasicAddressPad are
 * names of the 'object reference fields' inside other objects, that both refer
 * to a KnBasicAddress object.
 *
 * A FbSales object, by contrast, apparently has no embedded address object but
 * some number (integer) referring to an address object; this suggests that an
 * address does have an internal ID which we should be able to retrieve.
 */
class KnBasicAddress extends ObjectWithCountry
{
    /**
     * {@inheritdoc}
     */
    public function getPropertyDefinitions(array $element = null, $element_index = null)
    {
        // There are lots of Dutch comment lines in this function; these were
        // gathered from an online knowledge base page around 2012 when that
        // was the only form/language of documentation.

        /* Ad, HmNr, ZpCd are required.
         * A few lines below, the docs say that RS is also
         *   " 'essential', even if ResZip == true, because if Zip could not be
         *   resolved, the specified value of Rs is taken."
         * so we'll make it required too.
         * 
         * Note the different format (not 4 letters) for BeginDate & ResZip;
         * AFAS apparently does not consider them 'normal' field names. For
         * ResZip this is clear: this value is not stored in the object. 
         * 
         * BeginDate actually is stored like a normal field (AFAICT); it's just
         * connected to the functionality of automatically making a new copy of
         * the address object when it's updated. For the rest, the only thing 
         * special about the field is the fact that it's unnecessary & ignored
         * on inserts, and required on updates.
         * (From the docs:
         * "Organisaties toevoegen en wijzigen (UpdateConnector KnOrganisation)":
         *   Bij het eerste adres (in de praktijk bij een nieuw record) hoeft u
         *   geen begindatum aan te leveren in het veld 'BeginDate' genegeerd.
         *   Als er al een adres bestaat, geeft u met 'BeginDate' de
         *   ingangsdatum van de adreswijziging aan.)
         * 
         * (I don't remember testing whether the BeginDate is actually required
         * to be passed, or if it gets an automatic default of 'today' if left
         * empty. I also don't remember if passing a BeginDate equal to an 
         * existing record overwrites an existing address field rather than 
         * creating a new one. That will have to be tested, I guess.)     
         * 
         * The simplest way to implement what we need seems to be to just give
         * BeginDate a default of 'today'. This could always be passed, but
         * we won't do this for inserts, to not give people who look at API
         * payloads wrong information.
         */
        return [
            // See ObjectWithCountry class.
            'iso_country_fields' => [
                'country_iso' => 'CoId',
            ],
            'fields' => [
                // Land (verwijzing naar: Land => AfasKnCountry)
                'CoId' => [
                ],
                // Fake ISO field for CoId:
                'country_iso' => [],
                // "is postbusadres" (If true: HmNr has number of P.O. box.)
                'PbAd' => [
                    'alias' => 'is_po_box',
                    'type' => 'boolean',
                    'required' => true,
                    'default' => false,
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
                    'type' => 'integer',
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
                // (I think the "AfasKnResidence" reference was outdated docs?)
                'Rs' => [
                    'alias' => 'town',
                    'required' => true,
                ],
                // Adres toevoeging
                'AdAd' => [],
                // Ingangsdatum adreswijziging (wordt genegeerd bij eerste datum)
                'BeginDate' => [
                    'type' => 'date',
                ],
                'ResZip' => [
                    'alias' => 'resolve_zip',
                    'type' => 'boolean',
                    'default' => false,
                ],
            ],
        ];
    }

    /**
     * {@inheritdoc}
     */
    protected function validateFields(array $element, $element_index, $change_behavior = self::DEFAULT_CHANGE, $validation_behavior = self::DEFAULT_VALIDATION)
    {
        // ALLOW_CHANGES is not set by default.
        if ($change_behavior & self::ALLOW_CHANGES) {
            $element['Fields'] = static::convertStreetName($element['Fields']);
        }

        $element = parent::validateFields($element, $element_index, $change_behavior, $validation_behavior);

        // See comments on top of getPropertyDefinitions(): remove BeginDate
        // from output for inserts, and always insert a value for non-inserts.
        if ($this->getAction($element_index) === 'insert') {
            unset($element['Fields']['BeginDate']);
        }
        elseif (empty($element['Fields']['BeginDate'])) {
            // (We can't do this by setting 'default' because defaults are not
            // applied on non-inserts.)
            $element['Fields']['BeginDate'] = date('Y-m-d');
        }

        return $element;
    }

    /**
     * Splits street name which includes house number into separate fields.
     *
     * If the 'Ad' (street) contains something that looks like a street
     * followed by a house number (and possibly extension), and the 'HmNr' and
     * 'HmAd' fields are empty, this splits the house number + ext off into
     * those fields.
     *
     * @param array $fields
     *   The 'Fields' array of an element that is being validated.
     *
     * @return array
     *   The same fields array, possibly modified.
     */
    public static function convertStreetName($fields)
    {
        $matches = [];
        if (!empty($fields['Ad']) && empty($fields['HmNr']) && empty($fields['HmAd'])
            // Split off house number and possible extension from street,
            // because AFAS has separate fields for those. We do this only for
            // defined countries where the splitting of house numbers is common.
            // (This is a judgment call, and the list of countries is arbitrary,
            // but there's slightly less risk of messing up foreign addresses
            // that way.) 'No country' is assumed to be 'NL' since AFAS is
            // NL-centric.
            // This code comes from addressfield_tfnr module and was adjusted
            // later to conform to AFAS' definition of "extension".
            && (empty($fields['CoId']) || in_array($fields['CoId'],
                    ['B', 'D', 'DK', 'F', 'FIN', 'H', 'NL', 'NO', 'S']))
            && preg_match('/^
          (.*?\S) \s+ (\d+) # normal thoroughfare, followed by spaces and a number;
                            # non-greedy because for STREET NR1 NR2, "nr1" should
                            # end up in the number field, not "nr2".
          (?:\s+)?          # optionally separated by spaces
          (\S.{0,29})?      # followed by optional suffix of at most 30 chars (30 is the maximum in the AFAS UI)
          \s* $/x', $fields['Ad'], $matches)
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
            $fields['Ad'] = ltrim($matches[1]);
            $fields['HmNr'] = $matches[2];
            if (!empty($matches[3])) {
                $fields['HmAd'] = rtrim($matches[3]);
            }
        } elseif (!empty($fields['HmNr']) && empty($fields['HmAd'])) {
            // Split off extension from house number
            $matches = [];
            if (preg_match('/^ \s* (\d+) (?:\s+)? (\S.{0,29})? \s* $/x', $fields['HmNr'], $matches)) {
                // Here too, the last ? means $matches[2] may be empty, but
                // prevents a multi-digit number from being split into
                // $matches[1/2].
                if (!empty($matches[2])) {
                    $fields['HmNr'] = $matches[1];
                    $fields['HmAd'] = rtrim($matches[2]);
                }
            }
        }

        return $fields;
    }
}
