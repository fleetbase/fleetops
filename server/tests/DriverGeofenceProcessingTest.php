<?php

use Fleetbase\FleetOps\Http\Controllers\Api\v1\DriverController;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class FleetOpsDbRecorder
{
    public array $queries;
    public array $tables = [];

    public function __construct(FleetOpsDbQueryRecorder ...$queries)
    {
        $this->queries = $queries;
    }

    public function table(string $table): FleetOpsDbQueryRecorder
    {
        $this->tables[] = $table;

        return array_shift($this->queries) ?? new FleetOpsDbQueryRecorder();
    }
}

class FleetOpsDbQueryRecorder
{
    public array $wheres  = [];
    public ?array $upsert = null;
    public ?array $update = null;
    public mixed $first   = null;

    public function where(string $column, mixed $value): self
    {
        $this->wheres[] = [$column, $value];

        return $this;
    }

    public function first(): mixed
    {
        return $this->first;
    }

    public function upsert(array $rows, array $uniqueBy, array $update): int
    {
        $this->upsert = compact('rows', 'uniqueBy', 'update');

        return 1;
    }

    public function update(array $payload): int
    {
        $this->update = $payload;

        return 1;
    }
}

function fleetopsRecordDb(FleetOpsDbQueryRecorder ...$queries): FleetOpsDbRecorder
{
    $db = new FleetOpsDbRecorder(...$queries);
    app()->instance('db', $db);
    DB::clearResolvedInstance('db');

    return $db;
}

function fleetopsProcessDriverGeofenceCrossings(Driver $driver, array $crossings): void
{
    $controller = new DriverController();
    $reflection = new ReflectionMethod(DriverController::class, 'processSubjectGeofenceCrossings');
    $reflection->setAccessible(true);

    $reflection->invoke($controller, $driver, new Point(1.3, 103.8), 'driver_geofence_states', 'driver_uuid', $crossings);
}

function fleetopsGeofenceDriver(): Driver
{
    $driver = new Driver();
    $driver->setRawAttributes([
        'uuid'         => 'driver-uuid',
        'public_id'    => 'driver-public',
        'company_uuid' => 'company-uuid',
        'name'         => 'Ada Driver',
        'phone'        => '+15551234567',
    ], true);
    $driver->setRelation('vehicle', null);

    return $driver;
}

function fleetopsGeofence(array $attributes = []): object
{
    return (object) array_merge([
        'uuid'                    => 'geofence-uuid',
        'public_id'               => 'geofence-public',
        'name'                    => 'Depot',
        'trigger_on_entry'        => true,
        'trigger_on_exit'         => true,
        'dwell_threshold_minutes' => 0,
    ], $attributes);
}

test('driver geofence processor skips passive entries without state changes', function () {
    $db = fleetopsRecordDb();

    fleetopsProcessDriverGeofenceCrossings(fleetopsGeofenceDriver(), [
        [
            'type'          => 'entered',
            'geofence'      => fleetopsGeofence([
                'trigger_on_entry'        => false,
                'dwell_threshold_minutes' => 0,
            ]),
            'geofence_type' => 'zone',
        ],
    ]);

    expect($db->tables)->toBeEmpty();
});

test('driver geofence processor persists exit state with dwell duration inputs', function () {
    Carbon::setTestNow('2026-01-01 12:12:00');

    $stateQuery        = new FleetOpsDbQueryRecorder();
    $stateQuery->first = (object) ['entered_at' => Carbon::parse('2026-01-01 12:00:00')];
    $updateQuery       = new FleetOpsDbQueryRecorder();
    $db                = fleetopsRecordDb($stateQuery, $updateQuery);

    fleetopsProcessDriverGeofenceCrossings(fleetopsGeofenceDriver(), [
        [
            'type'          => 'exited',
            'geofence'      => fleetopsGeofence(['trigger_on_exit' => false]),
            'geofence_type' => 'zone',
        ],
    ]);

    expect($db->tables)->toBe(['driver_geofence_states', 'driver_geofence_states'])
        ->and($stateQuery->wheres)->toBe([
            ['driver_uuid', 'driver-uuid'],
            ['geofence_uuid', 'geofence-uuid'],
        ])
        ->and($updateQuery->wheres)->toBe([
            ['driver_uuid', 'driver-uuid'],
            ['geofence_uuid', 'geofence-uuid'],
        ])
        ->and($updateQuery->update['is_inside'])->toBeFalse()
        ->and($updateQuery->update['dwell_job_id'])->toBeNull()
        ->and($updateQuery->update['exited_at']->equalTo(Carbon::now()))->toBeTrue();

    Carbon::setTestNow();
});
