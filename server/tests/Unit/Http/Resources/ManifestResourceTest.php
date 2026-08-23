<?php

/*
 * The models resolve their connection through a namespaced `config()`, which
 * does not exist without the full application. Stubbed so a resource can be
 * exercised on its own — the projection is the thing under test, not Eloquent.
 */
if (!function_exists('Fleetbase\Traits\config')) {
    eval('namespace Fleetbase\Traits; function config($key = null, $default = null) { return $key === "api.cache.enabled" ? false : $default; }');
}

if (!function_exists('Fleetbase\Models\config')) {
    eval('namespace Fleetbase\Models; function config($key = null, $default = null) { return $default; }');
}

if (!function_exists('Fleetbase\FleetOps\Models\config')) {
    eval('namespace Fleetbase\FleetOps\Models; function config($key = null, $default = null) { return $default; }');
}

use Fleetbase\FleetOps\Http\Resources\v1\Manifest as ManifestResource;
use Fleetbase\FleetOps\Http\Resources\v1\ManifestStop as ManifestStopResource;
use Fleetbase\FleetOps\Models\Manifest;
use Fleetbase\FleetOps\Models\ManifestStop;
use Illuminate\Http\Request;

if (!class_exists('FleetOpsSupportRequestState')) {
    class FleetOpsSupportRequestState
    {
        public static ?Request $request = null;
    }
}

if (!function_exists('Fleetbase\Support\request')) {
    eval('namespace Fleetbase\Support; function request($key = null, $default = null) { return \FleetOpsSupportRequestState::$request; }');
}

/**
 * The manifest's stop counts are accessors that query the database, and the
 * driver and vehicle names walk relations. None of that is what a resource test
 * is for, so they are answered directly — the projection is under test, not
 * Eloquent.
 */
class FleetOpsManifestFake extends Manifest
{
    public function getCompletedStopsAttribute(): int
    {
        return 3;
    }

    public function getPendingStopsAttribute(): int
    {
        return 5;
    }

    public function getDriverNameAttribute(): ?string
    {
        return 'Ron';
    }

    public function getVehicleNameAttribute(): ?string
    {
        return 'EAS-01';
    }
}

test('manifest resource publishes what a driver needs to run a route', function () {
    $request                             = Request::create('/v1/manifests/manifest_public', 'GET');
    FleetOpsSupportRequestState::$request = $request;

    $manifest = new FleetOpsManifestFake();
    $manifest->setRawAttributes([
        'id'               => 3,
        'uuid'             => 'manifest-uuid',
        'public_id'        => 'manifest_public',
        'status'           => 'in_progress',
        'total_distance_m' => 41200,
        'total_duration_s' => 5400,
        'stop_count'       => 8,
        'notes'            => 'Cold chain first',
    ], true);

    $payload = (new ManifestResource($manifest))->resolve($request);

    expect($payload['id'])->toBe('manifest_public')
        ->and($payload['status'])->toBe('in_progress')
        ->and($payload['total_distance_m'])->toBe(41200)
        ->and($payload['stop_count'])->toBe(8)
        ->and($payload['notes'])->toBe('Cold chain first')
        ->and($payload['completed_stops'])->toBe(3)
        ->and($payload['pending_stops'])->toBe(5)
        ->and($payload['driver_name'])->toBe('Ron');
});

test('manifest resource omits stops when they were not loaded, so a list stays a list', function () {
    // A driver's manifest list on a busy fleet must not drag every stop of
    // every route along with it.
    $request                             = Request::create('/v1/drivers/driver_public/manifests', 'GET');
    FleetOpsSupportRequestState::$request = $request;

    $manifest = new FleetOpsManifestFake();
    $manifest->setRawAttributes(['id' => 4, 'uuid' => 'm-uuid', 'public_id' => 'manifest_public', 'status' => 'pending'], true);

    $payload = (new ManifestResource($manifest))->resolve($request);

    expect($payload)->not->toHaveKey('stops');
});

test('manifest stop resource carries the sequence a re-sequence rewrites', function () {
    $request                             = Request::create('/v1/manifest-stops/stop_public', 'GET');
    FleetOpsSupportRequestState::$request = $request;

    $stop = new ManifestStop();
    $stop->setRawAttributes([
        'id'                   => 9,
        'uuid'                 => 'stop-uuid',
        'public_id'            => 'stop_public',
        'status'               => 'pending',
        'sequence'             => 4,
        'distance_from_prev_m' => 1800,
        'duration_from_prev_s' => 240,
    ], true);

    $payload = (new ManifestStopResource($stop))->resolve($request);

    expect($payload['id'])->toBe('stop_public')
        ->and($payload['sequence'])->toBe(4)
        ->and($payload['status'])->toBe('pending')
        ->and($payload['distance_from_prev_m'])->toBe(1800);
});
