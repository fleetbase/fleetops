<?php

use Fleetbase\FleetOps\Http\Controllers\Internal\v1\LiveController;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\FleetOps\Support\LiveCacheService;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

if (!class_exists('Illuminate\Foundation\Auth\User')) {
    class_alias(EloquentModel::class, 'Illuminate\Foundation\Auth\User');
}

class FleetOpsLiveQueryRecorder
{
    public array $calls = [];

    public function whereNotNull(string $column): self
    {
        $this->calls[] = ['whereNotNull', $column];

        return $this;
    }

    public function whereRaw(string $sql, array $bindings = []): self
    {
        $this->calls[] = ['whereRaw', trim($sql), $bindings];

        return $this;
    }
}

class FleetOpsLiveMonitorDriverFake extends Driver
{
    public function getAttribute($key)
    {
        return $this->attributes[$key] ?? null;
    }
}

class FleetOpsLiveMonitorVehicleFake extends Vehicle
{
    public function getAttribute($key)
    {
        return $this->attributes[$key] ?? null;
    }
}

class FleetOpsLiveControllerTaggedCacheFake
{
    public array $rememberCalls = [];

    public function remember(string $key, int $ttl, Closure $callback): mixed
    {
        $this->rememberCalls[] = compact('key', 'ttl');

        return $callback();
    }

    public function flush(): bool
    {
        return true;
    }
}

class FleetOpsLiveControllerCacheFake
{
    public FleetOpsLiveControllerTaggedCacheFake $tagged;

    public array $tagCalls = [];

    public function __construct()
    {
        $this->tagged = new FleetOpsLiveControllerTaggedCacheFake();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $default;
    }

    public function increment(string $key): int
    {
        return 1;
    }

    public function tags(array $tags): FleetOpsLiveControllerTaggedCacheFake
    {
        $this->tagCalls[] = $tags;

        return $this->tagged;
    }
}

class FleetOpsLiveControllerDatabaseProbe
{
    public function __construct(private SQLiteConnection $connection)
    {
    }

    public function table(string $table)
    {
        return $this->connection->table($table);
    }

    public function connection(): SQLiteConnection
    {
        return $this->connection;
    }

    public function raw(mixed $value): mixed
    {
        return $this->connection->raw($value);
    }

    public function transaction(callable $callback): mixed
    {
        return $callback();
    }
}

function callLiveControllerMethod(string $method, array $arguments = [])
{
    $reflection = new ReflectionMethod(LiveController::class, $method);
    $reflection->setAccessible(true);

    return $reflection->invokeArgs(new LiveController(), $arguments);
}

function fleetopsLiveControllerUseMonitorDatabase(): SQLiteConnection
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->sqliteCreateFunction('CONCAT', fn (...$values) => implode('', $values));

    $connection = new SQLiteConnection($pdo);
    $resolver   = new ConnectionResolver([
        'default' => $connection,
        'mysql'   => $connection,
    ]);

    $resolver->setDefaultConnection('mysql');
    EloquentModel::setConnectionResolver($resolver);
    app()->instance('db', new FleetOpsLiveControllerDatabaseProbe($connection));

    $schema = $connection->getSchemaBuilder();
    $schema->create('users', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('avatar_uuid')->nullable();
        $table->string('name')->nullable();
        $table->string('phone')->nullable();
        $table->string('email')->nullable();
        $table->string('type')->nullable();
        $table->string('status')->nullable();
        $table->timestamp('last_login')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('drivers', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('user_uuid')->nullable();
        $table->string('vehicle_uuid')->nullable();
        $table->string('vendor_uuid')->nullable();
        $table->string('current_job_uuid')->nullable();
        $table->string('status')->nullable();
        $table->text('location')->nullable();
        $table->integer('heading')->nullable();
        $table->integer('altitude')->nullable();
        $table->integer('speed')->nullable();
        $table->boolean('online')->nullable();
        $table->timestamp('created_at')->nullable();
        $table->timestamp('updated_at')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('vehicles', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('vendor_uuid')->nullable();
        $table->string('photo_uuid')->nullable();
        $table->string('avatar_url')->nullable();
        $table->string('internal_id')->nullable();
        $table->string('name')->nullable();
        $table->string('display_name')->nullable();
        $table->string('class')->nullable();
        $table->string('color')->nullable();
        $table->string('call_sign')->nullable();
        $table->string('trim')->nullable();
        $table->string('plate_number')->nullable();
        $table->string('serial_number')->nullable();
        $table->string('fuel_card_number')->nullable();
        $table->string('vin')->nullable();
        $table->string('make')->nullable();
        $table->string('model')->nullable();
        $table->string('year')->nullable();
        $table->text('specs')->nullable();
        $table->text('vin_data')->nullable();
        $table->text('telematics')->nullable();
        $table->text('meta')->nullable();
        $table->string('status')->nullable();
        $table->text('location')->nullable();
        $table->integer('heading')->nullable();
        $table->integer('altitude')->nullable();
        $table->integer('speed')->nullable();
        $table->boolean('online')->nullable();
        $table->timestamp('created_at')->nullable();
        $table->timestamp('updated_at')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('fleets', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('parent_fleet_uuid')->nullable();
        $table->string('name')->nullable();
        $table->string('task')->nullable();
        $table->string('status')->nullable();
        $table->string('slug')->nullable();
        $table->timestamp('created_at')->nullable();
        $table->timestamp('updated_at')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('fleet_drivers', function ($table) {
        $table->increments('id');
        $table->string('fleet_uuid')->nullable();
        $table->string('driver_uuid')->nullable();
        $table->timestamp('created_at')->nullable();
        $table->timestamp('updated_at')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('fleet_vehicles', function ($table) {
        $table->increments('id');
        $table->string('fleet_uuid')->nullable();
        $table->string('vehicle_uuid')->nullable();
        $table->timestamp('created_at')->nullable();
        $table->timestamp('updated_at')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('files', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('type')->nullable();
        $table->timestamp('created_at')->nullable();
        $table->timestamp('updated_at')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });

    return $connection;
}

function fleetopsLiveControllerRegisterPermissionMacro(): void
{
    $reflection = new ReflectionClass(Illuminate\Database\Eloquent\Builder::class);
    $property   = $reflection->getProperty('macros');
    $macros     = $property->getValue();

    $macros['applyDirectivesForPermissions'] = function (string|array $names = []) {
        return $this;
    };

    $property->setValue(null, $macros);
}

function fleetopsLiveControllerInsertRows(SQLiteConnection $connection, string $table, array $rows): void
{
    foreach ($rows as $row) {
        $connection->table($table)->insert($row);
    }
}

test('live viewport bounds are normalized for stable cache keys', function () {
    $request = new Request([
        'bounds' => ['1.234567', '103.876543', '1.345678', '103.987654'],
    ]);

    expect(callLiveControllerMethod('normalizeLiveBounds', [$request]))
        ->toBe([1.2346, 103.8765, 1.3457, 103.9877]);
});

test('invalid live viewport bounds fall back to unbounded queries', function ($bounds) {
    $request = new Request(['bounds' => $bounds]);

    expect(callLiveControllerMethod('normalizeLiveBounds', [$request]))->toBeNull();
})->with([
    'missing coordinate' => [[1, 2, 3]],
    'non numeric'        => [[1, 'west', 3, 4]],
    'invalid latitude'   => [[-91, 103, 1, 104]],
    'invalid longitude'  => [[1, -181, 2, 104]],
    'inverted latitude'  => [[2, 103, 1, 104]],
    'inverted longitude' => [[1, 104, 2, 103]],
]);

test('live viewport limit defaults and clamps', function () {
    expect(callLiveControllerMethod('normalizeLiveLimit', [new Request()]))->toBe(500)
        ->and(callLiveControllerMethod('normalizeLiveLimit', [new Request(['limit' => 25])]))->toBe(25)
        ->and(callLiveControllerMethod('normalizeLiveLimit', [new Request(['limit' => 0])]))->toBe(500)
        ->and(callLiveControllerMethod('normalizeLiveLimit', [new Request(['limit' => 5000])]))->toBe(1000);
});

test('live viewport query avoids spatial constructors with fixed srids', function () {
    $controller = file_get_contents(dirname(__DIR__, 4) . '/src/Http/Controllers/Internal/v1/LiveController.php');

    expect($controller)->toContain('protected function applyLiveLocationGuards')
        ->and($controller)->toContain('protected function applyLiveViewportBounds')
        ->and($controller)->toContain('ST_Y(location) BETWEEN ? AND ?')
        ->and($controller)->toContain('ST_X(location) BETWEEN ? AND ?')
        ->and($controller)->not->toContain('ST_MakeEnvelope')
        ->and($controller)->not->toContain('ST_GeomFromText');
});

test('live controller applies location guards and optional viewport bounds', function () {
    $query = new FleetOpsLiveQueryRecorder();

    callLiveControllerMethod('applyLiveLocationGuards', [$query]);
    callLiveControllerMethod('applyLiveViewportBounds', [$query, [1.1, 103.2, 1.4, 103.9]]);
    callLiveControllerMethod('applyLiveViewportBounds', [$query, null]);

    expect($query->calls)->toBe([
        ['whereNotNull', 'location'],
        [
            'whereRaw',
            'ST_Y(location) BETWEEN -90 AND 90
                AND ST_X(location) BETWEEN -180 AND 180
                AND NOT (ST_X(location) = 0 AND ST_Y(location) = 0)',
            [],
        ],
        [
            'whereRaw',
            'ST_Y(location) BETWEEN ? AND ? AND ST_X(location) BETWEEN ? AND ?',
            [1.1, 1.4, 103.2, 103.9],
        ],
    ]);
});

test('live controller builds operations monitor fleet trees', function () {
    $fleetNodes = new Collection([
        'root' => [
            'uuid'              => 'root',
            'parent_fleet_uuid' => null,
            'subfleets'         => [],
        ],
        'child' => [
            'uuid'              => 'child',
            'parent_fleet_uuid' => 'root',
            'subfleets'         => [],
        ],
        'orphan' => [
            'uuid'              => 'orphan',
            'parent_fleet_uuid' => 'missing',
            'subfleets'         => [],
        ],
    ]);

    expect(callLiveControllerMethod('buildOperationsMonitorFleetTree', [$fleetNodes]))->toBe([
        [
            'uuid'              => 'root',
            'parent_fleet_uuid' => null,
            'subfleets'         => [
                [
                    'uuid'              => 'child',
                    'parent_fleet_uuid' => 'root',
                    'subfleets'         => [],
                ],
            ],
        ],
        [
            'uuid'              => 'orphan',
            'parent_fleet_uuid' => 'missing',
            'subfleets'         => [],
        ],
    ]);
});

test('live controller builds operations monitor snapshots from scoped records', function () {
    $connection = fleetopsLiveControllerUseMonitorDatabase();
    $cache      = new FleetOpsLiveControllerCacheFake();
    $now        = now();

    fleetopsLiveControllerRegisterPermissionMacro();
    Cache::swap($cache);
    session(['company' => 'company-uuid']);

    fleetopsLiveControllerInsertRows($connection, 'users', [
        [
            'uuid'       => 'user-a',
            'public_id'  => 'user-public-a',
            'name'       => 'Avery Driver',
            'phone'      => '+15550001001',
            'email'      => 'avery@example.test',
            'status'     => 'active',
            'deleted_at' => null,
        ],
        [
            'uuid'       => 'user-b',
            'public_id'  => 'user-public-b',
            'name'       => 'Blair Driver',
            'phone'      => '+15550001002',
            'email'      => 'blair@example.test',
            'status'     => 'active',
            'deleted_at' => null,
        ],
        [
            'uuid'       => 'other-user',
            'public_id'  => 'other-user-public',
            'name'       => 'Other Driver',
            'status'     => 'active',
            'deleted_at' => null,
        ],
    ]);
    fleetopsLiveControllerInsertRows($connection, 'drivers', [
        [
            'uuid'             => 'driver-a',
            'public_id'        => 'driver-public-a',
            'company_uuid'     => 'company-uuid',
            'user_uuid'        => 'user-a',
            'vehicle_uuid'     => 'vehicle-a',
            'vendor_uuid'      => 'vendor-a',
            'current_job_uuid' => 'order-a',
            'status'           => 'active',
            'heading'          => 180,
            'altitude'         => 7,
            'speed'            => 44,
            'online'           => 1,
            'created_at'       => $now,
            'updated_at'       => $now,
        ],
        [
            'uuid'             => 'driver-b',
            'public_id'        => 'driver-public-b',
            'company_uuid'     => 'company-uuid',
            'user_uuid'        => 'user-b',
            'vehicle_uuid'     => null,
            'vendor_uuid'      => 'vendor-b',
            'current_job_uuid' => null,
            'status'           => 'available',
            'heading'          => 90,
            'altitude'         => 3,
            'speed'            => 12,
            'online'           => 0,
            'created_at'       => $now,
            'updated_at'       => $now,
        ],
        [
            'uuid'         => 'other-driver',
            'public_id'    => 'other-driver-public',
            'company_uuid' => 'other-company',
            'user_uuid'    => 'other-user',
            'status'       => 'active',
            'online'       => 1,
            'created_at'   => $now,
            'updated_at'   => $now,
        ],
    ]);
    fleetopsLiveControllerInsertRows($connection, 'vehicles', [
        [
            'uuid'             => 'vehicle-a',
            'public_id'        => 'vehicle-public-a',
            'company_uuid'     => 'company-uuid',
            'vendor_uuid'      => 'vendor-a',
            'photo_uuid'       => null,
            'internal_id'      => 'internal-a',
            'name'             => 'Vehicle A',
            'display_name'     => 'Vehicle A',
            'plate_number'     => 'A-100',
            'serial_number'    => 'SERIAL-A',
            'fuel_card_number' => 'FUEL-A',
            'vin'              => 'VIN-A',
            'make'             => 'Ford',
            'model'            => 'Transit',
            'year'             => '2024',
            'status'           => 'available',
            'heading'          => 270,
            'altitude'         => 9,
            'speed'            => 28,
            'online'           => 1,
            'created_at'       => $now,
            'updated_at'       => $now,
        ],
        [
            'uuid'             => 'vehicle-b',
            'public_id'        => 'vehicle-public-b',
            'company_uuid'     => 'company-uuid',
            'vendor_uuid'      => 'vendor-b',
            'photo_uuid'       => null,
            'internal_id'      => 'internal-b',
            'name'             => 'Vehicle B',
            'display_name'     => 'Vehicle B Display',
            'plate_number'     => 'B-200',
            'serial_number'    => 'SERIAL-B',
            'fuel_card_number' => 'FUEL-B',
            'vin'              => 'VIN-B',
            'make'             => 'Mercedes',
            'model'            => 'Sprinter',
            'year'             => '2023',
            'status'           => 'maintenance',
            'online'           => 0,
            'created_at'       => $now,
            'updated_at'       => $now,
        ],
        [
            'uuid'         => 'other-vehicle',
            'public_id'    => 'other-vehicle-public',
            'company_uuid' => 'other-company',
            'status'       => 'available',
            'online'       => 1,
            'created_at'   => $now,
            'updated_at'   => $now,
        ],
    ]);
    fleetopsLiveControllerInsertRows($connection, 'fleets', [
        [
            'uuid'              => 'fleet-root',
            'public_id'         => 'fleet-public-root',
            'company_uuid'      => 'company-uuid',
            'parent_fleet_uuid' => null,
            'name'              => 'Root Fleet',
            'task'              => 'delivery',
            'status'            => 'active',
            'slug'              => 'root-fleet',
            'created_at'        => $now,
            'updated_at'        => $now,
        ],
        [
            'uuid'              => 'fleet-child',
            'public_id'         => 'fleet-public-child',
            'company_uuid'      => 'company-uuid',
            'parent_fleet_uuid' => 'fleet-root',
            'name'              => 'Child Fleet',
            'task'              => 'service',
            'status'            => 'active',
            'slug'              => 'child-fleet',
            'created_at'        => $now,
            'updated_at'        => $now,
        ],
        [
            'uuid'              => 'fleet-orphan',
            'public_id'         => 'fleet-public-orphan',
            'company_uuid'      => 'company-uuid',
            'parent_fleet_uuid' => 'missing-parent',
            'name'              => 'Orphan Fleet',
            'task'              => 'overflow',
            'status'            => 'inactive',
            'slug'              => 'orphan-fleet',
            'created_at'        => $now,
            'updated_at'        => $now,
        ],
        [
            'uuid'         => 'other-fleet',
            'public_id'    => 'other-fleet-public',
            'company_uuid' => 'other-company',
            'name'         => 'Other Fleet',
            'created_at'   => $now,
            'updated_at'   => $now,
        ],
    ]);
    fleetopsLiveControllerInsertRows($connection, 'fleet_drivers', [
        ['fleet_uuid' => 'fleet-root', 'driver_uuid' => 'driver-a'],
        ['fleet_uuid' => 'fleet-root', 'driver_uuid' => 'other-driver'],
        ['fleet_uuid' => 'fleet-child', 'driver_uuid' => 'driver-b'],
        ['fleet_uuid' => 'other-fleet', 'driver_uuid' => 'driver-a'],
    ]);
    fleetopsLiveControllerInsertRows($connection, 'fleet_vehicles', [
        ['fleet_uuid' => 'fleet-root', 'vehicle_uuid' => 'vehicle-a'],
        ['fleet_uuid' => 'fleet-root', 'vehicle_uuid' => 'other-vehicle'],
        ['fleet_uuid' => 'fleet-child', 'vehicle_uuid' => 'vehicle-b'],
        ['fleet_uuid' => 'other-fleet', 'vehicle_uuid' => 'vehicle-a'],
    ]);

    $snapshot = (new LiveController())->operationsMonitor();
    $fleets   = collect($snapshot['fleets'])->keyBy('uuid');
    $root     = $fleets->get('fleet-root');
    $child    = collect($root['subfleets'])->firstWhere('uuid', 'fleet-child');

    expect($cache->tagCalls)->toBe([['live:company-uuid', 'live:company-uuid:operations-monitor']])
        ->and($cache->tagged->rememberCalls[0]['ttl'])->toBe(LiveCacheService::DEFAULT_TTL)
        ->and($snapshot['meta'])->toMatchArray([
            'ttl'            => LiveCacheService::DEFAULT_TTL,
            'drivers_count'  => 2,
            'vehicles_count' => 2,
            'fleets_count'   => 3,
        ])
        ->and($snapshot['drivers'])->toHaveCount(2)
        ->and(collect($snapshot['drivers'])->pluck('uuid')->all())->toBe(['driver-a', 'driver-b'])
        ->and($snapshot['drivers'][0])->toMatchArray([
            'uuid'             => 'driver-a',
            'public_id'        => 'driver-public-a',
            'user_uuid'        => 'user-a',
            'vehicle_uuid'     => 'vehicle-a',
            'vendor_uuid'      => 'vendor-a',
            'current_job_uuid' => 'order-a',
            'name'             => 'Avery Driver',
            'vehicle_name'     => 'Vehicle A',
            'status'           => 'active',
            'heading'          => 180,
            'altitude'         => 7,
            'speed'            => 44,
            'online'           => true,
        ])
        ->and($snapshot['drivers'][1]['online'])->toBeFalse()
        ->and(collect($snapshot['vehicles'])->pluck('uuid')->all())->toBe(['vehicle-a', 'vehicle-b'])
        ->and($snapshot['vehicles'][0])->toMatchArray([
            'uuid'             => 'vehicle-a',
            'public_id'        => 'vehicle-public-a',
            'vendor_uuid'      => 'vendor-a',
            'photo_uuid'       => null,
            'internal_id'      => 'internal-a',
            'name'             => 'Vehicle A',
            'display_name'     => 'Vehicle A',
            'driver_name'      => 'Avery Driver',
            'plate_number'     => 'A-100',
            'serial_number'    => 'SERIAL-A',
            'fuel_card_number' => 'FUEL-A',
            'vin'              => 'VIN-A',
            'make'             => 'Ford',
            'model'            => 'Transit',
            'year'             => '2024',
            'status'           => 'available',
            'heading'          => 270,
            'altitude'         => 9,
            'speed'            => 28,
            'online'           => true,
        ])
        ->and($snapshot['vehicles'][1]['online'])->toBeFalse()
        ->and($fleets->keys()->all())->toBe(['fleet-orphan', 'fleet-root'])
        ->and($root)->toMatchArray([
            'uuid'                  => 'fleet-root',
            'public_id'             => 'fleet-public-root',
            'name'                  => 'Root Fleet',
            'drivers_count'         => 1,
            'drivers_online_count'  => 1,
            'vehicles_count'        => 1,
            'vehicles_online_count' => 1,
            'driver_ids'            => ['driver-a'],
            'vehicle_ids'           => ['vehicle-a'],
        ])
        ->and($child)->toMatchArray([
            'uuid'                  => 'fleet-child',
            'drivers_count'         => 1,
            'drivers_online_count'  => 0,
            'vehicles_count'        => 1,
            'vehicles_online_count' => 0,
            'driver_ids'            => ['driver-b'],
            'vehicle_ids'           => ['vehicle-b'],
        ])
        ->and($fleets->get('fleet-orphan')['parent_fleet_uuid'])->toBe('missing-parent');
});

test('live controller serializes operations monitor drivers and vehicles', function () {
    $updatedAt = now()->subMinute();
    $createdAt = now()->subHour();

    $driver = new FleetOpsLiveMonitorDriverFake();
    $driver->setRawAttributes([
        'uuid'             => 'driver-uuid',
        'public_id'        => 'driver_public',
        'company_uuid'     => 'company-uuid',
        'user_uuid'        => 'user-uuid',
        'vehicle_uuid'     => 'vehicle-uuid',
        'vendor_uuid'      => 'vendor-uuid',
        'current_job_uuid' => 'job-uuid',
        'name'             => 'Jamie Driver',
        'email'            => 'jamie@example.test',
        'phone'            => '+15550001111',
        'photo_url'        => 'https://example.test/photo.jpg',
        'vehicle_name'     => 'Van 9',
        'status'           => 'active',
        'location'         => ['latitude' => 1.3521, 'longitude' => 103.8198],
        'heading'          => '270',
        'altitude'         => '12',
        'speed'            => '42',
        'online'           => 1,
        'updated_at'       => $updatedAt,
        'created_at'       => $createdAt,
    ], true);

    $vehicle = new FleetOpsLiveMonitorVehicleFake();
    $vehicle->setRawAttributes([
        'uuid'             => 'vehicle-uuid',
        'public_id'        => 'vehicle_public',
        'company_uuid'     => 'company-uuid',
        'vendor_uuid'      => 'vendor-uuid',
        'photo_uuid'       => 'photo-uuid',
        'internal_id'      => 'internal-9',
        'name'             => 'Van 9',
        'display_name'     => 'Delivery Van 9',
        'driver_name'      => 'Jamie Driver',
        'plate_number'     => 'SG-1234',
        'serial_number'    => 'SERIAL9',
        'fuel_card_number' => 'FUEL9',
        'vin'              => 'VIN9',
        'make'             => 'Ford',
        'model'            => 'Transit',
        'year'             => '2024',
        'photo_url'        => 'https://example.test/vehicle.jpg',
        'avatar_url'       => 'https://example.test/avatar.jpg',
        'status'           => 'available',
        'location'         => ['latitude' => 1.4, 'longitude' => 103.9],
        'heading'          => '90',
        'altitude'         => '8',
        'speed'            => '35',
        'online'           => true,
        'updated_at'       => $updatedAt,
        'created_at'       => $createdAt,
    ], true);

    $serializedDriver  = callLiveControllerMethod('serializeMonitorDriver', [$driver]);
    $serializedVehicle = callLiveControllerMethod('serializeMonitorVehicle', [$vehicle]);

    expect($serializedDriver)->toMatchArray([
        'id'                    => 'driver-uuid',
        'uuid'                  => 'driver-uuid',
        'public_id'             => 'driver_public',
        'company_uuid'          => 'company-uuid',
        'user_uuid'             => 'user-uuid',
        'vehicle_uuid'          => 'vehicle-uuid',
        'vendor_uuid'           => 'vendor-uuid',
        'current_job_uuid'      => 'job-uuid',
        'name'                  => 'Jamie Driver',
        'email'                 => 'jamie@example.test',
        'phone'                 => '+15550001111',
        'photo_url'             => 'https://example.test/photo.jpg',
        'avatar_url'            => 'https://example.test/photo.jpg',
        'vehicle_name'          => 'Van 9',
        'status'                => 'active',
        'heading'               => 270,
        'altitude'              => 12,
        'speed'                 => 42,
        'online'                => true,
        'assigned_orders_count' => null,
        'meta'                  => ['_index_resource' => true],
        'updated_at'            => $updatedAt,
        'created_at'            => $createdAt,
    ])
        ->and($serializedDriver['location']->getLat())->toBe(1.3521)
        ->and($serializedDriver['location']->getLng())->toBe(103.8198)
        ->and($serializedVehicle)->toMatchArray([
            'id'                    => 'vehicle-uuid',
            'uuid'                  => 'vehicle-uuid',
            'public_id'             => 'vehicle_public',
            'company_uuid'          => 'company-uuid',
            'vendor_uuid'           => 'vendor-uuid',
            'photo_uuid'            => 'photo-uuid',
            'internal_id'           => 'internal-9',
            'name'                  => 'Van 9',
            'display_name'          => 'Delivery Van 9',
            'driver_name'           => 'Jamie Driver',
            'plate_number'          => 'SG-1234',
            'serial_number'         => 'SERIAL9',
            'fuel_card_number'      => 'FUEL9',
            'vin'                   => 'VIN9',
            'make'                  => 'Ford',
            'model'                 => 'Transit',
            'year'                  => '2024',
            'photo_url'             => 'https://example.test/vehicle.jpg',
            'avatar_url'            => 'https://example.test/avatar.jpg',
            'status'                => 'available',
            'heading'               => 90,
            'altitude'              => 8,
            'speed'                 => 35,
            'online'                => true,
            'assigned_orders_count' => null,
            'meta'                  => ['_index_resource' => true],
            'updated_at'            => $updatedAt,
            'created_at'            => $createdAt,
        ])
        ->and($serializedVehicle['location']->getLat())->toBe(1.4)
        ->and($serializedVehicle['location']->getLng())->toBe(103.9);
});
