<?php

use Fleetbase\FleetOps\Http\Resources\v1\ServiceArea as ServiceAreaResource;
use Fleetbase\FleetOps\Http\Resources\v1\Zone as ZoneResource;
use Fleetbase\FleetOps\Models\ServiceArea;
use Fleetbase\FleetOps\Models\Zone;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;

class FleetOpsGeofenceSettingsZoneFake extends Zone
{
    public function getLocationAttribute(): Point
    {
        return new Point(1.3, 103.8);
    }

    public function withCustomFields(array $attributes = [], int|string $position = 'middle'): array
    {
        return $attributes;
    }
}

class FleetOpsGeofenceSettingsServiceAreaFake extends ServiceArea
{
    public function getLocationAttribute(): Point
    {
        return new Point(1.3, 103.8);
    }

    public function withCustomFields(array $attributes = [], int|string $position = 'middle'): array
    {
        return $attributes;
    }
}

function fleetopsGeofenceSettingsRequest(bool $internal): Request
{
    $uri     = $internal ? 'int/v1/zones/zone_public' : 'v1/zones/zone_public';
    $request = Request::create('/' . $uri, 'GET');
    $route   = new Route('GET', $internal ? 'int/v1/zones/{id}' : 'v1/zones/{id}', []);

    $request->setRouteResolver(fn () => $route);
    app()->instance('request', $request);

    return $request;
}

/**
 * Raw attributes shaped the way MySQL hands them back: tinyint/smallint
 * columns arrive as strings, so the resource must rely on the model casts.
 */
function fleetopsGeofenceSettingsRawAttributes(): array
{
    return [
        'uuid'                    => 'zone-uuid',
        'public_id'               => 'zone_public',
        'service_area_uuid'       => 'area-uuid',
        'name'                    => 'Loading Dock',
        'status'                  => 'active',
        'trigger_on_entry'        => '0',
        'trigger_on_exit'         => '1',
        'dwell_threshold_minutes' => '15',
        'speed_limit_kmh'         => '25',
    ];
}

test('zone resource returns geofence settings typed on public and internal requests', function (bool $internal) {
    $zone = new FleetOpsGeofenceSettingsZoneFake();
    $zone->setRawAttributes(fleetopsGeofenceSettingsRawAttributes(), true);

    $payload = (new ZoneResource($zone))->resolve(fleetopsGeofenceSettingsRequest($internal));

    expect($payload['id'])->toBe($internal ? null : 'zone_public')
        ->and($payload['trigger_on_entry'])->toBeFalse()
        ->and($payload['trigger_on_exit'])->toBeTrue()
        ->and($payload['dwell_threshold_minutes'])->toBe(15)
        ->and($payload['speed_limit_kmh'])->toBe(25);
})->with(['public' => false, 'internal' => true]);

test('zone webhook payload includes typed geofence settings', function () {
    $zone = new FleetOpsGeofenceSettingsZoneFake();
    $zone->setRawAttributes(array_merge(fleetopsGeofenceSettingsRawAttributes(), ['dwell_threshold_minutes' => null]), true);

    expect((new ZoneResource($zone))->toWebhookPayload())->toMatchArray([
        'id'                      => 'zone_public',
        'trigger_on_entry'        => false,
        'trigger_on_exit'         => true,
        'dwell_threshold_minutes' => null,
        'speed_limit_kmh'         => 25,
    ]);
});

test('zone and service area updates fill geofence settings and round-trip through the resource', function (string $modelClass, string $resourceClass) {
    // Request-shaped input, as the update endpoints pass it to fill()/update().
    $input = [
        'trigger_on_entry'        => '1',
        'trigger_on_exit'         => false,
        'dwell_threshold_minutes' => '30',
        'speed_limit_kmh'         => 60,
    ];

    $model = new $modelClass();
    $model->setRawAttributes(['public_id' => 'record_public', 'name' => 'Before'], true);
    $model->fill($input);

    expect($model->isDirty(['trigger_on_entry', 'trigger_on_exit', 'dwell_threshold_minutes', 'speed_limit_kmh']))->toBeTrue();

    $payload = (new $resourceClass($model))->resolve(fleetopsGeofenceSettingsRequest(false));

    expect($payload)->toMatchArray([
        'id'                      => 'record_public',
        'trigger_on_entry'        => true,
        'trigger_on_exit'         => false,
        'dwell_threshold_minutes' => 30,
        'speed_limit_kmh'         => 60,
    ]);
})->with([
    'zone'         => [FleetOpsGeofenceSettingsZoneFake::class, ZoneResource::class],
    'service area' => [FleetOpsGeofenceSettingsServiceAreaFake::class, ServiceAreaResource::class],
]);
