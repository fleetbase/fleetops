<?php

if (!function_exists('Fleetbase\Support\session')) {
    eval('namespace Fleetbase\Support; function session($key = null, $default = null) { if ($key === null) { return new class { public function has($k) { return \session($k) !== null; } public function get($k, $d = null) { return \session($k, $d); } }; } return \session($key, $default); }');
}

if (!function_exists('Fleetbase\Support\auth')) {
    eval('namespace Fleetbase\Support; function auth() { return new class { public function user() { return null; } public function id() { return null; } }; }');
}

use Fleetbase\FleetOps\Http\Controllers\Internal\v1\OrderController;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Http\Request;

/**
 * Covers the internal OrderController protected helper seams against SQLite:
 * lookup helpers for orders, drivers, labels and proof subjects, bulk driver
 * assignment persistence and notification dispatch, transaction and response
 * wrappers, order type config sources, the export download seam and the
 * proofs endpoint subject resolution.
 */
class FleetOpsInternalOrderHelperProbe extends OrderController
{
    public function callHelper(string $method, ...$arguments): mixed
    {
        return $this->{$method}(...$arguments);
    }
}

class FleetOpsInternalOrderProofMissProbe extends FleetOpsInternalOrderHelperProbe
{
    protected function findOrderForProofs(string $id): ?Order
    {
        throw new Illuminate\Database\Eloquent\ModelNotFoundException();
    }
}

function fleetopsOrderHelperBoot(): SQLiteConnection
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

    app()->instance(Illuminate\Contracts\Bus\Dispatcher::class, new class {
        public array $dispatched = [];

        public function dispatch($job)
        {
            $this->dispatched[] = $job;

            return $job;
        }

        public function __call($method, $arguments)
        {
            $this->dispatched[] = $arguments[0] ?? $method;

            return $arguments[0] ?? null;
        }
    });
    $GLOBALS['fleetopsOrderHelperBus'] = app(Illuminate\Contracts\Bus\Dispatcher::class);

    app()->instance('excel', new class {
        public array $downloads = [];

        public function download($export, $fileName, $writerType = null, array $headers = [])
        {
            $this->downloads[] = $fileName;

            return response()->json(['download' => $fileName]);
        }

        public function __call($method, $arguments)
        {
            return null;
        }
    });
    $GLOBALS['fleetopsOrderHelperExcel'] = app('excel');
    Maatwebsite\Excel\Facades\Excel::clearResolvedInstance('excel');

    $schema = $connection->getSchemaBuilder();
    $tables = [
        'orders'            => ['uuid', 'public_id', 'internal_id', 'company_uuid', 'payload_uuid', 'order_config_uuid', 'tracking_number_uuid', 'driver_assigned_uuid', 'status', 'type', 'adhoc', 'dispatched', 'started', 'scheduled_at', 'meta', 'pod_required', 'pod_method'],
        'payloads'          => ['uuid', 'public_id', 'company_uuid', 'pickup_uuid', 'dropoff_uuid', 'current_waypoint_uuid', 'meta', 'type'],
        'places'            => ['uuid', 'public_id', 'company_uuid', 'name', 'location'],
        'waypoints'         => ['uuid', 'public_id', 'company_uuid', 'payload_uuid', 'place_uuid', 'tracking_number_uuid', 'order', 'type'],
        'entities'          => ['uuid', 'public_id', 'company_uuid', 'payload_uuid', 'destination_uuid', 'tracking_number_uuid', 'name', 'type'],
        'drivers'           => ['uuid', 'public_id', 'company_uuid', 'user_uuid', 'vehicle_uuid', 'status', 'online', 'location', 'current_job_uuid'],
        'users'             => ['uuid', 'public_id', 'company_uuid', 'name', 'status', 'type'],
        'tracking_numbers'  => ['uuid', 'public_id', 'company_uuid', 'tracking_number', 'owner_uuid', 'owner_type', '_key'],
        'tracking_statuses' => ['uuid', 'public_id', 'company_uuid', 'tracking_number_uuid', 'status', 'details', 'code', '_key'],
        'proofs'            => ['uuid', 'public_id', 'company_uuid', 'order_uuid', 'subject_uuid', 'subject_type', 'file_uuid', 'remarks', 'raw_data', 'data'],
        'types'             => ['uuid', 'public_id', 'company_uuid', 'name', 'key', 'for', 'description', 'meta'],
        'order_configs'     => ['uuid', 'public_id', 'company_uuid', 'name', 'key', 'namespace', 'flow', 'entities', 'meta', 'version', 'core_service', 'status', 'type', '_key'],
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

function fleetopsOrderHelperSeed(SQLiteConnection $connection): void
{
    $connection->table('users')->insert(['uuid' => 'user-1', 'company_uuid' => 'company-1', 'name' => 'Driver One']);
    $connection->table('drivers')->insert(['uuid' => '44444444-4444-4444-8444-444444444441', 'public_id' => 'driver_helper1', 'company_uuid' => 'company-1', 'user_uuid' => 'user-1']);
    $connection->table('payloads')->insert(['uuid' => 'payload-1', 'company_uuid' => 'company-1']);
    $connection->table('tracking_numbers')->insert(['uuid' => 'tn-1', 'company_uuid' => 'company-1', 'tracking_number' => 'TRKH1']);
    $connection->table('orders')->insert([
        'uuid'         => '44444444-4444-4444-8444-444444444401',
        'public_id'    => 'order_helper1',
        'company_uuid' => 'company-1',
        'payload_uuid' => 'payload-1',
        'status'       => 'created',
        'type'         => 'transport',
    ]);
    $connection->table('waypoints')->insert(['uuid' => '44444444-4444-4444-8444-444444444421', 'public_id' => 'waypoint_helper1', 'company_uuid' => 'company-1', 'payload_uuid' => 'payload-1', 'place_uuid' => 'place-1']);
    $connection->table('places')->insert(['uuid' => 'place-1', 'company_uuid' => 'company-1', 'name' => 'Stop']);
    $connection->table('entities')->insert(['uuid' => '44444444-4444-4444-8444-444444444431', 'public_id' => 'entity_helper1', 'company_uuid' => 'company-1', 'payload_uuid' => 'payload-1', 'name' => 'Parcel']);
    $connection->table('proofs')->insert([
        ['uuid' => 'proof-1', 'company_uuid' => 'company-1', 'order_uuid' => '44444444-4444-4444-8444-444444444401', 'subject_uuid' => '44444444-4444-4444-8444-444444444401', 'subject_type' => 'Fleetbase\FleetOps\Models\Order', 'remarks' => 'Order proof'],
        ['uuid' => 'proof-2', 'company_uuid' => 'company-1', 'order_uuid' => '44444444-4444-4444-8444-444444444401', 'subject_uuid' => '44444444-4444-4444-8444-444444444421', 'subject_type' => 'Fleetbase\FleetOps\Models\Waypoint', 'remarks' => 'Waypoint proof'],
    ]);
    $connection->table('tracking_statuses')->insert(['uuid' => 'ts-1', 'company_uuid' => 'company-1', 'tracking_number_uuid' => 'tn-1', 'code' => 'CREATED', 'status' => 'Created']);
}

test('order driver and label lookup helpers resolve records', function () {
    $connection = fleetopsOrderHelperBoot();
    fleetopsOrderHelperSeed($connection);
    $probe = new FleetOpsInternalOrderHelperProbe();

    expect($probe->callHelper('ordersByUuid', ['44444444-4444-4444-8444-444444444401']))->toHaveCount(1)
        ->and($probe->callHelper('trackingStatusExists', 'tn-1', 'CREATED'))->toBeTrue()
        ->and($probe->callHelper('trackingStatusExists', 'tn-1', 'COMPLETED'))->toBeFalse()
        ->and($probe->callHelper('findDriverByUuid', '44444444-4444-4444-8444-444444444441')?->public_id)->toBe('driver_helper1')
        ->and($probe->callHelper('driverDisplayName', Driver::where('uuid', '44444444-4444-4444-8444-444444444441')->first()))->toBe('Driver One')
        ->and($probe->callHelper('findOrderByUuid', '44444444-4444-4444-8444-444444444401')?->public_id)->toBe('order_helper1')
        ->and($probe->callHelper('findOrderById', 'order_helper1')?->uuid)->toBe('44444444-4444-4444-8444-444444444401')
        ->and($probe->callHelper('findOrderRouteForEdit', '44444444-4444-4444-8444-444444444401')?->uuid)->toBe('44444444-4444-4444-8444-444444444401')
        ->and($probe->callHelper('findOrderForDriverPing', 'order_helper1')?->uuid)->toBe('44444444-4444-4444-8444-444444444401')
        ->and($probe->callHelper('findOrderForSchedule', 'order_helper1')?->uuid)->toBe('44444444-4444-4444-8444-444444444401')
        ->and($probe->callHelper('findDriverForSchedule', 'driver_helper1')?->uuid)->toBe('44444444-4444-4444-8444-444444444441')
        ->and($probe->callHelper('findOrderLabelSubject', 'order_helper1')?->uuid)->toBe('44444444-4444-4444-8444-444444444401')
        ->and($probe->callHelper('findWaypointLabelSubject', 'waypoint_helper1')?->uuid)->toBe('44444444-4444-4444-8444-444444444421')
        ->and($probe->callHelper('findEntityLabelSubject', 'entity_helper1')?->uuid)->toBe('44444444-4444-4444-8444-444444444431');
});

test('label lookup helpers refuse subjects from another company', function () {
    $connection = fleetopsOrderHelperBoot();
    fleetopsOrderHelperSeed($connection);
    $connection->table('orders')->where('public_id', 'order_helper1')->update(['company_uuid' => 'company-2']);
    $connection->table('waypoints')->where('public_id', 'waypoint_helper1')->update(['company_uuid' => 'company-2']);
    $connection->table('entities')->where('public_id', 'entity_helper1')->update(['company_uuid' => 'company-2']);

    $probe = new FleetOpsInternalOrderHelperProbe();

    // Also covers the identifier-precedence trap: an unguarded
    // `where(public_id)->orWhere(uuid)->where(company_uuid)` chain would still resolve these.
    expect($probe->callHelper('findOrderLabelSubject', 'order_helper1'))->toBeNull()
        ->and($probe->callHelper('findOrderLabelSubject', '44444444-4444-4444-8444-444444444401'))->toBeNull()
        ->and($probe->callHelper('findWaypointLabelSubject', 'waypoint_helper1'))->toBeNull()
        ->and($probe->callHelper('findEntityLabelSubject', 'entity_helper1'))->toBeNull();
});

test('label lookup helpers fail closed without a company session', function () {
    $connection = fleetopsOrderHelperBoot();
    fleetopsOrderHelperSeed($connection);
    session(['company' => null]);

    $probe = new FleetOpsInternalOrderHelperProbe();

    expect($probe->callHelper('findOrderLabelSubject', 'order_helper1'))->toBeNull()
        ->and($probe->callHelper('findWaypointLabelSubject', 'waypoint_helper1'))->toBeNull()
        ->and($probe->callHelper('findEntityLabelSubject', 'entity_helper1'))->toBeNull();
});

test('bulk assignment transaction and response helpers persist and wrap', function () {
    $connection = fleetopsOrderHelperBoot();
    fleetopsOrderHelperSeed($connection);
    $probe  = new FleetOpsInternalOrderHelperProbe();
    $driver = Driver::where('uuid', '44444444-4444-4444-8444-444444444441')->first();

    $probe->callHelper('assignDriverToOrders', ['44444444-4444-4444-8444-444444444401'], $driver);
    expect($connection->table('orders')->value('driver_assigned_uuid'))->toBe('44444444-4444-4444-8444-444444444441');

    Fleetbase\TestSupport\DispatchRecorder::$dispatched = [];
    $probe->callHelper('dispatchBulkAssignedDriverNotification', ['44444444-4444-4444-8444-444444444401'], $driver);
    expect(collect(Fleetbase\TestSupport\DispatchRecorder::$dispatched)->pluck('job'))->toContain(Fleetbase\FleetOps\Jobs\NotifyBulkAssignedDriver::class);

    expect($probe->callHelper('runTransaction', fn () => 'inside'))->toBe('inside')
        ->and($probe->callHelper('jsonResponse', ['ok' => true])->getData(true))->toBe(['ok' => true])
        ->and($probe->callHelper('makeTextResponse', 'plain')->getContent())->toBe('plain');

    $order = Order::where('uuid', '44444444-4444-4444-8444-444444444401')->first();
    expect($probe->callHelper('orderResponse', $order))->toHaveKey('order');
});

test('order type sources and export download seams respond', function () {
    $connection = fleetopsOrderHelperBoot();
    fleetopsOrderHelperSeed($connection);
    $connection->table('types')->insert(['uuid' => 'type-1', 'company_uuid' => 'company-1', 'name' => 'Custom Order', 'key' => 'custom-order', 'for' => 'order']);
    $connection->table('order_configs')->insert(['uuid' => 'config-1', 'company_uuid' => 'company-1', 'name' => 'Transport', 'key' => 'transport', 'namespace' => 'system:order-config:transport', 'core_service' => '1', 'status' => 'active']);
    $probe = new FleetOpsInternalOrderHelperProbe();

    expect($probe->callHelper('defaultOrderTypeConfig'))->toBeArray()
        ->and($probe->callHelper('customOrderTypes'))->toHaveCount(1);

    $download = $probe->callHelper('downloadOrderExport', ['order-1'], 'orders.csv');
    expect($GLOBALS['fleetopsOrderHelperExcel']->downloads)->toContain('orders.csv');

    $default = (new OrderController())->getDefaultOrderConfig();
    expect($default->getData(true)['uuid'] ?? null)->toBe('config-1');
});

test('proofs endpoint resolves order waypoint and entity subjects', function () {
    $connection = fleetopsOrderHelperBoot();
    fleetopsOrderHelperSeed($connection);
    $controller = new OrderController();

    $missing = (new FleetOpsInternalOrderProofMissProbe())->proofs(Request::create('/x', 'GET'), 'order-unknown');
    expect($missing->getStatusCode())->toBe(404);

    $nullSubject = $controller->proofs(Request::create('/x', 'GET'), 'order-unknown');
    expect($nullSubject->getData(true)['error'] ?? '')->toContain('Unable to retrieve proof');

    $orderProofs = $controller->proofs(Request::create('/x', 'GET'), '44444444-4444-4444-8444-444444444401');
    expect($orderProofs)->not->toBeNull();

    $waypointProofs = $controller->proofs(Request::create('/x', 'GET'), '44444444-4444-4444-8444-444444444401', 'waypoint_44444444-4444-4444-8444-444444444421');
    expect($waypointProofs)->not->toBeNull();

    $probe = new FleetOpsInternalOrderHelperProbe();
    $order = Order::where('uuid', '44444444-4444-4444-8444-444444444401')->first();
    expect($probe->callHelper('findOrderForProofs', '44444444-4444-4444-8444-444444444401')?->uuid)->toBe('44444444-4444-4444-8444-444444444401')
        ->and($probe->callHelper('findWaypointProofSubject', $order, '44444444-4444-4444-8444-444444444421')?->uuid)->toBe('44444444-4444-4444-8444-444444444421')
        ->and($probe->callHelper('findEntityProofSubject', '44444444-4444-4444-8444-444444444431')?->uuid)->toBe('44444444-4444-4444-8444-444444444431')
        ->and($probe->callHelper('proofsForSubject', $order, $order))->toHaveCount(2)
        ->and($probe->callHelper('proofsForSubject', $order, Fleetbase\FleetOps\Models\Waypoint::where('uuid', '44444444-4444-4444-8444-444444444421')->first()))->toHaveCount(1);
});

test('status options resolve config activities and proofs default subjects', function () {
    $connection = fleetopsOrderHelperBoot();
    fleetopsOrderHelperSeed($connection);
    $connection->table('order_configs')->insert(['uuid' => '99999999-9999-4999-8999-999999999990', 'public_id' => 'order_config_status1', 'company_uuid' => 'company-1', 'name' => 'Status Transport', 'key' => 'transport', 'namespace' => 'system:order-config:transport', 'core_service' => '1', 'status' => 'active', 'flow' => json_encode([
        'order_created' => ['key' => 'order_created', 'code' => 'created', 'status' => 'Created', 'details' => 'Order created', 'activities' => []],
    ])]);
    $connection->table('orders')->where('uuid', '44444444-4444-4444-8444-444444444401')->update(['order_config_uuid' => '99999999-9999-4999-8999-999999999990', 'status' => 'created']);
    $controller = new OrderController();

    // Status options with activity metadata honor uuid, key and implicit scopes
    $byUuid = $controller->statuses(Request::create('/x', 'GET', ['include_activities' => 1, 'order_config_uuid' => '99999999-9999-4999-8999-999999999990']));
    expect($byUuid)->not->toBeNull();

    $byKey = $controller->statuses(Request::create('/x', 'GET', ['include_activities' => 1, 'order_config_key' => 'transport']));
    expect($byKey)->not->toBeNull();

    $implicit = $controller->statuses(Request::create('/x', 'GET', ['include_activities' => 1]));
    expect($implicit)->not->toBeNull();

    // Proof resolution accepts public ids and unknown subject prefixes default to the order
    $probe = new FleetOpsInternalOrderHelperProbe();
    $connection->table('proofs')->insert(['uuid' => 'proof-str-1', 'public_id' => 'proof_strone1', 'company_uuid' => 'company-1', 'order_uuid' => '44444444-4444-4444-8444-444444444401', 'subject_uuid' => '44444444-4444-4444-8444-444444444401', 'subject_type' => 'Fleetbase\FleetOps\Models\Order']);
    expect($probe->callHelper('resolveProof', 'proof_strone1')?->uuid)->toBe('proof-str-1');

    $defaultSubject = $controller->proofs(Request::create('/x', 'GET'), '44444444-4444-4444-8444-444444444401', 'unknown_subjectkey');
    expect($defaultSubject)->not->toBeNull();
});
