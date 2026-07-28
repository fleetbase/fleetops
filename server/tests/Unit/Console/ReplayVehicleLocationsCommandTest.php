<?php

if (!function_exists('Fleetbase\Support\session')) {
    eval('namespace Fleetbase\Support; function session($key = null, $default = null) { if ($key === null) { return new class { public function has($k) { return \session($k) !== null; } public function get($k, $d = null) { return \session($k, $d); } }; } return \session($key, $default); }');
}

use Fleetbase\FleetOps\Console\Commands\ReplayVehicleLocations;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;

/**
 * Covers the fleetops:replay-vehicle-locations command against SQLite:
 * argument validation failures, event filtering and limiting, the full
 * replay loop with recorded sleeps, fractional delays and fixed sleep
 * overrides, socket send errors counted as failures, and the file, sleep
 * and vehicle helper seams.
 */
class FleetOpsReplaySocketFake
{
    public array $sent      = [];
    public bool $throws     = false;
    public ?string $failFor = null;

    public function send($channel, $event)
    {
        if ($this->throws || ($this->failFor && str_contains($channel, $this->failFor))) {
            throw new RuntimeException('socket unavailable');
        }

        $this->sent[] = [$channel, $event];

        return true;
    }
}

class FleetOpsReplayVehicleProbe extends ReplayVehicleLocations
{
    public array $arguments = [];
    public array $options   = [];
    public array $messages  = [];
    public array $sleeps    = [];
    public FleetOpsReplaySocketFake $socket;

    public function __construct(array $arguments = [], array $options = [], ?FleetOpsReplaySocketFake $socket = null)
    {
        parent::__construct();
        $this->arguments = $arguments;
        $this->options   = $options;
        $this->socket    = $socket ?? new FleetOpsReplaySocketFake();
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

    public function warn($string, $verbosity = null)
    {
        $this->messages[] = ['warn', $string];
    }

    public function error($string, $verbosity = null)
    {
        $this->messages[] = ['error', $string];
    }

    public function line($string, $style = null, $verbosity = null)
    {
        $this->messages[] = ['line', $string];
    }

    public function newLine($count = 1)
    {
        return $this;
    }

    protected function socketClusterClient(): mixed
    {
        return $this->socket;
    }

    protected function sleepSeconds(int $seconds): void
    {
        $this->sleeps[] = ['seconds', $seconds];
    }

    protected function sleepMicroseconds(int $microseconds): void
    {
        $this->sleeps[] = ['microseconds', $microseconds];
    }

    public function callHelper(string $method, ...$arguments): mixed
    {
        $reflection = new ReflectionMethod(ReplayVehicleLocations::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($this, ...$arguments);
    }
}

function fleetopsReplayVehicleBoot(): SQLiteConnection
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
    $schema->create('vehicles', function ($blueprint) {
        $blueprint->increments('id');
        foreach (['uuid', 'public_id', 'company_uuid', 'name', 'location', 'online'] as $column) {
            $blueprint->string($column)->nullable();
        }
        $blueprint->timestamps();
        $blueprint->timestamp('deleted_at')->nullable();
    });

    session(['company' => 'company-1']);

    return $connection;
}

function fleetopsReplayVehicleFile(array $events): string
{
    $path = tempnam(sys_get_temp_dir(), 'replay-events-');
    file_put_contents($path, json_encode($events));

    return $path;
}

function fleetopsReplayVehicleEvents(): array
{
    return [
        [
            'id'         => 'event-1',
            'created_at' => '2026-07-01 08:00:00',
            'data'       => ['id' => 'vehicle_replay11', 'location' => ['coordinates' => [103.8, 1.3]], 'speed' => 30, 'heading' => 90],
        ],
        [
            'id'         => 'event-2',
            'created_at' => '2026-07-01 08:00:02',
            'data'       => ['id' => 'vehicle_replay11', 'location' => ['coordinates' => [103.81, 1.31]], 'speed' => 32, 'heading' => 92],
        ],
        [
            'id'         => 'event-3',
            'created_at' => '2026-07-01 08:00:03',
            'data'       => ['id' => 'vehicle_missing99', 'location' => ['coordinates' => [103.82, 1.32]], 'speed' => 20, 'heading' => 45],
        ],
    ];
}

test('invalid files speeds and payloads fail before replaying', function () {
    fleetopsReplayVehicleBoot();

    $missing = new FleetOpsReplayVehicleProbe(['file' => '/nope/never.json'], ['speed' => 1]);
    expect($missing->handle())->toBe(1);

    $file     = fleetopsReplayVehicleFile(fleetopsReplayVehicleEvents());
    $badSpeed = new FleetOpsReplayVehicleProbe(['file' => $file], ['speed' => 0]);
    expect($badSpeed->handle())->toBe(1);

    $badJson = tempnam(sys_get_temp_dir(), 'replay-bad-');
    file_put_contents($badJson, '{invalid json');
    $parseError = new FleetOpsReplayVehicleProbe(['file' => $badJson], ['speed' => 1]);
    expect($parseError->handle())->toBe(1);

    $empty = new FleetOpsReplayVehicleProbe(['file' => fleetopsReplayVehicleFile([])], ['speed' => 1]);
    expect($empty->handle())->toBe(1);

    // No events after filtering succeeds with a warning
    $filtered = new FleetOpsReplayVehicleProbe(['file' => $file], ['speed' => 1, 'vehicle' => 'vehicle_other']);
    expect($filtered->handle())->toBe(0);
});

test('the replay loop sends events with sleep pacing and counts errors', function () {
    $connection = fleetopsReplayVehicleBoot();
    $connection->table('vehicles')->insert(['uuid' => '11111111-1111-4111-8111-111111111111', 'public_id' => 'vehicle_replay11', 'company_uuid' => 'company-1']);

    $file  = fleetopsReplayVehicleFile(fleetopsReplayVehicleEvents());
    $probe = new FleetOpsReplayVehicleProbe(['file' => $file], ['speed' => 1]);
    expect($probe->handle())->toBe(0)
        ->and($probe->socket->sent)->toHaveCount(4)
        ->and(collect($probe->sleeps)->firstWhere(0, 'seconds'))->not->toBeNull();

    // Fractional multipliers add microsecond sleeps
    $fractional = new FleetOpsReplayVehicleProbe(['file' => $file], ['speed' => 3]);
    $fractional->handle();
    expect(collect($fractional->sleeps)->firstWhere(0, 'microseconds'))->not->toBeNull();

    // A fixed sleep option overrides the calculated delay
    $fixed = new FleetOpsReplayVehicleProbe(['file' => $file], ['speed' => 1, 'sleep' => 2]);
    $fixed->handle();
    expect(collect($fixed->sleeps)->firstWhere(1, 2))->not->toBeNull();

    // Send failures count as errors and fail the command
    $socket         = new FleetOpsReplaySocketFake();
    $socket->throws = true;
    $failing        = new FleetOpsReplayVehicleProbe(['file' => $file], ['speed' => 1, 'skip-sleep' => true, 'limit' => 1], $socket);
    expect($failing->handle())->toBe(1);
});

test('helper seams resolve files vehicles sleeps and timers', function () {
    $connection = fleetopsReplayVehicleBoot();
    $connection->table('vehicles')->insert(['uuid' => '11111111-1111-4111-8111-111111111111', 'public_id' => 'vehicle_replay11', 'company_uuid' => 'company-1']);
    $probe = new FleetOpsReplayVehicleProbe();

    // Non-array JSON payloads resolve to an empty event list
    $stringJson = tempnam(sys_get_temp_dir(), 'replay-str-');
    file_put_contents($stringJson, '"just a string"');
    expect($probe->callHelper('loadLocationEventsFromFile', $stringJson))->toBe([[], null]);

    expect($probe->callHelper('fileExists', $stringJson))->toBeTrue()
        ->and($probe->callHelper('vehicleForPublicId', 'vehicle_replay11')?->uuid)->toBe('11111111-1111-4111-8111-111111111111')
        ->and($probe->callHelper('currentMicrotime'))->toBeFloat();

    // Real sleep helpers with zero-cost arguments
    $real         = new ReplayVehicleLocations();
    $sleepSeconds = new ReflectionMethod(ReplayVehicleLocations::class, 'sleepSeconds');
    $sleepSeconds->setAccessible(true);
    $sleepSeconds->invoke($real, 0);
    $sleepMicroseconds = new ReflectionMethod(ReplayVehicleLocations::class, 'sleepMicroseconds');
    $sleepMicroseconds->setAccessible(true);
    $sleepMicroseconds->invoke($real, 1);
    expect(true)->toBeTrue();
});
