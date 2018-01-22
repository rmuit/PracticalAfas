## Calling Update Connectors through Connection::sendData()

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
write other comments in Helper::objectTypeInfo() has been instrumental in
keeping my sanity when I needed to test updating nested person objects inside
contact object inside organisation objects, and deal with all the funny (e.g.
'matching') behavior that AFAS exhibits. (Especially when updating nested person
/ organisation objects: read the code and save yourself the headaches I've had.)

### Warning: REST / JSON is untested

I've tested / used this code years ago, for a.o. sending orders and customer
(organisation / person) data from a commerce system. This has influenced logic
surrounding MatchPer/MatchOga (and even driven AFAS to implement an extra
MatchPer value for new behavior). This was all done through the SOAP API and I
have _no idea_ if this custom matching logic (and other logic) is exactly the
same for the REST API; in theory, some AFAS business logic could be attached to
a specific SOAP/REST API endpoint.

So please test things extensively when using this, and send (well documented)
feedback for anything you want to be changed. See further down for more details
about person / organisation objects.

At the moment, the Helper code assumes that all logic regarding e.g. person
matching is the same across SOAP and REST APIs; the payloads for the Update
Connectors are built with the same data.

### Payload comparison

For those who want to compare details: here's two examples of (formatted) values
returned from Helper::normalizeDataToSend() / constructXml(), which are
valid input for update connectors in the the REST API / SOAP API. The two
methods have the same input.

The significant things these examples show are:
* The way special 'id' fields are encoded;
* In the (original) XML notation, each separate object is embedded in one
  'Element' tag. This is not possible in JSON notation, so AFAS has made it
  possible to embed an array of multiple objects in one 'Element' key.

This last point means that an 'Element' key in JSON can contain either an array
of objects or a single object. It is not clear to me whether there are any
restrictions regarding this, and normalizeDataToSend() assumes there are none.

#### Multiple KnSubject objects in one update:

Input array:
```php
$input = [
  [ '#id' => 1957, 'type' => 1, 'description' => 'öndèrwérp', ],
  [ '#id' => 1958, 'type' => 2, 'description' => 'öndèrwérp twee', ],
]
```
REST / json_encode(Helper::normalizeDataToSend('KnSubject', $input), JSON_PRETTY_PRINT):
```json
{
    "KnSubject": {
        "Element": [
            {
                "@SbId": 1957,
                "Fields": {
                    "StId": 1,
                    "Ds": "\u00f6nd\u00e8rw\u00e9rp"
                }
            },
            {
                "@SbId": 1958,
                "Fields": {
                    "StId": 2,
                    "Ds": "\u00f6nd\u00e8rw\u00e9rp twee"
                }
            }
        ]
    }
}
```
SOAP / Helper::xmlEncodeNormalizedData(Helper::normalizeDataToSend('KnSubject', $input), 0):
```xml
<KnSubject xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
  <Element SbId="1957">
    <Fields>
      <StId>1</StId>
      <Ds>öndèrwérp</Ds>
    </Fields>
  </Element>
  <Element SbId="1958">
    <Fields>
      <StId>2</StId>
      <Ds>öndèrwérp twee</Ds>
    </Fields>
  </Element>
</KnSubject>
```

#### One order with two line items:

Input array:
```php
$input = [
  'sales_relation' => 'xxxxxxxxxxx',
  'Unit' => '1',
  'warehouse' => '*****',
  'line_items' => [
    [
      'item_type' => 'Art',
      'item_code' => 'xxxxx',
      'unit_type' => 'stk',
      'quantity' => '5'
    ],
    [
      'item_type' => 'Art',
      'item_code' => 'xxxxx-xxx',
      'unit_type' => 'stk',
      'quantity' => '1'
    ]
  ]
]
```
REST / json_encode(Helper::normalizeDataToSend('FbSales', $input), JSON_PRETTY_PRINT):
```json
{
    "FbSales": {
        "Element": {
            "Fields": {
                "DbId": "xxxxxxxxxxx",
                "Unit": "1",
                "War": "*****"
            },
            "Objects": {
                "FbSalesLines": {
                    "Element": [
                        {
                            "Fields": {
                                "VaIt": "Art",
                                "ItCd": "xxxxx",
                                "BiUn": "stk",
                                "QuUn": "5"
                            }
                        },
                        {
                            "Fields": {
                                "VaIt": "Art",
                                "ItCd": "xxxxxx-xxx",
                                "BiUn": "stk",
                                "QuUn": "1"
                            }
                        }
                    ]
                }
            }
        }
    }
}
```
SOAP / Helper::xmlEncodeNormalizedData(Helper::normalizeDataToSend('FbSales', $input), 0):
```xml
<FbSales xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
  <Element>
    <Fields>
      <DbId>xxxxxxxxxxx</DbId>
      <Unit>1</Unit>
      <War>*****</War>
    </Fields>
    <Objects>
      <FbSalesLines>
        <Element>
          <Fields>
            <VaIt>Art</VaIt>
            <ItCd>xxxxx</ItCd>
            <BiUn>stk</BiUn>
            <QuUn>5</QuUn>
          </Fields>
        </Element>
        <Element>
          <Fields>
            <VaIt>Art</VaIt>
            <ItCd>xxxxx-xxx</ItCd>
            <BiUn>stk</BiUn>
            <QuUn>1</QuUn>
          </Fields>
        </Element>
      </FbSalesLines>
    </Objects>
  </Element>
</FbSales>
```

### Real-world example sof inserting contact data

The above are not real-world examples that I've tried, and there might be
missing fields (or in the case of XML, 'Action' attributes).

Below are working examples (at least for SOAP connectors) of inserting /
updating contact data into AFAS, with organisation name plus address data, phone
number etc. It illustrates a possible use of the 'Action' attribute in XML
payloads and automatic addition of default values (done by this library).

The way I've used AFAS is:
- Address data goes into an organisation object;
- E-mail/phone goes into respective fields in a contact object; this is what
  AFAS calls "E-mail werk" in their entry screens;
- This means that if we insert a new contact, we have to insert a person object
  inside a contact object inside an organisation object.

My 'client' always sent XML with a person-inside-contact-inside-organisation to
update any data. It is possible to first work out whether we are only updating a
name (and not a phone or street address), and in that case send only an update
for a person object (or a person with an embedded contact object)... but that
would have just made my client code more complicated.

This means we are required to know beforehand whether we are inserting a new
person, and if so: whether we are inserting that new person for a new or
existing organisation. Otherwise we run the risk of double inserts or errors.
So I have a custom AFAS query (GetConnector) and code which matches organisation
and person objects, and then knows whether to insert/update the objects - and in
the 'update' case it gets the corresponding code (BcCo) for the objects to
update.

This leads to three different ways of having to specify 'actions' when sending
XML data over a SOAP connection, and subtle differences in the resulting XML;
these are detailed below, along with their REST/JSON equivalents.

(I never tried to do updates over the REST API, but it seems that the same
reasoning applies: you first need to determine whether you are inserting or
updating data... because there are different HTTP methods for each.)

_But first:_

_(Skip to TL/DR if not interested in confusing details.)_

#### Some possibly confusing details / effects on the current code

There are many ways of specifying and/or implying that we are inserting vs.
updating a record - especially with nested objects, and even more so with
organisation / person objects. The only thing I can offer so far, is a way that
is known to work over SOAP/XML. But there are ways of providing contradictory
information; we'll go over details here, which could serve as a guide for
improving the code later, if needed. (Code changes will need to think of
covering all possibilities and raising exceptions in 'unsupported' cases.)

_1: The 'Action' attribute in XML_

The 'Action' attribute (in the 'Fields' tag) is the most explicit specifier of
whether an update should be inserted, updated or deleted. (As mentioned, this
likely maps directly to the POST, PUT or DELETE methods of the REST API.)

The 'Action' attribute is not officially required, however. So it's _possible_
for the code to create XML without this attribute (and it should remain possible
until someone officially confirms that it's useless). But that doesn't mean it's
_properly supported_.

_1a: Impact on code structure_

It seemed logical to have the 'action' be a separate argument to
normalizeDataToSend(), because it's not really data (even though it's part of
the XML message). This seems to hold up for the REST/JSON situation too.

With the current implementation, however, the "insert" value has its effect on
the data returned: it will add default values for several fields. This is the
reason that "insert" should also be passed as an action argument, if it's meant
for generating JSON / for the REST API.

_1b: Embedded objects_

Most of the time, you can do with just one value for 'action'. There's an
exception though, which happens to be part of what we're doing here: what if we
need to insert a new contact/person object, for an existing organisation? AFAS
can do it, but we need to specify action="update" in the outside KnOrganisation
object and action="insert" in the inner KnContact and KnPerson object. In order
to support this, normalizeDataToSend() was extended to accept the fake '#action'
property inside the data, only for embedded objects. (It feels hacky and
contradicts the previous point, but this 'person inside organisation' case is
the only case I know of so far, where we would need it.)

Also: if we don't specify an action (in the outside Knorganisation) at all, AFAS
throws an error for these nested updates. Meaning: we _have_ to call
normalizeDataToSend() with "update" for the $action parameter, when doing a
SOAP/XML update of an organisation object which holds an embedded contact.
(Whereas in most other cases, we can get away with ignoring $action for
constructing a payload to send as an update.)

_2: The presence of an ID field_

An 'ID field', for this purpose, is an identifying attribute inside an Element
XML tag, or a field with a name starting with '@' in the REST API case.

One might think that the presence of such a field could in theory drive the
decision whether a payload represents an update (if present) or an insert (if
not present). Clearly (and perhaps for good reason) AFAS has chosen not to do
that.

It does raise the question, however, what happens when Action="insert" is
specified together with an ID, or "update" is specified while the ID is absent.
This has not been tested in detail yet.

_Please note:_ KnOrganisation and KnPerson objects have no ID field defined in
this way. They for some reason have a 'BcId' field which is defined as a regular
field in AFAS documentation (not an 'id=""' or @BcId field), but it says "do not
deliver the BcId field" at the same time. So our code does not define/recognize
BcId as a valid field name, and therefore makes it impossible to send a value
for it.

_3: Autonumbering (for KnOrganisation / KnPerson)_

KnOrganisation and KnPerson objects have an 'Autonum' field. This is not a real
field but it decides whether a number/code is automatically assigned to a newly
inserted record. (Note: this number/code is the BcCo field, not the BcId field
which is the actual internal ID in the AFAS database.) This means there are
three allowed ways of sending in these objects:

- Action = "insert", Autonum = true, number/code not specified.
- Action = "insert", Autonum = false, number/code specified.
- Action = "update", Autonum = false, number/code specified.

Other combinations have not been tested in detail yet. It is hoped/assumed that
AFAS will throw errors instead of exerting unspecified behavior.

The code sets Autonum to true by default (i.e. if no Autonum value is specified)
if it finds a combination of Action = "insert" and no number/code; this should
allow callers to not think about the Autonum field at all.

I believe that 'autonumbering' is an option that needs to be turned on inside
AFAS, though. (I'm not 100% sure if I remember correctly.) So if a combination
of Action = "insert" and no number/code throws an error, your AFAS environment
might need an 'autonumbering' setting tweaked.

_4: Automatic matching (for KnOrganisation / KnPerson)_

It is also possible to let AFAS search for an organisation/person with certain
matching values, e.g. name, address. If a match is found, the matching object
will be updated and otherwise a new object will be created. This done by
specifying the 'MatchOga' and 'MatchPer' field respectively (which, like
Autonum, is not a real field) with a numeric code. See the comments in
objectTypeInfo() for the meaning of various numeric codes.

This matching combined with 'Actions' can in theory lead to all kinds of
interesting behavior if the data is not inherently compatible. Tests so far
(with XML) show mostly predictable behavior and a few small bugs on the AFAS
side; details are outlined for possible later reference:

- A MatchOga/MatchPer value overrides the 'Action' field, when a value is passed
  for the 'matching' field(s). Meaning: it does not matter if you specify action
  "insert" or "update": these will do the same thing. (Again: REST has not been
  tested yet to see whether the difference between POST and PUT falls away too.)

- (Given this, we might expect MatchOga=6 and MatchPer=7 ('always insert') to
  insert new data also when using Action="update". We haven't explicitly tested
  that yet, nor what happens when the data holds an already existing BcCo.)

- If a MatchOga/MatchPer=0 (match on BcCo) is specified, but no BcCo is
  specified, then:
  - For action="update", AFAS throws an error. (As expected. We also expect this
    to happen for 'simpler' objects, when action="update" is specified without
    an ID field.)
  - For action="insert", a record is always inserted. (This is also expected,
    but it's worth noting that MatchOga is effectively ignored, or effectively
    defaults to 6, in this case. MatchOga does not 'win' here.)

  We expect this behavior to also extend to other 'matching' fields (instead of
  BcCo) if the MatchOga/MatchPer value changes.

- If MatchOga=0 is specified, and an existing BcCo is specified but no
  Action="update" is specified explicitly, an error gets thrown. (Again: unlike
  "insert", which acts as "update". This seems like a bug; see code comments.)

- If MatchOga is not specified, and a BcCo value is specified, we've observed an
  error being thrown for inserts of KnOrganisation objects. This may be
  connected to 'autonumbering' behavior though: maybe this only happens when a
  certain 'autonumbering' setting in AFAS is set? (Because if autonumbering is
  off, one would expect this to not throw an error. Or maybe this only throws no
  error if the 'Autonum' field is _not_ explicitly set to false. Or... maybe
  only if we send embedded contact objects inside the KnOrganisation?) This
  needs some more testing; also to see if this behavior extends to 'simpler'
  objects, as noted in point 2.

- If multiple matches are found according to the MatchOga value, then AFAS will
  throw an error, since it does not know which record to update. (This can
  happen with values >0 which are not equivalent to 'always insert'; value 0
  can never yield two matches because BcCo is a unique field.)

When normalizeDataToSend() is called with the data with(out) MatchOga/MatchPer
values, it tries to set a sensible default value, to keep the caller from having
to think about this field in most cases, and to make surer that behavior is
predictable. (Because we officially don't know what effect an unspecified
MatchOga/MatchPer might have in cases where it's expected.) This is what we do
in that case:

- If a field value that is supposed to be a unique ID (like BcCo or fiscal
  number) is provided, we set the corresponding match value so an existing
  record with the same ID value would be updated. _(Arguable bug: we do this
  also with action="insert".)_

- In other cases, for action="insert" we set MatchOga=6. It doesn't make any
  difference in practice, but it's more explicit. _(Omission: we don't set
  MatchPer=7 for person objects.)_

- In other (non-insert) cases, we set the value to 0. This will throw an error
  (since no BcCo is present), which at least makes sure that no behavior gets
  triggered which unpredictably updates some existing record that wasn't really
  specified.

#### TL/DR: What to do in practice

* Regardless of using SOAP or REST: always specify "insert" as the $action
  parameter to normalizeDataToSend(), when constructing a payload for insering
  new objects.

* When using SOAP/XML: always specify the $action parameter. (It's safest not to
  assume the default of an empty action will do the same as "update", because
  AFAS seems to have bugs in some cases; see point 1b above.)

_(Note that while JSON output is also included below, I have never tested whether
these payloads actually work over a REST connection. Feedback is appreciated.)_

#### Situation 1: inserting a new contact for a new organisation:

Input array:
```php
$input = [
  'name' => 'Wyz',
  'address' => [
    'street' => 'Govert Flinckstraat',
    'house_number' => '168',
    'house_number_ext' => 'A',
    'zip_code' => '1072EP',
    'town' => 'Amsterdam',
    'country_code' => 'NL',
  ],
  'contact' => [
    'email' => 'rm@wyz.biz',
    'phone' => '06-22517218',
    'person' => [
      'first_name' => 'Roderik',
      'last_name' => 'Muit',
      'search_name' => 'MUIT',
      'initials' => 'R.',
    ],
  ],
]
```
Helper::xmlEncodeNormalizedData(Helper::normalizeDataToSend('KnOrganisation', $input, 'insert', true), 0):

_(Note the MatchPer field being 0, when it should actually be 7 if it was
consistent with the MatchOga field. That's an inconsistency in my code. It
doesn't really matter though, because it has the same effect for
Action="insert"; see point 4 above.)_
```xml
<KnOrganisation xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
  <Element>
    <Fields Action="insert">
      <PbAd>0</PbAd>
      <AutoNum>1</AutoNum>
      <MatchOga>6</MatchOga>
      <Nm>Wyz</Nm>
    </Fields>
    <Objects>
      <KnBasicAddressAdr>
        <Element>
          <Fields Action="insert">
            <CoId>NL</CoId>
            <PbAd>0</PbAd>
            <Ad>Govert Flinckstraat</Ad>
            <HmNr>168</HmNr>
            <HmAd>A</HmAd>
            <ZpCd>1072EP</ZpCd>
            <Rs>Amsterdam</Rs>
            <BeginDate>2018-01-18</BeginDate>
            <ResZip>0</ResZip>
          </Fields>
        </Element>
      </KnBasicAddressAdr>
      <KnContact>
        <Element>
          <Fields Action="insert">
            <TeNr>06-22517218</TeNr>
            <EmAd>rm@wyz.biz</EmAd>
            <ViKc>PRS</ViKc>
          </Fields>
          <Objects>
            <KnPerson>
              <Element>
                <Fields Action="insert">
                  <AutoNum>1</AutoNum>
                  <MatchPer>0</MatchPer>
                  <SeNm>MUIT</SeNm>
                  <FiNm>Roderik</FiNm>
                  <In>R.</In>
                  <LaNm>Muit</LaNm>
                  <SpNm>0</SpNm>
                  <ViGe>O</ViGe>
                  <Corr>0</Corr>
                </Fields>
              </Element>
            </KnPerson>
          </Objects>
        </Element>
      </KnContact>
    </Objects>
  </Element>
</KnOrganisation>
```
For comparison: json_encode(Helper::normalizeDataToSend('KnOrganisation', $input, 'insert'), JSON_PRETTY_PRINT):

_(The MatchOga / MatchPer fields may have to be converted to integers instead,
by this code. Please send feedback.)_
```json
{
    "KnOrganisation": {
        "Element": {
            "Fields": {
                "PbAd": false,
                "AutoNum": true,
                "MatchOga": "6",
                "Nm": "Wyz"
            },
            "Objects": {
                "KnBasicAddressAdr": {
                    "Element": {
                        "Fields": {
                            "CoId": "NL",
                            "PbAd": false,
                            "Ad": "Govert Flinckstraat",
                            "HmNr": "168",
                            "HmAd": "A",
                            "ZpCd": "1072EP",
                            "Rs": "Amsterdam",
                            "BeginDate": "2018-01-18",
                            "ResZip": false
                        }
                    }
                },
                "KnContact": {
                    "Element": {
                        "Fields": {
                            "TeNr": "06-22517218",
                            "EmAd": "rm@wyz.biz",
                            "ViKc": "PRS"
                        },
                        "Objects": {
                            "KnPerson": {
                                "Element": {
                                    "Fields": {
                                        "AutoNum": true,
                                        "MatchPer": "0",
                                        "SeNm": "MUIT",
                                        "FiNm": "Roderik",
                                        "In": "R.",
                                        "LaNm": "Muit",
                                        "SpNm": false,
                                        "ViGe": "O",
                                        "Corr": false
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
    }
}
```
#### Situation 2: inserting a new contact for (/ while updating) an existing organisation:

This needs the '#action' hack that was also discussed in point 1b above, because
the action for the embedded objects is different from the outer KnOrganisation.

Also, just to note: I opted to always query for an existing organisation /
person code (as outlined above), and not to use any MatchOga/MatchPer values
other than 0 and 'insert'(6/7) because it's hard to make the code for that
robust: e.g. when more than one match is found, an error is thrown which then
needs to be worked around. Also, this 'Matching' functionality does not exist
for the KnContact objects, so we would need more extensive testing/documentation
around what happens with those.

Input array:
```php
$input = [
  'number' => '1100000', // Add existing organisation number/code (BcCo).
  'name' => 'Wyz',
  'address' => [
    'street' => 'Govert Flinckstraat',
    'house_number' => '168',
    'house_number_ext' => 'A',
    'zip_code' => '1072EP',
    'town' => 'Amsterdam',
    'country_code' => 'NL',
  ],
  'contact' => [
    '#action' => 'insert', // Add #action 'fake property' to insert new contact.
    'email' => 'rm@wyz.biz',
    'phone' => '06-22517218',
    'person' => [
      '#action' => 'insert', // (This is superfluous, with our normalizeDataToSend implementation.)
      'first_name' => 'Roderik',
      'last_name' => 'Muit',
      'search_name' => 'MUIT',
      'initials' => 'R.',
    ],
  ],
]
```
The differences with the 'insert new person' case in the input array are noted
in the PHP. The differences in output are:
- The outer Action attribute has changed (obviously), as well as the one in
  the KnBasicAddressAdr object;
- AutoNum field is not added to the KnOrganisation object (and a BcCo is present
  because we specified that in the input, obviously);
- MatchOga is 0 instead of 6;
- No default value is added for PbAd in the KnOrganisation object.
_(Note that a default value is still added for PbAd in the KnBasicAddressAdr
object; I expect this to be a bug and have documented the code where IMHO it 
needs a more substantial rewrite. I can't test this myself at the moment.)_

Helper::xmlEncodeNormalizedData(Helper::normalizeDataToSend('KnOrganisation', $input, 'update', true), 0):
```xml
<KnOrganisation xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
  <Element>
    <Fields Action="update">
      <MatchOga>0</MatchOga>
      <BcCo>1100000</BcCo>
      <Nm>Wyz</Nm>
    </Fields>
    <Objects>
      <KnBasicAddressAdr>
        <Element>
          <Fields Action="update">
            <CoId>NL</CoId>
            <PbAd>0</PbAd>
            <Ad>Govert Flinckstraat</Ad>
            <HmNr>168</HmNr>
            <HmAd>A</HmAd>
            <ZpCd>1072EP</ZpCd>
            <Rs>Amsterdam</Rs>
            <BeginDate>2018-01-18</BeginDate>
            <ResZip>0</ResZip>
          </Fields>
        </Element>
      </KnBasicAddressAdr>
      <KnContact>
        <Element>
          <Fields Action="insert">
            <TeNr>06-22517218</TeNr>
            <EmAd>rm@wyz.biz</EmAd>
            <ViKc>PRS</ViKc>
          </Fields>
          <Objects>
            <KnPerson>
              <Element>
                <Fields Action="insert">
                  <AutoNum>1</AutoNum>
                  <MatchPer>0</MatchPer>
                  <SeNm>MUIT</SeNm>
                  <FiNm>Roderik</FiNm>
                  <In>R.</In>
                  <LaNm>Muit</LaNm>
                  <SpNm>0</SpNm>
                  <ViGe>O</ViGe>
                  <Corr>0</Corr>
                </Fields>
              </Element>
            </KnPerson>
          </Objects>
        </Element>
      </KnContact>
    </Objects>
  </Element>
</KnOrganisation>
```
For comparison: json_encode(Helper::normalizeDataToSend('KnOrganisation', $input), JSON_PRETTY_PRINT):
```json
{
    "KnOrganisation": {
        "Element": {
            "Fields": {
                "PbAd": false,
                "MatchOga": "0",
                "BcCo": "1100000",
                "Nm": "Wyz"
            },
            "Objects": {
                "KnBasicAddressAdr": {
                    "Element": {
                        "Fields": {
                            "CoId": "NL",
                            "PbAd": false,
                            "Ad": "Govert Flinckstraat",
                            "HmNr": "168",
                            "HmAd": "A",
                            "ZpCd": "1072EP",
                            "Rs": "Amsterdam",
                            "BeginDate": "2018-01-18",
                            "ResZip": false
                        }
                    }
                },
                "KnContact": {
                    "Element": {
                        "Fields": {
                            "TeNr": "06-22517218",
                            "EmAd": "rm@wyz.biz",
                            "ViKc": "PRS"
                        },
                        "Objects": {
                            "KnPerson": {
                                "Element": {
                                    "Fields": {
                                        "AutoNum": true,
                                        "MatchPer": "0",
                                        "SeNm": "MUIT",
                                        "FiNm": "Roderik",
                                        "In": "R.",
                                        "LaNm": "Muit",
                                        "SpNm": false,
                                        "ViGe": "O",
                                        "Corr": false
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
    }
}
```

#### Situation 3: update existing contact, inside an existing organisation.

```php
$input = [
  'number' => '1100000', // Add existing organisation number/code (BcCo).
  'name' => 'Wyz',
  'address' => [
    'street' => 'Govert Flinckstraat',
    'house_number' => '168',
    'house_number_ext' => 'A',
    'zip_code' => '1072EP',
    'town' => 'Amsterdam',
    'country_code' => 'NL',
  ],
  'contact' => [
    'email' => 'rm@wyz.biz',
    'phone' => '06-22517218',
    'person' => [
      'code' => '100000', // Add existing person number/code (BcCo).
      'first_name' => 'Roderik',
      'last_name' => 'Muit',
      'search_name' => 'MUIT',
      'initials' => 'R.',
    ],
  ],
]
```
The differences in output with (/ on top of) situation 2 above, are:
- The inner two Action attributes have changed, obviously;
- AutoNum field is not added to the KnPerson object (and a BcCo is present 
  because we specified that in the input, obviously);
- (MatchPer is not different, but as noted above, that's an inconsistency in the
  situation 1/2 which does not make a practical difference;)
- No default values are added for ViKc in the KnContact object, or for SpNm / 
  ViGe / Corr in the KnPerson object.

Helper::xmlEncodeNormalizedData(Helper::normalizeDataToSend('KnOrganisation', $input, 'update', true), 0):
```xml
<KnOrganisation xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
  <Element>
    <Fields Action="update">
      <MatchOga>0</MatchOga>
      <BcCo>1100000</BcCo>
      <Nm>Wyz</Nm>
    </Fields>
    <Objects>
      <KnBasicAddressAdr>
        <Element>
          <Fields Action="update">
            <CoId>NL</CoId>
            <PbAd>0</PbAd>
            <Ad>Govert Flinckstraat</Ad>
            <HmNr>168</HmNr>
            <HmAd>A</HmAd>
            <ZpCd>1072EP</ZpCd>
            <Rs>Amsterdam</Rs>
            <BeginDate>2018-01-18</BeginDate>
            <ResZip>0</ResZip>
          </Fields>
        </Element>
      </KnBasicAddressAdr>
      <KnContact>
        <Element>
          <Fields Action="update">
            <TeNr>06-22517218</TeNr>
            <EmAd>rm@wyz.biz</EmAd>
          </Fields>
          <Objects>
            <KnPerson>
              <Element>
                <Fields Action="update">
                  <MatchPer>0</MatchPer>
                  <BcCo>1005127</BcCo>
                  <SeNm>MUIT</SeNm>
                  <FiNm>Roderik</FiNm>
                  <In>R.</In>
                  <LaNm>Muit</LaNm>
                </Fields>
              </Element>
            </KnPerson>
          </Objects>
        </Element>
      </KnContact>
    </Objects>
  </Element>
</KnOrganisation>
```
For comparison: json_encode(Helper::normalizeDataToSend('KnOrganisation', $input), JSON_PRETTY_PRINT):
```json
{
    "KnOrganisation": {
        "Element": {
            "Fields": {
                "PbAd": false,
                "MatchOga": "0",
                "BcCo": "1100000",
                "Nm": "Wyz"
            },
            "Objects": {
                "KnBasicAddressAdr": {
                    "Element": {
                        "Fields": {
                            "CoId": "NL",
                            "PbAd": false,
                            "Ad": "Govert Flinckstraat",
                            "HmNr": "168",
                            "HmAd": "A",
                            "ZpCd": "1072EP",
                            "Rs": "Amsterdam",
                            "BeginDate": "2018-01-21",
                            "ResZip": false
                        }
                    }
                },
                "KnContact": {
                    "Element": {
                        "Fields": {
                            "TeNr": "06-22517218",
                            "EmAd": "rm@wyz.biz"
                        },
                        "Objects": {
                            "KnPerson": {
                                "Element": {
                                    "Fields": {
                                        "MatchPer": "0",
                                        "BcCo": "100000",
                                        "SeNm": "MUIT",
                                        "FiNm": "Roderik",
                                        "In": "R.",
                                        "LaNm": "Muit"
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
    }
}
```
