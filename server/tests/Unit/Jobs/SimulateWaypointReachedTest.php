<?php

if (!function_exists('app')) {
    function app($abstract = null)
    {
        return $abstract ? Illuminate\Container\Container::getInstance()->make($abstract) : Illuminate\Container\Container::getInstance();
    }
}

if (!function_exists('Fleetbase\Traits\config')) {
    eval('namespace Fleetbase\Traits; function config($key = null, $default = null) { return $key === "api.cache.enabled" ? false : $default; }');
}

if (!trait_exists('Illuminate\Foundation\Bus\Dispatchable')) {
    eval('namespace Illuminate\Foundation\Bus; trait Dispatchable {}');
}

if (!function_exists('Fleetbase\FleetOps\Jobs\event')) {
    eval('namespace Fleetbase\FleetOps\Jobs; function event($event = null, $payload = [], $halt = false) { $GLOBALS["fleetopsUnitSimulateWaypointReachedEvents"][] = $event; return [$event]; }');
}

if (!class_exists('Illuminate\Foundation\Auth\User')) {
    class_alias(Illuminate\Database\Eloquent\Model::class, 'Illuminate\Foundation\Auth\User');
}

use Fleetbase\FleetOps\Events\DriverSimulatedLocationChanged;
use Fleetbase\FleetOps\Jobs\SimulateWaypointReached;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\LaravelMysqlSpatial\Types\Point;

class FleetOpsUnitWaypointPointWithHeading extends Point
{
    public int $heading = 270;
}

function fleetopsUnitSimulatedDriver(): Driver
{
    $driver = new Driver();
    $driver->setRawAttributes([
        'uuid'        => 'driver-uuid',
        'public_id'   => 'driver-public',
        'internal_id' => 'driver-internal',
        'altitude'    => 44,
        'heading'     => 90,
        'speed'       => 35,
    ], true);
    $driver->setRelation('user', (object) [
        'name'  => 'Test Driver',
        'phone' => '+15555550000',
    ]);

    return $driver;
}

function fleetopsUnitCaptureWaypointEvents(): void
{
    $GLOBALS['fleetopsUnitSimulateWaypointReachedEvents'] = [];
}

test('simulate waypoint reached dispatches simulated location event with waypoint heading', function () {
    fleetopsUnitCaptureWaypointEvents();

    $waypoint = new FleetOpsUnitWaypointPointWithHeading(1.30, 103.80);
    $job      = new SimulateWaypointReached(fleetopsUnitSimulatedDriver(), $waypoint, ['speed' => 42]);

    $job->handle();

    $event = $GLOBALS['fleetopsUnitSimulateWaypointReachedEvents'][0] ?? null;

    expect($event)->toBeInstanceOf(DriverSimulatedLocationChanged::class)
        ->and($event->location)->toBe($waypoint)
        ->and($event->heading)->toBe(270)
        ->and($event->speed)->toBe(42)
        ->and($event->additionalData)->toBe(['speed' => 42, 'heading' => 270]);
});

test('simulate waypoint reached keeps provided data when waypoint has no heading', function () {
    fleetopsUnitCaptureWaypointEvents();

    $waypoint = new Point(1.31, 103.81);
    $job      = new SimulateWaypointReached(fleetopsUnitSimulatedDriver(), $waypoint, ['speed' => 24]);

    $job->handle();

    $event = $GLOBALS['fleetopsUnitSimulateWaypointReachedEvents'][0] ?? null;

    expect($event)->toBeInstanceOf(DriverSimulatedLocationChanged::class)
        ->and($event->location)->toBe($waypoint)
        ->and($event->heading)->toBe(90)
        ->and($event->speed)->toBe(24)
        ->and($event->additionalData)->toBe(['speed' => 24]);
});
