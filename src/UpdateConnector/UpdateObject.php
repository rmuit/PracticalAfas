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

use InvalidArgumentException;
use OutOfBoundsException;
use UnexpectedValueException;

/**
 * Base class for generating output to send to AFAS Update Connectors.
 *
 * For any object type which has known property definitions,
 * - An UpdateObject can be create()'d from array of data having a format that
 *   is a little easier to grasp than the JSON structure (and a lot easier than
 *   XML);
 * - output() can immediately be called on the result to produce JSON/XML data
 *   suitable for sending through an Update Connector. No other calls are
 *   needed; validation will be done automatically.
 * - Custom validation can be done (at output or independently). Default values
 *   are populated and/or other non-invasive changes can be made to the output
 *   during validation, or this behavior can be suppressed, as needed.
 * - There are setters and getters for individual parts of the data (individual
 *   fields and embedded objects, additional full elements, action values) so
 *   an object can be constructed gradually / manipulated.
 *
 * Classes and field definitions can be overridden using static methods, to
 * support e.g. custom fields, new object types or modified behavior for
 * existing object types.
 *
 * See create() for more information; see the tests/update_examples directory
 * for some example array inputs.
 *
 * An UpdateObject can hold data for several elements to send through an
 * Update Connector at once; create() can be called with either the input data
 * structure for one element, or an array of those structures for more elements.
 *
 * About wording: the terms 'object' and 'element' are used in a way that may
 * not be apparent at first. The difference may be more apparent when looking
 * at the JSON representation of a message sent into an Update Connector:
 * - The whole JSON string represents an object; this object is also
 *   represented by one UpdateObject instance.
 * - The JSON string contains one "Element" value, which can actually contain
 *   one or more elements. (Maybe it should have been called "Elements", even
 *   though it most often will contain a single element.) One such element
 *   contains up to 3 key-value pairs: "Fields", "Objects" and an ID.
 * Key-value pairs inside the "Objects" value of an element each represent one
 * embedded object (again represented by an UpdateObject instance) which can
 * again (depending on the key/object type) contain one or multiple elements.
 *
 * About subclassing: see the comments at overrideClass(). Custom fields to
 * extend standard AFAS objects don't necessarily need subclassing; see
 * overridePropertyDefinitions().
 */
class UpdateObject
{
    /**
     * Bitmask value for output() $change_behavior argument.
     */
    const ALLOW_NO_CHANGES = 0;

    /**
     * Bitmask value for output() $change_behavior argument.
     */
    const ALLOW_EMBEDDED_CHANGES = 2;

    /**
     * Bitmask value for output() $change_behavior argument.
     */
    const ALLOW_DEFAULTS_ON_INSERT = 4;

    /**
     * Bitmask value for output() $change_behavior argument.
     */
    const ALLOW_DEFAULTS_ON_UPDATE = 8;

    /**
     * Bitmask value for output() $change_behavior argument.
     */
    const ALLOW_REFORMAT = 16;

    /**
     * Bitmask value for output() $change_behavior argument.
     */
    const ALLOW_CHANGES = 32;

    /**
     * Bitmask value for output() $change_behavior argument.
     */
    const FLATTEN_SINGLE_ELEMENT = 1;

    /**
     * Bitmask value for output() $change_behavior argument.
     */
    const RENUMBER_ELEMENT_INDEXES = 64;

    /**
     * Default behavior for output(,$change_behavior).
     *
     * This is ALLOW_EMBEDDED_CHANGES + ALLOW_DEFAULTS_ON_INSERT
     * + ALLOW_REFORMAT + FLATTEN_SINGLE_ELEMENT + RENUMBER_ELEMENT_INDEXES
     *
     * If future versions of this class introduce new behavior through
     * additional bitmask values, this value may or may not be changed to
     * incorporate that behavior by default.
     */
    const DEFAULT_CHANGE = 87;

    /**
     * Bitmask value for output() $validation_behavior argument.
     */
    const VALIDATE_NOTHING = 0;

    /**
     * Bitmask value for output() $validation_behavior argument.
     */
    const VALIDATE_ESSENTIAL = 1;

    /**
     * Bitmask value for output() $validation_behavior argument.
     */
    const VALIDATE_REQUIRED = 2;

    /**
     * Bitmask value for output() $validation_behavior argument.
     */
    const VALIDATE_NO_UNKNOWN = 4;

    /**
     * Bitmask value for output() $validation_behavior argument.
     */
    const VALIDATE_FORMAT = 8;

    /**
     * Default behavior for output(,,$validation_behavior).
     *
     * If future versions of this class introduce new behavior through
     * additional bitmask values, this value may or may not be changed to
     * incorporate that behavior by default.
     */
    const DEFAULT_VALIDATION = 7;

    /**
     * A mapping from object type to the class name implementing the type.
     *
     * Any object types not in here are implemented by this class, or
     * not implemented. Extra types can be set using static overrideClass().
     *
     * @var string[]
     */
    protected static $classMap = [
        'FbSales' => '\PracticalAfas\UpdateConnector\FbSales',
        'FbSalesLines' => '\PracticalAfas\UpdateConnector\FbSalesLines',
        'KnBasicAddress' => '\PracticalAfas\UpdateConnector\KnBasicAddress',
        'KnContact' => '\PracticalAfas\UpdateConnector\OrgPersonContact',
        'KnOrganisation' => '\PracticalAfas\UpdateConnector\OrgPersonContact',
        'KnPerson' => '\PracticalAfas\UpdateConnector\OrgPersonContact',
    ];

    /**
     * Properties to override per object type.
     *
     * Most methods are not supposed to reference this variable; (for speed
     * reasons) it is the responsibility of a class constructor to merge these
     * definitions into the propertyDefinitions variable, after which the
     * variable does not need to be touched anymore.
     *
     * It's supposed to be set through overridePropertyDefinitions().
     * Keys/values in this variable are that method's first/second arguments.
     *
     * @var array[]
     */
    protected static $definitionOverrides = [];


    /**
     * Field properties to override per object type + field.
     *
     * It's supposed to be set through overrideFieldProperty(). The caveats for
     * $definitionOverrides also apply here.
     *
     * @var array[]
     */
    protected static $fieldOverrides = [];

    /**
     * The type of object (and the name of the corresponding Update Connector).
     *
     * This is expected to be set on construction and to never change. Don't
     * reference it directly; use getType().
     *
     * @var string
     */
    protected $type = '';

    /**
     * The type of parent object which this data is going to be embedded into.
     *
     * This is not used by this class, but child classes may need it because it
     * can influence the available property definitions. It's set only in the
     * constructor. (It's a bit unfortunate that the parent type needs to be an
     * argument to create(), but populating element values would be way more
     * complicated for those child classes if it wasn't. Maybe it's possible to
     * make a setter for this, but that would need careful consideration.)
     *
     * @var string
     */
    protected $parentType = '';

    /**
     * Property definitions for data in this object.
     *
     * Code is explicitly allowed to reference this variable directly; there's
     * no getter. The constructor is responsible for populating it. (However,
     * it's recommended to define the value in getDefaultPropertyDefinitions()
     * rather than setting it directly in the constructor or the variable's
     * definition. The latter two will work fine for most use cases but
     * resetPropertyDefinitions() will likely fail.)
     *
     * Code may also modify this variable at runtime to influence the
     * validation process, but must then obviously be very careful to ensure
     * that all validation works uniformly. (This requires knowledge of exactly
     * when which types of validation is performed by which method: validation
     * happens during both validateElementInput() and validateElement() calls,
     * which each have a slightly different function and each call a number
     * of other methods. to do the work.) These modifications are not
     * guaranteed to persist after validation.
     *
     * The format is not related to AFAS but a structure specific to this class.
     * The array has the following keys:
     *   'id_property': If the object type has an ID property, it's name. (e.g.
     *                  for KnSubject this is 'SbId', because a subject always
     *                  has a "@SbId" property. ID properties are distinguished
     *                  by being outside of the 'Fields' section and being
     *                  prefixed by "@".)
     *   'fields':   Arrays describing properties of fields, keyed by AFAS
     *               field names. An array may be empty but must be defined for
     *               a field to be recognized. Properties known to this class:
     *   - 'alias':    A name for this field that is more readable than AFAS'
     *                 field name and that can be used in input data structures.
     *   - 'type':     Data type of the field, used for validation ond output
     *                 formatting. Values: boolean, date, int, decimal.
     *                 Optional; unspecified types are treated as strings.
     *   - 'default':  Default value for the field, if the field is not present.
     *                 (If set to null, this is not replaced by a default.)
     *   - 'required': If true, this field is required and our output()
     *                 method will throw an exception if the field is not
     *                 populated when action is "insert". If (int)1, this is
     *                 done even if output() is not instructed to validate
     *                 required values; this can be useful to set if it is
     *                 known that AFAS itself will throw an unclear error when
     *                 it receives no value for the field.
     *   - 'behavior': A string value that is meant to specify some specific
     *                 defined behavior. The following behavior types are
     *                 recognized (and other values are ignored):
     *     'afas_assigned_id': the field behaves just like the 'id_property'
     *                   in that it is assumed to be given a value by AFAS and
     *                   must be present when updating an element. (We do not
     *                   know why AFAS has such fields and doesn't just define
     *                   an ID property for the object type...)
     *   'objects':  Arrays describing properties of the 'object reference
     *               fields' defined for this object type, keyed by their names.
     *               An array may be empty but must be defined for an embedded
     *               object to be recognized. Properties known to this class:
     *   - 'type':     The name of the AFAS object type which this reference
     *                 points to. If not provided, the type is assumed to be
     *                 equal to the name of the reference field. See below.
     *   - 'alias':    A name for this field that can be used instead of the
     *                 AFAS name and that can be used in input data structures.
     *   - 'multiple': If true, the embedded object can hold more than one
     *                 element.
     *   - 'default':  Default value for the field, if the field is not present.
     *                 (If set to null, this is not replaced by a default.) The
     *                 value must be an array of element(s) values.
     *   - 'required': See 'fields' above.
     *
     * For examples, see setPropertyDefinitions().
     *
     * Child classes may define extra properties and handle those at will. They
     * should take into account that their properties could contain any value,
     * or alternatively implement strict checking in their constructor, because
     * values can be overridden using overridePropertyDefinitions(),
     *
     * About 'object reference fields': these are the names as found in e.g.
     * XSD schemas for a certain Update Connector. These are not necessarily
     * equal to the actual object type names, though they often are. As an
     * example: a KnPerson object has two address fields for home address and
     * postal address. Both addresses are objects of type KnBasicAddress,
     * however the "Objects" section of a KnPerson needs to reference them
     * separately and uses the names "KnBasicAddressAdr" & "KnBasicAddressPad"
     * for this. (AFAS documentation likely does not explain this, so) we call
     * the keys in the "Objects" part of an element 'object reference fields'.
     *
     * @var array[]
     */
    protected $propertyDefinitions = [];

    /**
     * The action(s) to perform on the data: "insert", "update" or "delete".
     *
     * @see UpdateObject::setAction()
     *
     * @var string[]
     */
    protected $actions = [];

    /**
     * Data representing one or several elements.
     *
     * Data of one element is typically structured just like a single "Element"
     * object inside a JSON representation for the REST API. This variable
     * should not be referenced directly, though.
     *
     * @see UpdateObject::getElements()
     *
     * @var array[]
     */
    protected $elements = [];

    /**
     * Sets a custom class to use for a specific object type.
     *
     * This is necessary if you want to use the static create() method for
     * creating customized objects. Using create() is recommended, though not
     * required.
     *
     * A project which wants to implement custom behavior for specific object
     * types, or define new object types, can do several things. As an example,
     * adding a custom field/behavior to KnContact objects can be done by:
     * - Creating a MyPerson class to extend OrgPersonContact (or to extend
     *   UpdateObject, but the current KnContact is implemented in
     *   OrgPersonContact); define the extra field/behavior in
     *   setPropertyDefinitions() etc and call 'new MyContact($values, $action)'
     *   to get an object representing this type.
     * - The same but implement multiple overridden objects in the same class
     *   named e.g. MyUpdateObject, and call
     *   'new MyUpdateObject($values, $action, "KnContact")' to get an object.
     * - The same but call
     *   UpdateObject::overrideClass('KnContact', '\...\MyPerson'); then call
     *   UpdateObject::create('KnContact, $values, $action) to get an object.
     *
     * The latter way enables creating custom embedded objects, e.g.
     * creating a KnPerson containing an embedded KnContact object with the
     * custom field/behavior. If overrideClass() is not called, the embedded
     * object will be created using the standard OrgPersonContact class.
     *
     * Note that for only adding/overriding field definitions (to e.g. support
     * custom fields), it is not necessary to implement a custom class. This
     * can be done by calling overridePropertyDefinitions() instead.
     *
     * @param string $object_type
     *   The object type. Please note that names of object types are not always
     *   (although often) equal to the names of 'object reference fields' in
     *   property definitions. See $propertyDefinitions for the difference.
     * @param string|null $class
     *   The class which should implement this object type, starting with '\'.
     *   Null to remove the override for this object type, falling back to the
     *   standard definition inside whichever class' create() method is called.
     *
     * @throws \InvalidArgumentException
     *   If any of the arguments are not a string or the class does not exist /
     *   cannot be autoloaded.
     *
     * @see UpdateObject::overridePropertyDefinitions()
     * @see UpdateObject::$classMap
     * @see UpdateObject::$propertyDefinitions
     */
    public static function overrideClass($object_type, $class)
    {
        if (!is_string($object_type)) {
            throw new InvalidArgumentException('$object_type argument is not a string.');
        }
        if (!is_string($class) && isset($class)) {
            throw new InvalidArgumentException('$class argument is not a string.');
        }
        if (!class_exists($class)) {
            throw new InvalidArgumentException("$class class does not exist.");
        }

        if (isset($class)) {
            self::$classMap[$object_type] = $class;
        } else {
            unset(self::$classMap[$object_type]);
        }
    }

    /**
     * Returns the almost-full mapping from object type to implementing class.
     *
     * Any object types not in here are supposedly implemented by UpdateObject.
     *
     * @return string[]
     */
    public static function getClassMap()
    {
        return self::$classMap;
    }

    /**
     * Overrides property definitions for a specific object type.
     *
     * The anticipated use of this is defining custom fields for existing
     * types. Definitions in objects which are already instantiated are not
     * affected, except when resetPropertyDefinitions() is called on those
     * objects afterwards. (Caveat: theoretical exceptions are child classes
     * that override the related logic in this class.)
     *
     * Overrides set through this method have no effect on overrides set
     * through overrideFieldProperty() (which take precedence) or on properties
     * set dynamically set by custom classes' own code (typically during
     * validation).
     *
     * @param string $object_type
     *   The object type. Please note that names of object types are not always
     *   (although often) equal to the names of 'object reference fields' in
     *   property definitions. See $propertyDefinitions for the difference.
     * @param array $definitions
     *   The definitions that will override any standard definitions set by
     *   the constructor. If any earlier overrides were already set for this
     *   type, they will be overwritten. (This means it is not allowed to set
     *   overrides for one type from multiple sources, because it's not known
     *   if those overrides will conflict. It also means that all necessary
     *   overrides for a certain types must be set in one call.) The structure
     *   is the same as property definitions: field definitions must be keyed
     *   by 'fields' and then the AFAS field name; object reference field
     *   definitions must be keyed by 'objects' and then the reference field
     *   name. The individual (reference) field definitions must be arrays or
     *   null. Arrays must be complete definitions: if a field definition
     *   already exists, it will be completely overwritten by this override
     *   definition, not merged. A null value will remove the original field
     *   definition. Other custom values on the first level of this array (i.e.
     *   on the same level as 'fields' and 'objects') are seen as individual
     *   property definitions that will also completely replace a property if
     *   that exists already; these property definitions don't have to be
     *   arrays. A null value will also remove any original property definition
     *   (rather than set it to null).
     *
     * @throws \InvalidArgumentException
     *   If any of the arguments are invalid.
     *
     * @see UpdateObject::$propertyDefinitions
     * @see UpdateObject::overrideFieldProperty()
     */
    public static function overridePropertyDefinitions($object_type, array $definitions)
    {
        if (!is_string($object_type)) {
            throw new InvalidArgumentException('$object_type argument is not a string.');
        }
        if ($definitions) {
            // Validate definitions. (We will also do this in the constructor
            // but it seems best to throw exceptions as early as possible.)
            static::validateDefinitionOverrides($definitions);
            self::$definitionOverrides[$object_type] = $definitions;
        } else {
            unset(self::$definitionOverrides[$object_type]);
        }
    }

    /**
     * Returns the property definition overrides per object type.
     *
     * @return array[]
     */
    public static function getPropertyDefinitionOverrides()
    {
        return self::$definitionOverrides;
    }

    /**
     * Overrides a property definition for a specific field in an object type.
     *
     * The anticipated use of this is overriding properties like 'alias' or
     * 'required' for individual fields without caring about other properties.
     * This also overrides any definitions set through
     * overridePropertyDefinitions(). The difference with that function is,
     * this only changes a single field property while leaving the other
     * properties intact.
     *
     * See the caveats outlined in overridePropertyDefinitions() which also
     * apply here.
     *
     * @param string $object_type
     *   The object type. Please note that names of object types are not always
     *   (although often) equal to the names of 'object reference fields' in
     *   property definitions. See $propertyDefinitions for the difference.
     * @param string $field_name
     *   The field name or alias within the object type. Note: no alias allowed.
     * @param string $property
     *   The property name within the field.
     * @param mixed $value
     *   The value to set.
     *
     * @see UpdateObject::overridePropertyDefinitions()
     * @see UpdateObject::unOverrideFieldProperty()
     */
    public static function overrideFieldProperty($object_type, $field_name, $property, $value)
    {
        self::$fieldOverrides[$object_type][$field_name][$property] = $value;
    }

    /**
     * Removes property override(s) for an object type and/or field/property.
     *
     * This does not affect definitions set through
     * overridePropertyDefinitions().
     *
     * @param string $object_type
     *   The object type. Please note that names of object types are not always
     *   (although often) equal to the names of 'object reference fields' in
     *   property definitions. See $propertyDefinitions for the difference.
     * @param string $field_name
     *   (Optional) The field name within the object type. Leave empty to
     *   remove all overrides for this object type. Note: no alias allowed.
     * @param string $property
     *   (Optional) The property name within the field. Leave empty to remove
     *   all overrides for this object type + field.
     *
     * @see UpdateObject::overrideFieldProperty()
     */
    public static function unOverrideFieldProperty($object_type, $field_name = '', $property = '')
    {
        if (empty($field_name)) {
            if (!empty($property)) {
                throw new InvalidArgumentException("Field name may not be empty if property name is not empty.");
            }
            unset(self::$fieldOverrides[$object_type]);
        } elseif (empty($property)) {
            unset(self::$fieldOverrides[$object_type][$field_name]);
        } else {
            unset(self::$fieldOverrides[$object_type][$field_name][$property]);
        }
    }

    /**
     * Returns the field property overrides per object type + field + property.
     *
     * @return array[]
     */
    public static function getFieldPropertyOverrides()
    {
        return self::$fieldOverrides;
    }

    /**
     * Instantiates a new UpdateObject, or a class defined in our map.
     *
     * One thing to remember for the $action argument: when wanting to use this
     * object's output for inserting new data into AFAS, it should have value
     * "insert". This will also take care of setting default values. In other
     * cases, preferably pass "update" even though that's very often equivalent
     * to passing nothing. (Yes this is a messy argument; @see setAction() if
     * you really want to know reasons.)
     *
     * Valid names and aliases for fields (and 'object reference fields') for
     * each object type are set in the protected $propertyDefinitions variable
     * of the appropriate class. In this base class, these are set in
     * setPropertyDefinitions() so can be found there. Other classes may do the
     * same, define them in __construct() or in $propertyDefinitions directly.
     * Definitions can however also be overridden and/or extra ones can be
     * added, in child classes or through static overridePropertyDefinitions().
     *
     * @param string $type
     *   The type of object, i.e. the 'Update Connector' name to send this data
     *   into. Possible values can be found in the property definitions (i.e.
     *   in setPropertyDefinitions() of this base class) or in the $classMap
     *   variable. Extra ones can however also be added through static
     *   overrideClass().
     * @param array $elements
     *   (Optional) Data to set in the UpdateObject, representing one or more
     *   elements of this type; see the definitions in __construct() or child
     *   classes for possible values. The data is assumed to represent multiple
     *   elements if all keys are numeric and all values are arrays (or if the
     *   value is a single empty array, meaning zero elements); it's processed
     *   as a single element otherwise. Keys inside a single element can be:
     *   - field names or aliases;
     *   - names or aliases of the 'object reference fields' (see comments at
     *     $propertyDefinitions)  which can be embedded into this object type;
     *     the values must be an array of data (one or multiple elements) to
     *     set in that object;
     *   - '@xxId' (where xx is a type-specific two letter code) or '@id', which
     *     holds the 'ID value' for an element. (In the output, this ID value is
     *     located in the Element structure the same level as the Fields, rather
     *     than inside Fields. Or in XML: it's an attribute in the Element tag.)
     *   The fields and objects can either all be present in the first dimension
     *   of the array, or be present in a 'Fields' and/or 'Objects' array. In
     *   the latter case, the first dimension of the array must not contain any
     *   keys other than 'Fields', 'Objects' and the ID property. In addition,
     *   this latter standalone structure can also be wrapped in a one-element
     *   array with key 'Element' - and also, this again wrapped into a
     *   one-element array being the object type. (Reason: this is equal to the
     *   structure of the REST API's JSON messages, so we also accept that.)
     * @param string $action
     *   (Optional) The action to perform on the data: "insert", "update" or
     *   "delete". @see setAction() or the comments above.
     * @param int $validation_behavior
     *   (Optional) Specifies whether/how the elements should be validated now,
     *   throwing an exception on failure. By default only very basic
     *   validation on individual fields (e.g. for correct data types) is done
     *   now, and full validation happens during output(). This value is a
     *   bitmask; the relevant bits for validating a single field are
     *   VALIDATE_ESSENTIAL and VALIDATE_FORMAT; most other bits have to do
     *   with validation of the object as a whole and are always ignored here.
     *   To have the full object evaluated after creating it, call
     *   getElements(DEFAULT_CHANGE, DEFAULT_VALIDATION). See output() for more.
     * @param string $parent_type
     *   (Optional) If nonempty, the return value will be suitable for
     *   embedding inside the parent type, which can have a slightly different
     *   structure (e.g. allowed fields) in some cases. It's unlikely this
     *   parameter needs to be passed, except when called recursively from
     *   UpdateObject code. Unlike $action, this value cannot be changed after
     *   the object is instantiated.
     *
     * @return static
     *
     * @throws \InvalidArgumentException
     *   If any argument is invalid or he element data contains invalid values
     *   or unknown field/object names.
     * @throws \UnexpectedValueException
     *   If this object's defined properties are invalid.
     *
     * @see UpdateObject::__construct()
     * @see UpdateObject::output()
     * @see UpdateObject::overrideClass()
     */
    public static function create($type, array $elements = [], $action = '', $validation_behavior = self::VALIDATE_ESSENTIAL, $parent_type = '')
    {
        // If a custom class is defined for this type, instantiate that one.
        // (Note to self: self::$classMap and static::$classMap are exactly the
        // same here, because the variable is defined as static.)
        if (isset(self::$classMap[$type])) {
            return new self::$classMap[$type]($elements, $action, $type, $validation_behavior, $parent_type);
        }
        // Otherwise instantiate this class. (self(), not static(); if a
        // child class creates a new embedded object of a type not defined in
        // self::$classMap, it should create an instance of UpdateObject, not
        // of itself.)
        return new self($elements, $action, $type, $validation_behavior, $parent_type);
    }

    /**
     * Validates and/or merges 'property definitions overrides'.
     *
     * @param array $definition_overrides
     *   The definitions for one type, as it is / should be stored in
     *   $definitionOverrides.
     *
     * @throws \InvalidArgumentException
     *   If the definition overrides are invalid.
     */
    protected static function validateDefinitionOverrides(array $definition_overrides)
    {
        // Validate the overrides for 'fields' and 'objects'; change or unset
        // definitions where necessary.
        $errors = [];
        foreach (['fields', 'objects'] as $subtype) {
            if (isset($definition_overrides[$subtype])) {
                // The array itself may only be an array (of objects/fields).
                // Empty is OK; that will fall through without doing anything.
                if (!is_array($definition_overrides[$subtype])) {
                    $errors[] = "'$subtype' definition override is not an array.";
                } else {
                    // Each field definition must be an array or null. Merge or
                    // unset depending on the value.
                    foreach ($definition_overrides[$subtype] as $name => $value) {
                        if (isset($value)) {
                            if (!is_array($value)) {
                                $errors[] = "'$name' $subtype definition override is not an array.";
                            }
                        }
                    }
                }
            }
        }
        if ($errors) {
            throw new InvalidArgumentException(implode("\n", $errors));
        }
    }

    /**
     * UpdateObject constructor.
     *
     * Do not call this method directly; use UpdateObject::create() instead.
     * This constructor will likely not stay fully forward compatible for all
     * object types; it may start throwing exceptions for some types over time,
     * as they are implemented in dedicated child classes. (This warning
     * applies specifically to UpdateObject; child classes may allow callers to
     * call their constructor directly.)
     *
     * The arguments' order is switched from create(), and $type is optional,
     * to allow e.g. 'new CustomType($values)' more easily. ($type is not
     * actually optional in this class; an empty value will cause an exception
     * to be thrown. But many child classes will likely ignore the 3rd/4th
     * argument. So if they're lazy, they can get away with not reimplementing
     * a constructor.)
     *
     * @param array $elements
     * @param string $action
     * @param string $type
     * @param int $validation_behavior
     * @param string $parent_type
     *
     * @see UpdateObject::create()
     */
    public function __construct(array $elements = [], $action = '', $type = '', $validation_behavior = self::VALIDATE_ESSENTIAL, $parent_type = '')
    {
        $this->type = $type;
        if (!is_string($parent_type)) {
            throw new InvalidArgumentException('$parent_type argument is not a string.');
        }
        $this->parentType = $parent_type;
        $this->setAction($action);

        // If property definitions were set (which would be by a child class'
        // constructor or just defined in the variable), then don't set them
        // again. Child classes shouldn't be doing that though, because
        // resetPropertyDefinitions() calls won't work in that case.
        if (!$this->propertyDefinitions) {
            $this->resetPropertyDefinitions();
        }

        $this->addElements($elements, $validation_behavior);
    }

    /**
     * Magic method called on a newly cloned object.
     *
     * Clones each embedded object, so that changing properties in objects
     * inside an UpdateObject won't affect the cloned UpdateObject.
     */
    public function __clone()
    {
        // Lots of doublechecks on data types; silently skip if these fail.
        if (!empty($this->elements) && is_array($this->elements)) {
            foreach ($this->elements as $element_index => $element) {
                if (!empty($element['Objects']) && is_array($element['Objects'])) {
                    foreach ($element['Objects'] as $ref_field => $object) {
                        // This should always be true. Silently skip corrupt
                        // reference field values.
                        if ($object instanceof UpdateObject) {
                            $this->elements[$element_index]['Objects'][$ref_field] = clone $object;
                        }
                    }
                }
            }
        }
    }

    /**
     * Returns the object type.
     *
     * @return string
     */
    public function getType()
    {
        return $this->type;
    }

    /**
     * Returns the action which should be performed on one or all elements.
     *
     * @param int|string $element_index
     *   (Optional) The index of the element whose action is requested. Often
     *   this class will contain data for only one element and this argument
     *   does not need to be specified (or should be 0).
     *
     * @return string
     *
     * @throws \OutOfBoundsException
     *   If the index value does not exist.
     * @throws \UnexpectedValueException
     *   If different actions exist and the caller didn't request an index.
     */
    public function getAction($element_index = null)
    {
        if (empty($this->actions)) {
            $action = '';
        } elseif (isset($element_index) && isset($this->actions[$element_index])) {
            $action = $this->actions[$element_index];
        } else {
            // Throw an exception when requesting an action for a nonexistent
            // element. (Unless the action with this index was set anyway,
            // which is a valid use case, but that was covered above.)
            if (isset($element_index) && !isset($this->elements[$element_index])) {
                throw new OutOfBoundsException("No action or element defined for index $element_index.");
            }
            // At least one action was set but we're not requesting a specific
            // action, which is fine if all actions which are set, are the same.
            $unique = array_unique($this->actions);
            if (count($unique) > 1) {
                throw new UnexpectedValueException("Multiple different action values are set, so getAction() has to be called with a valid index parameter.");
            }
            $action = $unique[0];
        }

        return $action;
    }

    /**
     * Returns the actions that were set in this class.
     *
     * This is not known to be of any practical use to outside callers; it's
     * probably easier to call getAction() without argument and get a single
     * string returned, because in the vast majority of cases callers will set
     * only one element in an UpdateObject, or set several elements with the
     * same action. Still, it's possible for who knows which use case...
     *
     * @return string[]
     *   The array of all action values. Usually this will be an array of one
     *   value that was set through create().
     */
    public function getActions()
    {
        return $this->actions;
    }

    // phpcs:disable Squiz.WhiteSpace.ControlStructureSpacing.SpacingAfterOpen
    /**
     * Sets the action to perform on element data.
     *
     * 'Actions' are a bit of an oddity: the only value that is known to have
     * an effect (and is often required to generate proper output) is "insert",
     * if this element's output should be used for inserting new data into AFAS.
     * This value can also be passed in create(), so calling setAction()
     * separately should not often be necessary.
     *
     * It's still implemented as a string with other possible values, in order
     * to be able to output all known forms of XML for sending to the SOAP
     * API. It's possible that we discover a situation later, where embedding
     * a specific action value in the XML is a necessity.
     *
     * For now, just remember: set "insert" when inserting new data (which will
     * automatically take care of setting necessary default values), otherwise
     * set "update" to be future proof. ("delete" has not been tested so we
     * can't comment on that yet.) This is only to assure future compatibility:
     * "update" does not have any effect on the REST API currently; it will
     * change the XML used for the SOAP API slightly but its only known effect
     * is when updating elements embedded inside other elements. (It prevents
     * AFAS internal errors, somehow.)
     *
     * @param string $action
     *   The action to perform on the data: "insert", "update" or "delete". ""
     *   is also accepted as a valid value, though it has no known use.
     * @param bool $set_embedded
     *   (Optional) If false, then if this/these element(s) contain(s) any
     *   embedded objects, do not modify those; only set the current object. By
     *   default, the action is also set/overwritten in any embedded objects.
     *   (This argument has no effect on elements added later; if e.g. a later
     *   addElements() contains embedded objects, those will always inherit the
     *   action set on the parent element.)
     * @param int|string $element_index
     *   (Optional) The index of the element for which to set the action. It's
     *   usually not needed even when the UpdateObject holds data for multiple
     *   elements. It's only of theoretical use (which is: outputting multiple
     *   objects with different "action" values as XML. JSON output is likely
     *   bogus when different action values are set for different elements).
     *
     * @throws \InvalidArgumentException
     *   If the action value is unknown.
     */
    public function setAction($action, $set_embedded = true, $element_index = null)
    {
        // Unify $action. We'll silently accept PUT/POST too, as long as the
        // REST API keeps the one-to-one relationship of these to update/insert.
        $actions = ['put' => 'update', 'post' => 'insert', 'delete' => 'delete'];
        if ($action && is_string($action)) {
            $action = strtolower($action);
            if (isset($actions[$action])) {
                $action = $actions[$action];
            }
        }
        if (!is_string($action) || ($action && !in_array($action, $actions, true))) {
            throw new InvalidArgumentException('Unknown action value ' . var_export($action, true) . '.');
        }

        if (isset($element_index)) {
            $this->actions[$element_index] = $action;
        } else {
            // Set the value in any defined actions. (Usually the actions
            // variable contains only one entry with index 0, regardless of
            // how many elements are present in this UpdateObject, which is
            // fine.)
            if (empty($this->actions) || !is_array($this->actions)) {
                $this->actions = [$action];
            } else {
                foreach ($this->actions as $index => $a) {
                    $this->actions[$index] = $action;
                }
            }
        }

        if ($set_embedded && !empty($this->elements) && is_array($this->elements)) {
            // Change $element_index if it's necessary for key comparisons.
            if (
                is_float($element_index) || is_bool($element_index) ||
                is_string($element_index) && (string)(int)$element_index === $element_index
            ) {
                $element_index = (int)$element_index;
            }
            // Set all actions in embedded objects of the element corresponding
            // to the index we passed into this method.
            foreach ($this->elements as $i => $element) {
                if (!isset($element_index) || $i === $element_index) {

                    if (!empty($element['Objects']) && is_array($element['Objects'])) {
                        foreach ($element['Objects'] as $object) {
                            // This should always be true. Silently skip
                            // corrupt reference field values.
                            if ($object instanceof UpdateObject) {
                                $object->setAction($action, true);
                            }
                        }
                    }
                }
            }
        }
    }
    // phpcs:enable

    /**
     * Returns the ID value of one of this object's elements.
     *
     * @param int|string $element_index
     *   (Optional) The index of the element whose ID is requested.
     *
     * @return int|string|null
     *   The ID value, or null if no value is set.
     *
     * @throws \OutOfBoundsException
     *   If this object type has no 'id_property' definition.
     */
    public function getId($element_index = 0)
    {
        $element = $this->checkElement($element_index);
        if (empty($this->propertyDefinitions['id_property'])) {
            throw new OutOfBoundsException("'{$this->getType()}' object has no 'id_property' definition.");
        }
        if (!is_string($this->propertyDefinitions['id_property'])) {
            throw new UnexpectedValueException("'id_property' definition in '{$this->getType()}' object is not a string value.");
        }

        $id_property = '@' . $this->propertyDefinitions['id_property'];
        return isset($element[$id_property]) ? $element[$id_property] : null;
    }

    /**
     * Sets the ID value in one of this object's elements.
     *
     * @param int|string $value
     *   The ID value to set. Empty string means no value.
     * @param int|string $element_index
     *   (Optional) The index of the element whose ID is set. If it does not
     *   exist yet, a new element is created with just this ID.
     *
     * @throws \InvalidArgumentException
     *   If the value has an unexpected type.
     * @throws \UnexpectedValueException
     *   If this object type has no 'id_property' definition.
     */
    public function setId($value, $element_index = 0)
    {
        if (!is_int($value) && !is_string($value)) {
            throw new InvalidArgumentException("Value must be integer or string.");
        }
        if (empty($this->propertyDefinitions['id_property'])) {
            throw new UnexpectedValueException("'{$this->getType()}' object has no 'id_property' definition.");
        }
        if (!is_string($this->propertyDefinitions['id_property'])) {
            throw new UnexpectedValueException("'id_property' definition in '{$this->getType()}' object is not a string value.");
        }

        $this->elements[$element_index]['@' . $this->propertyDefinitions['id_property']] = $value;
    }

    /**
     * Returns the value of a field as stored in one of this object's elements.
     *
     * This returns the stored value, not validated or changed. For a validated
     * equivalent, call getElements(DEFAULT_CHANGE, DEFAULT_VALIDATION) and
     * access the field inside the element.
     *
     * @param string $field_name
     *   The name of the field, or its alias.
     * @param int|string $element_index
     *   (Optional) The index of the element whose field value is requested.
     *   The element must exist, except if $return_default is passed.
     * @param bool $return_default
     *   (Optional) If true, return the default value if no field value is set.
     * @param bool $wrap_array
     *   (Optional) If true, wrap the return value in an array, or return an
     *   empty array if the field has no value. This is the only way to
     *   differentiate between 'no value' and 'a value of null'.
     *
     * @return mixed
     *   The value (usually a string/numeric/boolean value), possibly wrapped
     *   in an array.
     *
     * @throws \OutOfBoundsException
     *   If the field name/alias does not exist in this object type's "fields"
     *   definition, or no element corresponding to the index exists and
     *   no default value is requested.
     */
    public function getField($field_name, $element_index = 0, $return_default = false, $wrap_array = false)
    {
        // Get element, or empty array if we want to return default.
        $element = $this->checkElement($element_index, $return_default);
        $field_name = $this->checkFieldName($field_name);

        // Check for element value or default value. Both can be null. If the
        // element value is set to null explicitly, we do not replace it with
        // the default.
        if (isset($element['Fields']) && array_key_exists($field_name, $element['Fields'])) {
            $return = $wrap_array ? [$element['Fields'][$field_name]] : $element['Fields'][$field_name];
        } elseif (
            $return_default && isset($this->propertyDefinitions['fields'][$field_name])
            && array_key_exists('default', $this->propertyDefinitions['fields'][$field_name])
        ) {
            $return = $wrap_array ? [$this->propertyDefinitions['fields'][$field_name]['default']] : $this->propertyDefinitions['fields'][$field_name]['default'];
        } else {
            $return = $wrap_array ? [] : null;
        }
        // A note: if we ever want to add validation here, that means that
        // fields whose formatting/validation depends on other field values
        // will not be properly validated anymore. (See comments at
        // validateFieldValue() on the somewhat clunky, but more or less
        // reliable, way this is implemented now.) Also, the companion
        // getObject() will never validate its return value because it returns
        // an object.

        return $return;
    }

    /**
     * Sets the value of a field in one of this object's elements.
     *
     * @param string $field_name
     *   The name of the field, or its alias.
     * @param int|string $value
     *   The field value to set.
     * @param int|string $element_index
     *   (Optional) The index of the element whose field is set. If it does not
     *   exist yet, a new element is created with just this field.
     * @param int $validation_behavior
     *   (Optional) Specifies whether/how the elements should be validated,
     *   throwing an exception on failure. By default only very basic
     *   validation on individual fields (e.g. for correct data types) is done
     *   here and full validation happens during output(). This value is a
     *   bitmask; the relevant bits for validating a single field are
     *   VALIDATE_ESSENTIAL and VALIDATE_FORMAT; most other bits have to do
     *   with validation of the object as a whole and are always ignored here.
     *   See output() for more.
     *
     * @throws \InvalidArgumentException
     *   If the value has an unexpected type.
     * @throws \OutOfBoundsException
     *   If the field name/alias does not exist in this object type's "fields"
     *   definition.
     *
     * @see UpdateObject::output()
     */
    public function setField($field_name, $value, $element_index = 0, $validation_behavior = self::VALIDATE_ESSENTIAL)
    {
        $element = $this->checkElement($element_index, true);
        $field_name = $this->checkFieldName($field_name);

        $this->elements[$element_index]['Fields'][$field_name] = $this->validateFieldValue($value, $field_name, self::ALLOW_NO_CHANGES, $validation_behavior, $element_index, $element);
    }

    /**
     * Removes a field value from one of this object's elements.
     *
     * @param string $field_name
     *   The name of the field, or its alias.
     * @param int|string $element_index
     *   (Optional) The index of the element.
     *
     * @return bool
     *   True if the field is set before calling this function (also if its
     *   value is null); false otherwise.
     *
     * @throws \OutOfBoundsException
     *   If the field name/alias does not exist in this object type's "fields"
     *   definition.
     */
    public function unsetField($field_name, $element_index = 0)
    {
        $field_name = $this->checkFieldName($field_name);

        $return = false;
        if (!empty($this->elements[$element_index]['Fields']) && array_key_exists($field_name, $this->elements[$element_index]['Fields'])) {
            $return = true;
            unset($this->elements[$element_index]['Fields'][$field_name]);
            // Remove an empty internal 'Fields' array, so output does not
            // include it.
            if (empty($this->elements[$element_index]['Fields'])) {
                unset($this->elements[$element_index]['Fields']);
            }
        }

        return $return;
    }

    /**
     * Returns a copy of the current class instance without parentType set.
     *
     * This method exists to facilitate getting an embedded object which can be
     * used standalone. Probably the best way to use this is to not call this
     * method directly, but call getObject(,,,true) instead.
     *
     * @return UpdateObject
     */
    public function cloneUnlinked()
    {
        $parent_type = $this->parentType;
        $this->parentType = null;
        $clone = clone $this;
        $this->parentType = $parent_type;
        // Reset the property definitions; they may need to change now that
        // there is no parent type.
        $clone->resetPropertyDefinitions();
        return $clone;
    }

    /**
     * Returns an object embedded in one of this object's elements.
     *
     * @param string $reference_field_name
     *   The name of the reference field for the object, or its alias. (This is
     *   often but not always equal to the object type; see the docs at
     *   $propertyDefinitions.)
     * @param int|string $element_index
     *   (Optional) The index of the element whose embedded object value is
     *   requested. The element must exist except if $return_default is passed.
     * @param bool $default
     *   (Optional) If true, then if no embedded object is set, then an object
     *   with a default value according to property definitions is returned.
     *   For such a 'default' object, $unembedded = true is implied.
     * @param bool $unembedded
     *   (Optional) If true, return a copy of the object which is not linked to
     *   its parent. ('Copy' implies that changes made to the returned object
     *   will not affect its former parent element.) This enables it to be
     *   turned into an output string, and could change validation logic.
     *
     * @return \PracticalAfas\UpdateConnector\UpdateObject
     *   The requested object. Note this has an immutable 'parentType' property
     *   which might change its behavior, thereby making it unsuitable to be
     *   used in isolation.
     *
     * @throws \OutOfBoundsException
     *   If the reference field name/alias does not exist in this object type's
     *   "objects" definition, or no element corresponding to the index exists
     *   and no default value is requested.
     * @throws \UnexpectedValueException
     *   If something's wrong with the default value.
     */
    public function getObject($reference_field_name, $element_index = 0, $default = false, $unembedded = false)
    {
        $element = $this->checkElement($element_index, $default);
        $reference_field_name = $this->checkObjectReferenceFieldName($reference_field_name);

        // Check for element value or default value.
        if (isset($element['Objects'][$reference_field_name])) {
            $return = $element['Objects'][$reference_field_name];
            if (!$return instanceof UpdateObject) {
                throw new UnexpectedValueException("Value for embedded object must be of type UpdateObject.");
            }
            if ($unembedded) {
                $return = $return->cloneUnlinked();
            }
        } elseif ($default && isset($this->propertyDefinitions['objects'][$reference_field_name]['default'])) {
            $return = $this->propertyDefinitions['objects'][$reference_field_name]['default'];
            if (is_array($return)) {
                // The object type is often equal to the name of the 'reference
                // field' in the parent element, but not always; there's a
                // property to specify it. The intended 'action' value is
                // always assumed to be equal to its parent's current value.
                $type = !empty($this->propertyDefinitions['objects'][$reference_field_name]['type'])
                    ? $this->propertyDefinitions['objects'][$reference_field_name]['type'] : $reference_field_name;
                try {
                    $return = static::create($type, $return, $this->getAction($element_index), self::VALIDATE_ESSENTIAL, $this->getType());
                } catch (InvalidArgumentException $e) {
                    // 'Unify' exception to an UnexpectedValueException.
                    throw new UnexpectedValueException($e->getMessage(), $e->getCode());
                }
            } else {
                $element_descr = "'{$this->getType()}' element" . ($element_index ? " with index $element_index" : '');
                throw new UnexpectedValueException("Default value for '$reference_field_name' object embedded in $element_descr must be array.");
            }
        } else {
            $return = null;
        }

        return $return;
    }

    /**
     * Creates an embedded object in one of this object's elements.
     *
     * Note this is not an exact counterpart to getObject(); that returns an
     * object (for further manipulation) while this accepts parts to create an
     * object out of. If O is an object retrieved by getObject(), setting O's
     * value back would be achieved by passing O->getElements() and
     * O->getAction() as second and third argument.
     *
     * @param string $reference_field_name
     *   The name of the reference field for the object, or its alias. (This is
     *   often but not always equal to the object type; see the docs at
     *   $propertyDefinitions.)
     * @param array $embedded_elements
     *   Data to set in the embedded object, representing one or more elements.
     *   See create().
     * @param string $action
     *   (Optional) The action to perform on the data. By default, the action
     *   set in the parent element (stored in this object) is taken.
     * @param int|string $element_index
     *   (Optional) The index of the element in which the object is embedded.
     *   If it does not exist yet, a new element is created with just this
     *   embedded object.
     * @param int $validation_behavior
     *   (Optional) Specifies whether/how the elements should be validated; see
     *   create() for a more elaborate description.
     *
     * @throws \InvalidArgumentException
     *   If the element structure is invalid or the action value cannot be null.
     * @throws \OutOfBoundsException
     *   If the field name/alias does not exist in this object type's "fields"
     *   definition.
     *
     * @see UpdateObject::create()
     * @see UpdateObject::setAction()
     */
    public function setObject($reference_field_name, array $embedded_elements, $action = null, $element_index = 0, $validation_behavior = self::VALIDATE_ESSENTIAL)
    {
        $reference_field_name = $this->checkObjectReferenceFieldName($reference_field_name);

        $this->elements[$element_index]['Objects'][$reference_field_name] = $this->createEmbeddedObject($reference_field_name, $embedded_elements, $action, $element_index, $validation_behavior);
    }

    /**
     * Removes an embedded object from one of this object's elements.
     *
     * @param string $reference_field_name
     *   The name of the reference field for the object, or its alias. (This is
     *   often but not always equal to the object type; see the docs at
     *   $propertyDefinitions.)
     * @param int|string $element_index
     *   (Optional) The index of the element.
     *
     * @return bool
     *   True if on object is set before calling this function; false otherwise.
     *
     * @throws \OutOfBoundsException
     *   If the field name/alias does not exist in this object type's "fields"
     *   definition.
     */
    public function unsetObject($reference_field_name, $element_index = 0)
    {
        $reference_field_name = $this->checkObjectReferenceFieldName($reference_field_name);

        $return = false;
        // Values inside 'Objects' cannot be null; we check that in a few other
        // places - so we don't need to unset null values here.
        if (isset($this->elements[$element_index]['Objects'][$reference_field_name])) {
            $return = true;
            unset($this->elements[$element_index]['Objects'][$reference_field_name]);
            // Remove an empty internal 'Objects' array, so output does not
            // include it.
            if (empty($this->elements[$element_index]['Objects'])) {
                unset($this->elements[$element_index]['Objects']);
            }
        }

        return $return;
    }

    /**
     * Creates an object ready for embedding in one of this object's elements.
     *
     * This does some sanity checks first.
     *
     * @param string $reference_field_name
     *   Reference field name (not alias).
     * @param array $embedded_elements
     *   Data to set in the embedded object, representing one or more elements.
     *   See create().
     * @param string $action
     *   (Optional) The action to perform on the data. By default, the action
     *   set in the parent element (stored in this object) is taken.
     * @param int|string $element_index
     *   The index that the element which the object will be embedded into, has
     *   or will have. When called from add/setElement(s), the element with the
     *   specified index does/may not exist yet.
     * @param int $validation_behavior
     *   (Optional) Specifies whether/how the elements should be validated; see
     *   create() for a more elaborate description.
     *
     * @throws \InvalidArgumentException
     *   If the elements cannot be embedded or the action value cannot be null.
     * @throws \OutOfBoundsException
     *   If the field name/alias does not exist in this object type's "fields"
     *   definition.
     *
     * @return static
     *
     * @see UpdateObject::setObject()
     */
    protected function createEmbeddedObject($reference_field_name, array $embedded_elements, $action = null, $element_index = 0, $validation_behavior = self::VALIDATE_ESSENTIAL)
    {
        // Strip "Element" wrapper if it's there, and also convert to array
        // of N elements. (The latter is not necessary for calling create() but
        // it is necessary for the 'multiple' check.)
        $embedded_elements = $this->normalizeElements($embedded_elements);

        // Check if we are allowed to add multiple embedded objects into this
        // reference field, and if not, whether we are doing that. On 'output'
        // this is a mandatory check, but on 'input' it can be suppressed.
        if (
            $validation_behavior & self::VALIDATE_ESSENTIAL && count($embedded_elements) > 1
            && empty($this->propertyDefinitions['objects'][$reference_field_name]['multiple'])
        ) {
            $name_and_alias = "'$reference_field_name'" . (isset($this->propertyDefinitions['objects'][$reference_field_name]['alias']) ? " ({$this->propertyDefinitions['objects'][$reference_field_name]['alias']})" : '');
            throw new InvalidArgumentException("Embedded object $name_and_alias contains " . count($embedded_elements) . ' elements but can only contain a single element.');
        }

        if (!isset($action)) {
            // Take the action associated with the parent element, if that is
            // set. If neither the parent element nor its action exists yet,
            // this can throw an OutOfBoundsException, in which case we should
            // get the default action. Both getAction() calls can also throw an
            // UnexpectedValueException if (no action associated with the
            // specific element is set and) our UpdateObject has elements with
            // multiple different actions. This can only be resolved by calling
            // setAction(,,$element_index) before calling this method. That's
            // such an edge case that it isn't documented elsewhere.
            try {
                $action = $this->getAction($element_index);
            } catch (OutOfBoundsException $e) {
                $action = $this->getAction();
            }
        }
        // The object type is often equal to the name of the 'reference field'
        // but not always; there's a property to specify it.
        $type = !empty($this->propertyDefinitions['objects'][$reference_field_name]['type'])
            ? $this->propertyDefinitions['objects'][$reference_field_name]['type'] : $reference_field_name;
        try {
            $object = static::create($type, $embedded_elements, $action, $validation_behavior, $this->getType());
        } catch (InvalidArgumentException $e) {
            // The message can contain multiple errors separated by newlines.
            // Other messages in this method (just like validateFieldValue(),
            // and validateObjectValue() which is on the same 'level' as this
            // method but for output) mention the field name, so we should too.
            // So we catch the error and add it to every line.
            $error_prefix = "object-ref $reference_field_name: ";
            throw new InvalidArgumentException($error_prefix . implode("\n$error_prefix", explode("\n", $e->getMessage())));
        }

        return $object;
    }

    /**
     * Helper method: get an element with a certain index.
     *
     * We don't want to make this 'public getElement()' because that creates
     * too much choice / ambiguity; callers should use getElements().
     *
     * @param int|string $element_index
     *   The index of the requested element.
     * @param bool $allow_nonexistent
     *   (Optional) if true, return an empty array if the element with the
     *   specified index does not exixt.
     *
     * @return array
     *   The element.
     */
    protected function checkElement($element_index = 0, $allow_nonexistent = false)
    {
        if (isset($this->elements[$element_index])) {
            $element = $this->elements[$element_index];
        } elseif ($allow_nonexistent) {
            $element = [];
        } else {
            throw new OutOfBoundsException("No element present with index $element_index.");
        }

        return $element;
    }

    /**
     * Helper method: check if a field name exists, as field or alias.
     *
     * @param string $field_name
     *   The name of the field, or its alias.
     *   Value of the element. Only used for checking property definitions,
     *   unless if property definitions are cached; then this is not used.
     *
     * @return string
     *   The field name; either the same as the first argument, or resolved
     *   from the first argument if that is an alias.
     */
    protected function checkFieldName($field_name)
    {
        if (!isset($this->propertyDefinitions['fields'][$field_name])) {
            // Check if we have an alias; resolve to field name.
            foreach ($this->propertyDefinitions['fields'] as $real_field_name => $definition) {
                if (isset($definition['alias']) && $definition['alias'] === $field_name) {
                    $field_name = $real_field_name;
                    break;
                }
            }
            if (!isset($this->propertyDefinitions['fields'][$field_name])) {
                throw new OutOfBoundsException("'{$this->getType()}' object has no definition for field '$field_name'.");
            }
        }

        return $field_name;
    }

    /**
     * Helper method: check if an object reference field name, or alias, exists.
     *
     * @param string $reference_field_name
     *   The name of the object reference field, or its alias.
     *
     * @return string
     *   The field name; either the same as the first argument, or resolved
     *   from the first argument if that is an alias.
     */
    protected function checkObjectReferenceFieldName($reference_field_name)
    {
        if (!isset($this->propertyDefinitions['objects'][$reference_field_name])) {
            // Check if we have an alias; resolve to field name.
            foreach ($this->propertyDefinitions['objects'] as $real_field_name => $definition) {
                if (isset($definition['alias']) && $definition['alias'] === $reference_field_name) {
                    $reference_field_name = $real_field_name;
                    break;
                }
            }
            if (!isset($this->propertyDefinitions['objects'][$reference_field_name])) {
                throw new OutOfBoundsException("'{$this->getType()}' object has no definition for object '$reference_field_name'.");
            }
        }

        return $reference_field_name;
    }

    /**
     * Returns a uniformly structured representation of one or more elements.
     *
     * This is about converting a single element to an array if necessary. It
     * does nothing to the structure of the element itself; that's
     * validateElementInput().
     *
     * @param array $elements
     *   The input data; see create() for details.
     *
     * @return array[]
     *   The data formatted as an array of elements.
     *
     * @throws \InvalidArgumentException
     *   If the data has an invalid structure.
     */
    protected function normalizeElements(array $elements)
    {
        // If the caller passed the element(s) inside an 'Element' wrapper
        // array and/or that again wrapped inside a '<type> wrapper' (because
        // they are handling e.g. some json-decoded string from another source,
        // or embedded objects inside the getElements() return value), accept
        // that. Strip the wrappers off - except if what would be left is not
        // an array. (In that case we regard the key to be a field name.)
        if (count($elements) == 1 && key($elements) === $this->getType() && is_array(reset($elements))) {
            $elements = reset($elements);
        }
        if (count($elements) == 1 && key($elements) === 'Element' && is_array(reset($elements))) {
            $elements = reset($elements);
        }
        // Determine if we have a single element or an array of elements.
        foreach ($elements as $key => $element) {
            if (is_scalar($element) || !is_numeric($key)) {
                // This is assumed to be a single element. Normalize to an
                // array of elements..
                $elements = [$elements];
                break;
            }
        }

        return $elements;
    }

    /**
     * Validates, and uniformly structures, a single element.
     *
     * @param array $element
     *   The input data; see create() for details.
     * @param int|string $element_index
     *   (Optional) The index which the element will get.
     * @param int $validation_behavior
     *   (Optional) Specifies whether/how the elements should be validated; see
     *   create() for a more elaborate description.
     *
     * @return array
     *   The element with uniform structure and field/object names. Validation
     *   errors are stored as a one-dimensional array under the '*errors' key.
     *
     * @throws \InvalidArgumentException
     *   If the data has an invalid structure.
     * @throws \UnexpectedValueException
     *   If this object's defined properties are invalid.
     *
     * @see UpdateObject::create()
     */
    protected function validateElementInput(array $element, $element_index, $validation_behavior = self::VALIDATE_ESSENTIAL)
    {
        if (empty($this->propertyDefinitions)) {
            throw new UnexpectedValueException("'{$this->getType()}' object has no property definitions.");
        }
        // Check if the 'fields' property is set, even if it's an empty array.
        // (This is slightly arbitrary because we don't do the same for
        // 'objects', but it may provide an understandable error if definitions
        // are set wrongly by accident.)
        if (!isset($this->propertyDefinitions['fields']) || !is_array($this->propertyDefinitions['fields'])) {
            throw new UnexpectedValueException("'{$this->getType()}' object has no / a non-array 'fields' property definition.");
        }
        if (isset($this->propertyDefinitions['objects']) && !is_array($this->propertyDefinitions['objects'])) {
            throw new UnexpectedValueException("'{$this->getType()}' object has a non-array 'objects' property definition.");
        }

        $normalized_element = [];

        // If this type has an ID field, check for it and set it in its
        // dedicated location. AFAS has various names for various types' ID
        // property, but we are also allowed to refer to each id by '@id'.
        if (!empty($this->propertyDefinitions['id_property'])) {
            if (!is_string($this->propertyDefinitions['id_property'])) {
                throw new UnexpectedValueException("'id_property' definition in '{$this->getType()}' object is not a string value.");
            }
            $id_property = '@' . $this->propertyDefinitions['id_property'];
            if (array_key_exists($id_property, $element)) {
                if (isset($element[$id_property]) && !is_int($element[$id_property]) && !is_string($element[$id_property])) {
                    $normalized_element['*errors'][$id_property] = "'$id_property' property must hold integer/string value.";
                }
                $normalized_element[$id_property] = $element[$id_property];
                // Unset so that we won't throw an exception at the end.
                unset($element[$id_property]);
            }
            if (array_key_exists('@id', $element)) {
                // If 2 'id' properties exist, we validate both. The other one
                // 'wins', which doesn't matter since we'll throw an exception
                // in the end anyway.
                if (isset($element['@id']) && !is_int($element['@id']) && !is_string($element['@id'])) {
                    $normalized_element['*errors']['@id'] = "'@id' property must hold integer/string value.";
                }
                // We register an error only if the two id properties do not
                // have the same value. This is different from the field/object
                // values where we always throw an error if a duplicate exists.
                if (array_key_exists($id_property, $element) && $element['@id'] !== $element[$id_property]) {
                    $normalized_element['*errors']['@id:'] = "ID property is provided by both its name '$id_property' and the generic alias '@id'.";
                } else {
                    $normalized_element[$id_property] = $element['@id'];
                }
                unset($element['@id']);
            }
        }

        // Check if this element now consists of nothing more than 'Fields' and
        // 'Objects' sub-elements. (If there's an unrecognized sub-element, the
        // below code will populate nothing and report unknown properties
        // 'Fields, Objects, <extra>' in the end.)
        $well_formed = count($element) == (isset($element['Fields']) ? 1 : 0) + (isset($element['Objects']) ? 1 : 0);

        // The keys in $this->elements are not reordered on output, and we want
        // to have 'Fields' go first just because it looks nice for humans who
        // might look at the output. On the other hand, we need to populate
        // 'Objects' first because we promised implementing code that during
        // field validation, embedded objects are already validated. So,
        // 'cheat' by pre-populating a 'Fields' key. (Note that if code
        // populates a new element using individual setObject(), setField() and
        // setId() calls, it can influence the order of keys in output.)
        $normalized_element['Fields'] = [];

        // Validate / add objects, if object property definitions exist /
        // unless the 'well formed' element has no Objects.
        if ($well_formed && isset($element['Objects']) && !is_array($element['Objects'])) {
            $normalized_element['*errors']["Objects"] = "'Objects' value is not an array.";
            unset($element['Objects']);
        }
        if (!empty($this->propertyDefinitions['objects']) && (!$well_formed || isset($element['Objects']))) {
            $well_formed_element_backup = $well_formed ? $element : [];
            if ($well_formed) {
                // This is the part we'll check and slowly unset.
                $element = $element['Objects'];
            }

            foreach ($this->propertyDefinitions['objects'] as $name => $object_properties) {
                if (!is_array($object_properties)) {
                    throw new UnexpectedValueException("'{$this->getType()}' object has a non-array definition for object '$name'.");
                }
                $alias = isset($object_properties['alias']) ? $object_properties['alias'] : '';
                // Get value from the property equal to the object name (case
                // sensitive!), or the alias.
                if (array_key_exists($name, $element)) {
                    // If values exist for both name and alias, we still
                    // validate both, but throw an exception in the end.
                    if (array_key_exists($alias, $element)) {
                        $normalized_element['*errors']["Objects:$name:"] = "Object value is provided by both its property name $name and alias $object_properties[alias].";
                    }

                    if (!is_array($element[$name])) {
                        $name_and_alias = "'$name'" . ($alias ? " ($alias)" : '');
                        $normalized_element['*errors']["Objects:$name"] = "Value for $name_and_alias object is not an array.";
                    } else {
                        try {
                            $normalized_element['Objects'][$name] = $this->createEmbeddedObject($name, $element[$name], null, $element_index, $validation_behavior);
                        } catch (\Exception $e) {
                            $normalized_element['*errors']["Objects:$name"] = $e->getMessage();
                        }
                    }
                    unset($element[$name]);
                }
                if ($alias && array_key_exists($alias, $element)) {
                    if (!is_array($element[$alias])) {
                        $name_and_alias = "'$name'" . ($alias ? " ($alias)" : '');
                        $normalized_element['*errors']["Objects:$alias"] = "Value for $name_and_alias object is not an array.";
                    } else {
                        try {
                            $normalized_element['Objects'][$name] = $this->createEmbeddedObject($name, $element[$alias], null, $element_index, $validation_behavior);
                        } catch (\Exception $e) {
                            $normalized_element['*errors']["Objects:$alias"] = $e->getMessage();
                        }
                    }
                    unset($element[$alias]);
                }
            }

            // If the element is 'well formed', then we can check for unknown
            // object definitions here.
            if ($well_formed_element_backup) {
                if ($element) {
                    $keys = "'" . implode(', ', array_keys($element)) . "'";
                    $normalized_element['*errors']['Objects:'] = "Unknown 'Objects' properties provided: names are $keys.";
                }
                $element = $well_formed_element_backup;
                // Unset for last check.
                unset($element['Objects']);
            }
        }

        // Validate / add fields, if field property definitions exist / unless
        // the element has no Fields.
        if ($well_formed && isset($element['Fields']) && !is_array($element['Fields'])) {
            $normalized_element['*errors']["Fields"] = "'Fields' value is not an array.";
            unset($element['Fields']);
        }
        if (!empty($this->propertyDefinitions['fields']) && ($well_formed ? isset($element['Fields']) : $element)) {
            if ($well_formed) {
                // Nothing except 'Fields' is left.
                $element = $element['Fields'];
            }

            foreach ($this->propertyDefinitions['fields'] as $name => $field_properties) {
                if (!is_array($field_properties)) {
                    throw new UnexpectedValueException("'{$this->getType()}' object has a non-array definition for field '$name'.");
                }
                $alias = isset($field_properties['alias']) ? $field_properties['alias'] : '';
                // Get value from the property equal to the field name (case
                // sensitive!), or the alias.
                if (array_key_exists($name, $element)) {
                    // If values exist for both name and alias, we still
                    // validate both, but throw an exception in the end.
                    if (array_key_exists($alias, $element)) {
                        $normalized_element['*errors']["Fields:$name:"] = "Field value is provided by both its field name $name and alias $field_properties[alias].";
                    }

                    try {
                        $normalized_element['Fields'][$name] = $this->validateFieldValue($element[$name], $name, self::ALLOW_NO_CHANGES, $validation_behavior, $element_index, $normalized_element);
                    } catch (\Exception $e) {
                        $normalized_element['*errors']["Fields:$name"] = $e->getMessage();
                    }
                    unset($element[$name]);
                }
                if ($alias && array_key_exists($alias, $element)) {
                    try {
                        $normalized_element['Fields'][$name] = $this->validateFieldValue($element[$alias], $name, self::ALLOW_NO_CHANGES, $validation_behavior, $element_index, $normalized_element);
                    } catch (\Exception $e) {
                        $normalized_element['*errors']["Fields:$alias"] = $e->getMessage();
                    }
                    unset($element[$alias]);
                }
            }
        }

        // Throw exception if we have unknown data left (for which we have not
        // seen a field/object/id).
        if ($element) {
            $keys = "'" . implode(', ', array_keys($element)) . "'";
            $description = $well_formed ? "'Fields'" : 'element';
            $normalized_element['*errors'][':'] = "Unknown $description properties provided: names are $keys.";
        }

        // If we didn't populate any fields, then unset our 'cheat' value.
        if (empty($normalized_element['Fields'])) {
            unset($normalized_element['Fields']);
        }

        return $normalized_element;
    }

    /**
     * Sets (a normalized/de-aliased version of) an element in this object.
     *
     * This can be used to set/change just one element if the object already
     * contains multiple elements, without touching the other elements, or if
     * you really want to index your elements by strings for some reason. In
     * general it's more advisable to use setElements() if this object should
     * have just one element, or addElements() to add an additional element.
     *
     * @param int|string $element_index
     *   (Optional) The index of the element which will be created or
     *   overwritten. Indexes will not be present in output, and the order in
     *   which elements are output depends only on the order in which they were
     *   created; not directly on the index values. Their only use is for
     *   referring to specific elements which are present in this object. By
     *   default (when elements are created through create() -> addElement())
     *   they are zero-based, incrementing integers.
     * @param array $element
     *   Data representing a single element to set; see create() for a
     *   description.
     * @param int $validation_behavior
     *   (Optional) Specifies whether/how the elements should be validated; see
     *   create() for a more elaborate description.
     *
     * @throws \InvalidArgumentException
     *   If the element data contains invalid values or unknown field/object
     *   names.
     * @throws \UnexpectedValueException
     *   If this object's defined properties are invalid.
     *
     * @see UpdateObject::create()
     */
    public function setElement($element_index, array $element, $validation_behavior = self::VALIDATE_ESSENTIAL)
    {
        $element = $this->validateElementInput($element, $element_index, $validation_behavior);
        if (!empty($element['*errors'])) {
            throw new InvalidArgumentException(implode("\n", $element['*errors']));
        }
        $this->elements[$element_index] = $element;
    }

    /**
     * Sets (a normalized/de-aliased version of) element values in this object.
     *
     * Unlike addElements(), this overwrites any existing element data which
     * may have been present previously. It does not overwrite e.g. the action
     * value(s).
     *
     * @param array $elements
     *   Data representing one or more elements to set; see create() for a
     *   description. Note that an array of elements can only be numerically
     *   keyed; it will be interpreted as a single array otherwise. (This
     *   unlike setElements(), setField() etc, which allow to set/reference
     *   elements by alphanumeric indexes.)
     * @param int $validation_behavior
     *   (Optional) Specifies whether/how the elements should be validated; see
     *   create() for a more elaborate description.
     *
     * @throws \InvalidArgumentException
     *   If the element data contains invalid values.
     * @throws \OutOfBoundsException
     *   If the element data contains unknown field/object names.
     * @throws \UnexpectedValueException
     *   If this object's defined properties are invalid.
     *
     * @see UpdateObject::create()
     */
    public function setElements(array $elements, $validation_behavior = self::VALIDATE_ESSENTIAL)
    {
        $this->elements = [];
        $this->addElements($elements, $validation_behavior);
    }

    /**
     * Adds (a normalized/de-aliased version of) element values to this object.
     *
     * It is recommended to set the 'action' for the elements that will be
     * added, before calling this method. This can be significant for
     * validation and for embedded objects, which inherit that action.
     *
     * @param array $elements
     *   Data representing one or more elements to set; see create() for a
     *   description. Note that an array of elements can only be numerically
     *   indexed; it will be interpreted as a single array otherwise. (This
     *   unlike setElements(), setField() etc, which allow to set/reference
     *   elements by alphanumeric indexes.) Numeric indexes are preserved
     *   except when they clash with existing elements; then these new ones are
     *   renumbered.
     * @param int $validation_behavior
     *   (Optional) Specifies whether/how the elements should be validated; see
     *   create() for a more elaborate description.
     *
     * @throws \InvalidArgumentException
     *   If the element data contains invalid values or unknown field/object
     *   names. If one of the passed elements has an error, none of them will
     *   be added.
     * @throws \UnexpectedValueException
     *   If this object's defined properties are invalid.
     *
     * @see UpdateObject::create()
     */
    public function addElements(array $elements, $validation_behavior = self::VALIDATE_ESSENTIAL)
    {
        $elements = $this->normalizeElements($elements);
        $validated_elements = [];
        $errors = [];

        // $elements now contains only numeric keys (because if the input
        // contained non-numeric keys, this would be considered one element and
        // 'wrapped'). If the caller specified numeric keys for elements, we
        // try to keep them but we'll renumber them if they've been used
        // already.
        foreach ($elements as $index => $element) {
            // We use $index for exception messages but pass $next_index to
            // callers (and we hope it does not get used in messages).
            $next_index = $index;
            if (isset($this->elements[$next_index]) || isset($validated_elements[$next_index])) {
                if (!is_int($next_index)) {
                    $next_index = (int)$next_index;
                }
                do {
                    $next_index++;
                } while (isset($this->elements[$next_index]) || isset($validated_elements[$next_index]));
            }

            $element = $this->validateElementInput($element, $next_index, $validation_behavior);

            if (isset($element['*errors'])) {
                if (!is_array($element['*errors'])) {
                    throw new UnexpectedValueException('Something unexpected during validation. We got a single value where we expected an array:' . print_r($element['*errors'], true));
                }
                if ($element['*errors']) {
                    // There may be multiple keyed errors; each may contain
                    // multiple errors separated by newlines (if they come from
                    // an embedded object.) Prefix all of them with the element
                    // key if we're adding multiple elements. Do not mention
                    // the object type itself in errors: the caller supposedly
                    // knows the object it's calling, and for errors in
                    // embedded objects the object name is almost-duplicate
                    // with the reference field name which is already included.
                    $error_prefix = (count($elements) > 1 ? "element-key $index: " : '');
                    foreach ($element['*errors'] as $key => $value) {
                        $errors["$index:$key"] = $error_prefix ?
                            ($error_prefix . implode("\n$error_prefix", explode("\n", $value))) : $value;
                    }
                } else {
                    unset($element['*errors']);
                }
            }

            // We can't set the elements until after all of them are validated.
            $validated_elements[$next_index] = $element;
        }

        if ($errors) {
            // We expect the error messages are descriptive enough and will
            // disregard the error keys. (Though who knows some code might find
            // them useful, or we will get back to this, later.)
            throw new InvalidArgumentException(implode("\n", $errors));
        }
        // Above, we've assured that element keys don't overlap, so we can
        // 'add' them (instead of merging the array), keeping keys the same.
        $this->elements = $this->elements + $validated_elements;
    }

    /**
     * Returns the "Element" data representing one or several elements.
     *
     * This is the 'getter' equivalent for setElements() but the data is
     * normalized / de-aliased, and possibly validated and changed.
     *
     * @param int $change_behavior
     *   (Optional) see output(), plus there's an extra bitmask value which is
     *   forced on output() but optional here:
     *   - RENUMBER_ELEMENT_INDEXES: Renumber the keys to be zero-based. (This
     *     enables outputting the return value as a JSON array rather than an
     *     object - which is what AFAS expects. This is 'on' by default.)
     *   Unlike output(), nothing is changed to the element value(s) by default
     *   (e.g. default values are not added); only the keys are renumbered.
     * @param int $validation_behavior
     *   (Optional) see output(). Unlike output(), by default nothing is
     *   validated.
     *
     * @return array[]
     *   Zero or more (numerically keyed) arrays; one in the typical use case.
     *   Each represents an element, which can contain 1 to 3 keys: the name of
     *   the ID field (starting with "@"), "Fields" and "Objects". "Fields" is
     *   always present even if empty. The "Objects" value, if present, is an
     *   array (keyed by the reference field names) of one-element arrays with
     *   a key "Element" and value again (recursively) zero or more elements.
     *
     * @throws \InvalidArgumentException
     *   If any of the arguments have an unrecognized value.
     * @throws \UnexpectedValueException
     *   If this object's data does not pass validation. (This likely indicates
     *   the data is invalid, although in theory it can also indicate that the
     *   validation is based on improper logic/definitions.)
     *
     * @see UpdateObject::output()
     */
    public function getElements($change_behavior = self::RENUMBER_ELEMENT_INDEXES, $validation_behavior = self::VALIDATE_NOTHING)
    {
        if (!is_int($change_behavior)) {
            throw new InvalidArgumentException('$change_behavior argument is not an integer.');
        }
        if (!is_int($validation_behavior)) {
            throw new InvalidArgumentException('$validation_behavior argument is not an integer.');
        }

        $original_properties = $this->propertyDefinitions;
        $return = [];
        $errors = [];
        try {
            foreach ($this->elements as $element_index => $element) {
                // Standard code can never have non-array elements. Still,
                // check it, in case a child class messes up.
                if (!is_array($element)) {
                    // We only expose the index value in errors if we seem to
                    // have multiple elements (because non-zero index.)
                    $element_descr = 'element' . ($element_index ? " with index $element_index" : '');
                    throw new UnexpectedValueException("$element_descr is not an array.");
                }
                $element = $this->validateElement($element, $element_index, $change_behavior, $validation_behavior);

                if (isset($element['*errors'])) {
                    if (!is_array($element['*errors'])) {
                        throw new UnexpectedValueException('Something unexpected during validation. We got a single value where we expected an array:' . print_r($element['*errors'], true));
                    }
                    if ($element['*errors']) {
                        // There may be multiple keyed errors; each may have
                        // multiple errors separated by newlines (if they come
                        // from an embedded object.) Prefix all of them with
                        // the element key if we're validating multiple
                        // elements. Do not mention the object type itself in
                        // errors: the caller supposedly knows the object it's
                        // calling, and for errors in embedded objects the
                        // object name is almost-duplicate with the reference
                        // field name which is already included.
                        $error_prefix = (count($this->elements) > 1 ? "element-key $element_index: " : '');
                        foreach ($element['*errors'] as $key => $value) {
                            $errors["$element_index:$key"] = $error_prefix ?
                                ($error_prefix . implode("\n$error_prefix", explode("\n", $value))) : $value;
                        }
                    } else {
                        unset($element['*errors']);
                    }
                }
                if ($element) {
                    if ($change_behavior & self::RENUMBER_ELEMENT_INDEXES) {
                        $return[] = $element;
                    } else {
                        $return[$element_index] = $element;
                    }
                }
            }
        } finally {
            // As documented, validation methods are allowed to modify the
            // property definitions. This should have no effect on behavior
            // after this method is called, so we could leave it changed, but
            // unit tests sometimes benefit from being able to test whether
            // the object as a whole is unchanged after validation.
            $this->propertyDefinitions = $original_properties;
        }
        if ($errors) {
            // We (mostly?) don't need the error keys because they've been
            // transferred into the error messages already by above implode() &
            // validateObjectValue().
            throw new UnexpectedValueException(implode("\n", $errors));
        }

        return $return;
    }

    /**
     * Validates one element against a list of property definitions.
     *
     * This method is the starting point for 'validation on output' of an
     * element; there's a tree structure of protected methods to do all the
     * validation. It contains some checks that are actually necessary for some
     * of those methods and don't need to be repeated there. It should not
     * touch $this->elements.
     *
     * @param array $element
     *   The element (usually the single one contained in $this->elements) that
     *   should be validated.
     * @param int|string $element_index
     *   The index of the element in our object data; usually there is one
     *   element and the index is 0.
     * @param int $change_behavior
     *   (Optional) see output().
     * @param int $validation_behavior
     *   (Optional) see output().
     *
     * @return array
     *   The validated element, with changes applied if appropriate.
     *   Validation errors are stored as an array under the '*errors' key,
     *   further keyed by 'Objects:<name>'; anything present under this key
     *   is overwritten.
     *
     * @throws \UnexpectedValueException
     *   If property definitions are invalid and validation could not be done.
     */
    protected function validateElement(array $element, $element_index, $change_behavior = self::DEFAULT_CHANGE, $validation_behavior = self::DEFAULT_VALIDATION)
    {
        // Do most low level structure checks here so that the other validate*()
        // methods are easier to override. Only include the type in the
        // exceptions we throw immediately; the '*error's we return will be
        // handled by the parent (so we can properly handle embedded objects).
        $element_descr = "'{$this->getType()}' element" . ($element_index ? " with index $element_index" : '');
        if (isset($element['Fields']) && !is_array($element['Fields'])) {
            throw new UnexpectedValueException("$element_descr has a non-array 'Fields' property value.");
        }
        if (isset($element['Objects']) && !is_array($element['Objects'])) {
            throw new UnexpectedValueException("$element_descr has a non-array 'Objects' property value.");
        }

        // Doublechecks; unlikely to fail because also in addElements(). (We
        // won't repeat them in each individual validate method; we do them
        // beforehand so child classes overriding validate[Reference]Fields()
        // don't have to worry about them.
        if (empty($this->propertyDefinitions)) {
            throw new UnexpectedValueException("'{$this->getType()}' object has no property definitions.");
        }
        if (!isset($this->propertyDefinitions['fields']) || !is_array($this->propertyDefinitions['fields'])) {
            throw new UnexpectedValueException("'{$this->getType()}' object has no / a non-array 'fields' property definition.");
        }
        if (isset($this->propertyDefinitions['objects']) && !is_array($this->propertyDefinitions['objects'])) {
            throw new UnexpectedValueException("'{$this->getType()}' object has a non-array 'objects' property definition.");
        }

        $element['*errors'] = [];
        if (!empty($this->propertyDefinitions['id_property'])) {
            if (!is_string($this->propertyDefinitions['id_property'])) {
                throw new UnexpectedValueException("'id_property' definition in '{$this->getType()}' object is not a string value.");
            }
            // Empty string means the same as unset. (This way we do not need
            // to implement an unsetId method. We assume '' can never be a
            // valid ID value, even though who knows 0 or "0" could be.)
            $id_property = '@' . $this->propertyDefinitions['id_property'];
            if (isset($element[$id_property]) && $element[$id_property] === '') {
                unset($element[$id_property]);
            }
            if ($validation_behavior & self::VALIDATE_ESSENTIAL) {
                if (isset($element[$id_property])) {
                    if (!is_int($element[$id_property]) && !is_string($element[$id_property])) {
                        $element['*errors'][$id_property] = "'$id_property' property must hold integer/string value.";
                    }
                } else {
                    // If action is "insert", we are guessing that there
                    // usually isn't, but still could be, a value for the ID
                    // field; it depends on 'auto numbering' for this object
                    // type. We don't validate this. (Yet?) We do validate that
                    // there's an ID value if action is different than "insert".
                    $action = $this->getAction($element_index);
                    if ($action !== 'insert') {
                        $element['*errors'][$id_property] = "'$id_property' property must have a value, or Action '$action' must be set to 'insert'.";
                    }
                }
            }
        }

        // Design decision: validate embedded objects first ('depth first'),
        // then validate the rest of this element while knowing that the
        // 'children' are OK, and with their properties accessible (dependent
        // on some $change_behavior values).
        $element = $this->validateReferenceFields($element, $element_index, $change_behavior, $validation_behavior);
        $element = $this->validateFields($element, $element_index, $change_behavior, $validation_behavior);
        ;

        if ($validation_behavior & self::VALIDATE_NO_UNKNOWN) {
            // Validate that all Fields/Objects/other properties are known.
            // This is a somewhat superfluous check because we already do this
            // in validateElementInput() (where we more or less have to because
            // our input is not necessarily divided over 'Objects' and 'Fields'
            // so validateElementInput() has to decide what each property is).
            if (!empty($element['Fields']) && $unknown = array_diff_key($element['Fields'], $this->propertyDefinitions['fields'])) {
                $element['*errors']['Fields'] = "Unknown field(s) encountered: " . implode(', ', array_keys($unknown)) . '.';
            }
            if (!empty($element['Objects']) && !empty($this->propertyDefinitions['objects']) && $unknown = array_diff_key($element['Objects'], $this->propertyDefinitions['objects'])) {
                $element['*errors']['Objects'] = "Unknown object(s) encountered: " . implode(', ', array_keys($unknown)) . '.';
            }
            $known_properties = ['Fields' => true, 'Objects' => true, '*errors' => true];
            if (!empty($this->propertyDefinitions['id_property'])) {
                $known_properties['@' . $this->propertyDefinitions['id_property']] = true;
            }
            if ($unknown = array_diff_key($element, $known_properties)) {
                $element['*errors']['*'] = "Unknown properties encountered: " . implode(', ', array_keys($unknown)) . '.';
            }
        }

        return $element;
    }

    /**
     * Validates an element's object reference fields; replaces them by arrays.
     *
     * This is mainly split out from validateElement() to be easier to override
     * by child classes. It should generally not touch $this->elements. We
     * can assume '*errors' has been initialized.
     *
     * @param array $element
     *   The element (usually the single one contained in $this->elements)
     *   whose embedded objects should be validated.
     * @param int|string $element_index
     *   The index of the element in our object data; usually there is one
     *   element and the index is 0.
     * @param int $change_behavior
     *   (Optional) see output(). Note that this is the behavior for our
     *   element and may still need to be amended to apply to embedded objects.
     * @param int $validation_behavior
     *   (Optional) see output().
     *
     * @return array
     *   The element with its embedded fields validated, and possibly default
     *   objects added if appropriate. All values in the 'Objects' sub array
     *   are replaced by their validated array representation, wrapped inside a
     *   one-element array keyed by 'Element' (as is necessary for the JSON
     *   representation of data sent to an Update Connector). Validation errors
     *   are stored as an array under the '*errors' key, further keyed by
     *   'Objects:<name>'.
     */
    protected function validateReferenceFields(array $element, $element_index, $change_behavior = self::DEFAULT_CHANGE, $validation_behavior = self::DEFAULT_VALIDATION)
    {
        if (!isset($this->propertyDefinitions['objects'])) {
            return $element;
        }
        $action = $this->getAction($element_index);
        $defaults_allowed = ($action === 'insert' && $change_behavior & self::ALLOW_DEFAULTS_ON_INSERT)
            || ($action === 'update' && $change_behavior & self::ALLOW_DEFAULTS_ON_UPDATE);

        // Check requiredness for reference field, and create a default object
        // if it's missing (where defined. Defaults are unlikely to ever be
        // needed but still... they're possible.)
        foreach ($this->propertyDefinitions['objects'] as $ref_name => $object_properties) {
            if (!is_array($object_properties)) {
                throw new UnexpectedValueException("'{$this->getType()}' object has a non-array definition for object '$ref_name'.");
            }

            // The structure of this code is equivalent to validateFields()
            // except a null default value means 'no default available' instead
            // of 'a default of null'. (And the code is simplified by the fact
            // that reference field values are either objects or not present.)
            $default_available = $defaults_allowed && isset($object_properties['default']);
            $validate_required_value = !empty($object_properties['required'])
                && $action === 'insert'
                && ($validation_behavior & self::VALIDATE_REQUIRED
                    || ($object_properties['required'] === 1 && $validation_behavior & self::VALIDATE_ESSENTIAL));
            // Flag an error if we have no ref-field value and no default.
            if ($validate_required_value && !isset($element['Objects'][$ref_name]) && !$default_available) {
                $name_and_alias = "'$ref_name'" . (isset($object_properties['alias']) ? " ({$object_properties['alias']})" : '');
                $element['*errors']["Objects:$ref_name"] = "No value provided for required embedded object $name_and_alias.";
            } else {
                // Set default if value is missing, or if value is null and
                // field is required (and if we are allowed to set it, but
                // that's always the case if $default_available).
                if ($default_available && !isset($element['Objects'][$ref_name])) {
                    $element['Objects'][$ref_name] = $this->getObject($ref_name, $element_index, true);
                }

                if (isset($element['Objects'][$ref_name])) {
                    // Replace UpdateObject with validated array representation.
                    try {
                        $object_value = $this->validateObjectValue($element['Objects'][$ref_name], $ref_name, $change_behavior, $validation_behavior);
                        if ($object_value) {
                            $element['Objects'][$ref_name] = ['Element' => $object_value];
                        } else {
                            unset($element['Objects'][$ref_name]);
                        }
                    } catch (InvalidArgumentException $e) {
                        // This message may contain several errors separated by
                        // newlines.
                        $element['*errors']["Objects:$ref_name"] = $e->getMessage();
                    }
                }
            }
        }

        // It's possible that $element contained objects but they were all
        // 'empty'; in that case, unset 'Objects'.
        if (isset($element['Objects']) && empty($element['Objects'])) {
            unset($element['Objects']);
        }

        return $element;
    }

    /**
     * Validates an element's fields against a list of property definitions.
     *
     * This calls validateFieldValue() which is meant to validate 'standalone'
     * field values; the code in this method is meant to validate things that
     * make sense in the context of the full element, like default / required
     * values for fields that don't have a value.
     *
     * This is mainly split out from validateElement() to be easier to override
     * by child classes. It should generally not touch $this->elements. We
     * can assume '*errors' has been initialized and all embedded objects
     * (except those with validation errors) have been validated and replaced
     * by array structures (so this method should  not be called in cases where
     * this is not true).
     *
     * @param array $element
     *   The element (usually the single one contained in $this->elements)
     *   whose fields should be validated.
     * @param int|string $element_index
     *   The index of the element in our object data; usually there is one
     *   element and the index is 0.
     * @param int $change_behavior
     *   (Optional) see output().
     * @param int $validation_behavior
     *   (Optional) see output().
     *
     * @return array
     *   The element with its fields validated, and changed if appropriate.
     *   Validation errors are stored as an array under the '*errors' key,
     *   further keyed by 'Fields:<name>'.
     *
     * @throws \UnexpectedValueException
     *   If property definitions are invalid and validation could not be done.
     */
    protected function validateFields(array $element, $element_index, $change_behavior = self::DEFAULT_CHANGE, $validation_behavior = self::DEFAULT_VALIDATION)
    {
        $action = $this->getAction($element_index);
        $defaults_allowed = ($action === 'insert' && $change_behavior & self::ALLOW_DEFAULTS_ON_INSERT)
            || ($action === 'update' && $change_behavior & self::ALLOW_DEFAULTS_ON_UPDATE);

        foreach ($this->propertyDefinitions['fields'] as $name => $field_properties) {
            if (!is_array($field_properties)) {
                throw new UnexpectedValueException("'{$this->getType()}' object has a non-array definition for field '$name'.");
            }

            // If the field is defined as an ID field that is assigned by AFAS
            // (on insert), then validate it. This is the same behavior that
            // AFAS has implemented the per-type '@xxId' properties for, so it
            // is unclear why objects sometimes have regular fields that seem
            // to behave just like such an ID property... But it seems this is
            // the case and AFAS is inconsistent in their schema definitions.
            if (
                isset($field_properties['behavior']) && $field_properties['behavior'] === 'afas_assigned_id'
                && $validation_behavior & self::VALIDATE_ESSENTIAL
            ) {
                // This code is equivalent to the code in validateElements for
                // the ID property.
                if (isset($element['Fields'][$name])) {
                    if (!is_int($element['Fields'][$name]) && !is_string($element['Fields'][$name])) {
                        $name_and_alias = "'$name'" . (isset($field_properties['alias']) ? " ({$field_properties['alias']})" : '');
                        $element['*errors']["Fields:$name"] = "$name_and_alias field must hold integer/string value.";
                    }
                } else {
                    // If action is "insert", we are guessing that there
                    // usually isn't, but still could be, a value for the ID
                    // field; it depends on 'auto numbering' for this object
                    // type. We don't validate this. (Yet?) We do validate that
                    // there's an ID value if action is different than "insert".
                    $action = $this->getAction($element_index);
                    if ($action !== 'insert') {
                        $name_and_alias = "'$name'" . (isset($field_properties['alias']) ? " ({$field_properties['alias']})" : '');
                        $element['*errors']["Fields:$name"] = "$name_and_alias field must have a value, or Action '$action' must be set to 'insert'.";
                    }
                }
            }

            // Check required fields and add default values for fields (where
            // defined). About definitions:
            // - if no field value is set and a default is available, the
            //   default is returned.
            // - if required = true, then
            //   - if no field value is set and no default is available, an
            //     exception is thrown.
            //   - if the field value is null, an exception is thrown, unless
            //     the default value is also null. (We don't silently overwrite
            //     null values which were explicitly set, with a different
            //     default.)
            // - if the default is null (or the field value is null and the
            //   field is not required) then null is returned.
            $default_available = $defaults_allowed && array_key_exists('default', $field_properties);
            // Requiredness is only checked for action "insert"; the
            // VALIDATE_ESSENTIAL bit does not change that. (So far, we've only
            // seen 'required on update' situations only for fields which are
            // not proper fields, and those situations are solved in custom
            // code in child classes.)
            $validate_required_value = !empty($field_properties['required'])
                && $action === 'insert'
                && ($validation_behavior & self::VALIDATE_REQUIRED
                    || ($field_properties['required'] === 1 && $validation_behavior & self::VALIDATE_ESSENTIAL));
            // See above: flag an error if we have no-or-null field value and
            // no default, OR if we have null field value and non-null default.
            // (Note the array_key_exists() implies "is null".)
            if (
                $validate_required_value && !isset($element['Fields'][$name])
                && (!$default_available
                    || (isset($element['Fields']) && array_key_exists($name, $element['Fields'])
                        && $field_properties['default'] !== null))
            ) {
                $name_and_alias = "'$name'" . (isset($field_properties['alias']) ? " ({$field_properties['alias']})" : '');
                $element['*errors']["Fields:$name"] = "No value provided for required $name_and_alias field.";
            } else {
                // Set default if value is missing, or if value is null and
                // field is required (and if we are allowed to set it, but
                // that's always the case if $default_available).
                if (
                    $default_available
                    && (!isset($element['Fields']) || !array_key_exists($name, $element['Fields'])
                        || !empty($field_properties['required']) && $element['Fields'][$name] === null)
                ) {
                    // Support dynamic default value of "today" for date
                    // fields; convert it to yyyy-mm-dd today. (We might also
                    // be able to dynamically convert the value "today" set by
                    // the user, but let's not do that unless it has a proven
                    // value.)
                    if (
                        isset($field_properties['type']) && $field_properties['type'] === 'date'
                        && $field_properties['default'] === 'today'
                    ) {
                        $element['Fields'][$name] = date('Y-m-d');
                    } else {
                        $element['Fields'][$name] = $field_properties['default'];
                    }
                }

                if (isset($element['Fields'][$name])) {
                    try {
                        $element['Fields'][$name] = $this->validateFieldValue($element['Fields'][$name], $name, $change_behavior, $validation_behavior, $element_index, $element);
                    } catch (InvalidArgumentException $e) {
                        $element['*errors']["Fields:$name"] = $e->getMessage();
                    }
                }
            }
        }

        return $element;
    }

    /**
     * Validates the value for an element's embedded object.
     *
     * @param mixed $value
     *   The value, which is supposed to be an UpdateObject.
     * @param string $reference_field_name
     *   The name of the reference field for the object.
     * @param int $change_behavior
     *   (Optional) see output(). Note that this is the behavior for our
     *   element and may still need to be amended to apply to embedded objects.
     * @param int $validation_behavior
     *   (Optional) see output().
     *
     * @return array
     *   A representation of the object's contents: either a single element, or
     *   an array of one or more elements, depending on the 'multiple' property
     *   definition for the corresponding reference field.
     *
     * @throws \InvalidArgumentException
     *   If the element data does not pass validation.
     */
    protected function validateObjectValue($value, $reference_field_name, $change_behavior = self::DEFAULT_CHANGE, $validation_behavior = self::DEFAULT_VALIDATION)
    {
        if (!$value instanceof UpdateObject) {
            $name_and_alias = "'$reference_field_name'" . (isset($this->propertyDefinitions['objects'][$reference_field_name]['alias']) ? " ({$this->propertyDefinitions['objects'][$reference_field_name]['alias']})" : '');
            throw new InvalidArgumentException("Embedded object $name_and_alias must be an object of type UpdateObject.");
        }

        // Validation of a full object is done by getElements().
        $embedded_change_behavior = $change_behavior & self::ALLOW_EMBEDDED_CHANGES
            ? $change_behavior : self::ALLOW_NO_CHANGES;
        try {
            $elements = $value->getElements($embedded_change_behavior, $validation_behavior);
        } catch (UnexpectedValueException $e) {
            // getElements() throws an UnexpectedValueException rather than
            // passing '*errors' back, because it's public. The message can
            // contain multiple errors separated by newlines. Other messages in
            // this method (just like validateFieldValue()) mention the field
            // name, so we should too. So we catch the error and add it to
            // every line.
            $error_prefix = "object-ref $reference_field_name: ";
            throw new InvalidArgumentException($error_prefix . implode("\n$error_prefix", explode("\n", $e->getMessage())));
        }

        // Validate whether this reference field is allowed to have multiple
        // elements. Also, decide the exact structure of "Elements" in the
        // embedded objects. I'm making assumptions here because I don't have
        // real specifications from AFAS, or a means to test this:
        // - Their own knowledge base examples (for UpdateConnector which use
        //   KnSubject) specify a single element inside the "Element" key, e.g.
        //   "@SbId" and "Fields" are directly inside "Element".
        // - That clearly doesn't work when multiple elements need to be
        //   embedded as part of the same reference field, e.g. a FbSales
        //   entity can have multiple elements inside the FbSalesLines object.
        //   In this case its "Element" key contains an array of elements.
        // This class has the FLATTEN_SINGLE_ELEMENT bit for output() so the
        // caller can decide what to do with 'main' objects. (By default, one
        // element is 'flattened' because see first point above, but multiple
        // elements are supported.) For embedded objects, I officially do not
        // know if AFAS accepts an array inside "Elements" for _any_ field. So
        // we ignore the FLATTEN_SINGLE_ELEMENT bit here and let the 'multiple'
        // property of the reference field drive what happens:
        // - For reference fields that can embed multiple elements, we keep the
        //   array, regardless whether we have one or more elements at the
        //   moment. (This to keep the structure of a particular reference
        //   field consistent; I do not imagine AFAS will deny an array of 1
        //   elements.)
        // - For reference fields that can only embed one element, we unwrap
        //   this array and place the element directly inside "Element"
        //   (because I do not know if AFAS accepts an array).
        if (empty($this->propertyDefinitions['objects'][$reference_field_name]['multiple'])) {
            if (count($elements) == 1) {
                $elements = reset($elements);
            } elseif ($elements && $validation_behavior & self::VALIDATE_ESSENTIAL) {
                $name_and_alias = "'$reference_field_name'" . (isset($this->propertyDefinitions['objects'][$reference_field_name]['alias']) ? " ({$this->propertyDefinitions['objects'][$reference_field_name]['alias']})" : '');
                throw new InvalidArgumentException("Embedded object $name_and_alias contains " . count($elements) . ' elements but can only contain a single element.');
            }
        }

        return $elements;
    }

    /**
     * Validates the value for an element's field.
     *
     * This method is used both on 'input into' and 'output from' an element
     * (e.g. setField() / addElements() and validateFields() call it). It is
     * supposed to be called only with field names/aliases that we know to
     * exist for this object type. If an extending class implements validation
     * that depends on other values inside this element: that is allowed but
     * can get fussy / makes assumptions about how this method is called; see
     * $element parameter.
     *
     * @param mixed $value
     *   A scalar value which is (going to be) assigned to an element's field.
     * @param string $field_name
     *   The name of the field.
     * @param int $change_behavior
     *   (Optional) see output().
     * @param int $validation_behavior
     *   (Optional) see output().
     * @param int|string $element_index
     *   The index that the element which the object will be embedded into, has
     *   or will have. When called from add/setElement(s), the element with the
     *   specified index does/may not exist yet.
     * @param array $element
     *   (Optional) The full element being validated, for the benefit of
     *   validation checks which depend on other values. (If the full element
     *   is equal to $this->elements[index]... just pass that if possible, to
     *   make sure that those validation checks can be performed. It is often
     *   not equal to $this->elements[index] because the element may change
     *   during validation.) This argument is fussy: callers looping over
     *   fields while calling this function should do this in a well defined
     *   order, for the benefit of validation checks. Child classes extending
     *   this method should be aware of its limitations which we'll illustrate
     *   by the standard callers in this class:
     *   - When called while an element is being populated, we cannot assume
     *     all other fields have been populated yet. (validateElementInput()
     *     does this in the order in which fields occur in propertyDefinitions.)
     *     We also cannot assume any fields have been validated. (That depends
     *     on 'validation behavior' when those fields were populated.)
     *   - When called by setField(), we cannot assume other fields have been
     *     validated; see previous point.
     *   - When called while an element is being validated through
     *     getElements() -> validateElement(): we cannot assume all other
     *     fields have been validated (see first point about field order);
     *     individual objects in the 'objects' array are replaced by arrays:
     *     the return value of validateObjectValue() keyed by 'Element'.
     *
     * @return mixed
     *   The validated, and possibly changed if appropriate, value.
     *
     * @throws \InvalidArgumentException
     *   If the value does not pass validation.
     */
    protected function validateFieldValue($value, $field_name, $change_behavior = self::DEFAULT_CHANGE, $validation_behavior = self::DEFAULT_VALIDATION, $element_index = null, array $element = null)
    {
        // No validation for null values. (Requiredness is validated elsewhere.)
        if (isset($value)) {
            if ($validation_behavior & self::VALIDATE_ESSENTIAL) {
                try {
                    if (!is_scalar($value)) {
                        throw new InvalidArgumentException("%NAME field value must be scalar.");
                    }

                    if (!empty($this->propertyDefinitions['fields'][$field_name]['type'])) {
                        switch ($this->propertyDefinitions['fields'][$field_name]['type']) {
                            case 'integer':
                            case 'decimal':
                                if (!is_numeric($value)) {
                                    throw new InvalidArgumentException("%NAME field value is not numeric.");
                                }
                                if ($this->propertyDefinitions['fields'][$field_name]['type'] === 'integer' && strpos((string)$value, '.') !== false) {
                                    throw new InvalidArgumentException("%NAME field value is not a valid integer value.");
                                }
                                // @todo check digits for decimal, if/when we know that's necessary.
                                break;

                            case 'boolean':
                                // In this general class we'll be a bit lenient
                                // about what we accept as boolean, but we
                                // won't accept and silently convert just any
                                // string/number. Let's accept 0 / 1 / -1 too.
                                if (!is_bool($value)) {
                                    $valid = false;
                                    if (is_string($value) && in_array(strtolower($value), ['true', 'false'], true)) {
                                        $valid = true;
                                    }
                                    if (is_numeric($value) && in_array($value, [0, 1, -1])) {
                                        $valid = true;
                                    }
                                    if (!$valid) {
                                        throw new InvalidArgumentException("%NAME field value is not a valid boolean value.");
                                    }
                                }
                                break;

                            case 'email':
                                if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                                    throw new InvalidArgumentException("%NAME field value is not a valid e-mail address.");
                                }
                                break;
                        }
                    }
                } catch (InvalidArgumentException $e) {
                    // Catch and rethrow because we don't feel like repeatedly
                    // defining the element & field name constructions above.
                    $name_and_alias = "'$field_name'" . (isset($this->propertyDefinitions['fields'][$field_name]['alias']) ? " ({$this->propertyDefinitions['fields'][$field_name]['alias']})" : '');
                    throw new InvalidArgumentException(str_replace('%NAME', $name_and_alias, $e->getMessage()));
                }
            }

            // Trim value, or change its type, if allowed. Notes:
            // - This isn't done on 'input' (from setField() / addElements());
            //   values are only guaranteed to be converted cast to their
            //   correct type during 'output' validation (e.g. output() /
            //   getElements() if the ALLOW_REFORMAT flag is set.
            // - If VALIDATE_ESSENTIAL was not set on 'input' and not on
            //   'output' either, that can mean data loss when invalid values
            //   are converted. We won't guard against that; changing fields
            //   without validating them first (which is only possible by
            //   passing non-default arguments on both 'input' and 'output') is
            //   asking for trouble.
            if (is_scalar($value) && $change_behavior & self::ALLOW_REFORMAT) {
                if (!empty($this->propertyDefinitions['fields'][$field_name]['type'])) {
                    switch ($this->propertyDefinitions['fields'][$field_name]['type']) {
                        case 'boolean':
                            $value = (bool)$value;
                            break;

                        case 'decimal':
                            $value = (float)$value;
                            break;

                        case 'integer':
                            $value = (int)$value;
                            break;

                        case 'string':
                            $value = trim($value);
                    }
                    // @todo format dates in standard way, if/when we know that's necessary.
                } elseif (is_string($value)) {
                    $value = trim($value);
                }
            }
        }

        return $value;
    }

    /**
     * Outputs the object data as a string.
     *
     * One thing may be noteworthy: the 'action' value for each element can
     * have an effect on which fields are included in the output, but the value
     * itself is not present in JSON output - though it is in XML output. This
     * ties in with the fact that the REST API does inserts and updates through
     * separate API actions (HTTP verbs), while this distinction does not
     * exist for SOAP. This also means it's supposedly possible to update and
     * insert different objects in a single SOAP call, but not in a REST call.
     *
     * @param string $format
     *   (Optional) The format; 'json' (default) or 'xml'.
     * @param array $format_options
     *   (Optional) Options influencing the format. General options:
     *   - 'pretty' (boolean): If true, pretty-print (with newlines/spaces).
     *   See outputXml() for XML specific options.
     * @param int $change_behavior
     *   (Optional) By default, this method can change values of fields and
     *   embedded objects (for e.g. uniform formatting of values ar adding
     *   defaults). This argument is a bitmask that can influence which data
     *   can be changed. Possible values are:
     *   - ALLOW_NO_CHANGES: Do not allow any changes. This value loses its
     *     meaning when passed together with other values.
     *   - ALLOW_EMBEDDED_CHANGES (default): Allow changes to be made to
     *     embedded objects. If it is specified, the other bits determine which
     *     changes can be made to embedded objects (so if only this value is
     *     specified, no changes are allowed to either the elements in this
     *     object or their embedded objects). If it is not specified, the other
     *     bits determine only which changes can be made to elements in this
     *     object, but any changes to embedded objects are disallowed.
     *   - ALLOW_DEFAULTS_ON_INSERT (default): Allow adding default values to
     *     empty fields when inserting a new element.
     *   - ALLOW_DEFAULTS_ON_UPDATE: Allow adding default values to empty
     *     fields when updating an existing element. (This one might not be
     *     useful in practice...)
     *   - ALLOW_REFORMAT (default): Allow reformatting of singular field
     *     values. For 'reformatting' a combination of values (e.g. moving
     *     a house number from a street value into its own field) additional
     *     values may need to be passed.
     *   - ALLOW_CHANGES: Allow changing field values, in ways not covered by
     *     other bits. Behavior is not precisely defined by this class; child
     *     classes may use this value or implement their own additional bits.
     *   - FLATTEN_SINGLE_ELEMENT (default): If this object holds only one
     *     element, then output this single element inside the "Element"
     *     section rather than an array containing one element. (This bit only
     *     has effect for calls to output(), unlike other bits which have
     *     effect for every call that has a $change_behavior argument. It only
     *     applies to JSON output and is not expected to make a difference to
     *     AFAS though we're not 100% sure. It is not passed into embedded
     *     objects; those have hardcoded logic around 'flattening', in
     *     validateObjectValue().)
     *   Child classes might define additional values.
     * @param int $validation_behavior
     *   (Optional) By default, this method performs validation checks. This
     *   argument is a bitmask that can be used to disable validation checks (or
     *   add additional ones in child classes). Possible values are:
     *   - VALIDATE_NOTHING: Perform no validation checks at all.
     *   - VALIDATE_ESSENTIAL (default): Perform checks that we want to always
     *     be performed. These are checks on whether a value is of the proper
     *     data type (which are by default performed both while setting values
     *     into this class and on output). Another possible example is values
     *     required by AFAS, where AFAS will return an unhelpful error message
     *     if these are not provided.
     *   - VALIDATE_REQUIRED (default): Check for presence of field values which
     *     this library considers 'required'; this may be the case even if an
     *     AFAS Update Connector call would not fail if they're missing.
     *     Example: town/municipality in an address element.
     *   - VALIDATE_NO_UNKNOWN (default): Check if all fields and objects
     *     (reference fields) are known in our 'properties' definition, and if
     *     no unknown other properties (on the same level as 'Fields' and
     *     'Objects') exist. If this option is turned off, this may cause
     *     unknown values to be included in the output, with uncertain results
     *     (depending on how the AFAS API treats these).
     *   - VALIDATE_FORMAT: Check if fields are formatted in a certain way.
     *     (This is 'off' by default. The only fields affected at the moment
     *     are in a subclass.)
     *
     * @return string
     *   The string representation of the object data, validated and possibly
     *   modified according to the arguments passed.
     *
     * @throws \InvalidArgumentException
     *   If any of the arguments have an unrecognized value.
     * @throws \UnexpectedValueException
     *   If this object's data does not pass validation. (This likely indicates
     *   the data is invalid, although in theory it can also indicate that the
     *   validation is based on improper logic/definitions.)
     */
    public function output($format = 'json', array $format_options = [], $change_behavior = self::DEFAULT_CHANGE, $validation_behavior = self::DEFAULT_VALIDATION)
    {
        // Disallow outputting objects which are embedded inside other objects.
        // This is slightly arbitrary because getElements() is allowed and the
        // output could be valid in itself... but there is a potential for
        // generating wrong data (and lots of resulting confusion) because
        // property definitions may be different, which could cause issues with
        // generated output being sent to AFAS, if the parent type is set.
        if ($this->parentType) {
            throw new UnexpectedValueException('This object is embedded inside another object, so it cannot be converted to a standalone output string. See getObject(,,,true).');
        }
        if (!is_int($change_behavior)) {
            throw new InvalidArgumentException('$change_behavior argument is not an integer.');
        }
        if (!is_int($validation_behavior)) {
            throw new InvalidArgumentException('$validation_behavior argument is not an integer.');
        }
        if (!is_string($format)) {
            throw new InvalidArgumentException('Invalid format.');
        }

        // The 'flatten' bit only makes sense for JSON output, and only inside
        // this method. (For embedded elements, 'flattening' is governed by a
        // reference field property instead; see validateObjectValue().)
        $flatten = $change_behavior & self::FLATTEN_SINGLE_ELEMENT;
        $change_behavior = $change_behavior & ~self::FLATTEN_SINGLE_ELEMENT;
        // The 'renumber indexes' bit is forced because we never want to
        // preserve array keys to $this->elements; those would output a JSON
        // object to be output instead of an array.
        $elements = $this->getElements($change_behavior | self::RENUMBER_ELEMENT_INDEXES, $validation_behavior);
        // We disallow sending an empty value (i.e. zero elements) into AFAS.
        // (For the rest we allow anything, also e.g. an object with only ID.)
        if (empty($elements)) {
            // Strictly speaking, this is "no non-empty elements" because empty
            // ones are allowed in our internal structure, but filtered.
            throw new UnexpectedValueException("Object holds no elements.");
        }

        switch (strtolower($format)) {
            case 'json':
                if ($flatten && count($elements) == 1) {
                    $elements = reset($elements);
                }
                // The JSON structure should have two one-element wrappers
                // around the element data, with keys being the object type
                // and 'Element'. (Embedded objects have the 'Element' wrapper
                // added by validateReferenceFields().)
                $data = [$this->getType() => ['Element' => $elements]];
                /** @noinspection PhpComposerExtensionStubsInspection */
                return empty($format_options['pretty']) ? json_encode($data) : json_encode($data, JSON_PRETTY_PRINT);

            case 'xml':
                $line_prefix = '';
                if (!empty($format_options['line_prefix']) && is_string($format_options['line_prefix'])) {
                    $line_prefix = $format_options['line_prefix'];
                }
                $pretty = !empty($format_options['pretty']) && (!isset($format_options['indent'])
                        || (is_int($format_options['indent']) && $format_options['indent'] >= 0));
                if ($pretty) {
                    // We want to indent all the outputXml() output because
                    // we're generating the XML 'wrapper' here.
                    $indent = isset($format_options['indent']) ? $format_options['indent'] : 2;
                    $format_options['line_prefix'] = $line_prefix . str_repeat(' ', $indent);
                }
                return $line_prefix . "<{$this->getType()} xmlns:xsi=\"http://www.w3.org/2001/XMLSchema-instance\">"
                    . $this->outputXml($elements, $format_options)
                    . "\n$line_prefix</{$this->getType()}>";

            default:
                throw new InvalidArgumentException("Invalid format '$format'.");
        }
    }

    /**
     * Encode data as XML, suitable for sending through SOAP connector.
     *
     * @param array $elements
     *   The validated elements. Note we still call outputXml() in any embedded
     *   objects recursively but only to have access to each object's
     *   'actions' (and the 'id_property' definition) even though we do not use
     *   / validate the objects' element values anymore. (If we did, we could
     *   miss some effects of having validated the full elements including
     *   embedded objects.)
     * @param array $format_options
     *    (Optional) Options influencing the format:
     *   - 'pretty' (boolean): If true, pretty-print (with newlines/spaces).
     *     Start with a newline, to concatenate to the header tag which is not
     *     this method's responsibility.
     *   - 'indent' (integer): The number of spaces to prefix an indented line
     *     with; 2 by default. 'pretty' effect is canceled if this is not an
     *     integer or < 0.
     *   - 'line_prefix' (string): a prefix to start each line with.
     *
     * @return string
     *   XML payload to send to an Update Connector on a SOAP API/Connection,
     *   excluding the XML start/end tag.
     */
    protected function outputXml(array $elements, array $format_options = [])
    {
        $xml = $indent_str1 = $indent_str2 = $indent_str3 = '';
        $pretty = !empty($format_options['pretty']) && (!isset($format_options['indent'])
                || (is_int($format_options['indent']) && $format_options['indent'] >= 0));
        if ($pretty) {
            $indent = isset($format_options['indent']) ? $format_options['indent'] : 2;
            $extra_spaces = str_repeat(' ', $indent);
            // LF + Indentation before Element tag:
            $indent_str1 = "\n$format_options[line_prefix]";
            // LF + Indentation before Fields/Objects tag:
            $indent_str2 = $indent_str1 . $extra_spaces;
            // LF + Indentation before individual field values:
            $indent_str3 = $indent_str2 . $extra_spaces;
        }

        // Fully validate the element(s) in this object, even though we won't
        // need the embedded objects for generating output. This means that we
        // validate all embedded objects multiple times (here and while
        // generating the output); objects that are 2 levels deep even get
        // validated 3 times. But it's the only way to validate field values
        // which depend on values in embedded objects (and stick to the 'design
        // decision' to validate embedded objects before object fields). Never
        // flatten the return value, otherwise we can't foreach().
        foreach ($elements as $element_index => $element) {
            $action_attribute = '';
            $action = $this->getAction($element_index);
            if ($action) {
                // Add the Action attribute only if it's explicitly specified.
                // (This method makes no assumptions about what it does or
                // whether its behavior affects embedded objects; it only
                // assumes that "" is not a valid Action value.)
                $action_attribute = ' Action="' . $action . '"';
            }

            // Requiredness of the ID field and validity of its value has been
            // checked elsewhere; we just include it if it's there.
            $id_attribute = '';
            if (!empty($this->propertyDefinitions['id_property'])) {
                if (!is_string($this->propertyDefinitions['id_property'])) {
                    throw new UnexpectedValueException("'id_property' definition in '{$this->getType()}' object is not a string value.");
                }
                if (isset($element['@' . $this->propertyDefinitions['id_property']])) {
                    $id_attribute = ' ' . $this->propertyDefinitions['id_property'] . '="' . $element['@' . $this->propertyDefinitions['id_property']] . '"';
                }
            }
            // Each element is in its own 'Element' tag (unlike the input
            // argument which has an array of elements in one 'Element' key,
            // because multiple 'Element' keys cannot exist in one object or
            // JSON string).
            $xml .= "$indent_str1<Element$id_attribute>";

            // Always add Fields tag, also if it's empty. (No idea if that's
            // necessary, but that's apparently how I tested it 5 years ago.)
            $xml .= "$indent_str2<Fields$action_attribute>";
            foreach ($element['Fields'] as $name => $value) {
                if (is_bool($value)) {
                    // Boolean values are encoded as 0/1 in AFAS XML.
                    $value = $value ? '1' : '0';
                }
                $xml .= $indent_str3 . (isset($value)
                        ? "<$name>" . htmlspecialchars($value, ENT_QUOTES | ENT_XML1) . "</$name>"
                        // Value is passed but null or default value is null
                        : "<$name xsi:nil=\"true\"/>");
            }
            $xml .= "$indent_str2</Fields>";

            // $element contains all embedded objects' array representations,
            // but we need to call the UpdateObject's outputXml() to be able
            // to insert the "action" attribute, if that's necessary.
            if (!empty($element['Objects'])) {
                $xml .= "$indent_str2<Objects>";
                if ($pretty) {
                    /** @noinspection PhpUndefinedVariableInspection */
                    $format_options['line_prefix'] = (empty($format_options['line_prefix']) ? '' : $format_options['line_prefix'])
                        . str_repeat(' ', $indent * 3);
                }
                // Iterating over the keys in 'Objects' is fine; it contains
                // all object values we need including defaults. (No need to
                // recheck the property definitions here.) We also know
                // getObject() should never return null for these.
                foreach ($element['Objects'] as $ref_name => $value) {
                    // Embedded objects have been 'flattened' according to
                    // their definition, so unflatten them.
                    $object = empty($this->propertyDefinitions['objects'][$ref_name]['multiple']) ? [$value['Element']] : $value['Element'];
                    $xml .= "$indent_str3<$ref_name>"
                        . $this->getObject($ref_name, $element_index, true)->outputXml($object, $format_options)
                        . "$indent_str3</$ref_name>";
                }
                $xml .= "$indent_str2</Objects>";
            }

            $xml .= "$indent_str1</Element>";
        }

        return $xml;
    }

    /**
     * Resets property definitions in this class instance.
     *
     * This means setting all currently known definitions including overrides.
     * This is the way to e.g. apply definition overrides that were set after
     * this object was instantiated, to this instance anyway.
     */
    public function resetPropertyDefinitions()
    {
        // Set the default definitions, then override them. This is not the
        // recommended way of doing things; it's only done for the benefit of
        // child classes that might have  overridden setPropertyDefinitions().
        // @todo once the deprecated setPropertyDefinitions() gets removed:
        // $this->propertyDefinitions = $this->mergeDefinitionOverrides($this->getDefaultPropertyDefinitions();
        $this->setPropertyDefinitions();
        $this->propertyDefinitions = $this->mergeDefinitionOverrides($this->propertyDefinitions);
    }

    /**
     * Produces property definitions with the overrides merged into them.
     *
     * Class properties 'propertyDefinitions' and 'type' must already be set.
     *
     * @param array $definitions
     *   (Optional) The default definitions which the overrides should be
     *   merged into. If this argument is not passed, the class' current
     *   propertyDefinitions is taken. However please pass them explicitly;
     *   this parameter may become non-optional in the future.
     *
     * @return array
     *   The overridden definitions.
     *
     * @throws \UnexpectedValueException
     *   If any error is encountered.
     */
    protected function mergeDefinitionOverrides($definitions = [])
    {
        if (!$definitions && $this->propertyDefinitions) {
            $definitions = $this->propertyDefinitions;
        }
        $type = $this->getType();
        if (!$type) {
            throw new UnexpectedValueException('Object type is not set.');
        }

        if (!isset(self::$definitionOverrides[$type])) {
            $definition_overrides = [];
        } elseif (!is_array(self::$definitionOverrides[$type])) {
            throw new UnexpectedValueException("Definition overrides for '$type' is not an array.");
        } else {
            $definition_overrides = self::$definitionOverrides[$type];
        }

        // Validate the overrides for 'fields' and 'objects'; change or unset
        // definitions where necessary.
        try {
            static::validateDefinitionOverrides($definition_overrides);
        } catch (InvalidArgumentException $e) {
            throw new UnexpectedValueException($e->getMessage());
        }

        foreach (['fields', 'objects'] as $subtype) {
            if (isset($definition_overrides[$subtype])) {
                // Each field definition must be an array or null. Merge or
                // unset depending on the value.
                foreach ($definition_overrides[$subtype] as $name => $value) {
                    if (isset($value)) {
                        $definitions[$subtype][$name] = $value;
                    } else {
                        unset($definitions[$subtype][$name]);
                    }
                }
                // Validation functions check if the 'fields' property is set,
                // even if it's an empty array. Since we support setting
                // (full definitions, using) overrides for types that do not
                // have existing standard definitions... we must take care to
                // always set 'fields' in that case.
                if ($subtype === 'fields' && empty($definition_overrides[$subtype]) && !isset($definitions[$subtype])) {
                    // This means 'fields' is an empty array. Set it.
                    $definitions[$subtype] = $definition_overrides[$subtype];
                }
            }
        }

        // Change or unset the outer properties except 'fields' and 'objects'.
        // Value does not need to be an array.
        foreach ($definition_overrides as $name => $value) {
            if ($name !== 'fields' && $name !== 'objects') {
                if (isset($value)) {
                    $definitions[$name] = $value;
                } else {
                    unset($definitions[$name]);
                }
            }
        }

        // Handle individual field overrides.
        if (isset(self::$fieldOverrides[$type])) {
            if (!is_array(self::$fieldOverrides[$type])) {
                throw new UnexpectedValueException("Field overrides for '$type' is not an array.");
            }
            foreach (self::$fieldOverrides[$type] as $field => $override) {
                if (!is_array($override)) {
                    throw new UnexpectedValueException("Field overrides for '$type/$field' is not an array.");
                }
                foreach ($override as $property => $value) {
                    $definitions['fields'][$field][$property] = $value;
                }
            }
        }

        return $definitions;
    }

    /**
     * Sets default property definitions into the class variable.
     *
     * @deprecated will be removed in a future version.
     *   Use getDefaultPropertyDefinitions().
     */
    protected function setPropertyDefinitions()
    {
        $this->propertyDefinitions = $this->getDefaultPropertyDefinitions();
    }

    /**
     * Returns the property definitions, without overrides from static setters.
     *
     * @return array
     *   The definitions.
     *
     * @see UpdateObject::$propertyDefinitions
     */
    protected function getDefaultPropertyDefinitions()
    {
        // Below definitions are based on what AFAS calls the 'XSD Schema' for
        // SOAP, retrieved though a Data Connector in november 2014. They're
        // amended with extra info like more understandable aliases for the
        // field names, and default values. There are lots of Dutch comment
        // lines in this function; these were gathered from an online knowledge
        // base page around 2012 when that was the only form / language of
        // documentation.
        switch ($this->type) {
            case 'FbSubscription':
                return [
                    'objects' => [
                        'FbSubscriptionLines' => [
                            'alias' => 'line_items',
                            'multiple' => true,
                        ],
                    ],
                    'fields' => [
                        'BcId' => [
                            'alias' => 'organisation_id',
                            'required' => true,
                        ],
                        'CtPe' => [
                            'alias' => 'contact_id',
                            'required' => true,
                        ],
                        'DbId' => [
                            'alias' => 'debtor_id',
                            'required' => true,
                        ],
                        'SuSt' => [
                            'alias' => 'order_date',
                            'type' => 'date',
                            'required' => true,
                        ],
                        'VaIn' => [],
                        'VaRe' => [],
                        'DaRe' => [],
                    ],
                ];

            case 'FbSubscriptionLines':
                return [
                    'fields' => [
                        // (This is apparently not an 'ID property', which
                        // we'll assume a FbSubscriptionLines object doesn't
                        // have then. Why not? what is the difference?)
                        'Id' => [
                            'alias' => 'guid',
                            'behavior' => 'afas_assigned_id',
                        ],
                        'ItCd' => [
                            'alias' => 'item_code',
                        ],
                        'VaIt' => [
                            'alias' => 'item_type',
                        ],
                        'Qu' => [
                            'alias' => 'quantity',
                            'type' => 'integer',
                        ],
                        'DaSt' => [
                            'alias' => 'start_date',
                            'type' => 'date',
                            'required' => true,
                        ],
                        'DaPu' => [
                            'alias' => 'start_date',
                            'type' => 'date',
                            'required' => true,
                            // The default "today" for a date gets converted to yyyy-mm-dd.
                            'default' => 'today',
                        ],
                    ],
                ];

            case 'KnCourseMember':
                // I'm  not sure what the exact usage is. It might be that
                // SuDa needs a default of today, but only if it's the date of
                // registration and not the date of the course. And in that
                // case, I'd expect there to be a KnCourseEvent type which has
                // the course date... but we don't have that defined yet.
                return [
                    'fields' => [
                        'BcCo' => [
                            'alias' => 'organisation_code',
                            'required' => true,
                        ],
                        'DeId' => [
                            'alias' => 'debtor_id',
                        ],
                        'CrId' => [
                            'alias' => 'event_id',
                        ],
                        'CdId' => [
                            'alias' => 'contact_id',
                        ],
                        'SuDa' => [
                            'alias' => 'subscription_date',
                            'type' => 'date',
                            'required' => true,
                        ],
                        'DfPr' => [
                            'alias' => 'price',
                            'type' => 'decimal',
                        ],
                        'DiPc' => [
                            'alias' => 'discount_perc',
                        ],
                        'Invo' => [
                            'required' => true,
                        ],
                        'Rm' => [
                            'alias' => 'comment',
                        ],
                    ],
                ];

            case 'KnProvApplication':
                return [
                    'fields' => [
                        'BcCo' => [
                            'alias' => 'organisation_code',
                            'required' => true,
                        ],
                        'PvCd' => [
                            'required' => true,
                        ],
                        'PvCt' => [
                            'required' => true,
                        ],
                        'VaPt' => [
                            'required' => true,
                        ],
                    ],
                ];

            // This seems to be where the object model breaks down a bit.
            // KnSalesRelationOrg and KnSalesRelationPer clearly seem to share
            // the same 'object space'. (I suspect that only one of these can
            // have the same ID.) The same is the case for KnBasicAddressAdr
            // and KnBasicAddressPad, so I concluded that there must be one
            // object type, and named it 'KnBasicAddress'. That works because
            // addresses cannot be sent to AFAS as separate objects. Sales
            // relations can, and at least in the SOAP API must have e.g.
            // 'KnSalesRelationPer' in the header. Which is a bit surprising.
            // So until further info comes in, we define them as two types.
            case 'KnSalesRelationOrg':
            case 'KnSalesRelationPer':
                $definitions = [
                    'id_property' => 'DbId',
                    'fields' => [
                        // 'is debtor'?
                        'IsDb' => [
                            'type' => 'boolean',
                            'default' => true,
                        ],
                        // According to AFAS docs, PaCd / VaDu "are required
                        // if IsDb==True" ... no further specs.
                        // [ comment 2014: ]
                        // Heh, VaDu is not even in our inserted XML so that
                        // does not seem to be actually true.
                        'PaCd' => [
                            'default' => '14',
                        ],
                        'CuId' => [
                            'alias' => 'currency_code',
                            'default' => 'EUR',
                        ],
                        'Bl' => [
                            'default' => 'false',
                        ],
                        'AuPa' => [
                            'default' => '0',
                        ],
                        // Verzamelrekening Debiteur
                        // [ comment 2014: ] Apparently these just need to be
                        // specified by whoever is setting up the AFAS
                        // administration?
                        'ColA' => [
                            'alias' => 'coll_account',
                        ],
                        // [ comment 2014: ]
                        // ?? Doesn't seem to be required, but we're still
                        // setting default to the old value we're used to,
                        // until we know what this field means.
                        'VtIn' => [
                            'default' => '1',
                        ],
                        'PfId' => [
                            'default' => '*****',
                        ],
                    ],
                ];
                if ($this->getType() === 'KnSalesRelationOrg') {
                    $definitions['fields']['VaId'] = [
                        'alias' => 'vat_number',
                    ];
                    $definitions['objects'] = [
                        'KnOrganisation' => [
                            'alias' => 'organisation',
                        ],
                    ];
                } else {
                    $definitions['objects'] = [
                        'KnPerson' => [
                            'alias' => 'person',
                        ],
                    ];
                }
                return $definitions;

            case 'KnSubject':
                return [
                    'id_property' => 'SbId',
                    // See definition of KnS01: I'm not sure if this is correct.
                    'objects' => [
                        'KnSubjectLink' => [
                            'alias' => 'subject_link',
                        ],
                        'KnS01' => [
                            'alias' => 'subject_link_1',
                        ],
                        'KnS02' => [
                            'alias' => 'subject_link_2',
                        ],
                        // If there are more KnSNN, they have all custom fields?
                    ],
                    'fields' => [
                        // Type dossieritem (verwijzing naar: Type dossieritem => AfasKnSubjectType)
                        'StId' => [
                            'alias' => 'type',
                            'type' => 'integer',
                            'required' => true,
                        ],
                        // Onderwerp
                        'Ds' => [
                            'alias' => 'description',
                        ],
                        // Toelichting
                        'SbTx' => [
                            'alias' => 'comment',
                        ],
                        // Instuurdatum
                        'Da' => [
                            'alias' => 'date',
                            'type' => 'date',
                        ],
                        // Verantwoordelijke (verwijzing naar: Medewerker => AfasKnEmployee)
                        'EmId' => [
                            'alias' => 'responsible',
                        ],
                        // Aanleiding (verwijzing naar: Dossieritem => AfasKnSubject)
                        'SbHi' => [
                            'type' => 'integer',
                        ],
                        // Type actie (verwijzing naar: Type actie => AfasKnSubjectActionType)
                        'SaId' => [
                            'alias' => 'action_type',
                        ],
                        // Prioriteit (verwijzing naar: Tabelwaarde,Prioriteit actie => AfasKnCodeTableValue)
                        'ViPr' => [],
                        // Bron (verwijzing naar: Brongegevens => AfasKnSourceData)
                        'ScId' => [
                            'alias' => 'source',
                        ],
                        // Begindatum
                        'DtFr' => [
                            'alias' => 'start_date',
                            'type' => 'date',
                        ],
                        // Einddatum
                        'DtTo' => [
                            'alias' => 'end_date',
                            'type' => 'date',
                        ],
                        // Afgehandeld
                        'St' => [
                            'alias' => 'done',
                            'type' => 'boolean',
                        ],
                        // Datum afgehandeld
                        'DtSt' => [
                            'alias' => 'done_date',
                            'type' => 'date',
                        ],
                        // Waarde kenmerk 1 (verwijzing naar: Waarde kenmerk => AfasKnFeatureValue)
                        'FvF1' => [
                            'type' => 'integer',
                        ],
                        // Waarde kenmerk 2 (verwijzing naar: Waarde kenmerk => AfasKnFeatureValue)
                        'FvF2' => [
                            'type' => 'integer',
                        ],
                        // Waarde kenmerk 3 (verwijzing naar: Waarde kenmerk => AfasKnFeatureValue)
                        'FvF3' => [
                            'type' => 'integer',
                        ],
                        // Geblokkeerd
                        'SbBl' => [
                            'alias' => 'blocked',
                            'type' => 'boolean',
                        ],
                        // Bijlage
                        'SbPa' => [
                            'alias' => 'attachment',
                        ],
                        // Save file with subject
                        'FileTrans' => [
                            'type' => 'boolean',
                        ],
                        // File as byte-array
                        'FileStream' => [],
                    ],
                ];

            case 'KnSubjectLink':
                return [
                    'id_property' => 'SbId',
                    'fields' => [
                        // Save in CRM Subject
                        'DoCRM' => [
                            'type' => 'boolean',
                        ],
                        // Organisatie/persoon
                        'ToBC' => [
                            'alias' => 'is_org_person',
                            'type' => 'boolean',
                        ],
                        // Medewerker
                        'ToEm' => [
                            'alias' => 'is_employee',
                            'type' => 'boolean',
                        ],
                        // Verkooprelatie
                        'ToSR' => [
                            'alias' => 'is_sales_relation',
                            'type' => 'boolean',
                        ],
                        // Inkooprelatie
                        'ToPR' => [
                            'alias' => 'is_purchase_relation',
                            'type' => 'boolean',
                        ],
                        // Cliënt IB
                        'ToCl' => [
                            'alias' => 'is_client_ib',
                            'type' => 'boolean',
                        ],
                        // Cliënt Vpb
                        'ToCV' => [
                            'alias' => 'is_client_vpb',
                            'type' => 'boolean',
                        ],
                        // Werkgever
                        'ToEr' => [
                            'alias' => 'is_employer',
                            'type' => 'boolean',
                        ],
                        // Sollicitant
                        'ToAp' => [
                            'alias' => 'is_applicant',
                            'type' => 'boolean',
                        ],
                        // Type bestemming
                        // Values:  1:Geen   2:Medewerker   3:Organisatie/persoon   4:Verkooprelatie   8:Cliënt IB   9:Cliënt Vpb   10:Werkgever   11:Inkooprelatie   17:Sollicitant   30:Campagne   31:Item   32:Cursusevenement-->
                        'SfTp' => [
                            'alias' => 'destination_type',
                            'type' => 'integer',
                        ],
                        // Bestemming
                        'SfId' => [
                            'alias' => 'destination_id',
                        ],
                        // Organisatie/persoon (verwijzing naar: Organisatie/persoon => AfasKnBasicContact)
                        'BcId' => [
                            'alias' => 'org_person',
                        ],
                        // Contact (verwijzing naar: Contact => AfasKnContactData)
                        'CdId' => [
                            'alias' => 'contact',
                            'type' => 'integer',
                        ],
                        // Administratie (Verkoop) (verwijzing naar: Administratie => AfasKnUnit)
                        'SiUn' => [
                            'type' => 'integer',
                        ],
                        // Factuurtype (verkoop) (verwijzing naar: Type factuur => AfasFiInvoiceType)
                        'SiTp' => [
                            'alias' => 'sales_invoice_type',
                            'type' => 'integer',
                        ],
                        // Verkoopfactuur (verwijzing naar: Factuur => AfasFiInvoice)
                        'SiId' => [
                            'alias' => 'sales_invoice',
                        ],
                        // Administratie (Inkoop) (verwijzing naar: Administratie => AfasKnUnit)
                        'PiUn' => [
                            'type' => 'integer',
                        ],
                        // Factuurtype (inkoop) (verwijzing naar: Type factuur => AfasFiInvoiceType)
                        'PiTp' => [
                            'alias' => 'purchase_invoice_type',
                            'type' => 'integer',
                        ],
                        // Inkoopfactuur (verwijzing naar: Factuur => AfasFiInvoice)
                        'PiId' => [
                            'alias' => 'purchase_invoice',
                        ],
                        // Fiscaal jaar (verwijzing naar: Aangiftejaren => AfasTxDeclarationYear)
                        'FiYe' => [
                            'alias' => 'fiscal_year',
                            'type' => 'integer',
                        ],
                        // Project (verwijzing naar: Project => AfasPtProject)
                        'PjId' => [
                            'alias' => 'project',
                        ],
                        // Campagne (verwijzing naar: Campagne => AfasCmCampaign)
                        'CaId' => [
                            'alias' => 'campaign',
                            'type' => 'integer',
                        ],
                        // Actief (verwijzing naar: Vaste activa => AfasFaFixedAssets)
                        'FaSn' => [
                            'type' => 'integer',
                        ],
                        // Voorcalculatie (verwijzing naar: Voorcalculatie => AfasKnQuotation)
                        'QuId' => [],
                        // Dossieritem (verwijzing naar: Dossieritem => AfasKnSubject)
                        'SjId' => [
                            'type' => 'integer',
                        ],
                        // Abonnement (verwijzing naar: Abonnement => AfasFbSubscription
                        'SuNr' => [
                            'alias' => 'subscription',
                            'type' => 'integer',
                        ],
                        // Dienstverband
                        'DvSn' => [
                            'type' => 'integer',
                        ],
                        // Type item (verwijzing naar: Tabelwaarde,Itemtype => AfasKnCodeTableValue)
                        // Values:  Wst:Werksoort   Pid:Productie-indicator   Deg:Deeg   Dim:Artikeldimensietotaal   Art:Artikel   Txt:Tekst   Sub:Subtotaal   Tsl:Toeslag   Kst:Kosten   Sam:Samenstelling   Crs:Cursus-->
                        // NOTE: are these really 3-letter codes? See
                        // description for FbSales.VaIt which has integers.
                        'VaIt' => [
                            'alias' => 'item_type',
                        ],
                        // Itemcode (verwijzing naar: Item => AfasFbBasicItems)
                        'BiId' => [
                            'alias' => 'item_code',
                        ],
                        // Cursusevenement (verwijzing naar: Evenement => AfasKnCourseEvent)
                        'CrId' => [
                            'alias' => 'course_event',
                            'type' => 'integer',
                        ],
                        // Verzuimmelding (verwijzing naar: Verzuimmelding => AfasHrAbsIllnessMut)
                        'AbId' => [
                            'type' => 'integer',
                        ],
                        // Forecast (verwijzing naar: Forecast => AfasCmForecast)
                        'FoSn' => [
                            'type' => 'integer',
                        ],
                    ],
                ];


            // I do not know if the following is correct: back in 2014, the XSD
            // schema / Data Connector contained separate explicit definitions
            // for KnS01 and KnS02, which suggested they are separate object
            // types with defined fields, even though their fields all start
            // with 'U'. I can imagine that the XSD contained just examples and
            // actually it is up to the AFAS environment to define these. If
            // so, the following definitions should be removed from here and
            // KnS01 should be implemented (and the related 'object reference
            // fields' in KnSubject should be overridden) in custom classes.
            case 'KnS01':
                return [
                    'id_property' => 'SbId',
                    'fields' => [
                        // Vervaldatum
                        'U001' => [
                            'alias' => 'end_date',
                            'type' => 'date',
                        ],
                        // Identiteitsnummer
                        'U002' => [
                            'alias' => 'id_number',
                        ],
                    ],
                ];

            case 'KnS02':
                return [
                    'id_property' => 'SbId',
                    'fields' => [
                        // Contractnummer
                        'U001' => [
                            'alias' => 'contract_number',
                        ],
                        // Begindatum contract
                        'U002' => [
                            'alias' => 'start_date',
                            'type' => 'date',
                        ],
                        // Einddatum contract
                        'U003' => [
                            'alias' => 'start_date',
                            'type' => 'date',
                        ],
                        // Waarde
                        'U004' => [
                            'alias' => 'value',
                            'type' => 'decimal',
                        ],
                        // Beëindigd
                        'U005' => [
                            'alias' => 'ended',
                            'type' => 'boolean',
                        ],
                        // Stilzwijgend verlengen
                        'U006' => [
                            'alias' => 'recurring',
                            'type' => 'boolean',
                        ],
                        // Opzegtermijn (verwijzing naar: Tabelwaarde,(Afwijkende) opzegtermijn => AfasKnCodeTableValue)
                        'U007' => [
                            'alias' => 'cancel_term',
                        ],
                    ],
                ];

            case 'FbOrderBatchLines':
                return [
                    'fields' => [
                        // Partijnummer
                        'BaNu' => [
                            'alias' => 'batch_number',
                        ],
                        // Eenheid (verwijzing naar: Eenheid => AfasFbUnit)
                        'BiUn' => [
                            'alias' => 'unit_type',
                        ],
                        // Aantal eenheden
                        'QuUn' => [
                            'alias' => 'quantity_units',
                            'type' => 'decimal',
                        ],
                        // Aantal
                        'Qu' => [
                            'alias' => 'quantity',
                            'type' => 'decimal',
                        ],
                        // Factuuraantal
                        'QuIn' => [
                            'alias' => 'quantity_invoice',
                            'type' => 'decimal',
                        ],
                        // Opmerking
                        'Re' => [
                            'alias' => 'comment',
                        ],
                        // Lengte
                        'QuLe' => [
                            'alias' => 'length',
                            'type' => 'decimal',
                        ],
                        // Breedte
                        'QuWi' => [
                            'alias' => 'width',
                            'type' => 'decimal',
                        ],
                        // Hoogte
                        'QuHe' => [
                            'alias' => 'height',
                            'type' => 'decimal',
                        ],
                    ],
                ];

            case 'FbOrderSerialLines':
                return [
                    'fields' => [
                        // Serienummer
                        'SeNu' => [
                            'alias' => 'serial_number',
                        ],
                        // Eenheid (verwijzing naar: Eenheid => AfasFbUnit)
                        'BiUn' => [
                            'alias' => 'unit_type',
                        ],
                        // Aantal eenheden
                        'QuUn' => [
                            'alias' => 'quantity_units',
                            'type' => 'decimal',
                        ],
                        // Aantal
                        'Qu' => [
                            'alias' => 'quantity',
                            'type' => 'decimal',
                        ],
                        // Factuuraantal
                        'QuIn' => [
                            'alias' => 'quantity_invoice',
                            'type' => 'decimal',
                        ],
                        // Opmerking
                        'Re' => [
                            'alias' => 'comment',
                        ],
                    ],
                ];

            default:
                // We allow setting a full definition of an unknown object
                // through overridePropertyDefinitions(), so don't throw an
                // exception for types with overridden definitions. (The caller
                // is expected to merge those into the variable.)
                if (empty(static::$definitionOverrides[$this->type])) {
                    throw new UnexpectedValueException("No property definitions found for '{$this->type}' object.");
                }
                return [];
        }
    }
}
