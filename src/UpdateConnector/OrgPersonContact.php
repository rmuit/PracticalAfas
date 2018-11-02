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
 * An UpdateObject containing data about Organisations / Persons / Contacts.
 *
 * Implements AFAS object types KnContact, KnOrganisation, KnPerson. Also
 * contains a generally useful static method for validating phone numbers
 * (Dutch only at the moment).
 *
 * The objects are in one class because they have overlapping logic that
 * applies to certain fields.
 */
class OrgPersonContact extends ObjectWithCountry
{
    /**
     * @see output(); bitmask value for the $change_behavior argument.
     *
     * This has been implemented separately from ALLOW_CHANGE / ALLOW_REFORMAT
     * so that it can (and needs to be) explicitly specified if you want to
     * format phone numbers uniformly, separately from other changes. The
     * dangerous thing about it is that the default format template drops the
     * country code.
     *
     * Validating (but not reformatting) the phone number is driven by the
     * standard VALIDATE_FORMAT value for the $validation_behavior argument;
     * that is also not 'on' by default.
     */
    const ALLOW_REFORMAT_PHONE_NR = 1024;

    /**
     * A format template for the phone number. Must include %A and %L.
     *
     * Replacement tokens are %C for country code (numeric part), %A for area
     * code (without trailing 0) and %L for the local part. There are no
     * mechanisms for escaping / using these tokens literally in the template.
     *
     * The default value is really only applicable for administrations that
     * have phone numbers in a single country; then again, our code currently
     * only validates Dutch phone numbers.
     *
     * @var string
     */
    protected static $phoneNumberFormat = '0%A-%L';

    /**
     * Returns the phone number format template.
     *
     * @return string
     */
    public static function getPhoneNumberFormat()
    {
        return static::$phoneNumberFormat;
    }

    /**
     * Sets the phone number format template.
     *
     * @param $format
     *   The format
     */
    public static function setPhoneNumberFormat($format)
    {
        if (strpos($format, '%A') === false || strpos($format, '%L') === false) {
            throw new InvalidArgumentException('Phone number format does not contain both %A and %L.');
        }

        static::$phoneNumberFormat = $format;
    }

    /**
     * @inheritDoc
     */
    public function __construct(array $elements = [], $action = '', $type = '', $validation_behavior = self::VALIDATE_ESSENTIAL, $parent_type = '')
    {
        // If we don't have definitions, we'll define them here for the one
        // known type, and otherwise fall through to the parent. This way, it's
        // possible for a custom object to extend this class but still use a
        // definition in the parent as a basis.
        if (empty($this->propertyDefinitions)) {
            // Below definitions are based on what AFAS calls the 'XSD Schema'
            // for SOAP, retrieved though a Data Connector in november 2014,
            // amended with extra info like aliases and defaults. There are
            // lots of Dutch comment lines in this function; these were
            // gathered from an online knowledge base page around 2012 when
            // that was the only form/language of documentation.
            switch ($type) {
                case 'KnContact':
                    // This has no ID property. Updating standalone knContact
                    // objects can be done by passing BcCoOga + BcCoPer values.
                    $this->propertyDefinitions = [
                        // As we are extending ObjectWithCountry (for KnPerson),
                        // we have to define this even though we don't use it.
                        'iso_country_fields' => [],
                        'objects' => [
                            // KnPerson added conditionally below.
                            'KnBasicAddressAdr' => [
                                'type' => 'KnBasicAddress',
                                'alias' => 'address',
                            ],
                            'KnBasicAddressPad' =>  [
                                'type' => 'KnBasicAddress',
                                'alias' => 'postal_address',
                            ],
                        ],
                        'fields' => [
                            // The 2 code fields are only defined if KnContact
                            // isn't embedded in another object; see below.
                            // They're also required in that case, both on
                            // insert and update; see validateFields().
                            'BcCoOga' => [
                                'alias' => 'organisation_code',
                                'required' => true,
                            ],
                            // Code persoon
                            'BcCoPer' => [
                                'alias' => 'person_code',
                                'required' => true,
                            ],
                            // Postadres is adres
                            'PadAdr' => [
                                'alias' => 'postal_address_is_address',
                                'type' => 'boolean',
                                // Default of true is set dynamically in
                                // validateFields(), depending on presence of
                                // KnAddress objects.
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
                            // I never used below 2 fields but it feels like
                            // AFAS is using KnContact for both 'contact
                            // database' and 'AFAS user accounts' and these are
                            // applicable to the latter. Which would be why
                            // they're only defined if KnContact is not
                            // embedded (as per below).
                            // Persoon toegang geven tot afgeschermde deel van de portal(s)
                            'AddToPortal' => [
                                'type' => 'boolean',
                            ],
                            // E-mail toegang
                            'EmailPortal' => [],
                        ],
                    ];

                    // There's an extra field and some are invalid, if this
                    // element is embedded in an organisation/person element.
                    // (Which likely means: if it's embedded in any element.)
                    if ($parent_type === 'KnOrganisation' || $parent_type === 'KnPerson') {
                        // According to the XSD, a knContact can contain a
                        // knPerson if it's inside a knOrganisation, but not if
                        // it's standalone.
                        if ($parent_type === 'KnOrganisation') {
                            $this->propertyDefinitions['objects']['KnPerson'] = ['alias' => 'person'];
                        }
                        // (It doesn't make immediate sense to me that e.g.
                        // BcCoOga would not be available if the parent is a
                        // person; especially because of above. I assume this
                        // came from the same XSD though, so let's stick with
                        // it.)
                        unset($this->propertyDefinitions['fields']['BcCoOga']);
                        unset($this->propertyDefinitions['fields']['BcCoPer']);
                        unset($this->propertyDefinitions['fields']['AddToPortal']);
                        unset($this->propertyDefinitions['fields']['EmailPortal']);
                        $this->propertyDefinitions['fields'] += [
                            // Soort Contact
                            // Values:  AFD:Afdeling bij organisatie   AFL:Afleveradres
                            // if parent is knOrganisation: + PRS:Persoon bij organisatie (alleen mogelijk i.c.m. KnPerson tak)
                            //
                            // The description in 'parent' update connectors' (KnOrganisation, knContact) KB pages is:
                            // "Voor afleveradressen gebruikt u de waarde 'AFL': <ViKc>AFL</ViKc>"
                            'ViKc' => [
                                'alias' => 'contact_type',
                                // Dynamic default depending on parent; see
                                // validateFields().
                                // @todo should this be required? I've been
                                //   inadvertently inserting contacts without
                                //   types for ages, myself.
                            ],
                            // A note: we could be validating ViKc values in
                            // validateFields() but so far have decided to let
                            // AFAS do that during the API call, so our
                            // validation does not go outdated. If AFAS itself
                            // does not give clear error messages, we should
                            // start doing the validation ourselves.
                        ];
                    }
                    break;

                case 'KnPerson':
                    $this->propertyDefinitions = [
                        'iso_country_fields' => [
                            'birth_country_iso' => 'DaBi'
                        ],
                        'objects' => [
                            //'KnBankAccount' => 'bank_account',
                            'KnBasicAddressAdr' => [
                                'type' => 'KnBasicAddress',
                                'alias' => 'address',
                            ],
                            'KnBasicAddressPad' => [
                                'type' => 'KnBasicAddress',
                                'alias' => 'postal_address',
                            ],
                            'KnContact' => [
                                'alias' => 'contact',
                            ],
                        ],
                        'fields' => [
                            'AutoNum' => [
                                'alias' => 'auto_num',
                                'type' => 'boolean',
                                // See below for a dynamic default.
                            ],
                            /* NOTE - in Qoony sources in 2011 (which inserted KnPerson objects
                             *   inside KnSalesRelationPer), MatchPer value 3 had the comment
                             *   "match customer by mail". Qoony used 3 until april 2014, when
                             *   suddenly updates broke, giving "organisation vs person objects"
                             *   and "multiple person objects found for these search criteria"
                             *   errors. So apparently the official description (below) was not
                             *   accurate until 2014, and maybe the "match customer by mail"
                             *   was implemented until then?
                             *   While fixing the breakage, AFAS introduced an extra value for
                             *   us which we used from april 2014 until Qoony EOL:
                             * 9: always update embedded knPerson objects with the given data.
                             *    (We know which knPerson object to update because they already
                             *    have a reference from the embedding KnSalesRelationPer.
                             *    When inserting instead of updating data, I guess this falls
                             *    back to behavior '7', given our usage at Qoony.)
                             */
                            // Persoon vergelijken op
                            // Values:  0:Zoek op BcCo (Persoons-ID)   1:Burgerservicenummer   2:Naam + voorvoegsel + initialen + geslacht   3:Naam + voorvoegsel + initialen + geslacht + e-mail werk   4:Naam + voorvoegsel + initialen + geslacht + mobiel werk   5:Naam + voorvoegsel + initialen + geslacht + telefoon werk   6:Naam + voorvoegsel + initialen + geslacht + geboortedatum   7:Altijd nieuw toevoegen
                            // If MatchPer is specified and if the corresponding
                            // fields have values, the difference between action
                            // "update" and "insert" falls away: if there is a match
                            // (but only one) the existing record is updated; if
                            // there isn't, a new one is inserted. If there are
                            // multiple matches, or a wrong match method is
                            // specified, AFAS throws an error. 7 should always
                            // insert, regardless of action. For more details see
                            // "Automatic matching" in documentation in this dir.
                            'MatchPer' => [
                                'alias' => 'match_method',
                                'type' => 'integer',
                                // A 'safe' default is set below. We make it so
                                // that the default makes sense in situations
                                // where we can assume we know what the user
                                // wants, and will cause an error to be thrown
                                // otherwise (meaning that the user is forced
                                // to pass a MatchPer value in those cases).
                            ],
                            // Organisatie/persoon (intern)
                            // From "Organisaties toevoegen en wijzigen (UpdateConnector KnOrganisation)":
                            // "Do not deliver the 'BcId' field."
                            // (Because it really is internal. So why should we define it?)
                            //'BcId' => [
                            //  'type' => 'integer',
                            //),
                            // Nummer, 1-15 chars
                            'BcCo' => [
                                // This is called "Nummer" here by AFAS but the
                                // field name itself obviously refers to 'code',
                                // and also a reference field in KnContact is
                                // called "Code organisatie" by AFAS. Let's be
                                // consistent and call it "code" here too.
                                // ('ID' would be more confusing because it's
                                // not the internal ID.)
                                'alias' => 'code',
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
                                // Requiredness is sometimes unset below.
                                'required' => true,
                            ],
                            // Initialen
                            'In' => [
                                'alias' => 'initials',
                            ],
                            // Prefix has mostly Dutch, like "van"
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
                            ],
                            // Nationaliteit (verwijzing naar: Tabelwaarde,Nationaliteit (NEN1888) => AfasKnCodeTableValue)
                            // Values:  000:Onbekend   NL:Nederlandse   DZ:Algerijnse   AN:Angolese   RU:Burundische   RB:Botswaanse   BU:Burger van Burkina Faso   RCA:Centrafrikaanse   KM:Comorese   RCB:Kongolese   DY:Beninse   ET:Egyptische   EQ:Equatoriaalguinese   ETH:Etiopische   DJI:Djiboutiaanse   GA:Gabonese   WAG:Gambiaanse   GH:Ghanese   GN:Guinese   CI:Ivoriaanse   CV:Kaapverdische   TC:Kameroense   EAK:Kenyaanse   CD:ZaÃ¯rese   LS:Lesothaanse   LB:Liberiaanse   LAR:Libische   RM:Malagassische   MW:Malawische   RMM:Malinese   MA:Marokkaanse   RIM:Burger van Mauritanië   MS:Burger van Mauritius   MOC:Mozambiquaanse   SD:Swazische   RN:Burger van Niger   WAN:Burger van Nigeria   EAU:Ugandese   GW:Guineebissause   ZA:Zuidafrikaanse   ZW:Zimbabwaanse   RWA:Rwandese   ST:Burger van SÃ£o TomÃ© en Principe   SN:Senegalese   WAL:Sierraleoonse   SUD:Soedanese   SP:Somalische   EAT:Tanzaniaanse   TG:Togolese   TS:Tsjadische   TN:Tunesische   Z:Zambiaanse   ZSUD:Zuid-Soedanese   BS:Bahamaanse   BH:Belizaanse   CDN:Canadese   CR:Costaricaanse   C:Cubaanse   DOM:Burger van Dominicaanse Republiek   EL:Salvadoraanse   GCA:Guatemalteekse   RH:HaÃ¯tiaanse   HON:Hondurese   JA:Jamaicaanse   MEX:Mexicaanse   NIC:Nicaraguaanse   PA:Panamese   TT:Burger van Trinidad en Tobago   USA:Amerikaans burger   RA:Argentijnse   BDS:Barbadaanse   BOL:Boliviaanse   BR:Braziliaanse   RCH:Chileense   CO:Colombiaanse   EC:Ecuadoraanse   GUY:Guyaanse   PY:Paraguayaanse   PE:Peruaanse   SME:Surinaamse   ROU:Uruguayaanse   YV:Venezolaanse   WG:Grenadaanse   KN:Burger van Saint Kitts-Nevis   SK:Slowaakse   CZ:Tsjechische   BA:Burger van Bosnië-Herzegovina   GE:Burger van Georgië   AFG:Afgaanse   BRN:Bahreinse   BT:Bhutaanse   BM:Burmaanse   BRU:Bruneise   K:Kambodjaanse   CL:Srilankaanse   CN:Chinese   CY:Cyprische   RP:Filipijnse   TMN:Burger van Toerkmenistan   RC:Taiwanese   IND:Burger van India   RI:Indonesische   IRQ:Iraakse   IR:Iraanse   IL:Israëlische   J:Japanse   HKJ:Jordaanse   TAD:Burger van Tadzjikistan   KWT:Koeweitse   LAO:Laotiaanse   RL:Libanese   MV:Maldivische   MAL:Maleisische   MON:Mongolische   OMA:Omanitische   NPL:Nepalese   KO:Noordkoreaanse   OEZ:Burger van Oezbekistan   PK:Pakistaanse   KG:Katarese   AS:Saoediarabische   SGP:Singaporaanse   SYR:Syrische   T:Thaise   AE:Burger van de Ver. Arabische Emiraten   TR:Turkse   UA:Burger van Oekraine   ROK:Zuidkoreaanse   VN:Viëtnamese   BD:Burger van Bangladesh   KYR:Burger van Kyrgyzstan   MD:Burger van Moldavië   KZ:Burger van Kazachstan   BY:Burger van Belarus (Wit-Rusland)   AZ:Burger van Azerbajdsjan   AM:Burger van Armenië   AUS:Australische   PNG:Burger van Papua-Nieuwguinea   NZ:Nieuwzeelandse   WSM:Westsamoaanse   RUS:Burger van Rusland   SLO:Burger van Slovenië   AG:Burger van Antigua en Barbuda   VU:Vanuatuse   FJI:Fijische   GB4:Burger van Britse afhankelijke gebieden   HR:Burger van Kroatië   TO:Tongaanse   NR:Nauruaanse   USA2:Amerikaans onderdaan   LV:Letse   SB:Solomoneilandse   SY:Seychelse   KIR:Kiribatische   TV:Tuvaluaanse   WL:Sintluciaanse   WD:Burger van Dominica   WV:Burger van Sint Vincent en de Grenadinen   EW:Estnische   IOT:British National (overseas)   ZRE:ZaÃ¯rese (Congolese)   TLS:Burger van Timor Leste   SCG:Burger van Servië en Montenegro   SRB:Burger van Servië   MNE:Burger van Montenegro   LT:Litouwse   MAR:Burger van de Marshalleilanden   BUR:Myanmarese   SWA:Namibische   499:Staatloos   AL:Albanese   AND:Andorrese   B:Belgische   BG:Bulgaarse   DK:Deense   D:Duitse   FIN:Finse   F:Franse   YMN:Jemenitische   GR:Griekse   GB:Brits burger   H:Hongaarse   IRL:Ierse   IS:IJslandse   I:Italiaanse   YU:Joegoslavische   FL:Liechtensteinse   L:Luxemburgse   M:Maltese   MC:Monegaskische   N:Noorse   A:Oostenrijkse   PL:Poolse   P:Portugese   RO:Roemeense   RSM:Sanmarinese   E:Spaanse   VAT:Vaticaanse   S:Zweedse   CH:Zwitserse   GB2:Brits onderdaan   ERI:Eritrese   GB3:Brits overzees burger   MK:Macedonische   XK:Kosovaar
                            'PsNa' => [],
                            // Geboortedatum
                            'DaBi' => [],
                            // Geboorteland (verwijzing naar: Land => AfasKnCountry)
                            'CoBi' => [],
                            // Fake ISO field for CoBi:
                            'birth_country_iso' => [],
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
                                // [ comment 2014: ]
                                // ALG was given in Qoony (where person was inside knSalesRelationPer).
                                // in newer environment where it's inside knOrganisation > knContact,
                                // I don't even see this one in an entry screen.
                                //'default' => 'ALG',
                            ],
                            // Tweede titel (verwijzing naar: Titel => AfasKnTitle)
                            'TtEx' => [],
                            // Briefaanhef
                            'LeHe' => [],
                            // Postadres is adres
                            'PadAdr' => [
                                'alias' => 'postal_address_is_address',
                                'type' => 'boolean',
                                // Default of true is set below, dependent on
                                // presence of KnAddress objects.
                            ],
                            // Telefoonnr. werk
                            'TeNr' => [
                                // Note aliases are reassigned if this object
                                // is embedded in KnSalesRelationPer; see below.
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

                    if ($parent_type === 'KnContact' || $parent_type === 'KnSalesRelationPer') {
                        // Note that a 'standalone' KnContact cannot contain a
                        // knPerson. We know only of the situation where this
                        // parent knContact is embedded in a knOrganisation.
                        $this->propertyDefinitions['fields'] += [
                            // Land wetgeving (verwijzing naar: Land => AfasKnCountry)
                            'CoLw' => [],
                            // Fake ISO field for CoLw:
                            'regul_country_iso' => [],
                        ];
                        $this->propertyDefinitions['iso_country_fields']['regul_country_iso'] = 'CoLw';

                        if ($parent_type === 'KnSalesRelationPer') {
                            // [ Tested in 2012: ]
                            // Usually, phone/mobile/e-mail aliases are set to the
                            // business ones, and these are the ones shown in the
                            // AFAS UI. If this KnPerson object is embedded in
                            // KnSalesRelationPer, the AFAS UI shows the private
                            // equivalents instead. (At least that was the case for
                            // Qoony.) So it's those you want to fill by default.
                            // Reassign aliases from business to private fields.
                            $this->propertyDefinitions['fields']['TeN2']['alias'] = $this->propertyDefinitions['fields']['TeNr']['alias'];
                            unset($this->propertyDefinitions['fields']['TeNr']['alias']);
                            $this->propertyDefinitions['fields']['MbN2']['alias'] = $this->propertyDefinitions['fields']['MbNr']['alias'];
                            unset($this->propertyDefinitions['fields']['MbNr']['alias']);
                            $this->propertyDefinitions['fields']['EmA2']['alias'] = $this->propertyDefinitions['fields']['EmAd']['alias'];
                            unset($this->propertyDefinitions['fields']['EmAd']['alias']);
                        }
                    }
                    break;

                case 'KnOrganisation':
                    $this->propertyDefinitions = [
                        // As we are extending ObjectWithCountry (for KnPerson),
                        // we have to define this even though we don't use it.
                        'iso_country_fields' => [],
                        'objects' => [
                            //'KnBankAccount' => 'bank_account',
                            'KnBasicAddressAdr' => [
                                'type' => 'KnBasicAddress',
                                'alias' => 'address',
                            ],
                            'KnBasicAddressPad' => [
                                'type' => 'KnBasicAddress',
                                'alias' => 'postal_address',
                            ],
                            'KnContact' => [
                                'alias' => 'contact',
                            ],
                        ],
                        'fields' => [
                            'AutoNum' => [
                                'alias' => 'auto_num',
                                'type' => 'boolean',
                                // See below for a dynamic default.
                            ],
                            // Organisatie vergelijken op
                            // Values:  0:Zoek op BcCo   1:KvK-nummer   2:Fiscaal nummer   3:Naam   4:Adres   5:Postadres   6:Altijd nieuw toevoegen
                            // If MatchOga is specified and if the corresponding
                            // fields have values, the difference between action
                            // "update" and "insert" falls away: if there is a match
                            // (but only one) the existing record is updated; if
                            // there isn't, a new one is inserted. If there are
                            // multiple matches, or a wrong match method is
                            // specified, AFAS throws an error. 6 should always
                            // insert, regardless of action. For more details see
                            // "Automatic matching" in documentation in this dir.
                            'MatchOga' => [
                                'alias' => 'match_method',
                                'type' => 'integer',
                                // A 'safe' default is set below. We make it so
                                // that the default makes sense in situations
                                // where we can assume we know what the user
                                // wants, and will cause an error to be thrown
                                // otherwise (meaning that the user is forced
                                // to pass a MatchOga value in those cases).
                            ],
                            // Organisatie/persoon (intern)
                            // From docs "Organisaties toevoegen en wijzigen (UpdateConnector KnOrganisation)":
                            // "Do not deliver the 'BcId' field."
                            // (Because it really is internal. So why should we define it?)
                            //'BcId' => [
                            //),
                            // Nummer, 1-15 chars
                            'BcCo' => [
                                // This is called "Nummer" here by AFAS but the
                                // field name itself obviously refers to 'code',
                                // and also a reference field in KnContact is
                                // called "Code organisatie" by AFAS. Let's be
                                // consistent and call it "code" here too.
                                // ('ID' would be more confusing because it's
                                // not the internal ID.)
                                'alias' => 'code',
                            ],
                            'SeNm' => [
                                'alias' => 'search_name',
                            ],
                            // Name. Is not required officially, but I guess we
                            // must fill in either BcCo, SeNm or Nm to be able
                            // to find the record back. (Or maybe you get an
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
                            // Postadres is adres
                            // The name is equal to KnBasicAddress.PbAd but the
                            // meaning is equal to KnContact/KnPerson.PadAdr.
                            // Was that a mistake on AFAS' part or mine? I did
                            // add the same alias as to the PadAdr's.
                            'PbAd' => [
                                'alias' => 'postal_address_is_address',
                                'type' => 'boolean',
                                // Default of true is set below, dependent on
                                // presence of KnAddress objects.
                            ],
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
                    break;
            }
            if ($this->propertyDefinitions) {
                // An object cannot contain its parent type. (Example: knPerson
                // can have knContact as a parent, and it can also contain
                // knContact... except when its parent is knContact. Note this
                // implies the 'reference field name' is the same as the type.
                if (isset($this->propertyDefinitions['objects'][$parent_type])) {
                    unset($this->propertyDefinitions['objects'][$parent_type]);
                }
            }
        }

        parent::__construct($elements, $action, $type, $validation_behavior, $parent_type);
    }

    /**
     * {@inheritdoc}
     */
    protected function validateFields(array $element, $element_index, $change_behavior = self::DEFAULT_CHANGE, $validation_behavior = self::DEFAULT_VALIDATION)
    {
        // For requiredness (which is checked in the parent method) we have
        // 'dynamic' definitions that depend on the value of another field. Set
        // or reset them here - and do some other checks.
        switch ($this->getType()) {
            case 'KnContact':
                // Check requiredness of BcCoXXX fields on updates, since these
                // are necessary 'id fields'. (On insert, this extra code isn't
                // necessary because the 'required' property governs that.) For
                // better understanding: as per __construct(), let's assume
                // both fields are only defined when not embedded, and in those
                // cases it's also impossible to embed a KnPerson into this
                // object. (Or a KnOrganisation; that's never possible.)
                if (isset($this->propertyDefinitions['fields']['BcCoOga']) && empty($element['Fields']['BcCoOga'])) {
                    $element_descr = "'{$this->getType()}' element" . ($element_index ? ' with index ' . ($element_index + 1) : '');
                    $element['*errors']["Fields:BcCoOga"] = "No value provided for required 'BcCoOga' (organisation_code) field of $element_descr.";
                }
                if (isset($this->propertyDefinitions['fields']['BcCoPer']) && empty($element['Fields']['BcCoPer'])) {
                    $element_descr = "'{$this->getType()}' element" . ($element_index ? ' with index ' . ($element_index + 1) : '');
                    $element['*errors']["Fields:BcCoPer"] = "No value provided for required 'BcCoPer' (person_code) field of $element_descr.";
                }
                break;

            case 'KnPerson':
                // First name is required only if initials are not present.
                $this->propertyDefinitions['fields']['FiNm']['required'] = empty($element['Fields']['In']);

                // (Other check:) 'normalize' person name fields if specified.
                // ALLOW_CHANGES is not set by default.
                if ($change_behavior & self::ALLOW_CHANGES) {
                    $element['Fields'] = $this->convertNameFields($element['Fields'], $element_index);
                }
        }

        $element = parent::validateFields($element, $element_index, $change_behavior, $validation_behavior);

        // Set dynamic defaults (when value is still null) for several fields.
        // We could have set/reset the 'default' properties above, but that's
        // more involved, without benefit.
        $action = $this->getAction($element_index);
        $defaults_allowed = ($action === 'insert' && $change_behavior & self::ALLOW_DEFAULTS_ON_INSERT)
            || ($action === 'update' && $change_behavior & self::ALLOW_DEFAULTS_ON_UPDATE);

        // If the definition contains reference fields for both address and
        // postal address, and the data has an address but no postal address
        // set, then the default becomes postal_address_is_address = true. (If
        // both addresses are set, then we don't set the default to false and /
        // or compare the two addresses; we assume that's not necessary.)
        // That postal_address_is_address is called KnContact.PadAdr,
        // KnPerson.PadAdr and KnOrganisation.PbAd; I'm not 100% sure if this
        // is a mistake so we'll just do the two names, always. (Note that PbAd
        // means something different in KnBasicAddress, but that is not defined
        // by this class.)
        if ($defaults_allowed && isset($this->propertyDefinitions['objects']['KnBasicAddressAdr'])
            && isset($this->propertyDefinitions['objects']['KnBasicAddressPad'])
            && !empty($element['Objects']['KnBasicAddressAdr'])
            && empty($element['Objects']['KnBasicAddressPad'])
        ) {
            if (isset($this->propertyDefinitions['fields']['PadAdr']) && !isset($element['Fields']['PadAdr'])) {
                $element['Fields']['PadAdr'] = true;
            }
            if (isset($this->propertyDefinitions['fields']['PbAd']) && !isset($element['Fields']['PbAd'])) {
                $element['Fields']['PbAd'] = true;
            }
        }

        // If no ID is specified, default AutoNum to True for inserts. This is
        // an 'operation modifier' rather than a real field, only to be set
        // on insert. This presents a bit of a conundrum:
        // - We don't want to have 'setting AutoNum' be dependent on the
        //   ALLOW_DEFAULTS_ON_INSERT bit. (If someone wants to try and insert
        //   an organisation/person without defaults, it should still be
        //   auto-numbered.
        // - On the other hand, if we're called through getElements() with
        //   default arguments, the caller really wants to have the elements
        //   returned unchanged, for whatever purpose (e.g. unit tests).
        // So we'll be slightly hand-wavy here and say: never change in case
        // of those default arguments; otherwise it's allowed.
        $dont_change_anything = $change_behavior == self::ALLOW_NO_CHANGES && $validation_behavior == self::VALIDATE_NOTHING;
        if (!isset($element['Fields']['BcCo']) && $action === 'insert' && !$dont_change_anything
            && isset($this->propertyDefinitions['fields']['AutoNum']) && !isset($element['Fields']['AutoNum'])) {
            $element['Fields']['AutoNum'] = true;
        }

        switch ($this->getType()) {
            case 'KnContact':
                if ($defaults_allowed && $this->parentType === 'KnOrganisation') {
                    // If the element being validated contains person data,
                    // 'Persoon' is default contact type.
                    if (!empty($element['Objects']['KnPerson']) && !isset($element['Fields']['ViKc'])) {
                        $element['Fields']['ViKc'] = 'PRS';
                    }
                }
                break;

            case 'KnPerson':
                // Organization/Person objects have MatchXXX fields which we
                // always want to fill with values on update as well as insert
                // (because these are 'operation modifiers' rather than real
                // fields). We're presented with the same $dont_change_anything
                // conundrum as above.
                if (!isset($element['Fields']['MatchPer']) && !$dont_change_anything) {
                    // The MatchPer default is first of all influenced by
                    // whether we're inserting a record. For non-inserts, our
                    // principle is we would rather insert duplicate data than
                    // silently overwrite data by accident...
                    if ($action === 'insert') {
                        $element['Fields']['MatchPer'] = 7;
                    } elseif (!empty($element['Fields']['BcCo'])) {
                        // ...but it seems very unlikely that someone would
                        // specify BcCo when they don't explicitly want the
                        // corresponding record overwritten. So we match on
                        // BcCo in that case.
                        // Con: This overwrites existing data if there is a
                        //      'typo' in the BcCo field.
                        // Pro: - Now people are not forced to think about this
                        //        field. (If we left it empty, they would
                        //        likely have to pass it.)
                        //      - Predictability. If we leave this empty, we
                        //        don't know what AFAS will do. (And if AFAS
                        //        throws an error, we're back to the user
                        //        having to specify 0, which means it's easier
                        //        if we do it for them.)
                        $element['Fields']['MatchPer'] = 0;
                    } elseif (!empty($element['Fields']['SoSe'])) {
                        // I guess we can assume the same logic for BSN, since
                        // that's supposedly also a unique number.
                        $element['Fields']['MatchPer'] = 1;
                    } else {
                        // Probably even with action "update", a new record
                        // will be inserted if there is no match... but we do
                        // not know this for sure! Since our principle is to
                        // prevent silent overwrites of data, we here force an
                        // error for "update" if MatchPer is not explicitly set
                        // in $element. (If anyone disagrees / encounters
                        // circumstances where this is not OK: open an issue/PR
                        // to refine this.)
                        $element['Fields']['MatchPer'] = 0;
                    }
                }
                break;

            case 'KnOrganisation':
                if (!isset($element['Fields']['MatchOga']) && !$dont_change_anything) {
                    // See comments at MatchPer just above.
                    if ($action === 'insert') {
                        $element['Fields']['MatchOga'] = 6;
                    } elseif (!empty($element['Fields']['BcCo'])) {
                        $element['Fields']['MatchOga'] = 0;
                    } elseif (!empty($element['Fields']['CcNr'])) {
                        // I guess we can assume the same logic for KvK number,
                        // since that's supposedly also a unique number.
                        $element['Fields']['MatchOga'] = 1;
                    } elseif (!empty($element['Fields']['FiNr'])) {
                        // ...and fiscal number.
                        $element['Fields']['MatchOga'] = 2;
                    } else {
                        $element['Fields']['MatchOga'] = 0;
                    }
                }
        }

        return $element;
    }

    /**
     * @inheritDoc
     */
    protected function validateFieldValue($value, $field_name, $change_behavior = self::DEFAULT_CHANGE, $validation_behavior = self::DEFAULT_VALIDATION, $element_index = null, array $element = null)
    {
        $value = parent::validateFieldValue($value, $field_name, $change_behavior, $validation_behavior, $element_index, $element);

        // Validate and/or change the format of a Dutch phone number, using
        // a country code in an address object - which may be embedded either
        // in this element or in an embedded element. Note we cannot get to
        // addresses embedded in a parent object; those are checked later, in
        // validateReferenceFields(). This code isn't guaranteed to absolutely
        // always behave correctly... but the relevant bits are 'off' by
        // default, and the code is a good start. Skip all this if any errors
        // were encountered earlier because we only want to search address
        // fields in embedded elements that could be validated.
        if ($element && empty($element['*errors'])
            && isset($value) && in_array($field_name, ['TeNr', 'MbNr', 'FaNr', 'TeN2', 'MbN2'], true)
            && ($change_behavior & self::ALLOW_REFORMAT_PHONE_NR || $validation_behavior & self::VALIDATE_FORMAT)) {
            // First, establish whether we even know the country code, since
            // that is inside an address object. This means this validation /
            // change is not water tight; it will be skipped for updates (or
            // addElement() calls where the action was not set yet) which do
            // not include address objects. Compare country in main address if
            // available; otherwise in postal address if available. (It's a
            // little doubtful whether we want to take the postal address as
            // the base, but it probably works out for updates that happen to
            // update only the postal address at the same time...)
            // Again: this is all a bit wishy-washy, but a good start...
            if ($this->getType() === 'KnPerson') {
                // An address in a KnPerson is considered a personal address,
                // so validate personal numbers against that. Validate business
                // numbers against KnContact only. (KnOrganisation cannot
                // be embedded here so don't look for it.)
                $personal_nr = in_array($field_name, ['TeN2', 'MbN2'], true);
                $search_current = $personal_nr;
                $search_embedded_types = $personal_nr ? : ['KnContact'];
            } else {
                // If KnOrganisation: don't validate the company's numbers
                // against an address in an embedded object. If KnContact:
                // 'TeNr' is called "Telefoonnr. werk" so let's assume that 1)
                // we only need to verify against work addresses and 2) if this
                // object has an address, it's also a work address. This means
                // we only need to look into our own object, because KnPerson
                // has a personal address and KnOrganisation cannot be embedded.
                $search_current = true;
                $search_embedded_types = [];
            }
            $address = $search_current ? static::getAddressFields($element, ['KnBasicAddressAdr', 'KnBasicAddressPad']) : [];
            if (!$address && $search_embedded_types) {
                $address = static::getAddressFields($element, ['KnBasicAddressAdr', 'KnBasicAddressPad'], false, $search_embedded_types);
            }
            if ($address && (!isset($address['CoId']) || strtoupper($address['CoId']) === 'NL')) {
                $parts = static::validateDutchPhoneNr($value);
                if (!$parts && $validation_behavior & self::VALIDATE_FORMAT) {
                    throw new InvalidArgumentException("Phone number '$field_name' has invalid format.");
                }
                if ($parts && $change_behavior & self::ALLOW_REFORMAT_PHONE_NR) {
                    // Only replace area code and local part into here;
                    // country code is lost.
                    $value = str_replace('%L', $parts[1], str_replace('%A', $parts[0], static::getPhoneNumberFormat()));
                }
            }
        }

        return $value;
    }

    /**
     * @inheritDoc
     */
    protected function validateReferenceFields(array $element, $element_index, $change_behavior = self::DEFAULT_CHANGE, $validation_behavior = self::DEFAULT_VALIDATION)
    {
        $element = parent::validateReferenceFields($element, $element_index, $change_behavior, $validation_behavior);

        // If we have an address object and another OrgPersonContact embedded,
        // check if there's a phone field inside those objects that we should
        // retroactively validate / reformat. This is the counterpart to
        // validateFieldValue(). We need to do this once both objects are
        // validated, so this seems a good place. Note we don't need to
        // validate numbers in the current object; we've done that already.
        if (empty($element['*errors'])) {
            $address = static::getAddressFields($element, ['KnBasicAddressAdr', 'KnBasicAddressPad']);
            if ($address && (!isset($address['CoId']) || strtoupper($address['CoId']) === 'NL')) {
                // This uses an extension of validateFieldValue() logic:
                $type2 = '';
                switch ($this->getType()) {
                    case 'KnOrganisation':
                        // Validate business numbers in embedded
                        // KnContact/KnPerson except if there's an address in
                        // the KnContact (because then that address has already
                        // been used for validating all numbers in both itself
                        // and its embedded KnPerson, as per below.)
                        $type = 'KnContact';
                        $type2 = 'KnPerson';
                        $number_fields = ['TeNr', 'MbNr', 'FaNr'];
                        break;

                    case 'KnPerson':
                        // Validate personal numbers in our own object only.
                        $type = '';
                        $number_fields = ['TeN2', 'MbN2'];
                        break;

                    default:
                        // KnContact: as per earlier, we assume all numbers are
                        // business numbers. Validate business numbers in our
                        // own object, and in KnPerson except if there's an
                        // address in there.
                        $type = 'KnPerson';
                        $number_fields = ['TeNr', 'MbNr', 'FaNr'];
                        break;
                }
                // Each embedded type has only one element, keyed by 'Element'.
                // (It's all arrays, not objects, because the structure was
                // validated already.)
                if (!empty($element['Objects'][$type]['Element']['Fields'])
                    && !static::getAddressFields($element['Objects'][$type]['Element'], ['KnBasicAddressAdr', 'KnBasicAddressPad'])) {
                    foreach ($number_fields as $field_name) {
                        if (!empty($element['Objects'][$type]['Element']['Fields'][$field_name])) {
                            $parts = static::validateDutchPhoneNr($element['Objects'][$type]['Element']['Fields'][$field_name]);
                            if (!$parts && $validation_behavior & self::VALIDATE_FORMAT) {
                                throw new UnexpectedValueException("Phone number '$field_name' has invalid format.");
                            }
                            if ($parts && $change_behavior & self::ALLOW_REFORMAT_PHONE_NR) {
                                // Only replace area code and local part
                                // into here; country code is lost.
                                $element['Objects'][$type]['Element']['Fields'][$field_name] = str_replace('%L', $parts[1], str_replace('%A', $parts[0], static::getPhoneNumberFormat()));
                            }
                        }
                    }
                    if ($type2 && !empty($element['Objects'][$type]['Element']['Objects'][$type2]['Element']['Fields'])
                        && !static::getAddressFields($element['Objects'][$type]['Element']['Objects'][$type2]['Element'], ['KnBasicAddressAdr', 'KnBasicAddressPad'])) {
                        foreach ($number_fields as $field_name) {
                            if (!empty($element['Objects'][$type]['Element']['Objects'][$type2]['Element']['Fields'][$field_name])) {
                                $parts = static::validateDutchPhoneNr($element['Objects'][$type]['Element']['Objects'][$type2]['Element']['Fields'][$field_name]);
                                if (!$parts && $validation_behavior & self::VALIDATE_FORMAT) {
                                    throw new UnexpectedValueException("Phone number '$field_name' has invalid format.");
                                }
                                if ($parts && $change_behavior & self::ALLOW_REFORMAT_PHONE_NR) {
                                    // Only replace area code and local part
                                    //  into here; country code is lost.
                                    $element['Objects'][$type]['Element']['Objects'][$type2]['Element']['Fields'][$field_name] = str_replace('%L', $parts[1], str_replace('%A', $parts[0], static::getPhoneNumberFormat()));
                                }
                            }
                        }
                    }
                }
            }
        }

        return $element;
    }

    /**
     * Returns the 'Fields' part of an address object for a certain element.
     *
     * @param array $element
     *   The element, which may represent a single element from parent object
     *   containing an address object. This code tries to work with the same
     *   uncertainties as validateFieldValue() does re. the contents.
     * @param array $search_address_types
     *   The address types to check. By default only the regular address, but
     *   this can be appended or replaced with ['KnBasicAddressAdr'] to (also)
     *   check the postal address.
     * @param bool $search_current
     *   (Optional) If true, check the element itself for embedded addresses.
     * @param array $search_embedded_types
     *   (Optional) If no embedded address is found in the element itself
     *   (or $search_current is false) then check these embedded object types
     *   for addresses, recursively. (E.g. if $element is a KnOrganisation,
     *   then specify ['KnContact', 'KnPerson'] to check for an address in an
     *   embedded contact object, and then if not present, check in a person
     *   object embedded in the contact. All types are checked on all layers.)
     *
     * @throws \UnexpectedValueException
     *   If the address object (is still an object and) does not validate.
     *
     * @return array|mixed
     */
    protected static function getAddressFields(array $element, array $search_address_types = ['KnBasicAddressAdr'], $search_current = true, array $search_embedded_types = []) {
        $address = [];
        if ($search_current) {
            // First, see if there's an address directly inside this element.
            foreach ($search_address_types as $name) {
                if (!empty($element['Objects'][$name])) {
                    $address = $element['Objects'][$name];
                    break;
                }
            }
            if ($address) {
                // $address is an object or a one-element array, depending on
                // the caller. Get the Fields part. (Note in case of an array
                // validateObjectValue() has made sure there's only one element
                // inside "Element", because all addresses are non-multiple.)
                $address = $address instanceof UpdateObject ? $address->getElements(self::DEFAULT_CHANGE)[0]['Fields'] : $address['Element']['Fields'];
            }
        }
        if (!$address && $search_embedded_types) {
            // Check for address in embedded elements, in the argument's order.
            foreach ($search_embedded_types as $reference_field_name) {
                if (!empty($element['Objects'][$reference_field_name])) {
                    $embedded_element = $element['Objects'][$reference_field_name];
                    $embedded_element = $embedded_element instanceof UpdateObject ? $embedded_element->getElements(self::DEFAULT_CHANGE)[0] : $embedded_element['Element'];
                    $address = static::getAddressFields($embedded_element, $search_address_types, true, $search_embedded_types);
                    if ($address) {
                        break;
                    }
                }
            }
        }

        return $address ?: [];
    }

    /**
     * Derives initials / prefix / search name field from first / last name.
     *
     * @param array $fields
     *   The 'Fields' array of a KnContact element that is being validated.
     * @param int $element_index
     *   (optional) The index of the element in our object data; usually there
     *   is one element and the index is 0. For modifying a set of fields which
     *   are not stored in this object, do not pass this value.
     *
     * @return array
     *   The same fields array, possibly modified.
     */
    public function convertNameFields(array $fields, $element_index = 0)
    {
        // Not-officially-documented behavior of this method: if the action
        // related to $fields is not "insert", then most fields we want to
        // populate get set to either a derived value or '' - so any value
        // currently in AFAS can be overwritten. If the action is "insert",
        // most fields which are not set to a derived value, are not set at all.

        // If any of the relevant fields are non-strings, skip silently.
        if (isset($fields['LaNm']) && !is_string($fields['LaNm'])
            || isset($fields['FiNm']) && !is_string($fields['FiNm'])
            || isset($fields['SeNm']) && !is_string($fields['SeNm'])
            || isset($fields['Is']) && !is_string($fields['Is'])
            || isset($fields['In']) && !is_string($fields['In'])
        ) {
            return $fields;
        }

        // Split off (Dutch) prefix from last name. NOTE: creepily hardcoded
        // stuff. Trailing spaces are necessary, and sometimes ordering matters.
        // ('van de ' before 'van '.)
        if (!empty($fields['LaNm']) && (!isset($fields['Is']) || $fields['Is'] === '')) {
            $fields['LaNm'] = trim($fields['LaNm']);
            $name = strtolower($fields['LaNm']);
            $found = false;
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
                    $fields['Is'] = rtrim($value);
                    $fields['LaNm'] = trim(substr($fields['LaNm'], strlen($value)));
                    $found = true;
                    break;
                }
            }
            // If the prefix is not set, then set it to empty string for the
            // non-"insert" action.
            if (!$found && !isset($fields['Is']) && $this->getAction($element_index) !== 'insert') {
                $fields['Is'] = '';
            }
        }

        // Derive initials from first name, if not set yet. If first name seems
        // to be only initials, move to initials field and empty first name.
        if (!empty($fields['FiNm']) && (!isset($fields['In']) || $fields['In'] === '')) {
            $fields['FiNm'] = trim($fields['FiNm']);

            // Check if the first name field really contains only initials. If
            // so, move the value to the initials field and empty out the first
            // name. AFAS' automatic resolving code in its (contact)person UI
            // (tested in 2012) thinks anything is initials if it contains a
            // dot. It will then insert spaces in between every letter of the
            // initials, but we won't do that last part. (It may be good for
            // user UI input, but coded data does not expect it.)
            if (strlen($fields['FiNm']) == 1
                || strlen($fields['FiNm']) < 16
                && strpos($fields['FiNm'], '.') !== false
                && strpos($fields['FiNm'], ' ') === false
            ) {
                // Dot but no spaces, or just one letter: we consider these all
                // initials (just like the AFAS UI does.)
                $fields['In'] = strlen($fields['FiNm']) == 1 ?
                    strtoupper($fields['FiNm']) . '.' : $fields['FiNm'];
                if ($this->getAction($element_index) !== 'insert') {
                    unset($fields['FiNm']);
                } else {
                    $fields['FiNm'] = '';
                }
            } elseif (preg_match('/^[A-Za-z \-]+$/', $fields['FiNm'])) {
                // First name only contains letters, spaces and hyphens. In
                // this case (which is probably stricter than the AFAS UI),
                // create initials from all parts of the first name.
                $fields['In'] = '';
                foreach (preg_split('/[- ]+/', $fields['FiNm']) as $part) {
                    // Don't separate initials by spaces, only dot.
                    $fields['In'] .= strtoupper($part[0]) . '.';
                }
            }
            // If the first name field contains both a dot and spaces, we
            // change nothing.
        }

        // Derive search name from last name, if not set yet.
        if (!empty($fields['LaNm']) && empty($fields['SeNm'])) {
            // Zoeknaam: we got no request for a special definition of this, so:
            $fields['SeNm'] = strtoupper($fields['LaNm']);
            // Max length is 10, and we don't need to be afraid of duplicates.
            if (strlen($fields['SeNm']) > 10) {
                $fields['SeNm'] = substr($fields['SeNm'], 0, 10);
            }
        }

        return $fields;
    }

    /**
     * Checks if a string can be interpreted as a valid Dutch phone number.
     *
     * This can be used as a starter to implement uniform formatting of
     * phone numbers throughout an AFAS instance, by calling this and using the
     * output in validateFields(). This is not done by default because its's
     * not sure which format is 'the uniform one'. (Also, AFAS might have
     * implemented some functionality since I last used this code.) Passing
     * the VALIDATE_FORMAT to output(,,,$validation_behavior) will activate
     * this validation.
     *
     * There's only a "Dutch" function since AFAS will have 99% Dutch clients.
     * Extended helper functionality can be added as needed.
     *
     * @param string $phone_number
     *   Phone number to be validated.
     *
     * @return array
     *   If not recognized, empty array. If recognized: 2-element array with
     *   area(/mobile) code without the trailing zero, and local part. The
     *   local part is not uniformly re-formatted.
     */
    public static function validateDutchPhoneNr($phone_number)
    {
        if (!is_string($phone_number)) {
            return [];
        }

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

            if (preg_match('/^\s*' . $regex . '\s*$/x', $phone_number, $matches)) {
                $return = [
                    strtr($matches[1], [' ' => '', '-' => '', '+31' => '0']),
                    $matches[2],
                ];
                // $return[0] is a space-less area code now, with or without
                // trailing 0. $return[1] is not formatted.
                if ($return[0][0] === '0') {
                    $return[0] = substr($return[0], 1);
                }
                return $return;
            }
        }
        return [];
    }
}
