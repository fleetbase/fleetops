<?php

if (!function_exists('Fleetbase\FleetOps\Models\session')) {
    eval('namespace Fleetbase\FleetOps\Models; function session($key = null, $default = null) { return $key === "company" ? "company-equipment" : $default; }');
}

if (!function_exists('Fleetbase\FleetOps\Models\auth')) {
    eval('namespace Fleetbase\FleetOps\Models; function auth() { return new class { public function user() { return null; } public function id() { return null; } }; }');
}

if (!function_exists('Fleetbase\FleetOps\Models\activity')) {
    eval('namespace Fleetbase\FleetOps\Models; function activity($logName = null) { return \FleetOpsEquipmentActivityFake::start($logName); }');
}

use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Equipment;
use Fleetbase\FleetOps\Models\Maintenance;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\FleetOps\Models\Warranty;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Models\File;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Sluggable\SlugOptions;

class FleetOpsEquipmentActivityFake
{
    public static array $entries = [];

    public static function start(?string $logName = null): self
    {
        static::$entries[] = ['log_name' => $logName];

        return new self(count(static::$entries) - 1);
    }

    public function __construct(private int $index)
    {
    }

    public function performedOn($subject): self
    {
        static::$entries[$this->index]['subject'] = $subject;

        return $this;
    }

    public function withProperties(array $properties): self
    {
        static::$entries[$this->index]['properties'] = $properties;

        return $this;
    }

    public function log(string $message): bool
    {
        static::$entries[$this->index]['message'] = $message;

        return true;
    }
}

class FleetOpsEquipmentQueryFake
{
    public array $calls = [];

    public function where(...$arguments): self
    {
        $this->calls[] = ['where', $arguments];

        return $this;
    }

    public function whereNotNull(string $column): self
    {
        $this->calls[] = ['whereNotNull', $column];

        return $this;
    }

    public function whereNull(string $column): self
    {
        $this->calls[] = ['whereNull', $column];

        return $this;
    }

    public function orWhereNull(string $column): self
    {
        $this->calls[] = ['orWhereNull', $column];

        return $this;
    }
}

class FleetOpsEquipmentFileFake extends File
{
    public function getAttribute($key)
    {
        return $this->attributes[$key] ?? null;
    }
}

class FleetOpsEquipmentDatabaseProbe
{
    public function __construct(private SQLiteConnection $connection)
    {
    }

    public function connection(): SQLiteConnection
    {
        return $this->connection;
    }
}

class FleetOpsEquipmentUpdatingFake extends Equipment
{
    public array $updates = [];

    public function update(array $attributes = [], array $options = []): bool
    {
        $this->updates[] = $attributes;
        $this->forceFill($attributes);

        return true;
    }
}

class FleetOpsEquipmentMaintenanceRelationFake extends HasMany
{
    public array $wheres = [];
    public array $orders = [];
    public float $sum    = 0.0;

    public function __construct(
        public bool $overdueExists = false,
        public ?Maintenance $completed = null,
    ) {
    }

    public function where($column, $operator = null, $value = null, $boolean = 'and')
    {
        $this->wheres[] = [$column, $operator, $value, $boolean];

        return $this;
    }

    public function orderBy($column, $direction = 'asc')
    {
        $this->orders[] = [$column, $direction];

        return $this;
    }

    public function exists()
    {
        return $this->overdueExists;
    }

    public function first($columns = ['*'])
    {
        return $this->completed;
    }

    public function sum($column)
    {
        $this->wheres[] = ['sum', $column, null, 'and'];

        return $this->sum;
    }
}

class FleetOpsEquipmentMaintenanceFake extends Equipment
{
    public FleetOpsEquipmentMaintenanceRelationFake $maintenanceRelation;

    public function maintenances(): HasMany
    {
        return $this->maintenanceRelation;
    }
}

beforeEach(function () {
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
    $resolver   = new ConnectionResolver([
        'default' => $connection,
        'mysql'   => $connection,
    ]);

    $resolver->setDefaultConnection('mysql');
    EloquentModel::setConnectionResolver($resolver);
    app()->instance('db', new FleetOpsEquipmentDatabaseProbe($connection));

    FleetOpsEquipmentActivityFake::$entries = [];
    Carbon::setTestNow(Carbon::parse('2026-07-27 12:00:00'));
});

afterEach(function () {
    Carbon::setTestNow();
});

test('equipment relationship contracts and options are stable', function () {
    $equipment = new Equipment();

    expect($equipment->getSlugOptions())->toBeInstanceOf(SlugOptions::class)
        ->and($equipment->getActivitylogOptions())->toBeInstanceOf(LogOptions::class)
        ->and($equipment->warranty())->toBeInstanceOf(BelongsTo::class)
        ->and($equipment->warranty()->getRelated())->toBeInstanceOf(Warranty::class)
        ->and($equipment->photo())->toBeInstanceOf(BelongsTo::class)
        ->and($equipment->photo()->getRelated())->toBeInstanceOf(File::class)
        ->and($equipment->createdBy())->toBeInstanceOf(BelongsTo::class)
        ->and($equipment->updatedBy())->toBeInstanceOf(BelongsTo::class)
        ->and($equipment->equipable())->toBeInstanceOf(MorphTo::class)
        ->and($equipment->maintenances())->toBeInstanceOf(HasMany::class)
        ->and($equipment->maintenances()->getRelated())->toBeInstanceOf(Maintenance::class)
        ->and($equipment->maintenances()->getForeignKeyName())->toBe('maintainable_uuid');
});

test('equipment mutators and appended accessors normalize assignment state', function () {
    $warranty = new Warranty();
    $warranty->forceFill(['name' => 'Cold chain warranty', 'is_active' => true]);

    $photo = new FleetOpsEquipmentFileFake();
    $photo->forceFill(['url' => 'https://example.test/equipment.png']);

    $vehicle = new Vehicle();
    $vehicle->forceFill(['name' => 'Reefer Van']);

    $equipment = new Equipment([
        'status'         => 'active',
        'equipable_type' => 'vehicle',
        'equipable_uuid' => 'vehicle-uuid',
        'purchased_at'   => Carbon::now()->subYears(2),
        'purchase_price' => 10000,
        'meta'           => [
            'depreciation_rate' => 0.2,
            'replacement_cost'  => 15000,
        ],
    ]);
    $equipment->setRelation('warranty', $warranty);
    $equipment->setRelation('photo', $photo);
    $equipment->setRelation('equipable', $vehicle);

    expect($equipment->status)->toBe('available')
        ->and($equipment->equipable_type)->toBe(Utils::getMutationType('fleet-ops:vehicle'))
        ->and($equipment->warranty_name)->toBe('Cold chain warranty')
        ->and($equipment->photo_url)->toBe('https://example.test/equipment.png')
        ->and($equipment->equipped_to_name)->toBe('Reefer Van')
        ->and($equipment->is_equipped)->toBeTrue()
        ->and($equipment->age_in_days)->toBeGreaterThanOrEqual(730)
        ->and($equipment->depreciated_value)->toBe(6000.0)
        ->and($equipment->isUnderWarranty())->toBeTrue()
        ->and($equipment->getReplacementCostEstimate())->toBe(15000.0);

    $equipment = new Equipment(['equipable_type' => Driver::class]);
    expect($equipment->equipable_type)->toBe(Driver::class);

    $equipment = new Equipment(['equipable_type' => '']);
    expect($equipment->equipable_type)->toBeNull()
        ->and($equipment->equipped_to_name)->toBeNull()
        ->and($equipment->is_equipped)->toBeFalse()
        ->and($equipment->age_in_days)->toBeNull()
        ->and($equipment->depreciated_value)->toBeNull()
        ->and($equipment->getReplacementCostEstimate())->toBeNull();
});

test('equipment query scopes add expected filters', function () {
    $equipment = new Equipment();
    $query     = new FleetOpsEquipmentQueryFake();

    expect($equipment->scopeByType($query, 'reefer'))->toBe($query)
        ->and($equipment->scopeActive($query))->toBe($query)
        ->and($equipment->scopeEquipped($query))->toBe($query)
        ->and($equipment->scopeUnequipped($query))->toBe($query)
        ->and($equipment->scopeByManufacturer($query, 'Thermo King'))->toBe($query)
        ->and($query->calls)->toBe([
            ['where', ['type', 'reefer']],
            ['where', ['status', 'active']],
            ['whereNotNull', 'equipable_uuid'],
            ['whereNotNull', 'equipable_type'],
            ['whereNull', 'equipable_uuid'],
            ['orWhereNull', 'equipable_type'],
            ['where', ['manufacturer', 'Thermo King']],
        ]);
});

test('equipment assignment helpers update state and record activity payloads', function () {
    $vehicle = new Vehicle();
    $vehicle->forceFill(['uuid' => 'vehicle-uuid', 'name' => 'Van 42']);

    $equipment = new FleetOpsEquipmentUpdatingFake();
    $equipment->forceFill([
        'uuid'           => 'equipment-uuid',
        'equipable_type' => Vehicle::class,
        'equipable_uuid' => 'old-vehicle-uuid',
    ]);

    expect($equipment->equipTo($vehicle))->toBeTrue()
        ->and($equipment->updates[0])->toBe([
            'equipable_type' => Vehicle::class,
            'equipable_uuid' => 'vehicle-uuid',
        ])
        ->and($equipment->unequip())->toBeTrue()
        ->and($equipment->updates[1])->toBe([
            'equipable_type' => null,
            'equipable_uuid' => null,
        ])
        ->and(FleetOpsEquipmentActivityFake::$entries[0]['log_name'])->toBe('equipment_equipped')
        ->and(FleetOpsEquipmentActivityFake::$entries[0]['properties'])->toMatchArray([
            'equipped_to_type' => Vehicle::class,
            'equipped_to_uuid' => 'vehicle-uuid',
            'equipped_to_name' => 'Van 42',
        ])
        ->and(FleetOpsEquipmentActivityFake::$entries[0]['message'])->toBe('Equipment equipped')
        ->and(FleetOpsEquipmentActivityFake::$entries[1]['log_name'])->toBe('equipment_unequipped')
        ->and(FleetOpsEquipmentActivityFake::$entries[1]['properties'])->toBe([
            'previous_equipped_to_type' => Vehicle::class,
            'previous_equipped_to_uuid' => 'vehicle-uuid',
        ])
        ->and(FleetOpsEquipmentActivityFake::$entries[1]['message'])->toBe('Equipment unequipped');
});

test('equipment maintenance helpers evaluate overdue interval utilization and costs', function () {
    $equipment = new FleetOpsEquipmentMaintenanceFake([
        'equipable_type' => Vehicle::class,
        'equipable_uuid' => 'vehicle-uuid',
        'purchased_at'   => Carbon::now()->subDays(90),
        'meta'           => ['maintenance_interval_days' => 30],
    ]);

    $equipment->maintenanceRelation = new FleetOpsEquipmentMaintenanceRelationFake(overdueExists: true);

    expect($equipment->needsMaintenance())->toBeTrue()
        ->and($equipment->maintenanceRelation->wheres[0][0])->toBe('status')
        ->and($equipment->getUtilizationRate())->toBe(75.0);

    $completed = new Maintenance();
    $completed->forceFill(['completed_at' => Carbon::now()->subDays(45)]);

    $equipment->maintenanceRelation = new FleetOpsEquipmentMaintenanceRelationFake(completed: $completed);

    expect($equipment->needsMaintenance())->toBeTrue()
        ->and($equipment->maintenanceRelation->orders)->toBe([['completed_at', 'desc']]);

    $equipment->maintenanceRelation      = new FleetOpsEquipmentMaintenanceRelationFake();
    $equipment->maintenanceRelation->sum = 123.45;

    expect($equipment->getMaintenanceCost(60))->toBe(123.45)
        ->and($equipment->maintenanceRelation->wheres[0][0])->toBe('status')
        ->and($equipment->maintenanceRelation->wheres[1][0])->toBe('completed_at');

    $equipment                      = new FleetOpsEquipmentMaintenanceFake();
    $equipment->maintenanceRelation = new FleetOpsEquipmentMaintenanceRelationFake();

    expect($equipment->needsMaintenance())->toBeFalse()
        ->and($equipment->getUtilizationRate())->toBe(0.0);
});

test('equipment replacement estimates and import rows hydrate defaults', function () {
    $equipment = new Equipment([
        'purchase_price' => 10000,
        'purchased_at'   => Carbon::now()->subYears(2),
        'meta'           => ['inflation_rate' => 0.05],
    ]);

    expect(round($equipment->getReplacementCostEstimate(), 2))->toBe(11025.0);

    $equipment = Equipment::createFromImport([
        'name'            => 'Liftgate',
        'internal_id'     => 'LG-100',
        'serial'          => 'SERIAL-100',
        'brand'           => 'Fleet Gear',
        'equipment_model' => 'Heavy Lift',
        'cost'            => 450000,
        'currency'        => 'sgd',
        'purchase_date'   => '2025-05-01',
    ]);

    expect($equipment->company_uuid)->toBe('company-equipment')
        ->and($equipment->name)->toBe('Liftgate')
        ->and($equipment->code)->toBe('LG-100')
        ->and($equipment->type)->toBe('equipment')
        ->and($equipment->status)->toBe('operational')
        ->and($equipment->serial_number)->toBe('SERIAL-100')
        ->and($equipment->manufacturer)->toBe('Fleet Gear')
        ->and($equipment->model)->toBe('Heavy Lift')
        ->and($equipment->currency)->toBe('SGD')
        ->and($equipment->purchased_at->toDateString())->toBe('2025-05-01');
});

test('equipable types match maintenance scheduling and warranty imports resolve', function () {
    $connection = app('db')->connection();
    $schema     = $connection->getSchemaBuilder();
    foreach (['maintenances' => ['uuid', 'public_id', 'company_uuid', 'maintainable_type', 'maintainable_uuid', 'type', 'status', 'scheduled_at', 'summary', 'notes', 'created_by_uuid', '_key'], 'warranties' => ['uuid', 'public_id', 'company_uuid', 'name', 'provider', 'expires_at', '_key'], 'equipments' => ['uuid', 'public_id', 'company_uuid', 'name', 'internal_id', 'code', 'type', 'status', 'serial_number', 'manufacturer', 'model', 'purchase_price', 'currency', 'purchased_at', 'warranty_uuid', 'equipable_type', 'equipable_uuid', '_key']] as $table => $columns) {
        $schema->create($table, function ($blueprint) use ($columns) {
            $blueprint->increments('id');
            foreach ($columns as $column) {
                $blueprint->string($column)->nullable();
            }
            $blueprint->timestamps();
            $blueprint->timestamp('deleted_at')->nullable();
        });
    }

    // Equipable type mutator maps known aliases through mutation types
    $equipment                 = new Equipment();
    $equipment->equipable_type = 'vehicle';
    expect($equipment->getAttributes()['equipable_type'])->toContain('Vehicle');
    $equipment->equipable_type = 'fleet-ops:driver';
    expect($equipment->getAttributes()['equipable_type'])->toContain('Driver');
    $equipment->equipable_type = 'Custom\\Type';
    expect($equipment->getAttributes()['equipable_type'])->toBe('Custom\\Type');

    // Scheduling maintenance persists a scheduled row against the equipment
    $equipment->setRawAttributes(['uuid' => 'equip-sched-1', 'company_uuid' => 'company-equipment'], true);
    $maintenance = $equipment->scheduleMaintenance('inspection', new DateTime('2026-08-01 10:00:00'), ['summary' => 'Quarterly check']);
    expect($connection->table('maintenances')->where('type', 'inspection')->count())->toBe(1)
        ->and($connection->table('maintenances')->value('summary'))->toBe('Quarterly check');

    // Import rows resolve warranties by fuzzy name match
    $connection->table('warranties')->insert(['uuid' => 'warranty-1', 'company_uuid' => 'company-equipment', 'name' => 'Extended Coverage Plan']);
    $imported = Equipment::createFromImport([
        'name'     => 'Imported Lift',
        'warranty' => 'Extended Coverage',
    ], true);
    expect($imported->warranty_uuid)->toBe('warranty-1')
        ->and($connection->table('equipments')->where('name', 'Imported Lift')->count())->toBe(1);
});
