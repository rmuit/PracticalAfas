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
class FiEntryPar extends UpdateObject
{

    /**
     * {@inheritdoc}
     */
    protected function getDefaultPropertyDefinitions()
    {
        return [
            'objects' => [
                'FiEntries' => [
                    'alias' => 'line_items',
                    'multiple' => true,
                ],
            ],
            'fields' => [
                // Nummer
                'Year' => [
                    'alias' => 'Boekjaar',
                    'required' => true,
                ],
                'Peri' => [
                    'alias' => 'Periode',
                    'required' => true,
                ],
                'UnId' => [
                    'alias' => 'Nummer administratie'
                ],
                'JoCo' => [
                    'alias' => 'Dagboek',
                    'required' => true,
                ],
                'AdDc' => [
                    'alias' => 'Maak verbijzonderingscode'
                ],
                'AdDa' => [
                    'alias' => 'Maak verbijzonderingstoewijzing'
                ],
                'PrTp' => [
                    'alias' => 'Type boeking'
                ],
                'AuNu' => [
                    'alias' => 'Autonummering factuur',
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
