<?php

use Fleetbase\FleetOps\Models\Maintenance;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\FleetOps\Models\Warranty;

/**
 * Covers the guard clauses that several helpers use to bail out on missing or
 * unusable input. Each is invoked with exactly the input that trips its guard,
 * paired where practical with the input that gets past it, so the assertions
 * pin the boundary rather than just reaching the line.
 */
function fleetopsGuardInvoke(object $target, string $method, ...$arguments)
{
    $reflection = new ReflectionMethod($target, $method);
    $reflection->setAccessible(true);

    return $reflection->invoke($target, ...$arguments);
}

test('metrics report no currency unless a subclass declares one', function () {
    // The base implementation is the fallback for every non-monetary metric
    $metric = (new ReflectionClass(Fleetbase\FleetOps\Support\Metrics\OrdersCompletedMetric::class))->newInstanceWithoutConstructor();

    expect($metric->currency())->toBeNull();
});

test('telematics providers reject empty timestamps and sensor names', function () {
    $flespi = (new ReflectionClass(Fleetbase\FleetOps\Support\Telematics\Providers\FlespiProvider::class))->newInstanceWithoutConstructor();

    // Falsy timestamps bail out before Carbon is asked to parse them
    expect(fleetopsGuardInvoke($flespi, 'parseTimestamp', null))->toBeNull()
        ->and(fleetopsGuardInvoke($flespi, 'parseTimestamp', ''))->toBeNull()
        ->and(fleetopsGuardInvoke($flespi, 'parseTimestamp', 0))->toBeNull();

    // Numeric and string forms both resolve once past the guard
    expect(fleetopsGuardInvoke($flespi, 'parseTimestamp', 1750000000))->toBeString()
        ->and(fleetopsGuardInvoke($flespi, 'parseTimestamp', '2026-07-29 08:30:00'))->toBe('2026-07-29 08:30:00');

    $afaqy = (new ReflectionClass(Fleetbase\FleetOps\Support\Telematics\Providers\AfaqyProvider::class))->newInstanceWithoutConstructor();

    // With no name anywhere in the payload and no fallback there is nothing to return
    expect(fleetopsGuardInvoke($afaqy, 'resolveSensorName', []))->toBeNull()
        ->and(fleetopsGuardInvoke($afaqy, 'resolveSensorName', ['name' => '']))->toBeNull()
        ->and(fleetopsGuardInvoke($afaqy, 'resolveSensorName', [], 'Fallback Sensor'))->toBe('Fallback Sensor')
        ->and(fleetopsGuardInvoke($afaqy, 'resolveSensorName', ['param' => 'temp_1']))->toBe('temp_1');
});

test('maintenance efficiency needs both actual and estimated durations', function () {
    // Datetime casts read their format off the connection grammar, so the model
    // needs a resolver even though nothing is queried here
    $connection = new Illuminate\Database\SQLiteConnection(new PDO('sqlite::memory:'));
    $resolver   = new Illuminate\Database\ConnectionResolver(['default' => $connection, 'mysql' => $connection]);
    $resolver->setDefaultConnection('mysql');
    Illuminate\Database\Eloquent\Model::setConnectionResolver($resolver);

    $maintenance = new Maintenance();
    // duration_hours is derived from started_at/completed_at rather than stored
    $maintenance->setRawAttributes([
        'uuid'         => 'maintenance-guard-1',
        'scheduled_at' => '2026-07-20 08:00:00',
        'started_at'   => '2026-07-20 08:00:00',
        'completed_at' => '2026-07-20 12:00:00',
        'meta'         => json_encode([]),
    ], true);

    // Assert the actual-duration guard is satisfied first, so a null rating can
    // only come from the missing estimate rather than from the earlier guard
    expect($maintenance->duration_hours)->not->toBeNull()
        ->and($maintenance->scheduled_at)->not->toBeNull()
        ->and($maintenance->completed_at)->not->toBeNull()
        ->and($maintenance->getEfficiencyRating())->toBeNull();
});

test('non transferable warranties refuse to move to a new subject', function () {
    $warranty = new Warranty();
    $warranty->setRawAttributes([
        'uuid'         => 'warranty-guard-1',
        'subject_type' => Vehicle::class,
        'subject_uuid' => 'vehicle-guard-1',
        'terms'        => json_encode(['transferable' => false]),
    ], true);

    $newSubject = new Vehicle();
    $newSubject->setRawAttributes(['uuid' => 'vehicle-guard-2'], true);

    // The transfer is refused outright, leaving the original subject in place
    expect($warranty->transferTo($newSubject))->toBeFalse()
        ->and($warranty->subject_uuid)->toBe('vehicle-guard-1');
});
