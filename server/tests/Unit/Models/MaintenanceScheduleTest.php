<?php

use Fleetbase\FleetOps\Models\Equipment;
use Fleetbase\FleetOps\Models\MaintenanceSchedule;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\FleetOps\Models\Vendor;
use Fleetbase\FleetOps\Models\WorkOrder;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Carbon;

class FleetOpsMaintenanceScheduleImportProbe extends MaintenanceSchedule
{
    public static ?Vehicle $vehicle     = null;
    public static ?Equipment $equipment = null;
    public static ?Vendor $vendor       = null;
    public static array $lookups        = [];
    public array $saves                 = [];

    public static function resetProbe(): void
    {
        static::$vehicle   = null;
        static::$equipment = null;
        static::$vendor    = null;
        static::$lookups   = [];
    }

    public function save(array $options = []): bool
    {
        $this->saves[] = $options;

        return true;
    }

    protected static function findImportVehicle(string $vehicleName): ?Vehicle
    {
        static::$lookups[] = ['vehicle', $vehicleName, session('company')];

        return static::$vehicle;
    }

    protected static function findImportEquipment(string $vehicleName): ?Equipment
    {
        static::$lookups[] = ['equipment', $vehicleName, session('company')];

        return static::$equipment;
    }

    protected static function findImportVendor(string $vendorName): ?Vendor
    {
        static::$lookups[] = ['vendor', $vendorName, session('company')];

        return static::$vendor;
    }
}

class FleetOpsMaintenanceScheduleUpdatingFake extends MaintenanceSchedule
{
    public array $updates = [];

    public function getDateFormat()
    {
        return 'Y-m-d H:i:s';
    }

    public function update(array $attributes = [], array $options = []): bool
    {
        $this->updates[] = $attributes;
        $this->forceFill($attributes);

        return true;
    }
}

function fleetopsMaintenanceScheduleUseRelationConnection(): void
{
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
    $resolver   = new ConnectionResolver([
        'default' => $connection,
        'mysql'   => $connection,
    ]);

    $resolver->setDefaultConnection('mysql');
    EloquentModel::setConnectionResolver($resolver);
}

function fleetopsMaintenanceScheduleVehicle(string $uuid = 'vehicle-uuid'): Vehicle
{
    $vehicle = new Vehicle();
    $vehicle->setRawAttributes([
        'uuid'      => $uuid,
        'public_id' => 'vehicle_public',
        'name'      => 'Truck 9',
    ], true);
    $vehicle->setAppends([]);

    return $vehicle;
}

function fleetopsMaintenanceScheduleEquipment(string $uuid = 'equipment-uuid'): Equipment
{
    $equipment = new Equipment();
    $equipment->setRawAttributes([
        'uuid'      => $uuid,
        'public_id' => 'equipment_public',
        'name'      => 'Forklift 2',
    ], true);
    $equipment->setAppends([]);

    return $equipment;
}

function fleetopsMaintenanceScheduleVendor(string $uuid = 'vendor-uuid'): Vendor
{
    $vendor = new Vendor();
    $vendor->setRawAttributes([
        'uuid' => $uuid,
        'name' => 'Vendor Ops',
    ], true);
    $vendor->setAppends([]);

    return $vendor;
}

test('maintenance schedule relationship contracts resolve expected relation types', function () {
    fleetopsMaintenanceScheduleUseRelationConnection();

    $schedule = new MaintenanceSchedule();

    expect($schedule->subject())->toBeInstanceOf(MorphTo::class)
        ->and($schedule->defaultAssignee())->toBeInstanceOf(MorphTo::class)
        ->and($schedule->workOrders())->toBeInstanceOf(HasMany::class)
        ->and($schedule->workOrders()->getRelated())->toBeInstanceOf(WorkOrder::class);
});

test('maintenance schedule due checks cover inactive and threshold branches', function () {
    $schedule = new MaintenanceSchedule([
        'status'                => 'active',
        'next_due_date'         => Carbon::parse('2026-02-01'),
        'next_due_odometer'     => 15000,
        'next_due_engine_hours' => 600,
    ]);

    expect($schedule->isDue(null, null, Carbon::parse('2026-01-15')))->toBeFalse()
        ->and($schedule->isDue(null, null, Carbon::parse('2026-02-01')))->toBeTrue()
        ->and($schedule->isDue(15000, null, Carbon::parse('2026-01-15')))->toBeTrue()
        ->and($schedule->isDue(null, 600, Carbon::parse('2026-01-15')))->toBeTrue();

    $schedule->status = 'paused';

    expect($schedule->isDue(20000, 900, Carbon::parse('2026-03-01')))->toBeFalse();
});

test('maintenance schedule reset uses existing readings when completion readings are omitted', function () {
    Carbon::setTestNow(Carbon::parse('2026-03-10 10:00:00'));

    $schedule = new FleetOpsMaintenanceScheduleUpdatingFake([
        'last_service_odometer'      => 11000,
        'last_service_engine_hours'  => 450,
        'interval_value'             => null,
        'interval_unit'              => null,
        'interval_distance'          => 5000,
        'interval_engine_hours'      => 250,
    ]);

    expect($schedule->resetAfterCompletion())->toBeTrue()
        ->and($schedule->updates[0]['last_service_date']->toDateTimeString())->toBe('2026-03-10 10:00:00')
        ->and($schedule->updates[0]['last_service_odometer'])->toBe(11000)
        ->and($schedule->updates[0]['last_service_engine_hours'])->toBe(450)
        ->and($schedule->pause())->toBeTrue()
        ->and($schedule->resume())->toBeTrue()
        ->and($schedule->complete())->toBeTrue()
        ->and($schedule->updates[0])->not->toHaveKey('status')
        ->and($schedule->updates[1]['status'])->toBe('paused')
        ->and($schedule->updates[2]['status'])->toBe('active')
        ->and($schedule->updates[3]['status'])->toBe('completed');

    Carbon::setTestNow();
});

test('maintenance schedule import maps vehicle vendor defaults and saves when requested', function () {
    session(['company' => 'company-uuid']);

    FleetOpsMaintenanceScheduleImportProbe::resetProbe();
    FleetOpsMaintenanceScheduleImportProbe::$vehicle = fleetopsMaintenanceScheduleVehicle();
    FleetOpsMaintenanceScheduleImportProbe::$vendor  = fleetopsMaintenanceScheduleVendor();

    $schedule = FleetOpsMaintenanceScheduleImportProbe::createFromImport([
        'schedule_name'              => 'Quarterly service',
        'schedule_type'              => 'inspection',
        'status'                     => 'paused',
        'interval_method'            => 'meter',
        'interval_type'              => 'recurring',
        'interval_value'             => '90',
        'interval_unit'              => 'days',
        'interval_distance'          => '5000',
        'interval_engine_hours'      => '250',
        'last_service_odometer'      => '10000',
        'last_service_engine_hours'  => '400',
        'last_service_date'          => '2026-01-01',
        'next_due_date'              => '2026-04-01',
        'next_due_odometer'          => '15000',
        'next_due_engine_hours'      => '650',
        'default_priority'           => 'high',
        'description'                => 'Inspect brakes',
        'vehicle_name'               => 'Truck 9',
        'vendor_name'                => 'Vendor Ops',
    ], true);

    expect($schedule)->toBeInstanceOf(FleetOpsMaintenanceScheduleImportProbe::class)
        ->and($schedule->company_uuid)->toBe('company-uuid')
        ->and($schedule->name)->toBe('Quarterly service')
        ->and($schedule->type)->toBe('inspection')
        ->and($schedule->status)->toBe('paused')
        ->and($schedule->interval_value)->toBe(90)
        ->and($schedule->interval_distance)->toBe(5000.0)
        ->and($schedule->interval_engine_hours)->toBe(250.0)
        ->and($schedule->last_service_date->toDateString())->toBe('2026-01-01')
        ->and($schedule->next_due_date->toDateString())->toBe('2026-04-01')
        ->and($schedule->next_due_odometer)->toBe(15000.0)
        ->and($schedule->next_due_engine_hours)->toBe(650.0)
        ->and($schedule->default_priority)->toBe('high')
        ->and($schedule->instructions)->toBe('Inspect brakes')
        ->and($schedule->subject_type)->toBe(Vehicle::class)
        ->and($schedule->subject_uuid)->toBe('vehicle-uuid')
        ->and($schedule->default_assignee_type)->toBe(Vendor::class)
        ->and($schedule->default_assignee_uuid)->toBe('vendor-uuid')
        ->and($schedule->saves)->toBe([[]])
        ->and(FleetOpsMaintenanceScheduleImportProbe::$lookups)->toBe([
            ['vehicle', 'Truck 9', 'company-uuid'],
            ['vendor', 'Vendor Ops', 'company-uuid'],
        ]);
});

test('maintenance schedule import falls back to equipment and default values', function () {
    session(['company' => 'company-uuid']);

    FleetOpsMaintenanceScheduleImportProbe::resetProbe();
    FleetOpsMaintenanceScheduleImportProbe::$equipment = fleetopsMaintenanceScheduleEquipment();

    $schedule = FleetOpsMaintenanceScheduleImportProbe::createFromImport([
        'name'              => 'Equipment service',
        'asset'             => 'Forklift 2',
        'default_assignee'  => 'Missing Vendor',
    ]);

    expect($schedule->type)->toBe('preventive')
        ->and($schedule->status)->toBe('active')
        ->and($schedule->interval_method)->toBe('time')
        ->and($schedule->interval_type)->toBe('recurring')
        ->and($schedule->interval_unit)->toBe('days')
        ->and($schedule->default_priority)->toBe('normal')
        ->and($schedule->subject_type)->toBe(Equipment::class)
        ->and($schedule->subject_uuid)->toBe('equipment-uuid')
        ->and($schedule->default_assignee_type)->toBeNull()
        ->and($schedule->default_assignee_uuid)->toBeNull()
        ->and($schedule->saves)->toBe([])
        ->and(FleetOpsMaintenanceScheduleImportProbe::$lookups)->toBe([
            ['vehicle', 'Forklift 2', 'company-uuid'],
            ['equipment', 'Forklift 2', 'company-uuid'],
            ['vendor', 'Missing Vendor', 'company-uuid'],
        ]);
});
