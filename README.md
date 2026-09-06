# PracticalAFAS
[![Basic tests](https://github.com/rmuit/PracticalAfas/actions/workflows/test.yml/badge.svg)](https://github.com/rmuit/PracticalAfas/actions/workflows/test.yml)

A PHP library for communicating with AFAS Profit in a practical way, using
either SOAP or REST.


## Introduction

This is library code, for use by your own code/another project. Whoever is
reading this, supposedly already has an idea of what they want to achieve and
what [AFAS Profit](https://docs.afas.help/profit/) is, so we won't address that
here.

This code formed through three principles:

* _Practical usability (by developers)_;
* Making REST API calls work similarly to SOAP API calls;
* Where possible, not obscuring any of the functionality of the API endpoints
  (e.g. sending several items in one call) or any known arguments to API calls.

### Alternative / rough comparison

An (as of 2026) equally often used project is [Afas Profit API](https://github.com/MegaChriz/afasprofit).

A very rough / estimated comparison:

For simple tasks (fetching data using non-complicated filters): both are fine.
For somewhat more advanced use:

* Afas Profit API code looks more modern. It has nicely chained methods to set
  individual aspects of a query (filters, etc), rather than a single array.
  (Compare the examples in both README.mds.)
* PracticalAFAS
  * has uglier function definitions. (Above second / third principle, plus
    evolving AFAS functionality plus backward compatibility considerations,
    have made the `Connection::getData()` arguments illogical in some aspects.)
  * almost certainly exposes some more edge-case API behavior, and has some
    more validation to prevent it going unnoticed. (This is an assumption.
    But for code whose purpose is as simple as sending/receiving API data,
    customizing validation / workarounds is often harder if responsibilities are
    divided over a tree of neatly abstracted classes. At least, that was my
    view in 2013, while seeing quirky [undocumented API behavior](https://github.com/rmuit/PracticalAfas/blob/master/README-get.md#other-options) -
    that I wanted to keep under control, but not hide away.)
  * aims to make it easier (for developers) to [update/insert data](README-update.md).
    It provides
    * aliases for the short names of AFAS' object fields, making those objects
      in your own code better readable;
    * (optional) data manipulation, like a 'virtual' country field that
      converts a name/ISO code to a custom AFAS country code, and splitting
      address into street and house number.
    * **Note** Afas Profit API also has support for data objects. Both projects
      do e.g. custom validation of some interdependent fields. Afas Profit API's
      data validation may well be better / more up to date, when using its
      ability to fetch schema data from AFAS.
* For developers who want to know exactly what is happening:
  * In terms of fetching/sending data: PracticalAFAS code is likely easier to
    trace, because there's almost no boilerplate code. Almost all behavior is
    traceable by starting to read `Connection::getData()` and mostly staying
    within the same class.
  * The logic behind manipulation / validation of AFAS objects for updating is
    _not_ easy to read. AFAS Profit API may be easier to understand and extend.
    (PracticalAFAS needs you to [add definitions](README-update.md#customizing-behavior)
    for any unknown objects + fields that it does not know about, which is not
    hard. But tracing any custom behavior in code likely _is_.)

## Code structure

The code can be divided in several parts which are not tightly coupled:
* The Connection class, which might be the only one whose methods you call
  for getting/sending data.
* The (SOAP or REST) client classes: used internally by `Connection`; could be
  used standalone if needed.
* UpdateObject (plus child classes): used internally by `Connection::sendData()`
  when passing an array argument. Could be used standalone for constructing
  validated JSON/XML from array data, or manipulating individual fields in a
  data object. See [Update connectors](README-update.md) if needed.
* A Helper class with some extra static methods which could be useful for some
  developers, but which I did not want to overload the clients / Connection
  with. (Besides this helper code, IsoCountryTrait / KnBasicAddress /
  OrgPersonContact contain some public static methods that developers could use
  for their own custom validation of e.g. addresses, without using the main
  functionality of these classes. These are not documented further.)

### Using the Connection class

Your code needs to instantiate a client first, like in this example:

```php
use PracticalAfas\Connection;
use PracticalAfas\Client\RestCurlClient;
// This can be changed to SoapAppClient.
// Below 2 options are required; see the constructor for other options.
$client = new RestCurlClient( [ 'customerId' => 12345, 'appToken' => '64CHARS' ] );
$connection = new Connection($client);

// A common example got calling a Get connector with simple filter, returning an
// array of data rows:
$result_as_array = $connection->getData('MyGetConnectorName',  [ 'SomeCategory' => 'CategName' ] );

// A more elaborate example of filtering/sorting, and returning the response
// value as a string (JSON for REST client, or XML for SOAP client).
$result_as_string = $connection->getData(
  'MyGetConnectorName',
  [ 'SomeCategory' => 'CategName',
    [ 'Updated' => '2017-01-01T16:00:00', '#op' => Connection::OP_LARGER_THAN ],
  ],
  Connection::GET_FILTER_AND,
  [ 'take' => 1000,
    'orderbyfieldids' => '-Updated',
    'options' => ['Outputmode' => Connection::GET_OUTPUTMODE_LITERAL ]
  ]
);

// Get a 'subject' (data blob):
$attachment = $connection->getData(123, [], Connection::DATA_TYPE_SUBJECT);

// This inserts a new organisation with only its name populated:
$connection->sendData(['name' => 'MyCompany Ltd.'], 'KnOrganisation', 'insert');
```
...so if the 'Outputmode' option is _not_ set, getData() returns an array
of data rows instead (i.e. the XML/JSON string gets decoded for you).

This is all that's necessary for 'simple' use. More info about Get connectors
is in a [separate text](README-get.md), and the below is included for comparison, but is
optional reading.

### Client classes / difference with using Connection

If you want to stay as close to AFAS endpoints' data structure as possible:
there is nothing against simply using a client class standalone, and ignoring
all other code. There is one public method, callAfas(), which either returns a
string containing the return value from a successful API call, or throws an
exception.

You will need to know a bit more about AFAS endpoints' behavior, because the
clients contain almost no logic around argument/output validation. All they
handle is
* connection details (e.g. a different REST client could be written that does
  not use Curl);
* authentication details for making the connection (which is token based
  nowadays; there used to be another class using another method, NTLM);
* very basic argument validation only (e.g. skip & take being numeric).

Choose the client that suits you. (The structure of JSON data returned from
the REST endpoint sometimes seems closer to AFAS' own data structures, and
seemed to be more lenient with certain queries which the SOAP endpoint
refused. The SOAP endpoint also returns a data schema, in case you need that.)

You would want to use Connection class (per above) instead, if you
* do not like the structure of the filter arguments in calls (including the
  fact that there are numeric codes for operators)
* want array data returned, instead of XML (for SOAP) / JSON (for REST)
* want to specify simpler array structures (rather than the JSON/XML strings
  which AFAS accepts) for sending data to Update connectors
* want to be able to switch between the REST and SOAP APIs, for some reason.
  (They do not provide 100% equal results though; see [README.get.md](README-get.md#differences-between-rest-and-soap)
  for details.)
* want to fetch a large data set in batches, with as little risk of skipping
  rows as possible. There's a method in the Helper class for this.


#### RestCurlClient examples
```php
use PracticalAfas\Client\RestCurlClient;
// Below 2 options are required; see the constructor for other options.
$client = new RestCurlClient( [ 'customerId' => 12345, 'appToken' => '64CHARS' ] );

// Equivalent of above Connection::getData() example:
$result_as_json_string = $client->callAfas(
  'GET',
  'connectors/MyGetConnectorName',
  [ 'take' => 1000,
    'filterfieldids' => 'SomeCategory,Updated',
    'filtervalues' => 'CategName,2017-01-01T16:00:00',
    'operatortypes' => '1,4',
    'orderbyfieldids' => '-Updated'
  ]
);

// Get a 'subject' (data blob):
$attachment = $client->callAfas('GET', 'subjectconnector/123');

// Equivalent of above Connection::sendData() example:
$client->callAfas('POST', 'connectors/KnOrganisation', [], json_encode([
  'KnOrganisation' => [
    'Element' => [
      'Fields' => [
       'MatchOga' => 0,
       'Nm' => 'MyCompany Ltd.',
  ]]]]
));
```

#### SoapAppClient examples
```php
use PracticalAfas\Client\SoapAppClient;
// Below 2 options are required; see the constructor for other options.
$client = new SoapAppClient( [ 'customerId' => 12345, 'appToken' => '64CHARS' ] );

// Equivalent of above Connection::getData() example:
$result_as_xml_string = $client->callAfas(
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

// Get a 'subject' (data blob):
$attachment = $client->callAfas('subject', 'GetAttachment', [ 'subjectID' => 123 ] );

// Equivalent of above Connection::sendData() example:
$client->callAfas('update', 'Execute', [ 'connectorType' => 'KnOrganisation', 'dataXml' =>
 '<KnOrganisation xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><Element><Fields Action="insert"><MatchOga>0</MatchOga><Nm>MyCompany Ltd.</Nm></Fields></Element></KnOrganisation>' ]
);
```

## Further reading

[Get connectors](README-get.md)

[Update connectors / UpdateObject classes](README-update.md)

[Hints for developers](README-devs.md)

## Compatibility

The code likely is still compatible with PHP5 (5.4 and up) although v2.4 has
only been tested on PHP8.

As of v2.4: I am not opposed to adding code that is incompatible with PHP5,
but at the same time am not planning a rewrite of the existing code.

Client classes for REST and SOAP use PHP's standard Curl + JSON and SOAP +
SimpleXML extensions; if these do not work for you, PRs with new / modified
clients are welcome.

Automatic testing is only done for the UpdateConnector classes. (I currently
don't consider the Client classes in need of tests. Connection could use some
light tests, but it's not on my to-do list.)

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

* Hat tip to Philip Vergunst & Nathan Vergunst-Kolozsvári @ [Your source](http://www.your-source.nl/) -
  producing a first version of PHP code that at least exchanged the correct
  data, must not have been easy.

* Shout-out to [Yellowgrape](http://www.yellowgrape.nl/), professionals in
  E-commerce strategy / marketing / design. While I produced this piece of
  software in my own unpaid time, I wouldn't have the AFAS experience without them.
