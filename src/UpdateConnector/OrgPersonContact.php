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
 * * An UpdateObject containing data about Organisations / Persons / Contacts.
 *
 * Implements AFAS object types KnContact, KnOrganisation, KnPerson. Also
 * contains a generally useful static method for validating phone numbers.
 *
 * The reason to put these three types into one class is: they have overlapping
 * logic that applies to certain fields. See getProperties().
 */
class OrgPersonContact extends ObjectWithCountry
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
            case 'KnContact':
                // This has no ID property. Updating standalone knContact
                // objects can be done by passing BcCoOga + BcCoPer values.
                $definitions = [
                    // Since we are extending ObjectWithCountry (for KnPerson),
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
                            'alias' => 'postal_address_is_address',
                            'type' => 'boolean',
                            // Default of true is set below, dependent on
                            // presence of KnAddress objects.
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

                // There's an extra field and some are invalid, if this element
                // is sent embedded in an organisation/person element.
                if ($this->parentType === 'KnOrganisation' || $this->parentType === 'KnPerson') {
                    unset($definitions['fields']['BcCoOga']);
                    unset($definitions['fields']['BcCoPer']);
                    unset($definitions['fields']['AddToPortal']);
                    unset($definitions['fields']['EmailPortal']);
                    $definitions['fields'] += [
                        // Soort Contact
                        // Values:  AFD:Afdeling bij organisatie   AFL:Afleveradres
                        // if parent is knOrganisation: + PRS:Persoon bij organisatie (alleen mogelijk i.c.m. KnPerson tak)
                        //
                        // The description in 'parent' update connectors' (KnOrganisation, knContact) KB pages is:
                        // "Voor afleveradressen gebruikt u de waarde 'AFL': <ViKc>AFL</ViKc>"
                        'ViKc' => [
                            'alias' => 'contact_type',
                        ],
                        // A note: we could be validating the possible values in
                        // validateFields() but so far have decided to let AFAS
                        // do that during the REST API call, so our validation
                        // does not go outdated. If AFAS itself does not give
                        // clear error messages, we should start doing the
                        // validation ourselves.
                    ];

                    // According to the XSD, a knContact can contain a knPerson
                    // if it's inside a knOrganisation, but not if it's
                    // standalone.
                    if ($this->parentType === 'KnOrganisation') {
                        $definitions['objects']['KnPerson'] = ['alias' => 'person'];

                        // If the element being validated contains person data,
                        // we set 'Persoon' as default contact type.
                        if (!empty($element['Objects']['KnPerson'])) {
                            $definitions['fields']['ViKc']['default'] = 'PRS';
                        }
                    }
              }
                break;

            case 'KnPerson':
                $definitions = [
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
                            // A 'safe' default is set below. We make it so that
                            // the default makes sense in situations where we
                            // can assume we know what the user wants, and
                            // will cause an error to be thrown otherwise
                            // (meaning that the user is forced to pass a
                            // MatchPer value in those cases).
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
                            // This is called "Nummer" here by AFAS but the field
                            // name itself obviously refers to 'code', and also
                            // a reference field in KnContact is called "Code persoon"
                            // by AFAS. Let's be consistent and call it "code" here too.
                            // ('ID' would be more confusing because it's not the internal ID.)
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
                            // Note aliases are reassigned if this object is
                            // embedded in KnSalesRelationPer; see below.
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
                if (!empty($element['Fields']['In']) || !empty($element['Fields']['initials'])) {
                    unset($definitions['fields']['FiNm']['required']);
                }

                if ($this->parentType === 'KnContact' || $this->parentType === 'KnSalesRelationPer') {
                    // Note that a 'standalone' KnContact cannot contain a
                    // knPerson. So far we know only of the situation where this
                    // parent knContact is again embedded in a knOrganisation.
                    $definitions['fields'] += [
                        // Land wetgeving (verwijzing naar: Land => AfasKnCountry)
                        'CoLw' => [],
                        // Fake ISO field for CoLw:
                        'regul_country_iso' => [],
                    ];
                    $definitions['iso_country_fields']['regul_country_iso'] = 'CoLw';

                    if ($this->parentType === 'KnSalesRelationPer') {
                        // [ Tested in 2012: ]
                        // Usually, phone/mobile/e-mail aliases are set to the
                        // business ones, and these are the ones shown in the
                        // AFAS UI. If this KnPerson object is embedded in
                        // KnSalesRelationPer, the AFAS UI shows the private
                        // equivalents instead. (At least that was the case for
                        // Qoony.) So it's those you want to fill by default.
                        // Reassign aliases from business to private fields.
                        $definitions['fields']['TeN2']['alias'] = $definitions['fields']['TeNr']['alias'];
                        unset($definitions['fields']['TeNr']['alias']);
                        $definitions['fields']['MbN2']['alias'] = $definitions['fields']['MbNr']['alias'];
                        unset($definitions['fields']['MbNr']['alias']);
                        $definitions['fields']['EmA2']['alias'] = $definitions['fields']['EmAd']['alias'];
                        unset($definitions['fields']['EmAd']['alias']);
                    }
                }

                // The MatchPer default is first of all influenced by whether
                // we're inserting a record. For non-inserts,
                // - Actually taking care that the default is used, is done in
                //   validateFields(); that's a separate issue.
                // - Our principle is we would rather insert duplicate data
                //   than silently overwrite data by accident...
                if ($this->getAction($element_index) === 'insert') {
                    $definitions['fields']['MatchPer']['default'] = '7';
                } elseif (!empty($element['Fields']['BcCo'])) {
                    // ...but it seems very unlikely that someone would specify
                    // BcCo when they don't explicitly want the corresponding
                    // record overwritten. So we match on BcCo in that case.
                    // Con: This overwrites existing data if there is a 'typo'
                    //      in the BcCo field.
                    // Pro: - Now people are not forced to think about this
                    //        field. (If we left it empty, they would likely
                    //        have to pass it.)
                    //      - Predictability. If we leave this empty, we don't
                    //        know what AFAS will do. (And if AFAS throws an
                    //        error, we're back to the user having to specify 0,
                    //        which means it's easier if we do it for them.)
                    $definitions['fields']['MatchPer']['default'] = '0';
                } elseif (!empty($element['Fields']['SoSe'])) {
                    // I guess we can assume the same logic for BSN, since
                    // that's supposedly also a unique number.
                    $definitions['fields']['MatchPer']['default'] = '1';
                } else {
                    // Probably even with action "update", a new record will be
                    // inserted if there is no match... but we do not know this
                    // for sure! Since our principle is to prevent silent
                    // overwrites of data, we here force an error for "update"
                    // if MatchPer is not explicitly set in $element. (If
                    // anyone disagrees / encounters circumstances where this is
                    // not OK: open an issue/PR to refine this.)
                    $definitions['fields']['MatchPer']['default'] = '0';
                }
                break;

            case 'KnOrganisation':
                $definitions = [
                    // Since we are extending ObjectWithCountry (for KnPerson),
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
                            // A 'safe' default is set below. We make it so that
                            // the default makes sense in situations where we
                            // can assume we know what the user wants, and
                            // will cause an error to be thrown otherwise
                            // (meaning that the user is forced to pass a
                            // MatchPer value in those cases).
                        ],
                        // Organisatie/persoon (intern)
                        // From "Organisaties toevoegen en wijzigen (UpdateConnector KnOrganisation)":
                        // "Do not deliver the 'BcId' field."
                        // (Because it really is internal. So why should we define it?)
                        //'BcId' => [
                        //),
                        // Nummer, 1-15 chars
                        'BcCo' => [
                            // This is called "Nummer" here by AFAS but the field
                            // name itself obviously refers to 'code', and also
                            // a reference field in KnContact is called "Code organisatie"
                            // by AFAS. Let's be consistent and call it "code" here too.
                            // ('ID' would be more confusing because it's not the internal ID.)
                            'alias' => 'code',
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
                        // Postadres is adres
                        // The name is equal to KnBasicAddress.PbAd but the
                        // meaning is equal to KnContact.PadAdr and
                        // KnPerson.PadAdr. Was that a mistake on AFAS' part or
                        // mine? I did add the same alias as to the PadAdr's.
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

                // The MatchOga default is first of all influenced by whether
                // we're inserting a record. For non-inserts,
                // - Actually taking care that the default is used, is done in
                //   validateFields(); that's a separate issue.
                // - Our principle is we would rather insert duplicate data
                //   than silently overwrite data by accident...
                if ($this->getAction($element_index) === 'insert') {
                    $definitions['fields']['MatchOga']['default'] = '6';
                } elseif (!empty($element['Fields']['BcCo'])) {
                    // ...but it seems very unlikely that someone would specify
                    // BcCo when they don't explicitly want the corresponding
                    // record overwritten. So we match on BcCo in that case.
                    // See pros/cons at MatchPer.
                    $definitions['fields']['MatchOga']['default'] = '0';
                } elseif (!empty($element['Fields']['CcNr'])) {
                    // I guess we can assume the same logic for KvK number,
                    // since that's supposedly also a unique number.
                    $definitions['fields']['MatchOga']['default'] = '1';
                } elseif (!empty($element['Fields']['FiNr'])) {
                    // ...and fiscal number.
                    $definitions['fields']['MatchOga']['default'] = '2';
                } else {
                    // Probably even with action "update", a new record will be
                    // inserted if there is no match... but we do not know this
                    // for sure! Since our principle is to prevent silent
                    // overwrites of data, we here force an error for "update"
                    // if MatchOga is not explicitly set in $element. (If
                    // anyone disagrees / encounters circumstances where this is
                    // not OK: open an issue/PR to refine this.)
                    $definitions['fields']['MatchOga']['default'] = '0';
                }
                break;

            default:
                throw new UnexpectedValueException("No property definitions found for '{$this->getType()}' object in " . get_class() . ' class.');
        }

        // An object cannot contain its parent type. (Example: knPerson can
        // have knContact as a parent, and it can also contain knContact...
        // except when its parent is knContact. Note this implies the
        // 'reference field name' is the same as the type name.
        if (isset($definitions['objects'][$this->parentType])) {
            unset($definitions['objects'][$this->parentType]);
        }

        // Set dynamic defaults for several objects.

        // If no ID is specified, default AutoNum to True for inserts.
        if (isset($definitions['fields']['AutoNum']) && !isset($element['Fields']['BcCo']) && $this->getAction($element_index) === 'insert') {
            $definitions['fields']['AutoNum']['default'] = true;
        }

        // If the definition contains reference fields for both address and
        // postal address, and the data has an address but no postal address
        // set, then the default becomes postal_address_is_address = true.
        // That postal_address_is_address is called KnContact.PadAdr,
        // KnPerson.PadAdr and KnOrganisation.PbAd; I'm not 100% sure if this
        // is a mistake so we'll just do the two names, always.
        if (isset($definitions['objects']['KnBasicAddressAdr'])
            && isset($definitions['objects']['KnBasicAddressPad'])
            && !empty($element['Objects']['KnBasicAddressAdr'])
            && empty($element['Objects']['KnBasicAddressPad'])
        ) {
            if (isset($definitions['fields']['PadAdr'])) {
                $definitions['fields']['PadAdr']['default'] = true;
            }
            if (isset($definitions['fields']['PbAd'])) {
                $definitions['fields']['PbAd']['default'] = true;
            }
        }

        return $definitions;
    }

    /**
     * {@inheritdoc}
     */
    protected function validateFields(array $element, $element_index, $change_behavior = self::DEFAULT_CHANGE, $validation_behavior = self::DEFAULT_VALIDATION)
    {
        if ($this->getType() === 'KnPerson') {
            // ALLOW_CHANGES is not set by default.
            if ($change_behavior & self::ALLOW_CHANGES) {
                $element['Fields'] = static::convertNameFields($element['Fields']);
            }
        }

        $element = parent::validateFields($element, $element_index, $change_behavior, $validation_behavior);

        // As noted in setPropertyDefinitions(), MatchOga / MatchPer are not
        // real fields but 'operation modifiers' and they got defaults also for
        // non-"insert" actions. But these are not applied automatically.
        if ($this->getAction($element_index) !== 'insert') {
            $definitions = $this->getPropertyDefinitions($element, $element_index);
            foreach (['MatchOga', 'MatchPer'] as $property) {
                if (isset($definitions['fields'][$property]) && !isset($element['Fields'][$property])) {
                    if (!isset($definitions['fields'][$property]['default'])) {
                        throw new UnexpectedValueException("No default value found for '$property' property in '{$this->getType()}' object in " . get_class() . ' class.');
                    }
                    $element['Fields'][$property] = $definitions['fields'][$property]['default'];
                }
            }
        }

        return $element;
    }

    /**
     * Derives initials / prefix / search name field from first / last name.
     *
     * @param array $fields
     *   The 'Fields' array of a KnContact element that is being validated.
     *
     * @return array
     *   The same fields array, possibly modified.
     */
    public static function convertNameFields($fields)
    {
        // Split off (Dutch) prefix from last name. NOTE: creepily hardcoded
        // stuff. Trailing spaces are necessary, and sometimes ordering matters.
        // ('van de ' before 'van '.)
        if (!empty($fields['LaNm']) && empty($fields['Is'])) {
            $fields['LaNm'] = trim($fields['LaNm']);
            $name = strtolower($fields['LaNm']);
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
                    break;
                }
            }
        }

        // Derive initials from first name, if not set yet. If first name seems
        // to be only initials, move to initials field and empty first name.
        if (!empty($fields['FiNm']) && empty($fields['In'])) {
            $fields['FiNm'] = trim($fields['FiNm']);

            // Check if first name is really only initials. If so, move it.
            // AFAS' automatic resolving code in its new-(contact)person UI
            // thinks anything is initials if it contains a dot. It will then
            // insert spaces in between every letter of the initials, but we
            // won't do that last part. (It may be good for user UI input, but
            // coded data does not expect it.)
            if (strlen($fields['FiNm']) == 1
                || strlen($fields['FiNm']) < 16
                && strpos($fields['FiNm'], '.') !== false
                && strpos($fields['FiNm'], ' ') === false
            ) {
                // Dot but no spaces, or just one letter: all initials; move it.
                $fields['In'] = strlen($fields['FiNm']) == 1 ?
                    strtoupper($fields['FiNm']) . '.' : $fields['FiNm'];
                unset($fields['FiNm']);
            } elseif (preg_match('/^[A-Za-z \-]+$/', $fields['FiNm'])) {
                // First name only contains letters, spaces and hyphens. In this
                // case (which is probably stricter than the AFAS UI), create
                // initials.
                $fields['In'] = '';
                foreach (preg_split('/[- ]+/', $fields['FiNm']) as $part) {
                    // Don't separate initials by spaces, only dot.
                    $fields['In'] .= strtoupper(substr($part, 0, 1)) . '.';
                }
            }
            // Note if there's both a dot and spaces in first name, we skip it.
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
     * output in validateFields(). This is not done by default  because its's
     * not sure which format is 'the uniform one' (and also, AFAS might have
     * implemented some functionality since I last used this code.)
     *
     * There's only a "Dutch" function since AFAS will have 99% Dutch clients.
     * Extended helper functionality can be added as needed.
     *
     * @param string $phone_number
     *   Phone number to be validated.
     *
     * @return array
     *   If not recognized, empty array. If recognized: 2-element array with
     *   area(/mobile) code and local part. The local part is not uniformly
     *   re-formatted.
     */
    public static function validateDutchPhoneNr($phone_number)
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

            if (preg_match('/^\s*' . $regex . '\s*$/x', $phone_number, $matches)) {
                $return = [
                    strtr($matches[1], [' ' => '', '-' => '', '+31' => '0']),
                    $matches[2],
                ];
                // $return[0] is a space-less area code now, with or without
                // trailing 0. $return[1] is not formatted.
                if ($return[0][0] !== '0') {
                    $return[0] = "0$return[0]";
                }
                return $return;
            }
        }
        return [];
    }
}
