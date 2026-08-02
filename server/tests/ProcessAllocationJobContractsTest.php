<?php

use Fleetbase\FleetOps\Jobs\ProcessAllocationJob;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Orchestration\Contracts\OrchestrationEngineInterface;
use Fleetbase\FleetOps\Orchestration\OrchestrationEngineRegistry;
use Illuminate\Support\Collection;

class FleetOpsProcessAllocationEngineFake implements OrchestrationEngineInterface
{
    public array $calls = [];

    public function __construct(private array $result)
    {
    }

    public function allocate(Collection $orders, Collection $vehicles, array $options = []): array
    {
        $this->calls[] = [$orders, $vehicles, $options];

        return $this->result;
    }

    public function getName(): string
    {
        return 'Allocation Fake';
    }

    public function getIdentifier(): string
    {
        return 'fake';
    }
}

class FleetOpsProcessAllocationJobProbe extends ProcessAllocationJob
{
    public Collection $orders;
    public Collection $vehicles;
    public array $ordersByPublicId  = [];
    public array $driversByPublicId = [];
    public array $messages          = [];
    public string $engine           = 'fake';
    public array $options           = [
        'max_travel_time'  => 900,
        'balance_workload' => true,
    ];

    public function __construct(string $companyUuid = 'company-uuid', array $orderIds = [])
    {
        parent::__construct($companyUuid, $orderIds);

        $this->orders   = collect();
        $this->vehicles = collect();
    }

    protected function unassignedOrders()
    {
        return $this->orders;
    }

    protected function availableVehicles()
    {
        return $this->vehicles;
    }

    protected function engineId(): string
    {
        return $this->engine;
    }

    protected function allocationOptions(): array
    {
        return $this->options;
    }

    protected function findOrderByPublicId(string $publicId): ?Order
    {
        return $this->ordersByPublicId[$publicId] ?? null;
    }

    protected function findDriverByPublicId(string $publicId): ?Driver
    {
        return $this->driversByPublicId[$publicId] ?? null;
    }

    protected function logInfo(string $message): void
    {
        $this->messages[] = $message;
    }
}

class FleetOpsProcessAllocationOrderFake extends Order
{
    public bool $saved           = false;
    public bool $firstDispatched = false;

    public function save(array $options = []): bool
    {
        $this->saved = true;

        return true;
    }

    public function firstDispatchWithActivity(): Order
    {
        $this->firstDispatched = true;

        return $this;
    }
}

function fleetOpsProcessAllocationOrder(string $publicId, ?string $assignedDriverUuid = null): FleetOpsProcessAllocationOrderFake
{
    $order = new FleetOpsProcessAllocationOrderFake();
    $order->forceFill([
        'public_id'            => $publicId,
        'driver_assigned_uuid' => $assignedDriverUuid,
    ]);

    return $order;
}

function fleetOpsProcessAllocationDriver(string $publicId, string $uuid): Driver
{
    $driver = new Driver();
    $driver->forceFill([
        'public_id' => $publicId,
        'uuid'      => $uuid,
    ]);

    return $driver;
}

function fleetOpsProcessAllocationRegistry(FleetOpsProcessAllocationEngineFake $engine): OrchestrationEngineRegistry
{
    $registry = new OrchestrationEngineRegistry();
    $registry->register($engine);

    return $registry;
}

test('process allocation job exits when there are no unassigned orders', function () {
    $engine = new FleetOpsProcessAllocationEngineFake([
        'assignments' => [],
        'unassigned'  => [],
        'summary'     => [],
    ]);
    $job = new FleetOpsProcessAllocationJobProbe('company-empty');

    $job->handle(fleetOpsProcessAllocationRegistry($engine));

    expect($engine->calls)->toBe([])
        ->and($job->messages)->toBe([
            '[ProcessAllocationJob] No unassigned orders for company company-empty.',
        ]);
});

test('process allocation job exits when there are no available vehicles', function () {
    $engine = new FleetOpsProcessAllocationEngineFake([
        'assignments' => [],
        'unassigned'  => [],
        'summary'     => [],
    ]);
    $job         = new FleetOpsProcessAllocationJobProbe('company-no-vehicles');
    $job->orders = collect([fleetOpsProcessAllocationOrder('order_one')]);

    $job->handle(fleetOpsProcessAllocationRegistry($engine));

    expect($engine->calls)->toBe([])
        ->and($job->messages)->toBe([
            '[ProcessAllocationJob] No available vehicles for company company-no-vehicles.',
        ]);
});

test('process allocation job commits assignments and skips missing or already assigned records', function () {
    $engine = new FleetOpsProcessAllocationEngineFake([
        'assignments' => [
            ['order_id' => 'order_one', 'driver_id' => 'driver_one', 'vehicle_id' => 'vehicle_one', 'sequence' => 1],
            ['order_id' => 'missing_order', 'driver_id' => 'driver_one', 'vehicle_id' => 'vehicle_one', 'sequence' => 1],
            ['order_id' => 'order_two', 'driver_id' => 'missing_driver', 'vehicle_id' => 'vehicle_one', 'sequence' => 1],
            ['order_id' => 'order_three', 'driver_id' => 'driver_two', 'vehicle_id' => 'vehicle_two', 'sequence' => 1],
        ],
        'unassigned' => ['order_four'],
        'summary'    => ['engine' => 'fake'],
    ]);

    $orderOne   = fleetOpsProcessAllocationOrder('order_one');
    $orderTwo   = fleetOpsProcessAllocationOrder('order_two');
    $orderThree = fleetOpsProcessAllocationOrder('order_three', 'already-driver');
    $driverOne  = fleetOpsProcessAllocationDriver('driver_one', 'driver-uuid-one');
    $driverTwo  = fleetOpsProcessAllocationDriver('driver_two', 'driver-uuid-two');

    $job                   = new FleetOpsProcessAllocationJobProbe('company-assign');
    $job->orders           = collect([$orderOne, $orderTwo, $orderThree]);
    $job->vehicles         = collect([(object) ['public_id' => 'vehicle_one']]);
    $job->ordersByPublicId = [
        'order_one'   => $orderOne,
        'order_two'   => $orderTwo,
        'order_three' => $orderThree,
    ];
    $job->driversByPublicId = [
        'driver_one' => $driverOne,
        'driver_two' => $driverTwo,
    ];

    $job->handle(fleetOpsProcessAllocationRegistry($engine));

    expect($engine->calls)->toHaveCount(1)
        ->and($engine->calls[0][0])->toBe($job->orders)
        ->and($engine->calls[0][1])->toBe($job->vehicles)
        ->and($engine->calls[0][2])->toBe([
            'max_travel_time'  => 900,
            'balance_workload' => true,
        ])
        ->and($orderOne->driver_assigned_uuid)->toBe('driver-uuid-one')
        ->and($orderOne->saved)->toBeTrue()
        ->and($orderOne->firstDispatched)->toBeTrue()
        ->and($orderTwo->saved)->toBeFalse()
        ->and($orderThree->saved)->toBeFalse()
        ->and($job->messages)->toBe([
            '[ProcessAllocationJob] Committed 4 assignments, 1 unassigned for company company-assign.',
        ]);
});
