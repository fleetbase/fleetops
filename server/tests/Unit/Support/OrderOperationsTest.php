<?php

if (!function_exists('Fleetbase\Support\session')) {
    eval('namespace Fleetbase\Support; function session($key = null, $default = null) { if ($key === null) { return new class { public function has($k) { return \session($k) !== null; } public function get($k, $d = null) { return \session($k, $d); } }; } return \session($key, $default); }');
}

if (!function_exists('Fleetbase\Support\auth')) {
    eval('namespace Fleetbase\Support; function auth() { return new class { public function user() { return null; } public function id() { return null; } }; }');
}

if (!function_exists('event')) {
    function event(...$arguments)
    {
        return app('events')->dispatch(...$arguments);
    }
}

use Fleetbase\FleetOps\Console\Commands\ExpireOrderOffers;
use Fleetbase\FleetOps\Events\OrderChanged;
use Fleetbase\FleetOps\Events\OrderOfferChanged;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Entity;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Payload;
use Fleetbase\FleetOps\Support\OrderEdit;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Carbon;

/**
 * Covers order edits against SQLite: replacing a payload's entities, keeping the
 * driver's stop when an in-progress route changes, the order.changed event an edit
 * commits, and the driver offer lifecycle with its expiry command.
 */
class FleetOpsOrderOpsEventRecorder
{
    public array $dispatched = [];

    public function dispatch($event, $payload = [], $halt = false)
    {
        if (is_object($event)) {
            $this->dispatched[] = $event;
        }

        return $halt ? null : [];
    }

    public function of(string $class): array
    {
        return array_values(array_filter($this->dispatched, fn ($event) => $event instanceof $class));
    }

    public function __call($method, $arguments)
    {
        return null;
    }
}

/**
 * Runs only the uuid and public id generation the models register, none of the
 * observers and tracking number hooks, which reach for services the harness lacks.
 */
class FleetOpsOrderOpsModelDispatcher extends Illuminate\Events\Dispatcher
{
    public function listen($events, $listener = null)
    {
        if (!$listener instanceof Closure) {
            return;
        }

        $file = (string) (new ReflectionFunction($listener))->getFileName();
        if (str_contains($file, 'HasUuid') || str_contains($file, 'HasPublicId')) {
            parent::listen($events, $listener);
        }
    }
}

class FleetOpsOrderOpsDriver extends Driver
{
    public array $notified = [];

    public function notify($instance)
    {
        $this->notified[] = $instance;
    }
}

function fleetopsOrderOpsBoot(): SQLiteConnection
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->sqliteCreateFunction('ST_PointFromText', fn ($wkt, $srid = 0, $axisOrder = null) => $wkt);
    $pdo->sqliteCreateFunction('ST_GeomFromText', fn ($wkt, $srid = 0, $axisOrder = null) => $wkt);
    $connection = new SQLiteConnection($pdo);
    $resolver   = new ConnectionResolver(['default' => $connection, 'mysql' => $connection]);
    $resolver->setDefaultConnection('mysql');
    EloquentModel::setConnectionResolver($resolver);
    // models register their uuid hooks once, when first booted, so every test shares one dispatcher
    static $modelDispatcher;
    EloquentModel::setEventDispatcher($modelDispatcher ??= new FleetOpsOrderOpsModelDispatcher());
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
    class_exists(Fleetbase\FleetOps\Models\Contact::class);

    foreach (['DNS2D', 'DNS1D'] as $barcode) {
        app()->instance($barcode, new class {
            public function __call($method, $arguments)
            {
                return 'barcode';
            }
        });
    }

    app()->instance('events', new FleetOpsOrderOpsEventRecorder());
    app()->instance('responsecache', new class {
        public function __call($method, $arguments)
        {
            return null;
        }
    });

    $schema = $connection->getSchemaBuilder();
    $tables = [
        'orders'            => ['uuid', 'public_id', 'company_uuid', 'payload_uuid', 'tracking_number_uuid', 'driver_assigned_uuid', 'customer_uuid', 'customer_type', 'order_config_uuid', 'status', 'type', 'notes', 'scheduled_at', 'started', 'dispatched', 'adhoc', 'distance', 'time', 'meta', 'orchestrator_priority', '_key'],
        'payloads'          => ['uuid', 'public_id', 'company_uuid', 'pickup_uuid', 'dropoff_uuid', 'return_uuid', 'current_waypoint_uuid', 'cod_amount', 'cod_currency', 'type', 'meta', '_key'],
        'places'            => ['uuid', 'public_id', 'company_uuid', 'owner_uuid', 'name', 'street1', 'city', 'country', 'location', 'meta', 'type', '_key', '_import_id'],
        'waypoints'         => ['uuid', 'public_id', 'company_uuid', 'payload_uuid', 'place_uuid', 'tracking_number_uuid', 'customer_uuid', 'customer_type', 'order', 'type', '_import_id', '_key'],
        'entities'          => ['uuid', 'public_id', 'internal_id', 'company_uuid', 'payload_uuid', 'destination_uuid', 'tracking_number_uuid', 'customer_uuid', 'customer_type', 'photo_uuid', 'name', 'type', 'price', 'meta', 'qr_code', 'barcode', '_import_id', '_key'],
        'drivers'           => ['uuid', 'public_id', 'company_uuid', 'user_uuid', 'current_job_uuid', 'status', '_key'],
        'contacts'          => ['uuid', 'public_id', 'company_uuid', 'name', 'type', 'meta', '_key'],
        'tracking_numbers'  => ['uuid', 'public_id', 'company_uuid', 'tracking_number', 'owner_uuid', 'owner_type', 'region', 'qr_code', 'barcode', 'status_uuid', '_key'],
        'companies'         => ['uuid', 'public_id', 'name', 'country'],
        'tracking_statuses' => ['uuid', 'public_id', 'company_uuid', 'tracking_number_uuid', 'code', 'status', 'details', 'location', 'city', 'province', 'postal_code', 'country', 'complete', '_key'],
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

    session(['company' => 'company-1', 'api_key' => 'console', 'user' => null]);
    $connection->table('companies')->insert(['uuid' => 'company-1', 'name' => 'Acme', 'country' => 'SG']);
    $connection->table('places')->insert([
        ['uuid' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'public_id' => 'place_pickup', 'company_uuid' => 'company-1', 'name' => 'Store', 'street1' => '1 Store Rd'],
        ['uuid' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 'public_id' => 'place_drop_old', 'company_uuid' => 'company-1', 'name' => 'Old Drop', 'street1' => '2 Old Rd'],
        ['uuid' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc', 'public_id' => 'place_drop_new', 'company_uuid' => 'company-1', 'name' => 'Tower B', 'street1' => '8 Marina View'],
        ['uuid' => 'dddddddd-dddd-4ddd-8ddd-dddddddddddd', 'public_id' => 'place_stop_d', 'company_uuid' => 'company-1', 'name' => 'Stop D', 'street1' => '4 D St'],
    ]);
    $connection->table('drivers')->insert(['uuid' => 'driver-1', 'public_id' => 'driver_one', 'company_uuid' => 'company-1', 'current_job_uuid' => 'order-1']);

    return $connection;
}

function fleetopsOrderOpsEvents(): FleetOpsOrderOpsEventRecorder
{
    return app('events');
}

function fleetopsOrderOpsOrder(SQLiteConnection $connection, array $order = [], array $payload = []): Order
{
    $connection->table('payloads')->insert(array_merge([
        'uuid'                  => 'payload-1',
        'public_id'             => 'payload_one',
        'company_uuid'          => 'company-1',
        'pickup_uuid'           => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
        'dropoff_uuid'          => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
        'current_waypoint_uuid' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
    ], $payload));
    $connection->table('orders')->insert(array_merge([
        'uuid'         => 'order-1',
        'public_id'    => 'order_one',
        'company_uuid' => 'company-1',
        'payload_uuid' => 'payload-1',
        'status'       => 'created',
    ], $order));

    return Order::where('uuid', 'order-1')->first();
}

test('sync entities updates named entities creates new ones and deletes the rest while set entities appends', function () {
    $connection = fleetopsOrderOpsBoot();
    $order      = fleetopsOrderOpsOrder($connection);
    $payload    = $order->payload;

    $payload->setEntities([['name' => 'Latte', 'price' => '450'], ['name' => 'Muffin', 'price' => '300']]);
    $latte  = Entity::where('name', 'Latte')->first();
    $muffin = Entity::where('name', 'Muffin')->first();

    // resending the same list through setEntities duplicates it
    $payload->setEntities([['name' => 'Latte', 'price' => '450']]);
    expect(Entity::where('payload_uuid', 'payload-1')->count())->toBe(3);
    Entity::where('name', 'Latte')->where('uuid', '!=', $latte->uuid)->get()->each->forceDelete();

    expect($payload->entitiesListOnlyNamesExisting([['id' => $latte->public_id]]))->toBeTrue()
        ->and($payload->entitiesListOnlyNamesExisting([['id' => $latte->public_id], ['name' => 'New']]))->toBeFalse();

    $result = $payload->syncEntities([
        ['id' => $latte->public_id, 'name' => 'Latte', 'price' => '500'],
        ['name' => 'Croissant', 'price' => '350'],
    ]);

    $names = Entity::where('payload_uuid', 'payload-1')->orderBy('name')->pluck('name')->all();

    expect($names)->toBe(['Croissant', 'Latte'])
        ->and(Entity::where('uuid', $latte->uuid)->value('price'))->toBe('500')
        ->and($result['updated'])->toBe([$latte->uuid])
        ->and($result['deleted'])->toBe([$muffin->uuid])
        ->and($result['created'])->toHaveCount(1)
        ->and($payload->syncEntities([['uuid' => $latte->uuid, 'name' => 'Latte', 'price' => '500']])['updated'])->toBe([]);
});

test('route signatures report changed parts and a not started order restarts at the first stop', function () {
    $connection = fleetopsOrderOpsBoot();
    $order      = fleetopsOrderOpsOrder($connection, [], ['current_waypoint_uuid' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb']);
    $payload    = $order->payload;
    $before     = $payload->routeSignature();

    $connection->table('payloads')->update(['dropoff_uuid' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc']);
    $payload = Payload::where('uuid', 'payload-1')->first();

    expect(Payload::routeChanges($before, $payload->routeSignature()))->toBe(['dropoff'])
        ->and(Payload::routeChanges($before, $before))->toBe([]);

    $payload->reconcileCurrentWaypoint($before, false);

    expect($connection->table('payloads')->value('current_waypoint_uuid'))->toBe('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa');
});

test('an in progress order keeps the driver on their stop or moves them to its replacement', function () {
    $connection = fleetopsOrderOpsBoot();

    // heading to the pickup, the dropoff changes: the pickup stays current
    $order   = fleetopsOrderOpsOrder($connection, ['status' => 'driver_enroute', 'started' => '1']);
    $before  = $order->payload->routeSignature();
    $connection->table('payloads')->update(['dropoff_uuid' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc']);
    Payload::where('uuid', 'payload-1')->first()->reconcileCurrentWaypoint($before, true);
    expect($connection->table('payloads')->value('current_waypoint_uuid'))->toBe('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa');

    // heading to the dropoff, the dropoff changes: the new dropoff becomes current
    $connection->table('payloads')->update(['dropoff_uuid' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 'current_waypoint_uuid' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb']);
    $before = Payload::where('uuid', 'payload-1')->first()->routeSignature();
    $connection->table('payloads')->update(['dropoff_uuid' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc']);
    Payload::where('uuid', 'payload-1')->first()->reconcileCurrentWaypoint($before, true);
    expect($connection->table('payloads')->value('current_waypoint_uuid'))->toBe('cccccccc-cccc-4ccc-8ccc-cccccccccccc');

    // a multi-stop route loses the stop the driver was heading to: the next stop takes over
    $connection->table('payloads')->update(['pickup_uuid' => null, 'dropoff_uuid' => null, 'current_waypoint_uuid' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb']);
    $connection->table('waypoints')->insert([
        ['uuid' => 'wp-1', 'company_uuid' => 'company-1', 'payload_uuid' => 'payload-1', 'place_uuid' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 'order' => 0],
        ['uuid' => 'wp-2', 'company_uuid' => 'company-1', 'payload_uuid' => 'payload-1', 'place_uuid' => 'dddddddd-dddd-4ddd-8ddd-dddddddddddd', 'order' => 1],
    ]);
    $before = Payload::where('uuid', 'payload-1')->first()->routeSignature();
    $connection->table('waypoints')->where('uuid', 'wp-1')->update(['deleted_at' => now()]);
    Payload::where('uuid', 'payload-1')->first()->reconcileCurrentWaypoint($before, true);
    expect($connection->table('payloads')->value('current_waypoint_uuid'))->toBe('dddddddd-dddd-4ddd-8ddd-dddddddddddd');
});

test('committing a dropoff change on a dispatched order keeps its stop and announces order changed', function () {
    $connection = fleetopsOrderOpsBoot();
    $order      = fleetopsOrderOpsOrder($connection, ['status' => 'dispatched', 'driver_assigned_uuid' => 'driver-1'], ['cod_amount' => '5000']);
    $driver     = new FleetOpsOrderOpsDriver();
    $driver->setRawAttributes(['uuid' => 'driver-1', 'public_id' => 'driver_one', 'company_uuid' => 'company-1'], true);
    $order->setRelation('driverAssigned', $driver);

    $edit = OrderEdit::begin($order);

    // the edit is allowed: route changes are open while a driver has the order
    $edit->guard($edit->requestedChanges(['dropoff' => ['id' => 'place_drop_new']]));

    $connection->table('payloads')->update(['dropoff_uuid' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc']);
    $changes = $edit->commit(['total' => 8400, 'currency' => 'SGD', 'changed_by' => 'Jon (store)']);

    $events = fleetopsOrderOpsEvents()->of(OrderChanged::class);

    expect($changes)->toBe(['dropoff'])
        ->and($connection->table('payloads')->value('current_waypoint_uuid'))->toBe('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa')
        ->and($events)->toHaveCount(1)
        ->and($events[0]->broadcastAs())->toBe('order.changed')
        ->and($events[0]->channelNames)->toBe(['order.order_one', 'order.order-1', 'driver.driver_one', 'driver.driver-1', 'company.company-1'])
        ->and($events[0]->data)->toMatchArray([
            'id'         => 'order_one',
            'changes'    => ['dropoff'],
            'summary'    => 'Drop-off changed to TOWER B - 8 MARINA VIEW',
            'cod_amount' => 5000,
            'total'      => 8400,
            'currency'   => 'SGD',
            'changed_by' => 'Jon (store)',
        ])
        ->and($events[0]->data['dropoff']['id'])->toBe('place_drop_new')
        ->and($events[0]->broadcastWith()['data'])->toBe($events[0]->data);
});

test('order changed pushes the assigned driver only for changes that concern them', function () {
    $connection = fleetopsOrderOpsBoot();
    $order      = fleetopsOrderOpsOrder($connection, ['status' => 'dispatched', 'driver_assigned_uuid' => 'driver-1']);
    $driver     = new FleetOpsOrderOpsDriver();
    $driver->setRawAttributes(['uuid' => 'driver-1', 'public_id' => 'driver_one', 'company_uuid' => 'company-1'], true);
    $order->setRelation('driverAssigned', $driver);

    $event = OrderChanged::dispatchFor($order, ['dropoff', 'items', 'dropoff'], 'Drop-off changed', ['changed_by' => 'Jon (store)', 'dropoff' => null]);

    expect($event->data['changes'])->toBe(['dropoff', 'items'])
        ->and($driver->notified)->toHaveCount(1)
        ->and($driver->notified[0])->toBeInstanceOf(Fleetbase\FleetOps\Notifications\OrderChanged::class)
        ->and($driver->notified[0]->data)->toBe(['type' => 'order_changed', 'id' => 'order_one', 'changes' => ['dropoff', 'items']])
        ->and($driver->notified[0]->message)->toBe('Drop-off changed');

    OrderChanged::dispatchFor($order, ['contact'], 'Customer changed');
    OrderChanged::dispatchFor($order, ['items'], 'Items changed', [], false);

    expect($driver->notified)->toHaveCount(1)
        ->and(OrderChanged::dispatchFor($order, []))->toBeNull()
        ->and(fleetopsOrderOpsEvents()->of(OrderChanged::class))->toHaveCount(3);
});

test('an edit that changes nothing announces nothing and item and notes changes are detected', function () {
    $connection = fleetopsOrderOpsBoot();
    $order      = fleetopsOrderOpsOrder($connection);

    expect(OrderEdit::begin($order)->commit())->toBe([])
        ->and(fleetopsOrderOpsEvents()->of(OrderChanged::class))->toBe([]);

    $edit = OrderEdit::begin($order);
    $order->payload->setEntities([['name' => 'Latte']]);
    $order->notes = 'Ring twice';
    $order->save();

    expect($edit->commit())->toBe(['items', 'notes'])
        ->and($connection->table('payloads')->value('current_waypoint_uuid'))->toBe('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa')
        ->and(fleetopsOrderOpsEvents()->of(OrderChanged::class)[0]->data['summary'])->toBe('Items changed · Notes updated');
});

test('requested changes name the policy fields an edit touches', function () {
    $connection = fleetopsOrderOpsBoot();
    $order      = fleetopsOrderOpsOrder($connection, ['status' => 'ready', 'scheduled_at' => '2026-10-13 12:00:00', 'notes' => 'Same'], ['cod_amount' => '0']);
    $order->payload->setEntities([['name' => 'Latte']]);
    $latte = Entity::where('name', 'Latte')->first();
    $edit  = OrderEdit::begin($order);

    expect($edit->requestedChanges([
        'dropoff'      => ['id' => 'place_drop_new'],
        'entities'     => [['id' => $latte->public_id]],
        'scheduled_at' => '2026-10-13 11:00:00',
        'notes'        => 'Same',
        'cod_amount'   => 0,
    ], OrderEdit::ENTITIES_REPLACE))->toBe([
        'dropoff'  => true,
        'items'    => ['adds' => false],
        'schedule' => ['from' => '2026-10-13T12:00:00Z', 'to' => '2026-10-13 11:00:00'],
    ]);

    // ready: removing items and an earlier time pass, adding an item does not
    $edit->guard($edit->requestedChanges(['entities' => [['id' => $latte->public_id]], 'scheduled_at' => '2026-10-13 11:00:00'], OrderEdit::ENTITIES_REPLACE));

    expect(fn () => $edit->guard($edit->requestedChanges(['entities' => [['name' => 'Cake']]])))
        ->toThrow(Fleetbase\FleetOps\Exceptions\OrderEditLockedException::class, 'Once the order is ready, items can only be removed.')
        ->and(OrderEdit::entitiesMode('replace'))->toBe('replace')
        ->and(OrderEdit::entitiesMode('sync'))->toBe('replace')
        ->and(OrderEdit::entitiesMode(null))->toBe('append');
});
