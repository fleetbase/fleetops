<?php

if (!function_exists('Fleetbase\FleetOps\Models\session')) {
    eval('namespace Fleetbase\FleetOps\Models; function session($key = null, $default = null) { return $key === "company" ? "company-vehicle" : $default; }');
}

if (!function_exists('Fleetbase\Traits\config')) {
    eval('namespace Fleetbase\Traits; function config($key = null, $default = null) { return $key === "api.cache.enabled" ? false : $default; }');
}

if (!function_exists('Fleetbase\Models\config')) {
    eval('namespace Fleetbase\Models; function config($key = null, $default = null) { return $key === "fleetbase.connection.db" ? "mysql" : $default; }');
}

if (!function_exists('Fleetbase\FleetOps\Support\base_path')) {
    eval('namespace Fleetbase\FleetOps\Support; function base_path($path = "") { return getcwd() . "/" . str_replace("vendor/fleetbase/fleetops-api/", "", $path); }');
}

use Fleetbase\FleetOps\Models\Device;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Equipment;
use Fleetbase\FleetOps\Models\Fleet;
use Fleetbase\FleetOps\Models\Maintenance;
use Fleetbase\FleetOps\Models\Part;
use Fleetbase\FleetOps\Models\Position;
use Fleetbase\FleetOps\Models\Sensor;
use Fleetbase\FleetOps\Models\Telematic;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\FleetOps\Models\Vendor;
use Fleetbase\FleetOps\Models\Warranty;
use Fleetbase\Models\Category;
use Fleetbase\Models\File;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\SQLiteConnection;

class FleetOpsVehicleModelDatabaseProbe
{
    public function __construct(private SQLiteConnection $connection)
    {
    }

    public function connection(): SQLiteConnection
    {
        return $this->connection;
    }

    public function raw(string $value)
    {
        return $this->connection->raw($value);
    }
}

class FleetOpsVehicleModelAssignableDriverFake extends Driver
{
    public ?Vehicle $assignedVehicle = null;

    public function assignVehicle(Vehicle $vehicle): Driver
    {
        $this->assignedVehicle = $vehicle;

        return $this;
    }
}

class FleetOpsVehicleModelFindQueryFake
{
    public array $calls = [];

    public function where(...$arguments): static
    {
        $this->calls[] = ['where', $arguments];

        return $this;
    }

    public function orWhere(...$arguments): static
    {
        $this->calls[] = ['orWhere', $arguments];

        return $this;
    }

    public function orWhereRaw(string $sql, array $bindings = []): static
    {
        $this->calls[] = ['orWhereRaw', $sql, $bindings];

        return $this;
    }
}

class FleetOpsVehicleModelFindResultFake
{
    public function __construct(public ?Vehicle $result, public FleetOpsVehicleModelFindQueryFake $query)
    {
    }

    public function first(): ?Vehicle
    {
        return $this->result;
    }
}

class FleetOpsVehicleModelFindableFake extends Vehicle
{
    public static ?FleetOpsVehicleModelFindQueryFake $lastQuery = null;
    public static ?Vehicle $result                              = null;

    public static function where($column, $operator = null, $value = null, $boolean = 'and')
    {
        $query = new FleetOpsVehicleModelFindQueryFake();

        if (is_callable($column)) {
            $column($query);
        }

        self::$lastQuery = $query;

        return new FleetOpsVehicleModelFindResultFake(self::$result, $query);
    }
}

function fleetopsVehicleModelUseInMemoryConnection(): SQLiteConnection
{
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'));

    $resolver = new ConnectionResolver([
        'default' => $connection,
        'mysql'   => $connection,
    ]);
    $resolver->setDefaultConnection('mysql');
    EloquentModel::setConnectionResolver($resolver);
    app()->instance('db', new FleetOpsVehicleModelDatabaseProbe($connection));
    app()->instance('db.schema', $connection->getSchemaBuilder());

    $schema = $connection->getSchemaBuilder();
    $schema->create('drivers', function ($table) {
        $table->increments('id');
        $table->string('user_uuid')->nullable();
        $table->string('vehicle_uuid')->nullable();
        $table->timestamp('created_at')->nullable();
        $table->timestamp('updated_at')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('users', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('files', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('url')->nullable();
        $table->string('type')->nullable();
        $table->string('original_filename')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });

    return $connection;
}

test('vehicle model exposes relation builders and options', function () {
    fleetopsVehicleModelUseInMemoryConnection();

    $vehicle = new Vehicle();

    expect($vehicle->getActivitylogOptions()->logOnlyDirty)->toBeTrue()
        ->and($vehicle->getSlugOptions()->generateSlugFrom)->toBe(['year', 'make', 'model', 'trim', 'plate_number'])
        ->and($vehicle->getSlugOptions()->slugField)->toBe('slug')
        ->and($vehicle->photo())->toBeInstanceOf(BelongsTo::class)
        ->and($vehicle->photo()->getRelated())->toBeInstanceOf(File::class)
        ->and($vehicle->driver())->toBeInstanceOf(HasOne::class)
        ->and($vehicle->driver()->getRelated())->toBeInstanceOf(Driver::class)
        ->and($vehicle->category())->toBeInstanceOf(BelongsTo::class)
        ->and($vehicle->category()->getRelated())->toBeInstanceOf(Category::class)
        ->and($vehicle->telematic())->toBeInstanceOf(BelongsTo::class)
        ->and($vehicle->telematic()->getRelated())->toBeInstanceOf(Telematic::class)
        ->and($vehicle->warranty())->toBeInstanceOf(BelongsTo::class)
        ->and($vehicle->warranty()->getRelated())->toBeInstanceOf(Warranty::class)
        ->and($vehicle->vendor())->toBeInstanceOf(BelongsTo::class)
        ->and($vehicle->vendor()->getRelated())->toBeInstanceOf(Vendor::class)
        ->and($vehicle->fleets())->toBeInstanceOf(HasManyThrough::class)
        ->and($vehicle->fleets()->getRelated())->toBeInstanceOf(Fleet::class)
        ->and($vehicle->devices())->toBeInstanceOf(HasMany::class)
        ->and($vehicle->devices()->getRelated())->toBeInstanceOf(Device::class)
        ->and($vehicle->positions())->toBeInstanceOf(HasMany::class)
        ->and($vehicle->positions()->getRelated())->toBeInstanceOf(Position::class)
        ->and($vehicle->equipments())->toBeInstanceOf(HasMany::class)
        ->and($vehicle->equipments()->getRelated())->toBeInstanceOf(Equipment::class)
        ->and($vehicle->maintenances())->toBeInstanceOf(HasMany::class)
        ->and($vehicle->maintenances()->getRelated())->toBeInstanceOf(Maintenance::class)
        ->and($vehicle->sensors())->toBeInstanceOf(HasMany::class)
        ->and($vehicle->sensors()->getRelated())->toBeInstanceOf(Sensor::class)
        ->and($vehicle->parts())->toBeInstanceOf(MorphMany::class)
        ->and($vehicle->parts()->getRelated())->toBeInstanceOf(Part::class);
});

test('vehicle avatar helpers merge custom and bundled options', function () {
    $connection = fleetopsVehicleModelUseInMemoryConnection();
    $connection->table('files')->insert([
        'uuid'              => 'avatar-uuid',
        'url'               => 'https://cdn.test/custom.png',
        'type'              => 'vehicle-avatar',
        'original_filename' => 'reefer.png',
    ]);

    $options = Vehicle::getAvatarOptions(function ($query) {
        $query->where('uuid', 'avatar-uuid');
    });

    expect($options->get('Custom: reefer'))->toBe('avatar-uuid')
        ->and($options->has('mini_bus'))->toBeTrue()
        ->and(Vehicle::getAvatar('mini_bus'))->toBe($options->get('mini_bus'))
        ->and(Vehicle::getAvatar('11111111-1111-4111-8111-111111111111'))->toBeNull()
        ->and((new Vehicle())->getAvatarUrlAttribute(''))->toBe($options->get('mini_bus'));
});

test('vehicle driver assignment helpers update assigned state', function () {
    $connection = fleetopsVehicleModelUseInMemoryConnection();
    $connection->table('users')->insert(['uuid' => 'user-uuid']);
    $connection->table('drivers')->insert(['user_uuid' => 'user-uuid', 'vehicle_uuid' => 'vehicle-uuid']);

    $vehicle = new Vehicle();
    $vehicle->setRawAttributes(['uuid' => 'vehicle-uuid'], true);

    $driver = new FleetOpsVehicleModelAssignableDriverFake();

    expect($vehicle->assignDriver($driver))->toBe($vehicle)
        ->and($driver->assignedVehicle)->toBe($vehicle);

    $vehicle->setRelation('driver', $driver);

    expect($vehicle->unassignDriver())->toBe($vehicle)
        ->and($vehicle->relationLoaded('driver'))->toBeFalse()
        ->and($connection->table('drivers')->where('vehicle_uuid', 'vehicle-uuid')->count())->toBe(0)
        ->and($connection->table('drivers')->whereNull('vehicle_uuid')->count())->toBe(1);
});

test('vehicle import parses single vehicle name and find by name builds identifier query', function () {
    $vehicle = Vehicle::createFromImport([
        'vehicle_name' => '2018 Ford Transit refrigerated van',
    ]);

    expect($vehicle->exists)->toBeFalse()
        ->and($vehicle->company_uuid)->toBe('company-vehicle')
        ->and($vehicle->make)->toBe('Ford')
        ->and($vehicle->model)->toBe('TRANSIT')
        ->and($vehicle->year)->toBe('2018')
        ->and($vehicle->type)->toBe('vehicle')
        ->and(Vehicle::findByName())->toBeNull();

    $found                                    = new Vehicle();
    FleetOpsVehicleModelFindableFake::$result = $found;

    expect(FleetOpsVehicleModelFindableFake::findByName('ABC-123'))->toBe($found)
        ->and(FleetOpsVehicleModelFindableFake::$lastQuery->calls)->toContain(['where', ['public_id', 'ABC-123']])
        ->and(FleetOpsVehicleModelFindableFake::$lastQuery->calls)->toContain(['orWhere', ['plate_number', 'ABC-123']])
        ->and(FleetOpsVehicleModelFindableFake::$lastQuery->calls)->toContain(['orWhere', ['fuel_card_number', 'ABC-123']])
        ->and(FleetOpsVehicleModelFindableFake::$lastQuery->calls[7][0])->toBe('orWhereRaw')
        ->and(FleetOpsVehicleModelFindableFake::$lastQuery->calls[8][0])->toBe('orWhereRaw');
});
