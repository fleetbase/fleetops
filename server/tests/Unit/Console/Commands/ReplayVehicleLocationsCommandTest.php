<?php

use Fleetbase\FleetOps\Console\Commands\ReplayVehicleLocations;
use Fleetbase\FleetOps\Models\Vehicle;
use Illuminate\Console\Command;

class FleetOpsReplayVehicleLocationsProbe extends ReplayVehicleLocations
{
    public array $arguments          = [];
    public array $options            = [];
    public array $messages           = [];
    public array $vehicles           = [];
    public array $sent               = [];
    public array $sleepSeconds       = [];
    public array $sleepMicroseconds  = [];
    public array $microtimes         = [100.0, 102.5];
    public bool $fileExists          = true;
    public ?Throwable $sendThrowable = null;

    public function callHelper(string $method, mixed ...$arguments): mixed
    {
        $reflection = new ReflectionMethod(ReplayVehicleLocations::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($this, ...$arguments);
    }

    public function argument($key = null)
    {
        return $key === null ? $this->arguments : ($this->arguments[$key] ?? null);
    }

    public function option($key = null)
    {
        return $key === null ? $this->options : ($this->options[$key] ?? null);
    }

    public function info($string, $verbosity = null): void
    {
        $this->messages[] = ['info', $string];
    }

    public function error($string, $verbosity = null): void
    {
        $this->messages[] = ['error', $string];
    }

    public function warn($string, $verbosity = null): void
    {
        $this->messages[] = ['warn', $string];
    }

    public function line($string, $style = null, $verbosity = null): void
    {
        $this->messages[] = ['line', $string];
    }

    public function newLine($count = 1): void
    {
        $this->messages[] = ['newLine', $count];
    }

    protected function fileExists(string $filePath): bool
    {
        return $this->fileExists;
    }

    protected function socketClusterClient(): mixed
    {
        return new FleetOpsReplaySocketClusterFake($this);
    }

    protected function vehicleForPublicId(string $vehicleId): ?Vehicle
    {
        return $this->vehicles[$vehicleId] ?? null;
    }

    protected function sleepSeconds(int $seconds): void
    {
        $this->sleepSeconds[] = $seconds;
    }

    protected function sleepMicroseconds(int $microseconds): void
    {
        $this->sleepMicroseconds[] = $microseconds;
    }

    protected function currentMicrotime(): float
    {
        return array_shift($this->microtimes) ?? 100.0;
    }
}

class FleetOpsReplaySocketClusterFake
{
    public function __construct(private FleetOpsReplayVehicleLocationsProbe $command)
    {
    }

    public function send(string $channel, array $event): bool
    {
        if ($this->command->sendThrowable) {
            throw $this->command->sendThrowable;
        }

        $this->command->sent[] = [$channel, $event['id'] ?? null];

        return true;
    }
}

function fleetopsReplayEvents(): array
{
    return [
        [
            'id'         => 'event-1',
            'created_at' => '2026-01-01T10:00:00Z',
            'data'       => [
                'id'       => 'vehicle-1',
                'location' => ['coordinates' => [103.800001, 1.300001]],
                'speed'    => 12,
                'heading'  => 90,
            ],
        ],
        [
            'id'         => 'event-2',
            'created_at' => '2026-01-01T10:00:08Z',
            'data'       => [
                'id'       => 'vehicle-2',
                'location' => ['coordinates' => [103.900001, 1.400001]],
                'speed'    => 20,
                'heading'  => 180,
            ],
        ],
        [
            'id'         => 'event-3',
            'created_at' => '2026-01-01T10:00:10Z',
            'data'       => ['id' => 'vehicle-1'],
        ],
    ];
}

function fleetopsReplayTempFile(string $contents): string
{
    $path = tempnam(sys_get_temp_dir(), 'fleetops-replay-');
    file_put_contents($path, $contents);

    return $path;
}

function fleetopsReplayCommandForHandle(string $filePath): FleetOpsReplayVehicleLocationsProbe
{
    $command            = new FleetOpsReplayVehicleLocationsProbe();
    $command->arguments = ['file' => $filePath];
    $command->options   = [
        'speed'      => 2,
        'vehicle'    => null,
        'limit'      => null,
        'sleep'      => null,
        'skip-sleep' => false,
    ];

    $vehicle = new Vehicle();
    $vehicle->setRawAttributes(['uuid' => 'vehicle-uuid'], true);
    $command->vehicles = [
        'vehicle-1' => $vehicle,
    ];

    return $command;
}

test('replay vehicle locations command parses valid json and reports parse errors', function () {
    $command = new FleetOpsReplayVehicleLocationsProbe();
    $valid   = fleetopsReplayTempFile(json_encode(fleetopsReplayEvents()));
    $invalid = fleetopsReplayTempFile('{not json');

    [$events, $error]               = $command->callHelper('loadLocationEventsFromFile', $valid);
    [$invalidEvents, $invalidError] = $command->callHelper('loadLocationEventsFromFile', $invalid);

    expect($events)->toHaveCount(3)
        ->and($events[0]['id'])->toBe('event-1')
        ->and($error)->toBeNull()
        ->and($invalidEvents)->toBe([])
        ->and($invalidError)->toStartWith('Failed to parse JSON:');
});

test('replay vehicle locations command filters events and applies limits', function () {
    $command = new FleetOpsReplayVehicleLocationsProbe();
    $events  = fleetopsReplayEvents();

    expect($command->callHelper('filterEventsForVehicle', $events, null))->toHaveCount(3)
        ->and(array_column($command->callHelper('filterEventsForVehicle', $events, 'vehicle-1'), 'id'))->toBe(['event-1', 'event-3'])
        ->and($command->callHelper('filterEventsForVehicle', $events, 'missing'))->toBe([])
        ->and(array_column($command->callHelper('applyEventLimit', $events, 2), 'id'))->toBe(['event-1', 'event-2'])
        ->and($command->callHelper('applyEventLimit', $events, null))->toHaveCount(3)
        ->and($command->callHelper('applyEventLimit', $events, 10))->toHaveCount(3);
});

test('replay vehicle locations command calculates delay and vehicle channels', function () {
    $command = new FleetOpsReplayVehicleLocationsProbe();
    $vehicle = new Vehicle();
    $vehicle->setRawAttributes(['uuid' => 'vehicle-uuid'], true);

    expect($command->callHelper('calculateReplayDelay', '2026-01-01T10:00:00Z', '2026-01-01T10:00:08Z', 2.0))->toBe([8, 4.0])
        ->and($command->callHelper('channelsForVehicle', 'vehicle-public', $vehicle))->toBe([
            'vehicle.vehicle-public',
            'vehicle.vehicle-uuid',
        ]);
});

test('replay vehicle locations command formats sent lines with location telemetry', function () {
    $command = new FleetOpsReplayVehicleLocationsProbe();

    $line = $command->callHelper(
        'formatSentLine',
        2,
        3,
        'event-2',
        'vehicle-2',
        'vehicle.vehicle-2',
        fleetopsReplayEvents()[1],
        '2026-01-01T10:00:08Z'
    );

    expect($line)->toContain('[2/3] ✓ Sent event event-2 for vehicle vehicle-2')
        ->and($line)->toContain('Channel: vehicle.vehicle-2')
        ->and($line)->toContain('Coords: [103.900001, 1.400001]')
        ->and($line)->toContain('Speed: 20')
        ->and($line)->toContain('Heading: 180')
        ->and($line)->toContain('Time: 2026-01-01T10:00:08Z');
});

test('replay vehicle locations command handle rejects missing files invalid speed and empty data', function () {
    $missing             = fleetopsReplayCommandForHandle('/missing/replay.json');
    $missing->fileExists = false;

    $invalidSpeed                   = fleetopsReplayCommandForHandle(fleetopsReplayTempFile(json_encode(fleetopsReplayEvents())));
    $invalidSpeed->options['speed'] = 0;

    $empty = fleetopsReplayCommandForHandle(fleetopsReplayTempFile(json_encode([])));

    expect($missing->handle())->toBe(Command::FAILURE)
        ->and($missing->messages)->toContain(['error', 'File not found: /missing/replay.json'])
        ->and($invalidSpeed->handle())->toBe(Command::FAILURE)
        ->and($invalidSpeed->messages)->toContain(['error', 'Speed multiplier must be greater than 0'])
        ->and($empty->handle())->toBe(Command::FAILURE)
        ->and($empty->messages)->toContain(['error', 'Invalid or empty location data']);
});

test('replay vehicle locations command handle warns when filters match no events', function () {
    $command                     = fleetopsReplayCommandForHandle(fleetopsReplayTempFile(json_encode(fleetopsReplayEvents())));
    $command->options['vehicle'] = 'missing';

    expect($command->handle())->toBe(Command::SUCCESS)
        ->and($command->sent)->toBe([])
        ->and($command->messages)->toContain(['info', 'Filtering for vehicle: missing'])
        ->and($command->messages)->toContain(['warn', 'No events found matching the criteria']);
});

test('replay vehicle locations command handle sends events on vehicle channels and skips missing vehicles', function () {
    $command                   = fleetopsReplayCommandForHandle(fleetopsReplayTempFile(json_encode(fleetopsReplayEvents())));
    $command->options['limit'] = 2;

    expect($command->handle())->toBe(Command::SUCCESS)
        ->and($command->sent)->toBe([
            ['vehicle.vehicle-1', 'event-1'],
            ['vehicle.vehicle-uuid', 'event-1'],
        ])
        ->and($command->sleepSeconds)->toBe([])
        ->and($command->messages)->toContain(['info', 'Total events to process: 2'])
        ->and($command->messages)->toContain(['info', 'Successful: 2'])
        ->and($command->messages)->toContain(['info', 'Failed: 0']);
});

test('replay vehicle locations command handle supports sleep controls and reports send failures', function () {
    $command                     = fleetopsReplayCommandForHandle(fleetopsReplayTempFile(json_encode(fleetopsReplayEvents())));
    $command->options['vehicle'] = 'vehicle-1';
    $command->options['sleep']   = 3;
    $command->sendThrowable      = new RuntimeException('socket unavailable');

    expect($command->handle())->toBe(Command::FAILURE)
        ->and($command->sleepSeconds)->toBe([3])
        ->and($command->messages)->toContain(['error', '[1/2] ✗ Error for event event-1: socket unavailable'])
        ->and($command->messages)->toContain(['error', '[2/2] ✗ Error for event event-3: socket unavailable'])
        ->and($command->messages)->toContain(['error', 'Failed: 4']);
});

test('replay handles socket exception flavors bad timestamps and the real client factory', function () {
    // Connection and timeout websocket exceptions are reported distinctly
    $command                     = fleetopsReplayCommandForHandle(fleetopsReplayTempFile(json_encode(fleetopsReplayEvents())));
    $command->options['vehicle'] = 'vehicle-1';
    $command->sendThrowable      = new WebSocket\ConnectionException('link severed');
    expect($command->handle())->toBe(Command::FAILURE)
        ->and(collect($command->messages)->contains(fn ($m) => $m[0] === 'error' && str_contains($m[1], 'Connection error')))->toBeTrue();

    $timeoutCommand                     = fleetopsReplayCommandForHandle(fleetopsReplayTempFile(json_encode(fleetopsReplayEvents())));
    $timeoutCommand->options['vehicle'] = 'vehicle-1';
    $timeoutCommand->sendThrowable      = new WebSocket\TimeoutException('link timed out');
    expect($timeoutCommand->handle())->toBe(Command::FAILURE)
        ->and(collect($timeoutCommand->messages)->contains(fn ($m) => $m[0] === 'error' && str_contains($m[1], 'Timeout error')))->toBeTrue();

    // Unparseable created-at values warn without aborting the replay
    $events                             = fleetopsReplayEvents();
    $events[0]['created_at']            = 'not-a-real-timestamp';
    $events[2]['created_at']            = 'also-not-a-timestamp';
    $badTimeCommand                     = fleetopsReplayCommandForHandle(fleetopsReplayTempFile(json_encode($events)));
    $badTimeCommand->options['vehicle'] = 'vehicle-1';
    $badTimeCommand->handle();
    expect(collect($badTimeCommand->messages)->contains(fn ($m) => $m[0] === 'warn' && str_contains($m[1], 'Failed to calculate time difference')))->toBeTrue();

    // The real client factory attempts to construct the socket cluster
    // service (configuration-dependent, so both outcomes are acceptable)
    $factory = new ReflectionMethod(ReplayVehicleLocations::class, 'socketClusterClient');
    $factory->setAccessible(true);
    try {
        expect($factory->invoke(new ReplayVehicleLocations()))->toBeInstanceOf(Fleetbase\Support\SocketCluster\SocketClusterService::class);
    } catch (Throwable $e) {
        expect($e->getMessage())->toContain('URI');
    }
});
