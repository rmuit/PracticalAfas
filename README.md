# PracticalAFAS

A PHP library for communicating with AFAS Profit in a practical way, using
either SOAP or the REST API.

## Introduction

This is library code, for use by your own code/another project. Whoever is
reading this, supposedly already has an idea of what they want to achieve and
what AFAS is, so we won't address that here. (http://www.afas.nl/ - their
current documentation is at https://static-kb.afas.nl/datafiles/help/2_9_7/SE/EN/index.htm
though that URL will undoubtedly change.)

This code formed through two principles:

* _Practical usability (by programmers)_;
* Making REST API calls work similarly to SOAP API calls.

The first principle means understandable code is preferred over future-proof
extensibility that creates separate classes for each little thing and introduces
loads of boiler plate code. It means well commented code. And it means no
unexpected / undocumented behavior. (The validation of input arguments, and of
results received from the remote system, covers situations which are unexpected
for the programmer, and throws documented exceptions.)

That said, two things:
* This wasn't created because I was unhappy with other existing libraries. There
  may be good ones. I just inherited some procedural code at the end of 2011,
  which naturally evolved... and about 5 years and 3 rewrites later I finally
  got around to polishing it up for publication.
* The most used method (unless you use only RestCurlClient) is
  Connection::getData(). Its code is fairly readable (because it reads top-down
  without loads of things 'hidden' in other methods/classes)... but calling it
  is only simple for 'simple' use cases. (This code was created at a time when
  REST and skip/take arguments did not exist yet, which has influenced its
  evolving and now convoluted signature.)
  I personally still
  prefer doing one call (even with strange arguments) over having to set all
  arguments like filters, ordering, skip, take etc. in separate chained commands
  to execute a single Get call... but if people prefer that: they are welcome to
  contribute that. (Tip: you might want to wrap the existing Connection class,
  if you care about the validation of various forms of strangeness - or the
  compatibility between SOAP and REST clients for e.g. the 'orderbyfieldids'
  argument).

## Using the classes

Two alternative ways to use this library are:

- If you want to keep things simple and/or close to the structure of the AFAS
  REST API (see their documentation): use RestCurlClient and forget about the
  other classes. See the example block just below; this should be all you need
  and the rest of this document can be ignored. All you need to know is
  RestCurlClient::callAfas() either returns a JSON string or throws exceptions.

- Otherwise, use Connection::getData() / sendData() (which wraps around the SOAP
  or REST client), if you e.g.
  * do not like the structure of the filter arguments in the REST calls
    (including the fact that there are numeric codes for operators)
  * want to be able to change between the REST and SOAP APIs, for some reason.
    (They do not provide 100% equal results though; see below.)
  * want array data returned, instead of XML (for SOAP) / JSON (for REST)
  * like having things like default values filled automatically when inserting
    new objects through an Update Connector.

We'll first discuss the clients and give call examples for comparison purposes
with the Connection. You can skip these, unless you're curious about the
differences.

### 1. Client classes

These could be used standalone to make SOAP / REST calls to AFAS Profit, if you
know their structure. The class only deals with:
* connection details (e.g. a different REST client could be written that does
  not use Curl)
* authentication details for making the connection (e.g. inserting the App token
  into every call; there used to be different classes doing NTLM authentication
  instead of tokens)
* very basic argument validation only (e.g. skip & take being numeric).

The connection and authentication settings get passed into the constructor; not
to every individual AFAS call).

They have only one public method: callAfas(). They make (almost) no assumptions
about the remote API calls; the exact (type of) remote method and arguments need
to be passed to it, and it will return the result body as a string.

#### SoapAppClient

Note that this is not a PHP 'Soapclient' class; it's a wrapper around
SOAPClient. The required configuration options are in the example below; see the
code for others.
```php
// Note you will likely not call callAfas() directly but use Connection instead.
use PracticalAfas\SoapAppClient;
$client = new SoapAppClient( [ 'customerId' => 12345, 'appToken' => '64CHARS' ] );

$result_as_json_string = $client->callAfas(
  'get',
  'GetDataWithOptions',
  [ 'connectorId' => 'MyGetConnectorName',
    'take' => 1000,
    'filtersXml' => '<Filters><Filter><Field FieldId="SomeCategory" OperatorType="1">CategName</Field>
      <Field FieldId="Updated" OperatorType="4">2017-01-01T16:00:00</Field></Filter></Filters>',
    'options' => '<options><Index><Field FieldId="Updated" OperatorType="0"/></Index>
      <Outputoptions>3</Outputoptions><Outputmode>1</Outputmode><Metadata>0</Metadata></options>',
  ]
);
$attachment = $client->callAfas('subject', 'GetAttachment', [ 'subjectID' => 123 ] );

// This is inserting a new organisation with only its name filled:
$client->callAfas('update', 'Execute', [ 'connectorType' => 'KnOrganisation', 'dataXml => '<KnOrganisation xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><Element><Fields Action="insert"><MatchOga>0</MatchOga><Nm>MyCompany Ltd.</Nm></Fields></Element></KnOrganisation>' ] );
```

There are other 'SOAP related client classes' in the library, that serve only to
document an example of subclassing: SoapNtlmClient and NusoapNtlmClient. These
perform NTLM authentication, which AFAS discontinued in 2017. (The
NtlmSoapClient is a helper subclass of PHP SoapClient which can do NTLM.)

#### RestCurlClient
The below is (almost) equivalent to the above SOAP example (except it returns a
JSON string instead of an XML string):
```php
// Note you will likely not call callAfas() directly but use Connection instead.
use PracticalAfas\RestCurlClient;
$client = new RestCurlClient( [ 'customerId' => 12345, 'appToken' => '64CHARS' ] );

$result_as_xml_string = $client->callAfas(
  'GET',
  'connectors/MyGetConnectorName',
  [ 'take' => 1000,
    'filterfieldids' => 'SomeCategory,Updated',
    'filtervalues' => 'CategName,2017-01-01T16:00:00',
    'operatortypes' => '1,4',
    'orderbyfieldids' => '-Updated'
  ]
);
$attachment = $client->callAfas('GET', 'subjectconnector/123');

$client->callAfas('POST', 'connectors/KnOrganisation', [], '{"KnOrganisation":{"Element":{ "Fields":{"MatchOga":0,"Nm":MyCompany Ltd."}}}}'
```

### 2. Connection

The Connection wraps around a Client and abstracts away all argument validation
/ data processing that is not client specific. It has a.o. its own syntax for
filters. It has two important methods: sendXML() which wraps AFAS' Update
connector, and getData() which wraps all other connectors. The equivalent to
the above example is:

```php
use PracticalAfas\Connection;
use PracticalAfas\RestCurlClient;
$client = new RestCurlClient( [ 'customerId' => 12345, 'appToken' => '64CHARS' ] );
$connection = new Connection($client);

// A (more common) example for a GetConnector with simple filter:
$result_as_array = $connection->getData('MyGetConnectorName',  [ 'SomeCategory' => 'CategName' ] );

// The equivalent of above:
$result_as_string = $connection->getData(
  'MyGetConnectorName',
  [ 'SomeCategory' => 'CategName',
    [ 'Updated' => '2017-01-01T16:00:00', '#op' => Connection::OP_LARGER_THAN ],
  ],
  Connection::GET_FILTER_AND,
  [ 'take => 1000,
    'orderbyfieldids' => '-Updated',
    'options' => ['Outputmode' => Connection::GET_OUTPUTMODE_LITERAL ]
  ]
);
$attachment = $connection->getData(123, [], Connection::DATA_TYPE_SUBJECT);

// Sending data works for both client types, converting the array to XML or JSON
// as needed. See the docs on calling Update Connectors below for more info.
$connection->sendData('KnOrganisation', ['name' => 'MyCompany Ltd.'], 'POST');
```
...so if the 'Outputmode' option is _not_ provided, getData() returns an array
of data rows instead (i.e. the XML/JSON string gets decoded for you).


#### Connection::getData() function parameters

The getData() function is created with the assumption that most callers want to
call GetConnectors, optionally with filters, so this is made easy. All other
ways to call this are, admittedly, convoluted and somewhat confusing.

**First and second parameter:** (often) the GetConnector name and optional filters.

  Filters can be a one-dimensional array of name-value pairs with an optional
  '#op' that serves as the operator (if you want another operator than
  'name EQUALS value'). Or it can be a two-dimensional array, where each
  sub-array can have a different operator. Or it can be a mix, as in the
  example above. Constants starting with *OP_* are defined for each operator, in
  the connection class. (They map to integer values that AFAS recognizes.)

**Third parameter:** The 'type of filter', or the 'type of connector'.

  If you're calling a GetConnector with more than one filter, you can use the
  class constants *GET_FILTER_AND* (the default) or *GET_FILTER_OR* to use an
  AND / OR filter. (REST clients only.)

  If you want data from another connector, you can specify the type of connector
  / data to retrieve, with other constants, e.g. *DATA_TYPE_SUBJECT*,
  *DATA_TYPE_REPORT*, *DATA_TYPE_METAINFO_GET*, *DATA_TYPE_METAINFO_UPDATE*. In
  this case, the first argument is the name or ID of the piece of data you want
  to retrieve. The second argument is very likely an empty array.

**Fourth parameter:** for a GetConnector, these are all the arguments you want
to specify _besides_ the filter related arguments. Often these are 'Take',
'Skip' and/or 'OrderByFieldIds'.

(Since you often want to specify 'Take', you will often end up with a function
call that has 4 parameters. If you have a GetConnector call with a simple filter
which you know only returns few records, the first two parameters should be
enough.)

#### The 'options' argument and class-wide setters/getters

Three options influence the format of data returned by getData(); other options
are discussed further down.

In the (older) SOAP API for GetConnectors, the GetDataWithOptions call has an
'options' argument with various sub-values which influence the contents of the
response. These would get passed as `'options' => [ ... ]`  in the
$extra_arguments parameter to getData(); see above example.

To preserve backward compatibility, most 'options' are also supported when the
Connection object wraps around a REST client instead of a SOAP client. (The
options will not actually be sent to the REST API; the Connection class uses
them to post process the API return value.)

The three options which influence the format of the return value, have also been
turned into class variables with setters and getters, so they don't need to be
provided to every call. They are:

##### 'Outputmode' option / Connnection::setDataOutputFormat():

Only one value was supported for the SOAP calls: GET_OUTPUTMODE_XML (1). (There
is a GET_OUTPUTMODE_TEXT (2) but the Connection code does not support it.) The
Connection class has however changed the meaning and added to it:
* GET_OUTPUTMODE_ARRAY: this is the default value, and will have getData()
  convert the response string from the SOAP/REST API into an array. (In the
  case of GetConnectors, this is a two-dimensional array of rows/fields, but
  see 'Metadata' below.)
* GET_OUTPUTMODE_SIMPLEXML: returns a SimpleXMLElement instead of a string. Only
  valid when using a SOAP client.
* GET_OUTPUTMODE_LITERAL is the same as GET_OUTPUTMODE_XML (so these constants
  are used interchangeably). It does not return XML from the REST API, but JSON-
  which is the reason for renaming the constant.

##### 'Metadata' option / Connnection::setDataIncludeMetadata():

The setter/getter value is false by default and means that no 'metadata' is
returned with GetConnector results. 'metadata' can mean different things:
- For SOAP clients, this means the XML Schema of the connector is included in
  the result. (This only has meaning when the Outputmode option is set to
  GET_OUTPUTMODE_LITERAL so getData returns an XML string; if an array of rows
  is returned, the schema is lost in conversion.)
- For REST clients, this means the full API response gets returned (as an array)
  instead of only the 'rows' part. An example of a full response:
```
[ 'skip' => 0,
  'take' => 100,
  'rows' [ ...the two-dimensional data array which is returned by default... ]
]
```
_Note 1:_ with REST clients, it is not allowed to set the 'Outputmode'
explicitly to GET_OUTPUTMODE_LITERAL and the 'Metadata' explicitly to false:
the 'literal' string from the REST API _implies_ that metadata is returned too.

_Note 2:_ the 'Metadata' option value passed to getData() is 0/1 instead of
true/false.

##### 'Outputoptions' option / Connnection::setDataIncludeEmptyFields():

This has different defaults for REST and SOAP clients.

For SOAP clients, this is (historically) false, meaning that if a field value is
empty, the field will _not_ be included in the corresponding row. If set to
true, the field will be returned (with an empty string as value).

For REST clients, this is true and cannot be set to false. Empty fields are
always returned (with null as value).

_Note:_ the 'Outputoptions' option value passed to getData() is not equal to the
setter/getter value: it is not a boolean, but an integer represented by either
constant GET_OUTPUTOPTIONS_XML_EXCLUDE_EMPTY (2, the default) or
GET_OUTPUTOPTIONS_XML_INCLUDE_EMPTY (3).

##### Other options:

It turns out there are more options than the above three, that a SOAP
getDataWithOptions call supports - though these were not (officially) supported
by Connection::getData() before version 1.2 (and I've never seen these
documented in AFAS' knowledge base). They are:
- 'Skip' and 'Take'. Indeed it turns out that the SOAP API accepts these in two
  forms: as direct arguments and as options inside the 'options' argument. It
  is recommended to _not_ set these inside 'options'. Their behavior is slightly
  different in both places (see code comments) and v1.2 of this library unifies
  and validates them, which means the 'options' form has changed behavior
  slightly.
- 'Index'. This is used for ordering records in a data set, and its syntax is
  an XML snippet (which can be seen encapsulated by <Index> tags in an example
  above). It only works for SOAP clients. It's recommended not to use this
  option, but to pass the 'OrderByFieldIds' argument instead, which has a
  simpler syntax and is portable. (For SOAP clients, it is automatically
  converted to a correct 'Index' option.)

#### Differences between REST and SOAP (for Get Connectors)

The connection class behaves mostly the same, regardless whether it is used with
a SOAP or REST client. The exceptions are:

- SOAP clients/connections do not support 'OR filters'. (Or at least, I don't
  think so. Maybe there is another undocumented option for this...)

- The default value for the 'take' argument. The AFAS REST API will (always?)
return 100 rows maximum if the 'take' argument is not specified. With a SOAP
client, 1000 rows will be returned. (Unlike REST, this is not a remote API
default. Historically, older AFAS SOAP clients/connections -with deprecated
authentication methods- had no limit to the number of rows returned. With the
introduction of 'App tokens', SOAP connections suddenly started returning
_nothing_ (i.e. zero rows) unless a 'take' parameter was specified, so the
Connection class made sure to fill a value - it sets 1000 by default.)

- date values in record sets returned by GetConnectors: date values are by
default(?) returned in a format like 2009-06-15T13:45:30 by SOAP clients.
When returned by REST clients, they have a 'Z' appended (i.e. the format is
_formatted like_ Microsoft's "Universal sortable"). The values are _not_ in UTC,
though. (They are either in the local timezone... or in CET/CEST (UTC+1/2)
because that's where AFAS' client base is. Probably the latter.)

Note that it is still illegal for date values in filters (i.e. the
'filtervalues' parameter) for REST Clients' GetConnectors to have the 'Z'
appended. In other words, these should be formatted equally to the return values
from SOAP GetConnectors.

- empty values in rows: as noted just above, these are empty strings for SOAP
(if the corresponding 'Outputoptions' is set; otherwise the field is not
present) and null for REST. This is due to the differing output format (XML vs.
JSON) and the fact that the Connection class does not post-process them. (If
this becomes an issue, we may need to introduce extra values to
setDataIncludeEmptyFields().)

### Helper

This contains a bunch of static methods which I've found useful in exchanging
data with other systems, which are either not tightly integrated into the
Connection class, or their stability is unknown.

- A getDataBatch() method to assist in fetching a data set in multiple batches
  by calling the method repeatedly to get each batch. (This is useful for large
  data sets, when we need to specify a maximum 'take' parameter to calls.)

- Conversions to/from AFAS country codes, normalizing the structure of a Dutch
  phone number and/or a physical address. These can be used for validating /
  converting values before sending them over. (They are not really integrated
  into the library, i.e. array values that are passed through
  normalizeDataToSend() / constructXML() are not automatically run through these
  methods. Maybe they should be.)

- A normalizeDataToSend() method that will take an array with a custom (strict)
  format, and convert it to an array which is suitable for sending to an Update
  Connector through a REST client, as JSON. Plus a constructXml() method that
  will take the same array and convert it to an XML string which is suitable for
  sending through a SOAP client.

### Calling Update Connectors through Connection::sendData()

The second argument to sendData() can be a string, in which case it will be
passed to the client / sent to the AFAS API as-is. So it must be a valid XML or
JSON string, depending on the client type.

The argument can also be an array, in which case the Helper class will be used
to convert that array to XML or JSON. The format of this array is custom and
fairly strict, i.e. exceptions will be thrown for any unrecognized array value.
What this provides is, among others:
* being able to use aliases for the somewhat cryptic array keys / XML tags;
* default values in some cases;
* being able to switch between between SOAP/REST clients, if you care about that.
Besides this, for me personally, having a place to document test results and
write other comments in the Helper::objectTypeInfo() has been instrumental in
keeping my sanity when I needed to test updating nested person objects inside
contact object inside organization objects, and deal with all the funny (a.o.
'matching') behavior that AFAS exhibits. (Especially when updating nested person
/ organization objects: read the code and save yourself the heachaches I've had.)

I won't claim that the data definitions and conversion methods are perfect,
though. They suffice for simple updates, if you find arrays more descriptive
than formatted JSON/XML. For sending more elaborate structures, or data types
(Update Connectors) that were not used yet: please test carefully; documented
PRs are always welcome. Also: I've only tested sending nested objects over a
SOAP client, so far.

## Bugs

1) Helper::objectTypeInfo is permanently incomplete, as mentioned just above.
Send PRs.

2) The structure of JSON objects as (currently) found in AFAS documentation does
not describe how to send in multiple elements of the same type. Our
Helper::constructXML() can do this, but Helper::normalizeDataToSend() and by
extension Connection::sendData() cannot handle multiple elements at once. See
the comments/@todos inside Helper::normalizeDataToSend() for an example XML vs
JSON representation.

I'm fairly sure this must be possible (because otherwise it would be impossible
to send e.g. an order containing more than one line item over REST) but lack an
environment where I can test endlessly. Please send in date definitions (or even
better, changed code - or provide me with a test environment) to make this work.

## Authors

* Roderik Muit - [Wyz](https://wyz.biz/) - Rewrite, re-rewrite and re-re-rewrite.

I like contributing open source software to the world and I like opening up
semi-closed underdocumented systems. (Which was the case with AFAS in 2012, but
it has gotten better.) Give me a shout-out if this is useful or if you have a
contribution. Contact me if you need integration work done. (I have experience
with several other systems.)

## License

This library is licensed under the MIT License - see the
[LICENSE.md](LICENSE.md) file for details.

## Acknowledgments

* Hat tip to Nathan Vergunst-Kolozsvári @ [Your source](http://www.your-source.nl/) -
  producing a first version of PHP code that at least exchanged the correct
  data, must not have been easy.

* Shout-out to [Yellowgrape](http://www.yellowgrape.nl/), professionals in
  E-commerce strategy / marketing / design. While I produced this piece of
  software in my own unpaid time, I wouldn't have the AFAS experience without them.
