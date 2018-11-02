<?php
/**
 * This file is part of the PracticalAfas package.
 *
 * (c) Roderik Muit <rm@wyz.biz>
 *
 * For the full copyright and license information, please view the LICENSE file
 * that was distributed with this source code.
 */

namespace PracticalAfas\UpdateConnector;

use InvalidArgumentException;
use OutOfBoundsException;
use UnexpectedValueException;

/**
 * Base class for generating output to send to AFAS Update Connectors.
 *
 * For any object type which has known property definitions (either in this
 * class or in a class that is present in the static $classMap variable),
 * - An UpdateObject can be create()'d from array of data having a format that
 *   is a little easier to grasp than the JSON structure (and a lot easier than
 *   XML);
 * - output() can immediately be called on the result to produce JSON/XML data
 *   suitable for sending through an Update Connector. No other calls are
 *   needed; validation will be done automatically.
 * - Custom validation can be done (at output or independently). Default values
 *   are populated and/or other non-invasive changes can be made to the output
 *   during validation, or this behavior can be suppressed, as needed.
 * - There are setters and getters for individual parts of the data (individual
 *   fields and embedded objects, additional full elements, action values) so
 *   an object can be constructed gradually / manipulated.
 *
 * See create() for more information; see the tests/update_examples directory
 * for example array inputs.
 *
 * An UpdateObject can hold data for several elements to send through an
 * Update Connector at once; create() can be called with either the input data
 * structure for one element, or an array of those structures for more elements.
 *
 * About wording: the terms 'object' and 'element' are used in a way that may
 * not be apparent at first. The difference may be more apparent when looking
 * at the JSON representation of a message sent into an Update Connector:
 * - The whole JSON string represents an object; this object is also
 *   represented by one UpdateObject instance.
 * - The JSON string contains one "Element" value, which can actually contain
 *   one or more elements. (Maybe it should have been called "Elements", even
 *   though it most often will contain a single element.) One such element
 *   contains up to 3 key-value pairs: "Fields", "Objects" and an ID.
 * Key-value pairs inside the "Objects" value of an element each represent one
 * embedded object (again represented by an UpdateObject instance) which can
 * again (depending on the key/object type) contain one or multiple elements.
 *
 * About subclassing: see the comments at $classMap.
 */
class UpdateObject
{
    /**
     * @see output(); bitmask value for the $change_behavior argument.
     */
    const ALLOW_NO_CHANGES = 0;

    /**
     * @see output(); bitmask value for the $change_behavior argument.
     */
    const ALLOW_EMBEDDED_CHANGES = 2;

    /**
     * @see output(); bitmask value for the $change_behavior argument.
     */
    const ALLOW_DEFAULTS_ON_INSERT = 4;

    /**
     * @see output(); bitmask value for the $change_behavior argument.
     */
    const ALLOW_DEFAULTS_ON_UPDATE = 8;

    /**
     * @see output(); bitmask value for the $change_behavior argument.
     */
    const ALLOW_REFORMAT = 16;

    /**
     * @see output(); bitmask value for the $change_behavior argument.
     */
    const ALLOW_CHANGES = 32;

    /**
     * @see output(); bitmask value for the $change_behavior argument.
     */
    const FLATTEN_SINGLE_ELEMENT = 1;

    /**
     * Default behavior for output(,$change_behavior).
     *
     * This is ALLOW_EMBEDDED_CHANGES + ALLOW_REFORMAT
     * + ALLOW_DEFAULTS_ON_INSERT + FLATTEN_SINGLE_ELEMENT.
     *
     * If future versions of this class introduce new behavior through
     * additional bitmask values, this value may or may not be changed to
     * incorporate that behavior by default.
     */
    const DEFAULT_CHANGE = 23;

    /**
     * @see output(); bitmask value for the $validation_behavior argument.
     */
    const VALIDATE_NOTHING = 0;

    /**
     * @see output(); bitmask value for the $validation_behavior argument.
     */
    const VALIDATE_ESSENTIAL = 1;

    /**
     * @see output(); bitmask value for the $validation_behavior argument.
     */
    const VALIDATE_REQUIRED = 2;

    /**
     * @see output(); bitmask value for the $validation_behavior argument.
     */
    const VALIDATE_NO_UNKNOWN = 4;

    /**
     * @see output(); bitmask value for the $validation_behavior argument.
     */
    const VALIDATE_FORMAT = 8;

    /**
     * Default behavior for output(,,$validation_behavior).
     *
     * If future versions of this class introduce new behavior through
     * additional bitmask values, this value may or may not be changed to
     * incorporate that behavior by default.
     */
    const DEFAULT_VALIDATION = 7;

    /**
     * A mapping from object type to the class name implementing the type.
     *
     * Any object types not mentioned here are implemented by this class, or
     * not implemented.
     *
     * Please note that names of object types (the keys in this variable) are
     * not necessarily equal to the names of 'object reference fields' in
     * property definitions. See $propertyDefinitions docs for the difference.
     *
     * A project which wants to implement custom behavior for specific object
     * types, or define new object types, can do several things. As an example,
     * say you want to add a custom field/behavior to KnPerson objects. You can:
     * - Create a MyPerson class to extend OrgPersonContact (or to extend
     *   UpdateObject, but the current KnPerson is implemented in
     *   OrgPersonContact); define the extra field/behavior in
     *   $propertyDefinitions etc and call 'new MyPerson($values, $action)' to
     *   get an object representing this type.
     * - The same but implement multiple overridden objects in the same class
     *   named e.g. MyUpdateObject, and call
     *   'new MyUpdateObject($values, $action, "KnPerson")' to get an object.
     * - The same but set
     *   UpdateObject::$classMap['KnPerson'] = '\MyProject\MyPerson', and call
     *   UpdateObject::create('KnPerson, $values, $action) to get an object.
     * The latter way enables creating custom embedded objects, e.g.
     * creating a KnContact containing an embedded KnPerson object with the
     * custom field/behavior. If $classMap is not modified, the embedded object
     * will be created using the standard class.
     *
     * @todo before releasing 2.0, see if we should make this protected and
     *   add a method to set (change/add) one key + to get the whole thing.
     *
     * @var string[]
     */
    public static $classMap = [
        'FbSales' => '\PracticalAfas\UpdateConnector\ObjectWithCountry',
        'KnBasicAddress' => '\PracticalAfas\UpdateConnector\KnBasicAddress',
        'KnContact' => '\PracticalAfas\UpdateConnector\OrgPersonContact',
        'KnOrganisation' => '\PracticalAfas\UpdateConnector\OrgPersonContact',
        'KnPerson' => '\PracticalAfas\UpdateConnector\OrgPersonContact',
    ];

    /**
     * The type of object (and the name of the corresponding Update Connector).
     *
     * This is expected to be set on construction and to never change. Don't
     * reference it directly; use getType().
     *
     * @var string
     */
    protected $type = '';

    /**
     * The type of parent object which this data is going to be embedded into.
     *
     * This is not used by this class, but child classes may need it because it
     * can influence the available property definitions. It's set only in the
     * constructor. (It's a bit unfortunate that the parent type needs to be an
     * argument to create(), but populating element values would be way more
     * complicated for those child classes if it wasn't. Maybe it's possible to
     * make a setter for this, but that would need careful consideration.)
     *
     * @var string
     */
    protected $parentType = '';

    /**
     * Property definitions for data in this object.
     *
     * Code is explicitly allowed to reference this variable directly. (There's
     * no getter). The constructor is responsible for populating it, if it is
     * not already populated in the class definition.
     *
     * Code may also manipulate properties to influence the validation process
     * but must then obviously be very careful to ensure that all validation
     * works uniformly. (This can be tricky / requires knowledge of exactly
     * when which types of validation are performed, as it can happen during
     * addElements(), setField() and validateElement() calls.)
     *
     * The format is not related to AFAS but a structure specific to this class.
     * The array has the following keys:
     *   'id_property': If the object type has an ID property, it's name. (e.g.
     *                  for KnSubject this is 'SbId', because a subject always
     *                  has a "@SbId" property. ID properties are distinguished
     *                  by being outside of the 'Fields' section and being
     *                  prefixed by "@".)
     *   'fields':   Arrays describing properties of fields, keyed by AFAS
     *               field names. An array may be empty but must be defined for
     *               a field to be recognized. Properties known to this class:
     *   - 'alias':    A name for this field that is more readable than AFAS'
     *                 field name and that can be used in input data structures.
     *   - 'type':     Data type of the field, used for validation ond output
     *                 formatting. Values: boolean, date, int, decimal.
     *                 Optional; unspecified types are treated as strings.
     *   - 'default':  Default value for the field, if the field is not present.
     *                 (If set to null, this is not replaced by a default.)
     *   - 'required': If true, this field is required and our output()
     *                 method will throw an exception if the field is not
     *                 populated when action is "insert". If (int)1, this is
     *                 done even if output() is not instructed to validate
     *                 required values; this can be useful to set if it is
     *                 known that AFAS itself will throw an unclear error when
     *                 it receives no value for the field.
     *   'objects':  Arrays describing properties of the 'object reference
     *               fields' defined for this object type, keyed by their names.
     *               An array may be empty but must be defined for an embedded
     *               object to be recognized. Properties known to this class:
     *   - 'type':     The name of the AFAS object type which this reference
     *                 points to. If not provided, the type is assumed to be
     *                 equal to the name of the reference field. See below.
     *   - 'alias':    A name for this field that can be used instead of the
     *                 AFAS name and that can be used in input data structures.
     *   - 'multiple': If true, the embedded object can hold more than one
     *                 element.
     *   - 'default':  Default value for the field, if the field is not present.
     *                 (If set to null, this is not replaced by a default.) The
     *                 value must be an array of element(s) values.
     *   - 'required': See 'fields' above.
     *
     * Child classes may define extra properties and handle those at will.
     *
     * About 'object reference fields': these are the names as found in e.g.
     * XSD schemas for a certain Update Connector. These are not necessarily
     * equal to the actual object type names, though they often are. As an
     * example: a KnPerson object has two address fields for home address and
     * postal address. Both addresses are objects of type KnBasicAddress,
     * however the "Objects" section of a KnPerson needs to reference them
     * separately and uses the names "KnBasicAddressAdr" & "KnBasicAddressPad"
     * for this. (AFAS documentation likely does not explain this, so) we call
     * the keys in the "Objects" part of an element 'object reference fields'.
     *
     * @var array[]
     */
    protected $propertyDefinitions = [];

    /**
     * The action(s) to perform on the data: "insert", "update" or "delete".
     *
     * @see setAction()
     *
     * @var string[]
     */
    protected $actions = [];

    /**
     * Data representing one or several elements.
     *
     * Data of one element is typically structured just like a single "Element"
     * object inside a JSON representation for the REST API. This variable
     * should not be referenced directly, though.
     *
     * @see getElements()
     *
     * @var array[]
     */
    protected $elements = [];

    /**
     * Instantiates a new UpdateObject, or a class defined in our map.
     *
     * One thing to remember for the $action argument: when wanting to use this
     * object's output for inserting new data into AFAS, it should have value
     * "insert". This will also take care of setting default values. In other
     * cases, preferably pass "update" even though that's very often equivalent
     * to passing nothing. (Yes this is a messy argument; @see setAction() if
     * you really want to know reasons.)
     *
     * @param string $type
     *   The type of object, i.e. the 'Update Connector' name to send this data
     *   into. Possible values can be found in $classMap and __construct().
     * @param array $elements
     *   (Optional) Data to set in the UpdateObject, representing one or more
     *   elements of this type; see the definitions in __construct() or child
     *   classes for possible values. The data is assumed to represent multiple
     *   elements if all keys are numeric and all values are arrays (or if the
     *   value is a single empty array, meaning zero elements); it's processed
     *   as a single element otherwise. Keys inside a single element can be:
     *   - field names or aliases (as defined in __construct() / child classes);
     *   - names or aliases of the 'object reference fields' (see
     *     $propertyDefinitions)  which can be embedded into this object
     *     type; the values must be an array of data (one or multiple elements)
     *     to set in that object, or an UpdateObject;
     *   - '@xxId' (where xx is a type-specific two letter code) or '@id', which
     *     holds the 'ID value' for an element. (In the output, this ID value is
     *     located in the Element structure the same level as the Fields, rather
     *     than inside Fields. Or in XML: it's an attribute in the Element tag.)
     *   The names for fields and objects can either all be on the same level,
     *   or nested in a 'Fields' and 'Objects' array.
     * @param string $action
     *   (Optional) The action to perform on the data: "insert", "update" or
     *   "delete". @see setAction() or the comments above.
     * @param int $validation_behavior
     *   (Optional) Specifies whether/how the elements should be validated now,
     *   throwing an exception on failure. By default only very basic
     *   validation on individual fields (e.g. for correct data types) is done
     *   now, and full validation happens during output(). This value is a
     *   bitmask; the relevant bits for validating a single field are
     *   VALIDATE_ESSENTIAL and VALIDATE_FORMAT; most other bits have to do
     *   with validation of the object as a whole and are always ignored here.
     *   To have the full object evaluated after creating it, call
     *   getElements(DEFAULT_CHANGE, DEFAULT_VALIDATION). See output() for more.
     * @param string $parent_type
     *   (Optional) If nonempty, the return value will be suitable for
     *   embedding inside the parent type, which can have a slightly different
     *   structure (e.g. allowed fields) in some cases. It's unlikely this
     *   parameter needs to be passed, except when called recursively from
     *   UpdateObject code. Unlike $action, this value cannot be changed after
     *   the object is instantiated.
     *
     * @return static
     *
     * @throws \InvalidArgumentException
     *   If any argument is invalid or he element data contains invalid values.
     * @throws \OutOfBoundsException
     *   If the element data contains unknown field/object names.
     * @throws \UnexpectedValueException
     *   If this object's defined properties are invalid.
     *
     * @see $propertyDefinitions
     * @see __construct()
     * @see output()
     */
    public static function create($type, array $elements = [], $action = '', $validation_behavior = self::VALIDATE_ESSENTIAL, $parent_type = '') {
        // If a custom class is defined for this type, instantiate that one.
        if (isset(static::$classMap[$type])) {
            return new static::$classMap[$type]($elements, $action, $type, $validation_behavior, $parent_type);
        }
        // Use self(); static() yields errors when a child class creates a new
        // embedded object which is defined in this base class.
        return new self($elements, $action, $type, $validation_behavior, $parent_type);
    }

    /**
     * UpdateObject constructor.
     *
     * Do not call this method directly; use UpdateObject::create() instead.
     * This constructor will likely not stay fully forward compatible for all
     * object types; it may start throwing exceptions for some types over time,
     * as they are implemented in dedicated child classes. (This warning
     * applies specifically to UpdateObject; child classes may allow callers to
     * call their constructor directly.)
     *
     * The arguments' order is switched from create(), and $type is optional,
     * to allow e.g. 'new CustomType($values)' more easily. ($type is not
     * actually optional in this class; an empty value will cause an exception
     * to be thrown. But many child classes will likely ignore the 3rd/4th
     * argument. So if they're lazy, they can get away with not reimplementing
     * a constructor.)
     *
     * @param array $elements
     * @param string $action
     * @param string $type
     * @param int $validation_behavior
     * @param string $parent_type
     *
     * @see create()
     */
    public function __construct(array $elements = [], $action = '', $type = '', $validation_behavior = self::VALIDATE_ESSENTIAL, $parent_type = '')
    {
        if (empty($this->propertyDefinitions)) {
            // Below definitions are based on what AFAS calls the 'XSD Schema'
            // for SOAP, retrieved though a Data Connector in november 2014.
            // They're amended with extra info like more understandable aliases
            // for the field names, and default values. There are lots of Dutch
            // comment lines in this function; these were gathered from an
            // online knowledge base page around 2012 when that was the only
            // form/language of documentation.
            switch ($type) {
                case 'KnSalesRelationPer':
                    // [ Contains notes from 2014, based on an example XML snippet
                    //   from 2011 which I inherited from a commerce system. Please
                    //   send PRs to fix the fields / comments if you feel inclined. ]
                    // NOTE - not checked against XSD yet, only taken over from Qoony example
                    // Fields:
                    // ??? = Overheids Identificatienummer, which an AFAS expert recommended
                    //       for using as a secondary-unique-id, when we want to insert an
                    //       auto-numbered object and later retrieve it to get the inserted ID.
                    //       I don't know what this is but it's _not_ 'OIN', I tried that.
                    //       (In the end we never used this field.)
                    $this->propertyDefinitions = [
                        'id_property' => 'DbId',
                        'objects' => [
                            'KnPerson' => [
                                'alias' => 'person',
                            ],
                        ],
                        'fields' => [
                            // 'is debtor'?
                            'IsDb' => [
                                'type' => 'boolean',
                                'default' => true,
                            ],
                            // According to AFAS docs, PaCd / VaDu "are required
                            // if IsDb==True" ... no further specs.
                            // [ comment 2014: ]
                            // Heh, VaDu is not even in our inserted XML so
                            // that does not seem to be actually true.
                            'PaCd' => [
                                'default' => '14',
                            ],
                            'CuId' => [
                                'alias' => 'currency_code',
                                'default' => 'EUR',
                            ],
                            'Bl' => [
                                'default' => 'false',
                            ],
                            'AuPa' => [
                                'default' => '0',
                            ],
                            // Verzamelrekening Debiteur -- apparently these
                            // just need to be specified by whoever is setting
                            // up the AFAS administration?
                            'ColA' => [
                                'alias' => 'verzamelreking_debiteur',
                            ],
                            // [ comment 2014: ]
                            // ?? Doesn't seem to be required, but we're still
                            // setting default to the old value we're used to,
                            // until we know what this field means.
                            'VtIn' => [
                                'default' => '1',
                            ],
                            'PfId' => [
                                'default' => '*****',
                            ],
                        ],
                    ];
                    break;

                case 'KnSubject':
                    $this->propertyDefinitions = [
                        'id_property' => 'SbId',
                        // See definition of KnS01: I'm not sure if this is correct.
                        'objects' => [
                            'KnSubjectLink' => [
                                'alias' => 'subject_link',
                            ],
                            'KnS01' => [
                                'alias' => 'subject_link_1',
                            ],
                            'KnS02' => [
                                'alias' => 'subject_link_2',
                            ],
                            // If there are more KnSNN, they have all custom fields?
                        ],
                        'fields' => [
                            // Type dossieritem (verwijzing naar: Type dossieritem => AfasKnSubjectType)
                            'StId' => [
                                'alias' => 'type',
                                'type' => 'integer',
                                'required' => true,
                            ],
                            // Onderwerp
                            'Ds' => [
                                'alias' => 'description',
                            ],
                            // Toelichting
                            'SbTx' => [
                                'alias' => 'comment',
                            ],
                            // Instuurdatum
                            'Da' => [
                                'alias' => 'date',
                                'type' => 'date',
                            ],
                            // Verantwoordelijke (verwijzing naar: Medewerker => AfasKnEmployee)
                            'EmId' => [
                                'alias' => 'responsible',
                            ],
                            // Aanleiding (verwijzing naar: Dossieritem => AfasKnSubject)
                            'SbHi' => [
                                'type' => 'integer',
                            ],
                            // Type actie (verwijzing naar: Type actie => AfasKnSubjectActionType)
                            'SaId' => [
                                'alias' => 'action_type',
                            ],
                            // Prioriteit (verwijzing naar: Tabelwaarde,Prioriteit actie => AfasKnCodeTableValue)
                            'ViPr' => [],
                            // Bron (verwijzing naar: Brongegevens => AfasKnSourceData)
                            'ScId' => [
                                'alias' => 'source',
                            ],
                            // Begindatum
                            'DtFr' => [
                                'alias' => 'start_date',
                                'type' => 'date',
                            ],
                            // Einddatum
                            'DtTo' => [
                                'alias' => 'end_date',
                                'type' => 'date',
                            ],
                            // Afgehandeld
                            'St' => [
                                'alias' => 'done',
                                'type' => 'boolean',
                            ],
                            // Datum afgehandeld
                            'DtSt' => [
                                'alias' => 'done_date',
                                'type' => 'date',
                            ],
                            // Waarde kenmerk 1 (verwijzing naar: Waarde kenmerk => AfasKnFeatureValue)
                            'FvF1' => [
                                'type' => 'integer',
                            ],
                            // Waarde kenmerk 2 (verwijzing naar: Waarde kenmerk => AfasKnFeatureValue)
                            'FvF2' => [
                                'type' => 'integer',
                            ],
                            // Waarde kenmerk 3 (verwijzing naar: Waarde kenmerk => AfasKnFeatureValue)
                            'FvF3' => [
                                'type' => 'integer',
                            ],
                            // Geblokkeerd
                            'SbBl' => [
                                'alias' => 'blocked',
                                'type' => 'boolean',
                            ],
                            // Bijlage
                            'SbPa' => [
                                'alias' => 'attachment',
                            ],
                            // Save file with subject
                            'FileTrans' => [
                                'type' => 'boolean',
                            ],
                            // File as byte-array
                            'FileStream' => [],
                        ],
                    ];
                    break;

                case 'KnSubjectLink':
                    $this->propertyDefinitions = [
                        'id_property' => 'SbId',
                        'fields' => [
                            // Save in CRM Subject
                            'DoCRM' => [
                                'type' => 'boolean',
                            ],
                            // Organisatie/persoon
                            'ToBC' => [
                                'alias' => 'is_org_person',
                                'type' => 'boolean',
                            ],
                            // Medewerker
                            'ToEm' => [
                                'alias' => 'is_employee',
                                'type' => 'boolean',
                            ],
                            // Verkooprelatie
                            'ToSR' => [
                                'alias' => 'is_sales_relation',
                                'type' => 'boolean',
                            ],
                            // Inkooprelatie
                            'ToPR' => [
                                'alias' => 'is_purchase_relation',
                                'type' => 'boolean',
                            ],
                            // Cliënt IB
                            'ToCl' => [
                                'alias' => 'is_client_ib',
                                'type' => 'boolean',
                            ],
                            // Cliënt Vpb
                            'ToCV' => [
                                'alias' => 'is_client_vpb',
                                'type' => 'boolean',
                            ],
                            // Werkgever
                            'ToEr' => [
                                'alias' => 'is_employer',
                                'type' => 'boolean',
                            ],
                            // Sollicitant
                            'ToAp' => [
                                'alias' => 'is_applicant',
                                'type' => 'boolean',
                            ],
                            // Type bestemming
                            // Values:  1:Geen   2:Medewerker   3:Organisatie/persoon   4:Verkooprelatie   8:Cliënt IB   9:Cliënt Vpb   10:Werkgever   11:Inkooprelatie   17:Sollicitant   30:Campagne   31:Item   32:Cursusevenement-->
                            'SfTp' => [
                                'alias' => 'destination_type',
                                'type' => 'integer',
                            ],
                            // Bestemming
                            'SfId' => [
                                'alias' => 'destination_id',
                            ],
                            // Organisatie/persoon (verwijzing naar: Organisatie/persoon => AfasKnBasicContact)
                            'BcId' => [
                                'alias' => 'org_person',
                            ],
                            // Contact (verwijzing naar: Contact => AfasKnContactData)
                            'CdId' => [
                                'alias' => 'contact',
                                'type' => 'integer',
                            ],
                            // Administratie (Verkoop) (verwijzing naar: Administratie => AfasKnUnit)
                            'SiUn' => [
                                'type' => 'integer',
                            ],
                            // Factuurtype (verkoop) (verwijzing naar: Type factuur => AfasFiInvoiceType)
                            'SiTp' => [
                                'alias' => 'sales_invoice_type',
                                'type' => 'integer',
                            ],
                            // Verkoopfactuur (verwijzing naar: Factuur => AfasFiInvoice)
                            'SiId' => [
                                'alias' => 'sales_invoice',
                            ],
                            // Administratie (Inkoop) (verwijzing naar: Administratie => AfasKnUnit)
                            'PiUn' => [
                                'type' => 'integer',
                            ],
                            // Factuurtype (inkoop) (verwijzing naar: Type factuur => AfasFiInvoiceType)
                            'PiTp' => [
                                'alias' => 'purchase_invoice_type',
                                'type' => 'integer',
                            ],
                            // Inkoopfactuur (verwijzing naar: Factuur => AfasFiInvoice)
                            'PiId' => [
                                'alias' => 'purchase_invoice',
                            ],
                            // Fiscaal jaar (verwijzing naar: Aangiftejaren => AfasTxDeclarationYear)
                            'FiYe' => [
                                'alias' => 'fiscal_year',
                                'type' => 'integer',
                            ],
                            // Project (verwijzing naar: Project => AfasPtProject)
                            'PjId' => [
                                'alias' => 'project',
                            ],
                            // Campagne (verwijzing naar: Campagne => AfasCmCampaign)
                            'CaId' => [
                                'alias' => 'campaign',
                                'type' => 'integer',
                            ],
                            // Actief (verwijzing naar: Vaste activa => AfasFaFixedAssets)
                            'FaSn' => [
                                'type' => 'integer',
                            ],
                            // Voorcalculatie (verwijzing naar: Voorcalculatie => AfasKnQuotation)
                            'QuId' => [],
                            // Dossieritem (verwijzing naar: Dossieritem => AfasKnSubject)
                            'SjId' => [
                                'type' => 'integer',
                            ],
                            // Abonnement (verwijzing naar: Abonnement => AfasFbSubscription
                            'SuNr' => [
                                'alias' => 'subscription',
                                'type' => 'integer',
                            ],
                            // Dienstverband
                            'DvSn' => [
                                'type' => 'integer',
                            ],
                            // Type item (verwijzing naar: Tabelwaarde,Itemtype => AfasKnCodeTableValue)
                            // Values:  Wst:Werksoort   Pid:Productie-indicator   Deg:Deeg   Dim:Artikeldimensietotaal   Art:Artikel   Txt:Tekst   Sub:Subtotaal   Tsl:Toeslag   Kst:Kosten   Sam:Samenstelling   Crs:Cursus-->
                            'VaIt' => [
                                'alias' => 'item_type',
                            ],
                            // Itemcode (verwijzing naar: Item => AfasFbBasicItems)
                            'BiId' => [
                                'alias' => 'item_code',
                            ],
                            // Cursusevenement (verwijzing naar: Evenement => AfasKnCourseEvent)
                            'CrId' => [
                                'alias' => 'course_event',
                                'type' => 'integer',
                            ],
                            // Verzuimmelding (verwijzing naar: Verzuimmelding => AfasHrAbsIllnessMut)
                            'AbId' => [
                                'type' => 'integer',
                            ],
                            // Forecast (verwijzing naar: Forecast => AfasCmForecast)
                            'FoSn' => [
                                'type' => 'integer',
                            ],
                        ],
                    ];
                    break;

                // I do not know if the following is correct: back in 2014, the
                // XSD schema / Data Connector contained separate explicit
                // definitions for KnS01 and KnS02, which suggested they are
                // separate object types with defined fields, even though their
                // fields all start with 'U'. I can imagine that the XSD
                // contained just examples and actually it is up to the AFAS
                // environment to define these. In that case, the following
                // definitions should be removed from here and KnS01 should be
                // implemented (and the corresponding 'object reference fields'
                // in KnSubject should be overridden) in custom classes.
                case 'KnS01':
                    $this->propertyDefinitions = [
                        'id_property' => 'SbId',
                        'fields' => [
                            // Vervaldatum
                            'U001' => [
                                'alias' => 'end_date',
                                'type' => 'date',
                            ],
                            // Identiteitsnummer
                            'U002' => [
                                'alias' => 'id_number',
                            ],
                        ],
                    ];
                    break;

                case 'KnS02':
                    $this->propertyDefinitions = [
                        'id_property' => 'SbId',
                        'fields' => [
                            // Contractnummer
                            'U001' => [
                                'alias' => 'contract_number',
                            ],
                            // Begindatum contract
                            'U002' => [
                                'alias' => 'start_date',
                                'type' => 'date',
                            ],
                            // Einddatum contract
                            'U003' => [
                                'alias' => 'start_date',
                                'type' => 'date',
                            ],
                            // Waarde
                            'U004' => [
                                'alias' => 'value',
                                'type' => 'decimal',
                            ],
                            // Beëindigd
                            'U005' => [
                                'alias' => 'ended',
                                'type' => 'boolean',
                            ],
                            // Stilzwijgend verlengen
                            'U006' => [
                                'alias' => 'recurring',
                                'type' => 'boolean',
                            ],
                            // Opzegtermijn (verwijzing naar: Tabelwaarde,(Afwijkende) opzegtermijn => AfasKnCodeTableValue)
                            'U007' => [
                                'alias' => 'cancel_term',
                            ],
                        ],
                    ];
                    break;

                case 'FbSalesLines':
                    $this->propertyDefinitions = [
                        'objects' => [
                            'FbOrderBatchLines' => [
                                'alias' => 'batch_line_items',
                                'multiple' => true,
                            ],
                            'FbOrderSerialLines' => [
                                'alias' => 'serial_line_items',
                                'multiple' => true,
                            ],
                        ],
                        'fields' => [
                            // Type item (verwijzing naar: Tabelwaarde,Itemtype => AfasKnCodeTableValue)
                            // Values:  1:Werksoort   10:Productie-indicator   11:Deeg   14:Artikeldimensietotaal   2:Artikel   3:Tekst   4:Subtotaal   5:Toeslag   6:Kosten   7:Samenstelling   8:Cursus
                            'VaIt' => [
                                'alias' => 'item_type',
                            ],
                            // Itemcode
                            'ItCd' => [
                                'alias' => 'item_code',
                            ],
                            // Omschrijving
                            'Ds' => [
                                'alias' => 'description',
                            ],
                            // Btw-tariefgroep (verwijzing naar: Btw-tariefgroep => AfasKnVatTarifGroup)
                            'VaRc' => [
                                'alias' => 'vat_type',
                            ],
                            // Eenheid (verwijzing naar: Eenheid => AfasFbUnit)
                            'BiUn' => [
                                'alias' => 'unit_type',
                            ],
                            // Aantal eenheden
                            'QuUn' => [
                                'alias' => 'quantity',
                                'type' => 'decimal',
                            ],
                            // Lengte
                            'QuLe' => [
                                'alias' => 'length',
                                'type' => 'decimal',
                            ],
                            // Breedte
                            'QuWi' => [
                                'alias' => 'width',
                                'type' => 'decimal',
                            ],
                            // Hoogte
                            'QuHe' => [
                                'alias' => 'height',
                                'type' => 'decimal',
                            ],
                            // Aantal besteld
                            'Qu' => [
                                'alias' => 'quantity_ordered',
                                'type' => 'decimal',
                            ],
                            // Aantal te leveren
                            'QuDl' => [
                                'alias' => 'quantity_deliver',
                                'type' => 'decimal',
                            ],
                            // Prijslijst (verwijzing naar: Prijslijst verkoop => AfasFbPriceListSale)
                            'PrLi' => [
                                'alias' => 'price_list',
                            ],
                            // Magazijn (verwijzing naar: Magazijn => AfasFbWarehouse)
                            'War' => [
                                'alias' => 'warehouse',
                            ],
                            // Dienstenberekening
                            'EUSe' => [
                                'type' => 'boolean',
                            ],
                            // Gewichtseenheid (verwijzing naar: Tabelwaarde,Gewichtseenheid => AfasKnCodeTableValue)
                            // Values:  0:Geen gewicht   1:Microgram (Âµg)   2:Milligram (mg)   3:Gram (g)   4:Kilogram (kg)   5:Ton
                            'VaWt' => [
                                'alias' => 'weight_unit',
                            ],
                            // Nettogewicht
                            'NeWe' => [
                                'alias' => 'weight_net',
                                'type' => 'decimal',
                            ],
                            //
                            'GrWe' => [
                                'alias' => 'weight_gross',
                                'type' => 'decimal',
                            ],
                            // Prijs per eenheid
                            'Upri' => [
                                'alias' => 'unit_price',
                                'type' => 'decimal',
                            ],
                            // Kostprijs
                            'CoPr' => [
                                'alias' => 'cost_price',
                                'type' => 'decimal',
                            ],
                            // Korting toestaan (verwijzing naar: Tabelwaarde,Toestaan korting => AfasKnCodeTableValue)
                            // Values:  0:Factuur- en regelkorting   1:Factuurkorting   2:Regelkorting   3:Geen factuur- en regelkorting
                            'VaAD' => [],
                            // % Regelkorting
                            'PRDc' => [
                                'type' => 'decimal',
                            ],
                            // Bedrag regelkorting
                            'ARDc' => [
                                'type' => 'decimal',
                            ],
                            // Handmatig bedrag regelkorting
                            'MaAD' => [
                                'type' => 'boolean',
                            ],
                            // Opmerking
                            'Re' => [
                                'alias' => 'comment',
                            ],
                            // GUID regel
                            'GuLi' => [
                                'alias' => 'guid',
                            ],
                            // Artikeldimensiecode 1 (verwijzing naar: Artikeldimensiecodes => AfasFbStockDimLines)
                            'StL1' => [
                                'alias' => 'dimension_1',
                            ],
                            // Artikeldimensiecode 2 (verwijzing naar: Artikeldimensiecodes => AfasFbStockDimLines)
                            'StL2' => [
                                'alias' => 'dimension_2',
                            ],
                            // Direct leveren vanuit leverancier
                            'DiDe' => [
                                'alias' => 'direct_delivery',
                                'type' => 'boolean',
                            ],
                        ],
                    ];
                    break;

                case 'FbOrderBatchLines':
                    $this->propertyDefinitions = [
                        'fields' => [
                            // Partijnummer
                            'BaNu' => [
                                'alias' => 'batch_number',
                            ],
                            // Eenheid (verwijzing naar: Eenheid => AfasFbUnit)
                            'BiUn' => [
                                'alias' => 'unit_type',
                            ],
                            // Aantal eenheden
                            'QuUn' => [
                                'alias' => 'quantity_units',
                                'type' => 'decimal',
                            ],
                            // Aantal
                            'Qu' => [
                                'alias' => 'quantity',
                                'type' => 'decimal',
                            ],
                            // Factuuraantal
                            'QuIn' => [
                                'alias' => 'quantity_invoice',
                                'type' => 'decimal',
                            ],
                            // Opmerking
                            'Re' => [
                                'alias' => 'comment',
                            ],
                            // Lengte
                            'QuLe' => [
                                'alias' => 'length',
                                'type' => 'decimal',
                            ],
                            // Breedte
                            'QuWi' => [
                                'alias' => 'width',
                                'type' => 'decimal',
                            ],
                            // Hoogte
                            'QuHe' => [
                                'alias' => 'height',
                                'type' => 'decimal',
                            ],
                        ],
                    ];
                    break;

                case 'FbOrderSerialLines':
                    $this->propertyDefinitions = [
                        'fields' => [
                            // Serienummer
                            'SeNu' => [
                                'alias' => 'serial_number',
                            ],
                            // Eenheid (verwijzing naar: Eenheid => AfasFbUnit)
                            'BiUn' => [
                                'alias' => 'unit_type',
                            ],
                            // Aantal eenheden
                            'QuUn' => [
                                'alias' => 'quantity_units',
                                'type' => 'decimal',
                            ],
                            // Aantal
                            'Qu' => [
                                'alias' => 'quantity',
                                'type' => 'decimal',
                            ],
                            // Factuuraantal
                            'QuIn' => [
                                'alias' => 'quantity_invoice',
                                'type' => 'decimal',
                            ],
                            // Opmerking
                            'Re' => [
                                'alias' => 'comment',
                            ],
                        ],
                    ];
                    break;

                default:
                    throw new InvalidArgumentException("No property definitions found for '$type' object.");
            }
        }
        $this->type = $type;
        if (!is_string($parent_type)) {
            throw new InvalidArgumentException('$parent_type argument is not a string.');
        }
        $this->parentType = $parent_type;
        $this->setAction($action);
        $this->addElements($elements, $validation_behavior);
    }

    /**
     * Magic method called on a newly cloned object.
     *
     * Clones each embedded object, so that changing properties in objects
     * inside an UpdateObject won't affect the cloned UpdateObject.
     */
    public function __clone()
    {
        // Lots of doublechecks on data types; silently skip if these fail.
        if (!empty($this->elements) && is_array($this->elements)) {
            foreach ($this->elements as $element_index => $element) {
                if (!empty($element['Objects']) && is_array($element['Objects'])) {
                    foreach ($element['Objects'] as $ref_field => $object) {
                        $this->elements[$element_index]['Objects'][$ref_field] = clone $object;
                    }
                }
            }
        }
    }

    /**
     * Returns the object type.
     *
     * @return string
     */
    public function getType()
    {
        return $this->type;
    }

    /**
     * Returns the action which should be performed on one or all elements.
     *
     * @param int|string $element_index
     *   (Optional) The index of the element whose action is requested. Often
     *   this class will contain data for only one element and this argument
     *   does not need to be specified (or should be 0).
     *
     * @return string
     *
     * @throws \OutOfBoundsException
     *   If the index value does not exist.
     * @throws \UnexpectedValueException
     *   If different actions exist and the caller didn't request an index.
     */
    public function getAction($element_index = null)
    {
        if (empty($this->actions)) {
            $action = '';
        } elseif (isset($element_index) && isset($this->actions[$element_index])) {
            $action = $this->actions[$element_index];
        } else {
            // Throw an exception when requesting an action for a nonexistent
            // element. (Unless the action with this index was set anyway,
            // which is a valid use case, but that was covered above.)
            if (isset($element_index) && !isset($this->elements[$element_index])) {
                throw new OutOfBoundsException("No action or element defined for index $element_index.");
            }
            // At least one action was set but we're not requesting a specific
            // action, which is fine if all actions which are set, are the same.
            $unique = array_unique($this->actions);
            if (count($unique) > 1) {
                throw new UnexpectedValueException("Multiple different action values are set, so getAction() has to be called with a valid index parameter.");
            }
            $action = $unique[0];
        }

        return $action;
    }

    /**
     * Returns the actions that were set in this class.
     *
     * This is not known to be of any practical use to outside callers; it's
     * probably easier to call getAction() without argument and get a single
     * string returned, because in the vast majority of cases callers will set
     * only one element in an UpdateObject, or set several elements with the
     * same action. Still, it's possible for who knows which use case...
     *
     * @return string[]
     *   The array of all action values. Usually this will be an array of one
     *   value that was set through create().
     */
    public function getActions()
    {
        return $this->actions;
    }

    /**
     * Sets the action to perform on element data.
     *
     * 'Actions' are a bit of an oddity: the only value that is known to have
     * an effect (and is often required to generate proper output) is "insert",
     * if this element's output should be used for inserting new data into AFAS.
     * This value can also be passed in create(), so calling setAction()
     * separately should not often be necessary.
     *
     * It's still implemented as a string with other possible values, in order
     * to be able to output all known forms of XML for sending to the SOAP
     * API. It's possible that we discover a situation later, where embedding
     * a specific action value in the XML is a necessity.
     *
     * For now, just remember: set "insert" when inserting new data (which will
     * automatically take care of setting necessary default values), otherwise
     * set "update" to be future proof. ("delete" has not been tested so we
     * can't comment on that yet.) This is only to assure future compatibility:
     * "update" does not have any effect on the REST API currently; it will
     * change the XML used for the SOAP API slightly but its only known effect
     * is when updating elements embedded inside other elements. (It prevents
     * AFAS internal errors, somehow.)
     *
     * @param string $action
     *   The action to perform on the data: "insert", "update" or "delete". ""
     *   is also accepted as a valid value, though it has no known use.
     * @param bool $set_embedded
     *   (Optional) If false, then if this/these element(s) contain(s) any
     *   embedded objects, do not modify those; only set the current object. By
     *   default, the action is also set/overwritten in any embedded objects.
     *   (This argument has no effect on elements added later; if e.g. a later
     *   addElements() contains embedded objects, those will always inherit the
     *   action set on the parent element.)
     * @param int|string $element_index
     *   (Optional) The index of the element for which to set the action. It's
     *   usually not needed even when the UpdateObject holds data for multiple
     *   elements. It's only of theoretical use (which is: outputting multiple
     *   objects with different "action" values as XML. JSON output is likely
     *   bogus when different action values are set for different elements).
     *
     * @throws \InvalidArgumentException
     *   If the action value is unknown.
     */
    public function setAction($action, $set_embedded = true, $element_index = null)
    {
        // Unify $action. We'll silently accept PUT/POST too, as long as the
        // REST API keeps the one-to-one relationship of these to update/insert.
        $actions = ['put' => 'update', 'post' => 'insert', 'delete' => 'delete'];
        if ($action && is_string($action)) {
            $action = strtolower($action);
            if (isset($actions[$action])) {
                $action = $actions[$action];
            }
        }
        if (!is_string($action) || ($action && !in_array($action, $actions, true))) {
            throw new InvalidArgumentException('Unknown action value' . var_export($action, true) . '.');
        }

        if (isset($element_index)) {
            $this->actions[$element_index] = $action;
        } else {
            // Set the value in any defined actions. (Usually the actions
            // variable contains only one entry with index 0, regardless of
            // how many elements are present in this UpdateObject, which is
            // fine.)
            if (empty($this->actions) || !is_array($this->actions)) {
                $this->actions = [$action];
            } else {
                foreach ($this->actions as $index => $a) {
                    $this->actions[$index] = $action;
                }
            }
        }

        if ($set_embedded && !empty($this->elements) && is_array($this->elements)) {
            // Change $element_index if it's necessary for key comparisons.
            if (is_float($element_index) || is_bool($element_index) ||
                is_string($element_index) && (string) (int) $element_index === $element_index) {
                $element_index = (int) $element_index;
            }
            // Set all actions in embedded objects of the element corresponding
            // to the index we passed into this method.
            foreach ($this->elements as $i => $element) {
                if (!isset($element_index) || $i === $element_index) {

                    if (!empty($element['Objects']) && is_array($element['Objects'])) {
                        foreach ($element['Objects'] as $object) {
                            if ($object instanceof UpdateObject) {
                                $object->setAction($action, true);
                            }
                        }
                    }
                }
            }
        }
    }

    /**
     * Returns the ID value of one of this object's elements.
     *
     * @param int|string $element_index
     *   (Optional) The index of the element whose ID is requested.
     *
     * @return int|string|null
     *   The ID value, or null if no value is set.
     *
     * @throws \OutOfBoundsException
     *   If this object type has no 'id_property' definition, or no element
     *   corresponding to the index exists.
     */
    public function getId($element_index = 0)
    {
        $element = $this->checkElement($element_index);
        if (empty($this->propertyDefinitions['id_property'])) {
            throw new OutOfBoundsException("'{$this->getType()}' object has no 'id_property' definition.");
        }
        if (!is_string($this->propertyDefinitions['id_property'])) {
            throw new UnexpectedValueException("'id_property' definition in '{$this->getType()}' object is not a string value.");
        }

        $id_property = '@' . $this->propertyDefinitions['id_property'];
        return isset($element[$id_property]) ? $element[$id_property] : null;
    }

    /**
     * Sets the ID value in one of this object's elements.
     *
     * @param int|string $value
     *   The ID value to set. Empty string means no value.
     * @param int|string $element_index
     *   (Optional) The index of the element whose ID is set. If it does not
     *   exist yet, a new element is created with just this ID.
     *
     * @throws \InvalidArgumentException
     *   If the value has an unexpected type.
     * @throws \OutOfBoundsException
     *   If no element corresponding to the index exists and the index is
     *   higher than the number of existing elements.
     * @throws \UnexpectedValueException
     *   If this object type has no 'id_property' definition.
     */
    public function setId($value, $element_index = 0)
    {
        if (!is_int($value) && !is_string($value)) {
            throw new InvalidArgumentException("Value must be integer or string.");
        }
        if (empty($this->propertyDefinitions['id_property'])) {
            throw new UnexpectedValueException("'{$this->getType()}' object has no 'id_property' definition.");
        }
        if (!is_string($this->propertyDefinitions['id_property'])) {
            throw new UnexpectedValueException("'id_property' definition in '{$this->getType()}' object is not a string value.");
        }

        $this->elements[$element_index]['@' . $this->propertyDefinitions['id_property']] = $value;
    }

    /**
     * Returns the value of a field as stored in one of this object's elements.
     *
     * @param string $field_name
     *   The name of the field, or its alias.
     * @param int|string $element_index
     *   (Optional) The index of the element whose field value is requested.
     *   The element must exist, except if $return_default is passed.
     * @param bool $return_default
     *   (Optional) If true, return the default value if no field value is set.
     * @param bool $wrap_array
     *   (Optional) If true, wrap the return value in an array, or return an
     *   empty array if the field has no value. This is the only way to
     *   differentiate between 'no value' and 'a value of null'.
     *
     * @return mixed
     *   The value (usually a string/numeric/boolean value), possibly wrapped
     *   in an array.
     *
     * @throws \OutOfBoundsException
     *   If the field name/alias does not exist in this object type's "fields"
     *   definition, or no element corresponding to the index exists.
     */
    public function getField($field_name, $element_index = 0, $return_default = false, $wrap_array = false)
    {
        // Get element, or empty array if we want to return default.
        $element = $this->checkElement($element_index, $return_default);
        $field_name = $this->checkFieldName($field_name);

        // Check for element value or default value. Both can be null. If the
        // element value is set to null explicitly, we do not replace it with
        // the default.
        if (array_key_exists($field_name, $element['Fields'])) {
            $return = $wrap_array ? [$element['Fields'][$field_name]] : $element['Fields'][$field_name];
        } elseif ($return_default && array_key_exists('default', $this->propertyDefinitions['fields'][$field_name])) {
            $return = $wrap_array ? [$this->propertyDefinitions['fields'][$field_name]['default']] : $this->propertyDefinitions['fields'][$field_name]['default'];
        } else {
            $return = $wrap_array ? [] : null;
        }

        return $return;
    }

    /**
     * Sets the value of a field in one of this object's elements.
     *
     * @param string $field_name
     *   The name of the field, or its alias.
     * @param int|string $value
     *   The field value to set.
     * @param int|string $element_index
     *   (Optional) The index of the element whose ID is set. If it does not
     *   exist yet, a new element is created with just this field.
     * @param int $validation_behavior
     *   (Optional) Specifies whether/how the elements should be validated,
     *   throwing an exception on failure. By default only very basic
     *   validation on individual fields (e.g. for correct data types) is done
     *   here and full validation happens during output(). This value is a
     *   bitmask; the relevant bits for validating a single field are
     *   VALIDATE_ESSENTIAL and VALIDATE_FORMAT; most other bits have to do
     *   with validation of the object as a whole and are always ignored here.
     *   See output() for more.
     *
     * @throws \InvalidArgumentException
     *   If the value has an unexpected type.
     * @throws \OutOfBoundsException
     *   If the field name/alias does not exist in this object type's "fields"
     *   definition, or if no element corresponding to the index exists and the
     *   index is  higher than the number of existing elements.
     *
     * @see output()
     */
    public function setField($field_name, $value, $element_index = 0, $validation_behavior = self::VALIDATE_ESSENTIAL)
    {
        $element = $this->checkElement($element_index, true);
        $field_name = $this->checkFieldName($field_name);

        // validateFieldValue() gets definitions too but caching it here would
        // add too much fussy code.
        $this->elements[$element_index]['Fields'][$field_name] = $this->validateFieldValue($value, $field_name, self::ALLOW_NO_CHANGES, $validation_behavior, $element_index, $element);
    }

    /**
     * Removes a field value from one of this object's elements.
     *
     * @param string $field_name
     *   The name of the field, or its alias.
     * @param int|string $element_index
     *   (Optional) The index of the element.
     *
     * @return bool
     *   True if the field is set before calling this function (also if its
     *   value is null); false otherwise.
     *
     * @throws \OutOfBoundsException
     *   If the field name/alias does not exist in this object type's "fields"
     *   definition, or if no element corresponding to the index exists.
     */
    public function unsetField($field_name, $element_index = 0)
    {
        $field_name = $this->checkFieldName($field_name);

        $return = false;
        if (!empty($this->elements[$element_index]['Fields']) && array_key_exists($field_name, $this->elements[$element_index]['Fields'])) {
            $return = true;
            unset($this->elements[$element_index]['Fields'][$field_name]);
            // Remove an empty internal 'Fields' array, so output does not
            // include it.
            if (empty($this->elements[$element_index]['Fields'])) {
                unset($this->elements[$element_index]['Fields']);
            }
        }

        return $return;
    }

    /**
     * Returns an object embedded in one of this object's elements.
     *
     * @param string $reference_field_name
     *   The name of the reference field for the object, or its alias. (This is
     *   often but not always equal to the object type; see the docs at
     *   $propertyDefinitions.)
     * @param int|string $element_index
     *   (Optional) The index of the element whose embedded object value is
     *   requested. The element must exist except if $return_default is passed.
     * @param bool $return_default
     *   (Optional) If true, return an object with a default value, if no
     *   embedded object is set. In this case, changes made to the returned
     *   default object do not affect the parent element stored in this object.
     *
     * @return \PracticalAfas\UpdateConnector\UpdateObject
     *   The requested object. Note this has an immutable 'parentType' property
     *   which might change its behavior, thereby making it unsuitable to be
     *   used in isolation.
     *
     * @throws \OutOfBoundsException
     *   If the reference field name/alias does not exist in this object type's
     *   "objects" definition, or no element corresponding to the index exists.
     * @throws \UnexpectedValueException
     *   If something's wrong with the default value.
     */
    public function getObject($reference_field_name, $element_index = 0, $return_default = false)
    {
        $element = $this->checkElement($element_index, $return_default);
        $reference_field_name = $this->checkObjectReferenceFieldName($reference_field_name);

        // Check for element value or default value.
        if (isset($element['Objects'][$reference_field_name])) {
            $return = $element['Objects'][$reference_field_name];
        } elseif ($return_default && isset($this->propertyDefinitions['objects'][$reference_field_name]['default'])) {
            $return = $this->propertyDefinitions['objects'][$reference_field_name]['default'];
            if (is_array($return)) {
                // The object type is often equal to the name of the 'reference
                // field' in the parent element, but not always; there's a
                // property to specify it. The intended 'action' value is
                // always assumed to be equal to its parent's current value.
                $type = !empty($this->propertyDefinitions['objects'][$reference_field_name]['type'])
                    ? $this->propertyDefinitions['objects'][$reference_field_name]['type'] : $reference_field_name;
                try {
                    $return = static::create($type, $return, $this->getAction($element_index), self::VALIDATE_ESSENTIAL, $this->getType());
                } catch (InvalidArgumentException $e) {
                    // 'Unify' exception to an UnexpectedValueException.
                    throw new UnexpectedValueException($e->getMessage(), $e->getCode());
                }
            } else {
                $element_descr = "'{$this->getType()}' element" . ($element_index ? " with index $element_index" : '');
                throw new UnexpectedValueException("Default value for '$reference_field_name' object embedded in $element_descr must be array.");
            }
        } else {
            $return = null;
        }

        return $return;
    }

    /**
     * Creates an embedded object in one of this object's elements.
     *
     * Note this is not an exact counterpart to getObject(); that returns an
     * object (for further manipulation) while this accepts parts to create an
     * object out of. If O is an object retrieved by getObject(), setting O's
     * value back would be achieved by passing O->getElements() and
     * O->getAction() as second and third argument.
     *
     * @param string $reference_field_name
     *   The name of the reference field for the object, or its alias. (This is
     *   often but not always equal to the object type; see the docs at
     *   $propertyDefinitions.)
     * @param array $embedded_elements
     *   Data to set in the embedded object, representing one or more elements.
     *   See create().
     * @param string $action
     *   (Optional) The action to perform on the data. By default, the action
     *   set in the parent element (stored in this object) is taken.
     * @param int|string $element_index
     *   (Optional) The index of the element whose ID is set. If it does not
     *   exist yet, a new element is created with just this embedded object.
     * @param int $validation_behavior
     *   (Optional) Specifies whether/how the elements should be validated; see
     *   create() for a more elaborate description.
     *
     * @throws \InvalidArgumentException
     *   If the element structure is invalid or the action value cannot be null.
     * @throws \OutOfBoundsException
     *   If the field name/alias does not exist in this object type's "fields"
     *   definition, or if no element corresponding to the index exists and the
     *   index is higher than the number of existing elements.
     *
     * @see create()
     * @see setAction()
     */
    public function setObject($reference_field_name, array $embedded_elements, $action = null, $element_index = 0, $validation_behavior = self::VALIDATE_ESSENTIAL)
    {
        $reference_field_name = $this->checkObjectReferenceFieldName($reference_field_name);

        $this->elements[$element_index]['Objects'][$reference_field_name] = $this->createEmbeddedObject($reference_field_name, $embedded_elements, $action, $element_index, $validation_behavior);
    }

    /**
     * Removes an embedded object from one of this object's elements.
     *
     * @param string $reference_field_name
     *   The name of the reference field for the object, or its alias. (This is
     *   often but not always equal to the object type; see the docs at
     *   $propertyDefinitions.)
     * @param int|string $element_index
     *   (Optional) The index of the element.
     *
     * @return bool
     *   True if on object is set before calling this function; false otherwise.
     *
     * @throws \OutOfBoundsException
     *   If the field name/alias does not exist in this object type's "fields"
     *   definition, or if no element corresponding to the index exists.
     */
    public function unsetObject($reference_field_name, $element_index = 0)
    {
        $reference_field_name = $this->checkObjectReferenceFieldName($reference_field_name);

        $return = false;
        if (!empty($this->elements[$element_index]['Objects']) && array_key_exists($reference_field_name, $this->elements[$element_index]['Objects'])) {
            $return = true;
            unset($this->elements[$element_index]['Objects'][$reference_field_name]);
            // Remove an empty internal 'Objects' array, so output does not
            // include it.
            if (empty($this->elements[$element_index]['Objects'])) {
                unset($this->elements[$element_index]['Objects']);
            }
        }

        return $return;
    }

    /**
     * Creates an object ready for embedding in one of this object's elements.
     *
     * This does some sanity checks first.
     *
     * @param string $reference_field_name
     *   Reference field name (not alias).
     * @param array $embedded_elements
     *   Data to set in the embedded object, representing one or more elements.
     *   See create().
     * @param string $action
     *   (Optional) The action to perform on the data. By default, the action
     *   set in the parent element (stored in this object) is taken.
     * @param int|string $element_index
     *   The index that the element which the object will be embedded into, has
     *   or will have. When called from add/setElement(s), the element with the
     *   specified index does/may not exist yet.
     * @param int $validation_behavior
     *   (Optional) Specifies whether/how the elements should be validated; see
     *   create() for a more elaborate description.
     *
     * @throws \InvalidArgumentException
     *   If the elements cannot be embedded or the action value cannot be null.
     * @throws \OutOfBoundsException
     *   If the field name/alias does not exist in this object type's "fields"
     *   definition, or if no element corresponding to the index exists and the
     *   index is higher than the number of existing elements.
     *
     * @return static
     *
     * @see setObject()
     */
    protected function createEmbeddedObject($reference_field_name, array $embedded_elements, $action = null, $element_index = 0, $validation_behavior = self::VALIDATE_ESSENTIAL)
    {
        // Strip "Element" wrapper if it's there, and also convert to array
        // of N elements. (The latter is not necessary for calling create() but
        // it is necessary for the 'multiple' check.)
        $embedded_elements = $this->normalizeElements($embedded_elements);

        // Check if we are allowed to add multiple embedded objects into this
        // reference field, and if not, whether we are doing that. On 'output'
        // this is a mandatory check, but on 'input' it can be suppressed.
        if ($validation_behavior & self::VALIDATE_ESSENTIAL && count($embedded_elements) > 1
            && empty($this->propertyDefinitions['objects'][$reference_field_name]['multiple'])) {
            $element_descr = "'{$this->getType()}' element" . ($element_index ? " which has (or will get) index $element_index " : '');
            $name_and_alias = "'$reference_field_name'" . (isset($this->propertyDefinitions['objects'][$reference_field_name]['alias']) ? " ({$this->propertyDefinitions['objects'][$reference_field_name]['alias']})" : '');
            throw new InvalidArgumentException("$name_and_alias object embedded in $element_descr contains " . count($embedded_elements) . ' elements but can only contain a single element.');
        }

        if (!isset($action)) {
            // Take the action associated with the parent element, if that is
            // set. If neither the parent element nor its action exists yet,
            // this can throw an OutOfBoundsException, in which case we should
            // get the default action. Both getAction() calls can also throw an
            // UnexpectedValueException if (no action associated with the
            // specific element is set and) our UpdateObject has elements with
            // multiple different actions. This can only be resolved by calling
            // setAction(,,$element_index) before calling this method. That's
            // such an edge case that it isn't documented elsewhere.
            try {
                $action = $this->getAction($element_index);
            } catch (OutOfBoundsException $e) {
                $action = $this->getAction();
            }
        }
        // The object type is often equal to the name of the 'reference field'
        // but not always; there's a property to specify it.
        $type = !empty($this->propertyDefinitions['objects'][$reference_field_name]['type'])
            ? $this->propertyDefinitions['objects'][$reference_field_name]['type'] : $reference_field_name;
        return static::create($type, $embedded_elements, $action, $validation_behavior, $this->getType());
    }

    /**
     * Helper method: get an element with a certain index.
     *
     * We don't want to make this 'public getElement()' because that creates
     * too much choice / ambiguity; callers should use getElements().
     *
     * @param int|string $element_index
     *   The index of the requested element.
     * @param bool $allow_nonexistent
     *   (Optional) if true, return an empty array if the element with the
     *   specified index does not exixt.
     *
     * @return array
     *   The element.
     */
    protected function checkElement($element_index = 0, $allow_nonexistent = false)
    {
        if (isset($this->elements[$element_index])) {
            $element = $this->elements[$element_index];
        } elseif ($allow_nonexistent) {
            $element = [];
        } else {
            throw new OutOfBoundsException("No element present with index $element_index.");
        }

        return $element;
    }

    /**
     * Helper method: check if a field name exists, as field or alias.
     *
     * @param string $field_name
     *   The name of the field, or its alias.
     *   Value of the element. Only used for checking property definitions,
     *   unless if property definitions are cached; then this is not used.
     *
     * @return string
     *   The field name; either the same as the first argument, or resolved
     *   from the first argument if that is an alias.
     */
    protected function checkFieldName($field_name)
    {
        if (!isset($this->propertyDefinitions['fields'][$field_name])) {
            // Check if we have an alias; resolve to field name.
            foreach ($this->propertyDefinitions['fields'] as $real_field_name => $definition) {
                if (isset($definition['alias']) && $definition['alias'] === $field_name) {
                    $field_name = $real_field_name;
                    break;
                }
            }
            if (!isset($this->propertyDefinitions['fields'][$field_name])) {
                throw new OutOfBoundsException("'{$this->getType()}' object has no definition for field '$field_name'.");
            }
        }

        return $field_name;
    }

    /**
     * Helper method: check if an object reference field name, or alias, exists.
     *
     * @param string $reference_field_name
     *   The name of the object reference field, or its alias.
     *
     * @return string
     *   The field name; either the same as the first argument, or resolved
     *   from the first argument if that is an alias.
     */
    protected function checkObjectReferenceFieldName($reference_field_name)
    {
        if (!isset($this->propertyDefinitions['objects'][$reference_field_name])) {
            // Check if we have an alias; resolve to field name.
            foreach ($this->propertyDefinitions['objects'] as $real_field_name => $definition) {
                if (isset($definition['alias']) && $definition['alias'] === $reference_field_name) {
                    $reference_field_name = $real_field_name;
                    break;
                }
            }
            if (!isset($this->propertyDefinitions['objects'][$reference_field_name])) {
                throw new OutOfBoundsException("'{$this->getType()}' object has no definition for object '$reference_field_name'.");
            }
        }

        return $reference_field_name;
    }

    /**
     * Returns a uniformly structured representation of one or more elements.
     *
     * This is about converting a single element to an array if necessary. It
     * does nothing to the structure of the element itself; that's
     * normalizeElement().
     *
     * @param array $elements
     *   The input data; see create() for details.
     *
     * @return array[]
     *   The data formatted as an array of elements.
     *
     * @throws \InvalidArgumentException
     *   If the data has an invalid structure.
     */
    protected function normalizeElements(array $elements)
    {
        // If the caller passed the element(s) inside an 'Element' wrapper
        // array (because they are handling e.g. some external json-decoded
        // string from another source, or embedded objects inside the
        // getElements() return value), accept that. Strip the wrapper off.
        if (count($elements) == 1 && key($elements) === 'Element') {
            $elements = reset($elements);
            if (!is_array($elements)) {
                throw new InvalidArgumentException("Element data inside 'Element' wrapper is not an array.");
            }
        }
        // Determine if we have a single element or an array of elements.
        foreach ($elements as $key => $element) {
            if (is_scalar($element) || !is_numeric($key)) {
                // This is assumed to be a single element. Normalize to an
                // array of elements..
                $elements = [$elements];
                break;
            }
        }

        return $elements;
    }


    /**
     * Returns a uniformly structured representation of a single element.
     *
     * @param array $element
     *   The input data; see create() for details.
     * @param int|string $element_index
     *   (Optional) The index which the element will get.
     * @param int $validation_behavior
     *   (Optional) Specifies whether/how the elements should be validated; see
     *   create() for a more elaborate description.
     *
     * @return array
     *   The element with uniform structure and field/object names.
     *
     * @throws \InvalidArgumentException
     *   If the data has an invalid structure.
     *
     * @see create()
     */
    protected function normalizeElement(array $element, $element_index, $validation_behavior = self::VALIDATE_ESSENTIAL)
    {
        if (empty($this->propertyDefinitions)) {
            throw new UnexpectedValueException("'{$this->getType()}' object has no property definitions.");
        }
        if (!isset($this->propertyDefinitions['fields']) || !is_array($this->propertyDefinitions['fields'])) {
            throw new UnexpectedValueException("'{$this->getType()}' object has no / a non-array 'fields' property definition.");
        }
        if (isset($this->propertyDefinitions['objects']) && !is_array($this->propertyDefinitions['objects'])) {
            throw new UnexpectedValueException("'{$this->getType()}' object has a non-array 'objects' property definition.");
        }

        $element_descr = "'{$this->getType()}' element" . ($element_index ? " which will get index $element_index" : '');
        $normalized_element = [];

        // If this type has an ID field, check for it and set it in its
        // dedicated location. AFAS has various names for various types' ID
        // property, but we are also allowed to refer to each id by '@id'.
        if (!empty($this->propertyDefinitions['id_property'])) {
            if (!is_string($this->propertyDefinitions['id_property'])) {
                throw new UnexpectedValueException("'id_property' definition in '{$this->getType()}' object is not a string value.");
            }
            $id_property = '@' . $this->propertyDefinitions['id_property'];
            if (array_key_exists($id_property, $element)) {
                if (array_key_exists('@id', $element) && $element['@id'] !== $element[$id_property]) {
                    throw new InvalidArgumentException("{$this->getType()} object has the ID property provided by both its name '$id_property' and the generic alias '@id'.");
                }
                $normalized_element[$id_property] = $element[$id_property];
                // Unset so that we won't throw an exception at the end.
                unset($element[$id_property]);
            } elseif (array_key_exists('@id', $element)) {
                $normalized_element[$id_property] = $element['@id'];
                unset($element['@id']);
            }
            if (isset($normalized_element[$id_property]) && !is_int($normalized_element[$id_property]) && !is_string($normalized_element[$id_property])) {
                throw new InvalidArgumentException("'$id_property' property in $element_descr must hold integer/string value.");
            }
        }

        // Check if this element now consists of nothing more than 'Fields' and
        // 'Objects' sub-elements. (If there's an unrecognized sub-element, the
        // below code will populate nothing and report unknown properties
        // 'Fields, Objects, <extra>' in the end.)
        $well_formed = count($element) == (isset($element['Fields']) ? 1 : 0) + (isset($element['Objects']) ? 1 : 0);

        // The keys in $this->elements are not reordered on output, and we want
        // to have 'Fields' go first just because it looks nice for humans who
        // might look at the output. On the other hand, we need to populate
        // 'Objects' first because we promised implementing code that during
        // field validation, embedded objects are already validated. So,
        // 'cheat' by pre-populating a 'Fields' key. (Note that if code
        // populates a new element using individual setObject(), setField() and
        // setId() calls, it can influence the order of keys in output.)
        $normalized_element['Fields'] = [];

        // Validate / add objects, if object property definitions exist /
        // unless the 'well formed' element has no Objects.
        if (!empty($this->propertyDefinitions['objects']) && (!$well_formed || isset($element['Objects']))) {
            if ($well_formed) {
                $real_element = $element;
                $element = $element['Objects'];
                if (!is_array($element)) {
                    throw new InvalidArgumentException("$element_descr has a non-array 'Objects' value.");
                }
            }

            foreach ($this->propertyDefinitions['objects'] as $name => $object_properties) {
                if (!is_array($object_properties)) {
                    throw new UnexpectedValueException("'{$this->getType()}' object has a non-array definition for object '$name'.");
                }
                $value_present = false;
                // Get value from the property equal to the object name (case
                // sensitive!), or the alias. If two values are present with
                // both name and alias, throw an exception.
                $value_exists_by_alias = isset($object_properties['alias']) && array_key_exists($object_properties['alias'], $element);
                if (array_key_exists($name, $element)) {
                    if ($value_exists_by_alias) {
                        throw new InvalidArgumentException("$element_descr has a value provided by both its property name $name and alias $object_properties[alias].");
                    }
                    $value = $element[$name];
                    unset($element[$name]);
                    $value_present = true;
                } elseif ($value_exists_by_alias) {
                    $value = $element[$object_properties['alias']];
                    unset($element[$object_properties['alias']]);
                    $value_present = true;
                }

                if ($value_present) {
                    /** @noinspection PhpUndefinedVariableInspection */
                    if (!is_array($value)) {
                        $property = $name . (isset($alias) ? " ($alias)" : '');
                        throw new InvalidArgumentException("Value for '$property' object embedded in $element_descr must be array.");
                    }

                    $normalized_element['Objects'][$name] = $this->createEmbeddedObject($name, $value, null, $element_index, $validation_behavior);
                }
            }

            // If the element is 'well formed', then we can check for unknown
            // object definitions here.
            if ($well_formed) {
                if ($element) {
                    $keys = "'" . implode(', ', array_keys($element)) . "'";
                    throw new OutOfBoundsException("Unknown 'Objects' properties provided for $element_descr: names are $keys.");
                }
                /** @noinspection PhpUndefinedVariableInspection */
                $element = $real_element;
                unset($element['Objects']);
            }
        }

        // Validate / add fields, if field property definitions exist / unless
        // the element has no Fields.
        if (!empty($this->propertyDefinitions['fields']) && ($well_formed ? isset($element['Fields']) : $element)) {
            if ($well_formed) {
                // Nothing except 'Fields' is left.
                $element = $element['Fields'];
                if (!is_array($element)) {
                    throw new InvalidArgumentException("$element_descr has a non-array 'Fields' value.");
                }
            }

            foreach ($this->propertyDefinitions['fields'] as $name => $field_properties) {
                if (!is_array($field_properties)) {
                    throw new UnexpectedValueException("'{$this->getType()}' object has a non-array definition for field '$name'.");
                }
                $value_present = false;
                // Get value from the property equal to the field name (case
                // sensitive!), or the alias. If two values are present with
                // both field name and alias, throw an exception.
                $value_exists_by_alias = isset($field_properties['alias']) && array_key_exists($field_properties['alias'], $element);
                if (array_key_exists($name, $element)) {
                    if ($value_exists_by_alias) {
                        throw new InvalidArgumentException("$element_descr has a value provided by both its field name $name and alias $field_properties[alias].");
                    }
                    $value = $element[$name];
                    unset($element[$name]);
                    $value_present = true;
                } elseif ($value_exists_by_alias) {
                    $value = $element[$field_properties['alias']];
                    unset($element[$field_properties['alias']]);
                    $value_present = true;
                }

                if ($value_present) {
                    /** @noinspection PhpUndefinedVariableInspection */
                    $normalized_element['Fields'][$name] = $this->validateFieldValue($value, $name, self::ALLOW_NO_CHANGES, $validation_behavior, $element_index, $normalized_element);
                }
            }
        }

        // Throw exception if we have unknown data left (for which we have not
        // seen a field/object/id).
        if ($element) {
            $keys = "'" . implode(', ', array_keys($element)) . "'";
            $description = $well_formed ? "'Fields'" : 'element';
            throw new OutOfBoundsException("Unknown $description properties provided for $element_descr: names are $keys.");
        }

        // If we didn't populate any fields, then unset our 'cheat' value.
        if (empty($normalized_element['Fields'])) {
            unset($normalized_element['Fields']);
        }

        return $normalized_element;
    }

    /**
     * Sets (a normalized/de-aliased version of) an element in this object.
     *
     * This can be used to set/change just one element if the object already
     * contains multiple elements, without touching the other elements, or if
     * you really want to index your elements by strings for some reason. In
     * general it's more advisable to use setElements() if this object should
     * have just one element, or addElements() to add an additional element.
     *
     * @param int|string $element_index
     *   (Optional) The index of the element which will be created or
     *   overwritten. Indexes will not be present in output, and the order in
     *   which elements are output depends only on the order in which they were
     *   created; not directly on the index values. Their only use is for
     *   referring to specific elements which are present in this object. By
     *   default (when elements are created through create() -> addElement())
     *   they are zero-based, incrementing integers.
     * @param array $element
     *   Data representing a single element to set; see create() for a
     *   description.
     * @param int $validation_behavior
     *   (Optional) Specifies whether/how the elements should be validated; see
     *   create() for a more elaborate description.
     *
     * @throws \InvalidArgumentException
     *   If the element data contains invalid values.
     * @throws \OutOfBoundsException
     *   If the element data contains unknown field/object names.
     * @throws \UnexpectedValueException
     *   If this object's defined properties are invalid.
     *
     * @see create()
     */
    public function setElement($element_index, array $element, $validation_behavior = self::VALIDATE_ESSENTIAL)
    {
        $this->elements[$element_index] = $this->normalizeElement($element, $element_index, $validation_behavior);
    }

    /**
     * Sets (a normalized/de-aliased version of) element values in this object.
     *
     * Unlike addElements(), this overwrites any existing element data which
     * may have been present previously. It does not overwrite e.g. the action
     * value(s).
     *
     * @param array $elements
     *   Data representing one or more elements to set; see create() for a
     *   description. Note that an array of elements can only be numerically
     *   keyed; it will be interpreted as a single array otherwise. (This
     *   unlike setElements(), setField() etc, which allow to set/reference
     *   elements by alphanumeric indexes.)
     * @param int $validation_behavior
     *   (Optional) Specifies whether/how the elements should be validated; see
     *   create() for a more elaborate description.
     *
     * @throws \InvalidArgumentException
     *   If the element data contains invalid values.
     * @throws \OutOfBoundsException
     *   If the element data contains unknown field/object names.
     * @throws \UnexpectedValueException
     *   If this object's defined properties are invalid.
     *
     * @see create()
     */
    public function setElements(array $elements, $validation_behavior = self::VALIDATE_ESSENTIAL)
    {
        $this->elements = [];
        $this->addElements($elements, $validation_behavior);
    }

    /**
     * Adds (a normalized/de-aliased version of) element values to this object.
     *
     * It is recommended to set the 'action' for the elements that will be
     * added, before calling this method. This can be significant for
     * validation and for embedded objects, which inherit that action.
     *
     * @param array $elements
     *   Data representing one or more elements to set; see create() for a
     *   description. Note that an array of elements can only be numerically
     *   keyed; it will be interpreted as a single array otherwise. (This
     *   unlike setElements(), setField() etc, which allow to set/reference
     *   elements by alphanumeric indexes.)
     * @param int $validation_behavior
     *   (Optional) Specifies whether/how the elements should be validated; see
     *   create() for a more elaborate description.
     *
     * @throws \InvalidArgumentException
     *   If the element data contains invalid values.
     * @throws \OutOfBoundsException
     *   If the element data contains unknown field/object names.
     * @throws \UnexpectedValueException
     *   If this object's defined properties are invalid.
     *
     * @see create()
     */
    public function addElements(array $elements, $validation_behavior = self::VALIDATE_ESSENTIAL)
    {
        $elements = $this->normalizeElements($elements);

        // Log messages will contain the index which the element will get; not
        // the index which the element currently has in $elements. If this
        // object already contains elements and we are trying to add multiple
        // elements in this call, that could be confusing. Otherwise, not very.
        $next_index = count($this->elements);
        foreach ($elements as $element) {
            // We allow callers to add their own index values so we cannot
            // assume that the one we want to add does not exist.
            while (isset($this->elements[$next_index])) {
                $next_index++;
            }
            $this->elements[$next_index] = $this->normalizeElement($element, $next_index, $validation_behavior);
        }
    }

    /**
     * Returns the "Element" data representing one or several elements.
     *
     * This is the 'getter' equivalent for setElements() but the data is
     * normalized / de-aliased, and possibly validated and changed.
     *
     * @param int $change_behavior
     *   (Optional) see output(). Unlike output, by default nothing is changed
     *   in the value stored in this class (e.g. default values are not added).
     * @param int $validation_behavior
     *   (Optional) see output(). Unlike output, by default nothing is
     *   validated.
     *
     * @return array[]
     *   Zero or more (numerically keyed) arrays; one in the typical use case.
     *   Each represents an element, which can contain 1 to 3 keys: the name of
     *   the ID field (starting with "@"), "Fields" and "Objects". "Fields" is
     *   always present even if empty. The "Objects" value, if present, is an
     *   array (keyed by the reference field names) of one-element arrays with
     *   a key "Element" and value again (recursively) zero or more elements.
     *
     * @throws \InvalidArgumentException
     *   If any of the arguments have an unrecognized value.
     * @throws \UnexpectedValueException
     *   If this object's data does not pass validation. (This likely indicates
     *   the data is invalid, although in theory it can also indicate that the
     *   validation is based on improper logic/definitions.)
     *
     * @see output()
     */
    public function getElements($change_behavior = self::ALLOW_NO_CHANGES, $validation_behavior = self::VALIDATE_NOTHING)
    {
        if (!is_int($change_behavior)) {
            throw new InvalidArgumentException('$change_behavior argument is not an integer.');
        }
        if (!is_int($validation_behavior)) {
            throw new InvalidArgumentException('$validation_behavior argument is not an integer.');
        }

        $return = [];
        $errors = [];
        foreach ($this->elements as $element_index => $element) {
            // Standard code can never have non-numeric indices and non-array
            // elements, but check everything, in case a child class messes up.
            if (!is_array($element)) {
                // We never expose the index value but let's keep it as-is in
                // error messages - except suppress it when we have 1 element.
                $element_descr = "'{$this->getType()}' element" . ($element_index ? " with index $element_index" : '');
                throw new UnexpectedValueException("$element_descr is not an array.");
            }
            $element = $this->validateElement($element, $element_index, $change_behavior, $validation_behavior);

            if (isset($element['*errors'])) {
                if (!is_array($element['*errors'])) {
                    throw new UnexpectedValueException('Something unexpected during validation. We got a single value where we expected an array:' . print_r($element['*errors'], TRUE));
                }
                if ($element['*errors']) {
                    if ($errors) {
                        // Merge errors from two elements.
                        foreach ($element['*errors'] as $key => $value) {
                            $errors["$element_index:$key"] = $value;
                        }
                    } else {
                        // We don't care enough about the keys to renumber them
                        // if this is not the first element.
                        $errors = $element['*errors'];
                    }
                } else {
                    unset($element['*errors']);
                }
            }
            if ($element) {
                $return[] = $element;
            }
        }
        if ($errors) {
            throw new UnexpectedValueException(implode("\n", $errors));
        }

        return $return;
    }

    /**
     * Validates one element against a list of property definitions.
     *
     * This method has some boilerplate / checks integrated into it which are
     * also necessary for some protected methods it calls and don't need to be
     * repeated there. It should not touch $this->elements.
     *
     * @param array $element
     *   The element (usually the single one contained in $this->elements) that
     *   should be validated.
     * @param int|string $element_index
     *   The index of the element in our object data; usually there is one
     *   element and the index is 0.
     * @param int $change_behavior
     *   (Optional) see output().
     * @param int $validation_behavior
     *   (Optional) see output().
     *
     * @return array
     *   The validated element, with changes applied if appropriate.
     *   Validation errors are stored as an array under the '*errors' key,
     *   further keyed by 'Objects:<name>'; anything present under this key
     *   is overwritten.
     *
     * @throws \UnexpectedValueException
     *   If property definitions are invalid and validation could not be done.
     */
    protected function validateElement(array $element, $element_index, $change_behavior = self::DEFAULT_CHANGE, $validation_behavior = self::DEFAULT_VALIDATION)
    {
        // Do most low level structure checks here so that the other validate*()
        // methods are easier to override.
        $element_descr = "'{$this->getType()}' element" . ($element_index ? " with index $element_index" : '');
        if (isset($element['Fields']) && !is_array($element['Fields'])) {
            throw new UnexpectedValueException("$element_descr has a non-array 'Fields' property value.");
        }
        if (isset($element['Objects']) && !is_array($element['Objects'])) {
            throw new UnexpectedValueException("$element_descr has a non-array 'Objects' property value.");
        }

        // Doublechecks; unlikely to fail because also in addElements(). (We
        // won't repeat them in each individual validate method; we do them
        // beforehand so child classes overriding validate[Reference]Fields
        // don't have to worry about them.
        if (empty($this->propertyDefinitions)) {
            throw new UnexpectedValueException("'{$this->getType()}' object has no property definitions.");
        }
        if (!isset($this->propertyDefinitions['fields']) || !is_array($this->propertyDefinitions['fields'])) {
            throw new UnexpectedValueException("'{$this->getType()}' object has no / a non-array 'fields' property definition.");
        }
        if (isset($this->propertyDefinitions['objects']) && !is_array($this->propertyDefinitions['objects'])) {
            throw new UnexpectedValueException("'{$this->getType()}' object has a non-array 'objects' property definition.");
        }

        $element['*errors'] = [];
        if (!empty($this->propertyDefinitions['id_property'])) {
            if (!is_string($this->propertyDefinitions['id_property'])) {
                throw new UnexpectedValueException("'id_property' definition in '{$this->getType()}' object is not a string value.");
            }
            // Empty string means the same as unset. (This way we do not need
            // to implement an unsetId method. We assume '' can never be a
            // valid ID value, even though who knows 0 or "0" could be.)
            $id_property = '@' . $this->propertyDefinitions['id_property'];
            if (isset($element[$id_property]) && $element[$id_property] === '') {
                unset($element[$id_property]);
            } elseif ($validation_behavior & self::VALIDATE_ESSENTIAL) {
                if (isset($element[$id_property])) {
                    if (!is_int($element[$id_property]) && !is_string($element[$id_property])) {
                        $element['*errors'][$id_property] = "'$id_property' property in $element_descr must hold integer/string value.";
                    }
                } else {
                    // If action is "insert", we are guessing that there
                    // usually isn't, but still could be, a value for the ID
                    // field; it depends on 'auto numbering' for this object
                    // type (or the value of the 'Autonum' field). We don't
                    // validate this. (Yet?) We do validate that there is an ID
                    // value if action is different than "insert".
                    $action = $this->getAction($element_index);
                    if ($action !== 'insert') {
                        $element['*errors'][$id_property] = "'$id_property' property in $element_descr must have a value, or Action '$action' must be set to 'insert'.";
                    }
                }
            }
        }

        // Design decision: validate embedded objects first ('depth first'),
        // then validate the rest of this element while knowing that the
        // 'children' are OK, and with their properties accessible (dependent
        // on some $change_behavior values).
        $element = $this->validateReferenceFields($element, $element_index, $change_behavior, $validation_behavior);
        $element = $this->validateFields($element, $element_index, $change_behavior, $validation_behavior);;

        if ($validation_behavior & self::VALIDATE_NO_UNKNOWN) {
            // Validate that all Fields/Objects/other properties are known.
            // This is a somewhat superfluous check because we already do this
            // in addElements() (where we more or less have to, because our
            // input is not divided over 'Objects' and 'Fields' so
            // addElements() has to decide how/where to set each property).
            if (!empty($element['Fields']) && $unknown = array_diff_key($element['Fields'], $this->propertyDefinitions['fields'])) {
                $element['*errors']['Fields'] = "Unknown field(s) encountered in $element_descr: " . implode(', ', array_keys($unknown));
            }
            if (!empty($element['Objects']) && !empty($this->propertyDefinitions['objects']) && $unknown = array_diff_key($element['Objects'], $this->propertyDefinitions['objects'])) {
                $element['*errors']['Objects'] = "Unknown object(s) encountered in $element_descr: " . implode(', ', array_keys($unknown));
            }
            $known_properties = ['Fields' => true, 'Objects' => true, '*errors' => true];
            if (!empty($this->propertyDefinitions['id_property'])) {
                $known_properties['@' . $this->propertyDefinitions['id_property']] = true;
            }
            if ($unknown = array_diff_key($element, $known_properties)) {
                $element['*errors']['*'] = "Unknown properties encountered in $element_descr: " . implode(', ', array_keys($unknown));
            }
        }

        return $element;
    }

    /**
     * Validates an element's object reference fields; replaces them by arrays.
     *
     * This is mainly split out from validateElement() to be easier to override
     * by child classes. It should generally not touch $this->elements. We
     * can assume '*errors' has been initialized.
     *
     * @param array $element
     *   The element (usually the single one contained in $this->elements)
     *   whose embedded objects should be validated.
     * @param int|string $element_index
     *   The index of the element in our object data; usually there is one
     *   element and the index is 0.
     * @param int $change_behavior
     *   (Optional) see output(). Note that this is the behavior for our
     *   element and may still need to be amended to apply to embedded objects.
     * @param int $validation_behavior
     *   (Optional) see output().
     *
     * @return array
     *   The element with its embedded fields validated, and possibly default
     *   objects added if appropriate. All values in the 'Objects' sub array
     *   are replaced by their validated array representation, wrapped inside a
     *   one-element array keyed by 'Element' (as is necessary for the JSON
     *   representation of data sent to an Update Connector). Validation errors
     *   are stored as an array under the '*errors' key, further keyed by
     *   'Objects:<name>'.
     */
    protected function validateReferenceFields(array $element, $element_index, $change_behavior = self::DEFAULT_CHANGE, $validation_behavior = self::DEFAULT_VALIDATION)
    {
        if (!isset($this->propertyDefinitions['objects'])) {
            return $element;
        }
        $action = $this->getAction($element_index);
        $defaults_allowed = ($action === 'insert' && $change_behavior & self::ALLOW_DEFAULTS_ON_INSERT)
            || ($action === 'update' && $change_behavior & self::ALLOW_DEFAULTS_ON_UPDATE);
        $element_descr = "'{$this->getType()}' element" . ($element_index ? " with index $element_index" : '');

        // Check requiredness for reference field, and create a default object
        // if it's missing (where defined. Defaults are unlikely to ever be
        // needed but still... they're possible.)
        foreach ($this->propertyDefinitions['objects'] as $ref_name => $object_properties) {
            if (!is_array($object_properties)) {
                throw new UnexpectedValueException("'{$this->getType()}' object has a non-array definition for object '$ref_name'.");
            }

            // The structure of this code is equivalent to validateFields() but
            // a null default value means 'no default available' (instead of
            // 'a default of null', which it does in validateFields().)
            $default_available = $defaults_allowed && isset($object_properties['default']);
            // Requiredness is only checked for action "insert"; the
            // VALIDATE_ESSENTIAL bit does not change that. (So far, we've only
            // seen 'required on update' situations only for fields which are
            // not proper fields, and those situations are solved in custom
            // code in child classes.)
            $validate_required_value = !empty($object_properties['required'])
                && ($validation_behavior & self::VALIDATE_REQUIRED
                    || ($object_properties['required'] === 1 && $validation_behavior & self::VALIDATE_ESSENTIAL));
            // Throw an exception if we have no-or-null ref-field value and no
            // default, OR if we have null ref-field value and non-null default.
            // (See validateFields() for details on this reasoning. Note the
            // array_key_exists() means "is null",)
            if ($validate_required_value && !isset($element['Objects'][$ref_name])
                && (!$default_available || array_key_exists($ref_name, $element['Objects']))
            ) {
                $name_and_alias = "'$ref_name'" . (isset($object_properties['alias']) ? " ({$object_properties['alias']})" : '');
                $element['*errors']["Objects:$ref_name"] = "No value provided for required $name_and_alias object embedded in $element_descr.";
            } else {
                // Set default if value is missing, or if value is null and
                // field is required (and if we are allowed to set it, but
                // that's always the case if $default_available).
                if ($default_available
                    && (!array_key_exists($ref_name, $element['Objects'])
                        || !empty($object_properties['required']) && $element['Objects'][$ref_name] === null)) {
                    $element['Objects'][$ref_name] = $this->getObject($ref_name, $element_index, true);
                }

                if (isset($element['Objects'][$ref_name])) {
                    // Replace UpdateObject with validated array representation.
                    try {
                        $object_value = $this->validateObjectValue($element['Objects'][$ref_name], $ref_name, $change_behavior, $validation_behavior, $element_index);
                        if ($object_value) {
                            $element['Objects'][$ref_name] = ['Element' => $object_value];
                        } else {
                            unset($element['Objects'][$ref_name]);
                        }
                    } catch (UnexpectedValueException $e) {
                        $element['*errors']["Objects:$ref_name"] = $e->getMessage();
                    }
                }
            }
        }

        // It's possible that $element contained objects but they were all
        // 'empty'; in that case, unset 'Objects'.
        if (isset($element['Objects']) && empty($element['Objects'])) {
            unset($element['Objects']);
        }

        return $element;
    }

    /**
     * Validates an element's fields against a list of property definitions.
     *
     * This calls validateFieldValue() which is meant to validate 'standalone'
     * field values; the code in this method is meant to validate things that
     * make sense in the context of the full element, like default / required
     * values for fields that don't have a value.
     *
     * This is mainly split out from validateElement() to be easier to override
     * by child classes. It should generally not touch $this->elements. We
     * can assume '*errors' has been initialized and all embedded objects
     * (except those with validation errors) have been validated and replaced
     * by array structures (so this method should  not be called in cases where
     * this is not true).
     *
     * @param array $element
     *   The element (usually the single one contained in $this->elements)
     *   whose fields should be validated.
     * @param int|string $element_index
     *   The index of the element in our object data; usually there is one
     *   element and the index is 0.
     * @param int $change_behavior
     *   (Optional) see output().
     * @param int $validation_behavior
     *   (Optional) see output().
     *
     * @return array
     *   The element with its fields validated, and changed if appropriate.
     *   Validation errors are stored as an array under the '*errors' key,
     *   further keyed by 'Fields:<name>'.
     *
     * @throws \UnexpectedValueException
     *   If property definitions are invalid and validation could not be done.
     */
    protected function validateFields(array $element, $element_index, $change_behavior = self::DEFAULT_CHANGE, $validation_behavior = self::DEFAULT_VALIDATION)
    {
        $action = $this->getAction($element_index);
        $defaults_allowed = ($action === 'insert' && $change_behavior & self::ALLOW_DEFAULTS_ON_INSERT)
            || ($action === 'update' && $change_behavior & self::ALLOW_DEFAULTS_ON_UPDATE);
        $element_descr = "'{$this->getType()}' element" . ($element_index ? " with index $element_index" : '');

        // Check required fields and add default values for fields (where
        // defined). About definitions:
        // - if required = true, then
        //   - if no data value present and default is provided, it's set.
        //   - if no data value present and no default is provided, an
        //     exception is thrown.
        //   - if a null value is present, an exception is thrown, unless null
        //     is provided as a default value. (We don't silently overwrite
        //     null values which were explicitly set with other default values.)
        // - if the default is null (or value provided is null & not 'required')
        //   then null is passed.
        foreach ($this->propertyDefinitions['fields'] as $name => $field_properties) {
            if (!is_array($field_properties)) {
                throw new UnexpectedValueException("'{$this->getType()}' object has a non-array definition for field '$name'.");
            }

            $default_available = $defaults_allowed && array_key_exists('default', $field_properties);
            // Requiredness is only checked for action "insert"; the
            // VALIDATE_ESSENTIAL bit does not change that. (So far, we've only
            // seen 'required on update' situations only for fields which are
            // not proper fields, and those situations are solved in custom
            // code in child classes.)
            $validate_required_value = !empty($field_properties['required'])
                && $action === 'insert'
                && ($validation_behavior & self::VALIDATE_REQUIRED
                    || ($field_properties['required'] === 1 && $validation_behavior & self::VALIDATE_ESSENTIAL));
            // See above: throw an exception if we have no-or-null field
            // value and no default, OR if we have null field value and
            // non-null default. (Note the array_key_exists() means "is null",)
            if ($validate_required_value && !isset($element['Fields'][$name])
                && (!$default_available
                    || (array_key_exists($name, $element['Fields']) && $field_properties['default'] !== null))
            ) {
                $name_and_alias = "'$name'" . (isset($field_properties['alias']) ? " ({$field_properties['alias']})" : '');
                $element['*errors']["Fields:$name"] = "No value provided for required $name_and_alias field of $element_descr.";
            } else {
                // Set default if value is missing, or if value is null and
                // field is required (and if we are allowed to set it, but
                // that's always the case if $default_available).
                if ($default_available
                    && (!array_key_exists($name, $element['Fields'])
                        || !empty($field_properties['required']) && $element['Fields'][$name] === null)) {
                    $element['Fields'][$name] = $field_properties['default'];
                }

                if (isset($element['Fields'][$name])) {
                    try {
                        $element['Fields'][$name] = $this->validateFieldValue($element['Fields'][$name], $name, $change_behavior, $validation_behavior, $element_index, $element);
                    } catch (InvalidArgumentException $e) {
                        $element['*errors']["Fields:$name"] = $e->getMessage();
                    }
                }
            }
        }

        return $element;
    }

    /**
     * Validates the value for an element's embedded object.
     *
     * @param mixed $value
     *   The value, which is supposed to be an UpdateObject.
     * @param string $reference_field_name
     *   The name of the reference field for the object.
     * @param int $change_behavior
     *   (Optional) see output(). Note that this is the behavior for our
     *   element and may still need to be amended to apply to embedded objects.
     * @param int $validation_behavior
     *   (Optional) see output().
     * @param int|string $element_index
     *   (Optional) The index of the element in our object data. Often only
     *   used for logging.
     *
     * @return array
     *   A representation of the object's contents: either a single element, or
     *   an array of one or more elements, depending on the 'multiple' property
     *   definition for the corresponding reference field.
     *
     * @throws \UnexpectedValueException
     *   If the element data does not pass validation.
     */
    protected function validateObjectValue($value, $reference_field_name, $change_behavior = self::DEFAULT_CHANGE, $validation_behavior = self::DEFAULT_VALIDATION, $element_index = null)
    {
        if (!$value instanceof UpdateObject) {
            $element_descr = "'{$this->getType()}' element" . ($element_index ? " with index $element_index" : '');
            $name_and_alias = "'$reference_field_name'" . (isset($this->propertyDefinitions['objects'][$reference_field_name]['alias']) ? " ({$this->propertyDefinitions['objects'][$reference_field_name]['alias']})" : '');
            throw new UnexpectedValueException("$name_and_alias object embedded in $element_descr must be an object of type UpdateObject.");
        }

        // Validation of a full object is done by getElements().
        $embedded_change_behavior = $change_behavior & self::ALLOW_EMBEDDED_CHANGES
            ? $change_behavior : self::ALLOW_NO_CHANGES;
        $elements = $value->getElements($embedded_change_behavior, $validation_behavior);
        // Validate whether this reference field is allowed to have multiple
        // elements. Also, decide the exact structure of "Elements" in the
        // embedded objects. I'm making assumptions here because I don't have
        // real specifications from AFAS, or a means to test this:
        // - Their own knowledge base examples (for UpdateConnector which use
        //   KnSubject) specify a single element inside the "Element" key, e.g.
        //   "@SbId" and "Fields" are directly inside "Element".
        // - That clearly doesn't work when multiple elements need to be
        //   embedded as part of the same reference field, e.g. a FbSales
        //   entity can have multiple elements inside the FbSalesLines object.
        //   In this case its "Element" key contains an array of elements.
        // This class has the FLATTEN_SINGLE_ELEMENT bit for output() so the
        // caller can decide what to do with 'main' objects. (By default, one
        // element is 'flattened' because see first point above, but multiple
        // elements are supported.) For embedded objects, I officially do not
        // know if AFAS accepts an array inside "Elements" for _any_ field. So
        // we ignore the FLATTEN_SINGLE_ELEMENT bit here and let the 'multiple'
        // property of the reference field drive what happens:
        // - For reference fields that can embed multiple elements, we keep the
        //   array, regardless whether we have one or more elements at the
        //   moment. (This to keep the structure of a particular reference
        //   field consistent; I do not imagine AFAS will deny an array of 1
        //   elements.)
        // - For reference fields that can only embed one element, we unwrap
        //   this array and place the element directly inside "Element"
        //   (because I do not know if AFAS accepts an array).
        if (empty($this->propertyDefinitions['objects'][$reference_field_name]['multiple'])) {
            if (count($elements) == 1) {
                $elements = reset($elements);
            } elseif ($elements && $validation_behavior & self::VALIDATE_ESSENTIAL) {
                $element_descr = "'{$this->getType()}' element" . ($element_index ? " with index $element_index" : '');
                $name_and_alias = "'$reference_field_name'" . (isset($this->propertyDefinitions['objects'][$reference_field_name]['alias']) ? " ({$this->propertyDefinitions['objects'][$reference_field_name]['alias']})" : '');
                throw new UnexpectedValueException("$name_and_alias object embedded in $element_descr contains " . count($elements) . ' elements but can only contain a single element.');
            }
        }

        return $elements;
    }

    /**
     * Validates the value for an element's field.
     *
     * This method is used both on 'input into' and 'output from' an element
     * (e.g. setField() / addElements() and validateFields() call it). It is
     * supposed to be called only with field names/aliases that we know to
     * exist for this object type. If an extending class implements validation
     * that depends on other values inside this element: that is allowed but
     * can get fussy / makes assumptions about how this method is called; see
     * $element parameter.
     *
     * @param mixed $value
     *   A scalar value which is (going to be) assigned to an element's field.
     * @param string $field_name
     *   The name of the field.
     * @param int $change_behavior
     *   (Optional) see output().
     * @param int $validation_behavior
     *   (Optional) see output().
     * @param int|string $element_index
     *   The index that the element which the object will be embedded into, has
     *   or will have. When called from add/setElement(s), the element with the
     *   specified index does/may not exist yet.
     * @param array $element
     *   (Optional) The full element being validated, for the benefit of
     *   validation checks which depend on other values. (If the full element
     *   is equal to $this->elements[index]... just pass that if possible, to
     *   make sure that those validation checks can be performed. It is often
     *   not equal to $this->elements[index] because the element may change
     *   during validation.) This argument is fussy: callers looping over
     *   fields while calling this function should do this in a well defined
     *   order, for the benefit of validation checks. Child classes extending
     *   this method should be aware of its limitations which we'll illustrate
     *   by the standard callers in this class:
     *   - When called while an element is being populated, we cannot assume
     *     all other fields have been populated yet. (addElements() does this
     *     in the order in which fields occur in $this->propertyDefinitions.)
     *     We also cannot assume any fields have been validated. (That depends
     *     on 'validation behavior' when those fields were populated.)
     *   - When called by setField(), we cannot assume other fields have been
     *     validated; see previous point.
     *   - When called while an element is being validated through
     *     getElements() -> validateElement(): we cannot assume all other
     *     fields have been validated (see first point about field order);
     *     individual objects in the 'objects' array are replaced by arrays:
     *     the return value of validateObjectValue() keyed by 'Element'.
     *
     * @return mixed
     *   The validated, and possibly changed if appropriate, value.
     *
     * @throws \InvalidArgumentException
     *   If the value does not pass validation.
     */
    protected function validateFieldValue($value, $field_name, $change_behavior = self::DEFAULT_CHANGE, $validation_behavior = self::DEFAULT_VALIDATION, $element_index = null, array $element = null)
    {
        // No validation for null values. (Requiredness is validated elsewhere.)
        if (isset($value)) {
            if ($validation_behavior & self::VALIDATE_ESSENTIAL) {
                try {
                    if (!is_scalar($value)) {
                        throw new InvalidArgumentException("%NAME field value of %ELEMENT must be scalar.");
                    }

                    if (!empty($this->propertyDefinitions['fields'][$field_name]['type'])) {
                        switch ($this->propertyDefinitions['fields'][$field_name]['type']) {
                            case 'integer':
                            case 'decimal':
                                if (!is_numeric($value)) {
                                    throw new InvalidArgumentException("%NAME field value of %ELEMENT must be numeric.");
                                }
                                if ($this->propertyDefinitions['fields'][$field_name]['type'] === 'integer' && strpos((string)$value, '.') !== false) {
                                    throw new InvalidArgumentException("%NAME field value of %ELEMENT must be an integer value.");
                                }
                                // @todo check digits for decimal, if/when we know that's necessary.
                                break;
                        }
                    }
                } catch (InvalidArgumentException $e) {
                    // Catch and rethrow because we don't feel like repeatedly
                    // defining the element & field name constructions above.
                    $element_descr = "'{$this->getType()}' element" . ($element_index ? " which has (or will get) index $element_index" : '');
                    $name_and_alias = "'$field_name'" . (isset($this->propertyDefinitions['fields'][$field_name]['alias']) ? " ({$this->propertyDefinitions['fields'][$field_name]['alias']})" : '');
                    throw new InvalidArgumentException(str_replace('%NAME', $name_and_alias, str_replace('%ELEMENT', $element_descr, $e->getMessage())));
                }
            }

            // Trim value, or change its type, if allowed. Notes:
            // - This isn't done on 'input' (from setField() / addElements());
            //   values are only guaranteed to be converted cast to their
            //   correct type during 'output' validation (e.g. output() /
            //   getElements() if this flag is set.
            // - If VALIDATE_ESSENTIAL was not set on 'input' and not on
            //   'output' either, that can mean data loss when invalid values
            //   are converted. We won't guard against that; changing fields
            //   without validating them first (which is only possible by
            //   passing non-default arguments on both 'input' and 'output') is
            //   asking for trouble.
            if (is_scalar($value) && $change_behavior & self::ALLOW_REFORMAT) {
                if (!empty($this->propertyDefinitions['fields'][$field_name]['type'])) {
                    switch ($this->propertyDefinitions['fields'][$field_name]['type']) {
                        case 'boolean':
                            $value = (bool) $value;
                            break;

                        case 'decimal':
                            $value = (float) $value;
                            break;

                        case 'integer':
                            $value = (int) $value;
                            break;

                        case 'string':
                            $value = trim($value);
                    }
                    // @todo format dates in standard way, if/when we know that's necessary.
                } elseif (is_string($value)) {
                    $value = trim($value);
                }
            }
        }

        return $value;
    }

/*
@TODO write tests, especially for validate()
  */

    /**
     * Outputs the object data as a string.
     *
     * @param string $format
     *   (Optional) The format; 'json' (default) or 'xml'.
     * @param array $format_options
     *   (Optional) Options influencing the format. General options:
     *   - 'pretty' (boolean): If true, pretty-print (with newlines/spaces).
     *   See outputXml() for XML specific options.
     * @param int $change_behavior
     *   (Optional) By default, this method can change values of fields and
     *   embedded objects (for e.g. uniform formatting of values ar adding
     *   defaults). This argument is a bitmask that can influence which data
     *   can be changed. Possible values are:
     *   - ALLOW_NO_CHANGES: Do not allow any changes. This value loses its
     *     meaning when passed together with other values.
     *   - ALLOW_EMBEDDED_CHANGES (default): Allow changes to be made to
     *     embedded objects. If it is specified, the other bits determine which
     *     changes can be made to embedded objects (so if only this value is
     *     specified, no changes are allowed to either the elements in this
     *     object or their embedded objects). If it is not specified, the other
     *     bits determine only which changes can be made to elements in this
     *     object, but any changes to embedded objects are disallowed.
     *   - ALLOW_DEFAULTS_ON_INSERT (default): Allow adding default values to
     *     empty fields when inserting a new element.
     *   - ALLOW_DEFAULTS_ON_UPDATE: Allow adding default values to empty
     *     fields when updating an existing element.
     *   - ALLOW_REFORMAT (default): Allow reformatting of singular field
     *     values. For 'reformatting' a combination of values (e.g. moving
     *     a house number from a street value into its own field) additional
     *     values may need to be passed.
     *   - ALLOW_CHANGES: Allow changing field values, in ways not covered by
     *     other bits. Behavior is not precisely defined by this class; child
     *     classes may use this value or implement their own additional bits.
     *   - FLATTEN_SINGLE_ELEMENT (default): If this object holds only one
     *     element, then output this single element inside the "Element"
     *     section rather than an array containing one element. (This bit only
     *     has effect for calls to output(), unlike other bits which have
     *     effect for every call that has a $change_behavior argument. It only
     *     applies to JSON output and is not expected to make a difference to
     *     AFAS though we're not 100% sure. It is not passed into embedded
     *     objects; those have hardcoded logic around 'flattening', in
     *     validateObjectValue().)
     *   Child classes might define additional values.
     * @param int $validation_behavior
     *   (Optional) By default, this method performs validation checks. This
     *   argument is a bitmask that can be used to disable validation checks (or
     *   add additional ones in child classes). Possible values are:
     *   - VALIDATE_NOTHING: Perform no validation checks at all.
     *   - VALIDATE_ESSENTIAL (default): Perform checks that we want to always
     *     be performed. These are checks on whether a value is of the proper
     *     data type (which are by default performed both while setting values
     *     into this class and on output). Another possible example is values
     *     required by AFAS, where AFAS will return an unhelpful error message
     *     if these are not provided.
     *   - VALIDATE_REQUIRED (default): Check for presence of field values which
     *     this library considers 'required'; this may be the case even if an
     *     AFAS Update Connector call would not fail if they're missing.
     *     Example: town/municipality in an address element.
     *   - VALIDATE_NO_UNKNOWN (default): Check if all fields and objects
     *     (reference fields) are known in our 'properties' definition, and if
     *     no unknown other properties (on the same level as 'Fields' and
     *     'Objects') exist. If this option is turned off, this may cause
     *     unknown values to be included in the output, with uncertain results
     *     (depending on how the AFAS API treats these).
     *   - VALIDATE_FORMAT: Check if fields are formatted in a certain way.
     *     (This is 'off' by default. The only fields affected at the moment
     *     are in a subclass.)
     *
     * @return string
     *   The string representation of the object data, validated and possibly
     *   modified according to the arguments passed.
     *
     * @throws \InvalidArgumentException
     *   If any of the arguments have an unrecognized value.
     * @throws \UnexpectedValueException
     *   If this object's data does not pass validation. (This likely indicates
     *   the data is invalid, although in theory it can also indicate that the
     *   validation is based on improper logic/definitions.)
     */
    public function output($format = 'json', array $format_options = [], $change_behavior = self::DEFAULT_CHANGE, $validation_behavior = self::DEFAULT_VALIDATION)
    {
        if (!is_int($change_behavior)) {
            throw new InvalidArgumentException('$change_behavior argument is not an integer.');
        }
        if (!is_int($validation_behavior)) {
            throw new InvalidArgumentException('$validation_behavior argument is not an integer.');
        }
        if (!is_string($format)) {
            throw new InvalidArgumentException('Invalid format.');
        }

        // The 'flatten' bit only makes sense for JSON output, and only inside
        // this method. (For embedded elements, 'flattening' is governed by a
        // reference field property instead; see validateObjectValue().)
        $flatten = $change_behavior & self::FLATTEN_SINGLE_ELEMENT;
        $change_behavior = $change_behavior & ~self::FLATTEN_SINGLE_ELEMENT;
        $elements = $this->getElements($change_behavior, $validation_behavior);
        // We disallow sending an empty value (i.e. zero elements) into AFAS.
        // (For the rest we allow anything, also e.g. an object with only ID.)
        if (empty($elements)) {
            // Strictly speaking, this is "no non-empty elements" because empty
            // ones are allowed in our internal structure, but filtered.
            throw new UnexpectedValueException("'{$this->getType()}' object holds no elements.");
        }

        switch (strtolower($format)) {
            case 'json':
                if ($flatten && count($elements) == 1) {
                    $elements = reset($elements);
                }
                // The JSON structure should have two one-element wrappers
                // around the element data, with keys being the object type
                // and 'Element'. (Embedded objects have the 'Element' wrapper
                // added by validateReferenceFields().)
                $data = [$this->getType() => ['Element' => $elements]];
                return empty($format_options['pretty']) ? json_encode($data) : json_encode($data, JSON_PRETTY_PRINT);

            case 'xml':
                $line_prefix = '';
                if (!empty($format_options['line_prefix']) && is_string($format_options['line_prefix'])) {
                    $line_prefix = $format_options['line_prefix'];
                }
                $pretty = !empty($format_options['pretty']) && (!isset($format_options['indent'])
                        || (is_int($format_options['indent']) && $format_options['indent'] >= 0));
                if ($pretty) {
                    // We want to indent all the outputXml() output because
                    // we're generating the XML 'wrapper' here.
                    $indent = isset($format_options['indent']) ? $format_options['indent'] : 2;
                    $format_options['line_prefix'] = $line_prefix . str_repeat(' ', $indent);
                }
                return $line_prefix . "<{$this->getType()} xmlns:xsi=\"http://www.w3.org/2001/XMLSchema-instance\">"
                    . $this->outputXml($elements, $format_options)
                    . "\n$line_prefix</{$this->getType()}>";

            default:
                throw new InvalidArgumentException("Invalid format '$format'.");
        }
    }

    /**
     * Encode data as XML, suitable for sending through SOAP connector.
     *
     * @param array $elements
     *   The validated elements. Note we still call outputXml() in any embedded
     *   objects recursively but only to have access to each object's
     *   'actions' (and the 'id_property' definition) even though we do not use
     *   / validate the objects' element values anymore. (If we did, we could
     *   miss some effects of having validated the full elements including
     *   embedded objects.)
     * @param array $format_options
     *    (Optional) Options influencing the format:
     *   - 'pretty' (boolean): If true, pretty-print (with newlines/spaces).
     *     Start with a newline, to concatenate to the header tag which is not
     *     this method's responsibility.
     *   - 'indent' (integer): The number of spaces to prefix an indented line
     *     with; 2 by default. 'pretty' effect is canceled if this is not an
     *     integer or < 0.
     *   - 'line_prefix' (string): a prefix to start each line with.
     *
     * @return string
     *   XML payload to send to an Update Connector on a SOAP API/Connection,
     *   excluding the XML start/end tag.
     */
    protected function outputXml(array $elements, array $format_options = [])
    {
        $xml = $indent_str1 = $indent_str2 = $indent_str3 = '';
        $pretty = !empty($format_options['pretty']) && (!isset($format_options['indent'])
                || (is_int($format_options['indent']) && $format_options['indent'] >= 0));
        if ($pretty) {
            $indent = isset($format_options['indent']) ? $format_options['indent'] : 2;
            $extra_spaces = str_repeat(' ', $indent);
            // LF + Indentation before Element tag:
            $indent_str1 = "\n$format_options[line_prefix]";
            // LF + Indentation before Fields/Objects tag:
            $indent_str2 = $indent_str1 . $extra_spaces;
            // LF + Indentation before individual field values:
            $indent_str3 = $indent_str2 . $extra_spaces;
        }

        // Fully validate the element(s) in this object, even though we won't
        // need the embedded objects for generating output. This means that we
        // validate all embedded objects multiple times (here and while
        // generating the output); objects that are 2 levels deep even get
        // validated 3 times. But it's the only way to validate field values
        // which depend on values in embedded objects (and stick to the 'design
        // decision' to validate embedded objects before object fields). Never
        // flatten the return value, otherwise we can't foreach().
        foreach ($elements as $element_index => $element) {
            $action_attribute = '';
            $action = $this->getAction($element_index);
            if ($action) {
                // Add the Action attribute only if it's explicitly specified.
                // (This method makes no assumptions about what it does or
                // whether its behavior affects embedded objects; it only
                // assumes that "" is not a valid Action value.)
                $action_attribute = ' Action="' . $action . '"';
            }

            // Requiredness of the ID field and validity of its value has been
            // checked elsewhere; we just include it if it's there.
            $id_attribute = '';
            if (!empty($this->propertyDefinitions['id_property'])) {
                if (!is_string($this->propertyDefinitions['id_property'])) {
                    throw new UnexpectedValueException("'id_property' definition in '{$this->getType()}' object is not a string value.");
                }
                if (isset($element['@' . $this->propertyDefinitions['id_property']])) {
                    $id_attribute = ' ' . $this->propertyDefinitions['id_property'] . '="' . $element['@' . $this->propertyDefinitions['id_property']] . '"';
                }
            }
            // Each element is in its own 'Element' tag (unlike the input
            // argument which has an array of elements in one 'Element' key,
            // because multiple 'Element' keys cannot exist in one object or
            // JSON string).
            $xml .= "$indent_str1<Element$id_attribute>";

            // Always add Fields tag, also if it's empty. (No idea if that's
            // necessary, but that's apparently how I tested it 5 years ago.)
            $xml .= "$indent_str2<Fields$action_attribute>";
            foreach ($element['Fields'] as $name => $value) {
                if (is_bool($value)) {
                    // Boolean values are encoded as 0/1 in AFAS XML.
                    $value = $value ? '1' : '0';
                }
                $xml .= $indent_str3 . (isset($value)
                        ? "<$name>" . htmlspecialchars($value, ENT_QUOTES | ENT_XML1) . "</$name>"
                        // Value is passed but null or default value is null
                        : "<$name xsi:nil=\"true\"/>");
            }
            $xml .= "$indent_str2</Fields>";

            // $element contains all embedded objects' array representations,
            // but we need to call the UpdateObject's outputXml() to be able
            // to insert the "action" attribute, if that's necessary.
            if (!empty($element['Objects'])) {
                $xml .= "$indent_str2<Objects>";
                if ($pretty) {
                    /** @noinspection PhpUndefinedVariableInspection */
                    $format_options['line_prefix'] = (empty($format_options['line_prefix']) ? '' : $format_options['line_prefix'])
                        . str_repeat(' ', $indent * 3);
                }
                // Iterating over the keys in 'Objects' is fine; it contains
                // all object values we need including defaults. (No need to
                // recheck the property definitions here.) We also know
                // getObject() should never return null for these.
                foreach ($element['Objects'] as $ref_name => $value) {
                    // Embedded objects have been 'flattened' according to
                    // their definition, so unflatten them.
                    $object = empty($this->propertyDefinitions['objects'][$ref_name]['multiple']) ? [$value['Element']] : $value['Element'];
                    $xml .= "$indent_str3<$ref_name>"
                        . $this->getObject($ref_name, $element_index, true)->outputXml($object, $format_options)
                        . "$indent_str3</$ref_name>";
                }
                $xml .= "$indent_str2</Objects>";
            }

            $xml .= "$indent_str1</Element>";
        }

        return $xml;
    }

}
