<?php

use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\FleetOps\Support\GeofenceIntersectionService;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class FleetOpsGeofenceIntersectionProbe extends GeofenceIntersectionService
{
    public array $calls  = [];
    public array $result = [];

    protected function detectSubjectCrossings(string $companyUuid, Point $newLocation, string $stateTable, string $subjectColumn, string $subjectUuid): array
    {
        $this->calls[] = compact('companyUuid', 'newLocation', 'stateTable', 'subjectColumn', 'subjectUuid');

        return $this->result;
    }
}

class FleetOpsGeofenceDbRecorder
{
    public array $queries;
    public array $tables = [];

    public function __construct(FleetOpsGeofenceDbQueryRecorder ...$queries)
    {
        $this->queries = $queries;
    }

    public function table(string $table): FleetOpsGeofenceDbQueryRecorder
    {
        $this->tables[] = $table;

        return array_shift($this->queries) ?? new FleetOpsGeofenceDbQueryRecorder();
    }
}

class FleetOpsGeofenceDbQueryRecorder
{
    public array $wheres  = [];
    public ?array $update = null;
    public bool $exists   = false;

    public function where(string $column, mixed $operator = null, mixed $value = null, string $boolean = 'and'): self
    {
        $this->wheres[] = [$column, func_num_args() === 2 ? $operator : $value, $boolean];

        return $this;
    }

    public function exists(): bool
    {
        return $this->exists;
    }

    public function update(array $payload): int
    {
        $this->update = $payload;

        return 1;
    }
}

function fleetopsGeofenceRecordDb(FleetOpsGeofenceDbQueryRecorder ...$queries): FleetOpsGeofenceDbRecorder
{
    $db = new FleetOpsGeofenceDbRecorder(...$queries);
    app()->instance('db', $db);
    DB::clearResolvedInstance('db');

    return $db;
}

function fleetopsGeofenceDriverModel(): Driver
{
    $driver = new Driver();
    $driver->setRawAttributes([
        'uuid'         => 'driver-uuid',
        'company_uuid' => 'company-uuid',
    ], true);

    return $driver;
}

function fleetopsGeofenceVehicleModel(): Vehicle
{
    $vehicle = new Vehicle();
    $vehicle->setRawAttributes([
        'uuid'         => 'vehicle-uuid',
        'company_uuid' => 'company-uuid',
    ], true);

    return $vehicle;
}

test('geofence intersection service routes driver and vehicle crossing detection', function () {
    $service         = new FleetOpsGeofenceIntersectionProbe();
    $point           = new Point(1.3, 103.8);
    $service->result = [['type' => 'entered']];

    expect($service->detectCrossings(fleetopsGeofenceDriverModel(), $point))->toBe([['type' => 'entered']])
        ->and($service->calls[0])->toMatchArray([
            'companyUuid'   => 'company-uuid',
            'stateTable'    => 'driver_geofence_states',
            'subjectColumn' => 'driver_uuid',
            'subjectUuid'   => 'driver-uuid',
        ])
        ->and($service->detectCrossings(fleetopsGeofenceVehicleModel(), $point))->toBe([['type' => 'entered']])
        ->and($service->calls[1])->toMatchArray([
            'companyUuid'   => 'company-uuid',
            'stateTable'    => 'vehicle_geofence_states',
            'subjectColumn' => 'vehicle_uuid',
            'subjectUuid'   => 'vehicle-uuid',
        ]);
});

test('geofence intersection service checks recorded inside state for drivers and vehicles', function () {
    $service             = new GeofenceIntersectionService();
    $driverQuery         = new FleetOpsGeofenceDbQueryRecorder();
    $vehicleQuery        = new FleetOpsGeofenceDbQueryRecorder();
    $driverQuery->exists = true;
    $db                  = fleetopsGeofenceRecordDb($driverQuery, $vehicleQuery);

    expect($service->isDriverInsideGeofence(fleetopsGeofenceDriverModel(), (object) ['uuid' => 'geofence-uuid']))->toBeTrue()
        ->and($service->isVehicleInsideGeofence(fleetopsGeofenceVehicleModel(), (object) ['uuid' => 'geofence-uuid']))->toBeFalse()
        ->and($db->tables)->toBe(['driver_geofence_states', 'vehicle_geofence_states'])
        ->and($driverQuery->wheres)->toBe([
            ['driver_uuid', 'driver-uuid', 'and'],
            ['geofence_uuid', 'geofence-uuid', 'and'],
            ['is_inside', true, 'and'],
        ])
        ->and($vehicleQuery->wheres)->toBe([
            ['vehicle_uuid', 'vehicle-uuid', 'and'],
            ['geofence_uuid', 'geofence-uuid', 'and'],
            ['is_inside', true, 'and'],
        ]);
});

test('geofence intersection service clears driver and vehicle state records', function () {
    Carbon::setTestNow('2026-01-01 12:00:00');

    $service      = new GeofenceIntersectionService();
    $driverQuery  = new FleetOpsGeofenceDbQueryRecorder();
    $vehicleQuery = new FleetOpsGeofenceDbQueryRecorder();
    $db           = fleetopsGeofenceRecordDb($driverQuery, $vehicleQuery);

    $service->clearDriverState(fleetopsGeofenceDriverModel());
    $service->clearVehicleState(fleetopsGeofenceVehicleModel());

    expect($db->tables)->toBe(['driver_geofence_states', 'vehicle_geofence_states'])
        ->and($driverQuery->wheres)->toBe([['driver_uuid', 'driver-uuid', 'and']])
        ->and($vehicleQuery->wheres)->toBe([['vehicle_uuid', 'vehicle-uuid', 'and']])
        ->and($driverQuery->update)->toMatchArray([
            'is_inside'    => false,
            'dwell_job_id' => null,
        ])
        ->and($driverQuery->update['exited_at']->equalTo(Carbon::now()))->toBeTrue()
        ->and($driverQuery->update['updated_at']->equalTo(Carbon::now()))->toBeTrue()
        ->and($vehicleQuery->update['exited_at']->equalTo(Carbon::now()))->toBeTrue();

    Carbon::setTestNow();
});
