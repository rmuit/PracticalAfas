# PracticalAFAS

A PHP library for communicating with AFAS Profit in a practical way, using
either SOAP or REST.


## Introduction

This is library code, for use by your own code/another project. Whoever is
reading this, supposedly already has an idea of what they want to achieve and
what AFAS is, so we won't address that here. (http://www.afas.nl/ - their
current documentation is at https://static-kb.afas.nl/datafiles/help/2_9_7/SE/EN/index.htm
though that URL will undoubtedly change.)

This code formed through three principles:

* _Practical usability (by programmers)_;
* Making REST API calls work similarly to SOAP API calls;
* Where possible, not obscuring any of the functionality of the API endpoints
  (e.g. sending several items in one call) or any known arguments to API calls.

The first (and third) principle means understandable code is preferred over
future-proof extensibility that creates separate classes for each little thing
and introduces loads of boiler plate code. It means well commented code. And it
means no unexpected / undocumented behavior. (The validation of input arguments,
and of results received from the remote system, covers situations which are
unexpected for the programmer, and throws documented exceptions.)

That said: the second / third principle, plus evolving AFAS functionality plus
backward compatibility considerations, have made the Connection::getData()
arguments illogical in some aspects. But the code is still fairly easily
readable (because there's not many different methods) and easy to call for
'simple' use cases.


## Compatibility

Version 2 of the library works with PHP5 (5.4 and up), though the unit tests for
the UpdateConnector part are written in PHPUnit v7 (which supports PHP7 only).

Client classes for REST and SOAP use PHP's standard Curl + JSON and SOAP +
SimpleXML extensions; if these do not work for you, PRs with new / modified
clients are welcome.


## Using the classes

There are a few parts of this library which are not all tightly coupled:

- The client classes can be used standalone to make calls to AFAS connectors.
  You'll need to know the exact parameters that AFAS expects for their REST or
  SOAP API; there is a client for both. If you want to keep things close to
  AFAS' own structure, the REST client seems most suitable. Use RestCurlClient
  (see the example below) and forget about the other classes. All you need to
  know is there is one public method, callAfas(), which either returns a (JSON)
  string with a successful call result, or throws exceptions.

- The Connection class wraps around the SOAP or REST client and abstracts away
  some parameters which are not so easy to handle. Use it if you e.g.
  * do not like the structure of the filter arguments in calls (including the
    fact that there are numeric codes for operators)
  * want array data returned, instead of XML (for SOAP) / JSON (for REST)
  * want to specify simpler array structures (rather than the JSON/XML strings
    which AFAS accepts) for sending data to Update connectors
  * want to be able to change between the REST and SOAP APIs, for some reason.
    (They do not provide 100% equal results though; see [README.get.md](README-get.md)
    for details.)
  * want to fetch a large data set in batches, with as little risk of skipping
    rows as possible. There's a method in the Helper class for this.

- UpdateObject (plus child classes) can be used to create XML or JSON payloads
  for Update Connectors, and validate their contents. Their output is strings,
  which can be used in any way you want (e.g. send the string data through a
  client class, or through Connection::sendData(), or use UpdateObjects with
  your own custom code).

- There's also a Helper class with some extra static methods which could be
  useful for some programmers, but which I did not want to overload the
  clients / Connection with. (Also, IsoCountryTrait / KnBasicAddress /
  OrgPersonContact contain some public methods that programmers could use for
  their own custom validation of e.g. addresses, without using the main
  functionality of these classes. These are not documented further.)

We'll first discuss the clients and give call examples for comparison purposes
with the Connection. You can skip these, unless you're curious about the
differences.

### Client classes

These could be used standalone to make SOAP / REST calls to AFAS Profit, if you
know the structure of the calls. A client class only deals with:
* connection details (e.g. a different REST client could be written that does
  not use Curl)
* authentication details for making the connection. (E.g. inserting the App
  token into every call; there used to be different classes doing NTLM
  authentication instead of tokens.)
* very basic argument validation only (e.g. skip & take being numeric).

The connection and authentication settings get passed into the constructor; not
to every individual AFAS call.

You will only use one public method: callAfas(). (There is a second public
method: static getClientType() - but this is not necessary for standalone use.)
Client classes make (almost) no assumptions about the remote API calls; the
exact (type of) remote method and arguments need to be passed to it, and it
will return the result body as a string.

#### RestCurlClient examples
The required options are in the constructor below; see the code for other
options.
```php
use PracticalAfas\Client\RestCurlClient;
$client = new RestCurlClient( [ 'customerId' => 12345, 'appToken' => '64CHARS' ] );

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
$attachment = $client->callAfas('GET', 'subjectconnector/123');

// This is inserting a new organisation with only its name filled:
$client->callAfas('POST', 'connectors/KnOrganisation', [], '{"KnOrganisation":{"Element":{ "Fields":{"MatchOga":0,"Nm":MyCompany Ltd."}}}}'
```

#### SoapAppClient examples
The below is (almost) equivalent to the above REST example (except it returns
an XML string instead of a JSON string). Note that this is not a PHP
'Soapclient' class; it's a wrapper around SOAPClient.
```php
use PracticalAfas\Client\SoapAppClient;
$client = new SoapAppClient( [ 'customerId' => 12345, 'appToken' => '64CHARS' ] );

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
$attachment = $client->callAfas('subject', 'GetAttachment', [ 'subjectID' => 123 ] );

$client->callAfas('update', 'Execute', [ 'connectorType' => 'KnOrganisation', 'dataXml => '<KnOrganisation xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><Element><Fields Action="insert"><MatchOga>0</MatchOga><Nm>MyCompany Ltd.</Nm></Fields></Element></KnOrganisation>' ] );
```

### Connection

The Connection wraps around a Client and abstracts away all argument validation
/ data processing that is not client specific. It has a.o. its own syntax for
filters. It has two important methods: sendData() which wraps AFAS' Update
connector, and getData() which wraps all other connectors. (All other methods
are getters and setters that you might never need.)

The equivalent to the above example is:

```php
use PracticalAfas\Connection;
use PracticalAfas\Client\RestCurlClient;
$client = new RestCurlClient( [ 'customerId' => 12345, 'appToken' => '64CHARS' ] );
$connection = new Connection($client);

// A (more common) example for a Get connector with simple filter, returning an
// array of rows:
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

$connection->sendData(['name' => 'MyCompany Ltd.'], 'KnOrganisation', 'insert');
```
...so if the 'Outputmode' option is _not_ provided, getData() returns an array
of data rows instead (i.e. the XML/JSON string gets decoded for you).


## Further reading

[Get connectors](README-get.md)

[Update connectors / UpdateObject classes](README-update.md)

[Hints for developers](README-devs.md)

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
