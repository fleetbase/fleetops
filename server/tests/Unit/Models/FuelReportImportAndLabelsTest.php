<?php

use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Entity;
use Fleetbase\FleetOps\Models\FuelReport;
use Fleetbase\FleetOps\Models\Vehicle;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Relations;
use Illuminate\Database\SQLiteConnection;

/**
 * Covers the FuelReport relation builders and import resolution (reporter,
 * driver, vehicle and location fallbacks), and the Entity label and pdf
 * rendering paths.
 */
if (!class_exists('FleetOpsWaypointViewRecorder', false)) {
    class FleetOpsWaypointViewRecorder
    {
        public static array $views = [];
    }
}

if (!function_exists('Fleetbase\FleetOps\Models\view')) {
    eval('namespace Fleetbase\FleetOps\Models; function view($name = null, $data = []) { \FleetOpsWaypointViewRecorder::$views[] = [$name, array_keys($data)]; return new class { public function render() { return "<html>waypoint-label</html>"; } }; }');
}

if (!function_exists('Fleetbase\Support\session')) {
    eval('namespace Fleetbase\Support; function session($key = null, $default = null) { if ($key === null) { return new class { public function has($k) { return \session($k) !== null; } public function get($k, $d = null) { return \session($k, $d); } }; } return \session($key, $default); }');
}

function fleetopsFuelReportBoot(): SQLiteConnection
{
    $pdo = new PDO('sqlite::memory:');
    foreach (['ST_PointFromText', 'ST_GeomFromText'] as $fn) {
        $pdo->sqliteCreateFunction($fn, fn ($wkt, $srid = 0, $axisOrder = null) => $wkt);
    }
    $pdo->sqliteCreateFunction('CONCAT', fn (...$parts) => implode('', array_map(strval(...), $parts)));
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

    $schema = $connection->getSchemaBuilder();
    $tables = [
        'fuel_reports'     => ['uuid', 'public_id', 'company_uuid', 'reported_by_uuid', 'driver_uuid', 'vehicle_uuid', 'report', 'odometer', 'amount', 'currency', 'volume', 'metric_unit', 'status', 'location', 'meta', '_key'],
        'users'            => ['uuid', 'public_id', 'company_uuid', 'name', 'email', 'type', 'status', '_key'],
        'drivers'          => ['uuid', 'public_id', 'company_uuid', 'user_uuid', 'drivers_license_number', 'status', '_key'],
        'vehicles'         => ['uuid', 'public_id', 'company_uuid', 'plate_number', 'make', 'model', 'year', 'display_name', '_key'],
        'entities'         => ['uuid', 'public_id', 'company_uuid', 'payload_uuid', 'destination_uuid', 'tracking_number_uuid', 'name', 'type', 'meta', '_key'],
        'places'           => ['uuid', 'public_id', 'company_uuid', 'name', 'location', '_key'],
        'tracking_numbers' => ['uuid', 'public_id', 'company_uuid', 'tracking_number', '_key'],
        'companies'        => ['uuid', 'public_id', 'name', 'country'],
        'issues'           => ['uuid', 'public_id', 'company_uuid', 'reported_by_uuid', 'assigned_to_uuuid', 'driver_uuid', 'vehicle_uuid', 'priority', 'report', 'category', 'type', 'location', 'status', 'meta', 'slug', 'internal_id', '_key'],
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
    app()->instance('responsecache', new class {
        public function __call($method, $arguments)
        {
            return null;
        }
    });
    config()->set('activitylog.enabled', false);
    config()->set('activitylog.default_auth_driver', 'web');
    app()->bind(Illuminate\Contracts\Config\Repository::class, fn () => config());

    return $connection;
}

test('fuel report relations and imports resolve reporters drivers and vehicles', function () {
    $connection = fleetopsFuelReportBoot();
    $connection->table('users')->insert(['uuid' => 'user-fuel-1', 'company_uuid' => 'company-1', 'name' => 'Fuel Reporter']);
    $connection->table('drivers')->insert(['uuid' => 'driver-fuel-1', 'public_id' => 'driver_fuelimport', 'company_uuid' => 'company-1', 'user_uuid' => 'user-fuel-1']);
    $connection->table('vehicles')->insert(['uuid' => 'vehicle-fuel-1', 'public_id' => 'vehicle_fuelimport', 'company_uuid' => 'company-1', 'plate_number' => 'SGX-9911', 'make' => 'Volvo', 'model' => 'FH16', 'display_name' => 'Volvo FH16']);

    // Relation builders expose the expected foreign keys
    $fuelReport = new FuelReport();
    expect($fuelReport->driver())->toBeInstanceOf(Illuminate\Database\Eloquent\Relations\BelongsTo::class)
        ->and($fuelReport->vehicle())->toBeInstanceOf(Illuminate\Database\Eloquent\Relations\BelongsTo::class)
        ->and($fuelReport->reportedBy())->toBeInstanceOf(Illuminate\Database\Eloquent\Relations\BelongsTo::class)
        ->and($fuelReport->reporter())->toBeInstanceOf(Illuminate\Database\Eloquent\Relations\BelongsTo::class)
        ->and($fuelReport->reporter()->getForeignKeyName())->toBe('reported_by_uuid');

    // Imports resolve names into relations and fall back to array locations
    $imported = FuelReport::createFromImport([
        'reporter' => 'Fuel Reporter',
        'driver'   => 'driver_fuelimport',
        'vehicle'  => 'Volvo FH16',
        'report'   => 'Topped up before route',
        'amount'   => '85.50',
        'currency' => 'SGD',
        'volume'   => '60',
        'location' => ['latitude' => 1.31, 'longitude' => 103.82],
    ], true);

    expect($imported)->toBeInstanceOf(FuelReport::class)
        ->and($imported->reported_by_uuid)->toBe('user-fuel-1')
        ->and($imported->driver_uuid)->toBe('driver-fuel-1')
        ->and($imported->vehicle_uuid)->toBe('vehicle-fuel-1')
        ->and($connection->table('fuel_reports')->count())->toBe(1);
});

test('entity labels render views and stream pdf output', function () {
    $connection = fleetopsFuelReportBoot();
    $connection->table('entities')->insert(['uuid' => 'entity-label-1', 'company_uuid' => 'company-1', 'destination_uuid' => 'place-el-1', 'tracking_number_uuid' => 'tn-el-1', 'name' => 'Labeled Goods']);
    $connection->table('places')->insert(['uuid' => 'place-el-1', 'company_uuid' => 'company-1', 'name' => 'Entity Dest']);
    $connection->table('tracking_numbers')->insert(['uuid' => 'tn-el-1', 'company_uuid' => 'company-1', 'tracking_number' => 'FLB-ENT-1']);
    $connection->table('companies')->insert(['uuid' => 'company-1', 'name' => 'Entity Co']);

    $entity = Entity::where('uuid', 'entity-label-1')->first();

    FleetOpsWaypointViewRecorder::$views = [];
    expect($entity->label())->toContain('<html>')
        ->and(FleetOpsWaypointViewRecorder::$views[0][0])->toContain('label');

    $wrapper = new class {
        public function loadHTML(string $html, ?string $encoding = null): self
        {
            return $this;
        }

        public function stream()
        {
            return 'entity-pdf-stream';
        }

        public function __call($method, $arguments)
        {
            return $this;
        }
    };
    Illuminate\Container\Container::getInstance()->instance('dompdf.wrapper', $wrapper);
    app()->instance('dompdf.wrapper', $wrapper);

    expect($entity->pdfLabel())->toBe($wrapper)
        ->and($entity->pdfLabelStream())->toBe('entity-pdf-stream');
});

test('issue imports resolve drivers vehicles and locations', function () {
    $connection = fleetopsFuelReportBoot();
    session(['company' => 'company-1', 'user' => 'company-1']);
    $connection->table('users')->insert(['uuid' => 'user-issue-1', 'company_uuid' => 'company-1', 'name' => 'Issue Reporter']);
    $connection->table('drivers')->insert(['uuid' => 'driver-issue-1', 'public_id' => 'driver_issueimport', 'company_uuid' => 'company-1', 'user_uuid' => 'user-issue-1']);
    $connection->table('vehicles')->insert(['uuid' => 'vehicle-issue-1', 'public_id' => 'vehicle_issueimport', 'company_uuid' => 'company-1', 'plate_number' => 'SGZ-1122', 'make' => 'Scania', 'model' => 'R500', 'display_name' => 'Scania R500']);

    $imported = Fleetbase\FleetOps\Models\Issue::createFromImport([
        'priority' => 'high',
        'report'   => 'Engine overheating on route',
        'reporter' => 'Issue Reporter',
        'assignee' => 'Issue Reporter',
        'category' => 'mechanical',
        'type'     => 'vehicle',
        'driver'   => 'driver_issueimport',
        'vehicle'  => 'Scania R500',
        'location' => ['latitude' => 1.33, 'longitude' => 103.85],
    ], true);

    expect($imported)->toBeInstanceOf(Fleetbase\FleetOps\Models\Issue::class)
        ->and($imported->reported_by_uuid)->toBe('user-issue-1')
        ->and($imported->driver_uuid)->toBe('driver-issue-1')
        ->and($imported->vehicle_uuid)->toBe('vehicle-issue-1')
        ->and($connection->table('issues')->count())->toBe(1);
});
