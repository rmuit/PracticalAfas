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
class KnSubject extends UpdateObject
{

    /**
     * {@inheritdoc}
     */
    protected function getDefaultPropertyDefinitions()
    {
        return [
            'objects' => [
                'KnSubjectLink' => [
                    'alias' => 'subject_link',
                    'multiple' => false
                ],
            ],
            'fields' => [
                // Dossieritem
                'SbId' => [
                    'alias' => 'Dossieritem',
                    'required' => true,
                ],
                // Type
                'StId' => [
                    'alias' => 'Type dossieritem',
                    'required' => true,
                ],
                // Onderwerp
                'Ds' => [
                    'alias' => 'Onderwerp',
                    'required' => true,
                ],
                // Naam van document
                'SbPa' => [
                    'alias' => 'FileName of the attachment'
                ],
                // Soort bestand
                'FileTrans' => [
                    'alias' => 'Save file with subject',
                    'type' => 'boolean',
                ],
                // BASE64 van bestand
                'FileStream' => [
                    'alias' => 'File as byte-array',
                    'type' => 'blob',
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
