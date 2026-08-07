<?php

use Carbon\Carbon;
use Fleetbase\FleetOps\Console\Commands\SimulateGeofenceEvents;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\ServiceArea;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\FleetOps\Models\Zone;
use Illuminate\Console\Command;

class FleetOpsSimulateGeofenceEventsCommandProbe extends SimulateGeofenceEvents
{
    public array $arguments = [];
    public array $options   = [];
    public array $messages  = [];
    public array $subjects  = [];
    public array $geofences = [];
    public array $reset     = [];
    public array $inside    = [];
    public array $outside   = [];
    public array $ensured   = [];
    public array $dwells    = [];

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

    public function line($string, $style = null, $verbosity = null): void
    {
        $this->messages[] = ['line', $string];
    }

    public function newLine($count = 1): void
    {
        $this->messages[] = ['newLine', $count];
    }

    protected function resolveSubject(string $identifier): array
    {
        return $this->subjects[$identifier] ?? [null, null];
    }

    protected function resolveGeofence(string $identifier): array
    {
        return $this->geofences[$identifier] ?? [null, null];
    }

    protected function resetState(string $subjectType, Driver|Vehicle $subject, Zone|ServiceArea $geofence): void
    {
        $this->reset[] = [$subjectType, $subject->uuid, $geofence->uuid];
    }

    protected function markInside(string $subjectType, Driver|Vehicle $subject, Zone|ServiceArea $geofence, Carbon $enteredAt): void
    {
        $this->inside[] = [$subjectType, $subject->uuid, $geofence->uuid, $enteredAt->toDateTimeString()];
    }

    protected function ensureEnteredAt(string $subjectType, Driver|Vehicle $subject, Zone|ServiceArea $geofence, Carbon $enteredAt): void
    {
        $this->ensured[] = [$subjectType, $subject->uuid, $geofence->uuid, $enteredAt->toDateTimeString()];
    }

    protected function markOutside(string $subjectType, Driver|Vehicle $subject, Zone|ServiceArea $geofence): void
    {
        $this->outside[] = [$subjectType, $subject->uuid, $geofence->uuid];
    }

    protected function calculateDwellMinutes(string $subjectType, Driver|Vehicle $subject, Zone|ServiceArea $geofence, int $fallback): int
    {
        $this->dwells[] = [$subjectType, $subject->uuid, $geofence->uuid, $fallback];

        return 42;
    }
}

class FleetOpsSimulateGeofenceZoneFake extends Zone
{
    public function getLatitudeAttribute(): float
    {
        return 1.3521;
    }

    public function getLongitudeAttribute(): float
    {
        return 103.8198;
    }
}

function fleetopsSimulateGeofenceCommand(array $overrides = []): FleetOpsSimulateGeofenceEventsCommandProbe
{
    $command            = new FleetOpsSimulateGeofenceEventsCommandProbe();
    $command->arguments = array_merge([
        'subject'  => 'driver_public',
        'geofence' => 'zone_public',
        'events'   => 'sequence',
    ], $overrides['arguments'] ?? []);
    $command->options = array_merge([
        'repeat'        => 1,
        'sleep'         => 0,
        'dwell-minutes' => 10,
        'reset-state'   => false,
        'no-log'        => true,
    ], $overrides['options'] ?? []);

    return $command;
}

function fleetopsSimulateGeofenceDriver(): Driver
{
    $driver = new Driver();
    $driver->setRawAttributes([
        'uuid'         => 'driver-uuid',
        'public_id'    => 'driver_public',
        'company_uuid' => 'company-uuid',
    ], true);

    return $driver;
}

function fleetopsSimulateGeofenceZone(): Zone
{
    $zone = new FleetOpsSimulateGeofenceZoneFake();
    $zone->setRawAttributes([
        'uuid'      => 'zone-uuid',
        'public_id' => 'zone_public',
    ], true);

    return $zone;
}

test('simulate geofence command handle rejects invalid events and missing resources', function () {
    $invalid = fleetopsSimulateGeofenceCommand([
        'arguments' => ['events' => 'arrived'],
    ]);

    $missingSubject                           = fleetopsSimulateGeofenceCommand();
    $missingSubject->geofences['zone_public'] = ['zone', fleetopsSimulateGeofenceZone()];

    $missingGeofence                            = fleetopsSimulateGeofenceCommand();
    $missingGeofence->subjects['driver_public'] = ['driver', fleetopsSimulateGeofenceDriver()];

    expect($invalid->handle())->toBe(Command::FAILURE)
        ->and($invalid->messages)->toContain(['error', 'Invalid arguments. Events must be entered|exited|dwelled|sequence or a comma-separated combination.'])
        ->and($missingSubject->handle())->toBe(Command::FAILURE)
        ->and($missingSubject->messages[0][1])->toContain('Unable to resolve subject [driver_public]')
        ->and($missingGeofence->handle())->toBe(Command::FAILURE)
        ->and($missingGeofence->messages[0][1])->toContain('Unable to resolve geofence [zone_public]');
});

test('simulate geofence command handle runs repeated no-log event sequence and state transitions', function () {
    Carbon::setTestNow('2026-07-27 12:00:00');

    $command = fleetopsSimulateGeofenceCommand([
        'options' => [
            'repeat'        => 2,
            'dwell-minutes' => 15,
            'reset-state'   => true,
        ],
    ]);
    $command->subjects['driver_public']  = ['driver', fleetopsSimulateGeofenceDriver()];
    $command->geofences['zone_public']   = ['zone', fleetopsSimulateGeofenceZone()];

    expect($command->handle())->toBe(Command::SUCCESS)
        ->and(session('company'))->toBe('company-uuid')
        ->and($command->reset)->toBe([['driver', 'driver-uuid', 'zone-uuid']])
        ->and($command->inside)->toHaveCount(4)
        ->and($command->inside[0])->toBe(['driver', 'driver-uuid', 'zone-uuid', '2026-07-27 12:00:00'])
        ->and($command->inside[1])->toBe(['driver', 'driver-uuid', 'zone-uuid', '2026-07-27 11:45:00'])
        ->and($command->ensured)->toBe([
            ['driver', 'driver-uuid', 'zone-uuid', '2026-07-27 11:45:00'],
            ['driver', 'driver-uuid', 'zone-uuid', '2026-07-27 11:45:00'],
        ])
        ->and($command->dwells)->toBe([
            ['driver', 'driver-uuid', 'zone-uuid', 15],
            ['driver', 'driver-uuid', 'zone-uuid', 15],
        ])
        ->and($command->outside)->toBe([
            ['driver', 'driver-uuid', 'zone-uuid'],
            ['driver', 'driver-uuid', 'zone-uuid'],
        ])
        ->and($command->messages)->toContain(
            ['line', 'Run 1/2'],
            ['line', 'Run 2/2'],
            ['info', '  -> dispatched geofence.entered'],
            ['info', '  -> dispatched geofence.dwelled (15 min)'],
            ['info', '  -> dispatched geofence.exited (42 min)'],
            ['info', 'Geofence simulation complete.'],
        );

    Carbon::setTestNow();
});
