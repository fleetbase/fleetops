<?php

if (!function_exists('Fleetbase\Support\session')) {
    eval('namespace Fleetbase\Support; function session($key = null, $default = null) { if ($key === null) { return new class { public function has($k) { return \session($k) !== null; } public function get($k, $d = null) { return \session($k, $d); } }; } return \session($key, $default); }');
}

if (!function_exists('Fleetbase\FleetOps\Console\Commands\event')) {
    eval('namespace Fleetbase\FleetOps\Console\Commands; function event($event = null, $payload = []) { $GLOBALS["fleetopsSimulateGeofenceEvents"][] = $event; return []; }');
}

use Fleetbase\FleetOps\Console\Commands\SimulateGeofenceEvents;
use Fleetbase\FleetOps\Models\Zone;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;

/**
 * Covers the fleetops:simulate-geofence-events command against SQLite:
 * event parsing including sequences and invalid values, subject and
 * geofence resolution by public id and uuid, state table and column
 * mapping, the full simulation loop marking inside/outside states and
 * dispatching entered, dwelled and exited events with dwell math, sleep
 * pacing, and the failure branches for invalid arguments.
 */
class FleetOpsSimulateGeofenceZone extends Zone
{
    protected $table = 'zones';

    public function getLatitudeAttribute(): float
    {
        return 1.30;
    }

    public function getLongitudeAttribute(): float
    {
        return 103.80;
    }
}

class FleetOpsSimulateGeofenceProbe extends SimulateGeofenceEvents
{
    public array $arguments = [];
    public array $options   = [];
    public array $messages  = [];

    public function __construct(array $arguments = [], array $options = [])
    {
        parent::__construct();
        $this->arguments = $arguments;
        $this->options   = $options;
    }

    public function argument($key = null)
    {
        return $this->arguments[$key] ?? null;
    }

    public function option($key = null)
    {
        return $this->options[$key] ?? null;
    }

    public function info($string, $verbosity = null)
    {
        $this->messages[] = ['info', $string];
    }

    public function line($string, $style = null, $verbosity = null)
    {
        $this->messages[] = ['line', $string];
    }

    public function error($string, $verbosity = null)
    {
        $this->messages[] = ['error', $string];
    }

    public function newLine($count = 1)
    {
        $this->messages[] = ['newline', $count];

        return $this;
    }

    protected function resolveGeofence(string $identifier): array
    {
        // The real Zone location accessor requires the GEOS extension, so
        // the full-run probe hydrates a coordinate-stubbed zone instead
        $zone = FleetOpsSimulateGeofenceZone::withoutGlobalScopes()->where('public_id', $identifier)->first();

        return $zone ? ['zone', $zone] : [null, null];
    }

    public function callHelper(string $method, ...$arguments): mixed
    {
        $reflection = new ReflectionMethod(SimulateGeofenceEvents::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($this, ...$arguments);
    }
}

function fleetopsSimulateGeofenceBoot(): SQLiteConnection
{
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
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

    $schema = $connection->getSchemaBuilder();
    $tables = [
        'drivers'       => ['uuid', 'public_id', 'company_uuid', 'user_uuid', 'vehicle_uuid', 'location', 'online'],
        'vehicles'      => ['uuid', 'public_id', 'company_uuid', 'name', 'location', 'online'],
        'zones'         => ['uuid', 'public_id', 'company_uuid', 'service_area_uuid', 'name', 'border'],
        'service_areas' => ['uuid', 'public_id', 'company_uuid', 'name', 'border', 'type'],
        'users'         => ['uuid', 'public_id', 'company_uuid', 'name', 'type'],
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
    foreach (['driver_geofence_states' => 'driver_uuid', 'vehicle_geofence_states' => 'vehicle_uuid'] as $table => $column) {
        $schema->create($table, function ($blueprint) use ($column) {
            $blueprint->increments('id');
            foreach ([$column, 'geofence_uuid', 'geofence_type', 'entered_at', 'exited_at', 'dwell_job_id'] as $col) {
                $blueprint->string($col)->nullable();
            }
            $blueprint->integer('is_inside')->nullable();
            $blueprint->timestamps();
            $blueprint->unique([$column, 'geofence_uuid']);
        });
    }

    session(['company' => 'company-1']);

    return $connection;
}

test('events subjects geofences and state mappings resolve', function () {
    $connection = fleetopsSimulateGeofenceBoot();
    $connection->table('drivers')->insert(['uuid' => '11111111-1111-4111-8111-111111111111', 'public_id' => 'driver_simgeo11', 'company_uuid' => 'company-1']);
    $connection->table('vehicles')->insert(['uuid' => '22222222-2222-4222-8222-222222222222', 'public_id' => 'vehicle_simgeo22', 'company_uuid' => 'company-1']);
    $connection->table('zones')->insert(['uuid' => '33333333-3333-4333-8333-333333333333', 'public_id' => 'zone_simgeo333', 'company_uuid' => 'company-1']);
    $connection->table('service_areas')->insert(['uuid' => '44444444-4444-4444-8444-444444444444', 'public_id' => 'sa_simgeo4444', 'company_uuid' => 'company-1']);

    $probe = new FleetOpsSimulateGeofenceProbe();

    expect($probe->callHelper('parseEvents', 'sequence'))->toBe(['entered', 'dwelled', 'exited'])
        ->and($probe->callHelper('parseEvents', 'entered, exited, bogus'))->toBe(['entered', 'exited'])
        ->and($probe->callHelper('parseEvents', 'bogus'))->toBe([]);

    expect($probe->callHelper('resolveSubject', 'driver_simgeo11')[1]?->uuid)->toBe('11111111-1111-4111-8111-111111111111')
        ->and($probe->callHelper('resolveSubject', 'vehicle_simgeo22')[0])->toBe('vehicle')
        ->and($probe->callHelper('resolveSubject', '11111111-1111-4111-8111-111111111111')[0])->toBe('driver')
        ->and($probe->callHelper('resolveSubject', '22222222-2222-4222-8222-222222222222')[0])->toBe('vehicle')
        ->and($probe->callHelper('resolveSubject', 'unknown')[0])->toBeNull();

    $resolveGeofence = new ReflectionMethod(SimulateGeofenceEvents::class, 'resolveGeofence');
    $resolveGeofence->setAccessible(true);
    $command = new SimulateGeofenceEvents();
    expect($resolveGeofence->invoke($command, 'zone_simgeo333')[0])->toBe('zone')
        ->and($resolveGeofence->invoke($command, 'sa_simgeo4444')[0])->toBe('service_area')
        ->and($resolveGeofence->invoke($command, '33333333-3333-4333-8333-333333333333')[0])->toBe('zone')
        ->and($resolveGeofence->invoke($command, '44444444-4444-4444-8444-444444444444')[0])->toBe('service_area')
        ->and($resolveGeofence->invoke($command, 'nothing')[0])->toBeNull();

    expect($probe->callHelper('stateTable', 'vehicle'))->toBe('vehicle_geofence_states')
        ->and($probe->callHelper('stateTable', 'driver'))->toBe('driver_geofence_states')
        ->and($probe->callHelper('subjectColumn', 'vehicle'))->toBe('vehicle_uuid')
        ->and($probe->callHelper('subjectColumn', 'driver'))->toBe('driver_uuid');
});

test('the simulation loop marks states and dispatches the event sequence', function () {
    $connection = fleetopsSimulateGeofenceBoot();
    $connection->table('drivers')->insert(['uuid' => '11111111-1111-4111-8111-111111111111', 'public_id' => 'driver_simrun11', 'company_uuid' => 'company-1']);
    $connection->table('zones')->insert(['uuid' => '33333333-3333-4333-8333-333333333333', 'public_id' => 'zone_simrun333', 'company_uuid' => 'company-1']);

    $GLOBALS['fleetopsSimulateGeofenceEvents'] = [];
    $probe                                     = new FleetOpsSimulateGeofenceProbe(
        ['events' => 'sequence', 'subject' => 'driver_simrun11', 'geofence' => 'zone_simrun333'],
        ['repeat' => 1, 'sleep' => 0, 'dwell-minutes' => 5, 'no-log' => false, 'reset-state' => true]
    );

    expect($probe->handle())->toBe(0)
        ->and($GLOBALS['fleetopsSimulateGeofenceEvents'])->toHaveCount(3)
        ->and((int) $connection->table('driver_geofence_states')->value('is_inside'))->toBe(0)
        ->and($connection->table('driver_geofence_states')->value('exited_at'))->not->toBeNull();
});

test('sleep pacing applies and invalid arguments fail fast', function () {
    $connection = fleetopsSimulateGeofenceBoot();
    $connection->table('drivers')->insert(['uuid' => '11111111-1111-4111-8111-111111111111', 'public_id' => 'driver_simerr11', 'company_uuid' => 'company-1']);
    $connection->table('zones')->insert(['uuid' => '33333333-3333-4333-8333-333333333333', 'public_id' => 'zone_simerr333', 'company_uuid' => 'company-1']);

    // A one-second pacing pass through a single event covers the sleep branch
    $paced = new FleetOpsSimulateGeofenceProbe(
        ['events' => 'entered', 'subject' => 'driver_simerr11', 'geofence' => 'zone_simerr333'],
        ['repeat' => 1, 'sleep' => 1, 'dwell-minutes' => 5, 'no-log' => true, 'reset-state' => false]
    );
    expect($paced->handle())->toBe(0);

    $invalidEvents = new FleetOpsSimulateGeofenceProbe(['events' => 'bogus', 'subject' => 'x', 'geofence' => 'y'], ['repeat' => 1, 'sleep' => 0, 'dwell-minutes' => 5]);
    expect($invalidEvents->handle())->toBe(1);

    $missingSubject = new FleetOpsSimulateGeofenceProbe(['events' => 'entered', 'subject' => 'driver_missing99', 'geofence' => 'zone_simerr333'], ['repeat' => 1, 'sleep' => 0, 'dwell-minutes' => 5]);
    expect($missingSubject->handle())->toBe(1);

    $missingGeofence = new FleetOpsSimulateGeofenceProbe(['events' => 'entered', 'subject' => 'driver_simerr11', 'geofence' => 'zone_missing99'], ['repeat' => 1, 'sleep' => 0, 'dwell-minutes' => 5]);
    expect($missingGeofence->handle())->toBe(1);
});
