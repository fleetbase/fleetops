<?php

use Fleetbase\FleetOps\Models\Entity;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Payload;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\FleetOps\Orchestration\Support\OrchestrationPayloadBuilder;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;

/**
 * Covers the OrchestrationPayloadBuilder computation helpers: payload
 * demand aggregation with weight and dimension unit conversion plus the
 * meta fallback, vehicle capacity arrays, vehicle-only VROOM entries with
 * location/capacity/max-task/skill handling, safe meta access, and
 * coordinate validation.
 */
function fleetopsPayloadBuilderBoot(): void
{
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
    $resolver   = new ConnectionResolver(['default' => $connection, 'mysql' => $connection]);
    $resolver->setDefaultConnection('mysql');
    EloquentModel::setConnectionResolver($resolver);
}

function fleetopsPayloadBuilderEntity(array $attributes): Entity
{
    $entity = new Entity();
    $entity->setRawAttributes($attributes, true);

    return $entity;
}

function fleetopsPayloadBuilderInvoke(string $method, ...$arguments): mixed
{
    $reflection = new ReflectionMethod(OrchestrationPayloadBuilder::class, $method);
    $reflection->setAccessible(true);

    return $reflection->invoke(null, ...$arguments);
}

test('payload demand aggregates entity weights and volumes with unit conversion', function () {
    fleetopsPayloadBuilderBoot();

    $payload = new Payload();
    $payload->setRawAttributes(['uuid' => 'payload-1'], true);
    $payload->setRelation('entities', collect([
        fleetopsPayloadBuilderEntity(['uuid' => 'ent-1', 'weight' => '2000', 'weight_unit' => 'g', 'length' => '100', 'width' => '50', 'height' => '20', 'dimensions_unit' => 'cm']),
        fleetopsPayloadBuilderEntity(['uuid' => 'ent-2', 'weight' => '10', 'weight_unit' => 'lb']),
    ]));

    $order = new Order();
    $order->setRawAttributes(['uuid' => 'order-1'], true);
    $order->setRelation('payload', $payload);

    [$weightKg, $volumeLitres, $pallets, $parcels] = fleetopsPayloadBuilderInvoke('computePayloadDemand', $order);

    // 2000g -> 2kg plus 10lb -> ~4.54kg rounds to 7kg
    expect($weightKg)->toBe(7)
        // 1m x 0.5m x 0.2m = 0.1m3 -> 100 litres
        ->and($volumeLitres)->toBe(100)
        ->and($pallets)->toBe(0)
        ->and($parcels)->toBe(2);
});

test('payload demand falls back to order meta without entities', function () {
    fleetopsPayloadBuilderBoot();

    $order = new Order();
    $order->setRawAttributes(['uuid' => 'order-1', 'meta' => json_encode(['weight_kg' => 12, 'volume_m3' => 0.5, 'pallets' => 2, 'parcels' => 3])], true);
    $order->setRelation('payload', null);

    [$weightKg, $volumeLitres, $pallets, $parcels] = fleetopsPayloadBuilderInvoke('computePayloadDemand', $order);

    expect($weightKg)->toBe(12)
        ->and($volumeLitres)->toBe(500)
        ->and($pallets)->toBe(2)
        ->and($parcels)->toBe(3);
});

test('vehicles only entries include location capacity and optional fields', function () {
    fleetopsPayloadBuilderBoot();

    $located = new Vehicle();
    $located->setRawAttributes([
        'uuid'             => 'vehicle-1',
        'public_id'        => 'vehicle_vroom',
        'payload_capacity' => '1200',
        'max_tasks'        => '5',
    ], true);
    $located->setAttribute('location', new Point(1.3, 103.8));

    $unlocated = new Vehicle();
    $unlocated->setRawAttributes(['uuid' => 'vehicle-2', 'public_id' => 'vehicle_nowhere'], true);

    $entries = OrchestrationPayloadBuilder::buildVehiclesOnly(collect([$located, $unlocated]));

    expect($entries)->toHaveCount(1)
        ->and($entries[0]['id'])->toBe('vehicle_vroom')
        ->and($entries[0]['driver_id'])->toBeNull()
        ->and($entries[0]['start'])->toBe([103.8, 1.3])
        ->and($entries[0]['max_tasks'])->toBe(5)
        ->and($entries[0]['capacity'])->toBeArray();
});

test('safe meta swallows accessor failures and validates coordinates', function () {
    fleetopsPayloadBuilderBoot();

    $order = new Order();
    $order->setRawAttributes(['uuid' => 'order-1', 'meta' => json_encode(['priority' => 'high'])], true);

    expect(fleetopsPayloadBuilderInvoke('safeMeta', $order, 'priority'))->toBe('high')
        ->and(fleetopsPayloadBuilderInvoke('safeMeta', $order, 'missing', 'fallback'))->toBe('fallback');

    // Objects without getMeta throw and fall back safely
    $broken = new stdClass();
    expect(fleetopsPayloadBuilderInvoke('safeMeta', $broken, 'anything', 'default'))->toBe('default');

    expect(fleetopsPayloadBuilderInvoke('isValidCoordinate', 103.8, 1.3))->toBeTrue()
        ->and(fleetopsPayloadBuilderInvoke('isValidCoordinate', 'not-a-number', 1.3))->toBeFalse()
        ->and(fleetopsPayloadBuilderInvoke('isValidCoordinate', 999, 1.3))->toBeFalse();
});

test('coordinates resolve from located places and reject unlocated ones', function () {
    fleetopsPayloadBuilderBoot();

    $place = new Fleetbase\FleetOps\Models\Place();
    $place->setRawAttributes(['uuid' => 'place-1'], true);
    $place->setAttribute('location', new Point(1.3, 103.8));

    expect(fleetopsPayloadBuilderInvoke('coordinatesFromPlace', $place))->toBe([103.8, 1.3])
        ->and(fleetopsPayloadBuilderInvoke('coordinatesFromPlace', null))->toBeNull();
});
