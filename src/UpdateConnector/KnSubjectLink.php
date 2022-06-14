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
 * An UpdateObject containing definitions / logic for FiInvoices objects.
 *
 * This has its own class because it's the only object type that needs to
 * implement IsoCountryTrait and has no other custom functionality.
 */
class KnSubjectLink extends UpdateObject
{

    /**
     * {@inheritdoc}
     */
    protected function getDefaultPropertyDefinitions()
    {
        return [
            'fields' => [
                // Dossieritem
                'SbId' => [
                    'alias' => 'Dossieritem',
                    'required' => true,
                ],
                // Verkooprelatie
                'ToSR' => [
                    'alias' => 'Verkooprelatie',
                    'required' => true,
                ],
                // Inkooprelatie
                'ToPR' => [
                    'alias' => 'Inkooprelatie',
                    'required' => true,
                ],
                // Type bestemming
                'SfTp' => [
                    'alias' => 'Type bestemming',
                ],
                // Naam van document
                'SfId' => [
                    'alias' => 'Bestemming'
                ],
                // Soort bestand
                'PiUn' => [
                    'alias' => 'Administratie (Inkoop)',
                    'type' => 'int',
                ],
                // Factuurtype
                'PiTp' => [
                    'alias' => 'Factuurtype (inkoop)',
                    'type' => 'int',
                ],
                // Inkoopfactuur
                'PiId' => [
                    'alias' => 'Inkoopfactuur',
                    'type' => 'string',
                ],
            ],
        ];
    }

    /**
     * {@inheritdoc}
     */
    protected function validateFields(array $element, $element_index, $change_behavior = self::DEFAULT_CHANGE, $validation_behavior = self::DEFAULT_VALIDATION)
    {
        return parent::validateFields($element, $element_index, $change_behavior, $validation_behavior);
    }
}
