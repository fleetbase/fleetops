<?php

use Fleetbase\FleetOps\Http\Filter\ServiceQuoteFilter;
use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\FleetOps\Models\Customer;
use Fleetbase\FleetOps\Models\FleetDriver;
use Fleetbase\FleetOps\Models\FleetVehicle;
use Fleetbase\FleetOps\Models\FuelProviderConnection;
use Fleetbase\FleetOps\Models\GeofenceEventLog;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Models\Proof;
use Fleetbase\FleetOps\Models\ServiceRateParcelFee;
use Fleetbase\FleetOps\Models\VendorPersonnel;
use Fleetbase\FleetOps\Support\ParsePhone;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;
use libphonenumber\PhoneNumberFormat;

if (!class_exists('Illuminate\Foundation\Auth\User', false)) {
    eval('namespace Illuminate\Foundation\Auth; class User extends \Illuminate\Database\Eloquent\Model {}');
}

class FleetOpsCustomerLookupFake extends Customer
{
    public static ?Customer $result = null;
    public static array $whereCalls = [];

    public static function where($column, $operator = null, $value = null, $boolean = 'and')
    {
        static::$whereCalls[] = [$column, $operator, $value, $boolean];

        return new class {
            public function first(): ?Customer
            {
                return FleetOpsCustomerLookupFake::$result;
            }
        };
    }
}

class FleetOpsScopeBuilderFake
{
    public array $wheres = [];

    public function where(string $column, mixed $value): self
    {
        $this->wheres[] = [$column, $value];

        return $this;
    }
}

class FleetOpsFilterBuilderFake
{
    public array $wheres = [];

    public function where(string $column, mixed $value): self
    {
        $this->wheres[] = [$column, $value];

        return $this;
    }
}

class FleetOpsFilterSessionFake
{
    public function get(string $key): string
    {
        return $key === 'company' ? 'company-uuid' : '';
    }
}

class FleetOpsServiceQuoteFilterProbe extends ServiceQuoteFilter
{
    public FleetOpsFilterBuilderFake $testBuilder;

    public function __construct()
    {
        $this->testBuilder = new FleetOpsFilterBuilderFake();
        $this->builder     = $this->testBuilder;
        $this->session     = new FleetOpsFilterSessionFake();
    }
}

class FleetOpsParsePhoneProbe extends ParsePhone
{
    public static array $calls = [];

    public static function fromModel($model, $options = [], $format = PhoneNumberFormat::E164)
    {
        static::$calls[] = [$model, $options, $format];

        return 'parsed-phone';
    }
}

function fleetopsUseInMemoryRelationConnection(): void
{
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
    $resolver   = new ConnectionResolver([
        'default' => $connection,
        'mysql'   => $connection,
    ]);

    $resolver->setDefaultConnection('mysql');
    EloquentModel::setConnectionResolver($resolver);
}

test('customer public id lookup normalizes customer ids through contact ids', function () {
    FleetOpsCustomerLookupFake::$result     = new FleetOpsCustomerLookupFake();
    FleetOpsCustomerLookupFake::$whereCalls = [];

    expect(FleetOpsCustomerLookupFake::findFromCustomerId('customer_123'))->toBe(FleetOpsCustomerLookupFake::$result)
        ->and(FleetOpsCustomerLookupFake::$whereCalls)->toBe([
            ['public_id', 'contact_123', null, 'and'],
        ]);

    FleetOpsCustomerLookupFake::$whereCalls = [];

    FleetOpsCustomerLookupFake::findFromCustomerId('contact_456');

    expect(FleetOpsCustomerLookupFake::$whereCalls)->toBe([
        ['public_id', 'contact_456', null, 'and'],
    ]);
});

test('service rate parcel fee normalizes money and exposes unit value objects', function () {
    $row = ServiceRateParcelFee::onRowInsert(['fee' => 1250]);
    $fee = new ServiceRateParcelFee([
        'length'          => 10,
        'width'           => 20,
        'height'          => 30,
        'dimensions_unit' => 'cm',
        'weight'          => 7,
        'weight_unit'     => 'kg',
    ]);

    expect($row)->toHaveKey('fee')
        ->and($fee->getLengthUnitAttribute()->getOriginalValue())->toBe(10)
        ->and($fee->getWidthUnitAttribute()->getOriginalValue())->toBe(20)
        ->and($fee->getHeightUnitAttribute()->getOriginalValue())->toBe(30)
        ->and($fee->getMassUnitAttribute()->getOriginalValue())->toBe(7);
});

test('geofence event log scopes apply company and event type constraints', function () {
    $builder = new FleetOpsScopeBuilderFake();
    $model   = new GeofenceEventLog();

    expect($model->scopeForCompany($builder, 'company-uuid'))->toBe($builder)
        ->and($model->scopeOfType($builder, 'entered'))->toBe($builder)
        ->and($builder->wheres)->toBe([
            ['company_uuid', 'company-uuid'],
            ['event_type', 'entered'],
        ]);
});

test('small model relationships keep their intended foreign keys', function () {
    fleetopsUseInMemoryRelationConnection();

    $proof = new Proof();

    expect((new FleetDriver())->fleet()->getForeignKeyName())->toBe('fleet_uuid')
        ->and((new FleetDriver())->driver()->getForeignKeyName())->toBe('driver_uuid')
        ->and((new FleetVehicle())->fleet()->getForeignKeyName())->toBe('fleet_uuid')
        ->and((new FleetVehicle())->vehicle()->getForeignKeyName())->toBe('vehicle_uuid')
        ->and((new VendorPersonnel())->vendor()->getForeignKeyName())->toBe('vendor_uuid')
        ->and((new VendorPersonnel())->contact()->getForeignKeyName())->toBe('contact_uuid')
        ->and((new VendorPersonnel())->invitedBy()->getForeignKeyName())->toBe('invited_by_uuid')
        ->and((new GeofenceEventLog())->driver()->getForeignKeyName())->toBe('driver_uuid')
        ->and((new GeofenceEventLog())->order()->getForeignKeyName())->toBe('order_uuid')
        ->and((new GeofenceEventLog())->vehicle()->getForeignKeyName())->toBe('vehicle_uuid')
        ->and((new FuelProviderConnection())->transactions()->getForeignKeyName())->toBe('fuel_provider_connection_uuid')
        ->and((new FuelProviderConnection())->syncRuns()->getForeignKeyName())->toBe('fuel_provider_connection_uuid')
        ->and($proof->file()->getForeignKeyName())->toBe('file_uuid')
        ->and($proof->order()->getForeignKeyName())->toBe('order_uuid');
});

test('proof file url and subject morph use related model state', function () {
    fleetopsUseInMemoryRelationConnection();

    $proof = new Proof();
    $proof->setRelation('file', (object) ['url' => 'https://cdn.test/proof.jpg']);

    expect($proof->getFileUrlAttribute())->toBe('https://cdn.test/proof.jpg')
        ->and($proof->subject()->getMorphType())->toBe('subject_type')
        ->and($proof->subject()->getForeignKeyName())->toBe('subject_uuid');
});

test('service quote filter applies the session company for internal and public queries', function () {
    $filter = new FleetOpsServiceQuoteFilterProbe();

    $filter->queryForInternal();
    $filter->queryForPublic();

    expect($filter->testBuilder->wheres)->toBe([
        ['company_uuid', 'company-uuid'],
        ['company_uuid', 'company-uuid'],
    ]);
});

test('fleetops parse phone delegates contact and place models to the shared parser', function () {
    FleetOpsParsePhoneProbe::$calls = [];
    $contact                        = new Contact(['phone' => '+15551230000']);
    $place                          = new Place(['phone' => '+15559870000']);

    expect(FleetOpsParsePhoneProbe::fromContact($contact, ['country' => 'US']))->toBe('parsed-phone')
        ->and(FleetOpsParsePhoneProbe::fromPlace($place, [], PhoneNumberFormat::NATIONAL))->toBe('parsed-phone')
        ->and(FleetOpsParsePhoneProbe::$calls)->toBe([
            [$contact, ['country' => 'US'], PhoneNumberFormat::E164],
            [$place, [], PhoneNumberFormat::NATIONAL],
        ]);
});
