<?php

/**
 * This file is part of the PracticalAfas package.
 *
 * (c) Roderik Muit <rm@wyz.biz>
 *
 * For the full copyright and license information, please view the LICENSE file
 * that was distributed with this source code.
 */

namespace PracticalAfas\Tests;

use InvalidArgumentException;
use OutOfBoundsException;
use PHPUnit\Framework\TestCase;
use PracticalAfas\UpdateConnector\KnBasicAddress;
use PracticalAfas\UpdateConnector\UpdateObject;
use PracticalAfas\UpdateConnector\OrgPersonContact;
use PracticalAfas\Tests\Helpers\ArraysObject;
use UnexpectedValueException;

/**
 * Tests for UpdateObject and child classes.
 *
 * There's so much to test that this does not try to adhere to any structure
 * like tests for each method. Also a lot of things are tested implicitly in
 * tests for other things. I just stopped writing tests when I stopped thinking
 * of functionality that could break.
 */
class UpdateObjectTest extends TestCase
{
    /**
     * Runs through example payloads; verifies UpdateObject output matches them.
     *
     * Also tests whether we can set JSON output back into the object.
     *
     * This implicitly tests a lot more things; among others, whether embedded
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

                // Test that for the array structure in a file as input, the
                // output matches the JSON/XML contents in the same file.
                $output = $object->output('xml', ['pretty' => true], $change_behavior);
                $this->assertSame($expected_xml, $output, "XML output does not match the contents of $filename.");
                $output = $object->output('json', ['pretty' => true], $change_behavior);
                $this->assertSame($expected_json, $output, "JSON output does not match the contents of $filename.");

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

                    // Check if we can set the full JSON output (if converted
                    // back to the array). Difference with above: this has
                    // 'Element' wrapper(s) and an outer wrapper containing the
                    // object type; setElements() should be able to deal with
                    // that format too.
                    /** @noinspection PhpComposerExtensionStubsInspection */
                    $test = json_decode($output, true);
                    $object->setElements($test);
                    // Now we can't compare to $clone because we will have
                    // explicitly set all the defaults as object values. But we
                    // hope the output of both is still equal. (Except for the
                    // order of fields, which can e.g. be added on later by
                    // child classes in one case and not the other.)
                    $output = $object->output('json', ['pretty' => true], $change_behavior);
                    /** @noinspection PhpComposerExtensionStubsInspection */
                    $test2 = json_decode($output, true);
                    self::sortRecursiveKeys($test);
                    self::sortRecursiveKeys($test2);
                    $this->assertEquals($test2, $test);
                }
            }
        }
    }

    /**
     * Sort an array's keys recursively.
     *
     * @param array $array
     *   The array to sort
     */
    private static function sortRecursiveKeys(array &$array)
    {
        foreach ($array as &$value) {
            if (is_array($value)) {
                self::sortRecursiveKeys($value);
            }
        }
        ksort($array);
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
     * an advantage to doing that. 5 and 6 are just implications of 4. (We
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
        $object2 = UpdateObject::create('KnPerson', [[], []], 'update');
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
        $object1->setField('last_name', 'Muit', 'splut');
        $object1->setObject('contact', [], null, 'splut');
        $object1->setAction('update');
        // Even if we used 'randomkey' as index instead of 'splut' (or
        // specified no index), we still can't compare the objects themselves;
        // $object2 has extra empty elements stored internally. So compare
        // elements only.
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
        $this->expectExceptionMessage("Object holds no elements.");
        // We want an empty object without default values, so ALLOW_NO_CHANGES.
        // For KnPerson, if we don't set VALIDATE_NOTHING, we still get the
        // special MatchPer field.
        $object2->output('json', [], UpdateObject::ALLOW_NO_CHANGES, UpdateObject::VALIDATE_NOTHING);
    }

    /**
     * Tests that numeric element indexes are preserved if possible.
     *
     * This also tests point 6 mentioned in testEmptyObjects() docs.
     */
    public function testNumericElementIndexes()
    {
        $properties = [
            'line_items' => [
                3 => ['quantity' => 3],
                4 => ['quantity' => 4],
            ]
        ];
        $object = UpdateObject::create('FbSales', $properties, 'update');
        $more_elements = [
            4 => ['quantity' => 40],
            5 => ['quantity' => 50],
        ];
        // Element 4 will be renumbered to 5 and then 5 to 6.
        $object->getObject('line_items')->addElements($more_elements);
        $elements = $object->getObject('line_items')->getElements(UpdateObject::DEFAULT_CHANGE & ~UpdateObject::RENUMBER_ELEMENT_INDEXES);
        $this->assertEquals(4, $elements[4]['Fields']['QuUn']);
        $this->assertEquals(40, $elements[5]['Fields']['QuUn']);
        $this->assertEquals(50, $elements[6]['Fields']['QuUn']);
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
     * Test UpdateObject::validateFields' requiredness checks.
     *
     * This deals primarily with null (passed or default) values because those
     * are the only ones not tested elsewhere already. It only checks values
     * in the element, not output - because null outputs are tested through a
     * test file in update_examples already.
     */
    public function testValidateFieldsWithNullValuesAndDefaults()
    {
        // Comment from validateFields() copied, interspersed with >>:
        // - if no field value is set and a default is available, the default
        //   is returned.
        // - if required = true, then
        //   - if no field value is set and no default is available, an
        //     exception is thrown.
        //   - if the field value is null, an exception is thrown, unless the
        //     default value is also null. (We don't silently overwrite null
        //     values which were explicitly set, with a different default.)
        // - if the default is null (or the field value is null and the field
        //   is not required) then null is returned.
        // Test the last two points:
        UpdateObject::overridePropertyDefinitions('ttTest', ['fields' => [
            'required' => [
                'required' => true,
            ],
            'default' => [
                'default' => 1,
            ],
            'requiredDefault' => [
                'required' => true,
                'default' => 1,
            ],
            'defaultNull' => [
                'default' => null,
            ],
            'requiredDefaultNull' => [
                'required' => true,
                'default' => null,
            ],
        ]]);
        $input = [
            [
                'required' => 'this-needs-a-value',
                'default' => null,
                'defaultNull' => null,
                'requiredDefaultNull' => null,
            ],
            [
                'required' => 'this-needs-a-value',
            ],
        ];
        $expected = [
            ['Fields' => [
                'required' => 'this-needs-a-value',
                'default' => null,
                'requiredDefault' => 1,
                'defaultNull' => null,
                'requiredDefaultNull' => null,
            ]],
            ['Fields' => [
                'required' => 'this-needs-a-value',
                'default' => 1,
                'requiredDefault' => 1,
                'defaultNull' => null,
                'requiredDefaultNull' => null,
            ]],
        ];

        // Intermezzo 1: also test ALLOW_DEFAULTS_ON_UPDATE.
        $object = UpdateObject::create('ttTest', $input, 'update');
        $this->assertEquals($expected, $object->getElements(UpdateObject::DEFAULT_CHANGE | UpdateObject::ALLOW_DEFAULTS_ON_UPDATE, UpdateObject::DEFAULT_VALIDATION));
        $object->setAction('insert');

        // Test that if we pass null and/or default is null, things are fine -
        // except if we pass null into requiredDefault; we check that later.
        $this->assertEquals($expected, $object->getElements(UpdateObject::DEFAULT_CHANGE, UpdateObject::DEFAULT_VALIDATION));

        // Intermezzo 2: also test unsetField()...
        $object->unsetField('requiredDefaultNull');
        $this->assertEquals($expected, $object->getElements(UpdateObject::DEFAULT_CHANGE, UpdateObject::DEFAULT_VALIDATION));
        // ...and test if setField(,null) makes a null value be actually set in
        // the object, which is different from 'not specifying a field'.
        // (Since we re-set requiredDefaultNull back to its original input
        // value, getElements() should return a value equivalent to $input,
        // without the defaults.)
        $object->setField('requiredDefaultNull', null);
        $e2 = [
            ['Fields' => $input[0]],
            ['Fields' => $input[1]],
        ];
        $this->assertEquals($e2, $object->getElements());


        // Repeating the above: errors are thrown if:
        // - nothing is passed for a required field without default;
        // - null is passed for a required field without default;
        // - null is passed for a required field with a non-null default.
        $input = [
            [
            ],
            [
                'required' => null,
                'requiredDefault' => null,
                'requiredDefaultNull' => null,
            ],
        ];
        $object->setElements($input);
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage("element-key 0: No value provided for required 'required' field.
element-key 1: No value provided for required 'required' field.
element-key 1: No value provided for required 'requiredDefault' field.");
        $object->getElements(UpdateObject::DEFAULT_CHANGE, UpdateObject::DEFAULT_VALIDATION);
    }

    /**
     * Test UpdateObject::validateReferenceFields' requiredness checks.
     */
    public function testValidateReferenceFieldsWithEmptyValuesAndDefaults()
    {
        // Comment from validateFields() copied and modified for the fact that
        // we have no default values of null (and all reference field values
        // are objects):
        // - if no ref-field value is set and a default is available, the
        //   default is returned.
        // - if required = true, then
        //   - if no ref-field value is set and no default is available, an
        //     exception is thrown.
        // (Further points do not apply here.)
        UpdateObject::overridePropertyDefinitions('ttTestEmbedded', ['fields' => [
            'field' => [
                'alias' => 'fieldalias',
                'default' => 2,
            ],
        ]]);
        UpdateObject::overridePropertyDefinitions('ttTest', [
            // 'fields' is required for a type definition, so we need to
            // 'override' it here to prevent errors for this type that has no
            // standard property definitions.
            'fields' => [],
            'objects' => [
                'required' => [
                    'type' => 'ttTestEmbedded',
                    'required' => true,
                ],
                'default' => [
                    'type' => 'ttTestEmbedded',
                    // Watch out here: just like with create() et al, [] is
                    // zero elements. [[]] is one empty element.
                    'default' => [[]],
                ],
                'requiredDefault' => [
                    'type' => 'ttTestEmbedded',
                    'required' => true,
                    'default' => ['fieldalias' => 3],
                ],
            ]]);
        $input = [
            'required' => ['field' => 'this-needs-a-value'],
        ];
        $expected = [
            ['Objects' => [
                'required' => ['Element' => [
                    'Fields' => [
                        'field' => 'this-needs-a-value',
                    ]]],
                // Default of [] is set as an embedded object, which will have
                // default field value included during validation.
                'default' => ['Element' => [
                    'Fields' => [
                        'field' => 2,
                    ]]],
                // Default of ['field' => 3] is set as an embedded object.
                'requiredDefault' => ['Element' => [
                    'Fields' => [
                        'field' => 3,
                    ]]],
            ]],
        ];

        $object = UpdateObject::create('ttTest', $input, 'insert');
        $this->assertEquals($expected, $object->getElements(UpdateObject::DEFAULT_CHANGE, UpdateObject::DEFAULT_VALIDATION));

        // Unlike testValidateFieldsWithNullValuesAndDefaults() it's no use
        // trying to set nulls for object values; input validation will
        // prevent setting non-arrays regardless of validation behavior.
        $object = UpdateObject::create('ttTest', [[]], 'insert');
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage("No value provided for required embedded object 'required'.");
        $object->getElements(UpdateObject::DEFAULT_CHANGE, UpdateObject::DEFAULT_VALIDATION);
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

        // An update action should not work without an ID.
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage("'@SbId' property must have a value, or Action 'update' must be set to 'insert'.");
        $object2->getElements(UpdateObject::DEFAULT_CHANGE, UpdateObject::DEFAULT_VALIDATION);
    }


    /**
     * Test some things around setting 'afas assigned id' fields.
     */
    public function testAfasAssignedIdField()
    {
        // Check setting object with only an ID. Should not yield errors.
        $element = [[]];
        // An update action should not work without an ID.
        $object = UpdateObject::create('FbSalesLines', $element, 'update');
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage("'GuLi' (guid) field must have a value, or Action 'update' must be set to 'insert'.");
        $object->getElements(UpdateObject::DEFAULT_CHANGE, UpdateObject::DEFAULT_VALIDATION);
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
     * Test that multiple errors on input are all logged.
     */
    public function testMultipleMessages()
    {
        $properties = [
            [
                // 3 errors in the same object, of which 1 in an embedded one.
                'unit' => 2.2,
                'backorder' => '',
                'line_items' => [
                    [],
                    // Below is an array so is interpreted as having field '0'.
                    ['quantity'],
                ],
            ],
            [
                // This one should be fine.
                'backorder' => 'False',
                'line_items' => [
                    [],
                    [],
                    ['quantity' => '3'],
                ]
            ],
            [
                // This again contains 4 errors, 3 of which embedded.
                'backorder' => 2,
                'line_items' => [
                    [],
                    [],
                    [
                        // Both are the same field. Despite having the same
                        // value, that's seen as an (additional) error.
                        'quantity' => 'three',
                        'QuUn' => 'three',
                    ],
                ]
            ],
        ];
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("element-key 0: object-ref FbSalesLines: element-key 1: Unknown element properties provided: names are '0'.
element-key 0: 'BkOr' (backorder) field value is not a valid boolean value.
element-key 0: 'Unit' (unit) field value is not a valid integer value.
element-key 2: object-ref FbSalesLines: element-key 2: Field value is provided by both its field name QuUn and alias quantity.
element-key 2: object-ref FbSalesLines: element-key 2: 'QuUn' (quantity) field value is not numeric.
element-key 2: object-ref FbSalesLines: element-key 2: 'QuUn' (quantity) field value is not numeric.
element-key 2: 'BkOr' (backorder) field value is not a valid boolean value.");
        UpdateObject::create('FbSales', $properties, 'update');
    }

    /**
     * Tests that outputting an embedded object generates error.
     */
    public function testOutputEmbedded()
    {
        /** @var \PracticalAfas\UpdateConnector\UpdateObject $object */
        list($object) = $this->readUpdateExample(__DIR__ . '/update_examples/KnOrg-embedded-insert.txt');
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage("This object is embedded inside another object, so it cannot be converted to a standalone output string. See getObject(,,,true).");
        $object->getObject('KnContact')->output();
    }

    /**
     * Tests testResetPropertyDefinitions() by way of getObject(,,, true).
     */
    public function testResetPropertyDefinitions()
    {
        /** @var \PracticalAfas\UpdateConnector\UpdateObject $object */
        list($object) = $this->readUpdateExample(__DIR__ . '/update_examples/KnOrg-embedded-insert.txt');
        $contact1 = $object->getObject('KnContact');
        $contact2 = $object->getObject('KnContact', 0, false, true);

        $contact1->getElements(UpdateObject::DEFAULT_CHANGE, UpdateObject::DEFAULT_VALIDATION);
        // The not-embedded-in-organisation-anymore object now has several
        // errors: it doesn't have a required BcCoOga field, and also it cannot
        // embed a person object (which it now contains illegally).
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage("No value provided for required 'BcCoOga' (organisation_code) field.
No value provided for required 'BcCoPer' (person_code) field.
Unknown object(s) encountered: KnPerson.");
        $contact2->getElements(UpdateObject::DEFAULT_CHANGE, UpdateObject::DEFAULT_VALIDATION);
    }

    /**
     * Tests that overriding definition generally works.
     */
    public function testDefinitionOverrides()
    {
        $properties = [
            'order_date' => '2018-12-10',
            'order_number' => '1',
            'debtor_id' => 1,
            'currency_code' => 'EUR',
        ];
        $object1 = UpdateObject::create('FbSales', $properties, 'insert');

        // Override field definitions: we must use complete definitions, keyed
        // by 'fields'.
        $definitions = [
            'fields' => [
                // Comment will be required.
                'Re' => [
                    'alias' => 'comment',
                    'required' => true,
                ],
                'U1234567890' => [
                    'alias' => 'custom_field_1',
                ],
            ],
        ];
        UpdateObject::overridePropertyDefinitions('FbSales', $definitions);

        // The definition for custom_field_1 would have made above create()
        // error out (which we are assuming without testing it explicitly) but
        // is fine here.
        $properties += [
            'custom_field_1' => '2',
        ];
        $object2 = UpdateObject::create('FbSales', $properties, 'insert');

        // The order_date field is now required in $object2, but still not
        // required in $object1.
        $object1->output();
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage("No value provided for required 'Re' (comment) field.");
        $object2->output();
    }

    /**
     * Tests that overriding single properties works. (More below, though.)
     *
     * Also tests that objects don't change after reset... until
     * resetPropertyDefinitions() is called.
     */
    public function testPropertyOverridesAndReset()
    {
        // Reset the overrides from the test above.
        UpdateObject::overridePropertyDefinitions('FbSales', []);

        $properties = [
            'order_date' => '2018-12-10',
            'order_number' => '1',
            'debtor_id' => 1,
            'currency_code' => 'EUR',
        ];
        $object1 = UpdateObject::create('FbSales', $properties, 'insert');
        UpdateObject::overrideFieldProperty('FbSales', 'BkOr', 'default', false);
        $object2 = UpdateObject::create('FbSales', $properties, 'insert');

        // The validated objects should differ. Two ways of testing:
        $elements1 = $object1->getElements(UpdateObject::DEFAULT_CHANGE, UpdateObject::DEFAULT_VALIDATION);
        $elements2 = $object2->getElements(UpdateObject::DEFAULT_CHANGE, UpdateObject::DEFAULT_VALIDATION);
        $this->assertNotEquals($elements1, $elements2);
        $this->assertEquals(false, $elements2[0]['Fields']['BkOr']);

        // Check that resetPropertyDefinitions()  makes things equal.
        $object1->resetPropertyDefinitions();
        $elements1 = $object1->getElements(UpdateObject::DEFAULT_CHANGE, UpdateObject::DEFAULT_VALIDATION);
        $this->assertEquals($elements1, $elements2);
    }

    /**
     * Tests that overriding single properties (default, alias) works.
     *
     * Also tests that the 'street splitting' only works for certain countries.
     */
    public function testPropertyOverridesAndStreetLogic()
    {
        // In other places, we use readUpdateExample() to get some sample data.
        // But here, we'd like to have the input array instead of the object.
        // We'll just copy the definitions here.
        $properties = [
            'name' => 'Wyz',
            'address' => [
                'street' => 'Govert Flinckstraat 168A',
                'postal_code' => '1072EP',
                'town' => 'Amsterdam',
            ],
        ];
        $expected = [[
            'Fields' => [
                'Nm' => 'Wyz',
                'MatchOga' => 6,
                'PbAd' => true,
                'AutoNum' => true,
            ],
            'Objects' => [
                'KnBasicAddressAdr' => [
                    'Element' => [
                        'Fields' => [
                            'Ad' => 'Govert Flinckstraat',
                            'ZpCd' => '1072EP',
                            'Rs' => 'Amsterdam',
                            'CoId' => 'NL',
                            'HmNr' => 168,
                            'HmAd' => 'A',
                            'PbAd' => false,
                            'ResZip' => false,
                        ]
                    ]
                ],
            ]
        ]];
        // We know the above with country 'NL' and alias 'zip_code' will be
        // good because we tested that in KnOrg-embedded-update.txt. We'll test
        // whether the same works when overriding an alias and having 'NL' only
        // as default.
        UpdateObject::overrideFieldProperty('KnBasicAddress', 'CoId', 'default', 'NL');
        UpdateObject::overrideFieldProperty('KnBasicAddress', 'ZpCd', 'alias', 'postal_code');
        $actual = UpdateObject::create('KnOrganisation', $properties, 'insert')
            ->getElements(UpdateObject::DEFAULT_CHANGE | UpdateObject::ALLOW_CHANGES, UpdateObject::DEFAULT_VALIDATION);
        $this->assertEquals($expected, $actual);

        // If we however remove the default, the street does not get split
        // anymore.
        UpdateObject::unOverrideFieldProperty('KnBasicAddress', 'CoId', 'default');
        $actual = UpdateObject::create('KnOrganisation', $properties, 'insert')
            ->getElements(UpdateObject::DEFAULT_CHANGE | UpdateObject::ALLOW_CHANGES, UpdateObject::DEFAULT_VALIDATION);
        $this->assertNotEquals(
            $expected[0]['Objects']['KnBasicAddressAdr']['Element']['Fields']['Ad'],
            $actual[0]['Objects']['KnBasicAddressAdr']['Element']['Fields']['Ad']
        );


        // Also if we re-add the default but remove 'NL' from the list of
        // countries, the street does not get split anymore.
        UpdateObject::overrideFieldProperty('KnBasicAddress', 'CoId', 'default', 'NL');
        KnBasicAddress::setCountriesWithSeparateHouseNr(array_diff(
            KnBasicAddress::getCountriesWithSeparateHouseNr(),
            ['NL']
        ));
        $actual = UpdateObject::create('KnOrganisation', $properties, 'insert')
            ->getElements(UpdateObject::DEFAULT_CHANGE | UpdateObject::ALLOW_CHANGES, UpdateObject::DEFAULT_VALIDATION);
        $this->assertNotEquals(
            $expected[0]['Objects']['KnBasicAddressAdr']['Element']['Fields']['Ad'],
            $actual[0]['Objects']['KnBasicAddressAdr']['Element']['Fields']['Ad']
        );
        // ...though the country is still NL, unlike the previous code block.
        $this->assertEquals('NL', $actual[0]['Objects']['KnBasicAddressAdr']['Element']['Fields']['CoId']);

        // Reset settings for further tests.
        UpdateObject::unOverrideFieldProperty('KnBasicAddress');
    }

    /**
     * Tests that if one element fails, none of the elements get set.
     */
    public function testErrorInOneElementCancelsAll()
    {
        $properties = [
            [
                'backorder' => 'False',
                'line_items' => [
                    ['quantity' => '3'],
                ]
            ],
            [
                'backorder' => 'TRU',
            ],
        ];
        // Add the first (correct) element twice, proving we can do this.
        $object = UpdateObject::create('FbSales', $properties[0], 'update');
        $object->addElements([$properties[0]]);
        // Add both elements again; see that neither of them is added.
        try {
            $object->addElements($properties);
        } catch (InvalidArgumentException $exception) {
        }
        $this->assertEquals(2, count($object->getElements()));
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
        $this->expectExceptionMessage("Embedded object 'KnBasicAddressAdr' (address) contains 2 elements but can only contain a single element.");
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
        $this->expectExceptionMessage("Embedded object 'KnBasicAddressAdr' (address) contains 2 elements but can only contain a single element.");
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
        $this->expectExceptionMessage("No value provided for required 'PbAd' (is_po_box) field.");
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

    /**
     * Test a class which can contain 'metadata' for fields.
     *
     * This also implicitly tests that UpdateObject::overrideClass() works.
     *
     * This theoretical example is mostly meant to provide some extra testing
     * for validation; to see if nothing goes wrong if we pass array values
     * through all those functions, and only validateFieldValue() needs to be
     * overridden. There are more test for person/address objects below.
     */
    public function testCustomFieldValues()
    {
        $properties = [
            'type' => [1, 'meta'],
            'description' => ['This thing', 'moremeta'],
            // also do non-array value
        ];
        // This should store things as arrays...
        UpdateObject::overrideClass('KnSubject', '\PracticalAfas\Tests\Helpers\ArraysObject');
        $object = UpdateObject::create('KnSubject', $properties, 'insert');
        // ...and getFields() should still get those arrays returned, because
        // it does not do any kind of validation/change...
        $this->assertEquals([1, 'meta'], $object->getField('type'));
        $this->assertEquals(['This thing', 'moremeta'], $object->getField('description'));
        // ...which also goes for getElements() without arguments...
        $elements = $object->getElements(UpdateObject::ALLOW_NO_CHANGES);
        $expected = [[
            'Fields' => [
                'StId' => [1, 'meta'],
                'Ds' => ['This thing', 'moremeta'],
            ]]];
        $this->assertEquals($expected, $elements);
        // ...but getElements() should return only the actual field value,
        // if validated. (If it is given any non-default argument.)
        $elements = $object->getElements(UpdateObject::DEFAULT_CHANGE, UpdateObject::DEFAULT_VALIDATION);
        $expected = [[
            'Fields' => [
                'StId' => 1,
                'Ds' => 'moremeta:This thing',
            ]]];
        $this->assertEquals($expected, $elements);
    }

    // The following methods test custom behavior in extending classes. Not
    // everything, though; much is already being tested implicitly by
    // interpreting the update_examples.

    /**
     * Tests that a person object inside a standalone contact is not allowed.
     */
    public function testContactNoEmbeddedPerson()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Unknown element properties provided: names are 'person'.");
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
        $this->expectExceptionMessage("No value provided for required 'BcCoOga' (organisation_code) field.
No value provided for required 'BcCoPer' (person_code) field.");
        $object->getElements(UpdateObject::DEFAULT_CHANGE, UpdateObject::DEFAULT_VALIDATION);
    }

    /**
     * Tests setting wrong phone number in object whose child has address.
     */
    public function testValidateDutchPhoneNr1()
    {
        // This is testing the code in validateFieldValue(). There's only one
        // combination that works for this: KnPerson with a business phone, and
        // an embedded KnContact with an address.
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
     * Same as testValidateDutchPhoneNr1 but with 'array values'.
     */
    public function testValidateDutchPhoneNr1a()
    {
        UpdateObject::overrideClass('KnBasicAddress', '\PracticalAfas\Tests\Helpers\ArraysAddress');
        UpdateObject::overrideClass('KnContact', '\PracticalAfas\Tests\Helpers\ArraysOPC');
        UpdateObject::overrideClass('KnPerson', '\PracticalAfas\Tests\Helpers\ArraysOPC');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Phone number 'TeNr' has invalid format.");
        $properties = [
            'first_name' => ['Roderik', 'x'],
            'last_name' => ['Muit', 'm'],
            'phone' => ['+3162251721', 'a'],
            'contact' => [
                'address' => [
                    'country_iso' => ['NL', 2],
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
     * Same as testValidateDutchPhoneNr2 but with 'array values'.
     */
    public function testValidateDutchPhoneNr2a()
    {
        UpdateObject::overrideClass('KnBasicAddress', '\PracticalAfas\Tests\Helpers\ArraysAddress');
        UpdateObject::overrideClass('KnContact', '\PracticalAfas\Tests\Helpers\ArraysOPC');
        UpdateObject::overrideClass('KnOrganisation', '\PracticalAfas\Tests\Helpers\ArraysOPC');

        $properties = [
            'name' => ['Wyz', 7],
            'address' => [
                'street' => ['Govert Flinckstraat 168A', 7],
                'zip_code' => ['1072EP', 7],
                'town' => ['Amsterdam', 7],
                'country_iso' => ['NL', 7],
            ],
            'contact' => [
                'phone' => ['+31622517218', 7],
            ],
        ];
        $object = ArraysObject::create('KnOrganisation', $properties, 'insert', UpdateObject::DEFAULT_VALIDATION | OrgPersonContact::VALIDATE_FORMAT);

        $object->getObject('KnContact')->setField('phone', '+3162251721', 0, UpdateObject::DEFAULT_VALIDATION | OrgPersonContact::VALIDATE_FORMAT);

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
                    $change_behavior = (int)$change_behavior;
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
