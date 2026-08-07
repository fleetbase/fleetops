<?php

use Fleetbase\FleetOps\Integrations\Lalamove\LalamoveDeliveryStop;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\LaravelMysqlSpatial\Eloquent\SpatialExpression;
use Fleetbase\LaravelMysqlSpatial\Types\Point;

function fleetopsLalamoveDeliveryStopDisableRedisCache(): void
{
    app()->instance('redis', new class {
        public function connection(): self
        {
            return $this;
        }

        public function __call(string $method, array $arguments): mixed
        {
            return null;
        }
    });
}

function fleetopsLalamoveDeliveryStopPlace(Point|SpatialExpression $location, string $address = '12 Depot Road'): Place
{
    $place = new Place();
    $place->setRawAttributes([
        'uuid'     => 'place-uuid',
        'location' => $location,
        'street1'  => $address,
        'city'     => 'Singapore',
        'country'  => 'SG',
    ], true);

    return $place;
}

test('lalamove delivery stop can be created from points and raw coordinates', function () {
    $pointStop = LalamoveDeliveryStop::createFromPoint(new Point(1.29027, 103.851959), 'Marina Depot');
    $rawStop   = new LalamoveDeliveryStop(1.3521, 103.8198, 'Central Depot');

    expect($pointStop->latitude)->toBe(1.29027)
        ->and($pointStop->longitude)->toBe(103.851959)
        ->and($pointStop->address)->toBe('Marina Depot')
        ->and($pointStop->stopId)->toBeString()
        ->and($pointStop->missing)->toBeNull()
        ->and($pointStop->toArray())->toBe([
            'coordinates' => [
                'lat' => '1.29027',
                'lng' => '103.851959',
            ],
            'address' => 'Marina Depot',
        ])
        ->and(json_decode($pointStop->toJson(), true))->toBe($pointStop->toArray())
        ->and($pointStop->toPoint())->toBeInstanceOf(Point::class)
        ->and($rawStop->toArray())->toMatchArray([
            'coordinates' => [
                'lat' => '1.3521',
                'lng' => '103.8198',
            ],
            'address' => 'Central Depot',
        ]);
});

test('lalamove delivery stop resolves place point and spatial expression locations', function () {
    fleetopsLalamoveDeliveryStopDisableRedisCache();

    $pointPlace   = fleetopsLalamoveDeliveryStopPlace(new Point(1.3001, 103.8002), 'Point Place');
    $spatialPlace = fleetopsLalamoveDeliveryStopPlace(new SpatialExpression(new Point(1.4001, 103.9002)), 'Spatial Place');

    $pointStop   = LalamoveDeliveryStop::createFromPlace($pointPlace);
    $spatialStop = new LalamoveDeliveryStop($spatialPlace);

    expect($pointStop->latitude)->toBe(1.3001)
        ->and($pointStop->longitude)->toBe(103.8002)
        ->and($pointStop->address)->toBe('POINT PLACE - SINGAPORE')
        ->and($spatialStop->latitude)->toBe(1.4001)
        ->and($spatialStop->longitude)->toBe(103.9002)
        ->and($spatialStop->address)->toBe('SPATIAL PLACE - SINGAPORE');
});
