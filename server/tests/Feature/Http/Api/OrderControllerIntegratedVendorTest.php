<?php

use Fleetbase\FleetOps\Http\Controllers\Api\v1\OrderController;
use Fleetbase\FleetOps\Http\Requests\CreateOrderRequest;
use Fleetbase\FleetOps\Http\Resources\v1\Order as OrderResource;
use Fleetbase\FleetOps\Models\IntegratedVendor;
use Fleetbase\FleetOps\Models\Order;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Covers the public API OrderController integrated-vendor branches through
 * the container-injectable vendor bridge: vendor-backed service quotes
 * creating upstream orders and assigning the vendor as facilitator, bridge
 * failures surfacing as api errors, and Order::cancel notifying the vendor
 * bridge before dispatching the canceled event.
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

if (!function_exists('Fleetbase\Observers\event')) {
    eval('namespace Fleetbase\Observers; function event($event = null, $payload = []) { return []; }');
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

class FleetOpsApiVendorOrderProbe extends OrderController
{
    protected function createOrder(array $input): Order
    {
        $order = parent::createOrder($input);
        if (!$order->uuid) {
            $order->uuid = (string) Illuminate\Support\Str::uuid();
            Order::query()->whereNull('uuid')->update(['uuid' => $order->uuid]);
        }

        return $order;
    }
}

class FleetOpsVendorCancelOrderFake extends Order
{
    public array $activityUpdates = [];

    public function getDateFormat()
    {
        return 'Y-m-d H:i:s';
    }

    public function loadMissing($relations)
    {
        return $this;
    }

    public function updateActivity(?Fleetbase\FleetOps\Flow\Activity $activity = null, $proof = null): Order
    {
        $this->activityUpdates[] = $activity;

        return $this;
    }
}

function fleetopsApiVendorOrderBoot(): SQLiteConnection
{
    if (!Illuminate\Support\Str::hasMacro('humanize')) {
        Illuminate\Support\Str::macro('humanize', fn ($value, $uppercase = true) => str_replace('_', ' ', Illuminate\Support\Str::snake((string) $value)));
    }

    $pdo = new PDO('sqlite::memory:');
    $pdo->sqliteCreateFunction('ST_PointFromText', fn ($wkt, $srid = 0, $axisOrder = null) => $wkt);
    $connection = new SQLiteConnection($pdo);
    $resolver   = new ConnectionResolver(['default' => $connection, 'mysql' => $connection]);
    $resolver->setDefaultConnection('mysql');
    EloquentModel::setConnectionResolver($resolver);
    if (!EloquentModel::getEventDispatcher()) {
        EloquentModel::setEventDispatcher(new Illuminate\Events\Dispatcher());
    }
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
    app()->instance('responsecache', new class {
        public function __call($method, $arguments)
        {
            return null;
        }
    });

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
        'service_quotes'      => ['uuid', 'public_id', 'company_uuid', 'request_id', 'service_rate_uuid', 'payload_uuid', 'integrated_vendor_uuid', 'amount', 'currency', 'meta', 'expired_at', '_key'],
        'service_quote_items' => ['uuid', 'service_quote_uuid', 'amount', 'currency', 'details', 'code'],
        'purchase_rates'      => ['uuid', 'public_id', 'company_uuid', 'customer_uuid', 'customer_type', 'service_quote_uuid', 'payload_uuid', 'order_uuid', 'transaction_uuid', 'status', 'meta', '_key'],
        'integrated_vendors'  => ['uuid', 'public_id', 'company_uuid', 'provider', 'webhook_url', 'host', 'namespace', 'credentials', 'options', 'sandbox', 'status', '_key'],
        'companies'           => ['uuid', 'public_id', 'name', 'options'],
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

    config()->set('activitylog.enabled', false);
    config()->set('activitylog.default_auth_driver', 'web');
    app()->bind(Illuminate\Contracts\Config\Repository::class, fn () => config());
    app()->instance('request', Request::create('/v1/orders', 'POST'));
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
    $connection->table('integrated_vendors')->insert(['uuid' => 'iv-api-1', 'public_id' => 'integrated_vendor_apione', 'company_uuid' => 'company-1', 'provider' => 'lalamove', 'credentials' => json_encode([]), 'sandbox' => '1', 'options' => json_encode([])]);
    $connection->table('payloads')->insert(['uuid' => '66666666-6666-4666-8666-666666666701', 'public_id' => 'payload_vendor', 'company_uuid' => 'company-1']);
    $connection->table('service_quotes')->insert(['uuid' => '66666666-6666-4666-8666-666666666702', 'public_id' => 'quote_vendorapi1', 'company_uuid' => 'company-1', 'amount' => '2500', 'currency' => 'SGD', 'meta' => json_encode([]), 'integrated_vendor_uuid' => 'iv-api-1']);

    $GLOBALS['fleetopsApiBridgeCalls'] = [];
    $GLOBALS['fleetopsApiBridgeMode']  = 'ok';
    app()->bind(Fleetbase\FleetOps\Integrations\Lalamove\Lalamove::class, function () {
        return new class {
            public function setIntegratedVendor($vendor)
            {
                return $this;
            }

            public function setRequestId($id)
            {
                return $this;
            }

            public function createOrderFromServiceQuote($serviceQuote, $request)
            {
                if ($GLOBALS['fleetopsApiBridgeMode'] === 'fail') {
                    throw new Exception('vendor bridge rejected the order');
                }

                return ['orderId' => 'vendor-api-order-1', 'metadata' => ['integrated_vendor' => 'integrated_vendor_apione']];
            }

            public function cancelFromFleetbaseOrder($order)
            {
                $GLOBALS['fleetopsApiBridgeCalls'][] = ['cancel', $order->uuid];

                return true;
            }

            public function __call($method, $arguments)
            {
                return $this;
            }
        };
    });

    return $connection;
}

test('vendor backed quotes create upstream orders and assign the vendor facilitator', function () {
    $connection = fleetopsApiVendorOrderBoot();

    $request  = CreateOrderRequest::create('/v1/orders', 'POST', [
        'type'          => 'transport',
        'payload'       => 'payload_vendor',
        'service_quote' => 'quote_vendorapi1',
        'dispatch'      => false,
    ]);
    $response = (new FleetOpsApiVendorOrderProbe())->create($request);

    expect($response)->toBeInstanceOf(OrderResource::class)
        ->and($connection->table('orders')->count())->toBe(1)
        ->and($connection->table('orders')->value('facilitator_uuid'))->toBe('iv-api-1')
        ->and((string) $connection->table('orders')->value('facilitator_type'))->toContain('IntegratedVendor');
});

test('vendor bridge failures surface as api errors before order creation', function () {
    $connection = fleetopsApiVendorOrderBoot();

    $GLOBALS['fleetopsApiBridgeMode'] = 'fail';
    $request                          = CreateOrderRequest::create('/v1/orders', 'POST', [
        'type'          => 'transport',
        'payload'       => 'payload_vendor',
        'service_quote' => 'quote_vendorapi1',
        'dispatch'      => false,
    ]);
    $response                         = (new FleetOpsApiVendorOrderProbe())->create($request);
    $GLOBALS['fleetopsApiBridgeMode'] = 'ok';

    expect($response)->toBeInstanceOf(JsonResponse::class)
        ->and(json_encode($response->getData(true)))->toContain('vendor bridge rejected the order')
        ->and($connection->table('orders')->count())->toBe(0);
});

test('canceling an integrated vendor order notifies the vendor bridge', function () {
    fleetopsApiVendorOrderBoot();

    $vendor = IntegratedVendor::where('uuid', 'iv-api-1')->first();
    expect($vendor)->toBeInstanceOf(IntegratedVendor::class);

    $order = new FleetOpsVendorCancelOrderFake();
    $order->setRawAttributes([
        'uuid'             => 'order-vendor-cancel-1',
        'company_uuid'     => 'company-1',
        'facilitator_uuid' => 'iv-api-1',
        'facilitator_type' => IntegratedVendor::class,
        'status'           => 'created',
    ], true);
    $order->setRelation('facilitator', $vendor);
    $order->setRelation('orderConfig', new class extends Fleetbase\FleetOps\Models\OrderConfig {
        public function getCanceledActivity()
        {
            return new Fleetbase\FleetOps\Flow\Activity(['key' => 'order_canceled', 'code' => 'canceled', 'status' => 'Order canceled', 'details' => 'Order was canceled']);
        }
    });

    $result = $order->cancel();

    expect($order->status)->toBe('canceled')
        ->and($order->activityUpdates)->toHaveCount(1)
        ->and($GLOBALS['fleetopsApiBridgeCalls'])->toContain(['cancel', 'order-vendor-cancel-1']);
});
