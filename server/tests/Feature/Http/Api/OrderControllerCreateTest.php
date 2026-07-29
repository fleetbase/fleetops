<?php

use Fleetbase\FleetOps\Http\Controllers\Api\v1\OrderController;
use Fleetbase\FleetOps\Http\Requests\CreateOrderRequest;
use Fleetbase\FleetOps\Http\Resources\v1\Order as OrderResource;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Covers the public API OrderController create() flow against an in-memory
 * SQLite fixture: order config resolution and rejection, string payload
 * resolution, driver/vehicle/facilitator/customer assignment, adhoc and
 * priority normalization, missing payload rejection, order persistence with
 * relation loading, and the finalize dispatch.
 */
if (!function_exists('Fleetbase\Support\session')) {
    eval('namespace Fleetbase\Support; function session($key = null, $default = null) { if ($key === null) { return new class { public function has($k) { return \session($k) !== null; } public function get($k, $d = null) { return \session($k, $d); } }; } return \session($key, $default); }');
}

if (!function_exists('Fleetbase\Support\auth')) {
    eval('namespace Fleetbase\Support; function auth() { return new class { public function user() { return null; } public function id() { return null; } }; }');
}

if (!function_exists('Fleetbase\FleetOps\Models\dispatch')) {
    eval('namespace Fleetbase\FleetOps\Models; function dispatch($job = null) { return new \Fleetbase\TestSupport\PendingDispatch(); }');
}

if (!function_exists('Fleetbase\FleetOps\Models\event')) {
    eval('namespace Fleetbase\FleetOps\Models; function event($event = null) { return $event; }');
}

if (!Request::hasMacro('isArray')) {
    Request::macro('isArray', fn (string $key) => is_array($this->input($key)));
}

if (!Request::hasMacro('isString')) {
    Request::macro('isString', fn (string $key) => is_string($this->input($key)));
}

if (!Request::hasMacro('or')) {
    Request::macro('or', function (array $params = [], $default = null) {
        foreach ($params as $param) {
            if ($this->has($param)) {
                return $this->input($param);
            }
        }

        return $default;
    });
}

class FleetOpsApiOrderCreateProbe extends OrderController
{
    protected function createOrder(array $input): Fleetbase\FleetOps\Models\Order
    {
        $order = parent::createOrder($input);
        // The harness does not auto-generate uuids; stamp one so downstream
        // finalize dispatching receives the expected identifier.
        if (!$order->uuid) {
            $order->uuid = (string) Illuminate\Support\Str::uuid();
            Fleetbase\FleetOps\Models\Order::query()->whereNull('uuid')->update(['uuid' => $order->uuid]);
        }

        return $order;
    }
}

function fleetopsApiOrderCreateBoot(): SQLiteConnection
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
    app()->instance('db.schema', $connection->getSchemaBuilder());
    $barcodeFake = new class {
        public function __call($method, $arguments)
        {
            return 'barcode';
        }
    };
    app()->instance('DNS2D', $barcodeFake);
    app()->instance('DNS1D', $barcodeFake);
    Illuminate\Support\Facades\DB::clearResolvedInstance('db');

    $schema = $connection->getSchemaBuilder();
    $tables = [
        'orders'              => ['uuid', 'public_id', 'internal_id', 'company_uuid', 'payload_uuid', 'route_uuid', 'order_config_uuid', 'service_quote_uuid', 'purchase_rate_uuid', 'tracking_number_uuid', 'driver_assigned_uuid', 'vehicle_assigned_uuid', 'customer_uuid', 'customer_type', 'facilitator_uuid', 'facilitator_type', 'session_uuid', 'transaction_uuid', 'status', 'type', 'dispatched', 'dispatched_at', 'scheduled_at', 'distance', 'time', 'pod_required', 'pod_method', 'started', 'started_at', 'meta', 'orchestrator_priority', 'adhoc', 'adhoc_distance', 'created_by_uuid', 'updated_by_uuid', '_key'],
        'order_configs'       => ['uuid', 'public_id', 'company_uuid', 'name', 'key', 'namespace', 'description', 'flow', 'entities', 'meta', 'version', 'core_service', 'status', 'type', '_key'],
        'payloads'            => ['uuid', 'public_id', 'company_uuid', 'pickup_uuid', 'dropoff_uuid', 'return_uuid', 'current_waypoint_uuid', 'type', 'meta', '_key'],
        'waypoints'           => ['uuid', 'public_id', 'company_uuid', 'payload_uuid', 'place_uuid', 'order', 'type', 'status', 'tracking_number_uuid', 'customer_uuid', 'customer_type', '_key'],
        'entities'            => ['uuid', 'public_id', 'company_uuid', 'payload_uuid', 'tracking_number_uuid', 'name', 'type', 'status', 'internal_id', 'customer_uuid', 'customer_type', 'destination_uuid', 'photo_uuid', 'meta', '_key'],
        'places'              => ['uuid', 'public_id', 'company_uuid', 'name', 'location', '_key'],
        'tracking_numbers'    => ['uuid', 'public_id', 'company_uuid', 'tracking_number', 'owner_uuid', 'owner_type', 'region', 'barcode', 'qr_code', 'status_uuid', 'type', '_key'],
        'tracking_statuses'   => ['uuid', 'public_id', 'company_uuid', 'tracking_number_uuid', 'code', 'status', 'details', 'location', 'city', 'province', 'country', '_key'],
        'service_quotes'      => ['uuid', 'public_id', 'company_uuid', 'amount', 'currency', 'meta', 'expired_at', '_key'],
        'service_quote_items' => ['uuid', 'service_quote_uuid', 'amount', 'currency', 'details', 'code'],
        'purchase_rates'      => ['uuid', 'public_id', 'company_uuid', 'service_quote_uuid', 'status'],
        'drivers'             => ['uuid', 'public_id', 'company_uuid', 'user_uuid', 'vehicle_uuid'],
        'users'               => ['uuid', 'public_id', 'company_uuid'],
        'vehicles'            => ['uuid', 'public_id', 'company_uuid'],
        'contacts'            => ['uuid', 'public_id', 'company_uuid', 'internal_id', 'name', 'title', 'email', 'phone', 'type', 'meta', 'user_uuid'],
        'vendors'             => ['uuid', 'public_id', 'company_uuid', 'name'],
        'integrated_vendors'  => ['uuid', 'public_id', 'company_uuid', 'provider'],
        'companies'           => ['uuid', 'public_id', 'name', 'options'],
        'company_users'       => ['uuid', 'company_uuid', 'user_uuid', 'status'],
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
    $connection->table('companies')->insert(['uuid' => 'company-1', 'name' => 'Acme']);
    $connection->table('order_configs')->insert([
        'uuid'         => 'config-1',
        'public_id'    => 'order_config_transport',
        'company_uuid' => 'company-1',
        'name'         => 'Transport',
        'key'          => 'transport',
        'namespace'    => 'system:order-config:transport',
        'core_service' => '1',
        'status'       => 'active',
        'version'      => '0.0.1',
        'flow'         => json_encode([]),
    ]);

    return $connection;
}

test('create rejects invalid order types', function () {
    fleetopsApiOrderCreateBoot();

    $request  = CreateOrderRequest::create('/v1/orders', 'POST', ['type' => 'not-a-real-type']);
    $response = (new FleetOpsApiOrderCreateProbe())->create($request);

    expect($response)->toBeInstanceOf(JsonResponse::class)
        ->and($response->getData(true))->toBe(['error' => 'Invalid order `type` or `order_config` provided.']);
});

test('create persists an order with assignments from a string payload', function () {
    $connection = fleetopsApiOrderCreateBoot();
    $connection->table('payloads')->insert(['uuid' => '66666666-6666-4666-8666-666666666666', 'public_id' => 'payload_test', 'company_uuid' => 'company-1']);
    $connection->table('users')->insert(['uuid' => 'user-1', 'company_uuid' => 'company-1']);
    $connection->table('drivers')->insert(['uuid' => 'driver-1', 'public_id' => 'driver_test', 'company_uuid' => 'company-1', 'user_uuid' => 'user-1', 'vehicle_uuid' => 'vehicle-1']);
    $connection->table('vehicles')->insert(['uuid' => 'vehicle-1', 'public_id' => 'vehicle_test', 'company_uuid' => 'company-1']);
    $connection->table('vendors')->insert(['uuid' => 'vendor-1', 'public_id' => 'vendor_test', 'company_uuid' => 'company-1', 'name' => 'Facilitator']);
    $connection->table('contacts')->insert(['uuid' => 'contact-1', 'public_id' => 'contact_test', 'company_uuid' => 'company-1', 'name' => 'Customer']);

    $request = CreateOrderRequest::create('/v1/orders', 'POST', [
        'type'        => 'transport',
        'payload'     => 'payload_test',
        'driver'      => 'driver_test',
        'vehicle'     => 'vehicle_test',
        'facilitator' => 'vendor_test',
        'customer'    => 'contact_test',
        'adhoc'       => 'true',
        'dispatch'    => false,
    ]);
    $response = (new FleetOpsApiOrderCreateProbe())->create($request);

    expect($response)->toBeInstanceOf(OrderResource::class)
        ->and($connection->table('orders')->count())->toBe(1)
        ->and($connection->table('orders')->value('payload_uuid'))->toBe('66666666-6666-4666-8666-666666666666')
        ->and($connection->table('orders')->value('driver_assigned_uuid'))->toBe('driver-1')
        ->and($connection->table('orders')->value('vehicle_assigned_uuid'))->toBe('vehicle-1')
        ->and($connection->table('orders')->value('facilitator_uuid'))->toBe('vendor-1')
        ->and($connection->table('orders')->value('customer_uuid'))->toBe('contact-1')
        ->and($connection->table('orders')->value('adhoc'))->toBe('1')
        ->and($connection->table('orders')->value('orchestrator_priority'))->toBe('50');
});

test('create builds a customer contact from array input', function () {
    $connection = fleetopsApiOrderCreateBoot();
    $connection->table('payloads')->insert(['uuid' => '66666666-6666-4666-8666-666666666666', 'public_id' => 'payload_test', 'company_uuid' => 'company-1']);

    $request = CreateOrderRequest::create('/v1/orders', 'POST', [
        'type'     => 'transport',
        'payload'  => 'payload_test',
        'customer' => ['name' => 'New Customer', 'email' => 'newcustomer@example.com'],
        'dispatch' => false,
    ]);
    $response = (new FleetOpsApiOrderCreateProbe())->create($request);

    expect($response)->toBeInstanceOf(OrderResource::class)
        ->and($connection->table('contacts')->count())->toBe(1)
        ->and($connection->table('contacts')->value('type'))->toBe('customer')
        ->and($connection->table('orders')->count())->toBe(1);
});

test('create rejects orders without a resolvable payload', function () {
    fleetopsApiOrderCreateBoot();

    $request  = CreateOrderRequest::create('/v1/orders', 'POST', [
        'type'     => 'transport',
        'payload'  => 'payload_missing',
        'dispatch' => false,
    ]);
    $response = (new FleetOpsApiOrderCreateProbe())->create($request);

    expect($response)->toBeInstanceOf(JsonResponse::class)
        ->and($response->getData(true))->toBe(['error' => 'Attempted to attach invalid payload to order.']);
});

test('create defaults the order type to transport', function () {
    $connection = fleetopsApiOrderCreateBoot();
    $connection->table('payloads')->insert(['uuid' => '66666666-6666-4666-8666-666666666666', 'public_id' => 'payload_test', 'company_uuid' => 'company-1']);

    // Omitting the type falls back to transport
    $request = CreateOrderRequest::create('/v1/orders', 'POST', [
        'payload'  => 'payload_test',
        'dispatch' => false,
    ]);
    $response = (new FleetOpsApiOrderCreateProbe())->create($request);

    expect($response)->toBeInstanceOf(OrderResource::class)
        ->and($connection->table('orders')->value('type'))->toBe('transport');
});

test('create surfaces customer identity conflicts as api errors', function () {
    $connection = fleetopsApiOrderCreateBoot();
    $connection->table('payloads')->insert(['uuid' => '66666666-6666-4666-8666-666666666666', 'public_id' => 'payload_test', 'company_uuid' => 'company-1']);

    // Identity conflicts raised while resolving the customer are reported
    // verbatim rather than aborting the request
    $conflicting = new class extends OrderController {
        protected function firstOrCreateCustomerContact(array $attributes, array $values): Fleetbase\FleetOps\Models\Contact
        {
            throw new Fleetbase\FleetOps\Exceptions\CustomerUserConflictException('A user already owns this email.');
        }
    };
    $conflict = $conflicting->create(CreateOrderRequest::create('/v1/orders', 'POST', [
        'type'     => 'transport',
        'payload'  => 'payload_test',
        'customer' => ['name' => 'Conflicted', 'email' => 'conflict@example.com'],
        'dispatch' => false,
    ]));
    expect($conflict->getData(true)['error'] ?? '')->toBe('A user already owns this email.');

    // Duplicate user accounts report their own message
    $duplicate = new class extends OrderController {
        protected function firstOrCreateCustomerContact(array $attributes, array $values): Fleetbase\FleetOps\Models\Contact
        {
            throw new Fleetbase\FleetOps\Exceptions\UserAlreadyExistsException('That user already exists.');
        }
    };
    $existing = $duplicate->create(CreateOrderRequest::create('/v1/orders', 'POST', [
        'type'     => 'transport',
        'payload'  => 'payload_test',
        'customer' => ['name' => 'Duplicate', 'email' => 'duplicate@example.com'],
        'dispatch' => false,
    ]));
    expect($existing->getData(true)['error'] ?? '')->toBe('That user already exists.');
});
