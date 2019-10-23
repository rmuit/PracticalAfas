<?php

/**
 * This file is part of the PracticalAfas package.
 *
 * (c) Roderik Muit <rm@wyz.biz>
 *
 * For the full copyright and license information, please view the LICENSE file
 * that was distributed with this source code.
 */

namespace PracticalAfas\Tests\Helpers;

use PracticalAfas\UpdateConnector\UpdateObject;

/**
 * Trait which can handle arrays containing 'metadata' as field values.
 *
 * Each field can be stored as either a scalar or an array, where the first
 * array element is assumed to be the actual field value and the rest is
 * supposed to be 'metadata'.
 *
 * This is a theoretical example, mostly meant to provide some extra testing
 * for validation methods, to see if they are properly abstracted. There's no
 * known practical application for storing per-object, per-field 'metadata'.
 */
trait ArrayFieldValuesTrait
{
    /**
     * Signifies whether a class method is in the process of setting a value.
     *
     * This is necessary because validateFieldValue() is used both on setting
     * values into the class (input) as getting values for output. That makes
     * this class have to jump through an extra hoop to determine this, because
     * we need to treat 'input' and 'output' differently.
     *
     * @var bool
     */
    protected $inputMode = false;

    /**
     * {@inheritdoc}
     */
    public function setField($field_name, $value, $element_index = 0, $validation_behavior = UpdateObject::VALIDATE_ESSENTIAL)
    {
        $this->inputMode = true;
        try {
            parent::setField($field_name, $value, $element_index, $validation_behavior);
        } finally {
            $this->inputMode = false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function setObject($reference_field_name, array $embedded_elements, $action = null, $element_index = 0, $validation_behavior = UpdateObject::VALIDATE_ESSENTIAL)
    {
        $this->inputMode = true;
        try {
            parent::setObject($reference_field_name, $embedded_elements, $action, $element_index, $validation_behavior);
        } finally {
            $this->inputMode = false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function setElement($element_index, array $element, $validation_behavior = UpdateObject::VALIDATE_ESSENTIAL)
    {
        $this->inputMode = true;
        try {
            parent::setElement($element_index, $element, $validation_behavior);
        } finally {
            $this->inputMode = false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function addElements(array $elements, $validation_behavior = UpdateObject::VALIDATE_ESSENTIAL)
    {
        $this->inputMode = true;
        try {
            parent::addElements($elements, $validation_behavior);
        } finally {
            $this->inputMode = false;
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function validateFieldValue($value, $field_name, $change_behavior = UpdateObject::DEFAULT_CHANGE, $validation_behavior = UpdateObject::DEFAULT_VALIDATION, $element_index = null, array $element = null)
    {
        // Custom behavior is only implemented for arrays with keys 0 & 1. Also
        // if ALLOW_NO_CHANGES and VALIDATE_NOTHING are specified, then skip
        // custom behavior and return the element literally. (We can argue over
        // why only with this specific combination - and will ignore that here.)
        if (
            is_array($value) && isset($value[0]) && isset($value[1])
            && ($change_behavior != UpdateObject::ALLOW_NO_CHANGES || $validation_behavior !== UpdateObject::VALIDATE_NOTHING)
        ) {
            // First validate the first value in the array; then perform some
            // logic on it. (Some other class might swap this order.)
            $validated = parent::validateFieldValue($value[0], $field_name, $change_behavior, $validation_behavior, $element_index, $element);

            if ($this->inputMode) {
                // On input, we only need to validate the first array element
                // but want to set (i.e. return) the complete array. If
                // validation has changed the value (which is really not
                // expected) we'll set the changed value.
                $value[0] = $validated;
            } elseif (
                is_scalar($value[1])
                && (empty($this->propertyDefinitions['fields'][$field_name]['type']) || $this->propertyDefinitions['fields'][$field_name]['type'] === 'string')
                && $field_name !== 'CoId' && $field_name !== 'country_iso'
            ) {
                // On output, we might want to modify the element according to
                // some logic combined with the other elements. Example logic:
                // only if the data type is a string, prepend the second
                // element value. (Hardcode key 'l'.) Exempt 'CoId' from this,
                // because otherwise we can't test phone numbers anymore.
                $value = $value[1] . ':' . reset($value);
            } else {
                // ... and for non-strings or other unexpected situations,
                // return only the just validated value.
                $value = $validated;
            }
        } else {
            // Non-array value: standard behavior.
            $value = parent::validateFieldValue($value, $field_name, $change_behavior, $validation_behavior, $element_index, $element);
        }

        return $value;
    }
}
