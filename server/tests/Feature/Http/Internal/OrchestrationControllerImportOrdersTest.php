<?php

use Fleetbase\FleetOps\Http\Controllers\Internal\v1\OrchestrationController;
use Fleetbase\FleetOps\Orchestration\OrchestrationEngineRegistry;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Covers the internal OrchestrationController importOrders endpoint against
 * SQLite: the empty-rows rejection, single pickup/dropoff row imports with
 * customer/facilitator/vehicle/driver resolution and entity attachment,
 * multi-waypoint groups collapsing into one order with waypoints, and the
 * per-group failure capture with rollback.
 */
if (!function_exists('Fleetbase\Support\session')) {
    eval('namespace Fleetbase\Support; function session($key = null, $default = null) { if ($key === null) { return new class { public function has($k) { return \session($k) !== null; } public function get($k, $d = null) { return \session($k, $d); } }; } return \session($key, $default); }');
}

if (!function_exists('Fleetbase\Support\auth')) {
    eval('namespace Fleetbase\Support; function auth() { return new class { public function user() { return null; } public function id() { return null; } }; }');
}

if (!function_exists('Fleetbase\FleetOps\Models\event')) {
    eval('namespace Fleetbase\FleetOps\Models; function event($event = null) { return $event; }');
}

if (!function_exists('Fleetbase\FleetOps\Models\dispatch')) {
    eval('namespace Fleetbase\FleetOps\Models; function dispatch($job = null) { return new \Fleetbase\TestSupport\PendingDispatch(); }');
}

function fleetopsOrchImportBoot(): SQLiteConnection
{
    if (!Str::hasMacro('humanize')) {
        Str::macro('humanize', fn ($value, $uppercase = true) => str_replace('_', ' ', Str::snake((string) $value)));
    }

    $pdo = new PDO('sqlite::memory:');
    $pdo->sqliteCreateFunction('ST_PointFromText', fn ($wkt, $srid = 0, $axisOrder = null) => $wkt);
    $pdo->sqliteCreateFunction('ST_GeomFromText', fn ($wkt, $srid = 0, $axisOrder = null) => $wkt);
    $pdo->sqliteCreateFunction('ST_Equals', fn ($a, $b) => $a === $b ? 1 : 0);
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

    app()->instance('geocoder', new class {
        public $results;

        public function geocode($address)
        {
            return $this;
        }

        public function reverse($latitude, $longitude)
        {
            return $this;
        }

        public function get()
        {
            return collect();
        }
    });
    Geocoder\Laravel\Facades\Geocoder::clearResolvedInstances();

    $barcodeFake = new class {
        public function __call($method, $arguments)
        {
            return 'barcode';
        }
    };
    app()->instance('DNS2D', $barcodeFake);
    app()->instance('DNS1D', $barcodeFake);

    $schema = $connection->getSchemaBuilder();
    app()->instance('db.schema', $schema);
    $tables = [
        'orders'            => ['uuid', 'public_id', 'internal_id', 'company_uuid', 'payload_uuid', 'order_config_uuid', 'tracking_number_uuid', 'driver_assigned_uuid', 'vehicle_assigned_uuid', 'customer_uuid', 'customer_type', 'facilitator_uuid', 'facilitator_type', 'status', 'type', 'adhoc', 'dispatched', 'started', 'scheduled_at', 'notes', 'meta', 'time_window_start', 'time_window_end', 'required_skills', 'orchestrator_priority', 'distance', 'time', 'pod_required', 'pod_method', 'created_by_uuid', 'updated_by_uuid'],
        'payloads'          => ['uuid', 'public_id', 'company_uuid', 'pickup_uuid', 'dropoff_uuid', 'return_uuid', 'current_waypoint_uuid', 'meta', 'type', 'cod_amount', 'cod_currency', 'cod_payment_method', 'capacity_weight_kg', 'capacity_volume_m3', 'capacity_parcels', '_key'],
        'places'            => ['uuid', 'public_id', 'company_uuid', 'name', 'street1', 'street2', 'city', 'province', 'postal_code', 'country', 'phone', 'location', 'meta', '_key', 'owner_uuid', 'type', '_import_id'],
        'waypoints'         => ['uuid', 'public_id', 'company_uuid', 'payload_uuid', 'place_uuid', 'tracking_number_uuid', 'order', 'type', '_import_id', '_key'],
        'entities'          => ['uuid', 'public_id', 'company_uuid', 'payload_uuid', 'destination_uuid', 'tracking_number_uuid', 'name', 'type', 'weight', 'weight_unit', 'meta', '_import_id', '_key', 'internal_id', 'sku', 'height', 'width', 'length', 'dimensions_unit', 'declared_value', 'currency', 'description'],
        'contacts'          => ['uuid', 'public_id', 'internal_id', 'company_uuid', 'user_uuid', 'name', 'email', 'phone', 'type', 'meta', 'slug', '_key'],
        'vendors'           => ['uuid', 'public_id', 'internal_id', 'company_uuid', 'name', 'email', 'phone', 'status', 'type', 'meta', 'slug', '_key'],
        'vehicles'          => ['uuid', 'public_id', 'company_uuid', 'name', 'plate_number', 'make', 'model', 'year'],
        'drivers'           => ['uuid', 'public_id', 'company_uuid', 'user_uuid', 'drivers_license_number', 'status'],
        'users'             => ['uuid', 'public_id', 'company_uuid', 'name', 'email', 'phone', 'status', 'type'],
        'order_configs'     => ['uuid', 'public_id', 'company_uuid', 'name', 'key', 'namespace', 'description', 'flow', 'entities', 'meta', 'version', 'core_service', 'status', 'type', '_key'],
        'tracking_numbers'  => ['uuid', 'public_id', 'company_uuid', 'tracking_number', 'region', 'location', 'status_uuid', 'owner_uuid', 'owner_type', 'qr_code', 'barcode', '_key'],
        'tracking_statuses' => ['uuid', 'public_id', 'company_uuid', 'tracking_number_uuid', 'proof_uuid', 'status', 'details', 'location', 'code', 'complete', '_key'],
        'companies'         => ['uuid', 'public_id', 'name', 'country'],
    ];
    foreach ($tables as $table => $columns) {
        $schema->create($table, function ($blueprint) use ($columns) {
            $blueprint->increments('id');
            foreach ($columns as $column) {
                if ($column === 'core_service') {
                    $blueprint->integer($column)->nullable();
                    continue;
                }
                $blueprint->string($column)->nullable();
            }
            $blueprint->timestamps();
            $blueprint->timestamp('deleted_at')->nullable();
        });
    }

    session(['company' => 'company-1']);
    $connection->table('companies')->insert(['uuid' => 'company-1', 'name' => 'Acme', 'country' => 'SG']);
    $connection->table('order_configs')->insert([
        'uuid'         => 'config-1',
        'public_id'    => 'order_config_transport',
        'company_uuid' => 'company-1',
        'name'         => 'Transport',
        'key'          => 'transport',
        'namespace'    => 'system:order-config:transport',
        'core_service' => 1,
        'status'       => 'active',
        'version'      => '0.0.1',
        'flow'         => json_encode([]),
    ]);

    return $connection;
}

function fleetopsOrchImportController(): OrchestrationController
{
    return new OrchestrationController(new OrchestrationEngineRegistry());
}

test('import orders rejects empty row sets', function () {
    fleetopsOrchImportBoot();

    $response = fleetopsOrchImportController()->importOrders(Request::create('/x', 'POST', []));

    expect($response->getStatusCode())->toBe(422)
        ->and($response->getData(true)['error'])->toContain('No rows');
});

test('import orders creates a pickup dropoff order with resolutions', function () {
    $connection = fleetopsOrchImportBoot();
    $connection->table('vehicles')->insert(['uuid' => 'vehicle-1', 'company_uuid' => 'company-1', 'plate_number' => 'ATL-1']);
    $connection->table('users')->insert(['uuid' => 'user-1', 'company_uuid' => 'company-1', 'name' => 'Casey Driver', 'email' => 'casey@example.test']);
    $connection->table('drivers')->insert(['uuid' => 'driver-1', 'public_id' => 'driver_import', 'company_uuid' => 'company-1', 'user_uuid' => 'user-1']);

    $response = fleetopsOrchImportController()->importOrders(Request::create('/x', 'POST', ['rows' => [[
        'order_type'          => 'pickup_dropoff',
        'type'                => 'transport',
        'customer_name'       => 'Import Customer',
        'customer_email'      => 'import.customer@example.test',
        'facilitator_name'    => 'Import Vendor',
        'facilitator_email'   => 'import.vendor@example.test',
        'vehicle_plate'       => 'ATL-1',
        'driver_email'        => 'casey@example.test',
        'pickup_name'         => 'Warehouse',
        'pickup_street1'      => '1 Import Way',
        'pickup_city'         => 'Singapore',
        'pickup_country'      => 'SG',
        'pickup_lat'          => '1.30',
        'pickup_lng'          => '103.80',
        'dropoff_name'        => 'Customer Site',
        'dropoff_street1'     => '9 Delivery Road',
        'dropoff_city'        => 'Singapore',
        'dropoff_country'     => 'SG',
        'dropoff_lat'         => '1.35',
        'dropoff_lng'         => '103.85',
        'entity_name'         => 'Parcel A',
        'priority'            => '10',
        'required_skills'     => 'refrigerated, fragile',
        'service_time_min'    => '15',
    ]]]));

    $result = $response->getData(true);

    expect($result['failed'])->toBe([])
        ->and(count($result['created']))->toBe(1)
        ->and($connection->table('orders')->count())->toBe(1)
        ->and($connection->table('orders')->value('vehicle_assigned_uuid'))->toBe('vehicle-1')
        ->and($connection->table('orders')->value('driver_assigned_uuid'))->toBe('driver-1')
        ->and($connection->table('contacts')->where('email', 'import.customer@example.test')->count())->toBe(1)
        ->and($connection->table('vendors')->where('email', 'import.vendor@example.test')->count())->toBe(1)
        ->and($connection->table('places')->count())->toBe(2);
});

test('import orders collapses multi waypoint groups into one order', function () {
    $connection = fleetopsOrchImportBoot();

    $rows = [
        [
            'order_type'      => 'multi_waypoint',
            'order_ref'       => 'GROUP-1',
            'dropoff_name'    => 'Stop One',
            'dropoff_street1' => '1 First Stop',
            'dropoff_city'    => 'Singapore',
            'dropoff_country' => 'SG',
            'dropoff_lat'     => '1.30',
            'dropoff_lng'     => '103.80',
        ],
        [
            'order_type'      => 'multi_waypoint',
            'order_ref'       => 'GROUP-1',
            'dropoff_name'    => 'Stop Two',
            'dropoff_street1' => '2 Second Stop',
            'dropoff_city'    => 'Singapore',
            'dropoff_country' => 'SG',
            'dropoff_lat'     => '1.31',
            'dropoff_lng'     => '103.81',
            'entity_name'     => 'Grouped Parcel',
        ],
    ];

    $response = fleetopsOrchImportController()->importOrders(Request::create('/x', 'POST', ['rows' => $rows]));
    $result   = $response->getData(true);

    expect($result['failed'])->toBe([])
        ->and(count($result['created']))->toBe(1)
        ->and($connection->table('orders')->count())->toBe(1)
        ->and($connection->table('waypoints')->count())->toBe(2);
});

test('import orders captures per group failures with rollback', function () {
    $connection = fleetopsOrchImportBoot();

    $response = fleetopsOrchImportController()->importOrders(Request::create('/x', 'POST', ['rows' => [[
        'order_type'   => 'pickup_dropoff',
        'scheduled_at' => 'not-a-real-date',
        '_rowIndex'    => 3,
    ]]]));

    $result = $response->getData(true);

    expect($result['created'])->toBe([])
        ->and(count($result['failed']))->toBe(1)
        ->and($result['failed'][0]['row'])->toBe(3);
});
