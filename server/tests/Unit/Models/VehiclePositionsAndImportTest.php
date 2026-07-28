<?php

if (!function_exists('Fleetbase\Support\session')) {
    eval('namespace Fleetbase\Support; function session($key = null, $default = null) { if ($key === null) { return new class { public function has($k) { return \session($k) !== null; } public function get($k, $d = null) { return \session($k, $d); } }; } return \session($key, $default); }');
}

if (!defined('FLEETOPS_VEHICLE_TEST_SERVER_PATH')) {
    define('FLEETOPS_VEHICLE_TEST_SERVER_PATH', dirname(__DIR__, 3));
}

if (!function_exists('Fleetbase\FleetOps\Support\base_path')) {
    eval('namespace Fleetbase\FleetOps\Support; function base_path($path = \'\') { $path = (string) $path; $marker = \'vendor/fleetbase/fleetops-api/server/\'; if (str_contains($path, $marker)) { return FLEETOPS_VEHICLE_TEST_SERVER_PATH . \'/\' . substr($path, strpos($path, $marker) + strlen($marker)); } return rtrim(dirname(FLEETOPS_VEHICLE_TEST_SERVER_PATH) . \'/\' . ltrim($path, \'/\'), \'/\'); }');
}

if (!function_exists('Fleetbase\Support\auth')) {
    eval('namespace Fleetbase\Support; function auth() { return new class { public function user() { return null; } public function id() { return null; } }; }');
}

use Fleetbase\FleetOps\Models\Position;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;

/**
 * Covers Vehicle avatar resolution, position creation with order context
 * and movement thresholds, attribute-normalized position creation with
 * destinations, and import-row creation with vehicle-name parsing and
 * driver assignment against SQLite.
 */
function fleetopsVehiclePositionBoot(): SQLiteConnection
{
    $pdo = new PDO('sqlite::memory:');
    // Store points as WKB so spatial casts can rehydrate them on read
    $pdo->sqliteCreateFunction('ST_PointFromText', function ($wkt, $srid = 0, $axisOrder = null) {
        if (is_string($wkt) && sscanf($wkt, 'POINT(%f %f)', $lng, $lat) === 2) {
            return pack('V', 0) . pack('C', 1) . pack('V', 1) . pack('d', $lng) . pack('d', $lat);
        }

        return $wkt;
    });
    $pdo->sqliteCreateFunction('ST_GeomFromText', function ($wkt, $srid = 0, $axisOrder = null) {
        if (is_string($wkt) && sscanf($wkt, 'POINT(%f %f)', $lng, $lat) === 2) {
            return pack('V', 0) . pack('C', 1) . pack('V', 1) . pack('d', $lng) . pack('d', $lat);
        }

        return $wkt;
    });
    $connection = new SQLiteConnection($pdo);
    $resolver   = new ConnectionResolver(['default' => $connection, 'mysql' => $connection]);
    $resolver->setDefaultConnection('mysql');
    EloquentModel::setConnectionResolver($resolver);
    app()->instance('db', new class($connection) {
        public function __construct(public SQLiteConnection $c)
        {
        }

        public function connection($name = null): SQLiteConnection
        {
            return $this->c;
        }

        public function __call($method, $arguments)
        {
            return $this->c->{$method}(...$arguments);
        }
    });
    Illuminate\Support\Facades\DB::clearResolvedInstance('db');

    $schema = $connection->getSchemaBuilder();
    $tables = [
        'vehicles'  => ['uuid', 'public_id', 'internal_id', 'company_uuid', 'name', 'make', 'model', 'year', 'trim', 'plate_number', 'vin', 'serial_number', 'call_sign', 'fuel_card_number', 'type', 'status', 'online', 'location', 'meta', 'avatar_url', '_key'],
        'positions' => ['uuid', 'public_id', 'company_uuid', 'subject_uuid', 'subject_type', 'destination_uuid', 'coordinates', 'heading', 'bearing', 'speed', 'altitude', 'order_uuid', '_key'],
        'drivers'   => ['uuid', 'public_id', 'company_uuid', 'user_uuid', 'vehicle_uuid', 'current_job_uuid', 'location', 'online', 'status', '_key'],
        'users'     => ['uuid', 'public_id', 'company_uuid', 'name', 'type', 'status'],
        'orders'    => ['uuid', 'public_id', 'company_uuid', 'payload_uuid', 'driver_assigned_uuid', 'status', 'type', 'dispatched', 'started'],
        'payloads'  => ['uuid', 'public_id', 'company_uuid', 'pickup_uuid', 'dropoff_uuid', 'current_waypoint_uuid', 'type', 'meta', '_key'],
        'places'    => ['uuid', 'public_id', 'company_uuid', 'name', 'location', 'meta', 'type'],
        'waypoints' => ['uuid', 'public_id', 'company_uuid', 'payload_uuid', 'place_uuid', 'order', 'type'],
        'entities'  => ['uuid', 'public_id', 'company_uuid', 'payload_uuid', 'destination_uuid', 'name', 'type'],
        'files'     => ['uuid', 'public_id', 'company_uuid', 'name', 'path', 'disk', 'bucket', 'type'],
        'companies' => ['uuid', 'public_id', 'name', 'country'],
    ];
    foreach ($tables as $table => $columns) {
        $schema->create($table, function ($blueprint) use ($columns) {
            $blueprint->increments('id');
            foreach ($columns as $column) {
                if (in_array($column, ['online', 'dispatched', 'started'], true)) {
                    $blueprint->integer($column)->nullable();
                    continue;
                }
                $blueprint->string($column)->nullable();
            }
            $blueprint->timestamps();
            $blueprint->timestamp('deleted_at')->nullable();
        });
    }

    session(['company' => 'company-1']);
    $connection->table('companies')->insert(['uuid' => 'company-1', 'name' => 'Acme', 'country' => 'SG']);

    return $connection;
}

function fleetopsVehiclePositionWkb(float $lat, float $lng): string
{
    return pack('V', 0) . pack('C', 1) . pack('V', 1) . pack('d', $lng) . pack('d', $lat);
}

test('avatar urls resolve uuid keys through file lookups', function () {
    fleetopsVehiclePositionBoot();

    $vehicle = new Vehicle();
    $vehicle->setRawAttributes(['avatar_url' => 'https://cdn.example.com/van.png'], true);
    expect($vehicle->avatar_url)->toBe('https://cdn.example.com/van.png');

    $byUuid = new Vehicle();
    $byUuid->setRawAttributes(['avatar_url' => '99999999-9999-4999-8999-999999999999'], true);
    expect($byUuid->avatar_url)->toBeNull()
        ->and(Vehicle::getAvatar('88888888-8888-4888-8888-888888888888'))->toBeNull();
});

test('position creation with order context respects movement thresholds', function () {
    $connection = fleetopsVehiclePositionBoot();
    $connection->table('places')->insert(['uuid' => '11111111-1111-4111-8111-111111111111', 'public_id' => 'place_vehpos1', 'company_uuid' => 'company-1', 'name' => 'Pickup', 'location' => fleetopsVehiclePositionWkb(1.30, 103.80)]);
    $connection->table('payloads')->insert(['uuid' => 'payload-1', 'company_uuid' => 'company-1', 'pickup_uuid' => '11111111-1111-4111-8111-111111111111']);
    $connection->table('users')->insert(['uuid' => 'user-1', 'company_uuid' => 'company-1', 'type' => 'user']);
    $connection->table('drivers')->insert(['uuid' => 'driver-1', 'public_id' => 'driver_vehpos1', 'company_uuid' => 'company-1', 'user_uuid' => 'user-1', 'vehicle_uuid' => 'vehicle-1']);
    $connection->table('orders')->insert(['uuid' => 'order-1', 'public_id' => 'order_vehpos1', 'company_uuid' => 'company-1', 'payload_uuid' => 'payload-1', 'driver_assigned_uuid' => 'driver-1', 'status' => 'created']);
    $connection->table('vehicles')->insert(['uuid' => 'vehicle-1', 'public_id' => 'vehicle_vehpos1', 'company_uuid' => 'company-1', 'location' => fleetopsVehiclePositionWkb(1.32, 103.82)]);

    $vehicle = Vehicle::query()->where('uuid', 'vehicle-1')->first();
    $order   = Fleetbase\FleetOps\Models\Order::query()->where('uuid', 'order-1')->first();

    $first = $vehicle->createPositionWithOrderContext($order);
    expect($first)->toBeInstanceOf(Position::class)
        ->and($connection->table('positions')->count())->toBe(1)
        ->and($connection->table('positions')->value('order_uuid'))->toBe('order-1')
        ->and($connection->table('positions')->value('destination_uuid'))->toBe('11111111-1111-4111-8111-111111111111');

    // Unmoved vehicles skip creating a second position
    $vehicle = Vehicle::query()->where('uuid', 'vehicle-1')->first();
    expect($vehicle->createPositionWithOrderContext($order))->toBeNull()
        ->and($connection->table('positions')->count())->toBe(1);
});

test('create position normalizes coordinates and destinations', function () {
    $connection = fleetopsVehiclePositionBoot();
    $connection->table('vehicles')->insert(['uuid' => 'vehicle-1', 'public_id' => 'vehicle_vehpos2', 'company_uuid' => 'company-1']);
    $vehicle = Vehicle::query()->where('uuid', 'vehicle-1')->first();

    $fromLocation = $vehicle->createPosition(['location' => new Point(1.31, 103.81), 'speed' => '12']);
    expect($fromLocation)->toBeInstanceOf(Position::class);

    $fromLatLng = $vehicle->createPosition(['latitude' => 1.33, 'longitude' => 103.83], '22222222-2222-4222-8222-222222222222');
    expect($fromLatLng)->toBeInstanceOf(Position::class)
        ->and($connection->table('positions')->count())->toBe(2)
        ->and($connection->table('positions')->orderByDesc('id')->value('destination_uuid'))->toBe('22222222-2222-4222-8222-222222222222');
});

test('import rows parse vehicle names and assign resolved drivers', function () {
    $connection = fleetopsVehiclePositionBoot();
    $connection->table('users')->insert(['uuid' => 'user-1', 'company_uuid' => 'company-1', 'name' => 'Casey Driver', 'type' => 'user']);
    $connection->table('drivers')->insert(['uuid' => 'driver-1', 'public_id' => 'driver_vehimp1', 'company_uuid' => 'company-1', 'user_uuid' => 'user-1']);

    $parsed = Vehicle::createFromImport([
        'vehicle'      => 'Toyota Hiace 2020',
        'plate_number' => 'SGV5678B',
    ], true);
    expect($parsed)->toBeInstanceOf(Vehicle::class)
        ->and($parsed->plate_number)->toBe('SGV5678B')
        ->and($connection->table('vehicles')->count())->toBeGreaterThanOrEqual(1);

    $withDriver = Vehicle::createFromImport([
        'make'   => 'Ford',
        'model'  => 'Transit',
        'driver' => 'driver_vehimp1',
    ]);
    expect($withDriver)->toBeInstanceOf(Vehicle::class)
        ->and($connection->table('drivers')->where('uuid', 'driver-1')->value('vehicle_uuid'))->toBe($withDriver->uuid);
});
