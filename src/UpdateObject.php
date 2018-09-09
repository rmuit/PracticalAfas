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
use RuntimeException;
use UnexpectedValueException;

/**
 * TODO REDO
 *
 * @todo note this can explicitly be an array of objects, not only one single object.
 */
class UpdateObject
{
    /**
     * @see getObjectData(); bitmask for the $change_behavior argument.
     */
    const KEEP_EMBEDDED_OBJECTS = 1;

    /**
     * @see getObjectData(); bitmask for the $change_behavior argument.
     */
    const ADD_ELEMENT_WRAPPER = 2;

    /**
     * @see output(); bitmask for the $change_behavior argument.
     */
    const FLATTEN_SINGLE_ELEMENT = 4;

    /**
     * @see output(); this is a bitmask for the $change_behavior argument.
     */
    const VALIDATE_ALLOW_NO_CHANGES = 0;

    /**
     * @see output(); this is a bitmask for the $change_behavior argument.
     */
    const VALIDATE_ALLOW_EMBEDDED_CHANGES = 8;

    /**
     * @see output(); this is a bitmask for the $change_behavior argument.
     */
    const VALIDATE_ALLOW_DEFAULTS_ON_INSERT = 16;

    /**
     * @see output(); this is a bitmask for the $change_behavior argument.
     */
    const VALIDATE_ALLOW_DEFAULTS_ON_UPDATE = 32;

    /**
     * @see output(); this is a bitmask for the $change_behavior argument.
     */
    const VALIDATE_ALLOW_REFORMAT = 64;

    /**
     * @see output(); this is a bitmask for the $change_behavior argument.
     */
    const VALIDATE_ALLOW_CHANGES = 128;

    /**
     * Default behavior for output(,$change_behavior).
     *
     * This is VALIDATE_ALLOW_EMBEDDED_CHANGES + VALIDATE_ALLOW_REFORMAT
     * + VALIDATE_ALLOW_DEFAULTS_ON_INSERT + FLATTEN_SINGLE_ELEMENT,
     *
     * If future versions of this class introduce new behavior through
     * additional bitmask values, this value may or may not be changed to
     * incorporate that behavior by default.
     */
    const VALIDATE_ALLOW_DEFAULT = 92;

    /**
     * @see output(); this is a bitmask for the $validation_behavior argument.
     */
    const VALIDATE_NOTHING = 0;

    /**
     * @see output(); this is a bitmask for the $validation_behavior argument.
     */
    const VALIDATE_ESSENTIAL = 1;

    /**
     * @see output(); this is a bitmask for the $validation_behavior argument.
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
    const VALIDATE_DEFAULT = 6;

    /**
     * A mapping from object type to the class name implementing the type.
     *
     * Any object types not mentioned here are implemented by this class.
     *
     * Please note that names of object types (the keys in this variable) are
     * not necessarily equal to the names of 'object reference fields' in
     * property definitions (see getPropertyDefinitions()). e.g. the object
     * type defined here would be KnBasicAddress whereas the 'reference field'
     * defined inside a KnPerson object is called KnBasicAddressAdr /
     * KnBasicAddressPad.
     *
     * A project which wants to implement custom behavior for specific object
     * types can do three things, from simple to complicated:
     * - Implement OverriddenType as a child class of UpdateObject, define
     *   its data in getPropertyDefinitions() etc, and call new
     *   OverriddenType($values) to get an object representing this type.
     * - Implement OverriddenUpdateObject as a child class of UpdateObject,
     *   define 'OverriddenType' data in getPropertyDefinitions() etc - and
     *   call OverriddenUpdateObject::create('OverriddenType', $values) to get
     *   an object representing this type.
     * The second way enables creating custom embedded objects, e.g. defining a
     * custom object for line items and having that embedded inside an FbSales
     * object through OverriddenUpdateObject::create('FbSales', $values).
     * - A combination of the two, if you want to split out (a subset of) the
     *   type definitions into their own classes:
     *   - Define data for the overridden type in the OverriddenType class;
     *   - Redefine OverriddenUpdateObject::$classMap, or change it in create(),
     *     to contain a mapping 'OverriddenType' => 'OverriddenType' (from type
     *     name to class name)
     *   - Call OverriddenUpdateObject::create('OverriddenType', $values)
     *     instead of new OverriddenType($values) to make use of the mapping
     *     which enables embedded custom objects.
     *
     * @var string[]
     */
    public static $classMap = [];

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
     * The "Element" data representing one or several objects.
     *
     * @see getObjectData()
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
     * of setting default values. In other cases, pass "update", because "it's
     * the right thing to do". (Yes this is a messy argument; @see setAction()
     * for more info if you really want to know why.)
     *
     * @param string $type
     *   The type of object, i.e. the 'Update Connector' name to send this data
     *   into. See the getPropertyDefinitions() code for possible values.
     * @param array $object_data
     *   (Optional) Data to set in this class, representing one or more objects
     *   of this type; see getPropertyDefinitions() for possible values.
     *   If any value in the (first dimension of the) array is scalar, it's
     *   assumed to be a single object; if it contains only non-scalars (which
     *   must be arrays), it's assumed to be several objects. Note that it's
     *   possible to pass one object containing no fields and only embedded
     *   sub-objects, only by passing it as an 'array containing one object'.
     *   The keys inside a single object can be:
     *   - field names or aliases (as defined in getPropertyDefinitions());
     *   - type names for sub-objects which can be embedded into this type; the
     *     values must be an array of data to set for that object, or an
     *     UpdateObject;
     *   - '@xxId' (where xx is a type-specific two letter code) or '#id', which
     *     holds the 'id value' for an object which is located on the 'first
     *     layer' (or in XML: in an attribute) of the Element tag. (As opposed
     *     to: inside the Fields tag.)
     *   The format is fairly strict: this method will throw exceptions if e.g.
     *   data / format is invalid / not recognized.
     * @param string $action
     *   (Optional) The action to perform on the data: "insert", "update" or
     *   "delete". @see setAction() or the comments above.
     * @param string $parent_type
     *   (Optional) If nonempty, the return value will be suitable for
     *   embedding inside the parent type, which can have a slightly different
     *   structure (e.g. allowed fields) in some cases.  Unlike $action, this
     *   cannot be changed after the object is instantiated.
     *
     * @return static
     *
     * @throws \InvalidArgumentException
     *   If a type/action is not known, the data contains unknown field/object
     *   names, or the values have an unrecognized / invalid format.
     */
    public static function create($type, array $object_data = [], $action = '', $parent_type = '') {
        // If a custom class is defined for this type, instantiate that one.
        if (isset(static::$classMap[$type])) {
            return new static::$classMap[$type]($object_data, $action, $type, $parent_type);
        }
        // Use self(); static() yields errors when an overridden object
        // creates a new embedded object which is defined in this base class.
        return new self($object_data, $action, $type, $parent_type);
    }

    /**
     * UpdateObject constructor.
     *
     * Do not call this method directly; use UpdateObject::create() instead.
     *
     * This constructor will likely not stay fully forward compatible for all
     * object types; the constructor will start throwing exceptions for more
     * types over time, as they are implemented in dedicated child classes.
     * (This warning pertains to this specific class; child classes may allow
     * callers to call their constructor directly.)
     *
     * The arguments have switched order from create(), and $type is optional,
     * to allow e.g. 'new CustomType($values)' more easily. ($type is not
     * actually optional in this class; an empty value will cause an exception
     * to be thrown. But many child classes will likely ignore the 3nd/4th
     * argument. So if they're lazy, they can get away with not reimplementing
     * a constructor.)
     *
     * @see create()
     */
    public function __construct(array $object_data = [], $action = '', $type = '', $parent_type = '')
    {
        // If $type is empty or unrecognized, addObjectData() will throw an
        // exception. A wrong $parent_type will just... most likely, act as an
        // empty $parent_type (depending on what getPropertyDefinitions() does).
        // But we check the format here, since there is no setter to do that.
        if (!is_string($parent_type)) {
            throw new InvalidArgumentException('$parent_type argument is not a string.');
        }
        $this->parentType = $parent_type;
        $this->type = $type;
        $this->setAction($action);
        $this->addObjectData($object_data);
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
     * Returns the action which is set to perform on one or all objects.
     *
     * @param int $element_index
     *   (Optional) The zero-based index of the element whose action is
     *   requested. Usually this class will contain data for only one object,
     *   in which case this argument does not need to be specified (or should be
     *   0).
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
            if (isset($element_index)  && !isset($this->elements[$element_index])) {
                throw new OutOfBoundsException("No action or element defined for index $element_index.");
            }
            // We have element data without an explicit action defined for it,
            // which is fine - if all the actions which are set, are the same.
            $unique = array_unique($this->actions);
            if (count($unique) > 1) {
                $addition = isset($element_index) ? " but not for $element_index" : '';
                throw new UnexpectedValueException("Multiple different action values are set$addition, so getAction() has to be called with a valid index parameter.");
            }
            $action = $unique[0];
        }

        return $action;
    }

    /**
     * Returns the action values that were set in this class.
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
     * Sets the action to perform on object data.
     *
     * 'Actions' are a bit of an oddity: the only value that is known to have
     * an effect (and may be required to generate proper output) is "insert",
     * if this object's output will be used for inserting new data into AFAS.
     * This value can also be passed in create() so calling setAction() should
     * not often be necessary.
     *
     * It's still implemented as a string with other possible values, in order
     * to be able to output all known forms of XML for sending to the SOAP
     * API. It's possible that we discover a situation later, where embedding
     * a specific action value in the XML is a necessity.
     *
     * For now, just remember: set "insert" for updates (which will
     * automatically take care of setting necessary default values), otherwise
     * set "update" to be future proof. ("delete" has not been tested so we
     * can't comment on that yet.) This is only to assure future compatibility:
     * "update" does not have any effect on the REST API currently; it will
     * change the XML used for the SOAP API slightly but that is not known to
     * have any practical effect, at the moment.
     *
     * @param string $action
     *   The action to perform on the data: "insert", "update" or "delete". ""
     *   is also accepted as a valid value, though it has no known use.
     * @param bool $set_embedded
     *   (Optional) If false, only set the current object, By default, the
     *   action is also set/overwritten in any embedded objects.
     * @param int $element_index
     *   (Optional) The zero-based index of the element for which to set the
     *   action. Do not specify this; it's usually not needed even when the
     *   UpdateObject holds data for multiple objects. It's only of theoretical
     *   use (which is: outputting multiple objects with different "action"
     *   values as XML).
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
     * Unlike addObjectData(), this overwrites any existing data which may have
     * been present previously. (That is: the object data; not e.g. the action
     * value(s) accompanying the data.)
     *
     * @see addObjectData()
     */
    protected function setObjectData(array $object_data)
    {
        $this->elements = [];
        $this->addObjectData($object_data);
    }

    /**
     * Adds (a normalized/de-aliased version of) element values to this object.
     *
     * @param array $object_data
     *   (Optional) Data to set in this class, representing one or more objects
     *   of this type; see getPropertyDefinitions() for possible values per
     *   object type. See create() for a more elaborate description of this
     *   argument. If the data contains embedded objects, then those will
     *   inherit the 'action' that is set for their parent object, so if the
     *   caller cares about which action is set for embedded objects, it's
     *   advisable to call setAction() before this method.
     *
     * @throws \InvalidArgumentException
     *   If the data contains unknown field/object names or the values have an
     *   unrecognized / invalid format.
     * @throws \UnexpectedValueException
     *   If there's something wrong with this object's type value or its
     *   defined properties.
     *
     * @see create()
     */
    protected function addObjectData(array $object_data)
    {
        // Determine if $data holds a single object or an array of objects:
        // we assume the latter if all values are arrays.
        foreach ($object_data as $element) {
            if (is_scalar($element)) {
                // Normalize $data to an array of objects.
                $object_data = [$object_data];
                break;
            }
        }

        $definitions = $this->getPropertyDefinitions();
        if (empty($definitions)) {
            throw new UnexpectedValueException($this->getType() . ' object has no property definitions.');
        }
        if (!isset($definitions['fields']) || !is_array($definitions['fields'])) {
            throw new UnexpectedValueException($this->getType() . " object has no 'fields' property definition (or the property is not an array).");
        }
        if (isset($definitions['objects']) && !is_array($definitions['objects'])) {
            throw new UnexpectedValueException($this->getType() . " object has a non-array 'objects' property definition.");
        }

        foreach ($object_data as $key => $element) {
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
                        throw new InvalidArgumentException("'{$this->getType()}' object has a value provided by both its field name $name and alias $field_properties[alias].");
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
                            throw new InvalidArgumentException("'$property' field value of '{$this->getType()}' object must be scalar.");
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
                                        throw new InvalidArgumentException("'$property' field value of '{$this->getType()}' object must be numeric.");
                                    }
                                    if ($field_properties['type'] === 'integer' && strpos((string)$value, '.') !== false) {
                                        $property = $name . (isset($field_properties['alias']) ? " ({$field_properties['alias']})" : '');
                                        throw new InvalidArgumentException("'$property' field value of '{$this->getType()}' object must be an integer value.");
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
                            throw new InvalidArgumentException("'{$this->getType()}' object has a value provided by both its property name $name and alias $object_properties[alias].");
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
                                throw new InvalidArgumentException("Value for '$property' object embedded inside '{$this->getType()}' object must be array.");
                            }
                            // Determine action to pass into the child object;
                            // we encourage callers call setAction() before us.
                            // So we need to check for our element's specific
                            // action even though the element is not set yet,
                            // which will throw an exception if this action is
                            // not explicitly set.
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

                            // The object type is sometimes equal to the name
                            // of the 'reference field' inside the parent
                            // object, but not always.
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
                throw new InvalidArgumentException("Unmapped element values provided for '{$this->getType()}' object: keys are $keys.");
            }

            $this->elements[] = $normalized_element;
        }
    }

    /**
     * Returns the "Element" data representing one or several objects.
     *
     * This is the 'getter' equivalent for setObjectData() but the data is
     * normalized / de-aliased, and possibly validated and changed.
     *
     * @param int $change_behavior
     *   (Optional) by default, the literal value as stored in this object is
     *   returned without being validated; see @return. This argument is a
     *   bitmask that can influence which data can be changed in the return
     *   value. Possible values are:
     *   - ADD_ELEMENT_WRAPPER: add an array wrapper around the returned data;
     *     the return value will be a one-element array with key "Element".
     *     This is necessary for generating valid JSON output.
     *   - KEEP_EMBEDDED_OBJECTS: Keep embedded UpdateObjects in the 'Objects'
     *     sub-array of each element. This will still validate any embedded
     *     objects, but discard the resulting array structure instead of
     *     replacing the UpdateObject with it (which is the default behavior
     *     for a non-null $change_behavior).
     *   - VALIDATE_ALLOW_* & FLATTEN_SINGLE_ELEMENT: see output() for description.
     * @param int $validation_behavior
     *   (Optional) see output().
     *
     * @return array[]
     *   If $change_behavior is not specified, return only the elements as they
     *   are stored in this UpdateObject. This means: return an array of one or
     *   more sub-arrays representing an element. The sub arrays can contain
     *   one to three keys: the name of the ID field, "Fields" and "Objects".
     *   The "Object" value, if present, is an array of UpdateObjects keyed by
     *   the object type. (That is: a single UpdateObject per type, which
     *   itself may contain data for one or several elements.) If method
     *   arguments are specified, this changes the return value according to
     *   the modifiers passed.
     *
     * @throws \UnexpectedValueException
     *   If this object's data does not pass validation. (This likely indicates
     *   the data is invalid, although in theory it can also indicate that the
     *   validation is based on improper logic/definitions.)
     */
    public function getObjectData($change_behavior = null, $validation_behavior = self::VALIDATE_NOTHING)
    {
        if (!isset($change_behavior)) {
            if ($validation_behavior !== self::VALIDATE_NOTHING) {
                throw new InvalidArgumentException('If $change_behavior argument is NULL, $validation_behavior argument cannot be passed.');
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
     * call getObjectData() instead.
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
    protected function validateElement($element_index, $change_behavior = self::VALIDATE_ALLOW_DEFAULT, $validation_behavior = self::VALIDATE_DEFAULT)
    {
        $definitions = $this->getPropertyDefinitions();
        // Doublechecks; unlikely to fail because also in addObjectData(). (We
        // won't repeat them in each individual validate method.)
        if (empty($definitions)) {
            throw new UnexpectedValueException($this->getType() . ' object has no property definitions.');
        }
        if (!isset($definitions['fields']) || !is_array($definitions['fields'])) {
            throw new UnexpectedValueException($this->getType() . " object has no 'fields' property definition (or the property is not an array).");
        }
        if (isset($definitions['objects']) && !is_array($definitions['objects'])) {
            throw new UnexpectedValueException($this->getType() . " object has a non-array 'objects' property definition.");
        }

        $element = $this->elements[$element_index];
        // Do most low level structure checks here so that the other validate*()
        // methods are easier to override.
        $object_type_msg = "'{$this->getType()}' object" . ($element_index ? ' with index ' . ($element_index + 1) : '') . '.';
        if (!isset($element['Fields']) || !is_array($element['Fields'])) {
            throw new UnexpectedValueException($this->getType() . " object has no 'Fields' property value (or it is not an array).");
        }
        if (isset($element['Objects']) && !is_array($element['Objects'])) {
            throw new UnexpectedValueException($this->getType() . " object has a non-array 'Objects' property value.");
        }

        // Design decision: validate embedded objects first ('depth first'),
        // then validate the rest of this object while knowing that the
        // 'children' are OK, and with their properties accessible (dependent
        // on some $change_behavior values).
        $element = $this->validateEmbeddedObjects($element, $element_index, $change_behavior, $validation_behavior);
        $element = $this->validateFields($element, $element_index, $change_behavior, $validation_behavior);;
        // Validate scalar-ness of all fields. This is moved out of
        // validateFields() because it's expected to be easier for subclassing.
        foreach ($element['Fields'] as $name => $value) {
            if (!is_scalar($value)) {
                throw new InvalidArgumentException("'$name' field of $object_type_msg must be a scalar value.");
            }
        }

        // Do checks on 'id field' after validate*() methods, so they can still
        // change it even though that's not officially their purpose.
        if (!empty($definitions['id_property'])) {
            $id_property = '@' . $definitions['id_property'];

            if (isset($element[$id_property])) {
                if (!is_int($element[$id_property]) && !is_string($element[$id_property])) {
                    throw new InvalidArgumentException("'$id_property' property in $object_type_msg must hold integer/string value.");
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
                    throw new InvalidArgumentException("'$id_property' property in $object_type_msg must have a value, or Action '$action' must be set to 'insert'.");
                }
            }
        }

        if ($validation_behavior & self::VALIDATE_NO_UNKNOWN) {
            // Validate that all Fields/Objects/other properties are known.
            // This is a somewhat superfluous check because we already do this
            // in addObjectData() (where we more or less have to, because our
            // input is not divided over 'Objects' and 'Fields' so
            // addObjectData() has to decide how/where to set each property).
            if (!empty($element['Fields']) && $unknown = array_diff_key($element['Fields'], $definitions['fields'])) {
                throw new UnexpectedValueException("Unknown field(s) encountered in $object_type_msg: " , implode(', ', array_keys($unknown)));
            }
            if (!empty($element['Objects']) && !empty($definitions['objects']) && $unknown = array_diff_key($element['Objects'], $definitions['objects'])) {
                throw new UnexpectedValueException("Unknown object(s) encountered in $object_type_msg: " , implode(', ', array_keys($unknown)));
            }
            $known_properties = ['Fields' => true, 'Objects' => true];
            if (!empty($definitions['id_property'])) {
                $known_properties['@' . $definitions['id_property']] = true;
            }
            if ($unknown = array_diff_key($element, $known_properties)) {
                throw new UnexpectedValueException("Unknown properties encountered in $object_type_msg: " , implode(', ', array_keys($unknown)));
            }
        }

        return $element;
    }

    /**
     * Validates an element's embedded objects and replaces them by arrays.
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
     *   (Optional) see output(). Note that this is the behavior for the
     *   complete object, and may still need to be amended to apply to embedded
     *   objects.
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
    protected function validateEmbeddedObjects(array $element, $element_index, $change_behavior = self::VALIDATE_ALLOW_DEFAULT, $validation_behavior = self::VALIDATE_DEFAULT)
    {
        $element = $this->validateReferenceFields($element, $element_index, $change_behavior, $validation_behavior);

        if (isset($element['Objects'])) {
            $definitions = $this->getPropertyDefinitions($element, $element_index);
            $element_descr = "'{$this->getType()}' element" . ($element_index ? ' with index ' . ($element_index + 1) : '');
            // VALIDATE_ALLOW_EMBEDDED_CHANGES has no effect on
            // ADD_ELEMENT_WRAPPER; if that is set, it keeps being set, because
            // it's more or less 'owned' by output(), and doesn't really
            // influence the structure of objects/elements themselves. That
            // last thing also applies to FLATTEN_SINGLE_ELEMENT so let's keep
            // that too.
            $embedded_change_behavior = $change_behavior & self::VALIDATE_ALLOW_EMBEDDED_CHANGES
                ? $change_behavior
                : $change_behavior & (self::VALIDATE_ALLOW_EMBEDDED_CHANGES | self::FLATTEN_SINGLE_ELEMENT);

            foreach ($element['Objects'] as $ref_name => $object) {
                // Doublecheck; unlikely to fail because it's also in
                // addObjectData().
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
                // Validation of a full object is done by getObjectData() (if
                // we want to get an array structure returned).
                $object_data = $object->getObjectData($cb, $validation_behavior);

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
     * own object', not the contents of the embedded objects; the objects' own
     * getObjectData() / validateElement() / ... calls are responsible for that.
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
     *   (Optional) see output(). Note that this is the behavior for the
     *   complete object, and may still need to be amended to apply to embedded
     *   objects.
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
    protected function validateReferenceFields(array $element, $element_index, $change_behavior = self::VALIDATE_ALLOW_DEFAULT, $validation_behavior = self::VALIDATE_DEFAULT)
    {
        $definitions = $this->getPropertyDefinitions();
        if (!isset($definitions['objects'])) {
            return $element;
        }
        $action = $this->getAction($element_index);
        $defaults_allowed = ($action === 'insert' && $change_behavior & self::VALIDATE_ALLOW_DEFAULTS_ON_INSERT)
            || ($action === 'update' && $change_behavior & self::VALIDATE_ALLOW_DEFAULTS_ON_UPDATE);

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
    protected function validateFields(array $element, $element_index, $change_behavior = self::VALIDATE_ALLOW_DEFAULT, $validation_behavior = self::VALIDATE_DEFAULT)
    {
        $definitions = $this->getPropertyDefinitions();
        $action = $this->getAction($element_index);
        $defaults_allowed = ($action === 'insert' && $change_behavior & self::VALIDATE_ALLOW_DEFAULTS_ON_INSERT)
            || ($action === 'update' && $change_behavior & self::VALIDATE_ALLOW_DEFAULTS_ON_UPDATE);

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
            if ($change_behavior & self::VALIDATE_ALLOW_REFORMAT && isset($element['Fields'][$name]) && is_string($element['Fields'][$name])) {
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
     *   - VALIDATE_ALLOW_NO_CHANGES: Do not allow any changes. This value loses
     *     its meaning when passed together with other values.
     *   - VALIDATE_ALLOW_EMBEDDED_CHANGES (default): Allow changes to be made
     *     to embedded objects. If it is specified, the other bitmasks determine
     *     which changes can be made to embedded objects (so if only this value
     *     is specified, no changes are allowed to either this object or its
     *     embedded objects). If it is not specified, the other bitmasks
     *     determine only which changes can be made to this object, but any
     *     changes to embedded objects are disallowed.
     *   - VALIDATE_ALLOW_DEFAULTS_ON_INSERT (default): Allow adding default
     *     values to empty fields when inserting a new object. Note that even if
     *     this value is not specified, there are still some 'essential' values
     *     which can be set in the object; see getDefaults(,$essential_only).
     *   - VALIDATE_ALLOW_DEFAULTS_ON_UPDATE: Allow adding default values to
     *     empty fields when updating an existing object. Also see
     *     VALIDATE_ALLOW_DEFAULTS_ON_INSERT.
     *   - VALIDATE_ALLOW_REFORMAT (default): Allow reformatting of singular
     *     field values. For 'reformatting' a combination of values (e.g. moving
     *     a house number from a street value into its own field) additional
     *     values may need to be passed.
     *   - VALIDATE_ALLOW_CHANGES: Allow changing field values, in
     *     ways not covered by other bitmasks. Behavior is not precisely defined
     *     by this class; child classes may use this value or implement their
     *     own additional bitmasks.
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
     *     an address object.
     *   - VALIDATE_NO_UNKNOWN (default): Check if all fields and objects
     *     (reference fields) are known in our 'properties' definition, and if
     *     no unknown other properties (on the same level as 'Fields' and
     *     'Objects') exist. If this option is turned off, this may cause
     *     unknown values to be included in the output, with unknown results
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
    public function output($format = 'json', array $format_options = [], $change_behavior = self::VALIDATE_ALLOW_DEFAULT, $validation_behavior = self::VALIDATE_DEFAULT)
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
                // getObjectData() returns a one-element array with key
                // 'Element' and value being the one or several elements in
                // this DataObject. The JSON structure should be like this
                // except it needs another one-element array wrapper with key
                // being the object type.
                $data = [$this->getType() => $this->getObjectData($change_behavior | self::ADD_ELEMENT_WRAPPER, $validation_behavior)];
                return empty($format_options['pretty']) ? json_encode($data) : json_encode($data, JSON_PRETTY_PRINT);

            case 'xml':
                // The XML also needs one 'outer' wrapper tag (only around the
                // full object, not around embedded ones), but since this
                // calls output() recursively, we just take care of that
                // inside outputXml().
                return $this->outputXml($format_options, $change_behavior, $validation_behavior);

            default:
                throw new InvalidArgumentException("Invalid format '$format'.");
        }
    }

    /**
     * Encode data as XML, suitable for sending through SOAP connector.
     *
     * @param array $format_options
     *    (Optional) Options influencing the format. Known options:
     *    'pretty' (boolean): see output().
     *    'indent' (integer): see output().
     *   - 'indent_start' (string): a prefix to start each line with.
     * @param int $change_behavior
     *   (Optional) see output().
     * @param int $validation_behavior
     *   (Optional) see output().
     *
     * @return string
     *   XML payload to send to an Update Connector on a SOAP API/Connection.
     */
    protected function outputXml(array $format_options = [], $change_behavior = self::VALIDATE_ALLOW_DEFAULT, $validation_behavior = self::VALIDATE_DEFAULT)
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
        foreach ($this->getObjectData($cb, $validation_behavior) as $element_index => $element) {
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
            // JSON string),
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
     * The return value may or may not contain properties named 'default'; it's
     * better not to trust those but to call getDefaults() instead.
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
     *                 populated. If (int)1, this is done even if output() is
     *                 not instructed to validated required values; this can be
     *                 useful to set if it is known that AFAS itself will throw
     *                 an unclear error when it receives no value for the field.
     *   'objects':  Arrays describing properties of the 'object reference
     *               fields' defined for this object type, keyed by their names.
     *               An array may be empty but must be defined for an embedded
     *               object to be recognized. Properties known to this class:
     *    - 'type':    The name of the AFAS object type which this reference
     *                 points to. If not provided, the type is assumed to be
     *                 equal to the name of the reference field. (It's likely
     *                 that AFAS does not explain the difference between 'type'
     *                 and 'reference field' names anywhere, but there must be
     *                 a difference if e.g. one object has two fields
     *                 referencing object of the same type. See the code.)
     *   - 'alias':    A name for this field that can be used instead of the
     *                 AFAS name and that can be used in input data structures.
     *   - 'multiple': If true, the embedded object can hold more than one
     *                 element.
     *   - 'required': See 'fields' above.
     */
    public function getPropertyDefinitions()
    {
        switch ($this->parentType) {

        }
    }

    /**
     * Returns default values to fill for properties of an object.
     *
     * @param array $element
     *   (Optional) the element to derive defaults for: some defaults are
     *   dependent on the presence of other values. (This is usually the only
     *   element present in $this->elements, but it's passed into this method as
     *   an argument because object can hold more than one element.)
     * @param bool $essential_only
     *   (Optional) If true, don't return 'regular' defaults but still return
     *   defaults for fields that always need to have values filled. (Those
     *   values are usually not for 'real' fields, but for metadata or a kind of
     *   'change record'.)
// @TODO this is not implemented. I hope it can be scrapped.
     *
     * @return array
     *   An array with up to two keys (other keys will be ignored):
     *   'fields':  An array with default values keyed by their field names.
     *              This key is mandatory (unless the return value is empty).
     *   'objects': An array with default values keyed by the names which AFAS
     *              uses for embedded objects in this specific object type.
     *              Values are data structures in a format that would be valid
     *              input for this class.
     */
    public function getDefaults(array $element = [], $essential_only = false)
    {
        // Note this method contains no mechanism to allow for defaults (whether
        // essential or not ) that is only returned for a specific action (e.g.
        // only on insert). We hope this is not necessary and having enabled
        // the user to get defaults on update/insert (in validate()) is
        // enough.

        // @todo extract defaults from property definitions.

        // @todo translplant all default! / action dependent logic here, not in getProperties
    }

    /**
     * Maps ISO to AFAS country code.
     * (Note: this function is not complete yet, it only does Europe correctly.)
     *
     * @param string $isocode
     *   ISO9166 2-letter country code
     *
     * @return string
     *   AFAS country code
     */
    public static function convertIsoCountryCode($isocode)
    {
        // European codes we know to NOT match the 2-letter ISO codes:
        $cc = [
            'AT' => 'A',
            'BE' => 'B',
            'DE' => 'D',
            'ES' => 'E',
            'FI' => 'FIN',
            'FR' => 'F',
            'HU' => 'H',
            'IT' => 'I',
            'LU' => 'L',
            'NO' => 'N',
            'PT' => 'P',
            'SE' => 'S',
            'SI' => 'SLO',
        ];
        if (!empty($cc[strtoupper($isocode)])) {
            return $cc[strtoupper($isocode)];
        }
        // Return the input string (uppercased), or '' if the code is unknown.
        return static::convertCountryName($isocode, 1);
    }

    /**
     * Maps country name to AFAS country code.
     *
     * @param string $name
     *   Country name
     * @param int $default_behavior
     *   Code for default behavior if name is not found:
     *   0: always return empty string
     *   1: if $name itself is equal to a country code, return that code (always
     *      uppercased). So the function accepts codes as well as names.
     *   2: return the (non-uppercased) original string as default, even though
     *      it is apparently not a legal code.
     *   3: 1 + 2.
     *   4: return NL instead of '' as the default. (Because AFAS is used in NL
     *      primarily.)
     *   5: 1 + 4.
     *
     * @return string
     *   Country name, or NL / '' if not found.
     */
    public static function convertCountryName($name, $default_behavior = 0)
    {
        // We define a flipped array here because it looks nicer / I just don't want
        // to bother changing it around :p. In the future we could have this array
        // map multiple names to the same country code, in which case we need to
        // flip the keys/values.
        $codes = array_flip(array_map('strtolower', [
            'AFG' => 'Afghanistan',
            'AL' => 'Albania',
            'DZ' => 'Algeria',
            'ASM' => 'American Samoa',
            'AND' => 'Andorra',
            'AO' => 'Angola',
            'AIA' => 'Anguilla',
            'AG' => 'Antigua and Barbuda',
            'RA' => 'Argentina',
            'AM' => 'Armenia',
            'AUS' => 'Australia',
            'A' => 'Austria',
            'AZ' => 'Azerbaijan',
            'BS' => 'Bahamas',
            'BRN' => 'Bahrain',
            'BD' => 'Bangladesh',
            'BDS' => 'Barbados',
            'BY' => 'Belarus',
            'B' => 'België',
            'BH' => 'Belize',
            'BM' => 'Bermuda',
            'DY' => 'Benin',
            'BT' => 'Bhutan',
            'BOL' => 'Bolivia',
            'BA' => 'Bosnia and Herzegowina',
            'RB' => 'Botswana',
            'BR' => 'Brazil',
            'BRU' => 'Brunei Darussalam',
            'BG' => 'Bulgaria',
            'BU' => 'Burkina Faso',
            'RU' => 'Burundi',
            'K' => 'Cambodia',
            'TC' => 'Cameroon',
            'CDN' => 'Canada',
            'CV' => 'Cape Verde',
            'RCA' => 'Central African Republic',
            'TD' => 'Chad',
            'RCH' => 'Chile',
            'CN' => 'China',
            'CO' => 'Colombia',
            'KM' => 'Comoros',
            'RCB' => 'Congo',
            'CR' => 'Costa Rica',
            'CI' => 'Cote D\'Ivoire',
            'HR' => 'Croatia',
            'C' => 'Cuba',
            'CY' => 'Cyprus',
            'CZ' => 'Czech Republic',
            'DK' => 'Denmark',
            'DJI' => 'Djibouti',
            'WD' => 'Dominica',
            'DOM' => 'Dominican Republic',
            'TLS' => 'East Timor',
            'EC' => 'Ecuador',
            'ET' => 'Egypt',
            'EL' => 'El Salvador',
            'CQ' => 'Equatorial Guinea',
            'ERI' => 'Eritrea',
            'EE' => 'Estonia',
            'ETH' => 'Ethiopia',
            'FLK' => 'Falkland Islands (Malvinas)',
            'FRO' => 'Faroe Islands',
            'FJI' => 'Fiji',
            'FIN' => 'Finland',
            'F' => 'France',
            'GF' => 'French Guiana',
            'PYF' => 'French Polynesia',
            'ATF' => 'French Southern Territories',
            'GA' => 'Gabon',
            'WAG' => 'Gambia',
            'GE' => 'Georgia',
            'D' => 'Germany',
            'GH' => 'Ghana',
            'GIB' => 'Gibraltar',
            'GR' => 'Greece',
            'GRO' => 'Greenland',
            'WG' => 'Grenada',
            'GP' => 'Guadeloupe',
            'GUM' => 'Guam',
            'GCA' => 'Guatemala',
            'GN' => 'Guinea',
            'GW' => 'Guinea-bissau',
            'GUY' => 'Guyana',
            'RH' => 'Haiti',
            'HMD' => 'Heard and Mc Donald Islands',
            'HON' => 'Honduras',
            'HK' => 'Hong Kong',
            'H' => 'Hungary',
            'IS' => 'Iceland',
            'IND' => 'India',
            'RI' => 'Indonesia',
            'IR' => 'Iran (Islamic Republic of)',
            'IRQ' => 'Iraq',
            'IRL' => 'Ireland',
            'IL' => 'Israel',
            'I' => 'Italy',
            'JA' => 'Jamaica',
            'J' => 'Japan',
            'HKJ' => 'Jordan',
            'KZ' => 'Kazakhstan',
            'EAK' => 'Kenya',
            'KIR' => 'Kiribati',
            'KO' => 'Korea, Democratic People\'s Republic of',
            'ROK' => 'Korea, Republic of',
            'KWT' => 'Kuwait',
            'KG' => 'Kyrgyzstan',
            'LAO' => 'Lao People\'s Democratic Republic',
            'LV' => 'Latvia',
            'RL' => 'Lebanon',
            'LS' => 'Lesotho',
            'LB' => 'Liberia',
            'LAR' => 'Libyan Arab Jamahiriya',
            'FL' => 'Liechtenstein',
            'LT' => 'Lithuania',
            'L' => 'Luxembourg',
            'MO' => 'Macau',
            'MK' => 'Macedonia, The Former Yugoslav Republic of',
            'RM' => 'Madagascar',
            'MW' => 'Malawi',
            'MAL' => 'Malaysia',
            'MV' => 'Maldives',
            'RMM' => 'Mali',
            'M' => 'Malta',
            'MAR' => 'Marshall Islands',
            'MQ' => 'Martinique',
            'RIM' => 'Mauritania',
            'MS' => 'Mauritius',
            'MYT' => 'Mayotte',
            'MEX' => 'Mexico',
            'MIC' => 'Micronesia, Federated States of',
            'MD' => 'Moldova, Republic of',
            'MC' => 'Monaco',
            'MON' => 'Mongolia',
            'MSR' => 'Montserrat',
            'MA' => 'Morocco',
            'MOC' => 'Mozambique',
            'BUR' => 'Myanmar',
            'SWA' => 'Namibia',
            'NR' => 'Nauru',
            'NL' => 'Nederland',
            'NPL' => 'Nepal',
            'NA' => 'Netherlands Antilles',
            'NCL' => 'New Caledonia',
            'NZ' => 'New Zealand',
            'NIC' => 'Nicaragua',
            'RN' => 'Niger',
            'WAN' => 'Nigeria',
            'NIU' => 'Niue',
            'NFK' => 'Norfolk Island',
            'MNP' => 'Northern Mariana Islands',
            'N' => 'Norway',
            'OMA' => 'Oman',
            'PK' => 'Pakistan',
            'PLW' => 'Palau',
            'PSE' => 'Palestina',
            'PA' => 'Panama',
            'PNG' => 'Papua New Guinea',
            'PY' => 'Paraguay',
            'PE' => 'Peru',
            'RP' => 'Philippines',
            'PCN' => 'Pitcairn',
            'PL' => 'Poland',
            'P' => 'Portugal',
            'PR' => 'Puerto Rico',
            'QA' => 'Qatar',
            'REU' => 'Reunion',
            'RO' => 'Romania',
            'RUS' => 'Russian Federation',
            'RWA' => 'Rwanda',
            'KN' => 'Saint Kitts and Nevis',
            'WL' => 'Saint Lucia',
            'WV' => 'Saint Vincent and the Grenadines',
            'WSM' => 'Samoa',
            'RSM' => 'San Marino',
            'ST' => 'Sao Tome and Principe',
            'AS' => 'Saudi Arabia',
            'SN' => 'Senegal',
            'SRB' => 'Serbia',
            'SY' => 'Seychelles',
            'WAL' => 'Sierra Leone',
            'SGP' => 'Singapore',
            'SK' => 'Slovakia (Slovak Republic)',
            'SLO' => 'Slovenia',
            'SB' => 'Solomon Islands',
            'SP' => 'Somalia',
            'ZA' => 'South Africa',
            'GS' => 'South Georgia and the South Sandwich Islands',
            'E' => 'Spain',
            'CL' => 'Sri Lanka',
            'SHN' => 'St. Helena',
            'SPM' => 'St. Pierre and Miquelon',
            'SUD' => 'Sudan',
            'SME' => 'Suriname',
            'SJM' => 'Svalbard and Jan Mayen Islands',
            'SD' => 'Swaziland',
            'S' => 'Sweden',
            'CH' => 'Switzerland',
            'SYR' => 'Syrian Arab Republic',
            'RC' => 'Taiwan',
            'TAD' => 'Tajikistan',
            'EAT' => 'Tanzania, United Republic of',
            'T' => 'Thailand',
            'TG' => 'Togo',
            'TK' => 'Tokelau',
            'TO' => 'Tonga',
            'TT' => 'Trinidad and Tobago',
            'TN' => 'Tunisia',
            'TR' => 'Turkey',
            'TMN' => 'Turkmenistan',
            'TCA' => 'Turks and Caicos Islands',
            'TV' => 'Tuvalu',
            'EAU' => 'Uganda',
            'UA' => 'Ukraine',
            'AE' => 'United Arab Emirates',
            'GB' => 'United Kingdom',
            'USA' => 'United States',
            'UMI' => 'United States Minor Outlying Islands',
            'ROU' => 'Uruguay',
            'OEZ' => 'Uzbekistan',
            'VU' => 'Vanuatu',
            'VAT' => 'Vatican City State (Holy See)',
            'YV' => 'Venezuela',
            'VN' => 'Viet Nam',
            'VGB' => 'Virgin Islands (British)',
            'VIR' => 'Virgin Islands (U.S.)',
            'WLF' => 'Wallis and Futuna Islands',
            'ESH' => 'Western Sahara',
            'YMN' => 'Yemen',
            'Z' => 'Zambia',
            'ZW' => 'Zimbabwe',
        ]));

        if (isset($codes[strtolower($name)])) {
            return $codes[$name];
        }
        if ($default_behavior | 1) {
            // Search for code inside array. If found, $name is a code.
            if (in_array(strtoupper($name), $codes, true)) {
                return strtoupper($name);
            }
        }
        if ($default_behavior | 2) {
            return $name;
        }
        if ($default_behavior | 4) {
            return 'NL';
        }
        return '';
    }

    /**
     * Checks if a string can be interpreted as a valid Dutch phone number.
     * (There's only a "Dutch" function since AFAS will have 99% Dutch clients.
     * Extended helper functionality can be added as needed.)
     *
     * @param string $phonenumber
     *   Phone number to be validated.
     *
     * @return array
     *   If not recognized, empty array. If recognized: 2-element array with
     *     area/ mobile code and local part - as input; not uniformly
     *     re-formatted yet.
     */
    public static function validateDutchPhoneNr($phonenumber)
    {
        /*
          Accepts:
              06-12345678
              06 123 456 78
              010-1234567
              +31 10-1234567
              +31-10-1234567
              +31 (0)10-1234567
              +3110-1234567
              020 123 4567
              (020) 123 4567
              0221-123456
              0221 123 456
              (0221) 123 456
          Rejects:
              010-12345678
              05-12345678
              061-2345678
              (06) 12345678
              123-4567890
              123 456 7890
              +31 010-1234567
        */

        // Area codes start with 0, +31 or the (now deprecated) '+31 (0)'.
        // Non-mobile area codes starting with 0 may be surrounded by brackets.
        foreach (
            [
                '((?:\+31[-\s]?(?:\(0\))?\s?|0)6)            # mobile
        [-\s]* ([1-9]\s*(?:[0-9]\s*){7})',

                '((?:\+31[-\s]?(?:\(0\))?\s?|0)[1-5789][0-9] # 3-digit area code...
        | \(0[1-5789][0-9]\))                        # (possibly between brackets...)
        [-\s]* ([1-9]\s*(?:[0-9]\s*){6})             # ...plus local number.',

                '((?:\+31[-\s]?(?:\(0\))?\s?|0)[1-5789][0-9]{2} # 4-digit area code...
        |\(0[1-5789][0-9]{2}\))                         # (possibly between brackets...)
        [-\s]* ([1-9]\s*(?:[0-9]\s*){5})                # ...plus local number.',
            ] as $regex) {

            if (preg_match('/^\s*' . $regex . '\s*$/x', $phonenumber, $matches)) {
                $return = [
                    strtr($matches[1], [' ' => '', '-' => '', '+31' => '0']),
                    $matches[2],
                ];
                // $return[0] is a space-less area code now, with or without trailing 0.
                // $return[1] is not formatted.
                if ($return[0][0] !== '0') {
                    $return[0] = "0$return[0]";
                }
                return $return;
            }
        }
        return [];
    }

    /**
     * Normalizes country_code, last_name, extracts search_name for use in
     * update connectors. This function can be called for an array containing
     * person data, address data, or both. See code details; it contains 'Dutch
     * specific' logic, which can be a nice time saver but is partly arbitrary
     * and not necessarily complete.
     *
     * This function only works if the keys in $data are all aliases (like
     * first_name), not original AFAS tag names (like FiNm)!
     *
     * Phone number reformatting has not been incorporated into this function,
     * because there is no uniform standard for it. (The 'official' standard
     * of (012) 3456789 is not what most people want, i.e. 012-3456789.) You'll
     * need to do this yourself additionally, using validateDutchPhoneNr().
     *
     * @param $data
     *   Array with person and/or address data.
     */
    public static function normalizePersonAddress(&$data)
    {

        if (!empty($data['country_code'])) {
            // country_code can contain names as well as ISO9166 country codes;
            // normalize it to AFAS code.
            // NOTE: country_code is assumed NOT to contain an AFAS 1/3 letter country
            // code (because who uses these anyway?); these would be emptied out!
            if (strlen($data['country_code']) > 3) {
                $data['country_code'] = static::convertCountryName($data['country_code'], 3);
            } else {
                $data['country_code'] = static::convertIsoCountryCode($data['country_code']);
            }
        }

        $matches = [];
        if (!empty($data['street']) && empty($data['house_number']) &&
            empty($data['house_number_ext'])
            // Split off house number and possible extension from street,
            // because AFAS has separate fields for those. We do this _only_ for
            // defined countries where the splitting of house numbers is common.
            // (This is a judgment call, and the list of countries is arbitrary,
            // but there's slightly less risk of messing up foreign addresses
            // that way.) 'No country' is assumed to be 'NL' since AFAS is
            // NL-centric.
            // This code comes from addressfield_tfnr module and was adjusted
            // later to conform to AFAS' definition of "extension".
            && (empty($data['country_code']) || in_array($data['country_code'],
                    ['B', 'D', 'DK', 'F', 'FIN', 'H', 'NL', 'NO', 'S']))
            && preg_match('/^
          (.*?\S) \s+ (\d+) # normal thoroughfare, followed by spaces and a number;
                            # non-greedy because for STREET NR1 NR2, "nr1" should
                            # end up in the number field, not "nr2".
          (?:\s+)?          # optionally separated by spaces
          (\S.{0,29})?      # followed by optional suffix of at most 30 chars (30 is the maximum in the AFAS UI)
          \s* $/x', $data['street'], $matches)
        ) { // x == extended regex pattern
            // More notes about the pattern:
            // - theoretically a multi-digit number could be split into
            //   $matches[2/3]; this does not happen because the 3rd match is
            //   non-greedy.
            // - for numbers like 2-a and 2/a, we include the -// into
            //   $matches[3] on purpose: if AFAS has suffix "-a" or "/a" it
            //   prints them like "2-a" or "2/a" when printing an address. On
            //   the other hand, if AFAS has suffix "a" or "3", it prints them
            //   like "2 a" or "2 3".
            $data['street'] = ltrim($matches[1]);
            $data['house_number'] = $matches[2];
            if (!empty($matches[3])) {
                $data['house_number_ext'] = rtrim($matches[3]);
            }
        } elseif (!empty($data['house_number']) && empty($data['house_number_ext'])) {
            // Split off extension from house number
            $matches = [];
            if (preg_match('/^ \s* (\d+) (?:\s+)? (\S.{0,29})? \s* $/x', $data['house_number'], $matches)) {
                // Here too, the last ? means $matches[2] may be empty, but
                // prevents a multi-digit number from being split into
                // $matches[1/2].
                if (!empty($matches[2])) {
                    $data['house_number'] = $matches[1];
                    $data['house_number_ext'] = rtrim($matches[2]);
                }
            }
        }

        if (!empty($data['last_name']) && empty($data['prefix'])) {
            // Split off (Dutch) prefix from last name.
            // NOTE: creepily hardcoded stuff. Spaces are necessary, and sometimes
            // ordering matters! ('van de' before 'van')
            $name = strtolower($data['last_name']);
            foreach ([
                         'de ',
                         'v.',
                         'v ',
                         'v/d ',
                         'v.d.',
                         'van de ',
                         'van der ',
                         'van ',
                         "'t "
                     ] as $value) {
                if (strpos($name, $value) === 0) {
                    $data['prefix'] = rtrim($value);
                    $data['last_name'] = trim(substr($data['last_name'], strlen($value)));
                    break;
                }
            }
        }

        // Set search name
        if (!empty($data['last_name']) && empty($data['search_name'])) {
            // Zoeknaam: we got no request for a special definition of this, so:
            $data['search_name'] = strtoupper($data['last_name']);
            // Max length is 10, and we don't need to be afraid of duplicates.
            if (strlen($data['search_name']) > 10) {
                $data['search_name'] = substr($data['search_name'], 0, 10);
            }
        }

        if (!empty($data['first_name']) && empty($data['initials'])) {
            $data['first_name'] = trim($data['first_name']);

            // Check if first name is really only initials. If so, move it.
            // AFAS' automatic resolving code in its new-(contact)person UI
            // thinks anything is initials if it contains a dot. It will thenx
            // prevents a place spaces in between every letter, but we won't do
            // that last part. (It may be good for user UI input, but coded data
            // does not expect it.)
            if (strlen($data['first_name']) == 1
                || strlen($data['first_name']) < 16
                && strpos($data['first_name'], '.') !== false
                && strpos($data['first_name'], ' ') === false
            ) {
                // Dot but no spaces, or just one letter: all initials; move it.
                $data['initials'] = strlen($data['first_name']) == 1 ?
                    strtoupper($data['first_name']) . '.' : $data['first_name'];
                unset($data['first_name']);
            } elseif (preg_match('/^[A-Za-z \-]+$/', $data['first_name'])) {
                // First name only contains letters, spaces and hyphens. In this
                // case (which is probeably stricter than the AFAS UI), create
                // initials.
                $data['initials'] = '';
                foreach (preg_split('/[- ]+/', $data['first_name']) as $part) {
                    // Don't separate initials by spaces, only dot.
                    $data['initials'] .= strtoupper(substr($part, 0, 1)) . '.';
                }
            }
            // Note if there's both a dot and spaces in 'first_name' we skip it.
        }
    }

    /**
     * Return info for a certain type definition. (A certain Update Connector.)
     *
     * This definition is based on what AFAS calls the 'XSD Schema' for SOAP,
     * which you can get though a Data Connector, and is amended with extra info
     * like more understandable aliases for the field names, and default values.
     *
     * AFAS installations with custom fields will typically want to extend this
     * method in a subclass. Its name can be injected into the Connection class,
     * for using Connection::sendData() with those custom fields. The same goes
     * for standard object types which have not been included yet below - and
     * everyone's willing to send PRs to add those to the library code.
     *
     * @param string $type
     *   The type of object / Update Connector.
     * @param string $parent_type
     *   (optional) If nonempty, the generated info will be tailored for
     *   embedding within the parent type; this can influence the presence of
     *   some fields.
     * @param array $data
     *   (optional) Input data to 'normalize' using the returned info. This can
     *   influence e.g. some defaults.
     * @param string $action
     *   (optional) Action to fill in 'fields' tag; can be "insert", "update",
     *   "delete", "". This can influence e.g. some defaults.
     *
     * @return array
     *   Array with possible keys: 'id_field', 'fields' and 'objects'. See
     *   the code. Empty array if the type is unknown.
     *
     * @see constructXml()
     * @see normalizeDataToSend()
     */
    public static function objectTypeInfo($type, $parent_type = '', array $data = [], $action = '')
    {

        $inserting = $action === 'insert';

        $info = [];
        switch ($type) {
            // Even though they are separate types, there is no standalone
            // updateConnector for addresses.
            case 'KnBasicAddressAdr':
            case 'KnBasicAddressPad':
                $info = [
                    'fields' => [
                        // Land (verwijzing naar: Land => AfasKnCountry)
                        'CoId' => [
                            'alias' => 'country_code',
                        ],
                        /*   PbAd = 'is postbusadres' (if True, HmNr has number of P.O. box)
                         *   Ad, HmNr, ZpCd are required.
                         *      (and a few lines below, the docs say:)
                         *   Rs is _also_ " 'essential', even if ResZip==true, because if Zip
                         *      could not be resolved, the specified value of Rs is taken."
                         *      So we'll make it required too.
                         *
                         * @todo The following needs to be tested, seems like a bug:
                         *   There should be no 'default!' here. It should be
                         *   either removed, or replaced by 'default'. I'm
                         *   guessing that it's the latter AND that the 'checks
                         *   on requiredness' should be abolished for updates.
                         *   (If we change it to 'default' now, no organisation
                         *   updates will succeed unless we explicitly set
                         *   PbAd everywhere. Which seems wrong. That's why I'm
                         *   not changing this, without testing.)
                         */
                        'PbAd' => [
                            'alias' => 'is_po_box',
                            'type' => 'boolean',
                            'required' => true,
                            'default!' => false,
                        ],
                        // Toev. voor straat
                        'StAd' => [],
                        // Straat
                        'Ad' => [
                            'alias' => 'street',
                            'required' => true,
                        ],
                        // Huisnummer
                        'HmNr' => [
                            'alias' => 'house_number',
                            'type' => 'long',
                        ],
                        // Toev. aan huisnr.
                        'HmAd' => [
                            'alias' => 'house_number_ext',
                        ],
                        // Postcode
                        'ZpCd' => [
                            'alias' => 'zip_code',
                            'required' => true,
                        ],
                        // Woonplaats (verwijzing naar: Woonplaats => AfasKnResidence)
                        'Rs' => [
                            'alias' => 'town',
                            'required' => true,
                        ],
                        // Adres toevoeging
                        'AdAd' => [],
                        // From "Organisaties toevoegen en wijzigen (UpdateConnector KnOrganisation)":
                        // Bij het eerste adres (in de praktijk bij een nieuw record) hoeft u geen begindatum aan te leveren in het veld 'BeginDate' genegeerd.
                        // Als er al een adres bestaat, geeft u met 'BeginDate' de ingangsdatum van de adreswijziging aan.
                        // Ingangsdatum adreswijziging (wordt genegeerd bij eerste datum)
                        'BeginDate' => [
                            'type' => 'date',
                            'default!' => date('Y-m-d', REQUEST_TIME),
                        ],
                        'ResZip' => [
                            'alias' => 'resolve_zip',
                            'type' => 'boolean',
                            'default!' => false,
                        ],
                    ],
                ];
                break;

            case 'KnContact':
                // This has no id_field. Updating standalone knContact values is
                // possible by passing BcCoOga + BcCoPer in an update structure.
                $info = [
                    'objects' => [
                        'KnBasicAddressAdr' => 'address',
                        'KnBasicAddressPad' => 'postal_address',
                    ],
                    'fields' => [
                        // Code organisatie
                        'BcCoOga' => [
                            'alias' => 'organisation_code',
                        ],
                        // Code persoon
                        'BcCoPer' => [
                            'alias' => 'person_code',
                        ],
                        // Postadres is adres
                        'PadAdr' => [
                            'type' => 'boolean',
                        ],
                        // Afdeling contact
                        'ExAd' => [],
                        // Functie (verwijzing naar: Tabelwaarde,Functie contact => AfasKnCodeTableValue)
                        'ViFu' => [],
                        // Functie op visitekaart
                        'FuDs' => [
                            // Abbreviates 'function description', but that seems too Dutch.
                            'alias' => 'job_title',
                        ],
                        // Correspondentie
                        'Corr' => [
                            'type' => 'boolean',
                        ],
                        // Voorkeursmedium (verwijzing naar: Tabelwaarde,Medium voor correspondentie => AfasKnCodeTableValue)
                        'ViMd' => [],
                        // Telefoonnr. werk
                        'TeNr' => [
                            'alias' => 'phone',
                        ],
                        // Fax werk
                        'FaNr' => [
                            'alias' => 'fax',
                        ],
                        // Mobiel werk
                        'MbNr' => [
                            'alias' => 'mobile',
                        ],
                        // E-mail werk
                        'EmAd' => [
                            'alias' => 'email',
                        ],
                        // Homepage
                        'HoPa' => [
                            'alias' => 'homepage',
                        ],
                        // Toelichting
                        'Re' => [
                            'alias' => 'comment',
                        ],
                        // Geblokkeerd
                        'Bl' => [
                            'alias' => 'blocked',
                            'type' => 'boolean',
                        ],
                        // T.a.v. regel
                        'AtLn' => [],
                        // Briefaanhef
                        'LeHe' => [],
                        // Sociale netwerken
                        'SocN' => [],
                        // Facebook
                        'Face' => [
                            'alias' => 'facebook',
                        ],
                        // LinkedIn
                        'Link' => [
                            'alias' => 'linkedin',
                        ],
                        // Twitter
                        'Twtr' => [
                            'alias' => 'twitter',
                        ],
                        // Persoon toegang geven tot afgeschermde deel van de portal(s)
                        'AddToPortal' => [
                            'type' => 'boolean',
                        ],
                        // E-mail toegang
                        'EmailPortal' => [],
                    ],
                ];
                if ($parent_type === 'KnOrganisation' || $parent_type === 'KnPerson') {
                    $info['fields'] += [
                        // Soort Contact
                        // Values:  AFD:Afdeling bij organisatie   AFL:Afleveradres
                        // if inside knOrganisation: + PRS:Persoon bij organisatie (alleen mogelijk i.c.m. KnPerson tak)
                        //
                        // The description in 'parent' update connectors' (KnOrganisation, knContact) KB pages is:
                        // "Voor afleveradressen gebruikt u de waarde 'AFL': <ViKc>AFL</ViKc>"
                        'ViKc' => [
                            'alias' => 'contact_type',
                        ],
                    ];

                    // According to the XSD, a knContact can contain a knPerson
                    // if it's inside a knOrganisation, but not if it's
                    // standalone.
                    if ($parent_type === 'KnOrganisation') {
                        $info['objects']['KnPerson'] = 'person';

                        // If we specify a person in the data too, 'Persoon' is
                        // the default.
                        if (!empty($data['KnPerson']) || !empty($data['person'])) {
                            $info['fields']['ViKc']['default'] = 'PRS';
                        }
                    }

                    unset($info['fields']['BcCoOga']);
                    unset($info['fields']['BcCoPer']);
                    unset($info['fields']['AddToPortal']);
                    unset($info['fields']['EmailPortal']);
                }
                break;

            case 'KnPerson':
                $info = [
                    'objects' => [
//            'KnBankAccount' => 'bank_account',
                        'KnBasicAddressAdr' => 'address',
                        'KnBasicAddressPad' => 'postal_address',
                        'KnContact' => 'contact',
                    ],
                    'fields' => [
                        // Postadres is adres
                        'PadAdr' => [
                            'type' => 'boolean',
                        ],
                        'AutoNum' => [
                            'type' => 'boolean',
                            // See below for a dynamic default
                        ],
                        /**
                         * If you specify MatchPer and if the corresponding fields have
                         * values, the difference between $action "update" and
                         * "insert" falls away: if there is a match (but only one) the
                         * existing record is updated. If there isn't, a new one is
                         * inserted. If there are multiple matches, or a wrong match method
                         * is specified, AFAS throws an error.
                         *
                         * We make sure that you must explicitly specify a value for this
                         * with $field_action "update" (and get an error if you don't), by
                         * setting the default - see further down.
                         *
                         * NOTE 20150215: updating/inserting a contact/person inside an
                         * organization is only possible by sending in an embedded
                         * knOrganisation -> knContact -> knPerson XML (as far as I know).
                         * But updating existing data is tricky.
                         * Updates-or-inserts work when specifying non-zero match_method, no
                         * BcCo numbers and no $action (if there are no multiple
                         * matches; those will yield an error).
                         * Specifying MatchPer=0 and BcCo for an existing org+person, and no
                         * $action, yields an AFAS error "Object variable or With
                         * block variable not set" (which is a Visual Basic error, pointing
                         * to an error in AFAS' program code). To bypass this error,
                         * $action "update" must be explicitly specified.
                         * When inserting new contact/person objects into an existing
                         * organization (without risking the 'multiple matches' error above)
                         * $action "update" + BcCo + MatchPer=0 must be specified for
                         * the organization, and $action "insert" must be specified
                         * for the contact/person object. (In normalizeDataToSend() use '#action'.)
                         *
                         * NOTE - for Qoony sources in 2011 (which inserted KnPerson objects
                         *   inside KnSalesRelationPer), BcCo value 3 had the comment
                         *   "match customer by mail". They used 3 until april 2014, when
                         *   suddenly updates broke, giving "organisation vs person objects"
                         *   and "multiple person objects found for these search criteria"
                         *   errors. So apparently the official description (below) was not
                         *   accurate until 2014, and maybe the above was implemented?
                         *   While fixing the breakage, AFAS introduced an extra value for
                         *   us:
                         * 9: always update the knPerson objects (which are at this moment
                         *    referenced by the outer object) with the given data.
                         *    (When inserting instead of updating data, I guess this falls
                         *    back to behavior '7', given our usage at Qoony.)
                         */
                        // Persoon vergelijken op
                        // Values:  0:Zoek op BcCo (Persoons-ID)   1:Burgerservicenummer   2:Naam + voorvoegsel + initialen + geslacht   3:Naam + voorvoegsel + initialen + geslacht + e-mail werk   4:Naam + voorvoegsel + initialen + geslacht + mobiel werk   5:Naam + voorvoegsel + initialen + geslacht + telefoon werk   6:Naam + voorvoegsel + initialen + geslacht + geboortedatum   7:Altijd nieuw toevoegen
                        'MatchPer' => [
                            'alias' => 'match_method',
                        ],
                        // Organisatie/persoon (intern)
                        // From "Organisaties toevoegen en wijzigen (UpdateConnector KnOrganisation)":
                        // "Do not deliver the 'BcId' field."
                        // (Because it really is internal. So why should we define it?)
                        //'BcId' => [
                        //  'type' => 'long',
                        //),
                        // Nummer, 1-15 chars
                        'BcCo' => [
                            // This is called "Nummer" here by AFAS but the field
                            // name itself obviously refers to 'code', and also
                            // a reference field in KnContact is called "Code persoon"
                            // by AFAS. Let's be consistent and call it "code" here too.
                            // ('ID' would be more confusing because it's not the internal ID.)
                            'alias' => 'code',
                        ],
                        'SeNm' => [
                            'alias' => 'search_name',
                        ],
                        // Roepnaam
                        'CaNm' => [
                            'alias' => 'name',
                        ],
                        // Voornaam
                        'FiNm' => [
                            'alias' => 'first_name',
                            'required' => true,
                        ],
                        // initials
                        'In' => [
                            'alias' => 'initials',
                        ],
                        'Is' => [
                            'alias' => 'prefix',
                        ],
                        'LaNm' => [
                            'alias' => 'last_name',
                            'required' => true,
                        ],
                        // Geboortenaam apart vastleggen
                        'SpNm' => [
                            'type' => 'boolean',
                            'default' => false,
                        ],
                        // Voorv. geb.naam
                        'IsBi' => [],
                        // Geboortenaam
                        'NmBi' => [],
                        // Voorvoegsel partner
                        'IsPa' => [],
                        // Geb.naam partner
                        'NmPa' => [],
                        // Naamgebruik (verwijzing naar: Tabelwaarde,Naamgebruik (meisjesnaam etc.) => AfasKnCodeTableValue)
                        // Values:  0:Geboortenaam   1:Geb. naam partner + Geboortenaam   2:Geboortenaam partner   3:Geboortenaam + Geb. naam partner
                        'ViUs' => [],
                        // Sex (M = Man, V = Vrouw, O = Onbekend)
                        'ViGe' => [
                            'alias' => 'gender',
                            'default' => 'O',
                            // The default is only for explicit inserts; see below. This means
                            // that for data which is ambiguous about being an insert or
                            // update, you must specify a value yourself, otherwise you get an
                            // error "Bij een persoon is het geslacht verplicht.".
                            // There is no other way; if we set a default here for non-inserts
                            // we risk silently overwriting the gender value present in AFAS.
                        ],
                        // Nationaliteit (verwijzing naar: Tabelwaarde,Nationaliteit (NEN1888) => AfasKnCodeTableValue)
                        // Values:  000:Onbekend   NL:Nederlandse   DZ:Algerijnse   AN:Angolese   RU:Burundische   RB:Botswaanse   BU:Burger van Burkina Faso   RCA:Centrafrikaanse   KM:Comorese   RCB:Kongolese   DY:Beninse   ET:Egyptische   EQ:Equatoriaalguinese   ETH:Etiopische   DJI:Djiboutiaanse   GA:Gabonese   WAG:Gambiaanse   GH:Ghanese   GN:Guinese   CI:Ivoriaanse   CV:Kaapverdische   TC:Kameroense   EAK:Kenyaanse   CD:ZaÃ¯rese   LS:Lesothaanse   LB:Liberiaanse   LAR:Libische   RM:Malagassische   MW:Malawische   RMM:Malinese   MA:Marokkaanse   RIM:Burger van Mauritanië   MS:Burger van Mauritius   MOC:Mozambiquaanse   SD:Swazische   RN:Burger van Niger   WAN:Burger van Nigeria   EAU:Ugandese   GW:Guineebissause   ZA:Zuidafrikaanse   ZW:Zimbabwaanse   RWA:Rwandese   ST:Burger van SÃ£o TomÃ© en Principe   SN:Senegalese   WAL:Sierraleoonse   SUD:Soedanese   SP:Somalische   EAT:Tanzaniaanse   TG:Togolese   TS:Tsjadische   TN:Tunesische   Z:Zambiaanse   ZSUD:Zuid-Soedanese   BS:Bahamaanse   BH:Belizaanse   CDN:Canadese   CR:Costaricaanse   C:Cubaanse   DOM:Burger van Dominicaanse Republiek   EL:Salvadoraanse   GCA:Guatemalteekse   RH:HaÃ¯tiaanse   HON:Hondurese   JA:Jamaicaanse   MEX:Mexicaanse   NIC:Nicaraguaanse   PA:Panamese   TT:Burger van Trinidad en Tobago   USA:Amerikaans burger   RA:Argentijnse   BDS:Barbadaanse   BOL:Boliviaanse   BR:Braziliaanse   RCH:Chileense   CO:Colombiaanse   EC:Ecuadoraanse   GUY:Guyaanse   PY:Paraguayaanse   PE:Peruaanse   SME:Surinaamse   ROU:Uruguayaanse   YV:Venezolaanse   WG:Grenadaanse   KN:Burger van Saint Kitts-Nevis   SK:Slowaakse   CZ:Tsjechische   BA:Burger van Bosnië-Herzegovina   GE:Burger van Georgië   AFG:Afgaanse   BRN:Bahreinse   BT:Bhutaanse   BM:Burmaanse   BRU:Bruneise   K:Kambodjaanse   CL:Srilankaanse   CN:Chinese   CY:Cyprische   RP:Filipijnse   TMN:Burger van Toerkmenistan   RC:Taiwanese   IND:Burger van India   RI:Indonesische   IRQ:Iraakse   IR:Iraanse   IL:Israëlische   J:Japanse   HKJ:Jordaanse   TAD:Burger van Tadzjikistan   KWT:Koeweitse   LAO:Laotiaanse   RL:Libanese   MV:Maldivische   MAL:Maleisische   MON:Mongolische   OMA:Omanitische   NPL:Nepalese   KO:Noordkoreaanse   OEZ:Burger van Oezbekistan   PK:Pakistaanse   KG:Katarese   AS:Saoediarabische   SGP:Singaporaanse   SYR:Syrische   T:Thaise   AE:Burger van de Ver. Arabische Emiraten   TR:Turkse   UA:Burger van Oekraine   ROK:Zuidkoreaanse   VN:Viëtnamese   BD:Burger van Bangladesh   KYR:Burger van Kyrgyzstan   MD:Burger van Moldavië   KZ:Burger van Kazachstan   BY:Burger van Belarus (Wit-Rusland)   AZ:Burger van Azerbajdsjan   AM:Burger van Armenië   AUS:Australische   PNG:Burger van Papua-Nieuwguinea   NZ:Nieuwzeelandse   WSM:Westsamoaanse   RUS:Burger van Rusland   SLO:Burger van Slovenië   AG:Burger van Antigua en Barbuda   VU:Vanuatuse   FJI:Fijische   GB4:Burger van Britse afhankelijke gebieden   HR:Burger van Kroatië   TO:Tongaanse   NR:Nauruaanse   USA2:Amerikaans onderdaan   LV:Letse   SB:Solomoneilandse   SY:Seychelse   KIR:Kiribatische   TV:Tuvaluaanse   WL:Sintluciaanse   WD:Burger van Dominica   WV:Burger van Sint Vincent en de Grenadinen   EW:Estnische   IOT:British National (overseas)   ZRE:ZaÃ¯rese (Congolese)   TLS:Burger van Timor Leste   SCG:Burger van Servië en Montenegro   SRB:Burger van Servië   MNE:Burger van Montenegro   LT:Litouwse   MAR:Burger van de Marshalleilanden   BUR:Myanmarese   SWA:Namibische   499:Staatloos   AL:Albanese   AND:Andorrese   B:Belgische   BG:Bulgaarse   DK:Deense   D:Duitse   FIN:Finse   F:Franse   YMN:Jemenitische   GR:Griekse   GB:Brits burger   H:Hongaarse   IRL:Ierse   IS:IJslandse   I:Italiaanse   YU:Joegoslavische   FL:Liechtensteinse   L:Luxemburgse   M:Maltese   MC:Monegaskische   N:Noorse   A:Oostenrijkse   PL:Poolse   P:Portugese   RO:Roemeense   RSM:Sanmarinese   E:Spaanse   VAT:Vaticaanse   S:Zweedse   CH:Zwitserse   GB2:Brits onderdaan   ERI:Eritrese   GB3:Brits overzees burger   MK:Macedonische   XK:Kosovaar
                        //
                        'PsNa' => [],
                        // Geboortedatum
                        'DaBi' => [],
                        // Geboorteland (verwijzing naar: Land => AfasKnCountry)
                        'CoBi' => [],
                        // Geboorteplaats (verwijzing naar: Woonplaats => AfasKnResidence)
                        'RsBi' => [],
                        // BSN
                        'SoSe' => [
                            'alias' => 'bsn',
                        ],
                        // Burgerlijke staat (verwijzing naar: Tabelwaarde,Burgerlijke staat => AfasKnCodeTableValue)
                        'ViCs' => [],
                        // Huwelijksdatum
                        'DaMa' => [],
                        // Datum scheiding
                        'DaDi' => [],
                        // Overlijdensdatum
                        'DaDe' => [],
                        // Titel/aanhef (verwijzing naar: Titel => AfasKnTitle)
                        'TtId' => [
                            // ALG was given in Qoony (where person was inside knSalesRelationPer).
                            // in newer environment where it's inside knOrganisation > knContact,
                            // I don't even see this one in an entry screen.
                            //'default' => 'ALG',
                        ],
                        // Tweede titel (verwijzing naar: Titel => AfasKnTitle)
                        'TtEx' => [],
                        // Briefaanhef
                        'LeHe' => [],
                        // Telefoonnr. werk
                        'TeNr' => [
                            // Note aliases change for KnSalesRelationPer, see below.
                            'alias' => 'phone',
                        ],
                        // Telefoonnr. privé
                        'TeN2' => [],
                        // Fax werk
                        'FaNr' => [
                            'alias' => 'fax',
                        ],
                        // Mobiel werk
                        'MbNr' => [
                            'alias' => 'mobile',
                        ],
                        // Mobiel privé
                        'MbN2' => [],
                        // E-mail werk
                        'EmAd' => [
                            'alias' => 'email',
                        ],
                        'EmA2' => [],
                        // Homepage
                        'HoPa' => [
                            'alias' => 'homepage',
                        ],
                        // Correspondentie
                        'Corr' => [
                            'type' => 'boolean',
                            'default' => false,
                        ],
                        // Voorkeursmedium (verwijzing naar: Tabelwaarde,Medium voor correspondentie => AfasKnCodeTableValue)
                        'ViMd' => [],
                        // Opmerking
                        'Re' => [
                            'alias' => 'comment',
                        ],
                        // Status (verwijzing naar: Tabelwaarde,Status verkooprelatie => AfasKnCodeTableValue)
                        'StId' => [],
                        // Sociale netwerken
                        'SocN' => [],
                        // Facebook
                        'Face' => [
                            'alias' => 'facebook',
                        ],
                        // LinkedIn
                        'Link' => [
                            'alias' => 'linkedin',
                        ],
                        // Twitter
                        'Twtr' => [
                            'alias' => 'twitter',
                        ],
                        // Naam bestand
                        'FileName' => [],
                        // Afbeelding (base64Binary field)
                        'FileStream' => [],
                        // Persoon toegang geven tot afgeschermde deel van de portal(s)
                        'AddToPortal' => [
                            'type' => 'boolean',
                        ],
                        // E-mail toegang
                        'EmailPortal' => [],
                    ],
                ];

                // First name is not required if initials are filled.
                if (!empty($data['In']) || !empty($data['initials'])) {
                    unset($info['fields']['FiNm']['required']);
                }

                // We're sure that the record will be newly inserted if MatchPer
                // specifies this. (We assume that this is the case even when
                // $action specifies "update", i.e. MatchPer overrides $action;
                // this is what we've documented elsewhere too. The only thing
                // $inserting effectively does so far, is add default values.)
                $inserting = isset($data['match_method']) || isset($data['MatchPer']) ?
                    $action !== 'delete' && (isset($data['match_method']) ? $data['match_method'] : $data['MatchPer'] == 7) :
                    $action === 'insert';

                // MatchPer defaults are first of all influenced by whether
                // we're inserting a record. (Code note: checking $inserting or
                // $action doesn't make a difference in practice; in principle
                // it's just strange that a field default would depend on the
                // field value.) For non-inserts, our principle is we would
                // rather insert duplicate data than silently overwrite data by
                // accident...
                if ($action === 'insert') {
                    $info['fields']['MatchPer']['default!'] = '7';
                } elseif (!empty($data['BcCo']) || !empty($data['code'])) {
                    // ...but it seems very unlikely that someone would specify BcCo when
                    // they don't explicitly want the corresponding record overwritten.
                    // So we match on BcCo in that case.
                    // Con: This overwrites existing data if there is a 'typo'
                    //      in the BcCo field.
                    // Pro: - Now people are not forced to think about this
                    //        field. (If we left it empty, they would likely
                    //        have to pass it.)
                    //      - Predictability. If we leave this empty, we don't
                    //        know what AFAS will do. (And if AFAS throws an
                    //        error, we're back to the user having to specify 0,
                    //        which means it's easier if we do it for them.)
                    $info['fields']['MatchPer']['default!'] = '0';
                } elseif (!empty($data['SoSe']) || !empty($data['bsn'])) {
                    // I guess we can assume the same logic for BSN, since
                    // that's supposedly also a unique number.
                    $info['fields']['MatchPer']['default!'] = '1';
                } else {
                    // Probably even with $action "update", a new record will be
                    // inserted if there is no match... but we do not know this for sure!
                    // Since our principle is to prevent silent overwrites of data, we
                    // here force an error for "update" if MatchPer is not explicitly
                    // specified in $data.
                    // (If you disagree / encounter circumstances where this is not OK,
                    // tell me so we can refine this. --Roderik.)
                    $info['fields']['MatchPer']['default!'] = '0';
                }

                if ($parent_type === 'KnContact' || $parent_type === 'KnSalesRelationPer') {
                    // Note: a knPerson cannot be inside a knContact directly. So far we
                    // know only of the situation where that knContact is again inside a
                    // knOrganisation.

                    $info['fields'] += [
                        // This field applies to a knPerson inside a knContact inside a
                        // knOrganisation:
                        // Land wetgeving (verwijzing naar: Land => AfasKnCountry)
                        'CoLw' => [],
                    ];
                }
                if ($parent_type === 'KnSalesRelationPer') {
                    // Usually, phone/mobile/e-mail aliases are set to the business
                    // ones, and these are the ones you see on the screen in the UI.
                    // Inside KnSalesRelationPer, you see the private equivalents in the
                    // UI. (At least that was the case for Qoony.) So it's those you want
                    // to fill by default.
                    $info['fields']['TeN2']['alias'] = $info['fields']['TeNr']['alias'];
                    unset($info['fields']['TeNr']['alias']);
                    $info['fields']['MbN2']['alias'] = $info['fields']['MbNr']['alias'];
                    unset($info['fields']['MbNr']['alias']);
                    $info['fields']['EmA2']['alias'] = $info['fields']['EmAd']['alias'];
                    unset($info['fields']['EmAd']['alias']);
                }
                break;

            case 'KnSalesRelationPer':
                // NOTE - not checked against XSD yet, only taken over from Qoony example
                // Fields:
                // ??? = Overheids Identificatienummer, which an AFAS expert recommended
                //       for using as a secondary-unique-id, when we want to insert an
                //       auto-numbered object and later retrieve it to get the inserted ID.
                //       I don't know what this is but it's _not_ 'OIN', I tried that.
                //       (In the end we never used this field.)
                $info = [
                    'id_field' => 'DbId',
                    'objects' => [
                        'KnPerson' => 'person',
                    ],
                    'fields' => [

                        // 'is debtor'?
                        'IsDb' => [
                            'type' => 'boolean',
                            'default' => true,
                        ],
                        // According to AFAS docs, PaCd / VaDu "are required if IsDb==True" ...
                        // no further specs. Heh, VaDu is not even in our inserted XML.
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

            case 'KnOrganisation':
                $info = [
                    'objects' => [
//            'KnBankAccount' => 'bank_account',
                        'KnBasicAddressAdr' => 'address',
                        'KnBasicAddressPad' => 'postal_address',
                        'KnContact' => 'contact',
                    ],
                    'fields' => [
                        // Postadres is adres
                        // (In a previous version we defaulted this to 'false'
                        // just like the PbAd field from the address objects,
                        // but it seems to mean something different - and seems
                        // to only make sense to set 'false' if people have
                        // explicitly added a postal address?
                        // @todo maybe make this default dependent on presence
                        //   of, _and_ difference in, two address objects?
                        'PbAd' => [
                            'alias' => 'postal_address_is_address',
                            'type' => 'boolean',
                            'default' => true,
                        ],
                        'AutoNum' => [
                            'alias' => 'auto_num',
                            'type' => 'boolean',
                        ],
                        /**
                         * If you specify MatchOga and if the corresponding fields have
                         * values, the difference between $action "update" and
                         * "insert" falls away: if there is a match (but only one) the
                         * existing record is updated. If there isn't, a new one is
                         * inserted. If there are multiple matches, or a wrong match method
                         * is specified, AFAS throws an error.
                         *
                         * We make sure that you must explicitly specify a value for this
                         * with $field_action "update" (and get an error if you don't), by
                         * setting the default - see further down.
                         */
                        // Organisatie vergelijken op
                        // Values:  0:Zoek op BcCo   1:KvK-nummer   2:Fiscaal nummer   3:Naam   4:Adres   5:Postadres   6:Altijd nieuw toevoegen
                        'MatchOga' => [
                            'alias' => 'match_method',
                        ],
                        // Organisatie/persoon (intern)
                        // From "Organisaties toevoegen en wijzigen (UpdateConnector KnOrganisation)":
                        // "Do not deliver the 'BcId' field."
                        // (Because it really is internal. So why should we define it?)
                        //'BcId' => [
                        //),
                        // Nummer, 1-15 chars
                        'BcCo' => [
                            // This is called "Nummer" here by AFAS but the field
                            // name itself obviously refers to 'code', and also
                            // a reference field in KnContact is called "Code organisatie"
                            // by AFAS. Let's be consistent and call it "code" here too.
                            // ('ID' would be more confusing because it's not the internal ID.)
                            'alias' => 'code',
                        ],
                        'SeNm' => [
                            'alias' => 'search_name',
                            // @todo dynamic defaults for this and voorletter?
                        ],
                        // Name. Is not required officially, but I guess you must fill in either
                        // BcCo, SeNm or Nm to be able to find the record back. (Or maybe you get an
                        // error if you don't specify any.)
                        'Nm' => [
                            'alias' => 'name',
                        ],
                        // Rechtsvorm (verwijzing naar: Tabelwaarde,Rechtsvorm => AfasKnCodeTableValue)
                        'ViLe' => [
                            'alias' => 'org_type',
                        ],
                        // Branche (verwijzing naar: Tabelwaarde,Branche => AfasKnCodeTableValue)
                        'ViLb' => [
                            'alias' => 'branche',
                        ],
                        // KvK-nummer
                        'CcNr' => [
                            'alias' => 'coc_number',
                        ],
                        // Datum KvK
                        'CcDa' => [
                            'type' => 'date',
                        ],
                        // Naam (statutair)
                        'NmRg' => [],
                        // Vestiging (statutair)
                        'RsRg' => [],
                        // Titel/aanhef (verwijzing naar: Titel => AfasKnTitle)
                        'TtId' => [],
                        // Briefaanhef
                        'LeHe' => [],
                        // Organisatorische eenheid (verwijzing naar: Organisatorische eenheid => AfasKnOrgUnit)
                        'OuId' => [],
                        // Telefoonnr. werk
                        'TeNr' => [
                            'alias' => 'phone',
                        ],
                        // Fax werk
                        'FaNr' => [
                            'alias' => 'fax',
                        ],
                        // Mobiel werk
                        'MbNr' => [
                            'alias' => 'mobile',
                        ],
                        // E-mail werk
                        'EmAd' => [
                            'alias' => 'email',
                        ],
                        // Homepage
                        'HoPa' => [
                            'alias' => 'homepage',
                        ],
                        // Correspondentie
                        'Corr' => [
                            'type' => 'boolean',
                        ],
                        // Voorkeursmedium (verwijzing naar: Tabelwaarde,Medium voor correspondentie => AfasKnCodeTableValue)
                        'ViMd' => [],
                        // Opmerking
                        'Re' => [
                            'alias' => 'comment',
                        ],
                        // Fiscaalnummer
                        'FiNr' => [
                            'alias' => 'fiscal_number',
                        ],
                        // Status (verwijzing naar: Tabelwaarde,Status verkooprelatie => AfasKnCodeTableValue)
                        'StId' => [],
                        // Sociale netwerken
                        'SocN' => [],
                        // Facebook
                        'Face' => [
                            'alias' => 'facebook',
                        ],
                        // LinkedIn
                        'Link' => [
                            'alias' => 'linkedin',
                        ],
                        // Twitter
                        'Twtr' => [
                            'alias' => 'twitter',
                        ],
                        // Onderdeel van organisatie (verwijzing naar: Organisatie/persoon => AfasKnBasicContact)
                        'BcPa' => [],
                    ],
                ];

                // We're sure that the record will be newly inserted if MatchOga
                // specifies this. (We assume that this is the case even when
                // $action specifies "update", i.e. MatchOga overrides $action;
                // this is what we've documented elsewhere too. The only thing
                // $inserting effectively does so far, is add default values.)
                $inserting = isset($data['match_method']) || isset($data['MatchOga']) ?
                    $action !== 'delete' && (isset($data['match_method']) ? $data['match_method'] : $data['MatchOga'] == 6) :
                    $action === 'insert';

                // MatchOga defaults are first of all influenced by whether
                // we're inserting a record. (Code note: checking $inserting or
                // $action doesn't make a difference in practice; in principle
                // it's just strange that a field default would depend on the
                // field value.) For non-inserts, our principle is we would
                // rather insert duplicate data than silently overwrite data by
                // accident...
                if ($action === 'insert') {
                    $info['fields']['MatchOga']['default!'] = '6';
                } elseif (!empty($data['BcCo']) || !empty($data['code'])) {
                    // ...but it seems very unlikely that someone would specify BcCo when
                    // they don't explicitly want the corresponding record overwritten.
                    // So we match on BcCo in that case. See pros/cons at MatchPer.
                    $info['fields']['MatchOga']['default!'] = '0';
                } elseif (!empty($data['CcNr']) || !empty($data['coc_number'])) {
                    // I guess we can assume the same logic for KvK number, since
                    // that's supposedly also a unique number.
                    $info['fields']['MatchOga']['default!'] = '1';
                } elseif (!empty($data['FiNr']) || !empty($data['fiscal_number'])) {
                    // ...and fiscal number.
                    $info['fields']['MatchOga']['default!'] = '2';
                } else {
                    // Probably even with $action "update", a new record will be
                    // inserted if there is no match... but we do not know this for sure!
                    // Since our principle is to prevent silent overwrites of data, we
                    // here force an error for "update" if MatchOga is not explicitly
                    // specified in $data.
                    // (If you disagree / encounter circumstances where this is not OK,
                    // tell me so we can refine this. --Roderik.)
                    $info['fields']['MatchOga']['default!'] = '0';
                }
                break;

            case 'KnSubject':
                $info = [
                    'id_field' => 'SbId',
                    'objects' => [
                        'KnSubjectLink' => 'subject_link',
                        'KnS01' => 'subject_link_1',
                        'KnS02' => 'subject_link_2',
                        // If there are more KnSNN, they have all custom fields?
                    ],
                    'fields' => [
                        // Type dossieritem (verwijzing naar: Type dossieritem => AfasKnSubjectType)
                        'StId' => [
                            'alias' => 'type',
                            'type' => 'long',
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
                            'type' => 'long',
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
                            'type' => 'long',
                        ],
                        // Waarde kenmerk 2 (verwijzing naar: Waarde kenmerk => AfasKnFeatureValue)
                        'FvF2' => [
                            'type' => 'long',
                        ],
                        // Waarde kenmerk 3 (verwijzing naar: Waarde kenmerk => AfasKnFeatureValue)
                        'FvF3' => [
                            'type' => 'long',
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
                $info = [
                    'id_field' => 'SbId',
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
                            'type' => 'long',
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
                            'type' => 'long',
                        ],
                        // Administratie (Verkoop) (verwijzing naar: Administratie => AfasKnUnit)
                        'SiUn' => [
                            'type' => 'long',
                        ],
                        // Factuurtype (verkoop) (verwijzing naar: Type factuur => AfasFiInvoiceType)
                        'SiTp' => [
                            'alias' => 'sales_invoice_type',
                            'type' => 'long',
                        ],
                        // Verkoopfactuur (verwijzing naar: Factuur => AfasFiInvoice)
                        'SiId' => [
                            'alias' => 'sales_invoice',
                        ],
                        // Administratie (Inkoop) (verwijzing naar: Administratie => AfasKnUnit)
                        'PiUn' => [
                            'type' => 'long',
                        ],
                        // Factuurtype (inkoop) (verwijzing naar: Type factuur => AfasFiInvoiceType)
                        'PiTp' => [
                            'alias' => 'purchase_invoice_type',
                            'type' => 'long',
                        ],
                        // Inkoopfactuur (verwijzing naar: Factuur => AfasFiInvoice)
                        'PiId' => [
                            'alias' => 'purchase_invoice',
                        ],
                        // Fiscaal jaar (verwijzing naar: Aangiftejaren => AfasTxDeclarationYear)
                        'FiYe' => [
                            'alias' => 'fiscal_year',
                            'type' => 'long',
                        ],
                        // Project (verwijzing naar: Project => AfasPtProject)
                        'PjId' => [
                            'alias' => 'project',
                        ],
                        // Campagne (verwijzing naar: Campagne => AfasCmCampaign)
                        'CaId' => [
                            'alias' => 'campaign',
                            'type' => 'long',
                        ],
                        // Actief (verwijzing naar: Vaste activa => AfasFaFixedAssets)
                        'FaSn' => [
                            'type' => 'long',
                        ],
                        // Voorcalculatie (verwijzing naar: Voorcalculatie => AfasKnQuotation)
                        'QuId' => [],
                        // Dossieritem (verwijzing naar: Dossieritem => AfasKnSubject)
                        'SjId' => [
                            'type' => 'long',
                        ],
                        // Abonnement (verwijzing naar: Abonnement => AfasFbSubscription
                        'SuNr' => [
                            'alias' => 'subscription',
                            'type' => 'long',
                        ],
                        // Dienstverband
                        'DvSn' => [
                            'type' => 'long',
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
                            'type' => 'long',
                        ],
                        // Verzuimmelding (verwijzing naar: Verzuimmelding => AfasHrAbsIllnessMut)
                        'AbId' => [
                            'type' => 'long',
                        ],
                        // Forecast (verwijzing naar: Forecast => AfasCmForecast)
                        'FoSn' => [
                            'type' => 'long',
                        ],
                    ],
                ];
                break;

            // Subject link #1 (after KnSubjectLink), to be sent inside KnSubject.
            // The field names are not custom fields, but are the definitions general?
            // Not 100% sure.
            case 'KnS01':
                $info = [
                    'id_field' => 'SbId',
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
                $info = [
                    'id_field' => 'SbId',
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

            case 'FbSales':
                $info = [
                    'objects' => [
                        // @todo just be strict, and make it so that some child objects must come
                        //   as multiple values, and others can't? It seems like we might be able to predict that.
                        // (Or maybe "cannot be multiple" is for verification, but "should be multiple" still accepts singular but will always output array?)
                        'FbSalesLines' => 'line_items',
                    ],
                    'fields' => [
                        // Nummer
                        'OrNu' => [],
                        // Datum
                        'OrDa' => [
                            'alias' => 'date',
                            'type' => 'date',
                        ],
                        // Verkooprelatie (verwijzing naar: Verkooprelatie => AfasKnSalRelation)
                        'DbId' => [
                            'alias' => 'sales_relation',
                        ],
                        // Gewenste leverdatum
                        'DaDe' => [
                            'alias' => 'delivery_date_req',
                            'type' => 'date',
                        ],
                        // Datum levering (toegezegd)
                        'DaPr' => [
                            'alias' => 'delivery_date_ack',
                            'type' => 'date',
                        ],
                        // Valutacode (verwijzing naar: Valuta => AfasKnCurrency)
                        'CuId' => [
                            'alias' => 'currency_code',
                        ],
                        // Valutakoers
                        'Rate' => [
                            'alias' => 'currency_rate',
                        ],
                        // Backorder
                        'BkOr' => [
                            'type' => 'boolean',
                        ],
                        // Verkoopkanaal (verwijzing naar: Tabelwaarde,Verkoopkanaal => AfasKnCodeTableValue)
                        'SaCh' => [
                            'alias' => 'sales_channel',
                        ],
                        // Btw-plicht (verwijzing naar: Btw-plicht => AfasKnVatDuty)
                        'VaDu' => [
                            'alias' => 'vat_due',
                        ],
                        // Prijs incl. btw
                        'InVa' => [
                            'alias' => 'includes_vat',
                        ],
                        // Betalingsvoorwaarde (verwijzing naar: Betalingsvoorwaarde => AfasKnPaymentCondition)
                        'PaCd' => [],
                        // Betaalwijze (verwijzing naar: Betaalwijze => AfasKnPaymentType)
                        'PaTp' => [
                            'alias' => 'payment_type',
                        ],
                        // Opmerking
                        'Re' => [
                            'alias' => 'comment',
                        ],
                        // Administratie (verwijzing naar: Administratieparameters Algemeen => AfasKnUnitPar)
                        'Unit' => [
                            'type' => 'long',
                        ],
                        // Incasseren
                        'Coll' => [
                            'type' => 'boolean',
                        ],
                        // Creditorder
                        'CrOr' => [
                            'type' => 'boolean',
                        ],
                        // Code route (verwijzing naar: Tabelwaarde,Routes => AfasKnCodeTableValue)
                        'Rout' => [],
                        // Magazijn (verwijzing naar: Magazijn => AfasFbWarehouse)
                        'War' => [
                            'alias' => 'warehouse',
                        ],
                        // Verzamelpakbon
                        'CoDn' => [
                            'type' => 'boolean',
                        ],
                        // Verzamelfactuur
                        'CoIn' => [
                            'type' => 'boolean',
                        ],
                        // Prioriteit levering
                        'DlPr' => [
                            'alias' => 'delivery_prio',
                            'type' => 'long',
                        ],
                        // Taal (verwijzing naar: Talen => AfasKnLanguage)
                        'LgId' => [
                            'alias' => 'language',
                        ],
                        // Leveringsconditie (verwijzing naar: Tabelwaarde,Leveringvoorwaarde => AfasKnCodeTableValue)
                        // Values:  0:Deellevering toestaan   1:Regel volledig uitleveren   2:Order volledig uitleveren   3:Geen backorders leveren
                        'DeCo' => [
                            'alias' => 'delivery_cond',
                        ],
                        // CBS-typen (verwijzing naar: CBS-typen => AfasFbCBSType)
                        'CsTy' => [
                            'alias' => 'cbs_type',
                        ],
                        // Type vervoer CBS (verwijzing naar: Tabelwaarde,CBS Vervoerswijze => AfasKnCodeTableValue)
                        // Values:  1:Zeevaart   2:Spoorvervoer   3:Wegvervoer   4:Luchtvaart   5:Postzendingen   7:Pijpleidingvervoer   8:Binnenvaart   9:Eigen vervoer
                        'VaTr' => [],
                        // Statistisch stelsel CBS (verwijzing naar: Tabelwaarde,CBS Statistisch stelsel => AfasKnCodeTableValue)
                        // Values:  00:Reguliere invoer/ICV en uitvoer/ICL   01:Doorlevering (ICL) van onbewerkte goederen naar een andere Eu-lidstaat   02:Wederverkoop (ICL of uitvoer) van onbewerkte goederen   03:Invoer (al of niet via douane-entrepot) van goederen   04:Verwerving/levering vÃ³Ã³r eigen voorraadverplaatsing (fictieve zending)   05:Verwerving/levering nÃ¡ eigen voorraadverplaatsing (fictieve zending)   10:Actieve douaneveredeling met toepassing van het terugbetalingssysteem
                        'VaSt' => [],
                        // Goederenstroom CBS (verwijzing naar: Tabelwaarde,CBS Goederenstroom => AfasKnCodeTableValue)
                        // 6:Invoer/intra-cummunautaire verwerving (ICV)   7:Uitvoer/intra-communautaire levering (ICL)
                        'VaGs' => [],
                        // Transactie CBS (verwijzing naar: Tabelwaarde,CBS Transactie => AfasKnCodeTableValue)
                        // Values:  1:Koop, verkoop of huurkoop (financiële leasing)   2:Retourzending (excl. retour tijdelijke in- en uitvoer, zie code 6)   3:Gratis zending   4:Ontvangst of verzending vÃ³Ã³r loonveredeling   5:Ontvangst of verzending nÃ¡ loonveredeling   6:Tijdelijke in- en uitvoer en retour tijdelijke in- en uitvoer   7:Ontvangst of verzending in het kader van gecoÃ¶rdineerde fabrikage   8:Levering i.v.m. bouwmaterialen c.q. bouwkunde onder algemeen contract
                        'VaTa' => [],
                        // Land bestemming CBS (verwijzing naar: Land => AfasKnCountry)
                        'CoId' => [],
                        // Factuurkorting (%)
                        'InPc' => [
                            'type' => 'decimal',
                        ],
                        // Kredietbeperking inclusief btw
                        'VaCl' => [
                            'type' => 'boolean',
                        ],
                        // Kredietbeperking (%)
                        'ClPc' => [
                            'type' => 'decimal',
                        ],
                        // Betalingskorting (%)
                        'PaPc' => [
                            'type' => 'decimal',
                        ],
                        // Betalingskorting incl. btw
                        'VaPa' => [
                            'type' => 'boolean',
                        ],
                        // Afwijkende btw-tariefgroep
                        'VaYN' => [
                            'type' => 'boolean',
                        ],
                        // Type barcode (verwijzing naar: Tabelwaarde,Type barcode => AfasKnCodeTableValue)-->
                        // Values:  0:Geen controle   1:Barcode EAN8   2:Barcode UPC   3:Barcode EAN13   4:Barcode EAN14   5:Barcode SSCC   6:Code 128   7:Interleaved 2/5   8:Interleaved 2/5 (controlegetal)
                        'VaBc' => [
                            'alias' => 'barcode_type',
                        ],
                        // Barcode
                        'BaCo' => [
                            'alias' => 'barcode',
                        ],
                        // Rapport (verwijzing naar: Definitie => AfasKnMetaDefinition)
                        'PrLa' => [],
                        // Dagboek factuur (verwijzing naar: Dagboek => AfasKnJournal)
                        'JoCo' => [
                            'alias' => 'journal',
                        ],
                        // Factureren aan (verwijzing naar: Verkooprelatie => AfasKnSalRelation)
                        'FaTo' => [
                            'alias' => 'invoice_to',
                        ],
                        // Toekomstige order
                        'FuOr' => [
                            'alias' => 'future_order',
                            'type' => 'boolean',
                        ],
                        // Type levering (verwijzing naar: Type levering => AfasFbDeliveryType)
                        'DtId' => [
                            'alias' => 'delivery_type',
                            'type' => 'long',
                        ],
                        // Project (verwijzing naar: Project => AfasPtProject)
                        'PrId' => [
                            'alias' => 'project',
                        ],
                        // Projectfase (verwijzing naar: Projectfase => AfasPtProjectStage)
                        'PrSt' => [
                            'alias' => 'project_stage',
                        ],
                        // Status verzending (verwijzing naar: Tabelwaarde,Verzendstatus => AfasKnCodeTableValue)
                        // Values:  0:Niet aanbieden aan vervoerder   1:Aanbieden aan vervoerder   2:Aangeboden aan vervoerder   3:Verzending correct ontvangen   4:Fout bij aanbieden verzending
                        'SeSt' => [
                            'alias' => 'delivery_state',
                        ],
                        // Verzendgewicht
                        'SeWe' => [
                            'alias' => 'weight',
                            'type' => 'decimal',
                        ],
                        // Aantal colli
                        'QuCl' => [
                            'type' => 'long',
                        ],
                        // Verpakking (verwijzing naar: Tabelwaarde,Verpakkingssoort => AfasKnCodeTableValue)
                        'PkTp' => [
                            'alias' => 'package_type',
                        ],
                        // Vervoerder (verwijzing naar: Vervoerder => AfasKnTransporter)
                        'TrPt' => [
                            'alias' => 'shipping_company',
                        ],
                        // Dienst (verwijzing naar: Dienst => AfasKnShippingService)
                        'SsId' => [
                            'alias' => 'shipping_service',
                        ],
                        // Verwerking order (verwijzing naar: Tabelwaarde,Verwerking order => AfasKnCodeTableValue)
                        // Values:  1:Pakbon, factuur na levering   2:Pakbon en factuur   3:Factuur, levering na vooruitbetaling   4:Pakbon, geen factuur   5:Pakbon, factuur via nacalculatie   6:Pakbon en factuur, factuur niet afdrukken of verzenden   7:Aanbetalen, levering na aanbetaling
                        'OrPr' => [
                            'alias' => 'order_processing',
                        ],
                        // Bedrag aanbetalen
                        'AmDp' => [
                            'type' => 'decimal',
                        ],
                        // Vertegenwoordiger (verwijzing naar: Vertegenwoordiger => AfasKnRepresentative)
                        'VeId' => [],
                        // Afleveradres (verwijzing naar: Adres => AfasKnBasicAddress)
                        'DlAd' => [
                            'type' => 'long',
                        ],
                        // Omschrijving afleveradres
                        'ExAd' => [
                            'alias' => '',
                        ],
                        // Order blokkeren
                        'FxBl' => [
                            'alias' => 'block_order',
                            'type' => 'boolean',
                        ],
                        // Uitleverbaar
                        'DlYN' => [
                            'type' => 'boolean',
                        ],
                    ],
                ];
                break;

            case 'FbSalesLines':
                $info = [
                    'objects' => [
                        'FbOrderBatchLines' => 'batch_line_items',
                        'FbOrderSerialLines' => 'serial_line_items',
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
                $info = [
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
                $info = [
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
        }

        // If we are not sure that the record will be newly inserted, we do not
        // want to have default values - because those will risk silently
        // overwriting existing values in AFAS.
        // Exception: those marked with '!'. (These are usually not 'real'
        // fields, but metadata or values for a kind of 'change record'.)
        if (!empty($info['fields'])) {
            foreach ($info['fields'] as $field => &$definition) {
                if (isset($definition['default!'])) {
                    // This is always the default
                    $definition['default'] = $definition['default!'];
                    unset($definition['default!']);
                } elseif (!$inserting) {
                    unset($definition['default']);
                }
            }
        }

        // If no ID is specified, default AutoNum to True for inserts.
        if (isset($info['fields']['AutoNum'])
            && $action === 'insert' && !isset($data['#id'])
        ) {
            $info['fields']['AutoNum']['default'] = true;
        }

        // If this type is being rendered inside a parent type, then it cannot
        // contain its parent type. (Example: knPerson can be inside knContact
        // and it can also contain knContact... except when it is being rendered
        // inside knContact.)
        if (isset($info['objects'][$parent_type])) {
            unset($info['objects'][$parent_type]);
        }

        // If the definition has address and postal address defined, and the
        // data has an address but no postal address set, then the default
        // becomes PadAdr = true.
        if (isset($info['fields']['PadAdr'])
            && isset($info['objects']['KnBasicAddressAdr'])
            && isset($info['objects']['KnBasicAddressPad'])
            && (!empty($data['KnBasicAddressAdr'])
                || !empty($data[$info['objects']['KnBasicAddressAdr']]))
            && (empty($data['KnBasicAddressPad'])
                || empty($data[$info['objects']['KnBasicAddressPad']]))
        ) {
            $info['fields']['PadAdr']['default'] = true;
        }

        return $info;
    }

    /**
     * Return info for a certain type (dataConnectorId) definition.
     *
     * @deprecated Since REST/JSON appeared, this was renamed to objectTypeInfo.
     */
    protected static function xmlTypeInfo($type, $parent_type, $data, $action)
    {
        return static::objectTypeInfo($type, $parent_type, $data, $action);
    }
}
