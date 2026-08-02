<?php

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\FleetOps\Orchestration\Engines\CapacityAllocationEngine;

function capacityTestOrder(string $publicId, float $weightKg, float $volumeLitres = 0, array $skills = []): Order
{
    $payload = new class($weightKg, $volumeLitres) {
        public $entities;

        public function __construct(float $weightKg, float $volumeLitres)
        {
            $this->entities = collect([
                new class($weightKg, $volumeLitres) {
                    public float $weight;
                    public string $weight_unit = 'kg';
                    public float $length;
                    public float $width            = 1;
                    public float $height           = 1;
                    public string $dimensions_unit = 'm';

                    public function __construct(float $weightKg, float $volumeLitres)
                    {
                        $this->weight = $weightKg;
                        $this->length = $volumeLitres / 1000;
                    }
                },
            ]);
        }
    };

    $order                  = new Order();
    $order->public_id       = $publicId;
    $order->required_skills = $skills;
    $order->setRelation('payload', $payload);

    return $order;
}

function capacityTestVehicle(string $publicId, float $weightKg, float $volumeM3 = 0, int $maxTasks = 0, array $skills = []): Vehicle
{
    $vehicle                           = new Vehicle();
    $vehicle->public_id                = $publicId;
    $vehicle->payload_capacity         = $weightKg;
    $vehicle->payload_capacity_volume  = $volumeM3;
    $vehicle->payload_capacity_pallets = 0;
    $vehicle->payload_capacity_parcels = 100;
    $vehicle->max_tasks                = $maxTasks;
    $vehicle->skills                   = $skills;
    $vehicle->setRelation('driver', null);

    return $vehicle;
}

test('capacity engine assigns orders without vehicle locations', function () {
    $engine = new CapacityAllocationEngine();

    $result = $engine->allocate(collect([
        capacityTestOrder('order_light', 10, 250),
        capacityTestOrder('order_medium', 20, 500),
    ]), collect([
        capacityTestVehicle('vehicle_van', 100, 2),
    ]), [
        'balance_workload' => false,
    ]);

    expect($result['assignments'])->toHaveCount(2);
    expect(array_column($result['assignments'], 'vehicle_id'))->toBe(['vehicle_van', 'vehicle_van']);
    expect($result['unassigned'])->toBe([]);
    expect($result['summary']['allocation_strategy'])->toBe('capacity_only');
});

test('capacity engine rejects over-capacity orders with useful reasons', function () {
    $engine = new CapacityAllocationEngine();

    $result = $engine->allocate(collect([
        capacityTestOrder('order_heavy', 250),
    ]), collect([
        capacityTestVehicle('vehicle_small', 100),
    ]));

    expect($result['assignments'])->toBe([]);
    expect($result['unassigned'])->toBe(['order_heavy']);
    expect($result['summary']['unassigned_reasons'][0])->toMatchArray([
        'order_id' => 'order_heavy',
        'reason'   => 'capacity_exceeded',
    ]);
});

test('capacity engine respects skills max tasks and balanced workload', function () {
    $engine = new CapacityAllocationEngine();

    $result = $engine->allocate(collect([
        capacityTestOrder('order_cold_1', 10, 0, ['cold_chain']),
        capacityTestOrder('order_cold_2', 10, 0, ['cold_chain']),
        capacityTestOrder('order_fragile', 10, 0, ['fragile']),
    ]), collect([
        capacityTestVehicle('vehicle_cold_a', 100, 0, 1, ['cold_chain']),
        capacityTestVehicle('vehicle_cold_b', 100, 0, 1, ['cold_chain']),
    ]), [
        'balance_workload' => true,
    ]);

    expect(array_column($result['assignments'], 'vehicle_id'))->toBe(['vehicle_cold_a', 'vehicle_cold_b']);
    expect($result['unassigned'])->toBe(['order_fragile']);
    expect($result['summary']['unassigned_reasons'][0]['reason'])->toBe('missing_required_skills');
});

test('capacity engine names invalid tasks empty pools and mixed eligibility reasons', function () {
    $engine = new CapacityAllocationEngine();
    expect($engine->getName())->toBe('Capacity (built-in)')
        ->and($engine->getIdentifier())->toBe('capacity');

    // Orders without payloads are invalid tasks with explanatory reasons
    $noPayload            = new Order();
    $noPayload->public_id = 'order_nopayload';
    $noPayload->setRelation('payload', null);
    $result  = $engine->allocate(collect([$noPayload]), collect([capacityTestVehicle('vehicle_any', 100)]), []);
    $reasons = collect($result['summary']['unassigned_reasons'])->pluck('reason', 'order_id');
    expect($result['unassigned'])->toBe(['order_nopayload'])
        ->and(str_contains((string) $reasons['order_nopayload'], 'no payload'))->toBeTrue();

    // An empty vehicle pool cannot host any order
    $result  = $engine->allocate(collect([capacityTestOrder('order_alone', 10)]), collect([]), []);
    $reasons = collect($result['summary']['unassigned_reasons'])->pluck('reason', 'order_id');
    expect($reasons['order_alone'])->toBe('no_available_vehicle');

    // One vehicle with a single task slot: the second order exceeds max tasks
    $result = $engine->allocate(collect([
        capacityTestOrder('order_first', 10),
        capacityTestOrder('order_second', 10),
    ]), collect([capacityTestVehicle('vehicle_single', 100, 1, 1)]), []);
    $reasons = collect($result['summary']['unassigned_reasons'])->pluck('reason', 'order_id');
    expect($reasons['order_second'])->toBe('max_tasks_exceeded');

    // Capacity on one vehicle, skills on another: no single vehicle qualifies
    $result = $engine->allocate(collect([
        capacityTestOrder('order_mixed', 50, 0, ['crane']),
    ]), collect([
        capacityTestVehicle('vehicle_strong', 100, 1, 0, []),
        capacityTestVehicle('vehicle_skilled', 10, 1, 0, ['crane']),
    ]), []);
    $reasons = collect($result['summary']['unassigned_reasons'])->pluck('reason', 'order_id');
    expect($reasons['order_mixed'])->toBe('no_available_vehicle');
});

test('capacity engine balances workload across equally loaded vehicles', function () {
    $engine = new CapacityAllocationEngine();

    $result = $engine->allocate(collect([
        capacityTestOrder('order_b1', 10),
        capacityTestOrder('order_b2', 10),
        capacityTestOrder('order_b3', 10),
    ]), collect([
        capacityTestVehicle('vehicle_bal_a', 100, 1, 0),
        capacityTestVehicle('vehicle_bal_b', 100, 1, 0),
    ]), ['balance_workload' => true]);

    $byVehicle = collect($result['assignments'])->groupBy('vehicle_id')->map->count();
    expect($result['assignments'])->toHaveCount(3)
        ->and($byVehicle->get('vehicle_bal_a', 0) + $byVehicle->get('vehicle_bal_b', 0))->toBe(3)
        ->and($byVehicle->min())->toBeGreaterThanOrEqual(1);
});
