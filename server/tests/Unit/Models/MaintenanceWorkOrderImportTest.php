<?php

if (!function_exists('Fleetbase\Support\session')) {
    eval('namespace Fleetbase\Support; function session($key = null, $default = null) { if ($key === null) { return new class { public function has($k) { return \session($k) !== null; } public function get($k, $d = null) { return \session($k, $d); } }; } return \session($key, $default); }');
}

if (!function_exists('Fleetbase\Support\auth')) {
    eval('namespace Fleetbase\Support; function auth() { return new class { public function user() { return null; } public function id() { return null; } }; }');
}

if (!function_exists('Cknow\Money\config')) {
    eval('namespace Cknow\Money; function config($key = null, $default = null) { return $default; }');
}

use Fleetbase\FleetOps\Models\Maintenance;
use Fleetbase\FleetOps\Models\WorkOrder;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;

/**
 * Covers Maintenance and WorkOrder import-row creation against SQLite:
 * maintainable and target resolution by vehicle name with the equipment
 * fallback, performer and vendor assignee resolution, lifecycle guard
 * returns for start and complete, duration efficiency fallbacks, work
 * order code generation on create, and line-item normalization from
 * strings and invalid values.
 */
function fleetopsMaintenanceImportBoot(): SQLiteConnection
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->sqliteCreateFunction('ST_PointFromText', fn ($wkt, $srid = 0, $axisOrder = null) => $wkt);
    $pdo->sqliteCreateFunction('ST_GeomFromText', fn ($wkt, $srid = 0, $axisOrder = null) => $wkt);
    $pdo->sqliteCreateFunction('CONCAT', fn (...$parts) => implode('', array_map(fn ($part) => (string) $part, $parts)));
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
    Illuminate\Support\Facades\DB::clearResolvedInstance('db');

    app()->instance('responsecache', new class {
        public function __call($method, $arguments)
        {
            return null;
        }
    });

    $schema = $connection->getSchemaBuilder();
    $tables = [
        'maintenances' => ['uuid', 'public_id', 'company_uuid', 'maintainable_type', 'maintainable_uuid', 'maintainable_id', 'performed_by_type', 'performed_by_uuid', 'type', 'status', 'priority', 'summary', 'notes', 'scheduled_at', 'started_at', 'completed_at', 'odometer', 'engine_hours', 'labor_cost', 'parts_cost', 'tax', 'total_cost', 'currency', 'line_items', 'meta', '_key'],
        'work_orders'  => ['uuid', 'public_id', 'company_uuid', 'code', 'subject', 'category', 'status', 'priority', 'instructions', 'opened_at', 'due_at', 'estimated_cost', 'approved_budget', 'actual_cost', 'currency', 'cost_center', 'budget_code', 'target_type', 'target_uuid', 'assignee_type', 'assignee_uuid', 'meta', 'line_items', '_key'],
        'vehicles'     => ['uuid', 'public_id', 'company_uuid', 'name', 'make', 'model', 'year', 'plate_number', 'internal_id', 'vin', 'serial_number', 'call_sign', 'fuel_card_number', 'location', 'online'],
        'equipments'   => ['uuid', 'public_id', 'company_uuid', 'name', 'serial_number', 'type', 'status'],
        'vendors'      => ['uuid', 'public_id', 'company_uuid', 'name', 'type', 'status'],
        'drivers'      => ['uuid', 'public_id', 'company_uuid', 'user_uuid', 'location', 'online'],
        'users'        => ['uuid', 'public_id', 'company_uuid', 'name', 'type', 'status'],
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

    session(['company' => 'company-1']);

    return $connection;
}

test('maintenance imports resolve vehicles equipment and performers', function () {
    $connection = fleetopsMaintenanceImportBoot();
    $connection->table('vehicles')->insert(['uuid' => 'vehicle-1', 'public_id' => 'vehicle_maint1', 'company_uuid' => 'company-1', 'name' => 'Van 12', 'plate_number' => 'SGV1234A']);
    $connection->table('equipments')->insert(['uuid' => 'equip-1', 'public_id' => 'equipment_maint1', 'company_uuid' => 'company-1', 'name' => 'Generator Alpha', 'serial_number' => 'GEN-9']);
    $connection->table('users')->insert(['uuid' => 'user-1', 'company_uuid' => 'company-1', 'name' => 'Mech', 'type' => 'user']);
    $connection->table('drivers')->insert(['uuid' => 'driver-1', 'public_id' => 'driver_maint1', 'company_uuid' => 'company-1', 'user_uuid' => 'user-1']);

    $withVehicle = Maintenance::createFromImport([
        'summary'      => 'Oil change',
        'vehicle'      => 'SGV1234A',
        'performed_by' => 'driver_maint1',
        'labor_cost'   => '150.00',
    ], true);
    expect($withVehicle->maintainable_uuid)->toBe('vehicle-1')
        ->and($withVehicle->performed_by_uuid)->toBe('driver-1')
        ->and($connection->table('maintenances')->count())->toBe(1);

    $withEquipment = Maintenance::createFromImport([
        'summary' => 'Generator service',
        'vehicle' => 'Generator Alpha',
    ]);
    expect($withEquipment->maintainable_uuid)->toBe('equip-1');
});

test('maintenance lifecycle guards efficiency and line items normalize', function () {
    fleetopsMaintenanceImportBoot();

    $completed = new Maintenance();
    $completed->setRawAttributes(['uuid' => 'm-1', 'status' => 'completed'], true);
    expect($completed->start())->toBeFalse()
        ->and($completed->complete())->toBeFalse();

    // Efficiency without estimated hours resolves to null
    $noEstimate = new Maintenance();
    $noEstimate->setRawAttributes(['uuid' => 'm-2', 'meta' => json_encode([]), 'started_at' => '2026-07-01 08:00:00', 'completed_at' => '2026-07-01 10:00:00'], true);
    expect($noEstimate->duration_efficiency)->toBeNull();

    // Line items normalize from json strings and reject scalars
    $maintenance             = new Maintenance();
    $maintenance->line_items = json_encode([['description' => 'Filter', 'unit_cost' => '25.00', 'quantity' => 1]]);
    expect(json_decode($maintenance->getAttributes()['line_items'], true))->toHaveCount(1);

    $maintenance->line_items = 12345;
    expect(json_decode($maintenance->getAttributes()['line_items'], true))->toBe([]);
});

test('work order imports resolve targets assignees and generate codes', function () {
    $connection = fleetopsMaintenanceImportBoot();
    $connection->table('vehicles')->insert(['uuid' => 'vehicle-1', 'public_id' => 'vehicle_wo1', 'company_uuid' => 'company-1', 'name' => 'Truck 7', 'plate_number' => 'SGT7777B']);
    $connection->table('equipments')->insert(['uuid' => 'equip-1', 'public_id' => 'equipment_wo1', 'company_uuid' => 'company-1', 'name' => 'Crane Beta', 'serial_number' => 'CRN-2']);
    $connection->table('vendors')->insert(['uuid' => 'vendor-1', 'public_id' => 'vendor_wo1', 'company_uuid' => 'company-1', 'name' => 'FixIt Repairs']);

    $withVehicle = WorkOrder::createFromImport([
        'subject' => 'Brake inspection',
        'vehicle' => 'SGT7777B',
        'vendor'  => 'FixIt',
    ], true);
    expect($withVehicle->target_uuid)->toBe('vehicle-1')
        ->and($withVehicle->assignee_uuid)->toBe('vendor-1')
        ->and($withVehicle->code)->toStartWith('WO-')
        ->and($connection->table('work_orders')->count())->toBe(1);

    $withEquipment = WorkOrder::createFromImport([
        'subject' => 'Crane check',
        'vehicle' => 'Crane Beta',
    ]);
    expect($withEquipment->target_uuid)->toBe('equip-1');
});
