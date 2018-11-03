<?php
/**
 * This file is part of the PracticalAfas package.
 *
 * (c) Roderik Muit <rm@wyz.biz>
 *
 * For the full copyright and license information, please view the LICENSE file
 * that was distributed with this source code.
 */

namespace PracticalAfas\TestHelpers;

use PracticalAfas\UpdateConnector\UpdateObject;

/**
 * Example class which can handle arrays containing 'metadata' as field values.
 *
 * There are related classes that extend their own parent class (containing the
 * custom logic for their object types), but this is the 'base' class with the
 * create() method and an overridden classmap.
 */
class ArraysObject extends UpdateObject
{
    use ArrayFieldValuesTrait;

    /**
     * This function creates ArrayValuesObjects by default.
     *
     * {@inheritdoc}
     */
    public static function create($type, array $elements = [], $action = '', $validation_behavior = self::VALIDATE_ESSENTIAL, $parent_type = '') {
        self::$classMap = array_merge(self::$classMap, [
            // FbSales cannot currently deal with array field values because we
            // did not bother implementing it for this test. (If we do not
            // set the override here, it won't work at all because this class
            // does not know definitions for FbSales.)
            'FbSales' => '\PracticalAfas\UpdateConnector\FbSales',
            'KnBasicAddress' => '\PracticalAfas\TestHelpers\ArraysAddress',
            'KnContact' => '\PracticalAfas\TestHelpers\ArraysOPC',
            'KnOrganisation' => '\PracticalAfas\TestHelpers\ArraysOPC',
            'KnPerson' => '\PracticalAfas\TestHelpers\ArraysOPC',
        ]);

        // If a custom class is defined for this type, instantiate that one
        // because we can't easily extend it here.
        if (isset(static::$classMap[$type])) {
            return new static::$classMap[$type]($elements, $action, $type, $validation_behavior, $parent_type);
        }
        // Extend all other classes. Instead of this, we could also have
        // changed $classmap to contain only the classes which need this logic.
        return new self($elements, $action, $type, $validation_behavior, $parent_type);
    }
}