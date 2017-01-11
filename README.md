# PracticalAFAS

A PHP library for communicating with AFAS Profit in a practical way.

## Introduction

This is library code, for use by your own code/another project. Whoever is
reading this, supposedly already has an idea of what they want to achieve and
what AFAS is, so we won't address that here. (http://www.afas.nl/ - their
current documentation is at https://static-kb.afas.nl/datafiles/help/2_9_7/SE/EN/index.htm
though that URL will undoubtedly change.)

This code was formed through one basic principle:

_Practical usability (by programmers)._

This means understandable code is preferred over future-proof extensibility that
creates separate classes for each little thing and introduces loads of boiler
plate code. It means well commented code. And it means no unexpected /
undocumented behavior. (The validation of input arguments, and of results
received from the remote system, covers situations which are unexpected for the
programmer, and throws documented exceptions.)

(That said: this wasn't created because I was unhappy with other existing
libraries. There may be good ones. I just inherited some procedural code at the
end of 2011, which naturally evolved... and about 4 years and 3 rewrites later I
finally got around to polishing it up for publication.)

## Using the classes

There are 3 classes that are significant. You probably won't use the first one
directly, but it's still good to know its structure:

### SoapAppClient

This is a client that could be used standalone to make SOAP calls to AFAS
Profit. Note that this is not a PHP 'Soapclient' class; it's a wrapper around
SOAPClient which has a constructor and only one public method: callAfas().

This class makes (almost) no assumptions about the calls: you need to tell it
the connector type, SOAP function name and arguments, on each call. Except for
the authentication related arguments; those are considered 'configuration
options' which you must provide to the constructor. The required configuration
options are in the example below; see the code for others.

```
use PracticalAfas\SoapAppClient;
$client = new SoapAppClient( [ 'customerId' => 12345, 'appToken' => '64CHARS' ] );

$result_as_xml_string = $client->callAfas('get', 'GetDataWithOptions', [
  'connectorId' => 'MyGetConnectorName',
  'take' => 1000,
  'filtersXml' => '<Filters><Filter><Field FieldId="SomeCategory" OperatorType="1">CategName</Field></Filter></Filters>',
  'options' => '<options><Outputoptions>3</Outputoptions><Outputmode>1</Outputmode><Metadata>0</Metadata></options>',
]);
$attachment = $client->callAfas('subject', 'GetAttachment', [ 'subjectID' => 123 ] );

$client->callAfas('update', 'Execute', [ 'connectorType' => 'KnOrganisation', 'dataXml => '<KnOrganisation xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><Element><Fields Action="insert"><MatchOga>0</MatchOga><Nm>MyCompany Ltd.</Nm></Fields></Element></KnOrganisation>' ] );
```

There is only a SOAP class in this library so far. I would like to have a class
that calls their REST API as well, via the same callAfas() method, and see how
that can be integrated practically with the other classes, but it hasn't
happened yet.

There are other 'AFAS client classes' in the library, that serve only to
document an example of subclassing: SoapNtlmClient and NusoapNtlmClient. These
perform NTLM authentication, which AFAS discontinued in 2017. (The
NtlmSoapClient is a helper subclass of PHP SoapClient which can do NTLM.)

### Connection

The Connection class wraps around the client class and abstract away all
argument validation / data processing that is not client specific. It has a.o.
its own syntax for filters that it expands to FiltersXml. It has two important
methods: sendXML() which wraps AFAS' Update connector, and getData() which wraps
all other connectors. The equivalent to the above example is:

```
use PracticalAfas\Connection;
use PracticalAfas\SoapAppClient;
$client = new SoapAppClient( [ 'customerId' => 12345, 'appToken' => '64CHARS' ] );
$connection = new Connection($client);

$result_as_array = $connection->getData('MyGetConnectorName', [
  'filters' => [ 'SomeCategory' => 'CategName' ],
  'options' => [ 'Outputoptions' => Connection::GET_OUTPUTOPTIONS_XML_INCLUDE_EMPTY ],
]);
$attachment = $connection->getData(123, [], Connection::DATA_TYPE_SUBJECT);

$connection->sendXml('KnOrganisation', '<KnOrganisation xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><Element><Fields Action="insert"><MatchOga>0</MatchOga><Nm>MyCompany Ltd.</Nm></Fields></Element></KnOrganisation>' );
```

(If there were a REST client, the exact setup of the Connection class would need
to be re-tested / re-evaluated, but it seems possible to keep roughly the same
interface.)

### Helper

This contains a bunch of static methods which I've found useful in exchanging
data with other systems: Conversions to/from AFAS country codes, normalizing the
structure of a Dutch phone number and/or a physical address.

Also, it contains a method called constructXML, which takes as input an array
(in a very strict format) and outputs the corresponding XML string to send to an
Update connector. This has been instrumental in keeping my own sanity when I
needed to test updating person objects inside contact object inside organization
objects... Not only to have aliases for the cryptic tag names, but especially to
deal with all the funny (a.o.'matching') behavior that AFAS exhibits, and to
have a place to dump all my test results (in code comments that I could read
back later). The above simple KnOrganisation example string can be output using:
```
Helper::constructXml('KnOrganisation', ['name' => 'MyCompany Ltd.'], 'insert')
```
constructXml() is not inside Connection because it's incomplete / a work in
progress, because it forces a specific logic and because it's quite big with all
its data mapping arrays. However if you need to update AFAS objects over SOAP I
recommend testing it out. Maybe update the code and send a pull request.
(Especially when updating nested person/organization objects: read the code and
save yourself the heachaches I've had.)

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
