<?php

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Orchestration\Support\OrchestrationPayloadBuilder;
use Illuminate\Support\Collection;

function orchestrationTestPlace($lng, $lat)
{
    return new class($lng, $lat) {
        public $location;

        public function __construct($lng, $lat)
        {
            $this->location = new class($lng, $lat) {
                public function __construct(private $lng, private $lat)
                {
                }

                public function getLng()
                {
                    return $this->lng;
                }

                public function getLat()
                {
                    return $this->lat;
                }
            };
        }
    };
}

function orchestrationTestOrder($pickup = null, $dropoff = null, array $waypoints = []): Order
{
    $payload = new class($pickup, $dropoff, $waypoints) {
        public Collection $entities;
        public Collection $waypointMarkers;

        public function __construct(public $pickup, public $dropoff, array $waypoints)
        {
            $this->entities         = collect();
            $this->waypointMarkers = collect($waypoints)->map(function ($place, $index) {
                return new class($place, $index) {
                    public string $public_id;
                    public string $uuid;
                    public int $order;

                    public function __construct(public $place, int $index)
                    {
                        $this->public_id = 'waypoint_' . $index;
                        $this->uuid      = 'internal-waypoint-' . $index;
                        $this->order     = $index;
                    }
                };
            });
        }

        public function relationLoaded(string $relation): bool
        {
            return $relation === 'waypointMarkers';
        }
    };

    $order = new Order();
    $order->public_id = 'order_test';
    $order->setRelation('payload', $payload);

    return $order;
}

test('route stops use pickup waypoint dropoff order for mixed payloads', function () {
    $order = orchestrationTestOrder(
        orchestrationTestPlace(103.85, 1.28),
        orchestrationTestPlace(103.90, 1.31),
        [
            orchestrationTestPlace(103.86, 1.29),
            orchestrationTestPlace(103.87, 1.30),
        ]
    );

    $stops = OrchestrationPayloadBuilder::buildRouteStops($order);

    expect(array_column($stops, 'role'))->toBe(['pickup', 'waypoint', 'waypoint', 'dropoff']);
    expect(array_column($stops, 'location'))->toBe([
        [103.85, 1.28],
        [103.86, 1.29],
        [103.87, 1.30],
        [103.90, 1.31],
    ]);
    expect($stops[1])->not->toHaveKey('waypoint_uuid');
});

test('route stops support endpoint only and waypoint only payloads', function () {
    $endpointOnly = OrchestrationPayloadBuilder::buildRouteStops(orchestrationTestOrder(
        orchestrationTestPlace(103.85, 1.28),
        orchestrationTestPlace(103.90, 1.31)
    ));
    $waypointOnly = OrchestrationPayloadBuilder::buildRouteStops(orchestrationTestOrder(
        null,
        null,
        [orchestrationTestPlace(103.86, 1.29)]
    ));

    expect(array_column($endpointOnly, 'role'))->toBe(['pickup', 'dropoff']);
    expect(array_column($waypointOnly, 'role'))->toBe(['waypoint']);
});

test('route stops drop invalid coordinates before vroom payload mapping', function () {
    $stops = OrchestrationPayloadBuilder::buildRouteStops(orchestrationTestOrder(
        orchestrationTestPlace('and', 1.28),
        orchestrationTestPlace(103.90, 1.31)
    ));

    expect(array_column($stops, 'role'))->toBe(['dropoff']);
    expect($stops[0]['location'])->toBe([103.90, 1.31]);
});

test('route tasks mark declared invalid-coordinate stops unassigned', function () {
    $tasks = OrchestrationPayloadBuilder::buildRouteTasks(collect([
        orchestrationTestOrder(
            orchestrationTestPlace('and', 1.28),
            orchestrationTestPlace(103.90, 1.31)
        ),
    ]));

    expect($tasks[0])->toMatchArray([
        'id'      => 'order_test',
        'invalid' => true,
        'stops'   => [],
    ]);
    expect($tasks[0]['reason'])->toContain('pickup stop is missing valid coordinates');
});
