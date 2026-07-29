<?php

use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Http\Request;

/**
 * Covers the queryWithRequest seam every public API controller exposes. Each
 * one hands the request to its model's request-driven query builder, and the
 * result is scoped to the session company.
 */
if (!function_exists('Fleetbase\Support\auth')) {
    eval('namespace Fleetbase\Support; function auth() { return new class { public function user() { return null; } public function id() { return null; } public function check() { return false; } }; }');
}

if (!function_exists('Fleetbase\Support\session')) {
    eval('namespace Fleetbase\Support; function session($key = null, $default = null) { if ($key === null) { return new class { public function has($k) { return \session($k) !== null; } public function get($k, $d = null) { return \session($k, $d); } }; } return \session($key, $default); }');
}

if (!Request::hasMacro('getController')) {
    // Resolve the controller named by the current route action
    Request::macro('getController', function () {
        $action = $this->route()?->getAction('controller');

        return $action ? app(explode('@', $action)[0]) : null;
    });
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

function fleetopsQwrBoot(): SQLiteConnection
{
    $pdo = new PDO('sqlite::memory:');
    foreach (['ST_PointFromText', 'ST_GeomFromText'] as $spatialFunction) {
        $pdo->sqliteCreateFunction($spatialFunction, fn ($wkt, $srid = 0, $axisOrder = null) => $wkt);
    }
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
    Illuminate\Support\Facades\DB::clearResolvedInstance('db');
    app()->instance('responsecache', new class {
        public function __call($method, $arguments)
        {
            return null;
        }
    });
    config()->set('activitylog.enabled', false);
    config()->set('activitylog.default_auth_driver', 'web');
    app()->bind(Illuminate\Contracts\Config\Repository::class, fn () => config());

    $columns = [
        'uuid', 'public_id', 'internal_id', 'company_uuid', 'name', 'type', 'status', 'meta', '_key',
        'owner_uuid', 'owner_type', 'payload_uuid', 'order_uuid', 'place_uuid', 'tracking_number_uuid',
        'service_area_uuid', 'zone_uuid', 'vendor_uuid', 'driver_uuid', 'vehicle_uuid', 'device_uuid',
        'code', 'details', 'report', 'key', 'namespace', 'border', 'location', 'expired_at',
        'permission_uuid', 'subject_type', 'subject_uuid', 'rules',
    ];

    $schema = $connection->getSchemaBuilder();
    foreach ([
        'contacts', 'devices', 'entities', 'equipments', 'fleets', 'fuel_reports', 'fuel_provider_transactions',
        'issues', 'order_configs', 'parts', 'payloads', 'places', 'service_areas', 'service_rates',
        'tracking_numbers', 'tracking_statuses', 'vendors', 'work_orders', 'zones', 'directives', 'companies', 'waypoints',
    ] as $table) {
        $schema->create($table, function ($blueprint) use ($columns) {
            $blueprint->increments('id');
            foreach ($columns as $column) {
                $blueprint->string($column)->nullable();
            }
            $blueprint->timestamps();
            $blueprint->timestamp('deleted_at')->nullable();
        });
    }

    session(['company' => 'company-qwr-1']);
    $connection->table('companies')->insert(['uuid' => 'company-qwr-1', 'name' => 'Query Co']);

    return $connection;
}

function fleetopsQwrRequest(string $controllerClass, string $uri): Request
{
    $request = Request::create($uri, 'GET');
    $store   = app('session.store');
    $store->put('company', 'company-qwr-1');
    $request->setLaravelSession($store);
    $request->setRouteResolver(fn () => new class($controllerClass) {
        public function __construct(private string $controllerClass)
        {
        }

        public function getAction($key = null)
        {
            $action = $this->controllerClass . '@query';

            return $key === null ? ['controller' => $action] : $action;
        }

        public function getActionMethod()
        {
            return 'query';
        }

        public function getName()
        {
            return 'api.v1.query';
        }

        public function uri()
        {
            return 'v1/resource';
        }

        public function parameters()
        {
            return [];
        }

        public function parameter($name = null, $default = null)
        {
            return $default;
        }
    });

    app()->instance('request', $request);

    return $request;
}

test('api controllers query their model through the request pipeline', function (string $controllerClass, string $method, string $table, bool $takesCallback) {
    $connection = fleetopsQwrBoot();
    $connection->table($table)->insert([
        ['uuid' => 'qwr-row-1', 'public_id' => 'qwr_row_one', 'company_uuid' => 'company-qwr-1', 'name' => 'In Company'],
        ['uuid' => 'qwr-row-2', 'public_id' => 'qwr_row_two', 'company_uuid' => 'company-other', 'name' => 'Other Company'],
    ]);

    // Controllers with constructor dependencies resolve through the container
    $controller = app($controllerClass);
    $reflection = new ReflectionMethod($controllerClass, $method);
    $reflection->setAccessible(true);

    $request   = fleetopsQwrRequest($controllerClass, '/v1/resource');
    $arguments = $takesCallback ? [$request, function (&$query) {}] : [$request];
    $results   = $reflection->invoke($controller, ...$arguments);

    // Only the session company's records come back
    expect(collect($results)->pluck('uuid')->all())->toBe(['qwr-row-1']);
})->with([
    'contacts'          => [Fleetbase\FleetOps\Http\Controllers\Api\v1\ContactController::class, 'queryContacts', 'contacts', false],
    'entities'          => [Fleetbase\FleetOps\Http\Controllers\Api\v1\EntityController::class, 'queryEntities', 'entities', false],
    'fleets'            => [Fleetbase\FleetOps\Http\Controllers\Api\v1\FleetController::class, 'queryFleets', 'fleets', false],
    'fuel reports'      => [Fleetbase\FleetOps\Http\Controllers\Api\v1\FuelReportController::class, 'queryFuelReports', 'fuel_reports', false],
    'issues'            => [Fleetbase\FleetOps\Http\Controllers\Api\v1\IssueController::class, 'queryIssues', 'issues', false],
    'order configs'     => [Fleetbase\FleetOps\Http\Controllers\Api\v1\OrderConfigController::class, 'queryOrderConfigs', 'order_configs', false],
    'payloads'          => [Fleetbase\FleetOps\Http\Controllers\Api\v1\PayloadController::class, 'queryPayloads', 'payloads', false],
    'service areas'     => [Fleetbase\FleetOps\Http\Controllers\Api\v1\ServiceAreaController::class, 'queryServiceAreas', 'service_areas', false],
    'service rates'     => [Fleetbase\FleetOps\Http\Controllers\Api\v1\ServiceRateController::class, 'queryServiceRates', 'service_rates', false],
    'tracking numbers'  => [Fleetbase\FleetOps\Http\Controllers\Api\v1\TrackingNumberController::class, 'queryTrackingNumbers', 'tracking_numbers', false],
    'tracking statuses' => [Fleetbase\FleetOps\Http\Controllers\Api\v1\TrackingStatusController::class, 'queryTrackingStatuses', 'tracking_statuses', false],
    'vendors'           => [Fleetbase\FleetOps\Http\Controllers\Api\v1\VendorController::class, 'queryVendors', 'vendors', false],
    'zones'             => [Fleetbase\FleetOps\Http\Controllers\Api\v1\ZoneController::class, 'queryZones', 'zones', false],
    'devices'           => [Fleetbase\FleetOps\Http\Controllers\Api\v1\DeviceController::class, 'queryDevicesWithRequest', 'devices', true],
    'equipment'         => [Fleetbase\FleetOps\Http\Controllers\Api\v1\EquipmentController::class, 'queryEquipment', 'equipments', true],
    'fuel transactions' => [Fleetbase\FleetOps\Http\Controllers\Api\v1\FuelTransactionController::class, 'queryTransactionsWithRequest', 'fuel_provider_transactions', true],
    'parts'             => [Fleetbase\FleetOps\Http\Controllers\Api\v1\PartController::class, 'queryParts', 'parts', true],
    'places'            => [Fleetbase\FleetOps\Http\Controllers\Api\v1\PlaceController::class, 'queryPlaces', 'places', true],
    'work orders'       => [Fleetbase\FleetOps\Http\Controllers\Api\v1\WorkOrderController::class, 'queryWorkOrdersWithRequest', 'work_orders', true],
]);
