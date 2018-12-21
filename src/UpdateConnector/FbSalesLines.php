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
 * An UpdateObject containing definitions / logic for FbSalesLines objects.
 */
class FbSalesLines extends UpdateObject
{
    /**
     * {@inheritdoc}
     */
    protected $propertyDefinitions = [
        'objects' => [
            'FbOrderBatchLines' => [
                'alias' => 'batch_line_items',
                'multiple' => true,
            ],
            'FbOrderSerialLines' => [
                'alias' => 'serial_line_items',
                'multiple' => true,
            ],
        ],
        'fields' => [
            // Type item (verwijzing naar: Tabelwaarde,Itemtype => AfasKnCodeTableValue)
            // Values:  1:Werksoort   10:Productie-indicator   11:Deeg   14:Artikeldimensietotaal   2:Artikel   3:Tekst   4:Subtotaal   5:Toeslag   6:Kosten   7:Samenstelling   8:Cursus
            'VaIt' => [
                'alias' => 'item_type',
                'type' => 'integer',
                'default' => 2,
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
                // This may be integer in some cases? Could this be set in
                // validateFields()?
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
                'alias' => 'discount_perc',
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

    /**
     * {@inheritdoc}
     */
    protected function validateFields(array $element, $element_index, $change_behavior = self::DEFAULT_CHANGE, $validation_behavior = self::DEFAULT_VALIDATION)
    {
        // Unit Type and Quantity fields have default values only if item type
        // is Article / Combination. (For other types, we don't know yet.)
        if (isset($element['Fields']['VaIt'])) {
            $is_article = in_array($element['Fields']['VaIt'], [2, 7]);
        } else {
            $is_article = isset($this->propertyDefinitions['fields']['VaIt']['default'])
                && in_array($this->propertyDefinitions['fields']['VaIt']['default'], [2, 7]);
        }
        if ($is_article) {
            $this->propertyDefinitions['fields']['BiUn']['default'] = 'Stk';
            $this->propertyDefinitions['fields']['QuUn']['default'] = 1;
        } else {
            unset($this->propertyDefinitions['fields']['BiUn']['default']);
            unset($this->propertyDefinitions['fields']['QuUn']['default']);
        }
        // We're not sure if 'required' is true for some fields either, so set
        // 'required' only for articles, for now.
        $this->propertyDefinitions['fields']['ItCd']['required'] =
        $this->propertyDefinitions['fields']['BiUn']['required'] =
        $this->propertyDefinitions['fields']['QuUn']['required'] =
        $this->propertyDefinitions['fields']['Upri']['required'] = $is_article;

        return parent::validateFields($element, $element_index, $change_behavior, $validation_behavior);
    }
}
