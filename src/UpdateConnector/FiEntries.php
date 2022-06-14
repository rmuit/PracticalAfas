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
 * An UpdateObject containing definitions / logic for FiEntryPar objects.
 *
 * This has its own class because it's the only object type that needs to
 * implement IsoCountryTrait and has no other custom functionality.
 */
class FiEntries extends UpdateObject
{

    /**
     * {@inheritdoc}
     */
    protected function getDefaultPropertyDefinitions()
    {
        return [
            'fields' => [
                // Nummer
                'EnNo' => [
                    'alias' => 'Nummer journaalpost',
                    'required' => true,
                ],
                'VaAs' => [
                    'alias' => 'Kenmerk rekening',
                    'required' => true,
                ],
                'AcNr' => [
                    'alias' => 'Rekeningnummer',
                    'required' => true,
                ],
                'EnDa' => [
                    'alias' => 'Datum boeking',
                    'required' => true,
                ],
                'BpDa' => [
                    'alias' => 'Boekstukdatum',
                    'required' => true,
                    'type' => 'date',
                ],
                'BpNr' => [
                    'alias' => 'Boekstuknummer'
                ],
                'InId' => [
                    'alias' => 'Factuurnummer'
                ],
                'Ds' => [
                    'alias' => 'Omschrijving boeking'
                ],
                'AmDe' => [
                    'alias' => 'Bedrag debit',
                ],
                'AmCr' => [
                    'alias' => 'Bedrag credit',
                ],
                'VaId' => [
                    'alias' => 'Btw-code',
                ],
                'CuId' => [
                    'alias' => 'Valuta',
                ],
                'AmDc' => [
                    'alias' => 'Valutabedrag debit',
                ],
                'AmCc' => [
                    'alias' => 'Valutabedrag credit',
                ],
                'OrNu' => [
                    'alias' => 'Verkoopordernummer',
                ],
                'Fref' => [
                    'alias' => 'Factuurreferentie',
                ]
            ],
        ];
    }

    /**
     * {@inheritdoc}
     */
    protected function validateFields(array $element, $element_index, $change_behavior = self::DEFAULT_CHANGE, $validation_behavior = self::DEFAULT_VALIDATION)
    {
        // $element = $this->convertIsoCountryCodeFields($element, $element_index, $change_behavior);

        return parent::validateFields($element, $element_index, $change_behavior, $validation_behavior);
    }
}
