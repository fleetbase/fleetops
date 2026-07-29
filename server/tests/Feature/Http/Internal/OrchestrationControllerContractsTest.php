<?php

use Fleetbase\FleetOps\Http\Controllers\Internal\v1\OrchestrationController;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Manifest;
use Fleetbase\FleetOps\Models\ManifestStop;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\OrderConfig;
use Fleetbase\FleetOps\Models\Payload;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\FleetOps\Orchestration\Contracts\OrchestrationEngineInterface;
use Fleetbase\FleetOps\Orchestration\Engines\DriverAssignmentEngine;
use Fleetbase\FleetOps\Orchestration\Engines\GreedyOrchestrationEngine;
use Fleetbase\FleetOps\Orchestration\Engines\RouteSequencingEngine;
use Fleetbase\FleetOps\Orchestration\OrchestrationEngineRegistry;
use Fleetbase\Models\CustomField;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class FleetOpsOrchestrationCommitControllerProbe extends OrchestrationController
{
    public array $vehicles                                   = [];
    public array $drivers                                    = [];
    public array $orders                                     = [];
    public array $manifests                                  = [];
    public array $manifestStops                              = [];
    public array $waypointUpdates                            = [];
    public array $transactions                               = [];
    public array $fieldConfigs                               = [];
    public array $fallbackCustomFields                       = [];
    public array $vehiclesByUuid                             = [];
    public array $driverMap                                  = [];
    public ?FleetOpsOrchestrationQueryFake $orderQuery       = null;
    public ?FleetOpsOrchestrationQueryFake $vehicleQuery     = null;
    public ?FleetOpsDriverAssignmentEngineFake $driverEngine = null;
    public ?FleetOpsRouteSequencingEngineFake $routeEngine   = null;
    public string $engineSetting                             = 'greedy';
    public bool $throwOnCreateManifest                       = false;
    public int $transactionLevel                             = 0;

    protected function companyUuid(): ?string
    {
        return 'company-uuid';
    }

    protected function orchestratorOrdersQuery(?string $companyUuid): mixed
    {
        return $this->orderQuery ??= new FleetOpsOrchestrationQueryFake();
    }

    protected function orchestrationRunOrdersQuery(?string $companyUuid): mixed
    {
        return $this->orderQuery ??= new FleetOpsOrchestrationQueryFake();
    }

    protected function orchestrationRunVehiclesQuery(?string $companyUuid): mixed
    {
        return $this->vehicleQuery ??= new FleetOpsOrchestrationQueryFake();
    }

    protected function driversByPublicId(array $publicIds)
    {
        return collect($this->driverMap)->only($publicIds);
    }

    protected function vehicleByPublicIdWithDriver(string $publicId): ?Vehicle
    {
        return $this->vehicles[$publicId] ?? null;
    }

    protected function vehicleByUuidWithDriver(string $uuid): ?Vehicle
    {
        return $this->vehiclesByUuid[$uuid] ?? null;
    }

    protected function orchestratorEngineSetting(): string
    {
        return $this->engineSetting;
    }

    protected function driverAssignmentEngine(): DriverAssignmentEngine
    {
        return $this->driverEngine ??= new FleetOpsDriverAssignmentEngineFake();
    }

    protected function routeSequencingEngine(): RouteSequencingEngine
    {
        return $this->routeEngine ??= new FleetOpsRouteSequencingEngineFake();
    }

    protected function beginOrchestrationTransaction(): void
    {
        $this->transactions[] = 'begin';
        $this->transactionLevel++;
    }

    protected function commitOrchestrationTransaction(): void
    {
        $this->transactions[]   = 'commit';
        $this->transactionLevel = max(0, $this->transactionLevel - 1);
    }

    protected function rollBackOrchestrationTransaction(): void
    {
        $this->transactions[]   = 'rollback';
        $this->transactionLevel = max(0, $this->transactionLevel - 1);
    }

    protected function orchestrationTransactionLevel(): int
    {
        return $this->transactionLevel;
    }

    protected function findVehicleByPublicId(string $publicId): ?Vehicle
    {
        return $this->vehicles[$publicId] ?? null;
    }

    protected function findDriverByPublicId(string $publicId): ?Driver
    {
        return $this->drivers[$publicId] ?? null;
    }

    protected function findOrderByPublicId(string $publicId): ?Order
    {
        return $this->orders[$publicId] ?? null;
    }

    protected function createManifest(array $attributes): Manifest
    {
        if ($this->throwOnCreateManifest) {
            throw new RuntimeException('manifest boom');
        }

        $manifest = new Manifest();
        $manifest->setRawAttributes(array_merge([
            'uuid'      => 'manifest-' . (count($this->manifests) + 1),
            'public_id' => 'manifest_public_' . (count($this->manifests) + 1),
        ], $attributes), true);
        $this->manifests[] = $attributes;

        return $manifest;
    }

    protected function createManifestStop(array $attributes): ManifestStop
    {
        $stop = new ManifestStop();
        $stop->setRawAttributes($attributes, true);
        $this->manifestStops[] = $attributes;

        return $stop;
    }

    protected function updateWaypointSequence(string $payloadUuid, string $waypointPublicId, int|string $sequence): void
    {
        $this->waypointUpdates[] = [$payloadUuid, $waypointPublicId, $sequence];
    }

    protected function getOrderConfigFieldConfigs(string $companyUuid)
    {
        return collect($this->fieldConfigs);
    }

    protected function getCustomFieldsForOrderConfig(string $orderConfigUuid)
    {
        return collect($this->fallbackCustomFields[$orderConfigUuid] ?? []);
    }
}

class FleetOpsOrchestrationCommitOrderFake extends Order
{
    public bool $saved = false;

    public function save(array $options = []): bool
    {
        $this->saved = true;

        return true;
    }
}

class FleetOpsOrchestrationQueryFake
{
    public array $calls = [];

    public function __construct(public Collection $results = new Collection())
    {
    }

    public function __call(string $method, array $arguments): self
    {
        $this->calls[] = [$method, $arguments];

        foreach ($arguments as $argument) {
            if ($argument instanceof Closure) {
                $argument($this);
            }
        }

        return $this;
    }

    public function get(): Collection
    {
        $this->calls[] = ['get', []];

        return $this->results;
    }

    public function first(): mixed
    {
        $this->calls[] = ['first', []];

        return $this->results->first();
    }
}

class FleetOpsDriverAssignmentEngineFake extends DriverAssignmentEngine
{
    public array $calls = [];

    public function assign(Collection $orders, Collection $vehicles, array $options = []): array
    {
        $this->calls[] = compact('orders', 'vehicles', 'options');

        return [
            'assignments' => [[
                'order_id'   => $orders->first()?->public_id,
                'vehicle_id' => $vehicles->first()?->public_id,
                'driver_id'  => $orders->first()?->driverAssigned?->public_id,
                'sequence'   => null,
            ]],
            'unassigned'  => [],
            'summary'     => ['engine' => 'driver_assignment_fake'],
        ];
    }
}

class FleetOpsRouteSequencingEngineFake extends RouteSequencingEngine
{
    public array $calls = [];

    public function sequence(Collection $orders, array $options = []): array
    {
        $this->calls[] = compact('orders', 'options');

        return [
            'assignments' => [[
                'order_id'   => $orders->first()?->public_id,
                'vehicle_id' => $orders->first()?->vehicle?->public_id,
                'driver_id'  => $orders->first()?->vehicle?->driver?->public_id,
                'sequence'   => 1,
            ]],
            'unassigned'  => [],
            'summary'     => ['engine' => 'route_sequence_fake'],
        ];
    }
}

class FleetOpsThrowingOrchestrationEngineFake implements OrchestrationEngineInterface
{
    public function allocate(Collection $orders, Collection $vehicles, array $options = []): array
    {
        throw new RuntimeException('orchestration unavailable');
    }

    public function getName(): string
    {
        return 'Throwing Engine';
    }

    public function getIdentifier(): string
    {
        return 'throwing';
    }
}

function fleetopsOrchestrationController(): OrchestrationController
{
    $registry = new OrchestrationEngineRegistry();
    $registry->register(new GreedyOrchestrationEngine());

    return new OrchestrationController($registry);
}

function fleetopsOrchestrationCommitController(): FleetOpsOrchestrationCommitControllerProbe
{
    $registry = new OrchestrationEngineRegistry();
    $registry->register(new GreedyOrchestrationEngine());

    return new FleetOpsOrchestrationCommitControllerProbe($registry);
}

function fleetopsOrchestrationVehicle(string $publicId = 'vehicle_public'): Vehicle
{
    $vehicle = new Vehicle();
    $vehicle->setRawAttributes([
        'uuid'      => $publicId . '-uuid',
        'public_id' => $publicId,
    ], true);

    return $vehicle;
}

function fleetopsOrchestrationDriver(string $publicId = 'driver_public'): Driver
{
    $driver = new Driver();
    $driver->setRawAttributes([
        'uuid'      => $publicId . '-uuid',
        'public_id' => $publicId,
    ], true);

    return $driver;
}

function fleetopsOrchestrationOrder(string $publicId, ?string $dropoffUuid = 'dropoff-uuid'): FleetOpsOrchestrationCommitOrderFake
{
    $dropoff = new Place();
    $dropoff->setRawAttributes(['uuid' => $dropoffUuid], true);

    $payload = new Payload();
    $payload->setRawAttributes(['uuid' => 'payload-' . $publicId], true);
    $payload->setRelation('dropoff', $dropoff);

    $order = new FleetOpsOrchestrationCommitOrderFake();
    $order->setRawAttributes([
        'uuid'         => $publicId . '-uuid',
        'public_id'    => $publicId,
        'payload_uuid' => $payload->uuid,
    ], true);
    $order->setRelation('payload', $payload);

    return $order;
}

function fleetopsOrchestrationOrderConfig(string $uuid, string $publicId, array $attributes = [], array $customFields = []): OrderConfig
{
    $config = new OrderConfig();
    $config->setRawAttributes(array_merge([
        'uuid'      => $uuid,
        'public_id' => $publicId,
        'name'      => Str::headline($publicId),
        'key'       => Str::slug($publicId, '_'),
    ], $attributes), true);
    $config->setRelation('customFields', collect($customFields));

    return $config;
}

function fleetopsOrchestrationCustomField(array $attributes): CustomField
{
    $field = new CustomField();
    $field->setRawAttributes($attributes, true);

    return $field;
}

function callOrchestrationControllerHelper(OrchestrationController $controller, string $method, mixed ...$arguments): mixed
{
    $reflection = new ReflectionMethod(OrchestrationController::class, $method);
    $reflection->setAccessible(true);

    return $reflection->invoke($controller, ...$arguments);
}

test('orchestration orders endpoint applies workbench filters and caps limits', function () {
    $controller             = fleetopsOrchestrationCommitController();
    $controller->orderQuery = new FleetOpsOrchestrationQueryFake();
    $request                = Request::create('/orchestrator/orders', 'GET', [
        'unassigned' => '1',
        'limit'      => 1500,
    ]);
    app()->instance('request', $request);

    $response = $controller->orders($request);

    $methods    = array_column($controller->orderQuery->calls, 0);
    $limitCalls = array_values(array_filter(
        $controller->orderQuery->calls,
        fn ($call) => $call[0] === 'limit'
    ));
    $whereNullCalls = array_values(array_filter(
        $controller->orderQuery->calls,
        fn ($call) => $call[0] === 'whereNull'
    ));

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData(true))->toBe(['orders' => []])
        ->and($methods)->toContain('whereHas', 'whereNotNull', 'orWhereHas', 'with', 'limit', 'get')
        ->and($limitCalls[0][1])->toBe([1000])
        ->and($whereNullCalls)->toContain(['whereNull', ['vehicle_assigned_uuid']]);
});

test('orchestration run reports empty criteria and preview delegates to run', function () {
    $vehicle = fleetopsOrchestrationVehicle();
    $vehicle->setRelation('driver', fleetopsOrchestrationDriver());

    $controller               = fleetopsOrchestrationCommitController();
    $controller->orderQuery   = new FleetOpsOrchestrationQueryFake();
    $controller->vehicleQuery = new FleetOpsOrchestrationQueryFake(collect([$vehicle]));

    $response = $controller->run(Request::create('/orchestrator/run', 'POST', [
        'mode'              => 'allocate',
        'prior_assignments' => [
            ['order_id' => 'order_taken', 'vehicle_id' => 'vehicle_one'],
        ],
    ]));

    $preview = $controller->preview(Request::create('/orchestrator/preview', 'GET', [
        'mode' => 'assign_vehicles',
    ]));

    $orderMethods = array_column($controller->orderQuery->calls, 0);

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData(true))->toBe([
            'message'     => 'No orders found for the given criteria.',
            'assignments' => [],
            'unassigned'  => [],
        ])
        ->and($preview->getData(true)['message'])->toBe('No orders found for the given criteria.')
        ->and($orderMethods)->toContain('whereNull', 'whereNotIn', 'get');
});

test('orchestration run reports unavailable vehicles with selected order ids', function () {
    $order                    = fleetopsOrchestrationOrder('order_one');
    $controller               = fleetopsOrchestrationCommitController();
    $controller->orderQuery   = new FleetOpsOrchestrationQueryFake(collect([$order]));
    $controller->vehicleQuery = new FleetOpsOrchestrationQueryFake();

    $response = $controller->run(Request::create('/orchestrator/run', 'POST', [
        'mode'        => 'assign_vehicles',
        'order_ids'   => ['order_one'],
        'vehicle_ids' => ['vehicle_missing'],
    ]));

    $orderMethods   = array_column($controller->orderQuery->calls, 0);
    $vehicleMethods = array_column($controller->vehicleQuery->calls, 0);

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData(true))->toBe([
            'message'     => 'No available vehicles found.',
            'assignments' => [],
            'unassigned'  => ['order_one'],
        ])
        ->and($orderMethods)->toContain('whereIn')
        ->and($vehicleMethods)->toContain('whereIn');
});

test('orchestration assign drivers augments orders from prior assignments before engine dispatch', function () {
    $order   = fleetopsOrchestrationOrder('order_one');
    $driver  = fleetopsOrchestrationDriver('driver_one');
    $vehicle = fleetopsOrchestrationVehicle('vehicle_one');
    $vehicle->setRelation('driver', fleetopsOrchestrationDriver('old_driver'));

    $controller                          = fleetopsOrchestrationCommitController();
    $controller->orderQuery              = new FleetOpsOrchestrationQueryFake(collect([$order]));
    $controller->vehicleQuery            = new FleetOpsOrchestrationQueryFake(collect([$vehicle]));
    $controller->vehicles['vehicle_one'] = $vehicle;
    $controller->driverMap               = ['driver_one' => $driver];
    $controller->driverEngine            = new FleetOpsDriverAssignmentEngineFake();

    $response = $controller->run(Request::create('/orchestrator/run', 'POST', [
        'mode'              => 'assign_drivers',
        'options'           => ['respect_skills' => false],
        'prior_assignments' => [
            [
                'order_id'   => 'order_one',
                'vehicle_id' => 'vehicle_one',
                'driver_id'  => 'driver_one',
            ],
        ],
    ]));

    $payload = $response->getData(true);

    expect($response->getStatusCode())->toBe(200)
        ->and($payload['summary'])->toBe(['engine' => 'driver_assignment_fake'])
        ->and($order->vehicle_assigned_uuid)->toBe('vehicle_one-uuid')
        ->and($order->driver_assigned_uuid)->toBe('driver_one-uuid')
        ->and($order->vehicle->driver->public_id)->toBe('driver_one')
        ->and($order->driverAssigned->public_id)->toBe('driver_one')
        ->and($controller->driverEngine->calls[0]['options'])->toBe(['respect_skills' => false]);
});

test('orchestration optimize routes hydrates missing vehicle relations before sequencing', function () {
    $order                        = fleetopsOrchestrationOrder('order_one');
    $order->vehicle_assigned_uuid = 'vehicle-one-uuid';

    $driver        = fleetopsOrchestrationDriver('driver_one');
    $vehicle       = fleetopsOrchestrationVehicle('vehicle_one');
    $vehicle->uuid = 'vehicle-one-uuid';
    $vehicle->setRelation('driver', $driver);

    $controller                                     = fleetopsOrchestrationCommitController();
    $controller->orderQuery                         = new FleetOpsOrchestrationQueryFake(collect([$order]));
    $controller->vehicleQuery                       = new FleetOpsOrchestrationQueryFake(collect([$vehicle]));
    $controller->vehiclesByUuid['vehicle-one-uuid'] = $vehicle;
    $controller->routeEngine                        = new FleetOpsRouteSequencingEngineFake();

    $response = $controller->run(Request::create('/orchestrator/run', 'POST', [
        'mode' => 'optimize_routes',
    ]));

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData(true)['summary'])->toBe(['engine' => 'route_sequence_fake'])
        ->and($order->relationLoaded('vehicle'))->toBeTrue()
        ->and($order->vehicle->driver->public_id)->toBe('driver_one')
        ->and($controller->routeEngine->calls)->toHaveCount(1);
});

test('orchestration run returns structured engine failures', function () {
    $registry = new OrchestrationEngineRegistry();
    $registry->register(new FleetOpsThrowingOrchestrationEngineFake());

    $controller               = new FleetOpsOrchestrationCommitControllerProbe($registry);
    $controller->orderQuery   = new FleetOpsOrchestrationQueryFake(collect([fleetopsOrchestrationOrder('order_one')]));
    $controller->vehicleQuery = new FleetOpsOrchestrationQueryFake(collect([fleetopsOrchestrationVehicle('vehicle_one')]));

    $response = $controller->run(Request::create('/orchestrator/run', 'POST', [
        'options' => ['engine' => 'throwing'],
    ]));

    expect($response->getStatusCode())->toBe(503)
        ->and($response->getData(true))->toMatchArray([
            'error'  => 'orchestration unavailable',
            'engine' => 'throwing',
        ]);
});

test('orchestration controller exposes engines and rejects empty commits before persistence', function () {
    $controller = fleetopsOrchestrationController();

    $engines = $controller->engines();
    $commit  = $controller->commit(Request::create('/orchestrator/commit', 'POST', [
        'assignments' => [],
    ]));

    expect($engines->getData(true))->toBe([
        'engines' => [
            [
                'id'    => 'greedy',
                'name'  => 'Greedy (built-in)',
            ],
        ],
    ])
        ->and($commit->getStatusCode())->toBe(422)
        ->and($commit->getData(true))->toBe(['error' => 'No assignments provided.']);
});

test('orchestration controller maps order config card fields with fallback lookups', function () {
    session(['company' => 'company-uuid']);

    $controller = fleetopsOrchestrationCommitController();

    $controller->fieldConfigs = [
        fleetopsOrchestrationOrderConfig('config-loaded', 'config_loaded', [
            'name' => 'Loaded Config',
            'key'  => 'loaded',
        ], [
            fleetopsOrchestrationCustomField([
                'name'     => 'eta_window',
                'label'    => 'ETA Window',
                'type'     => 'datetime',
                'required' => 1,
            ]),
            fleetopsOrchestrationCustomField([
                'name'     => null,
                'label'    => 'Special Instructions',
                'type'     => null,
                'required' => 0,
            ]),
        ]),
        fleetopsOrchestrationOrderConfig('config-fallback', 'config_fallback', [
            'name' => 'Fallback Config',
            'key'  => 'fallback',
        ]),
        fleetopsOrchestrationOrderConfig('config-empty', 'config_empty', [
            'name' => 'Empty Config',
            'key'  => 'empty',
        ]),
    ];

    $controller->fallbackCustomFields['config-fallback'] = [
        fleetopsOrchestrationCustomField([
            'name'     => 'dock_code',
            'label'    => null,
            'type'     => 'text',
            'required' => true,
        ]),
    ];

    $payload = $controller->orderConfigFields()->getData(true);

    expect($payload)->toBe([
        'configs' => [
            [
                'id'     => 'config_loaded',
                'uuid'   => 'config-loaded',
                'name'   => 'Loaded Config',
                'key'    => 'loaded',
                'fields' => [
                    [
                        'key'      => 'eta_window',
                        'label'    => 'ETA Window',
                        'type'     => 'datetime',
                        'required' => true,
                    ],
                    [
                        'key'      => 'special_instructions',
                        'label'    => 'Special Instructions',
                        'type'     => 'text',
                        'required' => false,
                    ],
                ],
            ],
            [
                'id'     => 'config_fallback',
                'uuid'   => 'config-fallback',
                'name'   => 'Fallback Config',
                'key'    => 'fallback',
                'fields' => [
                    [
                        'key'      => 'dock_code',
                        'label'    => 'dock_code',
                        'type'     => 'text',
                        'required' => true,
                    ],
                ],
            ],
        ],
    ]);
});

test('orchestration commit creates manifests stops assignments and waypoint ordering', function () {
    session(['company' => 'company-uuid']);

    $controller                          = fleetopsOrchestrationCommitController();
    $controller->vehicles['vehicle_one'] = fleetopsOrchestrationVehicle('vehicle_one');
    $controller->drivers['driver_one']   = fleetopsOrchestrationDriver('driver_one');
    $first                               = fleetopsOrchestrationOrder('order_b');
    $second                              = fleetopsOrchestrationOrder('order_a');
    $controller->orders                  = [
        'order_a' => $second,
        'order_b' => $first,
    ];

    $response = $controller->commit(Request::create('/orchestrator/commit', 'POST', [
        'scheduled_date' => '2026-08-01',
        'assignments'    => [
            [
                'order_id'          => 'missing_vehicle_order',
                'distance'          => 100,
                'duration'          => 10,
            ],
            [
                'vehicle_id'        => 'missing_vehicle',
                'order_id'          => 'missing_vehicle_order',
                'distance'          => 100,
                'duration'          => 10,
            ],
            [
                'vehicle_id'        => 'vehicle_one',
                'driver_id'         => 'driver_one',
                'order_id'          => 'missing_order',
                'sequence'          => 9,
                'distance'          => 300,
                'duration'          => 30,
            ],
            [
                'vehicle_id'        => 'vehicle_one',
                'driver_id'         => 'driver_one',
                'order_id'          => 'order_b',
                'sequence'          => 2,
                'arrival'           => 1785542400,
                'distance'          => 200,
                'duration'          => 20,
                'waypoint_sequence' => ['waypoint_b', 'waypoint_c'],
            ],
            [
                'vehicle_id'        => 'vehicle_one',
                'driver_id'         => 'driver_one',
                'order_id'          => 'order_a',
                'sequence'          => 1,
                'distance'          => 100,
                'duration'          => 10,
            ],
        ],
    ]));

    $payload = $response->getData(true);

    expect($response->getStatusCode())->toBe(200)
        ->and($payload['committed'])->toBe(['order_a', 'order_b'])
        ->and($payload['failed'])->toContain('missing_vehicle_order', 'missing_order')
        ->and($payload['manifests'])->toBe(['manifest_public_1'])
        ->and($controller->transactions)->toBe(['begin', 'commit'])
        ->and($controller->manifests)->toHaveCount(1)
        ->and($controller->manifests[0])->toMatchArray([
            'company_uuid'     => 'company-uuid',
            'vehicle_uuid'     => 'vehicle_one-uuid',
            'driver_uuid'      => 'driver_one-uuid',
            'scheduled_date'   => '2026-08-01',
            'total_distance_m' => 600,
            'total_duration_s' => 60,
            'stop_count'       => 3,
        ])
        ->and(array_column($controller->manifestStops, 'order_uuid'))->toBe(['order_a-uuid', 'order_b-uuid'])
        ->and($controller->manifestStops[1]['estimated_arrival']->timestamp)->toBe(1785542400)
        ->and($controller->waypointUpdates)->toBe([
            ['payload-order_b', 'waypoint_b', 0],
            ['payload-order_b', 'waypoint_c', 1],
        ])
        ->and($first->saved)->toBeTrue()
        ->and($first->vehicle_assigned_uuid)->toBe('vehicle_one-uuid')
        ->and($first->driver_assigned_uuid)->toBe('driver_one-uuid')
        ->and($first->is_route_optimized)->toBeTrue()
        ->and($second->saved)->toBeTrue()
        ->and($second->manifest_uuid)->toBe('manifest-1');
});

test('orchestration commit rolls back and reports persistence failures', function () {
    session(['company' => 'company-uuid']);

    $controller                          = fleetopsOrchestrationCommitController();
    $controller->vehicles['vehicle_one'] = fleetopsOrchestrationVehicle('vehicle_one');
    $controller->throwOnCreateManifest   = true;

    $response = $controller->commit(Request::create('/orchestrator/commit', 'POST', [
        'assignments' => [[
            'vehicle_id' => 'vehicle_one',
            'order_id'   => 'order_a',
        ]],
    ]));

    expect($response->getStatusCode())->toBe(500)
        ->and($response->getData(true))->toBe(['error' => 'Commit failed: manifest boom'])
        ->and($controller->transactions)->toBe(['begin', 'rollback']);
});

test('orchestration import helpers build place points and entity payloads from rows', function () {
    $controller = fleetopsOrchestrationController();

    $place = callOrchestrationControllerHelper($controller, 'buildPlaceData', [
        'pickup_name'        => 'Warehouse',
        'pickup_street1'     => '1 Depot Way',
        'pickup_street2'     => 'Dock 4',
        'pickup_city'        => 'Singapore',
        'pickup_state'       => 'SG',
        'pickup_postal_code' => '018956',
        'pickup_country'     => 'SG',
        'pickup_phone'       => '+6512345678',
        'pickup_lat'         => '1.3001',
        'pickup_lng'         => '103.8002',
    ], 'pickup');

    $emptyPoint = callOrchestrationControllerHelper($controller, 'buildLocationPoint', '', '103.8');
    $zeroPoint  = callOrchestrationControllerHelper($controller, 'buildLocationPoint', '0', '0');
    $point      = callOrchestrationControllerHelper($controller, 'buildLocationPoint', '1.5', '103.9');
    $empty      = callOrchestrationControllerHelper($controller, 'buildEntityData', [], 'company-uuid');
    $entity     = callOrchestrationControllerHelper($controller, 'buildEntityData', [
        'entity_name'            => 'Parcel',
        'entity_type'            => 'box',
        'entity_description'     => 'Fragile parts',
        'entity_sku'             => 'SKU-1',
        'entity_barcode'         => 'BAR-1',
        'entity_internal_id'     => 'INT-1',
        'entity_declared_value'  => '12.34',
        'entity_currency'        => 'SGD',
        'entity_price'           => '15.5',
        'entity_sale_price'      => '14.5',
        'entity_weight'          => '2.75',
        'entity_weight_unit'     => 'kg',
        'entity_length'          => '10',
        'entity_width'           => '20',
        'entity_height'          => '30',
        'entity_dimensions_unit' => 'cm',
    ], 'company-uuid');

    expect($place)->toBe([
        'name'        => 'Warehouse',
        'street1'     => '1 Depot Way',
        'street2'     => 'Dock 4',
        'city'        => 'Singapore',
        'province'    => 'SG',
        'postal_code' => '018956',
        'country'     => 'SG',
        'phone'       => '+6512345678',
        'location'    => 'POINT(103.8002 1.3001)',
    ])
        ->and($emptyPoint)->toBeNull()
        ->and($zeroPoint)->toBeNull()
        ->and($point)->toBe('POINT(103.9 1.5)')
        ->and($empty)->toBeNull()
        ->and($entity)->toMatchArray([
            'company_uuid'    => 'company-uuid',
            'name'            => 'Parcel',
            'type'            => 'box',
            'description'     => 'Fragile parts',
            'sku'             => 'SKU-1',
            'barcode'         => 'BAR-1',
            'internal_id'     => 'INT-1',
            'declared_value'  => 12.34,
            'currency'        => 'SGD',
            'price'           => 15.5,
            'sale_price'      => 14.5,
            'weight'          => 2.75,
            'weight_unit'     => 'kg',
            'length'          => 10.0,
            'width'           => 20.0,
            'height'          => 30.0,
            'dimensions_unit' => 'cm',
        ]);
});

class FleetOpsPublicOrchestrationProbe extends Fleetbase\FleetOps\Http\Controllers\Api\v1\OrchestrationController
{
    public ?FleetOpsOrchestrationQueryFake $orderQuery   = null;
    public ?FleetOpsOrchestrationQueryFake $vehicleQuery = null;

    protected function companyUuid(): ?string
    {
        return 'company-uuid';
    }

    protected function orchestrationRunOrdersQuery(?string $companyUuid): mixed
    {
        return $this->orderQuery ??= new FleetOpsOrchestrationQueryFake();
    }

    protected function orchestrationRunVehiclesQuery(?string $companyUuid): mixed
    {
        return $this->vehicleQuery ??= new FleetOpsOrchestrationQueryFake();
    }

    public function callSanitize(mixed $payload): mixed
    {
        return $this->sanitizePublicPayload($payload);
    }
}

test('public orchestration wrappers sanitize run and commit payloads', function () {
    $registry = new OrchestrationEngineRegistry();
    $registry->register(new GreedyOrchestrationEngine());
    $controller = new FleetOpsPublicOrchestrationProbe($registry);

    $run = $controller->run(Request::create('/v1/orchestration/run', 'POST', ['mode' => 'assign_vehicles']));
    expect($run->getStatusCode())->toBe(200)
        ->and($run->getData(true)['message'] ?? '')->toContain('No orders');

    $commit = $controller->commit(Request::create('/v1/orchestration/commit', 'POST', []));
    expect($commit)->not->toBeNull();

    // Internal identifier keys are stripped recursively from public payloads
    $sanitized = $controller->callSanitize(['uuid' => 'x', 'nested' => ['company_uuid' => 'y', 'name' => 'keep'], 'name' => 'top']);
    expect($sanitized)->not->toHaveKey('uuid')
        ->and($sanitized['nested'] ?? [])->not->toHaveKey('company_uuid')
        ->and($sanitized['name'] ?? null)->toBe('top');
});

test('run modes scope orders by vehicle assignment and drivers by public id', function () {
    // optimize only considers orders that already have a vehicle assigned
    $optimize               = fleetopsOrchestrationCommitController();
    $optimize->orderQuery   = new FleetOpsOrchestrationQueryFake();
    $optimize->vehicleQuery = new FleetOpsOrchestrationQueryFake();
    $optimize->run(Request::create('/orchestrator/run', 'POST', ['mode' => 'optimize']));
    expect(array_column($optimize->orderQuery->calls, 0))->toContain('whereNotNull');

    // standalone assign_drivers takes every driverless order regardless of vehicle
    $assign               = fleetopsOrchestrationCommitController();
    $assign->orderQuery   = new FleetOpsOrchestrationQueryFake();
    $assign->vehicleQuery = new FleetOpsOrchestrationQueryFake();
    $assign->run(Request::create('/orchestrator/run', 'POST', ['mode' => 'assign_drivers']));
    expect(array_column($assign->orderQuery->calls, 0))->toContain('whereNull');

    // driver ids narrow the vehicle pool through the driver relation
    $order                  = fleetopsOrchestrationOrder('order_one');
    $byDriver               = fleetopsOrchestrationCommitController();
    $byDriver->orderQuery   = new FleetOpsOrchestrationQueryFake(collect([$order]));
    $byDriver->vehicleQuery = new FleetOpsOrchestrationQueryFake();
    $byDriver->run(Request::create('/orchestrator/run', 'POST', [
        'mode'       => 'assign_vehicles',
        'driver_ids' => ['driver_one'],
    ]));
    expect(array_column($byDriver->vehicleQuery->calls, 0))->toContain('whereHas');
});

test('assign drivers skips orders without a prior assignment entry', function () {
    $matched   = fleetopsOrchestrationOrder('order_one');
    $unmatched = fleetopsOrchestrationOrder('order_two');
    $driver    = fleetopsOrchestrationDriver('driver_one');
    $vehicle   = fleetopsOrchestrationVehicle('vehicle_one');
    $vehicle->setRelation('driver', fleetopsOrchestrationDriver('old_driver'));

    $controller                          = fleetopsOrchestrationCommitController();
    $controller->orderQuery              = new FleetOpsOrchestrationQueryFake(collect([$matched, $unmatched]));
    $controller->vehicleQuery            = new FleetOpsOrchestrationQueryFake(collect([$vehicle]));
    $controller->vehicles['vehicle_one'] = $vehicle;
    $controller->driverMap               = ['driver_one' => $driver];
    $controller->driverEngine            = new FleetOpsDriverAssignmentEngineFake();

    $controller->run(Request::create('/orchestrator/run', 'POST', [
        'mode'              => 'assign_drivers',
        'prior_assignments' => [
            ['order_id' => 'order_one', 'vehicle_id' => 'vehicle_one', 'driver_id' => 'driver_one'],
        ],
    ]));

    // Only the order named in prior_assignments is augmented
    expect($matched->vehicle_assigned_uuid)->toBe('vehicle_one-uuid')
        ->and($unmatched->vehicle_assigned_uuid)->toBeNull();
});
