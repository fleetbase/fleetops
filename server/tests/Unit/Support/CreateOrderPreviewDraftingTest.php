<?php

if (!function_exists('Fleetbase\Support\session')) {
    eval('namespace Fleetbase\Support; function session($key = null, $default = null) { if ($key === null) { return new class { public function has($k) { return \session($k) !== null; } public function get($k, $d = null) { return \session($k, $d); } }; } return \session($key, $default); }');
}

if (!function_exists('Fleetbase\Support\auth')) {
    eval('namespace Fleetbase\Support; function auth() { return new class { public function user() { return null; } public function id() { return null; } }; }');
}

use Fleetbase\FleetOps\Support\Ai\Capabilities\CreateOrderPreviewCapability;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Http\JsonResponse;

/**
 * Covers the CreateOrderPreviewCapability drafting helpers against SQLite:
 * prompt-to-draft conversion with quoted, from-to and labeled address
 * extraction, place resolution through saved-place search, dispatch,
 * scheduling, notes and proof-of-delivery detection, order-config, driver
 * and vehicle resolution, controller response helpers, and pod method
 * normalization.
 */
function fleetopsOrderPreviewBoot(): SQLiteConnection
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->sqliteCreateFunction('ST_PointFromText', fn ($wkt, $srid = 0, $axisOrder = null) => $wkt);
    $pdo->sqliteCreateFunction('ST_GeomFromText', fn ($wkt, $srid = 0, $axisOrder = null) => $wkt);
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

    app()->instance('redis', new class {
        public function get($key)
        {
            return null;
        }

        public function set($key, $value)
        {
            return true;
        }

        public function connection($name = null)
        {
            return $this;
        }

        public function __call($method, $arguments)
        {
            return null;
        }
    });
    Illuminate\Support\Facades\Redis::clearResolvedInstance('redis');

    app()->instance('geocoder', new class {
        public function geocode($query)
        {
            return $this;
        }

        public function get()
        {
            return collect();
        }

        public function __call($method, $arguments)
        {
            return $this;
        }
    });

    $schema = $connection->getSchemaBuilder();
    $tables = [
        'users'         => ['uuid', 'public_id', 'company_uuid', 'name', 'type', 'status'],
        'companies'     => ['uuid', 'public_id', 'name', 'country'],
        'places'        => ['uuid', 'public_id', 'company_uuid', 'name', 'street1', 'street2', 'city', 'province', 'postal_code', 'country', 'location', 'meta', 'type', '_key'],
        'order_configs' => ['uuid', 'public_id', 'company_uuid', 'name', 'key', 'namespace', 'flow', 'entities', 'meta', 'version', 'core_service', 'status', 'type', '_key'],
        'drivers'       => ['uuid', 'public_id', 'company_uuid', 'user_uuid', 'vehicle_uuid', 'location', 'online', 'status'],
        'vehicles'      => ['uuid', 'public_id', 'company_uuid', 'name', 'plate_number', 'year', 'make', 'model', 'location', 'online', 'status'],
    ];
    foreach ($tables as $table => $columns) {
        $schema->create($table, function ($blueprint) use ($columns) {
            $blueprint->increments('id');
            foreach ($columns as $column) {
                if (in_array($column, ['online', 'core_service'], true)) {
                    $blueprint->integer($column)->nullable();
                    continue;
                }
                $blueprint->string($column)->nullable();
            }
            $blueprint->timestamps();
            $blueprint->timestamp('deleted_at')->nullable();
        });
    }

    session(['company' => 'company-1', 'user' => 'admin-1']);
    $connection->table('companies')->insert(['uuid' => 'company-1', 'name' => 'Acme', 'country' => 'SG']);
    $connection->table('users')->insert(['uuid' => 'admin-1', 'company_uuid' => 'company-1', 'name' => 'Admin', 'type' => 'admin']);
    $connection->table('order_configs')->insert([
        'uuid'         => '66666666-6666-4666-8666-666666666666', 'public_id' => 'order_config_preview', 'company_uuid' => 'company-1',
        'name'         => 'Transport', 'key' => 'transport', 'namespace' => 'system:order-config:transport',
        'core_service' => 1, 'status' => 'active', 'version' => '0.0.1', 'flow' => '{}',
    ]);

    return $connection;
}

function fleetopsOrderPreviewCall(CreateOrderPreviewCapability $capability, string $method, ...$arguments): mixed
{
    $reflection = new ReflectionMethod(CreateOrderPreviewCapability::class, $method);
    $reflection->setAccessible(true);

    return $reflection->invoke($capability, ...$arguments);
}

function fleetopsOrderPreviewWkb(float $lat, float $lng): string
{
    return pack('V', 0) . pack('C', 1) . pack('V', 1) . pack('d', $lng) . pack('d', $lat);
}

test('draft from prompt extracts addresses schedule notes and pod flags', function () {
    $connection = fleetopsOrderPreviewBoot();
    $connection->table('places')->insert([
        ['uuid' => '11111111-1111-4111-8111-111111111111', 'public_id' => 'place_preview1', 'company_uuid' => 'company-1', 'name' => 'Warehouse A', 'street1' => '1 Industrial Way', 'city' => 'Singapore', 'country' => 'SG', 'location' => fleetopsOrderPreviewWkb(1.30, 103.80)],
        // Both ends of the prompt resolve to saved places, so the draft carries
        // a dropoff uuid rather than only the query it was asked about
        ['uuid' => '77777777-7777-4777-8777-777777777777', 'public_id' => 'place_preview3', 'company_uuid' => 'company-1', 'name' => 'Depot B', 'street1' => '3 Depot Loop', 'city' => 'Singapore', 'country' => 'SG', 'location' => fleetopsOrderPreviewWkb(1.32, 103.82)],
    ]);

    $capability = new CreateOrderPreviewCapability();
    $draft      = fleetopsOrderPreviewCall($capability, 'draftFromPrompt', 'Create an order from "Warehouse A" to "Depot B" and dispatch it 2 days from now with signature proof of delivery, notes: handle with care');

    expect($draft['order_config_uuid'])->toBe('66666666-6666-4666-8666-666666666666')
        ->and($draft['type'])->toBe('transport')
        ->and($draft['payload']['pickup_query'])->toBe('Warehouse A')
        ->and($draft['payload']['pickup_uuid'])->toBe('11111111-1111-4111-8111-111111111111')
        ->and($draft['payload']['dropoff_query'])->toBe('Depot B')
        ->and($draft['payload']['dropoff_uuid'])->toBe('77777777-7777-4777-8777-777777777777')
        ->and($draft['dispatched'])->toBeTrue()
        ->and($draft['scheduled_at'])->toBeString()
        ->and($draft['notes'])->toBe('handle with care')
        ->and($draft['pod_required'])->toBeTrue()
        ->and($draft['pod_method'])->toBe('signature');
});

test('address pairs resolve quoted from-to and labeled phrasings', function () {
    fleetopsOrderPreviewBoot();
    $capability = new CreateOrderPreviewCapability();

    expect(fleetopsOrderPreviewCall($capability, 'addressPairFromPrompt', 'order from "Alpha St 1" to "Beta Ave 2"'))->toBe(['Alpha St 1', 'Beta Ave 2'])
        ->and(fleetopsOrderPreviewCall($capability, 'addressPairFromPrompt', 'create order from Alpha Street 1 to Beta Avenue 2 with pod'))->toBe(['Alpha Street 1', 'Beta Avenue 2'])
        ->and(fleetopsOrderPreviewCall($capability, 'addressPairFromPrompt', 'order with pickup at Central Depot and dropoff at North Hub'))->toBe(['Central Depot', 'North Hub'])
        ->and(fleetopsOrderPreviewCall($capability, 'addressPairFromPrompt', 'just make me an order'))->toBe([null, null]);

    expect(fleetopsOrderPreviewCall($capability, 'cleanAddress', '  , and  Alpha   St ,'))->toBe('Alpha St')
        ->and(fleetopsOrderPreviewCall($capability, 'cleanAddress', '  '))->toBeNull();
});

test('place resolution returns serialized saved places or null', function () {
    $connection = fleetopsOrderPreviewBoot();
    $connection->table('places')->insert([
        ['uuid' => '22222222-2222-4222-8222-222222222222', 'public_id' => 'place_preview2', 'company_uuid' => 'company-1', 'name' => 'Central Depot', 'street1' => '2 Central Rd', 'city' => 'Singapore', 'country' => 'SG', 'location' => fleetopsOrderPreviewWkb(1.35, 103.85)],
    ]);

    $capability = new CreateOrderPreviewCapability();

    $resolved = fleetopsOrderPreviewCall($capability, 'resolvePlace', 'Central Depot');
    expect($resolved['uuid'])->toBe('22222222-2222-4222-8222-222222222222')
        ->and($resolved['latitude'])->not->toBeNull();

    expect(fleetopsOrderPreviewCall($capability, 'resolvePlace', null))->toBeNull()
        ->and(fleetopsOrderPreviewCall($capability, 'resolvePlace', 'Nowhere Special'))->toBeNull();

    $provisional = fleetopsOrderPreviewCall($capability, 'provisionalPlace', 'Somewhere New');
    expect($provisional['source'])->toBe('unresolved')
        ->and($provisional['address'])->toBe('Somewhere New');
});

test('controller helpers detect failed responses and unwrap orders', function () {
    fleetopsOrderPreviewBoot();
    $capability = new CreateOrderPreviewCapability();

    expect(fleetopsOrderPreviewCall($capability, 'orderController'))->toBeInstanceOf(Fleetbase\FleetOps\Http\Controllers\Internal\v1\OrderController::class)
        ->and(fleetopsOrderPreviewCall($capability, 'orderResponseFailed', new JsonResponse(['error' => 'nope'], 422)))->toBeTrue()
        ->and(fleetopsOrderPreviewCall($capability, 'orderResponseFailed', new JsonResponse(['ok' => true], 200)))->toBeFalse()
        ->and(fleetopsOrderPreviewCall($capability, 'orderFromResponse', ['order' => 'order-1']))->toBe('order-1');
});

test('order config driver and vehicle resolution match identifiers', function () {
    $connection = fleetopsOrderPreviewBoot();
    $connection->table('drivers')->insert(['uuid' => '33333333-3333-4333-8333-333333333333', 'public_id' => 'driver_preview1', 'company_uuid' => 'company-1', 'user_uuid' => 'admin-1']);
    $connection->table('vehicles')->insert(['uuid' => '44444444-4444-4444-8444-444444444444', 'public_id' => 'vehicle_preview1', 'company_uuid' => 'company-1', 'name' => 'Van 12', 'plate_number' => 'SGX1234A']);

    $capability = new CreateOrderPreviewCapability();

    expect(fleetopsOrderPreviewCall($capability, 'resolveOrderConfig', ['order_config_uuid' => '66666666-6666-4666-8666-666666666666'])?->uuid)->toBe('66666666-6666-4666-8666-666666666666')
        ->and(fleetopsOrderPreviewCall($capability, 'resolveOrderConfig', [])?->uuid)->toBe('66666666-6666-4666-8666-666666666666');

    expect(fleetopsOrderPreviewCall($capability, 'resolveDriver', ['driver' => '33333333-3333-4333-8333-333333333333'])?->uuid)->toBe('33333333-3333-4333-8333-333333333333')
        ->and(fleetopsOrderPreviewCall($capability, 'resolveDriver', ['driver_query' => 'Admin'])?->uuid)->toBe('33333333-3333-4333-8333-333333333333')
        ->and(fleetopsOrderPreviewCall($capability, 'resolveDriver', []))->toBeNull();

    expect(fleetopsOrderPreviewCall($capability, 'resolveVehicle', ['vehicle' => 'SGX1234'])?->uuid)->toBe('44444444-4444-4444-8444-444444444444')
        ->and(fleetopsOrderPreviewCall($capability, 'resolveVehicle', []))->toBeNull();
});

test('pod methods normalize configured strings and arrays', function () {
    fleetopsOrderPreviewBoot();
    $capability = new CreateOrderPreviewCapability();

    config()->set('fleetops.pod_methods', 'scan, photo, scan');
    expect(fleetopsOrderPreviewCall($capability, 'podMethods'))->toBe(['scan', 'photo']);

    config()->set('fleetops.pod_methods', ['signature']);
    expect(fleetopsOrderPreviewCall($capability, 'podMethods'))->toBe(['signature']);
});
