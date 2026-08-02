<?php

use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;

/**
 * Covers the createFromImport seam every spreadsheet importer exposes. The
 * importers delegate a normalized row to their model's import factory; the
 * feature tests stub the seam, so the delegation itself is asserted here by
 * checking the row reaches the database.
 */
if (!function_exists('Fleetbase\Observers\event')) {
    eval('namespace Fleetbase\Observers; function event($event = null, $payload = []) { return []; }');
}

if (!function_exists('Fleetbase\FleetOps\Observers\event')) {
    eval('namespace Fleetbase\FleetOps\Observers; function event($event = null, $payload = []) { return []; }');
}

if (!function_exists('Fleetbase\Support\session')) {
    eval('namespace Fleetbase\Support; function session($key = null, $default = null) { if ($key === null) { return new class { public function has($k) { return \session($k) !== null; } public function get($k, $d = null) { return \session($k, $d); } }; } return \session($key, $default); }');
}

function fleetopsImportSeamBoot(): SQLiteConnection
{
    $pdo = new PDO('sqlite::memory:');
    foreach (['ST_PointFromText', 'ST_GeomFromText'] as $spatialFunction) {
        $pdo->sqliteCreateFunction($spatialFunction, fn ($wkt, $srid = 0, $axisOrder = null) => $wkt);
    }
    if (!Illuminate\Support\Str::hasMacro('humanize')) {
        Illuminate\Support\Str::macro('humanize', fn ($value, $uppercase = true) => str_replace('_', ' ', Illuminate\Support\Str::snake((string) $value)));
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
        'uuid', 'public_id', 'internal_id', 'company_uuid', 'name', 'email', 'phone', 'title', 'status', 'type',
        'meta', '_key', '_import_id', 'slug', 'description', 'sku', 'serial_number', 'model', 'make', 'year',
        'plate_number', 'vin', 'report', 'amount', 'currency', 'volume', 'metric_unit', 'odometer',
        'street1', 'street2', 'city', 'province', 'postal_code', 'country', 'location', 'phone_country_code',
        'vendor_uuid', 'driver_uuid', 'vehicle_uuid', 'place_uuid', 'owner_uuid', 'owner_type', 'user_uuid',
        'service_area_uuid', 'parent_fleet_uuid', 'zone_uuid', 'order_uuid', 'subject_uuid', 'subject_type',
        'assignee_uuid', 'priority', 'category', 'due_at', 'scheduled_at',
        'avatar_url', 'trim', 'call_sign', 'fuel_card_number', 'online', 'code', 'manufacturer',
        'purchase_price', 'purchased_at', 'driver_name', 'assigned_to_uuid', 'reported_by_uuid',
        'cost', 'quantity', 'unit', 'warranty_uuid', 'asset_uuid', 'equipable_uuid', 'equipable_type',
        'maintainable_uuid', 'maintainable_type', 'target_uuid', 'target_type', 'interval', 'notes',
        'barcode', 'quantity_on_hand', 'unit_cost', 'msrp', 'subject', 'instructions', 'opened_at',
        'estimated_cost', 'approved_budget', 'actual_cost', 'cost_center', 'budget_code',
        'summary', 'started_at', 'completed_at', 'engine_hours', 'labor_cost', 'parts_cost', 'tax',
        'total_cost', 'interval_method', 'interval_type', 'interval_value', 'interval_unit',
        'interval_distance', 'interval_engine_hours', 'last_service_odometer', 'last_service_engine_hours',
        'last_service_date', 'next_due_date', 'next_due_odometer', 'next_due_engine_hours', 'default_priority',
    ];

    $schema = $connection->getSchemaBuilder();
    foreach ([
        'contacts', 'drivers', 'fleets', 'issues', 'places', 'vehicles', 'vendors', 'fuel_reports',
        'equipments', 'maintenances', 'maintenance_schedules', 'parts', 'work_orders', 'users', 'companies',
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

    app()->instance('geocoder', new class {
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

    session(['company' => 'company-import-1']);
    $connection->table('companies')->insert(['uuid' => 'company-import-1', 'name' => 'Import Co']);

    return $connection;
}

test('importers delegate rows to their model import factory', function (string $importClass, string $table, array $row) {
    $connection = fleetopsImportSeamBoot();

    $import     = new $importClass();
    $reflection = new ReflectionMethod($importClass, 'createFromImport');
    $reflection->setAccessible(true);
    $reflection->invoke($import, $row);

    // The delegated row is persisted for the session company
    expect($connection->table($table)->count())->toBe(1)
        ->and($connection->table($table)->value('company_uuid'))->toBe('company-import-1');
})->with([
    'contacts'                => [Fleetbase\FleetOps\Imports\ContactImport::class, 'contacts', ['name' => 'Imported Contact', 'email' => 'contact@example.test']],
    'fleets'                  => [Fleetbase\FleetOps\Imports\FleetImport::class, 'fleets', ['name' => 'Imported Fleet']],
    'issues'                  => [Fleetbase\FleetOps\Imports\IssueImport::class, 'issues', ['report' => 'Imported Issue', 'latitude' => 1.30, 'longitude' => 103.80]],
    'vendors'                 => [Fleetbase\FleetOps\Imports\VendorImport::class, 'vendors', ['name' => 'Imported Vendor']],
    'vehicles'                => [Fleetbase\FleetOps\Imports\VehicleImport::class, 'vehicles', ['make' => 'Toyota', 'model' => 'HiAce', 'plate_number' => 'SG-1234']],
    'equipment'               => [Fleetbase\FleetOps\Imports\EquipmentImport::class, 'equipments', ['name' => 'Imported Equipment']],
    'parts'                   => [Fleetbase\FleetOps\Imports\PartImport::class, 'parts', ['name' => 'Imported Part']],
    'work orders'             => [Fleetbase\FleetOps\Imports\WorkOrderImport::class, 'work_orders', ['name' => 'Imported Work Order']],
    'fuel reports'            => [Fleetbase\FleetOps\Imports\FuelReportImport::class, 'fuel_reports', ['report' => 'Imported Fuel Report', 'amount' => 50, 'volume' => 30]],
    'issues with coordinates' => [Fleetbase\FleetOps\Imports\IssueImport::class, 'issues', ['report' => 'Located Issue', 'latitude' => 1.30, 'longitude' => 103.80]],
    'maintenances'            => [Fleetbase\FleetOps\Imports\MaintenanceImport::class, 'maintenances', ['name' => 'Imported Maintenance']],
    'maintenance schedules'   => [Fleetbase\FleetOps\Imports\MaintenanceScheduleImport::class, 'maintenance_schedules', ['name' => 'Imported Schedule']],
    'places'                  => [Fleetbase\FleetOps\Imports\PlaceImport::class, 'places', ['name' => 'Imported Place', 'latitude' => 1.30, 'longitude' => 103.80]],
]);
