<?php
/**
 * This file is part of the PracticalAfas package.
 *
 * (c) Roderik Muit <rm@wyz.biz>
 *
 * For the full copyright and license information, please view the LICENSE file
 * that was distributed with this source code.
 */

use PHPUnit\Framework\TestCase;
use PracticalAfas\UpdateConnector\UpdateObject;
use PracticalAfas\UpdateConnector\OrgPersonContact;

class UpdateObjectTest extends TestCase
{
    /**
     * Runs through example payloads; verifies UpdateObject output matches them.
     *
     * This implicitly tests a lot of things; among others, whether embedded
     * objects are properly formatted.
     */
    public function testOutput()
    {
        foreach (scandir(__DIR__ . '/update_examples') as $dir_entry) {
            if (substr($dir_entry, -4) === '.txt') {
                $this->processUpdateExample(__DIR__ . "/update_examples/$dir_entry");
            }
        }
    }

    /**
     * Test that if we ALLOW_NO_CHANGES, validation throws 'required' error.
     */
    public function testRequiredWithoutChanges() {
        list($object) = $this->readUpdateExample(__DIR__ . '/update_examples/KnOrg-embedded-insert.txt');
        $this->expectException(UnexpectedValueException::class);
        // Org/Contact/Person objects contain no required values that are not
        // populated. Only the address field contains one.
        $this->expectExceptionMessage("No value provided for required 'PbAd' (is_po_box) field of 'KnBasicAddress' element.");
        $object->getElements(UpdateObject::ALLOW_NO_CHANGES, UpdateObject::DEFAULT_VALIDATION);
    }

// The following methods test custom behavior in extending classes. Not
// everything, though; much is already being tested implicitly by interpreting
// the update_examples.

    /**
     * Tests that a person object inside a standalone contact is not allowed.
     */
    public function testContactNoEmbeddedPerson()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Unknown element properties provided for 'KnContact' element: names are 'person'.");
        $properties = [
            'email' => 'rm@wyz.biz',
            'phone' => '+31622517218',
            'person' => [
                'first_name' => 'Roderik',
                'last_name' => 'Muit',
            ],
        ];
        UpdateObject::create('KnContact', $properties, 'update');
    }

    /**
     * Tests requiredness of BccoXXX fields in a standalone KnContact object.
     *
     * This is also required for updates (unlike regular required fields).
     */
    public function testContactRequiredOrg()
    {
        $properties = [
            'email' => 'rm@wyz.biz',
            'phone' => '+31622517218',
        ];
        $object = UpdateObject::create('KnContact', $properties, 'update');

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage("No value provided for required 'BcCoOga' (organisation_code) field of 'KnContact' element.
No value provided for required 'BcCoPer' (person_code) field of 'KnContact' element.");
        $object->getElements(UpdateObject::DEFAULT_CHANGE, UpdateObject::DEFAULT_VALIDATION);
    }

    /**
     * Tests setting wrong phone number in object whose child has address.
     */
    public function testValidateDutchPhoneNr1()
    {
        // There's only one combination that works for this: KnPerson with a
        // business phone, and an embedded KnContact with an address.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Phone number 'TeNr' has invalid format.");
        $properties = [
            'first_name' => 'Roderik',
            'last_name' => 'Muit',
            'phone' => '+3162251721',
            'contact' => [
                'address' => [
                  'country_iso' => 'NL',
                ],
            ],
        ];
        UpdateObject::create('KnPerson', $properties, 'update', UpdateObject::DEFAULT_VALIDATION | OrgPersonContact::VALIDATE_FORMAT);
    }

    /**
     * Tests setting wrong phone number in object whose parent has address.
     */
    public function testValidateDutchPhoneNr2()
    {
        /** @var \PracticalAfas\UpdateConnector\UpdateObject $object */
        list($object) = $this->readUpdateExample(__DIR__ . '/update_examples/KnOrg-embedded-insert.txt');

        // This is an example of where complicated validation breaks down:
        // setField() does not throw an exception despite this being a wrong
        // format because it needs address information in the parent object in
        // order to do the validation. (We don't plan to fix this because it's
        // not an often used pattern, and we don't want to introduce references
        // to a parent object because that would introduce memory leaks.)
        $object->getObject('KnContact')->setField('phone', '+3162251721', 0, UpdateObject::DEFAULT_VALIDATION | OrgPersonContact::VALIDATE_FORMAT);

        // Validation of the whole object does throw an exception because the
        // validation code is in the parent (containing the address), checking
        // if there's a wrong phone number in an embedded object.
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage("Phone number 'TeNr' has invalid format.");
        $object->getElements(UpdateObject::DEFAULT_CHANGE, UpdateObject::DEFAULT_VALIDATION | OrgPersonContact::VALIDATE_FORMAT);
    }

    /**
     * Processes an example file; verifies UpdateObject output matches contents.
     *
     * This also contains some tests which we should really run on every
     * custom object to test whether their validation functions don't change
     * things in ways they shouldn't.
     *
     * @param string $filename
     *   The absolute filename.
     */
    private function processUpdateExample($filename) {
        /** @var \PracticalAfas\UpdateConnector\UpdateObject $object */
        list($object, $change_behavior, $expected_json, $expected_xml) = $this->readUpdateExample($filename);

        $clone = clone $object;
        $test = $object->output('json', ['pretty' => true], $change_behavior);
        $this->assertSame($expected_json, $test, "JSON output does not match the contents of $filename.");
        $test = $object->output('xml', ['pretty' => true], $change_behavior);
        $this->assertSame($expected_xml, $test, "XML output does not match the contents of $filename.");

        // Test that the object is still the same after validation/output, so
        // validation did not change any properties of the object itself. (It
        // should e.g. add default values only to the output.)
        $this->assertEquals($clone, $object);

        // The following won't work for our 'upsert' example which has set
        // custom actions in the embedded objects, since these embedded objects
        // are recreated while setElements()'ing them.
        if (strpos($filename, 'upsert.txt') === false) {
            $elements = $object->getElements();
            // Check if the object is the same after we setElements() the elements.
            // If this is not the case, the likely suspect is a validation function
            // changing things even though change behavior ALLOW_NO_CHANGES was
            // specified.
            $object->setElements($elements);
            $this->assertEquals($clone, $object);

            if (!empty($elements[0]['Objects'])) {
                // If we re-set the elements individual embedded objects, that
                // should of course behave the same way. Doublecheck this,
                // mainly to see if setObject() has any strange behavior.
                foreach ($elements[0]['Objects'] as $ref_field => $sub_elements) {
                    // This has the 'Element' wrapper still around it, but
                    // setObject() should handle that oversight.
                    $object->setObject($ref_field, $sub_elements);
                }
                $this->assertEquals($clone, $object);
            }
        }
    }

    /**
     * Reads an example file; returns separate parts of the contents.
     *
     * @param string $filename
     *   The absolute filename.
     *
     * @return array
     *   $object, $change_behavior, $expected_json, $expected_xml
     */
    private function readUpdateExample($filename) {
        // We've dumped classname, input and output together into one file for
        // easier comparison by humans (so they can see if the test examples
        // make sense). Pull these apart.
        $object_type = $action = $buffer = '';
        $input_array = $expected_json = $eval = null;
        $change_behavior = UpdateObject::DEFAULT_CHANGE;
        $fp = fopen($filename, 'r');
        while ($line = fgets($fp)) {
            if ($line === false || trim($line) === '--') {
                // Buffer is supposedly complete; move it into whatever is the
                // next empty 'target'. There's not a lot of checking here; we
                // assume the input files are safe and don't cause e.g. the
                // eval() to break.
                if (!isset($input_array)) {
                    eval('$input_array = ' . $buffer . ';');
                    if (!isset($input_array)) {
                        throw new \UnexpectedValueException("Input array definition in $filename is apparently invalid.");
                    }
                } elseif (!isset($expected_json)) {
                    $expected_json = str_replace('{TODAY}', '"' . date('Y-m-d') . '"', trim($buffer));
                } elseif (!isset($expected_xml)) {
                    $expected_xml = str_replace('{TODAY}', date('Y-m-d'), trim($buffer));
                } else {
                    throw new \UnexpectedValueException("$filename contains extra content after output XML.");
                }
                $buffer = '';
            } elseif ($line[0] === ';') {
                // Skip comments. (They can be placed anywhere.)
                continue;
            } elseif (strpos($line, 'eval: ') === 0) {
                // Remember one 'eval'.
                $eval = substr($line, 5);
            } elseif ($object_type === '') {
                // First line.
                $object_type = trim($line);
                // Split off action & change behavior.
                if (($pos = strpos($object_type, ':')) !== false) {
                    $action = substr($object_type, $pos + 1);
                    $object_type = substr($object_type, 0, $pos);
                }
                if (($pos = strpos($action, ':')) !== false) {
                    $change_behavior = substr($action, $pos + 1);
                    $action = substr($action, 0, $pos);
                    if (!is_numeric($change_behavior) || $change_behavior < 0 || $change_behavior > 65535) {
                        throw new \UnexpectedValueException("'change behavior' on first line of $filename is invalid.");
                    }
                    $change_behavior = (int) $change_behavior;
                }
            } else {
                $buffer .= $line;
            }
        }
        // We need to partially redo the above code because $buffer is often
        // still full. Output XML is usually filled here, if the file does not
        // end with '--'.
        if ($buffer) {
            if (!isset($expected_json)) {
                throw new \UnexpectedValueException("$filename does not seem to contain both JSON and XML output.");
            }
            if (!isset($expected_xml)) {
                $expected_xml = str_replace('{TODAY}', date('Y-m-d'), trim($buffer));
            } else {
                throw new \UnexpectedValueException("$filename contains extra content after output XML.");
            }
        }
        if (!isset($expected_xml)) {
            throw new \UnexpectedValueException("$filename does not seem to contain output XML.");
        }

        $object = UpdateObject::create($object_type, $input_array, $action);
        if ($eval) {
            // We again assume the input files are safe.
            eval($eval);
        }

        return [$object, $change_behavior, $expected_json, $expected_xml];
    }
}
