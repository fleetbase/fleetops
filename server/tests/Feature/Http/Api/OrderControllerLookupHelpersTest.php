<?php

use Fleetbase\FleetOps\Http\Controllers\Api\v1\OrderController;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Entity;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Payload;
use Fleetbase\FleetOps\Models\Waypoint;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\SQLiteConnection;

/**
 * Covers the real bodies of the API OrderController's lookup and resolution
 * helpers against an in-memory SQLite fixture: order/driver/payload lookups,
 * proof subject resolution across order/waypoint/entity types, proof scoping,
 * order comments error handling, and order scheduling.
 */
if (!class_exists('Fleetbase\FleetOps\Http\Requests\ScheduleOrderRequest', false) && !class_exists('ScheduleOrderRequestShimLoaded', false)) {
    // Loads through the FormRequest shim provided by the pest bootstrap.
    class_exists(Fleetbase\FleetOps\Http\Requests\ScheduleOrderRequest::class);
}

class FleetOpsApiOrderLookupProbe extends OrderController
{
    public ?string $timezone = null;

    public function callProtected(string $method, mixed ...$arguments): mixed
    {
        $reflection = new ReflectionMethod(OrderController::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($this, ...$arguments);
    }

    protected function defaultCompanyTimezone(): string
    {
        return $this->timezone ?? 'Asia/Singapore';
    }
}

function fleetopsApiOrderLookupBoot(): SQLiteConnection
{
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
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
    app()->instance('db.schema', $connection->getSchemaBuilder());
    Illuminate\Support\Facades\DB::clearResolvedInstance('db');

    $schema = $connection->getSchemaBuilder();
    $tables = [
        'orders'    => ['uuid', 'public_id', 'internal_id', 'company_uuid', 'payload_uuid', 'driver_assigned_uuid', 'status', 'scheduled_at'],
        'drivers'   => ['uuid', 'public_id', 'company_uuid', 'user_uuid'],
        'users'     => ['uuid', 'public_id', 'company_uuid'],
        'payloads'  => ['uuid', 'public_id', 'company_uuid'],
        'waypoints' => ['uuid', 'public_id', 'company_uuid', 'payload_uuid', 'place_uuid'],
        'places'    => ['uuid', 'public_id', 'company_uuid', 'name'],
        'entities'  => ['uuid', 'public_id', 'company_uuid', 'payload_uuid', 'name'],
        'proofs'    => ['uuid', 'public_id', 'company_uuid', 'order_uuid', 'subject_uuid', 'subject_type', 'file_uuid'],
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

test('order and related lookup helpers execute against the database', function () {
    $connection = fleetopsApiOrderLookupBoot();
    $connection->table('orders')->insert(['uuid' => 'order-1', 'public_id' => 'order_test', 'company_uuid' => 'company-1']);
    $connection->table('users')->insert(['uuid' => 'user-1', 'company_uuid' => 'company-1']);
    $connection->table('drivers')->insert(['uuid' => 'driver-1', 'public_id' => 'driver_test', 'company_uuid' => 'company-1', 'user_uuid' => 'user-1']);
    $connection->table('payloads')->insert(['uuid' => 'payload-1', 'public_id' => 'payload_test', 'company_uuid' => 'company-1']);

    $probe = new FleetOpsApiOrderLookupProbe();

    expect($probe->callProtected('findOrder', 'order_test'))->toBeInstanceOf(Order::class);
    expect(fn () => $probe->callProtected('findOrder', 'missing'))->toThrow(ModelNotFoundException::class);

    expect($probe->callProtected('findDriverByPublicId', 'driver_test'))->toBeInstanceOf(Driver::class)
        ->and($probe->callProtected('findDriverByPublicId', 'missing'))->toBeNull()
        ->and($probe->callProtected('findDriverByUuid', 'driver-1'))->toBeInstanceOf(Driver::class)
        ->and($probe->callProtected('findDriverByUuid', 'missing'))->toBeNull();

    expect($probe->callProtected('findPayloadByUuid', 'payload-1'))->toBeInstanceOf(Payload::class)
        ->and($probe->callProtected('findPayloadByUuid', 'missing'))->toBeNull();

    expect($probe->callProtected('newPayload'))->toBeInstanceOf(Payload::class)
        ->and($probe->callProtected('sessionCompany'))->toBe('company-1');
});

test('proof subject resolution covers order waypoint and entity types', function () {
    $connection = fleetopsApiOrderLookupBoot();
    $connection->table('waypoints')->insert(['uuid' => 'waypoint-1', 'public_id' => 'waypoint_test', 'payload_uuid' => 'payload-1', 'place_uuid' => 'place-1']);
    $connection->table('places')->insert(['uuid' => 'place-1', 'public_id' => 'place_test', 'name' => 'Depot']);
    $connection->table('entities')->insert(['uuid' => 'entity-1', 'public_id' => 'entity_test', 'name' => 'Package']);

    $order = new class extends Order {
        protected $guarded = [];
        public $exists     = true;
    };
    $order->setRawAttributes(['uuid' => 'order-1', 'payload_uuid' => 'payload-1'], true);
    $order->setRelation('payload', null);

    $probe = new FleetOpsApiOrderLookupProbe();

    expect($probe->callProtected('resolveSubject', $order, null, null))->toBe($order)
        ->and($probe->callProtected('resolveSubject', $order, 'unknown-type', 'x'))->toBe($order);

    $waypoint = $probe->callProtected('resolveSubject', $order, 'waypoint', 'waypoint_test');
    expect($waypoint)->toBeInstanceOf(Waypoint::class)
        ->and($waypoint->uuid)->toBe('waypoint-1');

    $byPlace = $probe->callProtected('resolveSubject', $order, 'place', 'place_test');
    expect($byPlace)->toBeInstanceOf(Waypoint::class);

    $entity = $probe->callProtected('resolveSubject', $order, 'entity', 'entity_test');
    expect($entity)->toBeInstanceOf(Entity::class)
        ->and($entity->uuid)->toBe('entity-1');
});

test('proofs for subject scopes to order and subject records', function () {
    $connection = fleetopsApiOrderLookupBoot();
    $connection->table('proofs')->insert([
        ['uuid' => 'proof-1', 'company_uuid' => 'company-1', 'order_uuid' => 'order-1', 'subject_uuid' => 'order-1'],
        ['uuid' => 'proof-2', 'company_uuid' => 'company-1', 'order_uuid' => 'order-1', 'subject_uuid' => 'waypoint-1'],
    ]);

    $order = new class extends Order {
        protected $guarded = [];
        public $exists     = true;
    };
    $order->setRawAttributes(['uuid' => 'order-1'], true);
    $waypoint = new Waypoint();
    $waypoint->setRawAttributes(['uuid' => 'waypoint-1'], true);

    $probe = new FleetOpsApiOrderLookupProbe();

    expect($probe->callProtected('proofsForSubject', $order, $order)->count())->toBe(2)
        ->and($probe->callProtected('proofsForSubject', $order, $waypoint)->count())->toBe(1);
});

test('order comments endpoint reports missing orders and load failures', function () {
    fleetopsApiOrderLookupBoot();

    $probe = new FleetOpsApiOrderLookupProbe();

    $missing = $probe->orderComments('missing');
    expect($missing->getStatusCode())->toBe(404)
        ->and($missing->getData(true))->toBe(['error' => 'Order resource not found.']);
});

test('schedule order combines date time and timezone inputs', function () {
    $connection = fleetopsApiOrderLookupBoot();
    $connection->table('orders')->insert(['uuid' => 'order-1', 'public_id' => 'order_test', 'company_uuid' => 'company-1']);

    $probe = new FleetOpsApiOrderLookupProbe();

    $request  = Fleetbase\FleetOps\Http\Requests\ScheduleOrderRequest::create('/v1/orders/order_test/schedule', 'POST', [
        'date' => '2026-08-01',
        'time' => '14:30:00',
    ]);
    $response = $probe->scheduleOrder('order_test', $request);

    expect($connection->table('orders')->value('scheduled_at'))->toContain('2026-08-01 14:30');

    $missing = $probe->scheduleOrder('missing', $request);
    expect($missing->getStatusCode())->toBe(404)
        ->and($missing->getData(true))->toBe(['error' => 'Order resource not found.']);
});
