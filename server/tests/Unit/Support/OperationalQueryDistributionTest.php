<?php

use Fleetbase\FleetOps\Support\Ai\Capabilities\OperationalQueryCapability;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;

/**
 * Covers the OperationalQueryCapability driver geofence distribution against
 * SQLite: filter application, the empty-fleet short circuit, and the
 * service-area/zone containment counting with spatial stand-ins.
 */
if (!function_exists('Fleetbase\Support\session')) {
    eval('namespace Fleetbase\Support; function session($key = null, $default = null) { if ($key === null) { return new class { public function has($k) { return \session($k) !== null; } public function get($k, $d = null) { return \session($k, $d); } }; } return \session($key, $default); }');
}

if (!function_exists('Fleetbase\Support\auth')) {
    eval('namespace Fleetbase\Support; function auth() { return new class { public function user() { return null; } public function id() { return null; } }; }');
}

function fleetopsAiDistributionPermissionMacro(): void
{
    $reflection = new ReflectionClass(Illuminate\Database\Eloquent\Builder::class);
    $property   = $reflection->getProperty('macros');
    $macros     = $property->getValue();

    $macros['applyDirectivesForPermissions'] = function (string|array $names = []) {
        return $this;
    };

    $property->setValue(null, $macros);
}

function fleetopsAiDistributionWkb(float $latitude, float $longitude): string
{
    return pack('V', 0) . pack('C', 1) . pack('V', 1) . pack('d', $longitude) . pack('d', $latitude);
}

function fleetopsAiDistributionBoot(): SQLiteConnection
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->sqliteCreateFunction('MBRContains', fn ($border, $point) => 1);
    $pdo->sqliteCreateFunction('ST_Contains', fn ($border, $point) => 1);
    $pdo->sqliteCreateFunction('ST_GeomFromText', fn ($wkt, $srid = 0, $axisOrder = null) => $wkt);
    $pdo->sqliteCreateFunction('ST_X', fn ($value) => 0.5);
    $pdo->sqliteCreateFunction('ST_Y', fn ($value) => 0.5);
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

    fleetopsAiDistributionPermissionMacro();

    $schema = $connection->getSchemaBuilder();
    $tables = [
        'users'         => ['uuid', 'public_id', 'company_uuid', 'name', 'type', 'status'],
        'drivers'       => ['uuid', 'public_id', 'company_uuid', 'user_uuid', 'online', 'location', 'city', 'country', 'status'],
        'service_areas' => ['uuid', 'public_id', 'company_uuid', 'name', 'border', 'status'],
        'zones'         => ['uuid', 'public_id', 'company_uuid', 'service_area_uuid', 'name', 'border', 'status'],
    ];
    foreach ($tables as $table => $columns) {
        $schema->create($table, function ($blueprint) use ($columns) {
            $blueprint->increments('id');
            foreach ($columns as $column) {
                if ($column === 'online') {
                    $blueprint->integer($column)->nullable();
                    continue;
                }
                $blueprint->string($column)->nullable();
            }
            $blueprint->timestamps();
            $blueprint->timestamp('deleted_at')->nullable();
        });
    }

    $connection->table('users')->insert(['uuid' => 'admin-1', 'company_uuid' => 'company-1', 'type' => 'admin']);
    session(['company' => 'company-1', 'user' => 'admin-1']);

    return $connection;
}

function fleetopsAiDistribution(array $filters = []): array
{
    $reflection = new ReflectionMethod(OperationalQueryCapability::class, 'driverGeofenceDistribution');
    $reflection->setAccessible(true);

    return $reflection->invoke(new OperationalQueryCapability(), $filters);
}

test('distribution short circuits when no located drivers exist', function () {
    fleetopsAiDistributionBoot();

    $distribution = fleetopsAiDistribution();

    expect($distribution['authorized'])->toBeTrue()
        ->and($distribution['valid_location_count'])->toBe(0)
        ->and($distribution['service_areas'])->toBe([])
        ->and($distribution['zones'])->toBe([]);
});

test('distribution counts drivers per service area and zone with filters', function () {
    $connection = fleetopsAiDistributionBoot();
    $connection->table('users')->insert(['uuid' => 'user-1', 'company_uuid' => 'company-1']);
    $connection->table('drivers')->insert([
        ['uuid' => 'driver-1', 'company_uuid' => 'company-1', 'user_uuid' => 'user-1', 'online' => 1, 'location' => fleetopsAiDistributionWkb(1.30, 103.80), 'updated_at' => '2026-07-28 08:00:00'],
        ['uuid' => 'driver-2', 'company_uuid' => 'company-1', 'user_uuid' => 'user-1', 'online' => 0, 'location' => fleetopsAiDistributionWkb(1.31, 103.81), 'updated_at' => '2026-07-28 08:00:00'],
    ]);
    $connection->table('service_areas')->insert(['uuid' => 'sa-1', 'public_id' => 'service_area_test', 'company_uuid' => 'company-1', 'name' => 'Central', 'border' => 'POLYGON(...)']);
    $connection->table('zones')->insert(['uuid' => 'zone-1', 'public_id' => 'zone_test', 'company_uuid' => 'company-1', 'service_area_uuid' => 'sa-1', 'name' => 'Zone A', 'border' => 'POLYGON(...)']);

    $distribution = fleetopsAiDistribution([
        ['field' => 'online', 'value' => true],
        ['field' => 'updated_at', 'operator' => '>=', 'value' => '2000-01-01 00:00:00'],
    ]);

    expect($distribution['authorized'])->toBeTrue()
        ->and($distribution['valid_location_count'])->toBe(1)
        ->and($distribution['service_areas'][0]['name'])->toBe('Central')
        ->and($distribution['service_areas'][0]['count'])->toBe(1)
        ->and($distribution['zones'][0]['name'])->toBe('Zone A');
});
