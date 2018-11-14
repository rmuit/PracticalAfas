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
 * FbSales is in its own class because it's the only object type that needs to
 * implement IsoCountryTrait and has no other custom functionality.
 */
class FbSales extends UpdateObject
{
    use IsoCountryTrait;

    /**
     * {@inheritdoc}
     */
    protected $propertyDefinitions = [
        // See IsoCountryTrait.
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
                // We alias 'unit' to 'Unit' because names/aliases are case
                // sensitive, and people used to using aliases will get
                // confused if 'Unit' is the only field they need to use an
                // uppercase letter for.
                'alias' => 'unit',
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
                'alias' => 'dest_country_afas',
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

    /**
     * {@inheritdoc}
     */
    protected function validateFields(array $element, $element_index, $change_behavior = self::DEFAULT_CHANGE, $validation_behavior = self::DEFAULT_VALIDATION)
    {
        $element = $this->convertIsoCountryCodeFields($element, $element_index, $change_behavior);

        return parent::validateFields($element, $element_index, $change_behavior, $validation_behavior);
    }
}
