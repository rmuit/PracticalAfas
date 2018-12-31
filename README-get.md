# More info on Get Connectors

## Connection::getData() function parameters

The getData() function is created with the assumption that most callers want to
call Get connectors, optionally with filters, so this is made easy. All other
ways to call this are, admittedly, convoluted and somewhat confusing.

(I personally still prefer doing one call (even with strange arguments) over
having to set all arguments like filters, ordering, skip, take etc. in separate
chained calls to execute a single Get call... but if people prefer making
chained calls: they are welcome to contribute a class which does that, or to
write their own library that uses PracticalAfas. Tip: you might want to wrap
the existing Connection class, if you care about the validation of various
forms of strangeness - or the compatibility between SOAP and REST clients for
e.g. the 'orderbyfieldids' argument).

**First and second parameter:** (often) the Get connector name and optional filters.

  Filters can be a one-dimensional array of field-value comparisons, with an
  optional '#op' that serves as the comparison operator (if that should be
  different than 'field EQUALS value'). Or it can be a two-dimensional array,
  where each sub-array can have a different operator. Or it can be a mix, as in
  the example in [README.md](README.md). Constants starting with *OP_* are
  defined for each operator, in the connection class. (They map to integer
  values that AFAS recognizes.)

**Third parameter:** The 'type of filter', or the 'type of connector'.

  If you're calling a Get connector with more than one filter, you can use the
  class constants *GET_FILTER_AND* (the default) or *GET_FILTER_OR* to use an
  AND / OR filter. (OR filters are only supported for REST clients.)

  If you want data from another connector, you can specify the type of connector
  / data to retrieve, with other constants, e.g. *DATA_TYPE_SUBJECT*,
  *DATA_TYPE_REPORT*, *DATA_TYPE_METAINFO_GET*, *DATA_TYPE_METAINFO_UPDATE*. In
  this case, the first argument is the name or ID of the piece of data you want
  to retrieve. The second argument is likely an empty array.

**Fourth parameter:** for a Get connector, these are all the arguments you want
to specify _besides_ the filter related arguments. Often these are 'Take',
'Skip' and/or 'OrderByFieldIds'.

For Get connector calls where you know only few rows are returned, passing the
first two parameters should be enough. However you will often want to specify
'Skip' and/or 'Take', because results returned from AFAS are capped by default:
at 100 rows for the REST API endpoint, and at 1000 rows for the SOAP API
endpoint when called through getData(). So you will often end up with a
function call that has 4 parameters, with GET_FILTER_AND for the third. To
return more rows, explicitly pass the (maximum) number of rows to be
returned in 'Take', or pass -1 in 'Skip' to return the full data set. This
last thing obviously risks timeouts. (For returning a large data set in batches,
see the Helper class.)

## The 'options' argument

In the (older) SOAP API for Get connectors, the GetDataWithOptions call has an
'options' argument with various sub-values which influence the contents of the
response. These would get passed as `'options' => [ ... ]`  in the
$extra_arguments parameter to getData(); see the example in [README.md](README.md).

To preserve compatibility, most 'options' are supported regardless whether the
Connection object wraps around a REST or SOAP client. (The options will not
actually be sent to the APIs if not needed; the Connection class uses them to
preprocess argument values or post process the API return value.)

### Three options have class-wide setters/getters

You can generally use getData() without using any of these.

The three options which influence the format of the return value, have also
been turned into class variables with setters and getters, so they don't need
to be provided to every call. They are:

Appropriate constants are defined in the Connection class, so they can be
passed as e.g. `'options' => [ 'Outputmode' => Connection::GET_OUTPUTMODE_LITERAL ]`.

#### 'Metadata' option / Connnection::setDataIncludeMetadata():

This is false by default and means that no 'metadata' is returned with
Get connector results. 'metadata' can mean different things:
- For SOAP clients, this means the XML Schema of the connector is included in
  the result. (This only has meaning when the Outputmode option is set to
  GET_OUTPUTMODE_LITERAL so getData returns an XML string; if an array of rows
  is returned, the schema is lost in conversion.)
- For REST clients, this means the full API response gets returned (as an array)
  instead of only the 'rows' part. An example of a full response:
```php
[ 'skip' => 0,
  'take' => 100,
  'rows' [ ...the two-dimensional data array which is returned by default... ]
]
```
_Note 1:_ with REST clients, it is not allowed to set the 'Outputmode'
explicitly to GET_OUTPUTMODE_LITERAL and the 'Metadata' explicitly to false:
the 'literal' string from the REST API _implies_ that metadata is returned too.

_Note 2:_ the official values for the Get connector options are 0 and 1, which
have constants GET_METADATA_NO and GET_METADATA_YES - but passing false/true
into the option works fine. The argument to setDataIncludeMetadata() is boolean.

#### 'Outputmode' option / Connnection::setDataOutputFormat():

* GET_OUTPUTMODE_ARRAY: this is the default value, and will have getData()
  convert the response string from the SOAP/REST API into an array. (In the
  case of Get connectors, this is a two-dimensional array of rows/fields, but
  see 'Metadata' below.)
* GET_OUTPUTMODE_LITERAL returns XML for SOAP clients and JSON for REST clients.
* GET_OUTPUTMODE_SIMPLEXML: returns a SimpleXMLElement instead of a string.
  Only valid when using a SOAP client.

(AFAS' SOAP API itself originally supported two values: GET_OUTPUTMODE_XML (1)
and GET_OUTPUTMODE_TEXT (2). The first has been renamed to
GET_OUTPUTMODE_LITERAL for obvious reasons; the second one is not supported by
the Connection class and may not even be supported by AFAS anymore since 2017.)

#### 'Outputoptions' option / Connnection::setDataIncludeEmptyFields():

This has different defaults for REST and SOAP clients.

For SOAP clients, this is (historically) false, meaning that if a field value
is empty, the field will _not_ be included in the corresponding row. If set to
true, the field will be returned (with an empty string as value).

For REST clients, this is true and cannot be set to false. Empty fields are
always returned (with null as value).

_Note:_ the 'Outputoptions' option value passed to getData() is not equal to the
setter/getter value: it is not a boolean, but an integer represented by either
constant GET_OUTPUTOPTIONS_XML_EXCLUDE_EMPTY (2, the default) or
GET_OUTPUTOPTIONS_XML_INCLUDE_EMPTY (3).

### Other options:

It turns out there are more options than the above three, that a SOAP
getDataWithOptions call supports - though these were not (officially) supported
by Connection::getData() before version 1.2 (and I've never seen these
documented in AFAS' knowledge base). They are:
- 'Skip' and 'Take'. Indeed it turns out that the SOAP API accepts these in two
  forms: as direct arguments and as options inside the 'options' argument. It
  is recommended to _not_ set these inside 'options'. Their behavior is slightly
  different in both places (see code comments) and v1.2 of this library unifies
  and validates them.
- 'Index'. This is used for ordering rows in a data set, and its syntax is an
  XML snippet (which can be seen encapsulated by <Index> tags in an example
  above). It only works for SOAP clients. It's recommended not to use this
  option, but to pass the 'OrderByFieldIds' argument instead, which has a
  simpler syntax and is portable. (For SOAP clients, it is automatically
  converted to a correct 'Index' option - but the 'Index' option is not ported
  to the correct 'OrderByFieldIds' option for REST clients.)


## Differences between REST and SOAP

The connection class behaves mostly the same, regardless whether it is used with
a SOAP or REST client. The exceptions are:

_Category 1: Differences in behavior of the endpoints (which the Connection
class isn't involved in):_

- SOAP clients/connections do not support 'OR filters' or getting meta info /
  schema information about Get connectors. (Or at least, I don't think so.
  Maybe there is another undocumented option for this...)

- Date values returned by Get connectors are by default(?) returned in a format
  like 2009-06-15T13:45:30 by the SOAP endpoint. The REST endpoint returns them
  with a 'Z' appended (i.e. the format is _formatted like_ Microsoft's
  "Universal sortable"). The values are _not_ in UTC, though. (They are either
  in the local timezone... or in CET/CEST  (UTC+1/2) because that's where AFAS'
  client base is. I'm not sure.)

Note that it is still illegal for date values in filters (i.e. the
'filtervalues' parameter) for REST Clients' Get connectors to have the 'Z'
appended. In other words, these should be formatted equally to the return
values from SOAP Get connectors.

_Category 2: Differences in behavior of the endpoints, which have also led to
code in the Connection being implemented differently:_

- The default value for the 'take' argument. The AFAS REST endpoint returns
  maximum 100 rows if the 'take' argument is not specified (unless 'skip' is
  -1; then 'take' is ignored and the full data set is returned). The SOAP
  endpoint returns _zero_ rows in this case - and the Connection class
  sets a default 'take' value of 1000 to prevent that. (It does not set 100
  because that was considered too much of a deviation from historic behavior;
  before 2017, the SOAP API endpoint had no limit to the number of rows
  returned.)

- Empty values returned in rows are empty strings for SOAP (if the
  corresponding 'Outputoptions' is set; otherwise the field is not present) and
  null for REST. This is due to the different output format (XML vs. JSON) and
  the fact that the Connection class does not post-process them. (If this
  becomes an issue, we may need to introduce extra values to
  setDataIncludeEmptyFields().)


## AFAS bugs / challenges

### Filtering Get connectors on date fields

This should be done with caution; it may behave in unexpected ways.

First of all: the date format that REST clients returns, is not suitable to be
used in date filters. Unless there are formatting options for dates that I am
unaware of, REST clients return dates always with a granularity of a second,
and ending with "Z" (despite the value _not_ being expressed in UTC!). This
format is illegal for use as a filter value; the "Z" must be removed.

Further: filtering on a specific date to the second (with OP_EQUAL) will
almost(?) never return any rows. AFAS seems to store dates internally with a
granularity smaller than a second -probaby in microseconds- and the filter value
also seems to be interpreted in microseconds. Which means that (unless there is
some way to specify milliseconds in the filter value), the filter value is
always interpreted as being exactly on the .000 milliseconds border. (A filter
value of 2017-07-01T00:00:02 is actually always 2017-07-01T00:00:02.000; there
is no way to influence that.)

It would be nice if a Get connector would _interpret_ a filter value just like it
_displays_ the value. Meaning: a filter 'EQUALS 2017-07-01T00:00:02' would mean
"the time _rounded to a second_ equals this value". But it doesn't. And as long
as it doesn't, you will have to pass a double filter in order to get all values
within a range of a second: e.g. '>= 2017-07-01T00:00:02 AND < 2017-07-01T00:00:03'.

(Above also means that for date values, there is almost no difference
between '>' (OP_LARGER_THAN) and '>=' (OP_LARGER_OR_EQUAL) filters. Probably
it only makes a difference for rows with a recorded time ending in .000
milliseconds.)

Above are not necessarily bugs. Just inconveniences, and omissions in
documentation. (Arguably, if AFAS does not want to interpret an 'EQUALS' filter
like it displays it, then the bug is having no way to specify milliseconds in
the filter value.)


The following is a bug (in my opinion), however.

Tests show that if a date value is _displayed_ by a Get connector as
2017-07-01T00:00:02, then there is only a ~50% chance that a query with
a filter '>= 2017-07-01T00:00:02' will return that row. And there is a ~50%
chance that a query with a filter '< 2017-07-01T00:00:02' will return that
row, instead.

That's right: 2017-07-01T00:00:02 is (often) smaller than 2017-07-01T00:00:02!

This means that the earlier example query
'>= 2017-07-01T00:00:02 AND < 2017-07-01T00:00:03' will statistically only
return _half_ of the rows that have a display value of 2017-07-01T00:00:02,
plus _half_ of the rows that have a display value of 2017-07-01T00:00:03.

The behavior can have various inconvenient implications, depending on your
application. This is likely caused by AFAS rounding values with >= .500
milliseconds up to the next second before displaying; this strange behavior
would likely disappear if they rounded all values down to a full second.
