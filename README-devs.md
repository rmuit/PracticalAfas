# Hints for developers


## Client classes

The standard classes throw exceptions and don't do logging. If you want to add
more error handling / logging in your own framework, consider extending a
client object and including RememberCallDataTrait, which gives you info about
the last call made, for e.g. logging purposes.

Also consider whether it's necessary to distinguish between temporary errors
returned by AFAS which your system should retry later, and permanent errors.
There is no perfect way to do this, but some code in the Helper class provides
a start.

For retrieving large data sets by a system (as opposed to a human), paging
with skip/take options is not as perfect a solution as filtering on a unique,
sortable field. Helper::getDataBatch() assists with this.


## UpdateObjects

The class is suitable for doing more than simply passing an array structure in
and getting a string out immediately. Code which handles user input can e.g.
set all fields in an object separately by first calling ```UpdateObject::create(TYPE)```
and then setting the fields and the (insert/update) action later, using 
setField() and setAction() calls - perhaps followed by validating the input.

Some hints for people writing code that _uses_ UpdateObjects:

Read the UpdateObject class comments: the description of the class, the
$propertyDefinitions variable, and create(), output(), overrideClass(),
overridePropertyDefinitions() and overrideFieldProperty() methods.

Know the difference between Object and Element, as this class uses these terms.
(It is outlined in the class description comments.) Know that an UpdateObject
can hold several elements.

The 'input' methods to this class throw a single InvalidArgumentException if
illegal values are set. (At least: according to the validation checks which are
actually done on input.) These are e.g. create(), setField(), addElements().
The latter throws a single InvalidArgumentException whose message can describe
multiple errors, separated by newlines. On 'output' (e.g. output(),
getElements()) validation of the same data that was already set will throw an
UnexpectedValueException.

Some hints for people writing code that _extends_ UpdateObjects:

Code validating full elements 'on input' always calls validateElementInput().
Code validating the elements 'on output' always calls validateElements().
validateFieldValue() is called by both these protected methods. Both methods
have their own call tree which you'll need to get acquainted with if you
implement custom validation, so you put your code in the right spot.

The most important spots to know when validating field values are:
* validateFieldValue() validates simple single field values only (kinda; the
  OrgPersonContact class does more but it comes with a lot of caveats); it
  needs to be able to handle data both 'on input' and 'on output'.
* validateFields() validates all fields, only 'on output', and is therefore the
  place to check requiredness and set default values.

validateElements(), validateElementInput(), and the protected methods called by
them which validate multiple values, do not throw exceptions but rather add
error messages to an '*error' key of the element data being validated. (This is
because that's the easier way of collecting multiple errors.) Public methods
cannot change the element data like that, so they throw exceptions containing
these concatenated errors. Several methods are converting exception messages
into '*error' keys or vice versa, depending on their place in the tree of
methods called during validation.

_In theory_, validation functions cannot assume anything about the field values
they are validating; not even that have the right type. (A caller could have
called an 'input' method explicitly with the UpdateObject::VALIDATE_NONE
argument.)
