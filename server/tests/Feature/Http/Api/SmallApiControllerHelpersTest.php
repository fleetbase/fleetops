<?php

use Fleetbase\FleetOps\Http\Controllers\Api\v1\EquipmentController;
use Fleetbase\FleetOps\Http\Controllers\Api\v1\FleetController;
use Fleetbase\FleetOps\Http\Controllers\Api\v1\FuelReportController;
use Fleetbase\FleetOps\Http\Controllers\Api\v1\IssueController;
use Fleetbase\FleetOps\Http\Controllers\Api\v1\PartController;
use Fleetbase\FleetOps\Http\Controllers\Api\v1\ServiceRateController;
use Fleetbase\FleetOps\Http\Controllers\Api\v1\VendorController;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;

/**
 * Covers the shared protected helper batteries of the small API controllers
 * (Vendor, Issue, Fleet, FuelReport): record creation and lookup, uuid
 * getters, resource wrappers and json responses.
 */
if (!function_exists('Fleetbase\Support\session')) {
    eval('namespace Fleetbase\Support; function session($key = null, $default = null) { if ($key === null) { return new class { public function has($k) { return \session($k) !== null; } public function get($k, $d = null) { return \session($k, $d); } }; } return \session($key, $default); }');
}

if (!function_exists('Fleetbase\Observers\event')) {
    eval('namespace Fleetbase\Observers; function event($event = null, $payload = []) { return []; }');
}

function fleetopsSmallApiHelpersBoot(): SQLiteConnection
{
    if (!Illuminate\Support\Str::hasMacro('humanize')) {
        Illuminate\Support\Str::macro('humanize', fn ($value, $uppercase = true) => str_replace('_', ' ', Illuminate\Support\Str::snake((string) $value)));
    }
    $pdo = new PDO('sqlite::memory:');
    foreach (['ST_PointFromText', 'ST_GeomFromText'] as $fn) {
        $pdo->sqliteCreateFunction($fn, fn ($wkt, $srid = 0, $axisOrder = null) => $wkt);
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
    app()->instance('db.schema', $connection->getSchemaBuilder());
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

    $schema = $connection->getSchemaBuilder();
    $tables = [
        'vendors'                  => ['uuid', 'public_id', 'company_uuid', 'connect_company_uuid', 'place_uuid', 'name', 'email', 'phone', 'address', 'website', 'country', 'type', 'status', 'meta', 'internal_id', 'slug', '_key'],
        'issues'                   => ['uuid', 'public_id', 'company_uuid', 'reported_by_uuid', 'assignee_uuid', 'driver_uuid', 'vehicle_uuid', 'type', 'category', 'priority', 'status', 'report', 'tags', 'meta', 'slug', 'internal_id', '_key'],
        'fleets'                   => ['uuid', 'public_id', 'company_uuid', 'parent_fleet_uuid', 'service_area_uuid', 'zone_uuid', 'name', 'task', 'status', 'meta', 'slug', 'internal_id', '_key'],
        'fuel_reports'             => ['uuid', 'public_id', 'company_uuid', 'reported_by_uuid', 'driver_uuid', 'vehicle_uuid', 'report', 'odometer', 'amount', 'currency', 'volume', 'metric_unit', 'status', 'location', 'meta', 'slug', 'internal_id', '_key'],
        'drivers'                  => ['uuid', 'public_id', 'company_uuid', 'user_uuid', 'status', '_key'],
        'users'                    => ['uuid', 'public_id', 'company_uuid', 'name', '_key'],
        'places'                   => ['uuid', 'public_id', 'company_uuid', 'name', 'location', '_key'],
        'service_areas'            => ['uuid', 'public_id', 'company_uuid', 'name', 'border', '_key'],
        'companies'                => ['uuid', 'public_id', 'name', 'country', 'options'],
        'service_rates'            => ['uuid', 'public_id', 'company_uuid', 'service_area_uuid', 'zone_uuid', 'service_name', 'service_type', 'base_fee', 'per_km_flat_rate_fee', 'rate_calculation_method', 'currency', 'estimated_days', 'duration_terms', 'meta', 'slug', 'internal_id', '_key'],
        'service_rate_fees'        => ['uuid', 'public_id', 'service_rate_uuid', 'min', 'max', 'fee', 'distance_unit', '_key'],
        'service_rate_parcel_fees' => ['uuid', 'public_id', 'service_rate_uuid', 'size', 'length', 'width', 'height', 'fee', '_key'],
        'parts'                    => ['uuid', 'public_id', 'company_uuid', 'name', 'description', 'part_number', 'sku', 'category', 'quantity', 'price', 'currency', 'status', 'specs', 'meta', 'slug', 'internal_id', '_key'],
        'equipments'               => ['uuid', 'public_id', 'company_uuid', 'name', 'description', 'type', 'status', 'specs', 'meta', 'slug', 'internal_id', '_key'],
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
    $connection->table('companies')->insert(['uuid' => 'company-1', 'name' => 'Small Co']);

    return $connection;
}

function fleetopsSmallApiHelper(object $controller): Closure
{
    return function (string $method, ...$arguments) use ($controller) {
        $reflection = new ReflectionMethod($controller, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($controller, ...$arguments);
    };
}

test('vendor issue fleet and fuel report helper batteries execute against sqlite', function () {
    $connection = fleetopsSmallApiHelpersBoot();
    $connection->table('places')->insert(['uuid' => 'place-sm-1', 'public_id' => 'place_smallone1', 'company_uuid' => 'company-1', 'name' => 'Small Place']);
    $connection->table('users')->insert(['uuid' => 'user-sm-1', 'company_uuid' => 'company-1', 'name' => 'Small Driver']);
    $connection->table('drivers')->insert(['uuid' => 'driver-sm-1', 'public_id' => 'driver_smallone1', 'company_uuid' => 'company-1', 'user_uuid' => 'user-sm-1']);
    $connection->table('service_areas')->insert(['uuid' => 'sa-sm-1', 'public_id' => 'sa_smallone1', 'company_uuid' => 'company-1', 'name' => 'Small Area']);

    // Vendor battery
    $vendorHelper = fleetopsSmallApiHelper(new VendorController());
    expect($vendorHelper('getPlaceUuid', 'places', ['public_id' => 'place_smallone1']))->toBe('place-sm-1');
    $vendor = $vendorHelper('updateOrCreateVendor', ['name' => 'Battery Vendor'], ['company_uuid' => 'company-1', 'name' => 'Battery Vendor', 'status' => 'active']);
    expect($connection->table('vendors')->count())->toBe(1);
    $foundVendor = $vendorHelper('findVendorRecord', (string) $connection->table('vendors')->value('public_id'));
    expect($foundVendor->uuid)->toBe($vendor->uuid)
        ->and($vendorHelper('vendorResource', $foundVendor))->toBeInstanceOf(Fleetbase\FleetOps\Http\Resources\v1\Vendor::class)
        ->and($vendorHelper('vendorResourceCollection', collect([$foundVendor])))->toBeInstanceOf(Illuminate\Http\Resources\Json\ResourceCollection::class)
        ->and($vendorHelper('deletedVendorResource', $foundVendor))->not->toBeNull()
        ->and($vendorHelper('jsonResponse', ['ok' => true], 200))->toBeInstanceOf(Illuminate\Http\JsonResponse::class);

    // Issue battery
    $issueHelper = fleetopsSmallApiHelper(new IssueController());
    expect($issueHelper('findDriverRecord', 'driver_smallone1')->uuid)->toBe('driver-sm-1');
    $issue = $issueHelper('createIssue', ['company_uuid' => 'company-1', 'driver_uuid' => 'driver-sm-1', 'report' => 'Brake noise', 'priority' => 'high', 'status' => 'pending']);
    expect($connection->table('issues')->count())->toBe(1);
    $foundIssue = $issueHelper('findIssueRecord', (string) $connection->table('issues')->value('public_id'));
    expect($foundIssue->uuid)->toBe($issue->uuid)
        ->and($issueHelper('issueResource', $foundIssue))->toBeInstanceOf(Fleetbase\FleetOps\Http\Resources\v1\Issue::class)
        ->and($issueHelper('issueResourceCollection', collect([$foundIssue])))->toBeInstanceOf(Illuminate\Http\Resources\Json\ResourceCollection::class)
        ->and($issueHelper('deletedIssueResource', $foundIssue))->not->toBeNull()
        ->and($issueHelper('jsonResponse', ['ok' => true], 200))->toBeInstanceOf(Illuminate\Http\JsonResponse::class);

    // Fleet battery
    $fleetHelper = fleetopsSmallApiHelper(new FleetController());
    expect($fleetHelper('getServiceAreaUuid', 'service_areas', ['public_id' => 'sa_smallone1']))->toBe('sa-sm-1');
    $fleet = $fleetHelper('createFleet', ['company_uuid' => 'company-1', 'name' => 'Battery Fleet']);
    expect($connection->table('fleets')->count())->toBe(1);
    $foundFleet = $fleetHelper('findFleet', (string) $connection->table('fleets')->value('public_id'));
    expect($foundFleet->uuid)->toBe($fleet->uuid)
        ->and($fleetHelper('fleetResource', $foundFleet))->toBeInstanceOf(Fleetbase\FleetOps\Http\Resources\v1\Fleet::class)
        ->and($fleetHelper('fleetResourceCollection', collect([$foundFleet])))->toBeInstanceOf(Illuminate\Http\Resources\Json\ResourceCollection::class)
        ->and($fleetHelper('deletedFleetResource', $foundFleet))->not->toBeNull()
        ->and($fleetHelper('jsonResponse', ['ok' => true], 200))->toBeInstanceOf(Illuminate\Http\JsonResponse::class);

    // Fuel report battery
    $fuelHelper = fleetopsSmallApiHelper(new FuelReportController());
    expect($fuelHelper('findDriverRecord', 'driver_smallone1')->uuid)->toBe('driver-sm-1');
    $fuelReport = $fuelHelper('createFuelReport', ['company_uuid' => 'company-1', 'driver_uuid' => 'driver-sm-1', 'report' => 'Refuel', 'amount' => 4200, 'currency' => 'SGD', 'volume' => '55', 'metric_unit' => 'l', 'status' => 'pending']);
    expect($connection->table('fuel_reports')->count())->toBe(1);
    $foundFuel = $fuelHelper('findFuelReportRecord', (string) $connection->table('fuel_reports')->value('public_id'));
    expect($foundFuel->uuid)->toBe($fuelReport->uuid)
        ->and($fuelHelper('fuelReportResource', $foundFuel))->toBeInstanceOf(Fleetbase\FleetOps\Http\Resources\v1\FuelReport::class)
        ->and($fuelHelper('fuelReportResourceCollection', collect([$foundFuel])))->toBeInstanceOf(Illuminate\Http\Resources\Json\ResourceCollection::class)
        ->and($fuelHelper('deletedFuelReportResource', $foundFuel))->not->toBeNull()
        ->and($fuelHelper('jsonResponse', ['ok' => true], 200))->toBeInstanceOf(Illuminate\Http\JsonResponse::class);
});

test('service rate helper battery executes against sqlite', function () {
    $connection = fleetopsSmallApiHelpersBoot();
    $connection->table('service_areas')->insert(['uuid' => 'sa-sr-1', 'public_id' => 'sa_srateone1', 'company_uuid' => 'company-1', 'name' => 'Rate Area']);

    $helper = fleetopsSmallApiHelper(new ServiceRateController());

    expect($helper('resolveUuid', 'service_areas', ['public_id' => 'sa_srateone1']))->toBe('sa-sr-1');

    $serviceRate = $helper('createServiceRate', ['company_uuid' => 'company-1', 'service_name' => 'Battery Rate', 'service_type' => 'transport', 'base_fee' => 500, 'currency' => 'SGD', 'rate_calculation_method' => 'fixed_meter']);
    expect($connection->table('service_rates')->count())->toBe(1);

    $fee = $helper('createServiceRateFee', ['service_rate_uuid' => $serviceRate->uuid, 'min' => '0', 'max' => '10', 'fee' => '150']);
    expect($connection->table('service_rate_fees')->count())->toBe(1);

    $found = $helper('findServiceRate', (string) $connection->table('service_rates')->value('public_id'));
    expect($found->uuid)->toBe($serviceRate->uuid)
        ->and($helper('serviceRateResource', $found))->toBeInstanceOf(Fleetbase\FleetOps\Http\Resources\v1\ServiceRate::class)
        ->and($helper('serviceRateResourceCollection', collect([$found])))->toBeInstanceOf(Illuminate\Http\Resources\Json\ResourceCollection::class)
        ->and($helper('deletedServiceRateResource', $found))->not->toBeNull();
});

test('issue resource resolves linked orders from relations and metadata', function () {
    $connection = fleetopsSmallApiHelpersBoot();
    $schema     = $connection->getSchemaBuilder();
    foreach (['orders' => ['uuid', 'public_id', 'company_uuid', 'payload_uuid', 'tracking_number_uuid', 'customer_uuid', 'customer_type', 'status', 'meta', '_key'], 'payloads' => ['uuid', 'public_id', 'company_uuid', 'pickup_uuid', 'dropoff_uuid', 'meta', '_key'], 'tracking_numbers' => ['uuid', 'public_id', 'company_uuid', 'tracking_number', '_key'], 'contacts' => ['uuid', 'public_id', 'company_uuid', 'name', 'type', '_key']] as $table => $columns) {
        $schema->create($table, function ($blueprint) use ($columns) {
            $blueprint->increments('id');
            foreach ($columns as $column) {
                $blueprint->string($column)->nullable();
            }
            $blueprint->timestamps();
            $blueprint->timestamp('deleted_at')->nullable();
        });
    }
    $connection->table('orders')->insert(['uuid' => 'c7c7c7c7-7777-4777-8777-777777777701', 'public_id' => 'order_issuelink1', 'company_uuid' => 'company-1', 'status' => 'created']);

    $resolve = function (Fleetbase\FleetOps\Models\Issue $issue) {
        $resource   = new Fleetbase\FleetOps\Http\Resources\v1\Issue($issue);
        $reflection = new ReflectionMethod(Fleetbase\FleetOps\Http\Resources\v1\Issue::class, 'resolveLinkedOrder');
        $reflection->setAccessible(true);

        return $reflection->invoke($resource);
    };

    // Loaded relations resolve directly into order resources
    $order = Fleetbase\FleetOps\Models\Order::where('uuid', 'c7c7c7c7-7777-4777-8777-777777777701')->first();
    $issue = new Fleetbase\FleetOps\Models\Issue();
    $issue->setRawAttributes(['uuid' => 'issue-link-1', 'company_uuid' => 'company-1'], true);
    $issue->setRelation('order', $order);
    expect($resolve($issue))->toBeInstanceOf(Fleetbase\FleetOps\Http\Resources\v1\Order::class);

    // Metadata order uuids look the order up in the database
    $metaIssue = new Fleetbase\FleetOps\Models\Issue();
    $metaIssue->setRawAttributes(['uuid' => 'issue-link-2', 'company_uuid' => 'company-1', 'meta' => json_encode(['order_uuid' => 'c7c7c7c7-7777-4777-8777-777777777701'])], true);
    expect($resolve($metaIssue))->toBeInstanceOf(Fleetbase\FleetOps\Http\Resources\v1\Order::class);

    // Unresolvable metadata orders return null
    $missingIssue = new Fleetbase\FleetOps\Models\Issue();
    $missingIssue->setRawAttributes(['uuid' => 'issue-link-3', 'company_uuid' => 'company-1', 'meta' => json_encode(['order_uuid' => 'c7c7c7c7-7777-4777-8777-777777777799'])], true);
    expect($resolve($missingIssue))->toBeNull();

    $bareIssue = new Fleetbase\FleetOps\Models\Issue();
    $bareIssue->setRawAttributes(['uuid' => 'issue-link-4', 'company_uuid' => 'company-1'], true);
    expect($resolve($bareIssue))->toBeNull();
});

test('part and equipment helper batteries execute against sqlite', function () {
    $connection = fleetopsSmallApiHelpersBoot();

    // Part battery
    $partHelper = fleetopsSmallApiHelper(new PartController());
    $part       = $partHelper('createPart', ['company_uuid' => 'company-1', 'name' => 'Brake Pad', 'status' => 'available']);
    expect($connection->table('parts')->count())->toBe(1)
        ->and($partHelper('partResource', $part))->toBeInstanceOf(Fleetbase\FleetOps\Http\Resources\v1\Part::class)
        ->and($partHelper('partResourceCollection', collect([$part])))->toBeInstanceOf(Illuminate\Http\Resources\Json\ResourceCollection::class)
        ->and($partHelper('deletedPartResource', $part))->not->toBeNull()
        ->and($partHelper('jsonResponse', ['ok' => true], 200))->toBeInstanceOf(Illuminate\Http\JsonResponse::class);

    // Equipment battery
    $equipmentHelper = fleetopsSmallApiHelper(new EquipmentController());
    $equipment       = $equipmentHelper('createEquipment', ['company_uuid' => 'company-1', 'name' => 'Pallet Jack', 'status' => 'available']);
    expect($connection->table('equipments')->count())->toBe(1)
        ->and($equipmentHelper('equipmentResource', $equipment))->toBeInstanceOf(Fleetbase\FleetOps\Http\Resources\v1\Equipment::class)
        ->and($equipmentHelper('equipmentResourceCollection', collect([$equipment])))->toBeInstanceOf(Illuminate\Http\Resources\Json\ResourceCollection::class)
        ->and($equipmentHelper('deletedEquipmentResource', $equipment))->not->toBeNull()
        ->and($equipmentHelper('jsonResponse', ['ok' => true], 200))->toBeInstanceOf(Illuminate\Http\JsonResponse::class);
});
