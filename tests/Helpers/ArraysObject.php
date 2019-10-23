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
 * Example class which can handle arrays containing 'metadata' as field values.
 *
 * There are related classes that extend their own parent class (containing the
 * custom logic for their object types).
 */
class ArraysObject extends UpdateObject
{
    use ArrayFieldValuesTrait;
}
