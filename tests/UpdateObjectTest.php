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
                $filename = __DIR__ . "/update_examples/$dir_entry";
                /** @var \PracticalAfas\UpdateConnector\UpdateObject $object */
                list($object, $change_behavior, $expected_json, $expected_xml) = $this->readUpdateExample($filename);

                $clone = clone $object;
                $test = $object->output('json', ['pretty' => true], $change_behavior);
                $this->assertSame($expected_json, $test, "JSON output does not match the contents of $filename.");
                $test = $object->output('xml', ['pretty' => true], $change_behavior);
                $this->assertSame($expected_xml, $test, "XML output does not match the contents of $filename.");

                // Test that the object is still the same after validation /
                // output, so validation did not change any properties of the
                // object itself. (It should e.g. add default values only to
                // the output.)
                $this->assertEquals($clone, $object);

                // The following won't work for our 'upsert' example which has
                // set custom actions in the embedded objects, since these
                // embedded objects are recreated while setElements()'ing them.
                if (strpos($filename, 'upsert.txt') === false) {
                    $elements = $object->getElements();
                    // Check if the object is the same after we setElements()
                    // the elements. If this is not the case, the likely
                    // suspect is a validation function changing things even
                    // though change behavior ALLOW_NO_CHANGES was specified.
                    $object->setElements($elements);
                    $this->assertEquals($clone, $object);

                    if (!empty($elements[0]['Objects'])) {
                        // If we re-set the elements individual embedded
                        // objects, that should of course behave the same way.
                        // Doublecheck this, mainly to see if setObject() has
                        // any strange behavior.
                        foreach ($elements[0]['Objects'] as $ref_field => $sub_elements) {
                            // This has the 'Element' wrapper still around it,
                            // but setObject() should handle that oversight.
                            $object->setObject($ref_field, $sub_elements);
                        }
                        $this->assertEquals($clone, $object);
                    }
                }
            }
        }
    }

    /**
     * Test empty (embedded) objects and unsetting fields/objects.
     *
     * What we want to do with emptiness, and why:
     * 1. We are able to create empty objects (and modify them later), e.g.
     *    create(TYPE, []);
     * 2. We prevent sending empty 'Element' structures into AFAS, so output()
     *    throws an exception when trying to output an object with 0 elements.
     *    However,
     *    a. getElements() just returns an empty array.
     *    b. output() ignores empty embedded objects.
     * 3. We can create empty embedded objects using addElement()/setObject().
     * 4. If there are multiple elements but at least one of them is nonempty,
     *    we ignore the empty elements.
     * 5. When adding an element which is an empty array, we don't throw an
     *    exception.
     * 6. We don't need to be strict with element indices; we can add elements
     *    with index 0 and 2 without adding 1.
     *
     * Points until 2a are self evident.
     * 3 is not completely evident, but is needed if we care about consistency
     * with 1.
     * 2b & 4 are a bit arbitrary; we could also choose to throw an exception
     * if an embedded object or any of the elements is empty, but I don't see
     * an advantage to doing that. 5 and 6 are just an implications of 4. (We
     * could throw exceptions in these cases but I don't see the point in it.)
     *
     * There is an implication: there can be UpdateObjects which produce the
     * same results through getElements() / output(), yet are not equal
     * (because they have a different internal structure; i.e. have empty
     * elements and/or embedded objects in different places). We'll live with
     * this / ignore this. In order to not have this, we would need to lose
     * 2b or 3, plus we'd need to throw exceptions when unsetField() /
     * unsetObject() calls remove the last field/object inside an embedded
     * object to make it impossible to create an empty embedded object. That
     * seems too much, to have something which is really not a clear advantage.
     * (We'd also need to lose 4, 5 and 6 - which I wouldn't be against.)
     */
    public function testEmptyObjects()
    {
        // Create empty object; check output.
        $object1 = UpdateObject::create('KnPerson');
        $this->assertEquals([], $object1->getElements());

        // See whether setting empty elements works OK.
        $object2 = UpdateObject::create('KnPerson', [ [], [] ], 'update');
        // This adds nothing.
        $object2->addElements([]);
        // This adds a single empty element.
        $object2->addElements([[]]);
        // Adding object with empty embedded object, or an embedded object with
        // one or more empty elements, should also be OK. Keys for elements
        // should not be used in output, only for manipulating the element
        // inside this object.
        $object2->setElement('randomkey', ['last_name' => 'Muit', 'contact' => [[]]]);
        // All this should only return 1 element with 1 field.
        $element = ['Fields' => ['LaNm' => 'Muit']];
        $this->assertEquals([$element], $object2->getElements());

        // Populate earlier empty object. See that the empty setObject() works
        // and that the objects are exactly equal.
        $object1->setField('last_name', 'Muit');
        $object1->setObject('contact', []);
        $object1->setAction('update');
        $this->assertEquals($object1->getElements(), $object2->getElements());

        // Remove the field. The resulting empty embedded object should yield
        // no elements. Note there should be 3 empty elements before.
        $object2->unsetField('last_name', 'randomkey');
        $this->assertEquals([], $object2->getElements());

        // setElements([]) should actually empty out the object (not only
        // the output).
        $object1->setElements([]);
        $object2 = UpdateObject::create('KnPerson', [], 'update');
        $this->assertEquals($object2, $object1);

        // Check that output() on en empty object throws an exception.
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage("'KnPerson' object holds no elements.");
        // We want an empty object without default values, so ALLOW_NO_CHANGES.
        // For KnPerson, if we don't set VALIDATE_NOTHING, we still get the
        // special MatchPer field.
        $object2->output('json', [], UpdateObject::ALLOW_NO_CHANGES, UpdateObject::VALIDATE_NOTHING);
    }

    /**
     * Test that getElements(NO_CHANGES, NOTHING) actually returns empty array.
     */
    public function testEmptyOutput()
    {
        // If it doesn't, something's wrong with validateElements() or related
        // methods populating values that they shouldn't. We should ideally
        // test all classes including overridden validation code.
        $properties = [
            'address' => [],
            'contact' => [
                'person' => [],
            ],
        ];
        $object = UpdateObject::create('KnOrganisation', $properties, 'update');
        $this->assertEquals([], $object->getElements(UpdateObject::ALLOW_NO_CHANGES, UpdateObject::VALIDATE_NOTHING));
        // Test insert as well as update; code should not insert defaults.
        $object->setAction('insert');
        $this->assertEquals([], $object->getElements(UpdateObject::ALLOW_NO_CHANGES, UpdateObject::VALIDATE_NOTHING));
    }

    /**
     * Test some things around setting / rendering of ID.
     */
    public function testId()
    {
        // Check setting object with only an ID. Should not yield errors.
        $element = ['@SbId' => 2];
        $object1 = UpdateObject::create('KnSubject', $element, 'update');
        $this->assertEquals([$element], $object1->getElements());
        // This is equivalent to only calling setId().
        $object2 = UpdateObject::create('KnSubject');
        $object2->setId(2);
        $object2->setAction('update');
        $this->assertEquals($object1, $object2);

        // Check that the output is what we expect, and also yields no errors.
        $this->assertEquals('{"KnSubject":{"Element":{"@SbId":2}}}', $object2->output());
        // Setting a string ID (even if numeric) should have different JSON
        // result.
        $object2->setId('2');
        $this->assertEquals('{"KnSubject":{"Element":{"@SbId":"2"}}}', $object2->output());

        // setId('') should empty out the ID.
        $object2->setId('');
        $this->assertEquals([], $object2->getElements());
    }

    /**
     * Test setting invalid object throws OutOfBoundsException.
     */
    public function testSetInvalidElementData()
    {
        $this->expectException(OutOfBoundsException::class);
        UpdateObject::create('KnPerson', ['unknown' => 'value']);
    }

    /**
     * Test setting invalid field throws OutOfBoundsException.
     */
    public function testSetInvalidField()
    {
        $object = UpdateObject::create('KnPerson');
        $this->expectException(OutOfBoundsException::class);
        // Note checking a non-scalar value would throw InvalidArgumentException
        // but the field check comes before the value check.
        /** @noinspection PhpParamsInspection */
        $object->setField('contact', []);
    }

    /**
     * Test setting invalid object throws OutOfBoundsException.
     */
    public function testSetInvalidObject()
    {
        $object = UpdateObject::create('KnPerson');
        $this->expectException(OutOfBoundsException::class);
        $object->setObject('last_name', ['Muit']);
    }

    /**
     * Cause an exception message that reports on a non-'first' element.
     *
     * This serves as at least a cursory a test that the $element_index
     * parameter is being passed through correctly.
     */
    public function testNonFirstErrorMessage1()
    {
        $properties = [
            'line_items' => [
                [],
                // This does not need a separate test for it, but: below is an
                // array so is interpreted as having field name '0'.
                ['quantity'],
            ]
        ];
        $this->expectException(OutOfBoundsException::class);
        $this->expectExceptionMessage("Unknown element properties provided for 'FbSalesLines' element which will get index 1: names are '0'.");
        UpdateObject::create('FbSales', $properties, 'update');
    }

    /**
     * Same as testNonFirstErrorMessage1 but an error in validateFieldValue().
     *
     * Also: test that decimal fields cannot contain strings.
     */
    public function testNonFirstErrorMessage2()
    {
        $properties = [
            [
                'line_items' => [
                    [],
                    [],
                    ['quantity' => '1'],
                ]
            ],
            [
                'line_items' => [
                    [],
                    [],
                    ['quantity' => 'x'],
                ]
            ],
        ];
        $this->expectException(InvalidArgumentException::class);
        // The message is confined within one object (i.e. 'line_items' and
        // does not indicate any parent element it would be embedded in.
        $this->expectExceptionMessage("'QuUn' (quantity) field value of 'FbSalesLines' element which has (or will get) index 2 must be numeric.");
        UpdateObject::create('FbSales', $properties, 'update');
    }

    /**
     * Test that non-integer fields throw an exception.
     */
    public function testNonIntegerException()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("'Unit' (unit) field value of 'FbSales' element must be an integer value.");
        UpdateObject::create('FbSales', ['unit' => 1.2], 'update');
    }

    /**
     * Test that we can't set multiple embedded object unless it's allowed.
     */
    public function testNoMultipleObject1()
    {
        // Even if objects we set are empty, we should not be allowed to set
        // multiple.
        $properties = [
            'address' => [[], []],
        ];
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("'KnBasicAddressAdr' (address) object embedded in 'KnOrganisation' element contains 2 elements but can only contain a single element.");
        UpdateObject::create('KnOrganisation', $properties, 'update');
    }

    /**
     * Test that 'multiple embedded objects' check is also done on output.
     */
    public function testNoMultipleObject2()
    {
        $properties = [
            'address' => [[], []],
        ];
        $object = UpdateObject::create('KnOrganisation', $properties, 'update', UpdateObject::VALIDATE_NOTHING);
        $object->getElements();
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage("'KnBasicAddressAdr' (address) object embedded in 'KnOrganisation' element contains 2 elements but can only contain a single element.");
        $object->output();
    }

    /**
     * Test that if we ALLOW_NO_CHANGES, validation throws 'required' error.
     */
    public function testRequiredWithoutChanges()
    {
        /** @var \PracticalAfas\UpdateConnector\UpdateObject $object */
        list($object) = $this->readUpdateExample(__DIR__ . '/update_examples/KnOrg-embedded-insert.txt');
        $this->expectException(UnexpectedValueException::class);
        // Org/Contact/Person objects contain no required values that are not
        // populated. Only the address field contains one.
        $this->expectExceptionMessage("No value provided for required 'PbAd' (is_po_box) field of 'KnBasicAddress' element.");
        $object->getElements(UpdateObject::ALLOW_NO_CHANGES, UpdateObject::DEFAULT_VALIDATION);
    }

    /**
     * Test that cloned objects are really fully cloned.
     */
    public function testClone()
    {
        /** @var \PracticalAfas\UpdateConnector\UpdateObject $object1 */
        list($object1) = $this->readUpdateExample(__DIR__ . '/update_examples/KnOrg-embedded-insert.txt');
        $object2 = clone $object1;
        $this->assertEquals($object1, $object2);
        $object2->getObject('KnContact')->getObject('KnPerson')->setField('last_name', 'Smith');
        $this->assertNotEquals($object1, $object2);
    }

    // The following methods test custom behavior in extending classes. Not
    // everything, though; much is already being tested implicitly by
    // interpreting the update_examples.

    /**
     * Tests that a person object inside a standalone contact is not allowed.
     */
    public function testContactNoEmbeddedPerson()
    {
        $this->expectException(OutOfBoundsException::class);
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
     * Reads an example file; returns separate parts of the contents.
     *
     * @param string $filename
     *   The absolute filename.
     *
     * @return array
     *   $object, $change_behavior, $expected_json, $expected_xml
     */
    private function readUpdateExample($filename)
    {
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
