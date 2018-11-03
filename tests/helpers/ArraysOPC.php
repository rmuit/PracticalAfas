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

use PracticalAfas\UpdateConnector\OrgPersonContact;

/**
 * Example class which can handle arrays containing 'metadata' as field values.
 *
 * This class combines the property definitions and part of the logic from
 * the parent class, with the array handling from the trait. Most of the custom
 * logic in the parent actually doesn't work; we'd need to adjust code to be
 * able to recognize field value arrays as valid values, and work on the first
 * member of them. We have not done this because this is only a test setup
 * anyway. Details:
 * - 'non-standard defaults' & requiredness are filled by validateFields()
 * - validateFieldValue() only reformats the phone number if country is empty
 *   or 'NL' (not allowed to be an array); in this case the phone number must
 *   be a string, or validation will fail.  <--- this actually all isn't true? ON INput it doesn't.
 * -
 */
class ArraysOPC extends OrgPersonContact
{
    use ArrayFieldValuesTrait;
}