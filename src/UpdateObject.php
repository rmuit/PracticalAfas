<?php
/**
 * This file is part of the PracticalAfas package.
 *
 * (c) Roderik Muit <rm@wyz.biz>
 *
 * For the full copyright and license information, please view the LICENSE file
 * that was distributed with this source code.
 */

namespace PracticalAfas;

use InvalidArgumentException;
use OutOfBoundsException;
use UnexpectedValueException;

/**
 * Base class for generating output to send to AFAS Update Connectors.
 *
 * For any object type which has known property definitions (either in this
 * class or in a class that is present in the static $classMap variable),
 * - An UpdateObject can be create()'d from array of data having a format that
 *   is a little easier to grasp than the JSON structure (and a lot easier than
 *   XML);
 * - output() can immediately be called to produce JSON/XML data suitable for
 *   sending through an Update Connector. Validation will be done automatically.
 * - Custom validation be done (at output or independently). Default values
 *   are populated and/or other non-invasive changes can be made to the output,
 *   or this behavior can be suppressed, as needed.
 *
 * See create() for more information; see the tests/update_examples directory
 * for example array inputs.
 *
 * An UpdateObject can hold data for several elements to send through an
 * Update Connector at once; create() can be called with either the input data
 * structure for one element, or an array of those structures for more elements.
 *
 * This class currently only accepts element data as a whole; it has no methods
 * for e.g. changing/adding individual field data. It is expected that methods
 * for this could be added fairly easily without having to rewrite code.
 *
 * About wording: the terms 'object' and 'element' are used in a way that may
 * not be apparent at first. The difference may be more apparent when looking
 * at the JSON representation in the update_examples files:
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
 * About subclassing: see the comments at $classMap.
 */
class UpdateObject
{

    /**
     * @see getElements(); bitmask value for the $change_behavior argument.
     */
    const KEEP_EMBEDDED_OBJECTS = 1;

    /**
     * @see getElements(); bitmask value for the $change_behavior argument.
     */
    const ADD_ELEMENT_WRAPPER = 2;

    /**
     * @see output(); bitmask value for the $change_behavior argument.
     */
    const FLATTEN_SINGLE_ELEMENT = 4;

    /**
     * @see output(); bitmask value value for the $change_behavior argument.
     */
    const ALLOW_NO_CHANGES = 0;

    /**
     * @see output(); bitmask value value for the $change_behavior argument.
     */
    const ALLOW_EMBEDDED_CHANGES = 8;

    /**
     * @see output(); bitmask value value for the $change_behavior argument.
     */
    const ALLOW_DEFAULTS_ON_INSERT = 16;

    /**
     * @see output(); bitmask value value for the $change_behavior argument.
     */
    const ALLOW_DEFAULTS_ON_UPDATE = 32;

    /**
     * @see output(); bitmask value value for the $change_behavior argument.
     */
    const ALLOW_REFORMAT = 64;

    /**
     * @see output(); bitmask value value for the $change_behavior argument.
     */
    const ALLOW_CHANGES = 128;

    /**
     * Default behavior for output(,$change_behavior).
     *
     * This is ALLOW_EMBEDDED_CHANGES + ALLOW_REFORMAT
     * + ALLOW_DEFAULTS_ON_INSERT + FLATTEN_SINGLE_ELEMENT.
     *
     * If future versions of this class introduce new behavior through
     * additional bitmask values, this value may or may not be changed to
     * incorporate that behavior by default.
     */
    const DEFAULT_CHANGE = 92;

    /**
     * @see output(); bitmask value for the $validation_behavior argument.
     */
    const VALIDATE_NOTHING = 0;

    /**
     * @see output(); bitmask value for the $validation_behavior argument.
     */
    const VALIDATE_ESSENTIAL = 1;

    /**
     * @see output(); bitmask value for the $validation_behavior argument.
     */
    const VALIDATE_REQUIRED = 2;

    /**
     * Default behavior for output(,,$validation_behavior).
     *
     * If future versions of this class introduce new behavior through
     * additional bitmask values, this value may or may not be changed to
     * incorporate that behavior by default.
     */
    const VALIDATE_NO_UNKNOWN = 4;

    /**
     * Default behavior for output(,,$validation_behavior).
     *
     * If future versions of this class introduce new behavior through
     * additional bitmask values, this value may or may not be changed to
     * incorporate that behavior by default.
     */
    const DEFAULT_VALIDATION = 6;

    /**
     * A mapping from object type to the class name implementing the type.
     *
     * Any object types not mentioned here are implemented by this class, or
     * not implemented.
     *
     * Please note that names of object types (the keys in this variable) are
     * not necessarily equal to the names of 'object reference fields' in
     * property definitions. As an example: a KnPerson object has two address
     * fields for home address and postal address. Both addresses are objects
     * of type KnBasicAddress, however the "Objects" section of a KnPerson
     * needs to reference them differently and uses the names
     * "KnBasicAddressAdr" and "KnBasicAddressPad" for this. (AFAS
     * documentation likely does not explain this, so) we call the keys in the
     * "Objects" part of an element 'object reference fields'. While the
     * property definitions of an object type contain object reference fields
     * (see getPropertyDefinitions()), this $classMap variable contains
     * object types. In most cases, their names are equal, though.
     *
     * A project which wants to implement custom behavior for specific object
     * types, or define new object types, can do several things. As an example,
     * say you want to add a custom field/behavior to KnPerson objects. You can:
     * - Create a MyPerson class to extend OrgPersonContact (or to extend
     *   UpdateObject, but the current KnPerson is implemented in
     *   OrgPersonContact); define the extra field/behavior in
     *   getPropertyDefinitions() etc and call 'new MyPerson($values, $action)'
     *   to get an object representing this type.
     * - The same but implement multiple overridden objects in the same class
     *   called e.g. MyUpdateObject, and call
     *   'new MyUpdateObject($values, $action, "KnPerson")' to get an object.
     * - The same but set
     *   UpdateObject::$classMap['KnPerson'] = '\MyProject\MyPerson', and call
     *   UpdateObject::create('KnPerson, $values, $action) to get an object.
     * The latter way enables creating custom embedded objects, e.g.
     * creating a KnContact containing an embedded KnPerson object with the
     * custom field/behavior. If $classMap is not modified, the embedded object
     * will be created using the standard class.
     *
     * @see getPropertyDefinitions()
     *
     * @todo before releasing 2.0, see if we should make this protected and
     *   add a method to set (change/add) one key + to get the whole thing.
     *
     * @var string[]
     */
    public static $classMap = [
        'FbSales' => '\PracticalAfas\ObjectWithCountry',
        'KnBasicAddress' => '\PracticalAfas\KnBasicAddress',
        'KnContact' => '\PracticalAfas\OrgPersonContact',
        'KnOrganisation' => '\PracticalAfas\OrgPersonContact',
        'KnPerson' => '\PracticalAfas\OrgPersonContact',
    ];

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
     * The type of parent object this data is going to be embedded into.
     *
     * This is expected to be set on construction and to never change. It can
     * influence e.g. the available fields and default values. (Maybe it's
     * possible to lift this restriction and make a separate setter for this,
     * but that would need careful consideration. If we ever want to go there,
     * it might even be preferable to completely drop the $parentType property
     * and cache a version of the getPropertyDefinitions() value instead, at
     * construction time.)
     * @todo consider this. And only cache if necessary.
     *
     * @var string
     */
    protected $parentType = '';

    /**
     * The action(s) to perform on the data: "insert", "update" or "delete".
     *
     * @see setAction()
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
     * @see getElements()
     *
     * @var array[]
     */
    protected $elements = [];

    /**
     * Instantiates a new UpdateObject, or a class defined in our map.
     *
     * One thing to remember for the $action argument: when wanting to use this
     * object's output for inserting new data into AFAS, "insert" should be
     * passed here (or set later using setAction()). This will also take care
     * of setting default values. In other cases, preferably pass "update" even
     * though that's very often equivalent to passing nothing. (Yes this is a
     * messy argument; @see setAction() if you really want to know reasons.)
     *
     * @param string $type
     *   The type of object, i.e. the 'Update Connector' name to send this data
     *   into. See the getPropertyDefinitions() code for possible values.
     * @param array $elements
     *   (Optional) Data to set in the UpdateObject, representing one or more
     *   elements of this type; see getPropertyDefinitions() for possible
     *   values. If any value in the (first dimension of the) array is scalar,
     *   it's assumed to be a single element; if it contains only non-scalars
     *   (which must be arrays), it's assumed to be several elements. (So
     *   passing one element containing no fields and only embedded objects, is
     *   only possible by passing it as an 'array containing one element'.)
     *   The keys inside a single element can be:
     *   - field names or aliases (as defined in getPropertyDefinitions());
     *   - names of the types (or, strictly speaking "names of object reference
     *     fields"; see the appropriate readme) of objects which can be
     *     embedded into this object type; the values must be an array of data
     *     (one or multiple elements) to set in that object, or an UpdateObject;
     *   - '@xxId' (where xx is a type-specific two letter code) or '#id', which
     *     holds the 'ID value' for an element. (In the output, this ID value is
     *     located in the Element structure the same level as the Fields, rather
     *     than inside Fields. Or in XML: it's an attribute in the Element tag.)
     *   The format is fairly strict: this method will throw exceptions if e.g.
     *   data / format is invalid / not recognized.
     * @param string $action
     *   (Optional) The action to perform on the data: "insert", "update" or
     *   "delete". @see setAction() or the comments above.
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
     *   If a type/action is not known, the data contains unknown field/object
     *   names, or the values have an unrecognized / invalid format.
     */
    public static function create($type, array $elements = [], $action = '', $parent_type = '') {
        // If a custom class is defined for this type, instantiate that one.
        if (isset(static::$classMap[$type])) {
            return new static::$classMap[$type]($elements, $action, $type, $parent_type);
        }
        // Use self(); static() yields errors when a child class creates a new
        // embedded object which is defined in this base class.
        return new self($elements, $action, $type, $parent_type);
    }

    /**
     * UpdateObject constructor.
     *
     * Do not call this method directly; use UpdateObject::create() instead.
     * This constructor will likely not stay fully forward compatible for all
     * object types; it may start throwing exceptions for more types over time,
     * as they are implemented in dedicated child classes. (This warning
     * applies specifically to UpdateObject; child classes may allow callers to
     * call their constructor directly.)
     *
     * The arguments have switched order from create(), and $type is optional,
     * to allow e.g. 'new CustomType($values)' more easily. ($type is not
     * actually optional in this class; an empty value will cause an exception
     * to be thrown. But many child classes will likely ignore the 3rd/4th
     * argument. So if they're lazy, they can get away with not reimplementing
     * a constructor.)
     *
     * @see create()
     */
    public function __construct(array $elements = [], $action = '', $type = '', $parent_type = '')
    {
        // If $type is empty or unrecognized, addElements() will throw an
        // exception. A wrong $parent_type will just... most likely, act as an
        // empty $parent_type (depending on what getPropertyDefinitions() does).
        // But we check the format here, since there is no setter to do that.
        if (!is_string($parent_type)) {
            throw new InvalidArgumentException('$parent_type argument is not a string.');
        }
        $this->parentType = $parent_type;
        $this->type = $type;
        $this->setAction($action);
        $this->addElements($elements);
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
     * @param int $element_index
     *   (Optional) The 0-based index of the element whose action is requested.
     *   Usually this class will contain data for only one element, in which
     *   case this argument does not need to be specified (or should be 0).
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
     * @param int $element_index
     *   (Optional) The zero-based index of the element for which to set the
     *   action. It's usually not needed even when the UpdateObject holds data
     *   for multiple elements. It's only of theoretical use (which is:
     *   outputting multiple objects with different "action" values as XML.
     *   JSON output is likely bogus when different action values are set for
     *   different elements).
     */
    public function setAction($action, $set_embedded = true, $element_index = -1)
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
            throw new InvalidArgumentException('Unknown action value' . var_export($action, true) . '.');
        }

        if ($element_index == -1) {
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
        } else {
            $this->actions[$element_index] = $action;
        }

        if ($set_embedded && !empty($this->elements) && is_array($this->elements)) {
            // Set all actions in embedded objects of the element corresponding
            // to the index we passed into this method.
            foreach ($this->elements as $i => $element) {
                if ($element_index == -1 || $i == $element_index) {

                    if (!empty($element['Objects']) && is_array($element['Objects'])) {
                        foreach ($element['Objects'] as $object) {
                            if ($object instanceof UpdateObject) {
                                $object->setAction($action, true);
                            }
                        }
                    }
                }
            }
        }
    }

    /**
     * Sets (a normalized/de-aliased version of) element values in this object.
     *
     * Unlike addElements(), this overwrites any existing data which may have
     * been present previously. (That is: the element data; not e.g. the action
     * value(s) accompanying the data.)
     *
     * @see addElements()
     */
    public function setElements(array $elements)
    {
        $this->elements = [];
        $this->addElements($elements);
    }

    /**
     * Adds (a normalized/de-aliased version of) element values to this object.
     *
     * @param array $elements
     *   (Optional) Data to set in this class, representing one or more elements
     *   of this type; see getPropertyDefinitions() for possible values per
     *   object type. See create() for a more elaborate description of this
     *   argument. If the data contains embedded objects, then those will
     *   inherit the 'action' that is set for their parent elements, so if the
     *   caller cares about which action is set for embedded objects, it's
     *   advisable to call setAction() before this method.
     *
     * @throws \InvalidArgumentException
     *   If the data contains unknown field/object names or the values have an
     *   unrecognized / invalid format.
     * @throws \UnexpectedValueException
     *   If something is wrong with this object's type value or its defined
     *   properties.
     *
     * @see create()
     */
    public function addElements(array $elements)
    {
        // Determine if $data holds a single element or an array of elements:
        // we assume the latter if all values are arrays.
        foreach ($elements as $element) {
            if (is_scalar($element)) {
                // Normalize $data to an array of elements.
                $elements = [$elements];
                break;
            }
        }

        $definitions = $this->getPropertyDefinitions();
        if (empty($definitions)) {
            throw new UnexpectedValueException("'{$this->getType()}' object has no property definitions.");
        }
        if (!isset($definitions['fields']) || !is_array($definitions['fields'])) {
            throw new UnexpectedValueException("'{$this->getType()}' object has no / a non-array 'fields' property definition.");
        }
        if (isset($definitions['objects']) && !is_array($definitions['objects'])) {
            throw new UnexpectedValueException("'{$this->getType()}' object has a non-array 'objects' property definition.");
        }

        foreach ($elements as $key => $element) {
            $element_descr = "'{$this->getType()}' element" . ($key ? " with key $key " : '');
            // Construct new element with an optional id + fields + objects for
            // this type.
            $normalized_element = [];

            // If this type has an ID field, check for it and set it in its
            // dedicated location.
            if (!empty($definitions['id_property'])) {
                $id_property = '@' . $definitions['id_property'];
                if (array_key_exists($id_property, $element)) {
                    if (array_key_exists('#id', $element) && $element['#id'] !== $element[$id_property]) {
                        throw new InvalidArgumentException($this->getType() . ' object has the ID field provided by both its field name $name and alias #id.');
                    }
                    $normalized_element[$id_property] = $element[$id_property];
                    // Unset so that we won't throw an exception at the end.
                    unset($element[$id_property]);
                } elseif (array_key_exists('#id', $element)) {
                    $normalized_element[$id_property] = $element['#id'];
                    unset($element['#id']);
                }
            }

            // Convert our element data into fields, check required fields, and
            // add default values for fields (where defined). About definitions:
            // - if required = true and default is given, then
            //   - the default value is sent if no data value is passed
            //   - an exception is (only) thrown if the passed value is null.
            // - if the default is null (or value given is null & not
            //   'required'), then null is passed.
            foreach ($definitions['fields'] as $name => $field_properties) {
                $value_present = false;
                // Get value from the property equal to the field name (case
                // sensitive!), or the alias. If two values are present with
                // both field name and alias, throw an exception.
                $value_exists_by_alias = isset($field_properties['alias']) && array_key_exists($field_properties['alias'], $element);
                if (array_key_exists($name, $element)) {
                    if ($value_exists_by_alias) {
                        throw new InvalidArgumentException("$element_descr has a value provided by both its field name $name and alias $field_properties[alias].");
                    }
                    $value = $element[$name];
                    unset($element[$name]);
                    $value_present = true;
                } elseif ($value_exists_by_alias) {
                    $value = $element[$field_properties['alias']];
                    unset($element[$field_properties['alias']]);
                    $value_present = true;
                }

                if ($value_present) {
                    if (isset($value)) {
                        if (!is_scalar($value)) {
                            $property = $name . (isset($field_properties['alias']) ? " ({$field_properties['alias']})" : '');
                            throw new InvalidArgumentException("'$property' field value of $element_descr must be scalar.");
                        }
                        if (!empty($field_properties['type'])) {
                            switch ($field_properties['type']) {
                                case 'boolean':
                                    $value = (bool) $value;
                                    break;
                                case 'integer':
                                case 'decimal':
                                    if (!is_numeric($value)) {
                                        $property = $name . (isset($field_properties['alias']) ? " ({$field_properties['alias']})" : '');
                                        throw new InvalidArgumentException("'$property' field value of $element_descr must be numeric.");
                                    }
                                    if ($field_properties['type'] === 'integer' && strpos((string)$value, '.') !== false) {
                                        $property = $name . (isset($field_properties['alias']) ? " ({$field_properties['alias']})" : '');
                                        throw new InvalidArgumentException("'$property' field value of $element_descr must be an integer value.");
                                    }
                                    // For decimal, we could also check digits,
                                    // but we're not going that far yet.
                                    break;
                                case 'date':
                                    // @todo format in standard way, once we know that's necessary
                                    break;
                            }
                        }
                    }
                    $normalized_element['Fields'][$name] = $value;
                }
            }

            if (!empty($element) && !empty($definitions['objects'])) {
                // Add other embedded objects. (We assume all remaining element
                // values are indeed objects. If not, an error will be thrown.)
                foreach ($definitions['objects'] as $name => $object_properties) {
                    $value_present = false;
                    // Get value from the property equal to the object name
                    // (case sensitive!), or the alias. If two values are
                    // present with both name and alias, throw an exception.
                    $value_exists_by_alias = isset($object_properties['alias']) && array_key_exists($object_properties['alias'], $element);
                    if (array_key_exists($name, $element)) {
                        if ($value_exists_by_alias) {
                            throw new InvalidArgumentException("$element_descr has a value provided by both its property name $name and alias $object_properties[alias].");
                        }
                        $value = $element[$name];
                        unset($element[$name]);
                        $value_present = true;
                    } elseif ($value_exists_by_alias) {
                        $value = $element[$object_properties['alias']];
                        unset($element[$object_properties['alias']]);
                        $value_present = true;
                    }

                    if ($value_present) {
                        if ($value instanceof UpdateObject) {
                            $normalized_element['Objects'][$name] = $value;
                        } else {
                            if (!is_array($value)) {
                                $property = $name . (isset($alias) ? " ($alias)" : '');
                                throw new InvalidArgumentException("Value for '$property' object embedded in $element_descr must be array.");
                            }
                            // Determine action to pass into the embedded
                            // object; we encourage callers call setAction()
                            // before us. So we need to check for our element's
                            // specific action even though the element is not
                            // set yet, which will throw an exception if this
                            // action is not explicitly set.
                            try {
                                // count is 'current maximum index + 1'
                                $action = $this->getAction(count($this->elements));
                            }
                            catch (OutOfBoundsException $e) {
                                // Get default action. This will fail if
                                // the current UpdateObject has elements with
                                // multiple different actions, in which case
                                // calling setAction() is mandatory before
                                // calling this method. That's such an edge
                                // case that it isn't documented elsewhere.
                                $action = $this->getAction();
                            }

                            // The object type is often equal to the name
                            // of the 'reference field' in the parent element,
                            // but not always; there's a property to specify it.
                            $type = !empty($object_properties['type']) ? $object_properties['type'] : $name;

                            $normalized_element['Objects'][$name] = static::create($type, $value, $action, $this->getType());
                        }
                    }
                }
            }

            // Throw error for unknown element data (for which we have not seen
            // a field/object definition).
            if (!empty($element)) {
                $keys = "'" . implode(', ', array_keys($element)) . "'";
                throw new InvalidArgumentException("Unmapped element values provided for $element_descr: keys are $keys.");
            }

            $this->elements[] = $normalized_element;
        }
    }

    /**
     * Returns the "Element" data representing one or several elements.
     *
     * This is the 'getter' equivalent for setElements() but the data is
     * normalized / de-aliased, and possibly validated and changed.
     *
     * @param int $change_behavior
     *   (Optional) by default, the literal value as stored in this object is
     *   returned without being validated; see the return value docs. This
     *   argument is a bitmask that can influence which data can be changed in
     *   the return value. Possible values are:
     *   - ADD_ELEMENT_WRAPPER: add an array wrapper around the returned data;
     *     the return value will be a one-element array with key "Element".
     *     This is necessary for generating valid JSON output.
     *   - KEEP_EMBEDDED_OBJECTS: Keep embedded UpdateObjects in the 'Objects'
     *     sub-array of each element. This will still validate any embedded
     *     objects, but discard the resulting array structure instead of
     *     replacing the UpdateObject with it. (Replacing happens when this bit
     *     is not set, except when $change_behavior is null).
     *   - ALLOW_* & FLATTEN_SINGLE_ELEMENT: see output() for description.
     * @param int $validation_behavior
     *   (Optional) see output().
     *
     * @return array[]
     *   If $change_behavior is not specified, return only the elements as they
     *   are stored in this UpdateObject. This means: return an array of one or
     *   more sub-arrays representing an element, which each can contain one to
     *   keys: the name of the ID field, "Fields" and "Objects". The "Object"
     *   value, if present, is an array of UpdateObjects keyed by the object
     *   type. (That is: a single UpdateObject per type, which itself may
     *   contain data for one or several elements.) If method arguments are
     *   specified, this changes the return value according to the modifiers
     *   passed.
     *
     * @throws \UnexpectedValueException
     *   If this object's data does not pass validation. (This likely indicates
     *   the data is invalid, although in theory it can also indicate that the
     *   validation is based on improper logic/definitions.)
     *
     * @see output()
     */
    public function getElements($change_behavior = null, $validation_behavior = self::VALIDATE_NOTHING)
    {
        if (!isset($change_behavior)) {
            if ($validation_behavior !== self::VALIDATE_NOTHING) {
                throw new InvalidArgumentException('If $change_behavior argument is null, $validation_behavior argument cannot be passed.');
            }

            $return = $this->elements;
        } else {
            if (!is_int($change_behavior)) {
                throw new InvalidArgumentException('$change_behavior argument is not an integer.');
            }
            if (!is_int($validation_behavior)) {
                throw new InvalidArgumentException('$validation_behavior argument is not an integer.');
            }

            $elements = [];
            foreach ($this->elements as $element_index => $element) {
                $elements[] = $this->validateElement($element_index, $change_behavior, $validation_behavior);
            }
            if ($change_behavior & self::FLATTEN_SINGLE_ELEMENT && count($elements) == 1) {
                $elements = reset($elements);
            }
            $return = $change_behavior & self::ADD_ELEMENT_WRAPPER ? ['Element' => $elements] : $elements;
        }

        return $return;
    }

    /**
     * Validates one element against a list of property definitions.
     *
     * Code hint: if you want to loop through all elements contained in this
     * object and then call validateElement() on them, you probably want to
     * call getElements() instead.
     *
     * @param int $element_index
     *   The index of the element in our object data; usually there is one
     *   element and the index is 0.
     * @param int $change_behavior
     *   (Optional) see output().
     * @param int $validation_behavior
     *   (Optional) see output().
     *
     * @return array
     *   The validated element, with changes applied if appropriate.
     *
     * @throws \UnexpectedValueException
     *   If the element data does not pass validation. (This likely indicates
     *   the data is invalid, although in theory it can also indicate that the
     *   validation is based on improper logic/definitions.)
     */
    protected function validateElement($element_index, $change_behavior = self::DEFAULT_CHANGE, $validation_behavior = self::DEFAULT_VALIDATION)
    {
        $element = $this->elements[$element_index];
        // Do most low level structure checks here so that the other validate*()
        // methods are easier to override.
        $element_descr = "'{$this->getType()}' element" . ($element_index ? ' with index ' . ($element_index + 1) : '');
        if (!isset($element['Fields']) || !is_array($element['Fields'])) {
            throw new UnexpectedValueException("$element_descr has no 'Fields' property value (or it is not an array).");
        }
        if (isset($element['Objects']) && !is_array($element['Objects'])) {
            throw new UnexpectedValueException("$element_descr has a non-array 'Objects' property value.");
        }

        $definitions = $this->getPropertyDefinitions($element, $element_index);
        // Doublechecks; unlikely to fail because also in addElements(). (We
        // won't repeat them in each individual validate method.)
        if (empty($definitions)) {
            throw new UnexpectedValueException("'{$this->getType()}' object has no property definitions.");
        }
        if (!isset($definitions['fields']) || !is_array($definitions['fields'])) {
            throw new UnexpectedValueException("'{$this->getType()}' object has no / a non-array 'fields' property definition.");
        }
        if (isset($definitions['objects']) && !is_array($definitions['objects'])) {
            throw new UnexpectedValueException("'{$this->getType()}' object has a non-array 'objects' property definition.");
        }

        // Design decision: validate embedded objects first ('depth first'),
        // then validate the rest of this element while knowing that the
        // 'children' are OK, and with their properties accessible (dependent
        // on some $change_behavior values).
        $element = $this->validateEmbeddedObjects($element, $element_index, $change_behavior, $validation_behavior);
        $element = $this->validateFields($element, $element_index, $change_behavior, $validation_behavior);;
        // Validate scalar-ness of all fields. This is moved out of
        // validateFields() because it's expected to be easier for subclassing.
        foreach ($element['Fields'] as $name => $value) {
            if (!is_scalar($value)) {
                throw new InvalidArgumentException("'$name' field of $element_descr must be a scalar value.");
            }
        }

        // Do checks on 'id field' after validate*() methods, so they can still
        // change it even though that's not officially their purpose.
        if (!empty($definitions['id_property'])) {
            $id_property = '@' . $definitions['id_property'];

            if (isset($element[$id_property])) {
                if (!is_int($element[$id_property]) && !is_string($element[$id_property])) {
                    throw new InvalidArgumentException("'$id_property' property in $element_descr must hold integer/string value.");
                }
            } else {
                // If action is "insert", we are guessing that there usually
                // isn't, but still could be, a value for the ID field; it
                // depends on 'auto numbering' for this object type (or the
                // value of the 'Autonum' field). We don't validate this.
                // (Yet?) We do validate that there is an ID value if action is
                // different than "insert".
                $action = $this->getAction($element_index);
                if ($action !== 'insert') {
                    throw new InvalidArgumentException("'$id_property' property in $element_descr must have a value, or Action '$action' must be set to 'insert'.");
                }
            }
        }

        if ($validation_behavior & self::VALIDATE_NO_UNKNOWN) {
            // Validate that all Fields/Objects/other properties are known.
            // This is a somewhat superfluous check because we already do this
            // in addElements() (where we more or less have to, because our
            // input is not divided over 'Objects' and 'Fields' so
            // addElements() has to decide how/where to set each property).
            if (!empty($element['Fields']) && $unknown = array_diff_key($element['Fields'], $definitions['fields'])) {
                throw new UnexpectedValueException("Unknown field(s) encountered in $element_descr: " , implode(', ', array_keys($unknown)));
            }
            if (!empty($element['Objects']) && !empty($definitions['objects']) && $unknown = array_diff_key($element['Objects'], $definitions['objects'])) {
                throw new UnexpectedValueException("Unknown object(s) encountered in $element_descr: " , implode(', ', array_keys($unknown)));
            }
            $known_properties = ['Fields' => true, 'Objects' => true];
            if (!empty($definitions['id_property'])) {
                $known_properties['@' . $definitions['id_property']] = true;
            }
            if ($unknown = array_diff_key($element, $known_properties)) {
                throw new UnexpectedValueException("Unknown properties encountered in $element_descr: " . implode(', ', array_keys($unknown)));
            }
        }

        return $element;
    }

    /**
     * Validates an element's embedded objects and replaces them by arrays.
     *
     * This only validates the 'status of embedded objects in relation to our
     * own object', not the contents of the embedded objects; the objects' own
     * getElements() / validateElement() / ... calls are responsible for that.
     *
     * This is mainly split out from validateElement() to be easier to override
     * by child classes. It should generally not touch $this->elements.
     *
     * @param array $element
     *   The element (usually the single one contained in $this->elements)
     *   whose embedded objects should be validated.
     * @param int $element_index
     *   The index of the element in our object data; usually there is one
     *   element and the index is 0.
     * @param int $change_behavior
     *   (Optional) see output(). Note that this is the behavior for our
     *   element and may still need to be amended to apply to embedded objects.
     * @param int $validation_behavior
     *   (Optional) see output().
     *
     * @return array
     *   The element with its embedded fields plus the objects contained within
     *   them validated, and changed if appropriate. Dependent on
     *   $change_behavior, all UpdateObjects are replaced by their validated
     *   array representation, and possibly wrapped inside 'Element' structures.
     *
     * @throws \UnexpectedValueException
     *   If the element data does not pass validation. (This likely indicates
     *   the data is invalid, although in theory it can also indicate that the
     *   validation is based on improper logic/definitions.)
     */
    protected function validateEmbeddedObjects(array $element, $element_index, $change_behavior = self::DEFAULT_CHANGE, $validation_behavior = self::DEFAULT_VALIDATION)
    {
        $element = $this->validateReferenceFields($element, $element_index, $change_behavior, $validation_behavior);

        if (isset($element['Objects'])) {
            $definitions = $this->getPropertyDefinitions($element, $element_index);
            $element_descr = "'{$this->getType()}' element" . ($element_index ? ' with index ' . ($element_index + 1) : '');
            // ALLOW_EMBEDDED_CHANGES has no effect on ADD_ELEMENT_WRAPPER; if
            // that is set, it keeps being set, because it's more or less
            // 'owned' by output(), and doesn't really influence the structure
            // of objects/elements themselves. That last thing also applies to
            // FLATTEN_SINGLE_ELEMENT so let's keep that too.
            $embedded_change_behavior = $change_behavior & self::ALLOW_EMBEDDED_CHANGES
                ? $change_behavior
                : $change_behavior & (self::ALLOW_EMBEDDED_CHANGES | self::FLATTEN_SINGLE_ELEMENT);

            foreach ($element['Objects'] as $ref_name => $object) {
                // Doublecheck; unlikely to fail because it's also in
                // addElements().
                if (!$object instanceof UpdateObject) {
                    throw new UnexpectedValueException("'$ref_name' object embedded in $element_descr must be an object of type UpdateObject.");
                }

                // We need to decide the exact structure of "Elements" in the
                // embedded objects. I'm making assumptions here because I don't
                // have real specifications from AFAS, or a means to test this:
                // - Their own knowledge base examples (for UpdateConnector
                //   which use KnSubject) specify a single element inside
                //   the "Element" key, e.g. "@SbId" and "Fields" are
                //   directly inside "Element".
                // - That clearly doesn't work when multiple elements need to
                //   be embedded as part of the same reference field, e.g. a
                //   FbSales entity can have multiple elements inside the
                //   FbSalesLines object. In this case its "Element" key
                //   contains an array of elements.
                // Our code has the FLATTEN_SINGLE_ELEMENT flag for output() so
                // the caller can decide what to do with 'main' objects. (By
                // default, one element is 'flattened' because see first point
                // above, but multiple elements are supported.) For embedded
                // objects, I officially do not know if AFAS accepts an array
                // inside "Elements" for _any_field. So what we do here is:
                // - If ADD_ELEMENT_WRAPPER is not provided, we pass the
                //   existing value of FLATTEN_SINGLE_ELEMENT (because we are
                //   apparently not building JSON anyway).
                // Otherwise:
                // - For reference fields that can embed multiple elements,
                //   we keep the array, regardless whether we have one or
                //   more elements at the moment. (This to keep the
                //   structure of a particular reference field consistent; I do
                //   not imagine AFAS will deny an array of 1 elements.)
                // - For reference fields that can only embed one element,
                //   we unwrap this array and place the element directly
                //   inside "Element" (because I do not know if AFAS
                //   accepts an array).
                if ($embedded_change_behavior & self::ADD_ELEMENT_WRAPPER) {
                    $cb = empty($definitions['objects'][$ref_name]['multiple'])
                        ? $embedded_change_behavior | self::FLATTEN_SINGLE_ELEMENT
                        : $embedded_change_behavior & ~self::FLATTEN_SINGLE_ELEMENT;
                }
                else {
                    $cb = $embedded_change_behavior;
                }
                // Validation of a full object is done by getElements().
                $object_data = $object->getElements($cb, $validation_behavior);

                // Validate, and change the structure according to, whether
                // this reference field is allowed to have multiple elements.
                if (empty($definitions['objects'][$ref_name]['multiple'])) {
                    $embedded_elements = $change_behavior & self::ADD_ELEMENT_WRAPPER ? $object_data['Element'] : $object_data;
                    // We could have a flattened array here, depending on
                    // whether the object has multiple elements and/or the
                    // value of ADD_ELEMENT_WRAPPER. We assume one element
                    // always has 'Fields', so if we don't find that on the
                    // first level, we have an array of one/multiple elements.
                    if (!isset($embedded_elements['Fields']) && count($embedded_elements) > 1) {
                        throw new UnexpectedValueException("'$ref_name' object embedded in $element_descr contains " . count($embedded_elements) . ' elements but can only contain a single element.');
                    }
                }

                // By default, replace any UpdateObjects with their validated
                // array representation.
                if (!($change_behavior & self::KEEP_EMBEDDED_OBJECTS)) {
                    $element['Objects'][$ref_name] = $object_data;
                }
            }
        }

        return $element;
    }

    /**
     * Validates an element's object reference fields.
     *
     * This only validates the 'status of embedded objects in relation to our
     * element', not the contents of the embedded objects; the objects' own
     * getElements() / validateElement() / ... calls are responsible for that.
     *
     * This is mainly split out from validateEmbeddedObjects() to be easier to
     * override by child classes. It should generally not touch $this->elements.
     *
     * @param array $element
     *   The element (usually the single one contained in $this->elements)
     *   whose embedded objects should be validated.
     * @param int $element_index
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
     *   are guaranteed to be UpdateObjects.
     *
     * @throws \UnexpectedValueException
     *   If the element data does not pass validation. (This likely indicates
     *   the data is invalid, although in theory it can also indicate that the
     *   validation is based on improper logic/definitions.)
     */
    protected function validateReferenceFields(array $element, $element_index, $change_behavior = self::DEFAULT_CHANGE, $validation_behavior = self::DEFAULT_VALIDATION)
    {
        $definitions = $this->getPropertyDefinitions($element, $element_index);
        if (!isset($definitions['objects'])) {
            return $element;
        }
        $action = $this->getAction($element_index);
        $defaults_allowed = ($action === 'insert' && $change_behavior & self::ALLOW_DEFAULTS_ON_INSERT)
            || ($action === 'update' && $change_behavior & self::ALLOW_DEFAULTS_ON_UPDATE);

        $element_descr = "'{$this->getType()}' element" . ($element_index ? ' with index ' . ($element_index + 1) : '');
        foreach ($definitions['objects'] as $ref_name => $object_properties) {
            $default_available = $defaults_allowed && array_key_exists('default', $object_properties);
            // Check requiredness for reference field, and create a default
            // object if it's missing. (The latter is unlikely to ever be
            // needed but still... it's a possibility.) Code is largely the
            // same as validateFields(); see there for more comments.
            $validate_required_value = !empty($object_properties['required'])
                && ($validation_behavior & self::VALIDATE_REQUIRED
                    || ($object_properties['required'] === 1 && $validation_behavior & self::VALIDATE_ESSENTIAL));
            // See validateFields(): throw an exception if we have no-or-null
            // ref-field value and no default, OR if we have null ref-field
            // value and non-null default.
            if ($validate_required_value && !isset($element['Objects'][$ref_name])
                && (!$default_available
                    || (array_key_exists($ref_name, $element['Objects']) && $default_available && $object_properties['default'] !== null))
            ) {
                throw new UnexpectedValueException("No value given for required '$ref_name' object embedded in $element_descr.");
            }

            if ($default_available) {
                $null_required_value = !isset($element['Objects'][$ref_name]) && !empty($object_properties['required']);
                if ($null_required_value || !array_key_exists($ref_name, $element['Objects'])) {
                    // We would expect a default value to be the same data
                    // definition (array) that we use to create UpdateObjects.
                    // It can be defined as an UpdateObject itself, though we
                    // don't expect that; in this case, clone the object to be
                    // sure we don't end up adding some default object in
                    // several places.
                    if ($object_properties['default'] instanceof UpdateObject) {
                        $element['Objects'][$ref_name] = clone $object_properties['default'];
                    } else {
                        if (!is_array($object_properties['default'])) {
                            throw new UnexpectedValueException("Default value for '$ref_name' object embedded in $element_descr must be array.");
                        }
                        // The intended 'action' value is always assumed to be
                        // equal to its parent's current value.
                        $element['Objects'][$ref_name] = static::create($ref_name, $object_properties['default'], $this->getAction($element_index), $this->getType());
                    }
                }
            }
        }

        return $element;
    }

    /**
     * Validates an element's fields against a list of property definitions.
     *
     * This is mainly split out from validateElement() to be easier to override
     * by child classes. It should generally not touch $this->elements.
     *
     * @param array $element
     *   The element (usually the single one contained in $this->elements)
     *   whose fields should be validated.
     * @param int $element_index
     *   The index of the element in our object data; usually there is one
     *   element and the index is 0.
     * @param int $change_behavior
     *   (Optional) see output().
     * @param int $validation_behavior
     *   (Optional) see output().
     *
     * @return array
     *   The element with its fields validated, and changed if appropriate.
     *
     * @throws \UnexpectedValueException
     *   If the element data does not pass validation. (This likely indicates
     *   the data is invalid, although in theory it can also indicate that the
     *   validation is based on improper logic/definitions.)
     */
    protected function validateFields(array $element, $element_index, $change_behavior = self::DEFAULT_CHANGE, $validation_behavior = self::DEFAULT_VALIDATION)
    {
        $definitions = $this->getPropertyDefinitions($element, $element_index);
        $action = $this->getAction($element_index);
        $defaults_allowed = ($action === 'insert' && $change_behavior & self::ALLOW_DEFAULTS_ON_INSERT)
            || ($action === 'update' && $change_behavior & self::ALLOW_DEFAULTS_ON_UPDATE);
        $element_descr = "'{$this->getType()}' element" . ($element_index ? ' with index ' . ($element_index + 1) : '');

        // Check required fields and add default values for fields (where
        // defined). About definitions:
        // - if required = true, then
        //   - if no data value present and default is provided, it's set.
        //   - if no data value present and no default is provided, an
        //     exception is thrown.
        //   - if a null value is present, an exception is thrown, unless null
        //     is provided as a default value. (We don't silently overwrite
        //     null values which were explicitly set with other default values.)
        // - if the default is null (or value given is null & not 'required'),
        //   then null is passed.
        foreach ($definitions['fields'] as $name => $field_properties) {
            $default_available = $defaults_allowed && array_key_exists('default', $field_properties);
            // Requiredness is only checked for action "insert". The
            // VALIDATE_ESSENTIAL bit does not play a role here. (Admittedly
            // the below logic may be a bit too complicated and there isn't a
            // good known example where required==1 / VALIDATE_ESSENTIAL is a
            // good solution. If it turns out that somehow some value is also
            // required on updates, then... we might consider tying required==1
            // to that situation?
            $validate_required_value = !empty($field_properties['required'])
                && $action === 'insert'
                && ($validation_behavior & self::VALIDATE_REQUIRED
                    || ($field_properties['required'] === 1 && $validation_behavior & self::VALIDATE_ESSENTIAL));
            // See above: throw an exception if we have no-or-null field
            // value and no default, OR if we have null field value and
            // non-null default.
            if ($validate_required_value && !isset($element['Fields'][$name])
                && (!$default_available
                    || (array_key_exists($name, $element['Fields']) && $default_available && $field_properties['default'] !== null))
            ) {
                throw new UnexpectedValueException("No value given for required '$name' field of $element_descr.");
            }

            // Set default if value is missing, or if value is null and field
            // is required (and if we are allowed to set it, but that's always
            // the case if $default_available).
            if ($default_available) {
                $null_required_value = !isset($element['Fields'][$name]) && !empty($field_properties['required']);
                if ($null_required_value || !array_key_exists($name, $element['Fields'])) {
                    $element['Fields'][$name] = $field_properties['default'];
                }
            }

            // Trim value if allowed. (Do this only for field values known in
            // the definitions.)
            if ($change_behavior & self::ALLOW_REFORMAT && isset($element['Fields'][$name]) && is_string($element['Fields'][$name])) {
                $element['Fields'][$name] = trim($element['Fields'][$name]);
            }
        }

        return $element;
    }
/*
@TODO write tests, especially for validate()
  */

    /**
     * Outputs the object data as a string.
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
     *     fields when updating an existing element.
     *   - ALLOW_REFORMAT (default): Allow reformatting of singular field
     *     values. For 'reformatting' a combination of values (e.g. moving
     *     a house number from a street value into its own field) additional
     *     values may need to be passed.
     *   - ALLOW_CHANGES: Allow changing field values, in ways not covered by
     *     other bits. Behavior is not precisely defined by this class; child
     *     classes may use this value or implement their own additional bits.
     *   - FLATTEN_SINGLE_ELEMENT (default): If this object holds only one
     *     element, then output this single element inside the "Element"
     *     section rather than an array containing one element. (This is only
     *     applicable to JSON output; it's not expected to make a difference to
     *     AFAS though we're not 100% sure. It is not passed into embedded
     *     objects; those have hardcoded logic around this.)
     *   Child classes might define additional values.
     * @param int $validation_behavior
     *   (Optional) By default, this method performs validation checks. This
     *   argument is a bitmask that can be used to disable validation checks (or
     *   add additional ones in child classes). Possible values are:
     *   - VALIDATE_NOTHING: Perform no validation checks at all.
     *   - VALIDATE_ESSENTIAL: Perform requiredness checks that we know will
     *     make the AFAS Update Connector call fail, but skip others. This can
     *     be useful for e.g. updating data which is present in AFAS but does
     *     not pass all our validation checks. This value loses its meaning
     *     if VALIDATE_REQUIRED is passed as well.
     *   - VALIDATE_REQUIRED (default): Check for presence of field values which
     *     this library considers 'required' even if an AFAS Update Connector
     *     call would not fail if they're missing. Example: town/municipality in
     *     an address element.
     *   - VALIDATE_NO_UNKNOWN (default): Check if all fields and objects
     *     (reference fields) are known in our 'properties' definition, and if
     *     no unknown other properties (on the same level as 'Fields' and
     *     'Objects') exist. If this option is turned off, this may cause
     *     unknown values to be included in the output, with uncertain results
     *     (depending on how the AFAS API treats these).
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
        if (!is_int($change_behavior)) {
            throw new InvalidArgumentException('$change_behavior argument is not an integer.');
        }
        if (!is_int($validation_behavior)) {
            throw new InvalidArgumentException('$validation_behavior argument is not an integer.');
        }
        if (!is_string($format)) {
            throw new InvalidArgumentException('Invalid format.');
        }

        // ADD_ELEMENT_WRAPPER and KEEP_EMBEDDED_OBJECTS make no sense in this
        // method in general. (They are only set in very specific places.) Hard
        // unset them without checking.
        $change_behavior = $change_behavior & ~(self::ADD_ELEMENT_WRAPPER | self::KEEP_EMBEDDED_OBJECTS);

        switch (strtolower($format)) {
            case 'json':
                // getElements() returns a one-element array with key
                // 'Element' and value being the one or several elements in
                // this DataObject. The JSON structure should be like this
                // except it needs another one-element array wrapper with key
                // being the object type.
                $data = [$this->getType() => $this->getElements($change_behavior | self::ADD_ELEMENT_WRAPPER, $validation_behavior)];
                return empty($format_options['pretty']) ? json_encode($data) : json_encode($data, JSON_PRETTY_PRINT);

            case 'xml':
                // The XML also needs one 'outer' wrapper tag (only around the
                // full object, not around embedded ones), but since outputXml()
                // calls output() recursively, there's no advantage to
                // adding that here. We add it inside outputXml().
                return $this->outputXml($format_options, $change_behavior, $validation_behavior);

            default:
                throw new InvalidArgumentException("Invalid format '$format'.");
        }
    }

    /**
     * Encode data as XML, suitable for sending through SOAP connector.
     *
     * @param array $format_options
     *    (Optional) Options influencing the format:
     *   - 'pretty' (boolean): If true, pretty-print (with newlines/spaces).
     *   - 'indent' (integer): The number of spaces to prefix an indented line
     *     with; 2 by default. 'pretty' effect is canceled if this is not an
     *     integer or <= 0.
     *   - 'indent_start' (string): a prefix to start each line with.
     * @param int $change_behavior
     *   (Optional) see output().
     * @param int $validation_behavior
     *   (Optional) see output().
     *
     * @return string
     *   XML payload to send to an Update Connector on a SOAP API/Connection.
     */
    protected function outputXml(array $format_options = [], $change_behavior = self::DEFAULT_CHANGE, $validation_behavior = self::DEFAULT_VALIDATION)
    {
        $xml = $indent_str0 = $indent_str1 = $indent_str2 = $indent_str3 = '';
        $skip_start = false;
        $pretty = !empty($format_options['pretty']) && (!isset($format_options['indent'])
                || (is_int($format_options['indent']) && $format_options['indent'] > 0));
        if (!empty($format_options['indent_start']) && is_string($format_options['indent_start'])) {
            $xml = $format_options['indent_start'];
            $skip_start = true;
        }
        if ($pretty) {
            $indent = isset($format_options['indent']) ? $format_options['indent'] : 2;
            $extra_spaces = str_repeat(' ', $indent);
            if ($this->parentType) {
                // LF + Indentation before Element tag:
                $indent_str1 = "\n$xml";
            } else {
                // LF + Indentation before 'type end tag':
                $indent_str0 = "\n$xml";
                // LF + Indentation before Element tag:
                $indent_str1 = $indent_str0 . $extra_spaces;
            }
            // LF + Indentation before Fields/Objects tag:
            $indent_str2 = $indent_str1 . $extra_spaces;
            // LF + Indentation before individual field values:
            $indent_str3 = $indent_str2 . $extra_spaces;
        }
        // Only include the start tag with object type if we're not recursing.
        if (!$this->parentType) {
            $xml .= "<{$this->getType()} xmlns:xsi=\"http://www.w3.org/2001/XMLSchema-instance\">";
            $skip_start = false;
        }

        // Fully validate the element(s) in this object, but keep embedded
        // objects in the form of UpdateObjects because we need to call
        // output() on the individual objects (because e.g. they need to know
        // their own getAction() while generating output). This means that we
        // validate all embedded objects multiple times (here and while
        // generating the output); objects that are 2 levels deep even get
        // validated 3 times. but there's not much we can do about that, since
        // our 'design decision' is to validate all embedded objects before
        // being able to fully validate the main one.
        $cb = ($change_behavior & ~self::FLATTEN_SINGLE_ELEMENT) | self::KEEP_EMBEDDED_OBJECTS;
        foreach ($this->getElements($cb, $validation_behavior) as $element_index => $element) {
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
            $definitions = $this->getPropertyDefinitions($element, $element_index);
            if (!empty($definitions['id_property']) && isset($element['@' . $definitions['id_property']])) {
                $id_attribute = ' ' . $definitions['id_property'] . '="' . $element['@' . $definitions['id_property']] . '"';
            }
            // Each element is in its own 'Element' tag (unlike the input
            // argument which has an array of elements in one 'Element' key,
            // because multiple 'Element' keys cannot exist in one object or
            // JSON string).
            if ($skip_start) {
                $skip_start = false;
            } else {
                $xml .= $indent_str1;
            }
            $xml .= "<Element$id_attribute>";

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

            if (!empty($element['Objects'])) {
                $xml .= "$indent_str2<Objects>";
                if ($pretty) {
                    // $indent is defined above.
                    $format_options['indent_start'] = (empty($format_options['indent_start']) ? '' : $format_options['indent_start'])
                        . str_repeat(' ', $indent * ($this->parentType ? 3 : 4));
                }
                /* @var UpdateObject $value */
                foreach ($element['Objects'] as $ref_name => $value) {
                    $xml .= "$indent_str3<$ref_name>" . ($pretty ? "\n" : '')
                        . $value->output('xml', $format_options, $change_behavior, $validation_behavior)
                        . "$indent_str3</$ref_name>";
                }
                $xml .= "$indent_str2</Objects>";
            }

            $xml .= "$indent_str1</Element>";
        }
        if (!$this->parentType) {
            // Add closing XML tag. Do not end with newline even if 'pretty'.
            $xml .= "$indent_str0</{$this->getType()}>";
        }

        return $xml;
    }

    /**
     * Returns property definitions for this specific object type.
     *
     * The format is not related to AFAS but a structure specific to this class.
     *
     * The definitions in the below method are based on what AFAS calls the
     * 'XSD Schema' for SOAP, retrieved though a Data Connector in november
     * 2014. They're amended with extra info like more understandable aliases
     * for the field names, and default values.
     *
     * @return array
     *   An array with the following keys:
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
     *   - 'required': If true, this field is required and our output()
     *                 method will throw an exception if the field is not
     *                 populated when action is "insert". If (int)1, this is
     *                 done even if output() is not instructed to validate
     *                 required values; this can be useful to set if it is
     *                 known that AFAS itself will throw an unclear error when
     *                 it receives no value for the field.
     *   'objects':  Arrays describing properties of the 'object reference
     *               fields' defined for this object type, keyed by their names.
     *               An array may be empty but must be defined for an embedded
     *               object to be recognized. Properties known to this class:
     *   - 'type':     The name of the AFAS object type which this reference
     *                 points to. If not provided, the type is assumed to be
     *                 equal to the name of the reference field. (This is most
     *                 often the case. For an explanation about the difference,
     *                 see the comments at static $classMap.)
     *   - 'alias':    A name for this field that can be used instead of the
     *                 AFAS name and that can be used in input data structures.
     *   - 'multiple': If true, the embedded object can hold more than one
     *                 element.
     *   - 'required': See 'fields' above.
     * @param array $element
     *   (Optional) The element which is getting validated at the moment, if
     *   there is one. Note that it's strange to make an element's property
     *   definitions dependent on its values. However, in some situations,
     *   things like requiredness or default value of one field are dependent
     *   on the value of another field. Passing the element into here in calls
     *   from e.g. validateFields(), while logically inconsistent, is a
     *   shortcut to handling these situations, which would otherwise need to
     *   be solved by copying the full validateFields() code into a child class
     *   and modifying it.
     * @param int $element_index
     *   (Optional) The index of the element in our object data.
     *
     * @throws \UnexpectedValueException
     *   If something is wrong with the definition or it could not be derived.
     *
     */
    public function getPropertyDefinitions(array $element = null, $element_index = null)
    {
        // There are lots of Dutch comment lines in this function; these were
        // gathered from an online knowledge base page around 2012 when that
        // was the only form/language of documentation.

        switch ($this->getType()) {
            case 'KnSalesRelationPer':
                // [ Contains notes from 2014, based on an example XML snippet
                //   from 2011 which I inherited from a commerce system. Please
                //   send PRs to fix the fields / comments if you feel inclined. ]
                // NOTE - not checked against XSD yet, only taken over from Qoony example
                // Fields:
                // ??? = Overheids Identificatienummer, which an AFAS expert recommended
                //       for using as a secondary-unique-id, when we want to insert an
                //       auto-numbered object and later retrieve it to get the inserted ID.
                //       I don't know what this is but it's _not_ 'OIN', I tried that.
                //       (In the end we never used this field.)
                $definitions = [
                    'id_property' => 'DbId',
                    'objects' => [
                        'KnPerson' => [
                            'alias' => 'person',
                        ],
                    ],
                    'fields' => [
                        // 'is debtor'?
                        'IsDb' => [
                            'type' => 'boolean',
                            'default' => true,
                        ],
                        // According to AFAS docs, PaCd / VaDu "are required if IsDb==True" ...
                        // no further specs. [ comment 2014: ] Heh, VaDu is not even in
                        // our inserted XML so that does not seem to be actually true.
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
                        // Verzamelrekening Debiteur -- apparently these just need to be
                        // specified by whoever is setting up the AFAS administration?
                        'ColA' => [
                            'alias' => 'verzamelreking_debiteur',
                        ],
                        // [ comment 2014: ]
                        // ?? Doesn't seem to be required, but we're still setting default to
                        // the old value we're used to, until we know what this field means.
                        'VtIn' => [
                            'default' => '1',
                        ],
                        'PfId' => [
                            'default' => '*****',
                        ],
                    ],
                ];
                break;

            case 'KnSubject':
                $definitions = [
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
                break;

            case 'KnSubjectLink':
                $definitions = [
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
                break;

            // I do not know if the following is correct: back in 2014, the XSD
            // schema / Data Connector contained separate explicit definitions
            // for KnS01 and KnS02, which suggested they are separate object
            // types with defined fields, even though their fields all start
            // with 'U'. I can imagine that the XSD contained just examples and
            // actually it is up to the AFAS environment to define these. In
            // that case, the following definitions should be removed from here
            // and KnS01 should be implemented (and the corresponding 'object
            // reference fields' in KnSubject should be overridden) in custom
            // classes.
            case 'KnS01':
                $definitions = [
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
                break;

            case 'KnS02':
                $definitions = [
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
                break;

            case 'FbSalesLines':
                $definitions = [
                    'objects' => [
                        'FbOrderBatchLines' => [
                            'alias' => 'batch_line_items',
                            'multiple' => true,
                        ],
                        'FbOrderSerialLines' => [
                            'alias' => 'serial_line_items',
                            'multiple' => true,
                        ],
                    ],
                    'fields' => [
                        // Type item (verwijzing naar: Tabelwaarde,Itemtype => AfasKnCodeTableValue)
                        // Values:  1:Werksoort   10:Productie-indicator   11:Deeg   14:Artikeldimensietotaal   2:Artikel   3:Tekst   4:Subtotaal   5:Toeslag   6:Kosten   7:Samenstelling   8:Cursus
                        'VaIt' => [
                            'alias' => 'item_type',
                        ],
                        // Itemcode
                        'ItCd' => [
                            'alias' => 'item_code',
                        ],
                        // Omschrijving
                        'Ds' => [
                            'alias' => 'description',
                        ],
                        // Btw-tariefgroep (verwijzing naar: Btw-tariefgroep => AfasKnVatTarifGroup)
                        'VaRc' => [
                            'alias' => 'vat_type',
                        ],
                        // Eenheid (verwijzing naar: Eenheid => AfasFbUnit)
                        'BiUn' => [
                            'alias' => 'unit_type',
                        ],
                        // Aantal eenheden
                        'QuUn' => [
                            'alias' => 'quantity',
                            'type' => 'decimal',
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
                        // Aantal besteld
                        'Qu' => [
                            'alias' => 'quantity_ordered',
                            'type' => 'decimal',
                        ],
                        // Aantal te leveren
                        'QuDl' => [
                            'alias' => 'quantity_deliver',
                            'type' => 'decimal',
                        ],
                        // Prijslijst (verwijzing naar: Prijslijst verkoop => AfasFbPriceListSale)
                        'PrLi' => [
                            'alias' => 'price_list',
                        ],
                        // Magazijn (verwijzing naar: Magazijn => AfasFbWarehouse)
                        'War' => [
                            'alias' => 'warehouse',
                        ],
                        // Dienstenberekening
                        'EUSe' => [
                            'type' => 'boolean',
                        ],
                        // Gewichtseenheid (verwijzing naar: Tabelwaarde,Gewichtseenheid => AfasKnCodeTableValue)
                        // Values:  0:Geen gewicht   1:Microgram (Âµg)   2:Milligram (mg)   3:Gram (g)   4:Kilogram (kg)   5:Ton
                        'VaWt' => [
                            'alias' => 'weight_unit',
                        ],
                        // Nettogewicht
                        'NeWe' => [
                            'alias' => 'weight_net',
                            'type' => 'decimal',
                        ],
                        //
                        'GrWe' => [
                            'alias' => 'weight_gross',
                            'type' => 'decimal',
                        ],
                        // Prijs per eenheid
                        'Upri' => [
                            'alias' => 'unit_price',
                            'type' => 'decimal',
                        ],
                        // Kostprijs
                        'CoPr' => [
                            'alias' => 'cost_price',
                            'type' => 'decimal',
                        ],
                        // Korting toestaan (verwijzing naar: Tabelwaarde,Toestaan korting => AfasKnCodeTableValue)
                        // Values:  0:Factuur- en regelkorting   1:Factuurkorting   2:Regelkorting   3:Geen factuur- en regelkorting
                        'VaAD' => [],
                        // % Regelkorting
                        'PRDc' => [
                            'type' => 'decimal',
                        ],
                        // Bedrag regelkorting
                        'ARDc' => [
                            'type' => 'decimal',
                        ],
                        // Handmatig bedrag regelkorting
                        'MaAD' => [
                            'type' => 'boolean',
                        ],
                        // Opmerking
                        'Re' => [
                            'alias' => 'comment',
                        ],
                        // GUID regel
                        'GuLi' => [
                            'alias' => 'guid',
                        ],
                        // Artikeldimensiecode 1 (verwijzing naar: Artikeldimensiecodes => AfasFbStockDimLines)
                        'StL1' => [
                            'alias' => 'dimension_1',
                        ],
                        // Artikeldimensiecode 2 (verwijzing naar: Artikeldimensiecodes => AfasFbStockDimLines)
                        'StL2' => [
                            'alias' => 'dimension_2',
                        ],
                        // Direct leveren vanuit leverancier
                        'DiDe' => [
                            'alias' => 'direct_delivery',
                            'type' => 'boolean',
                        ],
                    ],
                ];
                break;

            case 'FbOrderBatchLines':
                $definitions = [
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
                break;

            case 'FbOrderSerialLines':
                $definitions = [
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
                break;

            default:
                throw new UnexpectedValueException("No property definitions found for '{$this->getType()}' object.");
        }

        return $definitions;
    }
}
