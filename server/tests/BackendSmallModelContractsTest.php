<?php

use Fleetbase\FleetOps\Http\Filter\ServiceQuoteFilter;
use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\FleetOps\Models\Customer;
use Fleetbase\FleetOps\Models\Equipment;
use Fleetbase\FleetOps\Models\FleetDriver;
use Fleetbase\FleetOps\Models\FleetVehicle;
use Fleetbase\FleetOps\Models\FuelProviderConnection;
use Fleetbase\FleetOps\Models\FuelProviderSyncRun;
use Fleetbase\FleetOps\Models\GeofenceEventLog;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Models\Proof;
use Fleetbase\FleetOps\Models\ServiceRateParcelFee;
use Fleetbase\FleetOps\Models\VehicleDeviceEvent;
use Fleetbase\FleetOps\Models\VendorPersonnel;
use Fleetbase\FleetOps\Support\ParsePhone;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Carbon;
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

    public function whereNotNull(string $column): self
    {
        $this->wheres[] = ['whereNotNull', $column];

        return $this;
    }

    public function whereNull(string $column): self
    {
        $this->wheres[] = ['whereNull', $column];

        return $this;
    }

    public function orWhereNull(string $column): self
    {
        $this->wheres[] = ['orWhereNull', $column];

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
        ->and((new FuelProviderSyncRun())->connection()->getForeignKeyName())->toBe('fuel_provider_connection_uuid')
        ->and((new VehicleDeviceEvent())->device()->getForeignKeyName())->toBe('vehicle_device_uuid')
        ->and($proof->file()->getForeignKeyName())->toBe('file_uuid')
        ->and($proof->order()->getForeignKeyName())->toBe('order_uuid');
});

test('equipment model exposes relationship scope and pure accessor contracts', function () {
    fleetopsUseInMemoryRelationConnection();
    Carbon::setTestNow(Carbon::parse('2026-07-26 12:00:00'));

    $equipment = new Equipment();
    $equipment->setRawAttributes([
        'uuid'           => 'equipment-uuid',
        'name'           => 'Forklift battery',
        'status'         => 'available',
        'equipable_type' => 'fleet-ops:vehicle',
        'equipable_uuid' => 'vehicle-uuid',
        'purchased_at'   => Carbon::parse('2024-07-26 12:00:00'),
        'purchase_price' => 1000,
        'meta'           => [
            'depreciation_rate'         => 0.25,
            'replacement_cost'          => 1500.0,
            'maintenance_interval_days' => 90,
        ],
    ], true);
    $equipment->setRelation('warranty', (object) ['name' => 'Battery Warranty', 'is_active' => true]);
    $equipment->setRelation('photo', (object) ['url' => 'https://cdn.test/equipment.png']);
    $equipment->setRelation('equipable', (object) ['display_name' => 'Truck 12']);

    $typeBuilder         = new FleetOpsScopeBuilderFake();
    $activeBuilder       = new FleetOpsScopeBuilderFake();
    $manufacturerBuilder = new FleetOpsScopeBuilderFake();
    $equippedBuilder     = new FleetOpsScopeBuilderFake();
    $unequippedBuilder   = new FleetOpsScopeBuilderFake();

    expect($equipment->warranty())->toBeInstanceOf(BelongsTo::class)
        ->and($equipment->photo())->toBeInstanceOf(BelongsTo::class)
        ->and($equipment->createdBy())->toBeInstanceOf(BelongsTo::class)
        ->and($equipment->updatedBy())->toBeInstanceOf(BelongsTo::class)
        ->and($equipment->maintenances())->toBeInstanceOf(HasMany::class)
        ->and($equipment->equipable())->toBeInstanceOf(MorphTo::class)
        ->and($equipment->warranty_name)->toBe('Battery Warranty')
        ->and($equipment->photo_url)->toBe('https://cdn.test/equipment.png')
        ->and($equipment->equipped_to_name)->toBe('Truck 12')
        ->and($equipment->is_equipped)->toBeTrue()
        ->and($equipment->age_in_days)->toBe(730)
        ->and($equipment->depreciated_value)->toBe(500.0)
        ->and($equipment->getUtilizationRate())->toBe(75.0)
        ->and($equipment->isUnderWarranty())->toBeTrue()
        ->and($equipment->getReplacementCostEstimate())->toBe(1500.0)
        ->and($equipment->scopeByType($typeBuilder, 'battery'))->toBe($typeBuilder)
        ->and($equipment->scopeActive($activeBuilder))->toBe($activeBuilder)
        ->and($equipment->scopeByManufacturer($manufacturerBuilder, 'Acme'))->toBe($manufacturerBuilder)
        ->and($equipment->scopeEquipped($equippedBuilder))->toBe($equippedBuilder)
        ->and($equipment->scopeUnequipped($unequippedBuilder))->toBe($unequippedBuilder)
        ->and($typeBuilder->wheres)->toBe([['type', 'battery']])
        ->and($activeBuilder->wheres)->toBe([['status', 'active']])
        ->and($manufacturerBuilder->wheres)->toBe([['manufacturer', 'Acme']])
        ->and($equippedBuilder->wheres)->toBe([
            ['whereNotNull', 'equipable_uuid'],
            ['whereNotNull', 'equipable_type'],
        ])
        ->and($unequippedBuilder->wheres)->toBe([
            ['whereNull', 'equipable_uuid'],
            ['orWhereNull', 'equipable_type'],
        ]);

    $imported = Equipment::createFromImport([
        'name'            => 'Safety Kit',
        'internal_id'     => 'KIT-1',
        'serial'          => 'SER-1',
        'make'            => 'Acme',
        'equipment_model' => 'K-100',
        'price'           => 2500,
        'currency'        => 'sgd',
        'purchase_date'   => '2026-01-15',
    ]);

    expect($imported->name)->toBe('Safety Kit')
        ->and($imported->code)->toBe('KIT-1')
        ->and($imported->type)->toBe('equipment')
        ->and($imported->status)->toBe('operational')
        ->and($imported->serial_number)->toBe('SER-1')
        ->and($imported->manufacturer)->toBe('Acme')
        ->and($imported->model)->toBe('K-100')
        ->and($imported->currency)->toBe('SGD')
        ->and($imported->purchased_at->toDateString())->toBe('2026-01-15');

    Carbon::setTestNow();
});

test('fuel sync run and vehicle device event metadata stays aligned with storage contracts', function () {
    $syncRun = new FuelProviderSyncRun();
    $event   = new VehicleDeviceEvent();

    expect($syncRun->getTable())->toBe('fuel_provider_sync_runs')
        ->and($syncRun->getFillable())->toContain(
            'company_uuid',
            'fuel_provider_connection_uuid',
            'provider',
            'status',
            'summary',
            'meta'
        )
        ->and($syncRun->getCasts())->toHaveKeys(['from', 'to', 'started_at', 'finished_at', 'summary', 'meta'])
        ->and($event->getTable())->toBe('device_events')
        ->and($event->getFillable())->toContain(
            'vehicle_device_uuid',
            'payload',
            'meta',
            'location',
            'ident',
            'provider'
        )
        ->and($event->getCasts())->toHaveKeys(['payload', 'meta', 'location']);
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
