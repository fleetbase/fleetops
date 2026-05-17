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
                    public float $width = 1;
                    public float $height = 1;
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

    $order = new Order();
    $order->public_id = $publicId;
    $order->required_skills = $skills;
    $order->setRelation('payload', $payload);

    return $order;
}

function capacityTestVehicle(string $publicId, float $weightKg, float $volumeM3 = 0, int $maxTasks = 0, array $skills = []): Vehicle
{
    $vehicle = new Vehicle();
    $vehicle->public_id = $publicId;
    $vehicle->payload_capacity = $weightKg;
    $vehicle->payload_capacity_volume = $volumeM3;
    $vehicle->payload_capacity_pallets = 0;
    $vehicle->payload_capacity_parcels = 100;
    $vehicle->max_tasks = $maxTasks;
    $vehicle->skills = $skills;
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
