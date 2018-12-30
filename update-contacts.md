## Real-world examples of inserting contact data

_This is part of a text I'd written for version 1 of this library. Some
superfluous parts have been deleted since then, and output examples have moved
into text files in tests/update_examples... but I'm keeping all notes on
my (embedded) contact synchronization here, because it may save me confusion in
the future. Maybe it will help someone else too._

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

### Some possibly confusing details / effects on the current code

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

_1a: Embedded objects_

Most of the time, you can do with just one value for 'action'. There's an
exception though, which happens to be part of what we're doing here: what if we
need to insert a new contact/person object, for an existing organisation? AFAS
can do it, but we need to specify action="update" in the outside KnOrganisation
object and action="insert" in the inner KnContact and KnPerson object. We can
do this by specifying "update" as the action argument to UpdateObject::create() 
and then doing ```$object->getObject('KnContact')->setAction('insert');```
afterwards.

A note: if we don't specify an action (in the outside Knorganisation) at all, 
AFAS throws an error for these nested updates. Meaning: we _have_ to set
"update" for the 'Action' in the outer UpdateObject, when doing an update of an
organisation object which holds an embedded contact. (In most other cases, we
can get away with ignoring $action... but this case is why the code recommends
always passing either "update" or "insert".)

Another note: I have not tried this over JSON/REST.

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
field in AFAS documentation (not an 'id=""' or @BcId field), but the XSD schema
says "do not deliver the BcId field" at the same time. So our code does not 
define/recognize BcId as a valid field name, and therefore makes it impossible
to send a value for it.

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
  Action="update" is specified explicitly, an error gets thrown. (Again: if
  "insert" is specified, things are OK and the operation acts as "update".) This 
  seems like a bug. Some details are in 'situation 3' below.

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

When no MatchOga/MatchPer values are set, the UpdateObject tries to set a
sensible default value on validation/output, to keep the caller from having to
think about this field in most cases, and to make surer that behavior is
predictable. (Because we officially don't know what effect an unspecified
MatchOga/MatchPer might have in cases where it's expected.) This is what we do
in that case:

- For action="insert" we set MatchOga=6/MatchPer=7. It doesn't make any
  difference in practice (because it doesn't alter the insert action), but it's
  more explicit.

- For other actions, if a field value that is supposed to be a unique ID (like 
  BcCo or fiscal number) is provided, we set the corresponding match value so 
  an existing record with the same ID value would be updated.

- In other (non-insert) cases, we set the value to 0. This will throw an error
  (since no BcCo is present), which at least makes sure that no behavior gets
  triggered which unpredictably updates some existing record that wasn't really
  specified.

### Situation 1: inserting a new contact for a new organisation:

See [KnOrg-embedded-insert.txt](test/update-examples/KnOrg-embedded-insert.txt) / 
[KnOrg-embedded-insert2.txt](test/update-examples/KnOrg-embedded-insert2.txt).

### Situation 2: inserting a new contact for (/ while updating) an existing organisation:

See [KnOrg-embedded-upsert.txt](test/update-examples/KnOrg-embedded-upsert.txt).

To check the differences in output: ...just diff the text files.

Also, just to note: I opted to always query for an existing organisation /
person code (as outlined above), and not to use any MatchOga/MatchPer values
other than 0 and 'insert'(6/7) because it's hard to make the code for that
robust: e.g. when more than one match is found, an error is thrown which then
needs to be worked around. Also, this 'Matching' functionality does not exist
for the KnContact objects, so we would need more extensive testing/documentation
around what happens with those.

### Situation 3: update existing contact, inside an existing organisation.

See [KnOrg-embedded-update.txt](test/update-examples/KnOrg-embedded-update.txt).

(A note: one would expect that the presence of a MatchOga/MatchPer of 0 and
BcCo numbers would make it clear that this concerns updates. However, last time
I tested (feb 2015), the XML UpdateConnector threw an error "Object variable or 
With block variable not set" when I did not explicitly specify action "update".
This is a Visual Basic error, pointing to an error in AFAS' program code.)
