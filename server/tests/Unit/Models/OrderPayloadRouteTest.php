<?php

use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Payload;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;

/**
 * Covers the Order model's payload creation, file attachment, time-window
 * normalization, payload retrieval, route setting, and driver assignment
 * behavior against an in-memory SQLite fixture.
 */
class FleetOpsOrderPayloadRouteProbe extends Order
{
    protected $guarded       = [];
    public $exists           = true;
    public array $notified   = [];

    public function callProtected(string $method, mixed ...$arguments): mixed
    {
        $reflection = new ReflectionMethod(Order::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($this, ...$arguments);
    }

    public function notifyDriverAssigned(): void
    {
        $this->notified[] = 'driver-assigned';
    }
}

function fleetopsOrderPayloadRouteBoot(): SQLiteConnection
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->sqliteCreateFunction('ST_PointFromText', fn ($wkt, $srid = 0, $axisOrder = null) => $wkt);
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
        'orders'           => ['uuid', 'public_id', 'company_uuid', 'payload_uuid', 'route_uuid', 'driver_assigned_uuid', 'type', 'status', 'scheduled_at', '_key'],
        'payloads'         => ['uuid', 'public_id', 'company_uuid', 'pickup_uuid', 'dropoff_uuid', 'return_uuid', 'current_waypoint_uuid', 'type', 'meta', '_key'],
        'places'           => ['uuid', 'public_id', 'company_uuid', 'name', 'street1', 'location', '_key'],
        'routes'           => ['uuid', 'public_id', 'company_uuid', 'order_uuid', 'details', 'total_distance', 'total_time', '_key'],
        'files'            => ['uuid', 'public_id', 'company_uuid', 'key_uuid', 'key_type', 'subject_uuid', 'subject_type', 'name', 'type'],
        'drivers'          => ['uuid', 'public_id', 'company_uuid', 'user_uuid'],
        'users'            => ['uuid', 'public_id', 'company_uuid'],
        'entities'         => ['uuid', 'public_id', 'company_uuid', 'payload_uuid', 'name'],
        'waypoints'        => ['uuid', 'public_id', 'company_uuid', 'payload_uuid', 'place_uuid'],
        'tracking_numbers' => ['uuid', 'public_id', 'company_uuid', 'tracking_number', 'owner_uuid'],
    ];
    foreach ($tables as $table => $columns) {
        $schema->create($table, function ($blueprint) use ($columns) {
            $blueprint->increments('id');
            foreach ($columns as $column) {
                $blueprint->string($column)->nullable();
            }
            $blueprint->timestamps();
            $blueprint->timestamp('deleted_at')->nullable();
        });
    }

    session(['company' => 'company-1']);

    return $connection;
}

function fleetopsOrderPayloadRouteOrder(array $attributes = []): FleetOpsOrderPayloadRouteProbe
{
    $order = new FleetOpsOrderPayloadRouteProbe();
    $order->setRawAttributes(array_merge([
        'uuid'         => 'order-1',
        'public_id'    => 'order_test',
        'company_uuid' => 'company-1',
        'type'         => 'transport',
    ], $attributes), true);

    return $order;
}

test('create payload persists a payload and links it to the order', function () {
    $connection = fleetopsOrderPayloadRouteBoot();
    $connection->table('orders')->insert(['uuid' => 'order-1', 'public_id' => 'order_test', 'company_uuid' => 'company-1']);
    $connection->table('places')->insert(['uuid' => '11111111-1111-4111-8111-111111111111', 'company_uuid' => 'company-1', 'name' => 'Depot']);

    $order   = fleetopsOrderPayloadRouteOrder();
    $payload = $order->createPayload([
        'pickup_uuid' => '11111111-1111-4111-8111-111111111111',
    ]);

    expect($payload)->toBeInstanceOf(Payload::class)
        ->and($connection->table('payloads')->count())->toBe(1)
        ->and($connection->table('payloads')->value('type'))->toBe('transport');
});

test('insert payload writes directly and fires the created event', function () {
    $connection = fleetopsOrderPayloadRouteBoot();
    $connection->table('orders')->insert(['uuid' => 'order-1', 'public_id' => 'order_test', 'company_uuid' => 'company-1']);

    $order   = fleetopsOrderPayloadRouteOrder();
    $payload = $order->insertPayload(['meta' => json_encode(['origin' => 'test'])]);

    expect($payload)->toBeInstanceOf(Payload::class)
        ->and($connection->table('payloads')->count())->toBe(1)
        ->and($connection->table('payloads')->value('company_uuid'))->toBe('company-1')
        ->and($connection->table('payloads')->value('_key'))->toBe('console')
        ->and($connection->table('payloads')->value('public_id'))->toStartWith('payload_');
});

test('get payload resolves from relation database or returns null', function () {
    $connection = fleetopsOrderPayloadRouteBoot();
    $connection->table('payloads')->insert(['uuid' => '22222222-2222-4222-8222-222222222222', 'company_uuid' => 'company-1']);

    $withRelation = fleetopsOrderPayloadRouteOrder();
    $payload      = new Payload();
    $payload->setRawAttributes(['uuid' => 'payload-r'], true);
    $withRelation->setRelation('payload', $payload);
    $seen = null;
    expect($withRelation->getPayload(function ($p) use (&$seen) { $seen = $p; }))->toBe($payload)
        ->and($seen)->toBe($payload);

    $byUuid = fleetopsOrderPayloadRouteOrder(['payload_uuid' => '22222222-2222-4222-8222-222222222222']);
    $byUuid->setRelation('payload', null);
    expect($byUuid->getPayload()?->uuid)->toBe('22222222-2222-4222-8222-222222222222');

    $none = fleetopsOrderPayloadRouteOrder();
    $none->setRelation('payload', null);
    expect($none->getPayload())->toBeNull();
});

test('attach files links file records to the order', function () {
    $connection = fleetopsOrderPayloadRouteBoot();
    $connection->table('files')->insert([
        ['uuid' => 'file-1', 'company_uuid' => 'company-1', 'key_uuid' => null, 'key_type' => null, 'subject_uuid' => null, 'subject_type' => null, 'name' => 'a.png', 'type' => 'photo'],
        ['uuid' => 'file-2', 'company_uuid' => 'company-1', 'key_uuid' => null, 'key_type' => null, 'subject_uuid' => null, 'subject_type' => null, 'name' => 'b.png', 'type' => 'photo'],
    ]);

    $order = fleetopsOrderPayloadRouteOrder();

    // Empty uploads exit early without touching records
    expect($order->attachFiles([]))->toBe($order);

    $order->attachFiles(['file-1', ['uuid' => 'file-2'], null]);

    expect($connection->table('files')->whereNotNull('subject_uuid')->orWhereNotNull('key_uuid')->count())->toBe(2);
});

test('normalise time window value handles nulls datetimes and time-only values', function () {
    fleetopsOrderPayloadRouteBoot();
    $order = fleetopsOrderPayloadRouteOrder(['scheduled_at' => '2026-08-15 00:00:00']);

    expect($order->callProtected('normaliseTimeWindowValue', null))->toBeNull()
        ->and($order->callProtected('normaliseTimeWindowValue', ''))->toBeNull()
        ->and($order->callProtected('normaliseTimeWindowValue', '2026-08-20 10:15:00'))->toBe('2026-08-20 10:15:00')
        ->and($order->callProtected('normaliseTimeWindowValue', '1970-01-01 09:30:00'))->toBe('2026-08-15 09:30:00');
});

test('set route creates a route from attributes and skips empty input', function () {
    $connection = fleetopsOrderPayloadRouteBoot();
    $connection->table('orders')->insert(['uuid' => 'order-1', 'public_id' => 'order_test', 'company_uuid' => 'company-1']);

    $order = fleetopsOrderPayloadRouteOrder();

    expect($order->setRoute(null))->toBe($order)
        ->and($connection->table('routes')->count())->toBe(0);

    $order->setRoute(['details' => json_encode(['summary' => 'test-route'])]);

    expect($connection->table('routes')->count())->toBe(1)
        ->and($connection->table('routes')->value('order_uuid'))->toBe('order-1');
});

test('assign driver covers instance string and invalid identifier branches', function () {
    $connection = fleetopsOrderPayloadRouteBoot();
    $connection->table('orders')->insert(['uuid' => 'order-1', 'public_id' => 'order_test', 'company_uuid' => 'company-1']);
    $connection->table('drivers')->insert(['uuid' => 'driver-1', 'public_id' => 'driver_test', 'company_uuid' => 'company-1']);

    $order  = fleetopsOrderPayloadRouteOrder();
    $driver = new Driver();
    $driver->setRawAttributes(['uuid' => 'driver-1', 'public_id' => 'driver_test'], true);

    // Assigning the already-assigned driver is a no-op
    $assigned = fleetopsOrderPayloadRouteOrder(['driver_assigned_uuid' => 'driver-1']);
    expect($assigned->assignDriver($driver))->toBe($assigned)
        ->and($assigned->notified)->toBe([]);

    // Driver instance assignment notifies and saves
    $order->assignDriver($driver);
    expect($order->driver_assigned_uuid)->toBe('driver-1')
        ->and($order->notified)->toBe(['driver-assigned'])
        ->and($connection->table('orders')->value('driver_assigned_uuid'))->toBe('driver-1');

    // Public id resolution recurses into instance assignment
    $byPublicId = fleetopsOrderPayloadRouteOrder();
    $byPublicId->assignDriver('driver_test', true);
    expect($byPublicId->driver_assigned_uuid)->toBe('driver-1');

    // Raw uuid strings are assigned directly
    $byUuid = fleetopsOrderPayloadRouteOrder();
    $byUuid->assignDriver('33333333-3333-4333-8333-333333333333', true);
    expect($byUuid->driver_assigned_uuid)->toBe('33333333-3333-4333-8333-333333333333');

    // Unknown public ids raise an exception
    expect(fn () => fleetopsOrderPayloadRouteOrder()->assignDriver('driver_missing', true))
        ->toThrow(Exception::class, 'Invalid driver provided for assignment!');
});
