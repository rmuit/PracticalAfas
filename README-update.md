# More info on Update connectors


## Connection::sendData()

The second argument to sendData() can be a string, which needs to be JSON or
XML suitable to send into the connector directly. In this case there isn't much
advantage over calling a REST Client class directly to send data; this is not
portable from REST to JSON.

It is also possible to specify an array (which can have a variation of slightly
different structures; see examples in the next section for example array data
which is also accepted as sendData() argument). In this case, the array is
converted to either XML or JSON, depending on which client you're using with
the connection. So in theory, these calls should be completely portable between
SOAP and REST APIs. (In practice, this isn't always the case; see 'bugs' below.)

The conversion from the array to JSON/XML is done by sendData() internally
using UpdateObject classes, so in that case you don't need to use those objects
directly. But if you have a reason for manipulating UpdateObjects yourself
before sending data: you can also construct an UpdateObject and pass it as
argument to sendData().


## Using UpdateObject classes

UpdateObject classes know nothing about API calls; they only know how to create
JSON or XML strings _which can be used for_ an API call, from some kind of
input - and how to validate the input. That input may be an array structure, or
it may be built using individual setField() / setObject() calls.

Various examples of array input can be found in the [tests/update_examples](tests/update_examples)
directory. The arrays can be used as input to the create() method, e.g.:
```php
$order_data = [
  'debtor_id' => 25000,
  'currency_code' => 'EUR',
  'warehouse' => '*** - ******',
  'Unit' => '1',
  'line_items' => [
    [
      'item_code' => 'xxxxx-xxx',
      'unit_price' => 1.20,
    ]
  ]
];

$out = UpdateObject::create('FbSales', $order_data, 'insert')->output('json', ['pretty' => true]);
print $out;
{
    "FbSales": {
        "Element": {
            "Fields": {
                "DbId": 25000,
                "CuId": "EUR",
                "Unit": 1,
                "War": "*** - ******",
                "OrDa": 2018-12-24
            },
            "Objects": {
                "FbSalesLines": {
                    "Element": [
                        {
                            "Fields": {
                                "ItCd": "xxxxx-xxx",
                                "Upri": 1.2,
                                "VaIt": 2,
                                "BiUn": "Stk",
                                "QuUn": 1
                            }
                        }
                    ]
                }
            }
        }
    }
}
```
About the above:
* Calling output() without arguments will return un-formatted JSON.
* The field names specified in the input data in this example are aliases for
  the real AFAS field names; they are defined by the UpdateObject class.
* The input which we passed into create() is 'flatter' than the JSON output
  that can be sent into an Update connector, and the output has default values
  added. However, this output can also serve as input to create() if necessary,
  after it is json-decoded back into a multidimensional array.
* We are going to assume that the third argument always needs to be specified,
  and needs to be either "insert" or "update". (It's not completely true, but
  it helps preventing strange error messages from AFAS.)

### Validating an object / influencing output

UpdateObject::output() performs validation (and for action "insert", adding
default values) by default. This behavior can be modified by passing arguments.
It is expected that the majority of users can ignore these 'change' and
'validation' arguments; the defaults should be fine for validating data just
before sending it into AFAS.

As an example, the addition of default values (on inserting) can be suppressed
with arguments to output():
```php
$object = UpdateObject::create('FbSales', $order_data, 'insert');
$out = $object->output(
  'json',
  ['pretty' => true],
  UpdateObject::DEFAULT_CHANGE & (~UpdateObject::ALLOW_DEFAULTS_ON_INSERT),
  UpdateObject::DEFAULT_VALIDATION & (~UpdateObject::VALIDATE_REQUIRED)
);
print $out;
{
    "FbSales": {
        "Element": {
            "Fields": {
                "DbId": 25000,
                "CuId": "EUR",
                "Unit": 1,
                "War": "*** - ******"
            },
            "Objects": {
                "FbSalesLines": {
                    "Element": [
                        {
                            "Fields": {
                                "ItCd": "xxxxx-xxx",
                                "Upri": 1.2
                            }
                        }
                    ]
                }
            }
        }
    }
}

```
This example was just chosen for easy comparison with the earlier one; the
output is not useful in practice. If we only disable the
ALLOW_DEFAULTS_ON_INSERT flag, output() itself would throw an exception
mentioning missing required fields. Now that we also disable the
VALIDATE_REQUIRED flag, this is not done - but AFAS will very likely not accept
this data for the same reason (though AFAS tends to be unclear in its error
messages).

Validation is mostly done on output. Calls which set data into the object (e.g.
create(), addElements(), setField()) only do a small subset of validation by
default; they e.g. check if field values have a correct data type.

Validation of the data in an object _without_ generating output, can be done by
calling ```$object->getElements(UpdateObject::DEFAULT_CHANGE, UpdateObject::DEFAULT_VALIDATION)```.
The arguments need to be explicitly specified in this case, because the
defaults for getElements() are to return the element unchanged/un-validated.

Further information on the different types of validation, and how to change
them on 'input' or 'output' calls, can be found in the source code.

#### Custom 'change behavior'

The following custom behavior is worth mentioning:
* AFAS has their own country codes. This library creates separate 'fake' fields
  for specifying ISO country codes instead, which will take care that the
  'real' AFAS field gets the correct code populated. See the field definitions
  in the code. (The 'real' AFAS field never needs to be set directly.)
* ALLOW_CHANGES is not active by default; specifying ```UpdateObject::DEFAULT_CHANGE | UpdateObject::ALLOW_CHANGES```
  will enable
  * for objects containing addresses: specifying the street plus house number
    in the 'street' field, and having this library split number/extension off
    into their own fields;
  * for person objects: having this library split Dutch prefixes like 'van'
    from the 'last name' field into its own prefix field; populate the 'search
    name' field; split or move any initials from the 'first name' to the
    'initials' field.
* specifying ```UpdateObject::DEFAULT_CHANGE | OrgPersonContact::ALLOW_REFORMAT_PHONE_NR```
  will enable reformatting Dutch phone numbers.

### Sending several elements at once

Multiple elements (i.e. multiple things like orders) can be sent into AFAS in
the same API call. (If this is not true for certain Update connectors, I'd like
to know; the code assumes this is possible for every connector / object type.)

Therefore, an UpdateObject can contain more than one element. The create() and
addElement() methods accept one element, as well as an array of elements:
```php
// Put two orders in the same output message, to send to AFAS at once.
// Order data is the same - except the debtor ID differs:
$object = UpdateObject::create('FbSales', [$order_data, $order_data], 'insert');
$object->setField('debtor_id', 11111, 1);
```
The '1' here means we're setting the field value in the _second_ FbSales
element in the object; create() internally uses zero-based indexes for the
elements.

### Working with embedded objects

After creating an UpdateObject as in the FbSales example above, all
FbSalesLines entities are in a (single) embedded UpdateObject. These can be
reached or manipulated like such:
```php
$object->getObject('FbSalesLines', 1)->setField('unit_price', 2.30);
```
The above sets the price for the _first_ line item (because no third argument
is specified to setField()) in the _second_ order (because of the '1').

Likewise, since an FbSales object can only contain one FbSalesLines _object_,
adding extra line items to an existing order is done by adding extra _elements_
to the existing _object_:
```php
$line_item_data = [ 'item_code' => 'AB123', 'unit_price' => 1.20 ];
$object->getObject('FbSalesLines')->addElements($line_item_data);

$several_more_line_items = [
 ['item_code' => 'XX345', 'unit_price' => 34 ],
 ['item_code' => 'AS725', 'unit_price' => 52 ],
];
$object->getObject('FbSalesLines')->addElements($several_more_line_items);
```
If an existing FbSales object does not already contain an embedded FbSalesLines
object, getObject() cannot return an object (or it could... but changes to it
wouldn't persist). Instead, the following would add one or more
line items as a new object:
```php
$line_item_data = [ 'item_code' => 'AB123', 'unit_price' => 1.20 ];
$object->setObject('FbSalesLines', $line_item_data);
```
An alternative is to pre-initialize an empty FbSalesLines object using either a
```'line_items' => []``` construct in the element data which is passed into the
create() / addElements() call for the FbSales object, or through
```$object->setObject('FbSalesLines', [])```. After either of these,
```getObject('line_items')``` will work fine.

('line_items' and 'FbSalesLines' can be used interchangeably; 'line_items' is
an alias for the object name, just like fields have aliases too. Or actually...
technically it's not the 'object name' but rather the 'object reference field
name'... but we won't go into that here. See the source code's comments.)

### Using non-numeric keys for elements

As we've seen, one UpdateObject can contain several elements. The indexes of
these elements are never present in (JSON or XML) output, and array keys for
the elements cannot be specified in create() or addElement() calls. Non-numeric
keys for elements to these methods will simply not work - reason being: with
create() and addElement() accepting single elements as well as arrays of
elements, any non-numeric key will be seen as a field name in a single element.

However, if for some reason it is considered useful to be able to assign
keys to the elements as they are added... so they can be accessed/manipulated
using the same keys later... this is possible. In this case, setElement() needs
to be used instead of addElements(), and only one element can be set at a time.
```php
$object = UpdateObject::create('FbSales', [], 'insert');
$object->setElement('order123', $order_data);
$object->setElement('order124', $another_order);

// To initialize an embedded object, similar to creating the empty object above:
// (If $order_data contained any 'line_items' data, this is reset.)
$object->setObject('FbSalesLines', [], null, 'order123');

$line_item_data = [ 'item_type' => 6, 'unit_price' => 3.75 ];
$object->getObject('FbSalesLines', 'order123')->setElement('shipping', $line_item_data);

$object->getObject('FbSalesLines', 'order123')->setField('description', 'Courier', 'shipping');

$shipping_cost = $object->getObject('FbSalesLines', 'order123')->getField('unit_price', 'shipping');
```

### Customizing behavior

The property definitions which are used for various objects, can be changed
using static functions. (For the standard definitions, see $propertyDefinitions
or setPropertyDefinitions().)
```php
// To set the default country for addresses to 'NL':
UpdateObject::overrideFieldProperty('KnBasicAddress', 'CoId', 'default', 'NL');

// To set another alias, id you don't like the standard alias 'debtor_id':
UpdateObject::overrideFieldProperty('FbSales', 'DbId', 'alias', 'sales_relation');

// To add custom fields or non-field properties, or to override complete
// definitions rather than just set one property in a definition:
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
```

It's also possible to override behavior by implementing a custom class (which
must extend UpateObject). See e.g. [FbSalesLines.php](src/UpdateConnector/FbSalesLines.php)
for an example.
```php
UpdateObject::overrideClass('FbSalesLines', '\Myproject\MyFbSalesLines.php');
```
The above overrideClass() method ensures two things:
- An UpdateObject::create() call uses the custom class for creating a new
  object. (It's not strictly necessary to use create() for this, though; often,
  you could also call ```new MyFbSalesLines($data, $action)```.)
- Embedded objects (like FbSalesLines, when creating an FbSales object) will
  also use the custom class.

The above static calls only have effect on objects that are instantiated _after_
the call is done. In order to propagate changes made by overrideFieldProperty()
or overridePropertyDefinitions() into an existing object, call
$object->resetPropertyDefinitions().


## 'Bugs'

The property definitions in UpdateObjects are permanently incomplete and depend
on comments and PRs to gradually make them more complete. In feedback/PRs,
please clarify (as far as needed) that any proposed changes are generally
applicable to all AFAS installations.

The REST API seems to be more lenient in accepting certain data when the SOAP
API is returning errors for the exact same XML equivalent; mostly "_Object
reference not set to an instance of an object_" errors if some expected field
is not set. Any such incompatibility is on the remote side, not in this
library. However, it leads to situations where array input for UpdateObject /
Connection::sendData() works for REST and not for SOAP. If this library can
implement validation to prevent sending data to AFAS which we know will only
return cryptic error messages... reports are welcome.
