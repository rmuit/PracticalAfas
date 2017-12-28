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
contact object inside organization objects, and deal with all the funny (e.g.
'matching') behavior that AFAS exhibits. (Especially when updating nested person
/ organization objects: read the code and save yourself the headaches I've had.)

### Warning: REST / JSON is untested

I've tested / used this code years ago, for a.o. sending orders and customer
(organisation / person) data from a commerce system. This has influenced logic
logic surrounding MatchPer/MatchOga (and even driven AFAS to implement an extra
MatchPer value for new behavior). This was all done through the SOAP API and I
have _no idea_ if this custom matching logic (and other logic) is exactly the
same for the REST API; in theory, some AFAS business logic could be attached to
the specific SOAP/REST API endpoint rather than the AFAS software.

So please test things extensively when using this, and send (well documented)
feedback for anything you want to be changed.

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
```
[
  [ "#id" => 1957, "type" => 1, "description" => "öndèrwérp", ],
  [ "#id" => 1958, "type" => 2, "description" => "öndèrwérp twee", ],
]
```
REST / Helper::normalizeDataToSend('KnSubject', $input), after JSON encoding:
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
SOAP / Helper::constructXml("KnSubject", $input, '', '', 0):
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
```
[
  "sales_relation" => "xxxxxxxxxxx",
  "Unit" => "1",
  "warehouse" => "*****",
  "line_items" => [
    [
      "item_type" => "Art",
      "item_code" => "xxxxx",
      "unit_type" => "stk",
      "quantity" => "5"
    ],
    [
      "item_type" => "Art",
      "item_code" => "xxxxx-xxx",
      "unit_type" => "stk",
      "quantity" => "1"
    ]
  ]
]
```
REST / Helper::normalizeDataToSend('FbSales', $input), after JSON encoding:
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
SOAP / Helper::constructXml("FbSales", $input, '', '', 0):
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
