<?php

use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;

/**
 * Covers the collection() query builders shared by every spreadsheet export:
 * each one scopes to the session company, and narrows to the selected uuids
 * when a selection list is supplied. Both branches are asserted for every
 * export so a broken scope or relation eager-load surfaces here.
 */
if (!function_exists('Fleetbase\Support\session')) {
    eval('namespace Fleetbase\Support; function session($key = null, $default = null) { if ($key === null) { return new class { public function has($k) { return \session($k) !== null; } public function get($k, $d = null) { return \session($k, $d); } }; } return \session($key, $default); }');
}

/**
 * Every export's base table plus the tables its eager loads reach. A shared
 * wide column set keeps the fixture declarative — unused columns are simply
 * never read.
 */
function fleetopsExportCollectionBoot(): SQLiteConnection
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
        'uuid', 'public_id', 'internal_id', 'company_uuid', 'name', 'status', 'type', 'meta', '_key',
        'place_uuid', 'vehicle_uuid', 'driver_uuid', 'order_uuid', 'owner_uuid', 'owner_type',
        'service_area_uuid', 'parent_fleet_uuid', 'vendor_uuid', 'zone_uuid', 'payload_uuid',
        'tracking_number_uuid', 'customer_uuid', 'customer_type', 'facilitator_uuid', 'facilitator_type',
        'driver_assigned_uuid', 'vehicle_assigned_uuid', 'current_job_uuid', 'telematic_uuid',
        'device_uuid', 'warranty_uuid', 'work_order_uuid', 'performed_by_uuid', 'default_assignee_uuid',
        'assignee_uuid', 'asset_uuid', 'user_uuid', 'subject_uuid', 'subject_type',
        'attachable_uuid', 'attachable_type', 'equipable_uuid', 'equipable_type',
        'maintainable_uuid', 'maintainable_type', 'sensorable_uuid', 'sensorable_type',
        'target_uuid', 'target_type',
    ];

    $schema = $connection->getSchemaBuilder();
    $tables = [
        'contacts', 'drivers', 'vehicles', 'orders', 'fleets', 'service_areas', 'zones', 'vendors',
        'issues', 'places', 'service_rates', 'devices', 'telematics', 'sensors', 'equipments',
        'warranties', 'maintenances', 'maintenance_schedules', 'parts', 'work_orders', 'payloads',
        'tracking_numbers', 'assets', 'users', 'companies',
    ];
    foreach ($tables as $table) {
        $schema->create($table, function ($blueprint) use ($columns) {
            $blueprint->increments('id');
            foreach ($columns as $column) {
                $blueprint->string($column)->nullable();
            }
            $blueprint->timestamps();
            $blueprint->timestamp('deleted_at')->nullable();
        });
    }

    session(['company' => 'company-export-1']);
    $connection->table('companies')->insert(['uuid' => 'company-export-1', 'name' => 'Export Co']);

    return $connection;
}

test('every export scopes its collection to the company and honours selections', function (string $exportClass, string $table) {
    $connection = fleetopsExportCollectionBoot();

    // Drivers are globally scoped to those with a surviving user account
    $connection->table('users')->insert([
        ['uuid' => 'export-user-1', 'company_uuid' => 'company-export-1', 'name' => 'Export User'],
        ['uuid' => 'export-user-2', 'company_uuid' => 'company-other', 'name' => 'Other User'],
    ]);

    // One in-company row to select, and one belonging to another company that
    // must never appear in either branch
    $connection->table($table)->insert([
        ['uuid' => 'export-row-1', 'public_id' => 'export_row_one', 'company_uuid' => 'company-export-1', 'name' => 'Selected Row', 'user_uuid' => 'export-user-1'],
        ['uuid' => 'export-row-2', 'public_id' => 'export_row_two', 'company_uuid' => 'company-other', 'name' => 'Other Company Row', 'user_uuid' => 'export-user-2'],
    ]);

    // Without selections every in-company row is exported
    $all = (new $exportClass([]))->collection();
    expect($all->pluck('uuid')->all())->toBe(['export-row-1']);

    // With selections the export narrows to the chosen uuids
    $selected = (new $exportClass(['export-row-1']))->collection();
    expect($selected->pluck('uuid')->all())->toBe(['export-row-1']);

    // Selections outside the company still resolve to nothing
    expect((new $exportClass(['export-row-2']))->collection())->toHaveCount(0);
})->with([
    'contacts'              => [Fleetbase\FleetOps\Exports\ContactExport::class, 'contacts'],
    'drivers'               => [Fleetbase\FleetOps\Exports\DriverExport::class, 'drivers'],
    'fleets'                => [Fleetbase\FleetOps\Exports\FleetExport::class, 'fleets'],
    'issues'                => [Fleetbase\FleetOps\Exports\IssueExport::class, 'issues'],
    'orders'                => [Fleetbase\FleetOps\Exports\OrderExport::class, 'orders'],
    'places'                => [Fleetbase\FleetOps\Exports\PlaceExport::class, 'places'],
    'service areas'         => [Fleetbase\FleetOps\Exports\ServiceAreaExport::class, 'service_areas'],
    'service rates'         => [Fleetbase\FleetOps\Exports\ServiceRateExport::class, 'service_rates'],
    'vendors'               => [Fleetbase\FleetOps\Exports\VendorExport::class, 'vendors'],
    'devices'               => [Fleetbase\FleetOps\Exports\DeviceExport::class, 'devices'],
    'equipment'             => [Fleetbase\FleetOps\Exports\EquipmentExport::class, 'equipments'],
    'maintenances'          => [Fleetbase\FleetOps\Exports\MaintenanceExport::class, 'maintenances'],
    'maintenance schedules' => [Fleetbase\FleetOps\Exports\MaintenanceScheduleExport::class, 'maintenance_schedules'],
    'parts'                 => [Fleetbase\FleetOps\Exports\PartExport::class, 'parts'],
    'sensors'               => [Fleetbase\FleetOps\Exports\SensorExport::class, 'sensors'],
    'telematics'            => [Fleetbase\FleetOps\Exports\TelematicExport::class, 'telematics'],
    'work orders'           => [Fleetbase\FleetOps\Exports\WorkOrderExport::class, 'work_orders'],
]);
