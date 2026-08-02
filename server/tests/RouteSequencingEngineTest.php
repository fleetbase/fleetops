<?php

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Orchestration\Engines\RouteSequencingEngine;
use Illuminate\Support\Collection;

function routeSequencingLocation(float $lat, float $lng): object
{
    return new class($lat, $lng) {
        public function __construct(private float $lat, private float $lng)
        {
        }

        public function getLat(): float
        {
            return $this->lat;
        }

        public function getLng(): float
        {
            return $this->lng;
        }
    };
}

function routeSequencingPlace(float|string|null $lat, float|string|null $lng): object
{
    return (object) [
        'lat' => $lat,
        'lng' => $lng,
    ];
}

function routeSequencingPayload($pickup = null, $dropoff = null, array $waypoints = [], bool $multiDrop = false): object
{
    return new class($pickup, $dropoff, $waypoints, $multiDrop) {
        public ?string $pickup_uuid;
        public ?string $dropoff_uuid;
        public Collection $waypoints;

        public function __construct(public $pickup, public $dropoff, array $waypoints, bool $multiDrop)
        {
            $this->pickup_uuid  = $multiDrop ? null : 'pickup-uuid';
            $this->dropoff_uuid = $multiDrop ? null : 'dropoff-uuid';
            $this->waypoints    = collect($waypoints);
        }
    };
}

function routeSequencingWaypoint(int $order, object $place): object
{
    return (object) [
        'order' => $order,
        'place' => $place,
    ];
}

function routeSequencingOrder(string $publicId, ?string $vehicleUuid = null, $payload = null, $vehicle = null): Order
{
    $order                        = new Order();
    $order->public_id             = $publicId;
    $order->vehicle_assigned_uuid = $vehicleUuid;

    if ($payload) {
        $order->setRelation('payload', $payload);
    }

    if ($vehicle) {
        $order->setRelation('vehicle', $vehicle);
    }

    return $order;
}

function routeSequencingInvoke(RouteSequencingEngine $engine, string $method, array $arguments = [])
{
    $reflection = new ReflectionMethod($engine, $method);
    $reflection->setAccessible(true);

    return $reflection->invokeArgs($engine, $arguments);
}

test('route sequencing preserves vehicle assignment and orders stops by nearest eligible location', function () {
    $engine = new RouteSequencingEngine();
    $driver = (object) [
        'public_id' => 'driver_public',
        'location'  => routeSequencingLocation(1.00, 103.00),
    ];
    $vehicle = (object) [
        'public_id' => 'vehicle_public',
        'driver'    => $driver,
        'location'  => routeSequencingLocation(1.50, 103.50),
    ];

    $nearOrder = routeSequencingOrder(
        'order_near',
        'vehicle-uuid',
        routeSequencingPayload(
            routeSequencingPlace(1.01, 103.01),
            routeSequencingPlace(1.02, 103.02)
        ),
        $vehicle
    );
    $farOrder = routeSequencingOrder(
        'order_far',
        'vehicle-uuid',
        routeSequencingPayload(
            routeSequencingPlace(2.00, 104.00),
            routeSequencingPlace(2.10, 104.10)
        ),
        $vehicle
    );
    $unassigned = routeSequencingOrder('order_unassigned');

    $result = $engine->sequence(collect([$farOrder, $unassigned, $nearOrder]));

    expect($result['unassigned'])->toBe(['order_unassigned'])
        ->and($result['summary'])->toMatchArray([
            'engine'     => 'route_sequencing',
            'assigned'   => 2,
            'unassigned' => 1,
        ]);

    $assignments = collect($result['assignments'])->keyBy('order_id');
    expect($assignments['order_near'])->toMatchArray([
        'vehicle_id' => 'vehicle_public',
        'driver_id'  => 'driver_public',
        'sequence'   => 1,
    ]);
    expect($assignments['order_far']['sequence'])->toBeGreaterThan($assignments['order_near']['sequence']);
});

test('route sequencing handles multi drop waypoints and missing vehicle relations', function () {
    $engine = new RouteSequencingEngine();
    $order  = routeSequencingOrder(
        'order_multi',
        'vehicle-missing-relation',
        routeSequencingPayload(null, null, [
            routeSequencingWaypoint(2, routeSequencingPlace(1.20, 103.20)),
            routeSequencingWaypoint(1, routeSequencingPlace(1.10, 103.10)),
        ], true)
    );
    $order->setRelation('vehicle', null);

    $result = $engine->sequence(collect([$order]));

    expect($result['assignments'])->toHaveCount(1)
        ->and($result['assignments'][0])->toMatchArray([
            'order_id'   => 'order_multi',
            'vehicle_id' => 'vehicle-missing-relation',
            'driver_id'  => null,
            'sequence'   => 1,
        ])
        ->and($result['summary']['assigned'])->toBe(1);
});

test('route sequencing protected helpers cover precedence fallback and distances', function () {
    $engine = new RouteSequencingEngine();
    $order  = routeSequencingOrder(
        'order_dropoff_only',
        'vehicle-uuid',
        routeSequencingPayload(
            routeSequencingPlace(null, null),
            routeSequencingPlace(1.25, 103.25)
        )
    );

    $sequence = routeSequencingInvoke($engine, '_sequenceOrdersForVehicle', [[$order], null, null]);

    expect($sequence)->toHaveCount(1)
        ->and($sequence[0])->toMatchArray([
            'order_public_id' => 'order_dropoff_only',
            'type'            => 'dropoff',
        ]);

    $distance = routeSequencingInvoke($engine, '_haversine', [1.00, 103.00, 1.01, 103.01]);
    expect($distance)->toBeGreaterThan(0.0)
        ->and(routeSequencingInvoke($engine, '_sequenceOrdersForVehicle', [[], null, null]))->toBe([]);
});
