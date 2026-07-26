<?php

use Fleetbase\Ai\Models\AiTask;
use Fleetbase\FleetOps\Models\Device;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Maintenance;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Sensor;
use Fleetbase\FleetOps\Models\Telematic;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\FleetOps\Models\WorkOrder;
use Fleetbase\FleetOps\Support\Ai\Capabilities\SearchResourcesCapability;

class FleetOpsSearchResourcesQueryFake
{
    public array $calls = [];

    public function __construct(public array $records = [])
    {
    }

    public function where(...$arguments): self
    {
        $this->calls[] = ['where', $arguments];

        if (isset($arguments[0]) && is_callable($arguments[0])) {
            $arguments[0]($this);
        }

        return $this;
    }

    public function orWhere(...$arguments): self
    {
        $this->calls[] = ['orWhere', $arguments];

        return $this;
    }

    public function orWhereHas(string $relation, Closure $callback): self
    {
        $related = new self();
        $callback($related);
        $this->calls[] = ['orWhereHas', $relation, $related->calls];

        return $this;
    }

    public function limit(int $limit): self
    {
        $this->calls[] = ['limit', $limit];

        return $this;
    }

    public function get()
    {
        $this->calls[] = ['get'];

        return collect($this->records);
    }
}

class FleetOpsSearchResourcesCapabilityProbe extends SearchResourcesCapability
{
    public array $allowedPermissions = [];
    public array $queries            = [];
    public array $appliedLikes       = [];

    public function exposePromptMatches(string $prompt): bool
    {
        return $this->matchesPrompt($prompt);
    }

    protected function can(string $permission): bool
    {
        return in_array($permission, $this->allowedPermissions, true);
    }

    protected function whereLikeAny($builder, array $columns, array $terms): void
    {
        $this->appliedLikes[] = [$columns, $terms];

        if ($builder instanceof FleetOpsSearchResourcesQueryFake) {
            $builder->calls[] = ['whereLikeAny', $columns, $terms];
        }
    }

    protected function orderSearchQuery()
    {
        return $this->queries['orders'];
    }

    protected function vehicleSearchQuery()
    {
        return $this->queries['vehicles'];
    }

    protected function driverSearchQuery()
    {
        return $this->queries['drivers'];
    }

    protected function genericSearchQuery(string $modelClass)
    {
        return match ($modelClass) {
            WorkOrder::class   => $this->queries['work_orders'],
            Maintenance::class => $this->queries['maintenances'],
            Device::class      => $this->queries['devices'],
            Sensor::class      => $this->queries['sensors'],
            Telematic::class   => $this->queries['telematics'],
        };
    }
}

function fleetopsSearchResourcesModel(string $class, array $attributes)
{
    $model = new $class();
    $model->setRawAttributes($attributes, true);

    return $model;
}

test('search resources resolve returns authorized resource summaries across all supported branches', function () {
    $order = fleetopsSearchResourcesModel(Order::class, [
        'public_id' => 'order_public',
        'uuid'      => 'order-uuid',
        'status'    => 'dispatched',
        'type'      => 'transport',
    ]);
    $order->setRelation('trackingNumber', (object) ['tracking_number' => 'TRK-1']);
    $order->setRelation('transaction', (object) ['amount' => 125.5, 'currency' => 'SGD']);

    $vehicle = fleetopsSearchResourcesModel(Vehicle::class, [
        'public_id'    => 'vehicle_public',
        'uuid'         => 'vehicle-uuid',
        'name'         => 'Van 12',
        'plate_number' => 'SBA1234Z',
        'status'       => 'active',
    ]);

    $driver = fleetopsSearchResourcesModel(Driver::class, [
        'public_id' => 'driver_public',
        'uuid'      => 'driver-uuid',
        'status'    => 'available',
    ]);
    $driver->setRelation('user', (object) ['name' => 'Ada Driver']);

    $workOrder = fleetopsSearchResourcesModel(WorkOrder::class, [
        'public_id' => 'work_order_public',
        'uuid'      => 'work-order-uuid',
        'name'      => 'Inspect liftgate',
        'status'    => 'open',
    ]);

    $maintenance = fleetopsSearchResourcesModel(Maintenance::class, [
        'public_id' => 'maintenance_public',
        'uuid'      => 'maintenance-uuid',
        'name'      => 'Quarterly service',
        'status'    => 'scheduled',
    ]);

    $device = fleetopsSearchResourcesModel(Device::class, [
        'public_id' => 'device_public',
        'uuid'      => 'device-uuid',
        'name'      => 'Tablet 7',
        'status'    => 'online',
    ]);

    $sensor = fleetopsSearchResourcesModel(Sensor::class, [
        'public_id' => 'sensor_public',
        'uuid'      => 'sensor-uuid',
        'name'      => 'Temperature probe',
        'status'    => 'active',
    ]);

    $telematic = fleetopsSearchResourcesModel(Telematic::class, [
        'public_id' => 'telematic_public',
        'uuid'      => 'telematic-uuid',
        'name'      => 'Flespi Gateway',
        'status'    => 'connected',
    ]);

    $capability                     = new FleetOpsSearchResourcesCapabilityProbe();
    $capability->allowedPermissions = $capability->permissions();
    $capability->queries            = [
        'orders'       => new FleetOpsSearchResourcesQueryFake([$order]),
        'vehicles'     => new FleetOpsSearchResourcesQueryFake([$vehicle]),
        'drivers'      => new FleetOpsSearchResourcesQueryFake([$driver]),
        'work_orders'  => new FleetOpsSearchResourcesQueryFake([$workOrder]),
        'maintenances' => new FleetOpsSearchResourcesQueryFake([$maintenance]),
        'devices'      => new FleetOpsSearchResourcesQueryFake([$device]),
        'sensors'      => new FleetOpsSearchResourcesQueryFake([$sensor]),
        'telematics'   => new FleetOpsSearchResourcesQueryFake([$telematic]),
    ];

    $result = $capability->resolve(new AiTask(['prompt' => 'Find order ORDER-1 vehicle VEH-1 driver DRV-1 device DEV-1 sensor SNS-1 telematic TEL-1']));

    expect($capability->exposePromptMatches('look up work order WO-1'))->toBeTrue()
        ->and($result['query_terms'])->toBe(['ORDER-1', 'VEH-1', 'DRV-1', 'device', 'DEV-1', 'sensor'])
        ->and($result['results'])->toHaveKeys(['orders', 'vehicles', 'drivers', 'work_orders', 'maintenances', 'devices', 'sensors', 'telematics'])
        ->and($result['results']['orders'][0])->toMatchArray([
            'id'                   => 'order_public',
            'uuid'                 => 'order-uuid',
            'tracking'             => 'TRK-1',
            'status'               => 'dispatched',
            'type'                 => 'transport',
            'transaction_amount'   => 125.5,
            'transaction_currency' => 'SGD',
            'route'                => 'console.fleet-ops.operations.orders.index.details',
            'models'               => ['order_public'],
        ])
        ->and($result['results']['vehicles'][0])->toMatchArray([
            'id'     => 'vehicle_public',
            'uuid'   => 'vehicle-uuid',
            'name'   => 'Van 12',
            'plate'  => 'SBA1234Z',
            'status' => 'active',
            'route'  => 'console.fleet-ops.management.vehicles.index.details',
            'models' => ['vehicle_public'],
        ])
        ->and($result['results']['drivers'][0])->toMatchArray([
            'id'     => 'driver_public',
            'uuid'   => 'driver-uuid',
            'name'   => 'Ada Driver',
            'status' => 'available',
            'route'  => 'console.fleet-ops.management.drivers.index.details',
            'models' => ['driver_public'],
        ])
        ->and($result['results']['work_orders'][0]['name'])->toBe('Inspect liftgate')
        ->and($result['results']['maintenances'][0]['name'])->toBe('Quarterly service')
        ->and($result['results']['devices'][0]['route'])->toBe('console.fleet-ops.connectivity.devices.index.details')
        ->and($result['results']['sensors'][0]['route'])->toBe('console.fleet-ops.connectivity.sensors.index.details')
        ->and($result['results']['telematics'][0]['route'])->toBe('console.fleet-ops.connectivity.telematics.details')
        ->and($capability->queries['orders']->calls)->toContain(['limit', 5], ['get'])
        ->and($capability->appliedLikes)->not->toBeEmpty();
});
