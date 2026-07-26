<?php

use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Payload;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\FleetOps\Orchestration\Engines\DriverAssignmentEngine;
use Fleetbase\Models\ScheduleItem;

class FleetOpsDriverAssignmentEngineProbe extends DriverAssignmentEngine
{
    public $drivers;
    public array $companyLookups = [];

    protected function availableDriversForCompany(?string $companyUuid): Illuminate\Support\Collection
    {
        $this->companyLookups[] = $companyUuid;

        return collect($this->drivers ?? []);
    }
}

class FleetOpsDriverAssignmentDriverFake extends Driver
{
    public ?object $locationFake = null;
    public array $skillsFake     = [];
    public bool $onlineFake      = false;
    public bool $activeShiftFake = false;

    public function getAttribute($key)
    {
        if ($key === 'location') {
            return $this->locationFake;
        }

        if ($key === 'skills') {
            return $this->skillsFake;
        }

        if ($key === 'online') {
            return $this->onlineFake;
        }

        return parent::getAttribute($key);
    }

    public function activeShiftFor(?DateTimeInterface $date = null): ?ScheduleItem
    {
        return $this->activeShiftFake ? new ScheduleItem() : null;
    }
}

class FleetOpsDriverAssignmentVehicleFake extends Vehicle
{
    public ?object $locationFake = null;

    public function getAttribute($key)
    {
        if ($key === 'location') {
            return $this->locationFake;
        }

        return parent::getAttribute($key);
    }
}

function fleetopsDriverAssignmentEngine(array $drivers = []): FleetOpsDriverAssignmentEngineProbe
{
    $engine          = new FleetOpsDriverAssignmentEngineProbe();
    $engine->drivers = collect($drivers);

    return $engine;
}

function fleetopsDriverAssignmentLocation(float $lat, float $lng): object
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

function fleetopsDriverAssignmentDriver(
    string $uuid,
    string $publicId,
    array $skills = [],
    bool $online = false,
    ?object $location = null,
    bool $hasSchedule = false,
    bool $hasActiveShift = false,
): FleetOpsDriverAssignmentDriverFake {
    $driver = new FleetOpsDriverAssignmentDriverFake();
    $driver->setRawAttributes([
        'uuid'      => $uuid,
        'public_id' => $publicId,
    ], true);
    $driver->setAppends([]);
    $driver->skillsFake      = $skills;
    $driver->onlineFake      = $online;
    $driver->locationFake    = $location;
    $driver->activeShiftFake = $hasActiveShift;
    $driver->setRelation('scheduleItems', $hasSchedule ? collect([new ScheduleItem()]) : collect());

    return $driver;
}

function fleetopsDriverAssignmentVehicle(string $uuid, string $publicId, ?object $location = null): FleetOpsDriverAssignmentVehicleFake
{
    $vehicle = new FleetOpsDriverAssignmentVehicleFake();
    $vehicle->setRawAttributes([
        'uuid'      => $uuid,
        'public_id' => $publicId,
    ], true);
    $vehicle->setAppends([]);
    $vehicle->locationFake = $location;

    return $vehicle;
}

function fleetopsDriverAssignmentOrder(
    string $publicId,
    ?string $vehicleUuid = null,
    array $skills = [],
    ?object $pickupLocation = null,
): Order {
    $order = new Order();
    $order->setRawAttributes([
        'uuid'                 => $publicId . '-uuid',
        'public_id'            => $publicId,
        'company_uuid'         => 'company-uuid',
        'vehicle_assigned_uuid'=> $vehicleUuid,
        'driver_assigned_uuid' => null,
        'required_skills'      => $skills,
    ], true);
    $order->setAppends([]);

    if ($pickupLocation) {
        $pickup = new Place();
        $pickup->setRawAttributes(['uuid' => $publicId . '-pickup'], true);
        $pickup->setAppends([]);
        $pickup->setRelation('location', $pickupLocation);

        $payload = new Payload();
        $payload->setRawAttributes(['uuid' => $publicId . '-payload'], true);
        $payload->setAppends([]);
        $payload->setRelation('pickup', $pickup);

        $order->setRelation('payload', $payload);
    }

    return $order;
}

test('driver assignment engine reports every vehicle unassigned when no drivers are available', function () {
    $engine = fleetopsDriverAssignmentEngine();

    $result = $engine->assign(collect([
        fleetopsDriverAssignmentOrder('order_public', 'vehicle-a'),
    ]), collect([
        fleetopsDriverAssignmentVehicle('vehicle-a', 'vehicle_a'),
        fleetopsDriverAssignmentVehicle('vehicle-b', 'vehicle_b'),
    ]));

    expect($result)->toBe([
        'assignments' => [],
        'unassigned'  => ['vehicle_a', 'vehicle_b'],
        'summary'     => ['message' => 'No available drivers found.'],
    ]);
    expect($engine->companyLookups)->toBe(['company-uuid']);
});

test('driver assignment engine assigns grouped vehicle orders to the best skilled driver', function () {
    $nearSkilled = fleetopsDriverAssignmentDriver(
        'driver-a',
        'driver_a',
        ['hazmat', 'reefer'],
        true,
        fleetopsDriverAssignmentLocation(1.3005, 103.8005),
        true,
        true,
    );
    $missingSkill = fleetopsDriverAssignmentDriver(
        'driver-b',
        'driver_b',
        ['hazmat'],
        true,
        fleetopsDriverAssignmentLocation(1.31, 103.81),
        true,
        true,
    );

    $engine = fleetopsDriverAssignmentEngine([$missingSkill, $nearSkilled]);
    $result = $engine->assign(collect([
        fleetopsDriverAssignmentOrder('order_one', 'vehicle-a', ['hazmat']),
        fleetopsDriverAssignmentOrder('order_two', 'vehicle-a', ['reefer']),
        fleetopsDriverAssignmentOrder('order_three', 'vehicle-b', ['liftgate']),
    ]), collect([
        fleetopsDriverAssignmentVehicle('vehicle-a', 'vehicle_a', fleetopsDriverAssignmentLocation(1.30, 103.80)),
        fleetopsDriverAssignmentVehicle('vehicle-b', 'vehicle_b', fleetopsDriverAssignmentLocation(1.45, 103.95)),
    ]), [
        'require_active_shift' => true,
    ]);

    expect($result['assignments'])->toBe([
        [
            'order_id'   => 'order_one',
            'vehicle_id' => 'vehicle_a',
            'driver_id'  => 'driver_a',
            'sequence'   => null,
        ],
        [
            'order_id'   => 'order_two',
            'vehicle_id' => 'vehicle_a',
            'driver_id'  => 'driver_a',
            'sequence'   => null,
        ],
    ]);
    expect($result['unassigned'])->toBe(['order_three']);
    expect($result['summary'])->toBe([
        'drivers_assigned'  => 1,
        'vehicles_assigned' => 0,
    ]);
});

test('driver assignment engine supports standalone order assignment and soft skill matching', function () {
    $farDriver = fleetopsDriverAssignmentDriver(
        'driver-far',
        'driver_far',
        [],
        false,
        fleetopsDriverAssignmentLocation(1.50, 104.00),
    );
    $nearDriver = fleetopsDriverAssignmentDriver(
        'driver-near',
        'driver_near',
        [],
        true,
        fleetopsDriverAssignmentLocation(1.301, 103.801),
    );

    $engine = fleetopsDriverAssignmentEngine([$farDriver, $nearDriver]);
    $result = $engine->assign(collect([
        fleetopsDriverAssignmentOrder('order_near', null, ['fragile'], fleetopsDriverAssignmentLocation(1.300, 103.800)),
        fleetopsDriverAssignmentOrder('order_overflow', null, [], fleetopsDriverAssignmentLocation(1.305, 103.805)),
    ]), collect([
        fleetopsDriverAssignmentVehicle('vehicle_near_uuid', 'vehicle_near', fleetopsDriverAssignmentLocation(1.301, 103.801)),
    ]), [
        'respect_skills' => false,
    ]);

    expect($result['assignments'])->toBe([
        [
            'order_id'   => 'order_near',
            'vehicle_id' => 'vehicle_near',
            'driver_id'  => 'driver_near',
            'sequence'   => null,
        ],
    ]);
    expect($result['unassigned'])->toBe(['order_overflow']);
    expect($result['summary'])->toBe([
        'drivers_assigned'  => 1,
        'vehicles_assigned' => 1,
    ]);
});

test('driver assignment engine filters scheduled drivers without active shifts when required', function () {
    $inactiveScheduled = fleetopsDriverAssignmentDriver(
        'driver-inactive',
        'driver_inactive',
        [],
        true,
        null,
        true,
        false,
    );

    $engine = fleetopsDriverAssignmentEngine([$inactiveScheduled]);
    $result = $engine->assign(collect([
        fleetopsDriverAssignmentOrder('order_public', 'vehicle-a'),
    ]), collect([
        fleetopsDriverAssignmentVehicle('vehicle-a', 'vehicle_a'),
    ]), [
        'require_active_shift' => true,
    ]);

    expect($result)->toBe([
        'assignments' => [],
        'unassigned'  => ['vehicle_a'],
        'summary'     => ['message' => 'No available drivers found.'],
    ]);
});
