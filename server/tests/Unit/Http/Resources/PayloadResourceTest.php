<?php

use Fleetbase\FleetOps\Http\Resources\v1\Payload as PayloadResource;
use Fleetbase\FleetOps\Models\Payload;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Models\Waypoint;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Http\Request;

/**
 * Covers the Payload API resource: full array serialization with and without
 * route ETAs (the tracker is registered as an Expandable expansion), the
 * private place/waypoint builders, and the webhook payload projection.
 */
function fleetopsPayloadResourceBoot(): void
{
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
    $resolver   = new ConnectionResolver(['default' => $connection, 'mysql' => $connection]);
    $resolver->setDefaultConnection('mysql');
    EloquentModel::setConnectionResolver($resolver);

    Payload::expand('tracker', function () {
        return new class {
            public function getWaypointETA($waypoint): int
            {
                return 420;
            }
        };
    });
}

function fleetopsPayloadResourcePayload(): Payload
{
    $payload = new Payload();
    $payload->setRawAttributes([
        'uuid'         => 'payload-1',
        'public_id'    => 'payload_test',
        'cod_amount'   => '25.00',
        'cod_currency' => 'SGD',
    ], true);
    $payload->exists = true;

    $pickup = new Place();
    $pickup->setRawAttributes(['uuid' => 'place-1', 'public_id' => 'place_pickup', 'name' => 'Pickup'], true);
    $dropoff = new Place();
    $dropoff->setRawAttributes(['uuid' => 'place-2', 'public_id' => 'place_dropoff', 'name' => 'Dropoff'], true);
    $waypoint = new Waypoint();
    $waypoint->setRawAttributes(['uuid' => 'wp-1', 'public_id' => 'waypoint_1', 'place_uuid' => 'place-1'], true);

    $payload->setRelation('pickup', $pickup);
    $payload->setRelation('dropoff', $dropoff);
    $payload->setRelation('return', null);
    $payload->setRelation('waypoints', collect([$waypoint]));
    $payload->setRelation('entities', collect([]));

    return $payload;
}

test('payload resource serializes with route etas from the tracker expansion', function () {
    fleetopsPayloadResourceBoot();

    $resource = new PayloadResource(fleetopsPayloadResourcePayload());
    $payload  = $resource->toArray(new Request(['with_route_eta' => 1]));

    expect($payload['cod_amount'])->toBe('25.00')
        ->and($payload['cod_currency'])->toBe('SGD')
        ->and(count($payload['waypoints']))->toBe(1)
        ->and($payload['waypoints'][0]->eta)->toBe(420)
        // toArray only routes the ETA flag into waypoints; pickup/dropoff
        // are serialized without ETAs.
        ->and($payload['pickup']->eta)->toBeNull();
});

test('payload place and waypoint builders skip etas when not requested', function () {
    fleetopsPayloadResourceBoot();

    $model    = fleetopsPayloadResourcePayload();
    $resource = new PayloadResource($model);

    $getPlace = new ReflectionMethod(PayloadResource::class, 'getPlace');
    $getPlace->setAccessible(true);
    $place = $getPlace->invoke($resource, $model->pickup, false);
    expect($place)->toBeInstanceOf(Fleetbase\FleetOps\Http\Resources\v1\Place::class)
        ->and($model->pickup->eta)->toBeNull();

    // Requesting the ETA resolves it through the tracker expansion
    $getPlace->invoke($resource, $model->pickup, true);
    expect($model->pickup->eta)->toBe(420);

    $getWaypoints = new ReflectionMethod(PayloadResource::class, 'getWaypoints');
    $getWaypoints->setAccessible(true);
    $waypoints = $getWaypoints->invoke($resource, false);
    expect($waypoints)->toHaveCount(1)
        ->and($waypoints->first()->payload_uuid)->toBe('payload-1');

    // Non-collection waypoints fall back to an empty collection
    $model->setRelation('waypoints', null);
    expect($getWaypoints->invoke($resource, false))->toHaveCount(0);
});

test('payload webhook projection exposes public ids and child places', function () {
    fleetopsPayloadResourceBoot();

    $hook = (new PayloadResource(fleetopsPayloadResourcePayload()))->toWebhookPayload();

    expect($hook['id'])->toBe('payload_test')
        ->and($hook)->toHaveKeys(['pickup', 'dropoff', 'return', 'waypoints', 'entities', 'cod_amount', 'meta'])
        ->and($hook['cod_amount'])->toBe('25.00');
});
