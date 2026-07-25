<?php

use Fleetbase\FleetOps\Console\Commands\ReplayVehicleLocations;
use Fleetbase\FleetOps\Models\Vehicle;

class FleetOpsReplayVehicleLocationsProbe extends ReplayVehicleLocations
{
    public function callHelper(string $method, mixed ...$arguments): mixed
    {
        $reflection = new ReflectionMethod(ReplayVehicleLocations::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($this, ...$arguments);
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
