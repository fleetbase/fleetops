<?php

use Fleetbase\FleetOps\Console\Commands\ProcessMaintenanceTriggers;
use Fleetbase\FleetOps\Http\Controllers\Internal\v1\FleetController;
use Fleetbase\FleetOps\Models\FleetDriver;
use Fleetbase\FleetOps\Models\FleetVehicle;
use Fleetbase\FleetOps\Models\MaintenanceSchedule;
use Fleetbase\FleetOps\Models\WorkOrder;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Carbon;

/**
 * Covers the internal FleetController static lookup/assignment helpers and
 * the ProcessMaintenanceTriggers query helpers against SQLite: fleet,
 * driver, and vehicle lookups, driver/vehicle assignment existence,
 * creation and deletion, cache invalidation, and the maintenance trigger
 * schedule/work-order queries with connection selection.
 */
if (!function_exists('Fleetbase\Support\session')) {
    eval('namespace Fleetbase\Support; function session($key = null, $default = null) { if ($key === null) { return new class { public function has($k) { return \session($k) !== null; } public function get($k, $d = null) { return \session($k, $d); } }; } return \session($key, $default); }');
}

if (!function_exists('Fleetbase\Support\auth')) {
    eval('namespace Fleetbase\Support; function auth() { return new class { public function user() { return null; } public function id() { return null; } }; }');
}

if (!function_exists('Fleetbase\FleetOps\Console\Commands\event')) {
    eval('namespace Fleetbase\FleetOps\Console\Commands; function event($event = null, $payload = []) { \FleetOpsFleetTriggersRecorder::$events[] = [$event, $payload]; return $event; }');
}

class FleetOpsFleetTriggersRecorder
{
    public static array $events = [];
}

class FleetOpsFleetControllerProbe extends FleetController
{
    public static function callStatic(string $method, ...$arguments): mixed
    {
        $reflection = new ReflectionMethod(FleetController::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke(null, ...$arguments);
    }
}

class FleetOpsMaintenanceTriggersProbe extends ProcessMaintenanceTriggers
{
    public function callHelper(string $method, ...$arguments): mixed
    {
        return $this->{$method}(...$arguments);
    }
}

function fleetopsFleetTriggersBoot(): SQLiteConnection
{
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
    $resolver   = new ConnectionResolver(['default' => $connection, 'mysql' => $connection, 'sandbox' => $connection]);
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

    Illuminate\Support\Facades\Cache::swap(new class {
        public function tags(array $tags)
        {
            return $this;
        }

        public function flush(): bool
        {
            return true;
        }

        public function increment($key)
        {
            return 1;
        }

        public function get($key, $default = null)
        {
            return is_callable($default) ? $default() : $default;
        }

        public function __call($method, $arguments)
        {
            return null;
        }
    });

    $schema = $connection->getSchemaBuilder();
    $tables = [
        'fleets'                => ['uuid', 'public_id', 'company_uuid', 'name', 'status'],
        'fleet_drivers'         => ['uuid', 'fleet_uuid', 'driver_uuid'],
        'fleet_vehicles'        => ['uuid', 'fleet_uuid', 'vehicle_uuid'],
        'drivers'               => ['uuid', 'public_id', 'company_uuid', 'user_uuid', 'status'],
        'users'                 => ['uuid', 'public_id', 'company_uuid', 'name'],
        'vehicles'              => ['uuid', 'public_id', 'company_uuid', 'name'],
        'maintenance_schedules' => ['uuid', 'public_id', 'company_uuid', 'subject_uuid', 'subject_type', 'subject_id', 'status', 'next_due_date', 'next_due_odometer', 'next_due_engine_hours', 'name'],
        'work_orders'           => ['uuid', 'public_id', 'company_uuid', 'schedule_uuid', 'status', 'code', 'subject'],
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
    FleetOpsFleetTriggersRecorder::$events = [];

    return $connection;
}

test('fleet lookup and assignment helpers persist and remove memberships', function () {
    $connection = fleetopsFleetTriggersBoot();
    $connection->table('fleets')->insert(['uuid' => 'fleet-1', 'company_uuid' => 'company-1', 'name' => 'North Fleet']);
    $connection->table('users')->insert(['uuid' => 'user-1', 'company_uuid' => 'company-1']);
    $connection->table('drivers')->insert(['uuid' => 'driver-1', 'company_uuid' => 'company-1', 'user_uuid' => 'user-1']);
    $connection->table('vehicles')->insert(['uuid' => 'vehicle-1', 'company_uuid' => 'company-1', 'name' => 'Truck']);

    expect(FleetOpsFleetControllerProbe::callStatic('findFleetByUuid', 'fleet-1')?->uuid)->toBe('fleet-1')
        ->and(FleetOpsFleetControllerProbe::callStatic('findDriverByUuid', 'driver-1')?->uuid)->toBe('driver-1')
        ->and(FleetOpsFleetControllerProbe::callStatic('findVehicleByUuid', 'vehicle-1')?->uuid)->toBe('vehicle-1')
        ->and(FleetOpsFleetControllerProbe::callStatic('findFleetByUuid', 'missing'))->toBeNull();

    // Driver assignments
    expect(FleetOpsFleetControllerProbe::callStatic('fleetDriverAssignmentExists', 'fleet-1', 'driver-1'))->toBeFalse();
    $assignment = FleetOpsFleetControllerProbe::callStatic('createFleetDriverAssignment', 'fleet-1', 'driver-1');
    expect($assignment)->toBeInstanceOf(FleetDriver::class)
        ->and(FleetOpsFleetControllerProbe::callStatic('fleetDriverAssignmentExists', 'fleet-1', 'driver-1'))->toBeTrue();
    FleetOpsFleetControllerProbe::callStatic('deleteFleetDriverAssignment', 'fleet-1', 'driver-1');
    expect($connection->table('fleet_drivers')->whereNull('deleted_at')->count())->toBe(0);

    // Vehicle assignments
    $vehicleAssignment = FleetOpsFleetControllerProbe::callStatic('createFleetVehicleAssignment', 'fleet-1', 'vehicle-1');
    expect($vehicleAssignment)->toBeInstanceOf(FleetVehicle::class)
        ->and(FleetOpsFleetControllerProbe::callStatic('fleetVehicleAssignmentExists', 'fleet-1', 'vehicle-1'))->toBeTrue();
    FleetOpsFleetControllerProbe::callStatic('deleteFleetVehicleAssignment', 'fleet-1', 'vehicle-1');
    expect($connection->table('fleet_vehicles')->whereNull('deleted_at')->count())->toBe(0);

    // Cache invalidation and json responses execute against the fakes
    FleetOpsFleetControllerProbe::callStatic('invalidateOperationsMonitor');
    expect(FleetOpsFleetControllerProbe::callStatic('jsonResponse', ['status' => 'ok'])->getData(true))->toBe(['status' => 'ok']);
});

test('maintenance trigger helpers query schedules and work orders', function () {
    $connection = fleetopsFleetTriggersBoot();
    $probe      = new FleetOpsMaintenanceTriggersProbe();

    expect($probe->callHelper('connectionName', false))->toBe('mysql')
        ->and($probe->callHelper('connectionName', true))->toBe('sandbox');

    $connection->table('maintenance_schedules')->insert([
        ['uuid' => 'ms-1', 'company_uuid' => 'company-1', 'status' => 'active', 'next_due_date' => Carbon::now()->addDay()->toDateTimeString()],
        ['uuid' => 'ms-2', 'company_uuid' => 'company-1', 'status' => 'inactive', 'next_due_date' => Carbon::now()->addDay()->toDateTimeString()],
        ['uuid' => 'ms-3', 'company_uuid' => 'company-1', 'status' => 'active', 'next_due_date' => null],
    ]);

    $schedules = $probe->callHelper('schedules', 'mysql');
    expect($schedules)->toHaveCount(1)
        ->and($schedules->first()->uuid)->toBe('ms-1');

    $schedule = MaintenanceSchedule::on('mysql')->withoutGlobalScopes()->where('uuid', 'ms-1')->first();
    expect($probe->callHelper('openWorkOrderExists', 'mysql', $schedule))->toBeFalse();

    $connection->table('work_orders')->insert(['uuid' => 'wo-1', 'company_uuid' => 'company-1', 'schedule_uuid' => 'ms-1', 'status' => 'open']);
    expect($probe->callHelper('openWorkOrderExists', 'mysql', $schedule))->toBeTrue()
        ->and($probe->callHelper('workOrderCount', 'mysql'))->toBe(1);

    $created = $probe->callHelper('createWorkOrder', 'mysql', ['company_uuid' => 'company-1', 'schedule_uuid' => 'ms-1', 'status' => 'open', 'subject' => 'Inspection']);
    expect($created)->toBeInstanceOf(WorkOrder::class)
        ->and($probe->callHelper('workOrderCount', 'mysql'))->toBe(2);

    $probe->callHelper('dispatchTriggeredEvent', $schedule, $created);
    expect(FleetOpsFleetTriggersRecorder::$events)->toHaveCount(1)
        ->and(FleetOpsFleetTriggersRecorder::$events[0][0])->toBe('maintenance.triggered');
});
