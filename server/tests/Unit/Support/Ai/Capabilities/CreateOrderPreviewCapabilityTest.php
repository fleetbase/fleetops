<?php

use Fleetbase\Ai\Models\AiTask;
use Fleetbase\FleetOps\Http\Controllers\Internal\v1\OrderController;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\OrderConfig;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\FleetOps\Support\Ai\Capabilities\CreateOrderPreviewCapability;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FleetOpsCreateOrderPreviewCapabilityProbe extends CreateOrderPreviewCapability
{
    public bool $authorized = true;
    public ?OrderConfig $orderConfig;
    public ?Driver $driver                                            = null;
    public ?Vehicle $vehicle                                          = null;
    public array $promptDraft                                         = [];
    public array $resolvedPlaces                                      = [];
    public ?FleetOpsCreateOrderPreviewOrderControllerFake $controller = null;

    public function __construct()
    {
        $this->orderConfig = new OrderConfig();
        $this->orderConfig->setRawAttributes([
            'uuid' => 'order-config-uuid',
            'key'  => 'transport',
            'name' => 'Transport',
        ], true);
    }

    public function exposeBuildDraft(AiTask $task, array $input = []): array
    {
        return $this->buildDraft($task, $input);
    }

    public function exposePromptMatches(string $prompt): bool
    {
        return $this->matchesPrompt($prompt);
    }

    protected function can(string $permission): bool
    {
        return $permission === 'fleet-ops create order' && $this->authorized;
    }

    protected function draftFromPrompt(string $prompt): array
    {
        return $this->promptDraft ?: [
            'order_config_uuid' => 'order-config-uuid',
            'type'              => 'transport',
            'payload'           => [],
            'dispatched'        => false,
        ];
    }

    protected function resolvePlace(?string $query): ?array
    {
        return $this->resolvedPlaces[$query] ?? null;
    }

    protected function resolveOrderConfig(array $draft): ?OrderConfig
    {
        return $this->orderConfig;
    }

    protected function resolveDriver(array $draft): ?Driver
    {
        return $this->driver;
    }

    protected function resolveVehicle(array $draft): ?Vehicle
    {
        return $this->vehicle;
    }

    protected function orderController(): OrderController
    {
        return $this->controller ??= new FleetOpsCreateOrderPreviewOrderControllerFake();
    }
}

class FleetOpsCreateOrderPreviewOrderControllerFake extends OrderController
{
    public array $requests = [];
    public mixed $response;

    public function __construct()
    {
        $this->response = (object) [
            'order' => (object) [
                'public_id' => 'order_public_id',
                'uuid'      => 'order-uuid',
            ],
        ];
    }

    public function createRecord(Request $request)
    {
        $this->requests[] = $request;

        return $this->response;
    }
}

function fleetopsCreateOrderAiTask(string $prompt = 'Create a new Fleet-Ops order', array $metadata = []): AiTask
{
    return new AiTask([
        'prompt'   => $prompt,
        'metadata' => $metadata,
    ]);
}

class FleetOpsCreateOrderPreviewDriverFake extends Driver
{
    public function getNameAttribute(): string
    {
        return 'Ada Dispatcher';
    }
}

class FleetOpsCreateOrderPreviewVehicleFake extends Vehicle
{
    public function getDisplayNameAttribute(): string
    {
        return 'Sprinter 12';
    }
}

function fleetopsCreateOrderDriver(): Driver
{
    $driver = new FleetOpsCreateOrderPreviewDriverFake();
    $driver->setRawAttributes([
        'uuid' => 'driver-uuid',
    ], true);

    return $driver;
}

function fleetopsCreateOrderVehicle(): Vehicle
{
    $vehicle = new FleetOpsCreateOrderPreviewVehicleFake();
    $vehicle->setRawAttributes([
        'uuid' => 'vehicle-uuid',
    ], true);

    return $vehicle;
}

test('create order capability exposes action metadata and prompt matching', function () {
    $capability = new FleetOpsCreateOrderPreviewCapabilityProbe();

    expect($capability->key())->toBe('fleet-ops.create_order')
        ->and($capability->label())->toBe('Create Fleet-Ops order preview')
        ->and($capability->description())->toContain('Fleet-Ops order creation drafts')
        ->and($capability->type())->toBe('action')
        ->and($capability->mode())->toBe('confirmation_required')
        ->and($capability->permissions())->toBe(['fleet-ops create order'])
        ->and($capability->previewOnly())->toBeFalse()
        ->and($capability->executable())->toBeTrue()
        ->and($capability->module())->toBe('fleet-ops')
        ->and($capability->inputSchema())->toHaveKeys(['order_config_uuid', 'payload.pickup_uuid', 'payload.dropoff_uuid', 'payload.waypoints', 'customer', 'scheduled_at', 'dispatched'])
        ->and($capability->shouldPreview(fleetopsCreateOrderAiTask('Please create a new order')))->toBeTrue()
        ->and($capability->shouldResolve(fleetopsCreateOrderAiTask('Please create a new order')))->toBeTrue()
        ->and($capability->exposePromptMatches('show me driver status'))->toBeFalse();
});

test('create order preview prepares a ready authorized draft with resolved resources', function () {
    $capability                 = new FleetOpsCreateOrderPreviewCapabilityProbe();
    $capability->driver         = fleetopsCreateOrderDriver();
    $capability->vehicle        = fleetopsCreateOrderVehicle();
    $capability->resolvedPlaces = [
        '16 Simon Walk' => [
            'uuid'      => 'pickup-uuid',
            'name'      => '16 Simon Walk',
            'address'   => '16 Simon Walk',
            'latitude'  => 1.3621,
            'longitude' => 103.8845,
        ],
        '18 Hougang Ave' => [
            'uuid'      => 'dropoff-uuid',
            'name'      => '18 Hougang Ave',
            'address'   => '18 Hougang Ave',
            'latitude'  => 1.3701,
            'longitude' => 103.8912,
        ],
    ];
    $capability->promptDraft = [
        'payload' => [
            'pickup_query'  => '16 Simon Walk',
            'dropoff_query' => '18 Hougang Ave',
            'waypoints'     => [
                ['address' => 'Midpoint', 'latitude' => 1.365, 'longitude' => 103.887],
            ],
        ],
        'driver_query'  => 'Ada',
        'vehicle_query' => 'Sprinter',
        'scheduled_at'  => '2026-07-30T10:00:00+08:00',
        'dispatched'    => true,
    ];

    $preview = $capability->preview(fleetopsCreateOrderAiTask());

    expect($preview['action'])->toBe('fleet-ops.create_order')
        ->and($preview['authorized'])->toBeTrue()
        ->and($preview['ready'])->toBeTrue()
        ->and($preview['apply_label'])->toBe('Create order')
        ->and($preview['draft']['order_config_uuid'])->toBe('order-config-uuid')
        ->and($preview['draft']['type'])->toBe('transport')
        ->and($preview['draft']['driver'])->toBe('driver-uuid')
        ->and($preview['draft']['driver_assigned_uuid'])->toBe('driver-uuid')
        ->and($preview['draft']['vehicle_assigned_uuid'])->toBe('vehicle-uuid')
        ->and($preview['draft']['payload']['pickup_uuid'])->toBe('pickup-uuid')
        ->and($preview['draft']['payload']['dropoff_uuid'])->toBe('dropoff-uuid')
        ->and($preview['missing_fields'])->toBe([])
        ->and(collect($preview['fields'])->pluck('value')->all())->toContain('Transport', 'Ada Dispatcher', 'Sprinter 12', 'Yes')
        ->and(collect($preview['route_preview']['stops'])->pluck('role')->all())->toBe(['pickup', 'waypoint', 'dropoff'])
        ->and($preview['route_preview']['coordinates'])->toHaveCount(3)
        ->and($preview['options']['pod_methods'])->toBe(['scan', 'signature', 'photo']);
});

test('create order preview reports missing authorization config and route fields', function () {
    $capability              = new FleetOpsCreateOrderPreviewCapabilityProbe();
    $capability->authorized  = false;
    $capability->orderConfig = null;
    $capability->promptDraft = ['payload' => []];

    $preview = $capability->resolve(fleetopsCreateOrderAiTask('Make a new order'));

    expect($preview['ready'])->toBeFalse()
        ->and($preview['authorized'])->toBeFalse()
        ->and($preview['missing_fields'])->toBe([
            'permission to create Fleet-Ops orders',
            'order configuration',
            'pickup address or place',
            'dropoff address or place',
        ])
        ->and($preview['message'])->toContain('after these required details are resolved');
});

test('create order draft merges prompt metadata and input while normalizing places', function () {
    $capability              = new FleetOpsCreateOrderPreviewCapabilityProbe();
    $capability->promptDraft = [
        'payload' => [
            'pickup_query'  => 'Prompt pickup',
            'dropoff_query' => 'Prompt dropoff',
        ],
        'scheduled_at' => '',
        'dispatched'   => '0',
    ];
    $capability->resolvedPlaces = [
        'Input pickup' => [
            'uuid'      => 'input-pickup-uuid',
            'address'   => 'Input pickup',
            'latitude'  => 1.31,
            'longitude' => 103.81,
        ],
    ];
    $task = fleetopsCreateOrderAiTask('Create order', [
        'action_previews' => [
            [
                'draft' => [
                    'payload' => [
                        'pickup_query'  => 'Metadata pickup',
                        'dropoff_query' => 'Metadata dropoff',
                    ],
                    'notes' => 'metadata note',
                ],
            ],
        ],
    ]);

    $draft = $capability->exposeBuildDraft($task, [
        'draft' => [
            'payload' => [
                'pickup_query'  => 'Input pickup',
                'dropoff_query' => 'Unresolved dropoff',
            ],
            'dispatched' => 'true',
        ],
    ]);

    expect($draft['payload']['pickup_query'])->toBe('Input pickup')
        ->and($draft['payload']['pickup_uuid'])->toBe('input-pickup-uuid')
        ->and($draft['payload']['pickup']['address'])->toBe('Input pickup')
        ->and($draft['payload']['dropoff_query'])->toBe('Unresolved dropoff')
        ->and($draft['payload']['dropoff'])->toMatchArray([
            'uuid'    => null,
            'address' => 'Unresolved dropoff',
            'source'  => 'unresolved',
        ])
        ->and($draft['notes'])->toBe('metadata note')
        ->and($draft['dispatched'])->toBeTrue()
        ->and(array_key_exists('scheduled_at', $draft))->toBeFalse()
        ->and(array_key_exists('dropoff_uuid', $draft['payload']))->toBeFalse();
});

test('create order apply rejects unauthorized and incomplete previews', function () {
    $capability             = new FleetOpsCreateOrderPreviewCapabilityProbe();
    $capability->authorized = false;

    expect(fn () => $capability->apply(fleetopsCreateOrderAiTask(), ['ready' => true]))
        ->toThrow(RuntimeException::class, 'permission to create Fleet-Ops orders');

    $capability->authorized = true;

    expect(fn () => $capability->apply(fleetopsCreateOrderAiTask(), ['ready' => false]))
        ->toThrow(RuntimeException::class, 'missing required fields');
});

test('create order apply sanitizes draft and reports created order resource', function () {
    $controller             = new FleetOpsCreateOrderPreviewOrderControllerFake();
    $capability             = new class($controller) extends FleetOpsCreateOrderPreviewCapabilityProbe {
        public function __construct(public FleetOpsCreateOrderPreviewOrderControllerFake $fakeController)
        {
            parent::__construct();
        }

        protected function hasExistingPlaceUuid($uuid): bool
        {
            return in_array($uuid, ['pickup-uuid', 'dropoff-uuid'], true);
        }

        protected function orderController(): OrderController
        {
            return $this->fakeController;
        }
    };

    $result = $capability->apply(fleetopsCreateOrderAiTask(), [
        'ready' => true,
        'draft' => [
            'payload' => [
                'pickup_uuid'  => 'pickup-uuid',
                'dropoff_uuid' => 'dropoff-uuid',
                'pickup'       => ['uuid' => 'pickup-uuid'],
                'dropoff'      => ['uuid' => 'dropoff-uuid'],
                'return'       => ['address' => 'Return address'],
            ],
            'payload_meta' => 'kept',
        ],
    ], [
        'draft' => [
            'payload' => [
                'dropoff' => ['uuid' => 'dropoff-uuid', 'address' => 'Input override'],
            ],
        ],
    ]);

    $submitted = $controller->requests[0]->input('order');

    expect($submitted['payload'])->toHaveKeys(['pickup_uuid', 'dropoff_uuid', 'return'])
        ->and(array_key_exists('pickup', $submitted['payload']))->toBeFalse()
        ->and(array_key_exists('dropoff', $submitted['payload']))->toBeFalse()
        ->and($result)->toMatchArray([
            'action'   => 'fleet-ops.create_order',
            'status'   => 'completed',
            'resource' => [
                'type'   => 'order',
                'id'     => 'order_public_id',
                'uuid'   => 'order-uuid',
                'route'  => 'console.fleet-ops.operations.orders.index.details',
                'models' => ['order_public_id'],
            ],
        ]);
});

test('create order apply raises controller error responses', function () {
    $controller           = new FleetOpsCreateOrderPreviewOrderControllerFake();
    $controller->response = new JsonResponse(['error' => 'Invalid order payload'], 422);
    $capability           = new class($controller) extends FleetOpsCreateOrderPreviewCapabilityProbe {
        public function __construct(public FleetOpsCreateOrderPreviewOrderControllerFake $fakeController)
        {
            parent::__construct();
        }

        protected function orderController(): OrderController
        {
            return $this->fakeController;
        }
    };

    expect(fn () => $capability->apply(fleetopsCreateOrderAiTask(), ['ready' => true, 'draft' => ['payload' => []]]))
        ->toThrow(RuntimeException::class, 'Invalid order payload');
});
