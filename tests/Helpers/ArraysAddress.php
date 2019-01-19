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

use PracticalAfas\UpdateConnector\KnBasicAddress;

/**
 * Example class which can handle arrays containing 'metadata' as field values.
 *
 * This class combines the property definitions and part of the logic from
 * the parent class, with the array handling from the trait. The street name
 * conversion does not work if any of the involved fields is set to an array
 * because
 * - splitting the street address is done before validation of the individual
 *   fields (for good reason: this makes validation of fields more predictable
 *   and therefore easier to override with custom logic)
 * - but that means that the 'splitting' method should also be able to work
 *   with array values. That's not implemented here/not overridden yet.
 */
class ArraysAddress extends KnBasicAddress
{
    use ArrayFieldValuesTrait;
}
