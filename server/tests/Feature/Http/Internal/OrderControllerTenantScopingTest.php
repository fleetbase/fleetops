<?php

if (!function_exists('Fleetbase\Support\session')) {
    eval('namespace Fleetbase\Support; function session($key = null, $default = null) { if ($key === null) { return new class { public function has($k) { return \session($k) !== null; } public function get($k, $d = null) { return \session($k, $d); } }; } return \session($key, $default); }');
}

if (!function_exists('Fleetbase\Support\auth')) {
    eval('namespace Fleetbase\Support; function auth() { return new class { public function user() { return null; } public function id() { return null; } }; }');
}

use Fleetbase\FleetOps\Http\Controllers\Internal\v1\OrderController;
use Fleetbase\FleetOps\Http\Requests\BulkDispatchRequest;
use Fleetbase\FleetOps\Http\Requests\CancelOrderRequest;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Http\Request;

/**
 * Covers tenant isolation on the internal OrderController.
 *
 * Every order-lifecycle action here takes its target as a caller-supplied
 * identifier in the request body or query string rather than as a bound route
 * parameter, and `fleetbase.protected` only checks that the caller holds the
 * named RBAC capability — never which company the record belongs to. Nothing
 * upstream narrows these queries, so each lookup carries the company
 * constraint itself, and these tests pin that: an identifier belonging to
 * another organization must resolve to nothing, and a request without a
 * company session must fail closed rather than query every tenant at once.
 *
 * `company-1` is the caller throughout; `company-2` is the victim tenant.
 */
class FleetOpsInternalOrderTenantScopeProbe extends OrderController
{
    public function callHelper(string $method, ...$arguments): mixed
    {
        return $this->{$method}(...$arguments);
    }
}

const FLEETOPS_TENANT_OWN_ORDER     = '55555555-5555-4555-8555-555555555501';
const FLEETOPS_TENANT_VICTIM_ORDER  = '55555555-5555-4555-8555-555555555502';
const FLEETOPS_TENANT_OWN_DRIVER    = '55555555-5555-4555-8555-555555555511';
const FLEETOPS_TENANT_VICTIM_DRIVER = '55555555-5555-4555-8555-555555555512';

function fleetopsOrderTenantBoot(): SQLiteConnection
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
    Illuminate\Support\Facades\DB::clearResolvedInstance('db');

    $schema = $connection->getSchemaBuilder();
    $tables = [
        'orders'            => ['uuid', 'public_id', 'company_uuid', 'payload_uuid', 'order_config_uuid', 'tracking_number_uuid', 'driver_assigned_uuid', 'status', 'type', 'adhoc', 'dispatched', 'started', 'scheduled_at', 'meta', '_key'],
        'payloads'          => ['uuid', 'public_id', 'company_uuid', 'pickup_uuid', 'dropoff_uuid', 'current_waypoint_uuid', 'meta', 'type'],
        'places'            => ['uuid', 'public_id', 'company_uuid', 'name', 'location'],
        'waypoints'         => ['uuid', 'public_id', 'company_uuid', 'payload_uuid', 'place_uuid', 'tracking_number_uuid', 'order', 'type'],
        'entities'          => ['uuid', 'public_id', 'company_uuid', 'payload_uuid', 'destination_uuid', 'tracking_number_uuid', 'name', 'type'],
        'drivers'           => ['uuid', 'public_id', 'company_uuid', 'user_uuid', 'vehicle_uuid', 'status', 'online', 'location', 'current_job_uuid'],
        'users'             => ['uuid', 'public_id', 'company_uuid', 'name', 'status', 'type'],
        'tracking_numbers'  => ['uuid', 'public_id', 'company_uuid', 'tracking_number', 'owner_uuid', 'owner_type', '_key'],
        'tracking_statuses' => ['uuid', 'public_id', 'company_uuid', 'tracking_number_uuid', 'status', 'details', 'code', '_key'],
        'proofs'            => ['uuid', 'public_id', 'company_uuid', 'order_uuid', 'subject_uuid', 'subject_type', 'file_uuid', 'remarks', 'raw_data', 'data'],
        'companies'         => ['uuid', 'public_id', 'name', 'country'],
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

/**
 * Seeds one order/driver/payload per tenant. The two tenants' records are
 * identical apart from `company_uuid`, so any lookup that resolves the
 * `company-2` identifier is crossing the tenant boundary.
 */
function fleetopsOrderTenantSeed(SQLiteConnection $connection): void
{
    $connection->table('users')->insert([
        ['uuid' => 'user-own', 'company_uuid' => 'company-1', 'name' => 'Own Driver'],
        ['uuid' => 'user-victim', 'company_uuid' => 'company-2', 'name' => 'Victim Driver'],
    ]);
    $connection->table('drivers')->insert([
        ['uuid' => FLEETOPS_TENANT_OWN_DRIVER, 'public_id' => 'driver_own1', 'company_uuid' => 'company-1', 'user_uuid' => 'user-own'],
        ['uuid' => FLEETOPS_TENANT_VICTIM_DRIVER, 'public_id' => 'driver_victim1', 'company_uuid' => 'company-2', 'user_uuid' => 'user-victim'],
    ]);
    $connection->table('payloads')->insert([
        ['uuid' => 'payload-own', 'company_uuid' => 'company-1'],
        ['uuid' => 'payload-victim', 'company_uuid' => 'company-2'],
    ]);
    $connection->table('tracking_numbers')->insert([
        ['uuid' => 'tn-own', 'company_uuid' => 'company-1', 'tracking_number' => 'FLB-OWN-1'],
        ['uuid' => 'tn-victim', 'company_uuid' => 'company-2', 'tracking_number' => 'FLB-VICTIM-1'],
    ]);
    $connection->table('orders')->insert([
        [
            'uuid'                 => FLEETOPS_TENANT_OWN_ORDER,
            'public_id'            => 'order_own1',
            'company_uuid'         => 'company-1',
            'payload_uuid'         => 'payload-own',
            'tracking_number_uuid' => 'tn-own',
            'driver_assigned_uuid' => FLEETOPS_TENANT_OWN_DRIVER,
            'status'               => 'created',
            'type'                 => 'transport',
        ],
        [
            'uuid'                 => FLEETOPS_TENANT_VICTIM_ORDER,
            'public_id'            => 'order_victim1',
            'company_uuid'         => 'company-2',
            'payload_uuid'         => 'payload-victim',
            'tracking_number_uuid' => 'tn-victim',
            'driver_assigned_uuid' => FLEETOPS_TENANT_VICTIM_DRIVER,
            'status'               => 'created',
            'type'                 => 'transport',
        ],
    ]);
    $connection->table('entities')->insert([
        ['uuid' => 'entity-own', 'company_uuid' => 'company-1', 'payload_uuid' => 'payload-own', 'name' => 'Own Parcel'],
        ['uuid' => 'entity-victim', 'company_uuid' => 'company-2', 'payload_uuid' => 'payload-victim', 'name' => 'Victim Parcel'],
    ]);
    $connection->table('proofs')->insert([
        ['uuid' => 'proof-own', 'public_id' => 'proof_own1', 'company_uuid' => 'company-1', 'order_uuid' => FLEETOPS_TENANT_OWN_ORDER, 'subject_uuid' => FLEETOPS_TENANT_OWN_ORDER],
        ['uuid' => 'proof-victim', 'public_id' => 'proof_victim1', 'company_uuid' => 'company-2', 'order_uuid' => FLEETOPS_TENANT_VICTIM_ORDER, 'subject_uuid' => FLEETOPS_TENANT_VICTIM_ORDER],
    ]);
}

test('order lookups resolve the callers own records', function () {
    $connection = fleetopsOrderTenantBoot();
    fleetopsOrderTenantSeed($connection);
    $probe = new FleetOpsInternalOrderTenantScopeProbe();

    expect($probe->callHelper('ordersByUuid', [FLEETOPS_TENANT_OWN_ORDER])->pluck('uuid')->all())->toBe([FLEETOPS_TENANT_OWN_ORDER])
        ->and($probe->callHelper('findOrderByUuid', FLEETOPS_TENANT_OWN_ORDER)?->public_id)->toBe('order_own1')
        ->and($probe->callHelper('findOrderById', FLEETOPS_TENANT_OWN_ORDER)?->public_id)->toBe('order_own1')
        ->and($probe->callHelper('findOrderById', 'order_own1')?->uuid)->toBe(FLEETOPS_TENANT_OWN_ORDER)
        ->and($probe->callHelper('findOrderRouteForEdit', FLEETOPS_TENANT_OWN_ORDER)?->uuid)->toBe(FLEETOPS_TENANT_OWN_ORDER)
        ->and($probe->callHelper('findOrderForStart', FLEETOPS_TENANT_OWN_ORDER)?->uuid)->toBe(FLEETOPS_TENANT_OWN_ORDER)
        ->and($probe->callHelper('findPayloadForStart', 'payload-own')?->uuid)->toBe('payload-own')
        ->and($probe->callHelper('findOrderForSchedule', 'order_own1')?->uuid)->toBe(FLEETOPS_TENANT_OWN_ORDER)
        ->and($probe->callHelper('findOrderForProofs', FLEETOPS_TENANT_OWN_ORDER)?->uuid)->toBe(FLEETOPS_TENANT_OWN_ORDER)
        ->and($probe->callHelper('findOrderForDriverPing', 'order_own1')?->uuid)->toBe(FLEETOPS_TENANT_OWN_ORDER)
        ->and($probe->callHelper('findEntityProofSubject', 'entity-own')?->uuid)->toBe('entity-own')
        ->and($probe->callHelper('resolveProof', 'proof_own1')?->uuid)->toBe('proof-own')
        ->and($probe->callHelper('resolveProof', 'proof-own')?->uuid)->toBe('proof-own');
});

test('driver lookups resolve the callers own drivers', function () {
    $connection = fleetopsOrderTenantBoot();
    fleetopsOrderTenantSeed($connection);
    $probe = new FleetOpsInternalOrderTenantScopeProbe();

    expect($probe->callHelper('findDriverByUuid', FLEETOPS_TENANT_OWN_DRIVER)?->public_id)->toBe('driver_own1')
        ->and($probe->callHelper('findDriverForStart', FLEETOPS_TENANT_OWN_DRIVER)?->public_id)->toBe('driver_own1')
        ->and($probe->callHelper('findDriverForSchedule', FLEETOPS_TENANT_OWN_DRIVER)?->public_id)->toBe('driver_own1')
        ->and($probe->callHelper('findDriverForSchedule', 'driver_own1')?->uuid)->toBe(FLEETOPS_TENANT_OWN_DRIVER);
});

test('order lookups refuse identifiers belonging to another company', function () {
    $connection = fleetopsOrderTenantBoot();
    fleetopsOrderTenantSeed($connection);
    $probe = new FleetOpsInternalOrderTenantScopeProbe();

    // Both identifier arms are covered: an unguarded
    // `where(uuid)->orWhere(public_id)->where(company_uuid)` chain would read as
    // `uuid = ? OR (public_id = ? AND company_uuid = ?)` and still resolve the
    // victim by uuid.
    expect($probe->callHelper('ordersByUuid', [FLEETOPS_TENANT_VICTIM_ORDER]))->toHaveCount(0)
        ->and($probe->callHelper('findOrderByUuid', FLEETOPS_TENANT_VICTIM_ORDER))->toBeNull()
        ->and($probe->callHelper('findOrderById', FLEETOPS_TENANT_VICTIM_ORDER))->toBeNull()
        ->and($probe->callHelper('findOrderById', 'order_victim1'))->toBeNull()
        ->and($probe->callHelper('findOrderRouteForEdit', FLEETOPS_TENANT_VICTIM_ORDER))->toBeNull()
        ->and($probe->callHelper('findOrderForStart', FLEETOPS_TENANT_VICTIM_ORDER))->toBeNull()
        ->and($probe->callHelper('findPayloadForStart', 'payload-victim'))->toBeNull()
        ->and($probe->callHelper('findOrderForSchedule', 'order_victim1'))->toBeNull()
        ->and($probe->callHelper('findOrderForProofs', FLEETOPS_TENANT_VICTIM_ORDER))->toBeNull()
        ->and($probe->callHelper('findEntityProofSubject', 'entity-victim'))->toBeNull()
        ->and($probe->callHelper('resolveProof', 'proof_victim1'))->toBeNull()
        ->and($probe->callHelper('resolveProof', 'proof-victim'))->toBeNull()
        ->and($probe->callHelper('findDriverByUuid', FLEETOPS_TENANT_VICTIM_DRIVER))->toBeNull()
        ->and($probe->callHelper('findDriverForStart', FLEETOPS_TENANT_VICTIM_DRIVER))->toBeNull()
        ->and($probe->callHelper('findDriverForSchedule', FLEETOPS_TENANT_VICTIM_DRIVER))->toBeNull()
        ->and($probe->callHelper('findDriverForSchedule', 'driver_victim1'))->toBeNull();

    // The ping lookup reports a miss the same way it reports an unknown id, so
    // the endpoint cannot be used to probe which ids exist in other tenants.
    expect(fn () => $probe->callHelper('findOrderForDriverPing', 'order_victim1'))
        ->toThrow(ModelNotFoundException::class);
});

test('the public tracking lookup finds an order by its tracking number alone', function () {
    $connection = fleetopsOrderTenantBoot();
    fleetopsOrderTenantSeed($connection);
    $probe = new FleetOpsInternalOrderTenantScopeProbe();

    // `fleet-ops/lookup` backs the public Track Order page: recipients have no session
    // and the tracking number is the credential. It must work without a company, and
    // for whichever company owns the order.
    session(['company' => null]);

    expect($probe->callHelper('findOrderByTrackingNumber', 'FLB-OWN-1')?->uuid)->toBe(FLEETOPS_TENANT_OWN_ORDER)
        ->and($probe->callHelper('findOrderByTrackingNumber', 'FLB-VICTIM-1')?->uuid)->toBe(FLEETOPS_TENANT_VICTIM_ORDER)
        ->and($probe->callHelper('findOrderByTrackingNumber', 'FLB-UNKNOWN'))->toBeNull();

    session(['company' => 'company-1']);

    expect($probe->callHelper('findOrderByTrackingNumber', 'FLB-VICTIM-1')?->uuid)->toBe(FLEETOPS_TENANT_VICTIM_ORDER);
});

test('order lookups fail closed when no company session is present', function () {
    $connection = fleetopsOrderTenantBoot();
    fleetopsOrderTenantSeed($connection);
    session(['company' => null]);
    $probe = new FleetOpsInternalOrderTenantScopeProbe();

    expect($probe->callHelper('ordersByUuid', [FLEETOPS_TENANT_OWN_ORDER, FLEETOPS_TENANT_VICTIM_ORDER]))->toHaveCount(0)
        ->and($probe->callHelper('findOrderByUuid', FLEETOPS_TENANT_OWN_ORDER))->toBeNull()
        ->and($probe->callHelper('findOrderById', 'order_own1'))->toBeNull()
        ->and($probe->callHelper('findDriverByUuid', FLEETOPS_TENANT_OWN_DRIVER))->toBeNull()
        ->and($probe->callHelper('findDriverForSchedule', 'driver_own1'))->toBeNull();
});

test('order resolution by id rejects empty identifiers without querying', function () {
    $connection = fleetopsOrderTenantBoot();
    fleetopsOrderTenantSeed($connection);
    $probe = new FleetOpsInternalOrderTenantScopeProbe();

    // The identifier is raw request input, so a non-string body value has to
    // resolve to no order rather than raising out of the endpoint.
    expect($probe->callHelper('findOrderById', null))->toBeNull()
        ->and($probe->callHelper('findOrderById', ''))->toBeNull()
        ->and($probe->callHelper('findOrderById', ['uuid' => FLEETOPS_TENANT_OWN_ORDER]))->toBeNull()
        ->and($probe->callHelper('findOrderById', 42))->toBeNull()
        ->and($probe->callHelper('findOrderForSchedule', null))->toBeNull();
});

test('bulk driver assignment only touches orders the caller owns', function () {
    $connection = fleetopsOrderTenantBoot();
    fleetopsOrderTenantSeed($connection);
    $connection->table('orders')->update(['driver_assigned_uuid' => null]);
    $controller = new OrderController();

    $response = $controller->bulkAssignDriver(Request::create('/x', 'PATCH', [
        'ids'    => [FLEETOPS_TENANT_OWN_ORDER, FLEETOPS_TENANT_VICTIM_ORDER],
        'driver' => FLEETOPS_TENANT_OWN_DRIVER,
        'silent' => true,
    ]));

    // The victim order is dropped before the update, so it is neither
    // reassigned nor counted in the response.
    expect($response->getData(true)['count'])->toBe(1)
        ->and($connection->table('orders')->where('uuid', FLEETOPS_TENANT_OWN_ORDER)->value('driver_assigned_uuid'))->toBe(FLEETOPS_TENANT_OWN_DRIVER)
        ->and($connection->table('orders')->where('uuid', FLEETOPS_TENANT_VICTIM_ORDER)->value('driver_assigned_uuid'))->toBeNull();
});

test('bulk driver assignment refuses a driver from another company', function () {
    $connection = fleetopsOrderTenantBoot();
    fleetopsOrderTenantSeed($connection);
    $controller = new OrderController();

    $response = $controller->bulkAssignDriver(Request::create('/x', 'PATCH', [
        'ids'    => [FLEETOPS_TENANT_OWN_ORDER],
        'driver' => FLEETOPS_TENANT_VICTIM_DRIVER,
        'silent' => true,
    ]));

    expect($response->getData(true)['error'] ?? '')->toContain('Invalid driver selected');
});

test('the bulk assignment update is itself scoped to the callers company', function () {
    $connection = fleetopsOrderTenantBoot();
    fleetopsOrderTenantSeed($connection);
    $connection->table('orders')->update(['driver_assigned_uuid' => null]);
    $probe  = new FleetOpsInternalOrderTenantScopeProbe();
    $driver = Driver::where('uuid', FLEETOPS_TENANT_OWN_DRIVER)->first();

    // Called directly with a victim uuid, standing in for any future caller
    // that reaches this seam without pre-filtering the ids.
    $probe->callHelper('assignDriverToOrders', [FLEETOPS_TENANT_OWN_ORDER, FLEETOPS_TENANT_VICTIM_ORDER], $driver);

    expect($connection->table('orders')->where('uuid', FLEETOPS_TENANT_OWN_ORDER)->value('driver_assigned_uuid'))->toBe(FLEETOPS_TENANT_OWN_DRIVER)
        ->and($connection->table('orders')->where('uuid', FLEETOPS_TENANT_VICTIM_ORDER)->value('driver_assigned_uuid'))->toBeNull();
});

test('bulk cancel and bulk dispatch skip orders from another company', function () {
    $connection = fleetopsOrderTenantBoot();
    fleetopsOrderTenantSeed($connection);
    $controller = new OrderController();

    $canceled = $controller->bulkCancel(Request::create('/x', 'PATCH', [
        'ids' => [FLEETOPS_TENANT_VICTIM_ORDER],
    ]));
    expect($canceled->getData(true)['count'])->toBe(0)
        ->and($connection->table('orders')->where('uuid', FLEETOPS_TENANT_VICTIM_ORDER)->value('status'))->toBe('created');

    $dispatched = $controller->bulkDispatch(BulkDispatchRequest::create('/x', 'POST', [
        'ids' => [FLEETOPS_TENANT_VICTIM_ORDER],
    ]));
    expect($dispatched->getData(true)['count'])->toBe(0)
        ->and($connection->table('orders')->where('uuid', FLEETOPS_TENANT_VICTIM_ORDER)->value('dispatched'))->toBeNull();
});

test('cancel rejects a known order uuid that belongs to another company', function () {
    $connection = fleetopsOrderTenantBoot();
    fleetopsOrderTenantSeed($connection);

    // `exists:orders,uuid` on CancelOrderRequest is a global existence check, so
    // this uuid passes validation and the controller itself has to refuse it.
    $response = (new OrderController())->cancel(
        CancelOrderRequest::create('/x', 'PATCH', ['order' => FLEETOPS_TENANT_VICTIM_ORDER])
    );

    expect($response->getData(true)['error'] ?? '')->toContain('No order found to cancel')
        ->and($connection->table('orders')->where('uuid', FLEETOPS_TENANT_VICTIM_ORDER)->value('status'))->toBe('created');
});

test('dispatch start and schedule refuse another companys order', function () {
    $connection = fleetopsOrderTenantBoot();
    fleetopsOrderTenantSeed($connection);
    $controller = new OrderController();

    $dispatched = $controller->dispatchOrder(Request::create('/x', 'PATCH', ['order' => FLEETOPS_TENANT_VICTIM_ORDER]));
    expect($dispatched->getData(true)['error'] ?? '')->toContain('No order found to dispatch');

    $started = $controller->start(Request::create('/x', 'PATCH', ['order' => FLEETOPS_TENANT_VICTIM_ORDER]));
    expect($started->getData(true)['error'] ?? '')->toContain('Unable to find order to start');

    $scheduled = $controller->scheduleOrder(Request::create('/x', 'PATCH', [
        'order'        => FLEETOPS_TENANT_VICTIM_ORDER,
        'scheduled_at' => '2026-01-01 09:00:00',
    ]));
    expect($scheduled->getData(true)['error'] ?? '')->toContain('No order found to schedule')
        ->and($connection->table('orders')->where('uuid', FLEETOPS_TENANT_VICTIM_ORDER)->value('scheduled_at'))->toBeNull()
        ->and($connection->table('drivers')->where('uuid', FLEETOPS_TENANT_VICTIM_DRIVER)->value('current_job_uuid'))->toBeNull();
});

test('schedule ignores a driver from another company', function () {
    $connection = fleetopsOrderTenantBoot();
    fleetopsOrderTenantSeed($connection);
    $connection->table('orders')->where('uuid', FLEETOPS_TENANT_OWN_ORDER)->update(['driver_assigned_uuid' => null]);

    $response = (new OrderController())->scheduleOrder(Request::create('/x', 'PATCH', [
        'order'     => FLEETOPS_TENANT_OWN_ORDER,
        'driver_id' => 'driver_victim1',
    ]));

    expect($response->getData(true)['status'])->toBe('OK')
        ->and($connection->table('orders')->where('uuid', FLEETOPS_TENANT_OWN_ORDER)->value('driver_assigned_uuid'))->toBeNull();
});

test('activity destination and next-activity endpoints refuse another companys order', function () {
    $connection = fleetopsOrderTenantBoot();
    fleetopsOrderTenantSeed($connection);
    $controller = new OrderController();

    $nextActivity = $controller->nextActivity('order_victim1', Request::create('/x', 'GET'));
    expect($nextActivity->getData(true))->toBe(['error' => 'No order found.']);

    $updateActivity = $controller->updateActivity(FLEETOPS_TENANT_VICTIM_ORDER, Request::create('/x', 'PATCH', ['activity' => []]));
    expect($updateActivity->getData(true))->toBe(['error' => 'No order found.']);

    $setDestination = $controller->setDestination(FLEETOPS_TENANT_VICTIM_ORDER, 'place-victim');
    expect($setDestination->getData(true))->toBe(['error' => 'No order found.']);

    $trackerInfo = $controller->trackerInfo(Request::create('/x', 'GET'), FLEETOPS_TENANT_VICTIM_ORDER);
    expect($trackerInfo->getData(true))->toBe(['error' => 'No order found.']);

    $waypointEtas = $controller->waypointEtas(Request::create('/x', 'GET'), FLEETOPS_TENANT_VICTIM_ORDER);
    expect($waypointEtas->getData(true))->toBe(['error' => 'No order found.']);

    $editRoute = $controller->editOrderRoute(FLEETOPS_TENANT_VICTIM_ORDER, Request::create('/x', 'PATCH'));
    expect($editRoute->getData(true)['error'] ?? '')->toContain('Unable to find order to update route for');
});

test('proofs endpoint refuses another companys order', function () {
    $connection = fleetopsOrderTenantBoot();
    fleetopsOrderTenantSeed($connection);

    $response = (new OrderController())->proofs(Request::create('/x', 'GET'), FLEETOPS_TENANT_VICTIM_ORDER);

    expect($response->getData(true)['error'] ?? '')->toContain('Unable to retrieve proof');
});
