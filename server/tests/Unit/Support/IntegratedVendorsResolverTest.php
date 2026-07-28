<?php

if (!function_exists('Fleetbase\Support\session')) {
    eval('namespace Fleetbase\Support; function session($key = null, $default = null) { if ($key === null) { return new class { public function has($k) { return \session($k) !== null; } public function get($k, $d = null) { return \session($k, $d); } }; } return \session($key, $default); }');
}

use Fleetbase\FleetOps\Models\IntegratedVendor;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Proof;
use Fleetbase\FleetOps\Models\Waypoint;
use Fleetbase\FleetOps\Support\IntegratedVendors;
use Fleetbase\FleetOps\Support\ResolvedIntegratedVendor;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;

/**
 * Covers the integrated vendor resolver registry and the tracking number
 * trait against SQLite: resolver lookup with magic getters and setters,
 * bridge, service and country bridge instantiation, callback dispatching
 * through configured bridge methods, static service types, tracking number
 * creation on model insert, proof resolution, and activity template string
 * fallbacks.
 */
class FleetOpsVendorBridgeProbe
{
    public static array $calls = [];

    public function __construct(public ?string $apiKey = null, public ?string $apiSecret = null, public bool $sandbox = false, public $market = null)
    {
    }

    public function setIntegratedVendor($vendor)
    {
        static::$calls[] = ['setIntegratedVendor'];

        return $this;
    }

    public function setWebhook(?string $url = null)
    {
        static::$calls[] = ['setWebhook', $url];

        return $this;
    }
}

function fleetopsVendorResolverBoot(): SQLiteConnection
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->sqliteCreateFunction('ST_PointFromText', fn ($wkt, $srid = 0, $axisOrder = null) => $wkt);
    $pdo->sqliteCreateFunction('ST_GeomFromText', fn ($wkt, $srid = 0, $axisOrder = null) => $wkt);
    $connection = new SQLiteConnection($pdo);
    $resolver   = new ConnectionResolver(['default' => $connection, 'mysql' => $connection]);
    $resolver->setDefaultConnection('mysql');
    EloquentModel::setConnectionResolver($resolver);
    EloquentModel::setEventDispatcher(new Illuminate\Events\Dispatcher());
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

    app()->instance('log', new class {
        public function __call($method, $arguments)
        {
            return null;
        }
    });
    Illuminate\Support\Facades\Log::clearResolvedInstance('log');

    app()->instance('responsecache', new class {
        public function __call($method, $arguments)
        {
            return null;
        }
    });

    // Service and market bridge construction require details, so bind instances
    app()->instance(Fleetbase\FleetOps\Integrations\Lalamove\LalamoveServiceType::class, new Fleetbase\FleetOps\Integrations\Lalamove\LalamoveServiceType(['key' => 'MOTORCYCLE']));
    app()->instance(Fleetbase\FleetOps\Integrations\Lalamove\LalamoveMarket::class, new Fleetbase\FleetOps\Integrations\Lalamove\LalamoveMarket(['code' => 'SG']));

    $schema = $connection->getSchemaBuilder();
    $tables = [
        'waypoints'         => ['uuid', 'public_id', 'company_uuid', 'payload_uuid', 'place_uuid', 'tracking_number_uuid', 'order', 'type', '_key'],
        'tracking_numbers'  => ['uuid', 'public_id', 'company_uuid', 'tracking_number', 'owner_uuid', 'owner_type', 'region', 'location', 'qr_code', 'barcode', 'status_uuid', '_key'],
        'proofs'            => ['uuid', 'public_id', 'company_uuid', 'order_uuid', 'subject_uuid', 'subject_type', 'file_uuid', 'remarks', 'raw_data', 'data', '_key'],
        'places'            => ['uuid', 'public_id', 'company_uuid', 'name', 'location'],
        'companies'         => ['uuid', 'public_id', 'name', 'country'],
        'orders'            => ['uuid', 'public_id', 'company_uuid', 'payload_uuid', 'status', 'type', 'dispatched', 'started'],
        'payloads'          => ['uuid', 'public_id', 'company_uuid', 'pickup_uuid', 'dropoff_uuid', 'type', '_key'],
        'entities'          => ['uuid', 'public_id', 'company_uuid', 'payload_uuid', 'destination_uuid', 'name', 'type'],
        'tracking_statuses' => ['uuid', 'public_id', 'company_uuid', 'tracking_number_uuid', 'code', 'status', 'details', 'location', 'city', 'province', 'postal_code', 'country', '_key'],
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

    // Waypoint creation generates QR codes through the barcode services
    foreach (['DNS2D', 'DNS1D'] as $barcode) {
        app()->instance($barcode, new class {
            public function __call($method, $arguments)
            {
                return 'barcode';
            }
        });
    }

    session(['company' => 'company-1', 'api_key' => 'console']);
    $connection->table('companies')->insert(['uuid' => 'company-1', 'name' => 'Acme', 'country' => 'SG']);

    return $connection;
}

function fleetopsVendorResolverModel(): IntegratedVendor
{
    $vendor = new IntegratedVendor();
    $vendor->setRawAttributes([
        'uuid'        => 'iv-1',
        'public_id'   => 'integrated_vendor_res1',
        'provider'    => 'lalamove',
        'sandbox'     => 1,
        'webhook_url' => 'https://hooks.example.test/lalamove',
        'credentials' => json_encode(['api_key' => 'key-1', 'api_secret' => 'secret-1']),
        'options'     => json_encode(['market' => 'SG']),
    ], true);

    return $vendor;
}

test('resolver lookup exposes magic accessors and bridge instances', function () {
    fleetopsVendorResolverBoot();

    $resolver = IntegratedVendors::find('lalamove');
    expect($resolver)->toBeInstanceOf(ResolvedIntegratedVendor::class)
        ->and($resolver->name)->toBe('Lalamove')
        ->and($resolver->getCode())->toBe('lalamove')
        ->and($resolver->logo)->toContain('lalamove')
        ->and($resolver->missing_property)->toBeNull()
        ->and($resolver->unknownCall())->toBeNull();

    $resolver->setIntegratedVendor(fleetopsVendorResolverModel());

    $bridge = $resolver->getBridgeInstance();
    expect($bridge)->toBeInstanceOf(Fleetbase\FleetOps\Integrations\Lalamove\Lalamove::class);

    expect($resolver->getServiceBridgeInstance())->not->toBeNull()
        ->and($resolver->getServiceTypes())->not->toBeEmpty()
        ->and($resolver->geIso2ccBridgeInstance())->not->toBeNull()
        ->and($resolver->getCountries())->not->toBeEmpty();

    expect(IntegratedVendors::getServiceTypes(fleetopsVendorResolverModel()))->not->toBeEmpty();
});

test('callbacks dispatch configured bridge methods with resolved params', function () {
    fleetopsVendorResolverBoot();
    FleetOpsVendorBridgeProbe::$calls = [];

    $resolver = new ResolvedIntegratedVendor([
        'name'         => 'Probe Vendor',
        'code'         => 'lalamove',
        'bridge'       => FleetOpsVendorBridgeProbe::class,
        'bridgeParams' => [
            'apiKey'    => 'credentials.api_key',
            'apiSecret' => 'credentials.api_secret',
            'sandbox'   => 'sandbox',
        ],
        'callbacks' => [
            'onCreated' => [
                'setWebhook' => ['webhook_url'],
            ],
        ],
    ]);
    $resolver->setIntegratedVendor(fleetopsVendorResolverModel());

    $resolver->callback('onCreated');
    expect(collect(FleetOpsVendorBridgeProbe::$calls)->firstWhere(0, 'setWebhook'))->not->toBeNull();

    // Non-string callbacks are ignored
    expect($resolver->callback(null))->toBeNull();
});

test('tracking numbers generate on insert and proofs and templates resolve', function () {
    $connection = fleetopsVendorResolverBoot();
    $connection->table('places')->insert(['uuid' => 'place-1', 'company_uuid' => 'company-1', 'name' => 'Stop']);
    $connection->table('payloads')->insert(['uuid' => 'payload-1', 'company_uuid' => 'company-1']);

    $waypoint = Waypoint::create(['company_uuid' => 'company-1', 'payload_uuid' => 'payload-1', 'place_uuid' => 'place-1', 'order' => 0]);
    expect($connection->table('tracking_numbers')->count())->toBe(1)
        ->and($connection->table('waypoints')->value('tracking_number_uuid'))->not->toBeNull();

    $connection->table('proofs')->insert(['uuid' => 'proof-1', 'public_id' => 'proof_res11111', 'company_uuid' => 'company-1']);
    expect(Waypoint::resolveProof('proof_res11111')?->uuid)->toBe('proof-1')
        ->and(Waypoint::resolveProof(Proof::query()->first()))->toBeInstanceOf(Proof::class)
        ->and(Waypoint::resolveProof(null))->toBeNull();

    $order = new Order();
    $order->setRawAttributes(['uuid' => 'order-1', 'public_id' => 'order_res11111'], true);

    $resolve = new ReflectionMethod(Order::class, 'resolveActivityTemplateString');
    $resolve->setAccessible(true);
    expect($resolve->invoke($order, 'No placeholders here'))->toBe('No placeholders here')
        ->and($resolve->invoke($order, 'Order {order.public_id}'))->toBeString();

    $waypointTemplate = new ReflectionMethod(Waypoint::class, 'resolveActivityTemplateString');
    $waypointTemplate->setAccessible(true);
    expect($waypointTemplate->invoke($waypoint, 'Waypoint {waypoint.type}'))->toBeString();
});
