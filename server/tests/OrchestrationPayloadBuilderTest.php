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
            $this->waypointMarkers  = collect($waypoints)->map(function ($place, $index) {
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

    $order            = new Order();
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

test('payload demand converts uncommon weight and dimension units', function () {
    $makeEntity = function (array $attributes) {
        $entity = new Fleetbase\FleetOps\Models\Entity();
        $entity->setRawAttributes($attributes, true);

        return $entity;
    };

    $payload = new class extends Fleetbase\FleetOps\Models\Payload {
        public $timestamps = false;
    };
    $payload->setRawAttributes(['uuid' => 'payload-demand-1'], true);
    $payload->setRelation('entities', collect([
        $makeEntity(['uuid' => 'ent-oz', 'weight' => '16', 'weight_unit' => 'oz']),
        $makeEntity(['uuid' => 'ent-ton', 'weight' => '2', 'weight_unit' => 'tonne']),
        $makeEntity(['uuid' => 'ent-mm', 'weight' => '1', 'weight_unit' => 'kg', 'length' => '500', 'width' => '400', 'height' => '300', 'dimensions_unit' => 'mm']),
        $makeEntity(['uuid' => 'ent-in', 'weight' => '1', 'weight_unit' => 'kg', 'length' => '10', 'width' => '10', 'height' => '10', 'dimensions_unit' => 'in']),
        $makeEntity(['uuid' => 'ent-ft', 'weight' => '1', 'weight_unit' => 'kg', 'length' => '2', 'width' => '2', 'height' => '2', 'dimensions_unit' => 'ft']),
    ]));

    $order = new Order();
    $order->setRawAttributes(['uuid' => 'order-demand-1'], true);
    $order->setRelation('payload', $payload);

    $reflection = new ReflectionMethod(OrchestrationPayloadBuilder::class, 'computePayloadDemand');
    $reflection->setAccessible(true);
    [$weightKg, $volumeLit, $pallets, $parcels] = $reflection->invoke(null, $order);

    // 16oz ≈ 0.45kg + 2t = 2000kg + 3×1kg ≈ 2003kg
    expect($weightKg)->toBe(2003)
        ->and($parcels)->toBe(5)
        ->and($pallets)->toBe(0)
        // 0.5×0.4×0.3 m³ = 60L, 10in cube ≈ 16.4L, 2ft cube ≈ 226.5L
        ->and($volumeLit)->toBeGreaterThan(300)->toBeLessThan(310);
});

test('vehicles without any start location are skipped and shift windows apply', function () {
    $connection = new Illuminate\Database\SQLiteConnection(new PDO('sqlite::memory:'));
    $resolver   = new Illuminate\Database\ConnectionResolver(['default' => $connection, 'mysql' => $connection]);
    $resolver->setDefaultConnection('mysql');
    Illuminate\Database\Eloquent\Model::setConnectionResolver($resolver);
    Illuminate\Support\Carbon::setTestNow(Illuminate\Support\Carbon::parse('2026-07-27 12:00:00'));

    $point = new Fleetbase\LaravelMysqlSpatial\Types\Point(1.3, 103.8);

    // Vehicle with no driver and no location is dropped from the fleet
    $bare = new class extends Fleetbase\FleetOps\Models\Vehicle {
        public $timestamps = false;
    };
    $bare->setRawAttributes(['uuid' => 'vehicle-noloc', 'public_id' => 'vehicle_noloc1'], true);
    $bare->setRelation('driver', null);

    // Vehicle whose driver has an active shift gets that time window
    $shift = new Fleetbase\Models\ScheduleItem();
    $shift->setRawAttributes(['uuid' => 'shift-window-1', 'start_at' => '2026-07-27 08:00:00', 'end_at' => '2026-07-27 16:00:00'], true);

    $driver = new class extends Fleetbase\FleetOps\Models\Driver {
        public $timestamps                               = false;
        public ?Fleetbase\Models\ScheduleItem $shiftFake = null;

        public function activeShiftFor(?DateTimeInterface $date = null): ?Fleetbase\Models\ScheduleItem
        {
            return $this->shiftFake;
        }
    };
    $driver->setRawAttributes(['uuid' => 'driver-window-1', 'public_id' => 'driver_window1'], true);
    $driver->shiftFake  = $shift;
    $driver->location   = $point;

    $shifted = new class extends Fleetbase\FleetOps\Models\Vehicle {
        public $timestamps = false;
    };
    $shifted->setRawAttributes(['uuid' => 'vehicle-shift', 'public_id' => 'vehicle_shift1'], true);
    $shifted->setRelation('driver', $driver);

    $vehicles = OrchestrationPayloadBuilder::buildVehicles(collect([$bare, $shifted]));

    expect($vehicles)->toHaveCount(1)
        ->and($vehicles[0]['id'])->toBe('vehicle_shift1')
        ->and($vehicles[0]['time_window'])->toBe([
            Illuminate\Support\Carbon::parse('2026-07-27 08:00:00')->timestamp,
            Illuminate\Support\Carbon::parse('2026-07-27 16:00:00')->timestamp,
        ]);

    Illuminate\Support\Carbon::setTestNow();
});
