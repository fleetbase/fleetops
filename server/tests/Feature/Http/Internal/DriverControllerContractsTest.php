<?php

use Fleetbase\FleetOps\Http\Controllers\Internal\v1\DriverController;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

class FleetOpsInternalDriverAssignmentControllerProbe extends DriverController
{
    public FleetOpsInternalDriverAssignmentDriverFake $driver;
    public FleetOpsInternalDriverAssignmentOrderFake $order;
    public FleetOpsInternalDriverAssignmentVehicleFake $vehicle;
    public FleetOpsInternalDriverAssignmentOrderCollectionFake $orders;
    public ?Order $currentAssignedOrder = null;
    public array $clearedOrderUuids     = [];
    public int $transactions            = 0;

    protected function findDriver(?string $id): Driver
    {
        $this->driver->setAttribute('lookup_id', $id);

        return $this->driver;
    }

    protected function findOrder(?string $id): Order
    {
        $this->order->setAttribute('lookup_id', $id);

        return $this->order;
    }

    protected function findVehicle(?string $id): Vehicle
    {
        $this->vehicle->setAttribute('lookup_id', $id);

        return $this->vehicle;
    }

    protected function assignedOrdersForDriver(Driver $driver)
    {
        return $this->orders;
    }

    protected function selectedAssignedOrdersForDriver(Driver $driver, $ids)
    {
        $this->orders->selectedIds = $ids->all();

        return $this->orders;
    }

    protected function currentAssignedOrderForDriver(Driver $driver): ?Order
    {
        return $this->currentAssignedOrder;
    }

    protected function clearDriverAssignmentsForOrders($orders): void
    {
        $this->clearedOrderUuids = $orders->pluck('uuid')->all();
    }

    protected function freshOrders($orders, array $with)
    {
        $orders->freshWith = $with;

        return $orders;
    }

    protected function indexOrderCollection($orders): array
    {
        return $orders->map(fn ($order) => [
            'resource' => 'order',
            'uuid'     => $order->uuid,
        ])->all();
    }

    protected function runTransaction(callable $callback): mixed
    {
        $this->transactions++;

        return $callback();
    }

    protected function jsonResponse(array $payload): JsonResponse
    {
        return response()->json($payload);
    }

    protected function errorResponse(string $message): JsonResponse
    {
        return response()->json(['error' => $message], 400);
    }
}

class FleetOpsInternalDriverAssignmentDriverFake extends Driver
{
    public array $updates          = [];
    public array $assignedVehicles = [];
    public bool $currentJobCleared = false;

    public function update(array $attributes = [], array $options = []): bool
    {
        $this->updates[] = $attributes;
        $this->forceFill($attributes);

        return true;
    }

    public function assignVehicle(Vehicle $vehicle): Driver
    {
        $this->assignedVehicles[] = $vehicle;
        $this->forceFill(['vehicle_uuid' => $vehicle->uuid]);

        return $this;
    }

    public function fresh($with = [])
    {
        return [
            'resource' => 'driver',
            'uuid'     => $this->uuid,
            'with'     => $with,
        ];
    }

    public function unassignCurrentJob(): bool
    {
        $this->currentJobCleared = true;
        $this->forceFill(['current_job_uuid' => null]);

        return true;
    }
}

class FleetOpsInternalDriverAssignmentOrderFake extends Order
{
    public bool $hasDriverAssignedForTest = false;
    public bool $driverAlreadyAssigned    = false;
    public array $assignedDrivers         = [];
    public array $updates                 = [];

    public function getHasDriverAssignedAttribute(): bool
    {
        return $this->hasDriverAssignedForTest;
    }

    public function isDriver($driver): bool
    {
        return $this->driverAlreadyAssigned;
    }

    public function assignDriver($driver, $silent = false)
    {
        $this->assignedDrivers[] = $driver;
        $this->forceFill(['driver_assigned_uuid' => $driver->uuid]);
    }

    public function update(array $attributes = [], array $options = []): bool
    {
        $this->updates[] = $attributes;
        $this->forceFill($attributes);

        return true;
    }

    public function fresh($with = [])
    {
        return [
            'resource' => 'order',
            'uuid'     => $this->uuid,
            'with'     => $with,
        ];
    }
}

class FleetOpsInternalDriverAssignmentOrderCollectionFake extends Collection
{
    public array $selectedIds = [];
    public array $freshWith   = [];
}

class FleetOpsInternalDriverAssignmentVehicleFake extends Vehicle
{
    public function fresh($with = [])
    {
        return [
            'resource' => 'vehicle',
            'uuid'     => $this->uuid,
            'with'     => $with,
        ];
    }
}

class FleetOpsInternalDriverAuthControllerProbe extends DriverController
{
    public static ?User $loginUser          = null;
    public static ?User $verificationUser   = null;
    public static ?Driver $loginDriver      = null;
    public static bool $verificationExists  = false;
    public static bool $tokenShouldFail     = false;
    public static array $loginPhones        = [];
    public static array $verifications      = [];
    public static array $verificationChecks = [];
    public static array $driverLookups      = [];
    public static array $tokens             = [];

    public function __construct()
    {
        $this->resource = FleetOpsInternalDriverResourceFake::class;
    }

    public static function resetProbe(): void
    {
        static::$loginUser          = null;
        static::$verificationUser   = null;
        static::$loginDriver        = null;
        static::$verificationExists = false;
        static::$tokenShouldFail    = false;
        static::$loginPhones        = [];
        static::$verifications      = [];
        static::$verificationChecks = [];
        static::$driverLookups      = [];
        static::$tokens             = [];
    }

    protected static function findLoginUserByPhone(string $phone): ?User
    {
        static::$loginPhones[] = $phone;

        return static::$loginUser;
    }

    protected static function generateDriverLoginVerification(User $user): void
    {
        static::$verifications[] = ['user' => $user->uuid, 'for' => 'driver_login'];
    }

    protected static function findVerificationUser(string $identity): ?User
    {
        static::$verificationChecks[] = ['identity' => $identity];

        return static::$verificationUser;
    }

    protected static function verificationCodeExists(User $user, ?string $code, string $for): bool
    {
        static::$verificationChecks[] = ['user' => $user->uuid, 'code' => $code, 'for' => $for];

        return static::$verificationExists;
    }

    protected static function findLoginDriverForUser(User $user): ?Driver
    {
        static::$driverLookups[] = $user->uuid;

        return static::$loginDriver;
    }

    protected static function createDriverToken(User $user, Driver $driver)
    {
        static::$tokens[] = ['user' => $user->uuid, 'driver' => $driver->uuid];

        if (static::$tokenShouldFail) {
            throw new RuntimeException('Token service unavailable.');
        }

        return (object) ['plainTextToken' => 'plain-token'];
    }
}

class FleetOpsInternalDriverResourceFake extends JsonResource
{
    public function toArray($request)
    {
        return [
            'resource' => 'driver',
            'uuid'     => $this->resource->uuid,
            'token'    => $this->resource->token,
        ];
    }
}

function fleetopsInternalDriverAssignmentController(): FleetOpsInternalDriverAssignmentControllerProbe
{
    $controller          = new FleetOpsInternalDriverAssignmentControllerProbe();
    $controller->driver  = new FleetOpsInternalDriverAssignmentDriverFake();
    $controller->order   = new FleetOpsInternalDriverAssignmentOrderFake();
    $controller->vehicle = new FleetOpsInternalDriverAssignmentVehicleFake();
    $controller->orders  = new FleetOpsInternalDriverAssignmentOrderCollectionFake();

    $controller->driver->setRawAttributes([
        'uuid'             => 'driver-uuid',
        'public_id'        => 'driver-public',
        'current_job_uuid' => null,
    ], true);
    $controller->order->setRawAttributes([
        'uuid'      => 'order-uuid',
        'public_id' => 'order-public',
    ], true);
    $controller->vehicle->setRawAttributes([
        'uuid'      => 'vehicle-uuid',
        'public_id' => 'vehicle-public',
    ], true);

    return $controller;
}

function fleetopsInternalDriverAssignmentOrder(string $uuid, string $publicId): FleetOpsInternalDriverAssignmentOrderFake
{
    $order = new FleetOpsInternalDriverAssignmentOrderFake();
    $order->setRawAttributes([
        'uuid'      => $uuid,
        'public_id' => $publicId,
    ], true);

    return $order;
}

function fleetopsInternalDriverAuthUser(string $uuid = 'user-uuid'): User
{
    $user = new User();
    $user->setRawAttributes([
        'uuid'  => $uuid,
        'phone' => '+15551234567',
        'email' => 'driver@example.com',
    ], true);

    return $user;
}

function fleetopsInternalDriverAuthDriver(string $uuid = 'driver-uuid'): Driver
{
    $driver = new Driver();
    $driver->setRawAttributes([
        'uuid'      => $uuid,
        'user_uuid' => 'user-uuid',
    ], true);

    return $driver;
}

test('internal driver controller assigns an order from route or request identifiers', function () {
    $controller = fleetopsInternalDriverAssignmentController();

    $response = $controller->assignOrder(new Request([
        'order' => 'order-public',
    ]), 'driver-route-id');

    expect($response->getData(true))->toMatchArray([
        'status'  => 'ok',
        'message' => 'Driver assigned',
        'driver'  => [
            'resource' => 'driver',
            'uuid'     => 'driver-uuid',
            'with'     => ['vehicle', 'currentOrder'],
        ],
        'order' => [
            'resource' => 'order',
            'uuid'     => 'order-uuid',
            'with'     => ['driverAssigned'],
        ],
    ])
        ->and($controller->driver->lookup_id)->toBe('driver-route-id')
        ->and($controller->order->lookup_id)->toBe('order-public')
        ->and($controller->order->assignedDrivers)->toBe([$controller->driver])
        ->and($controller->driver->updates)->toBe([['current_job_uuid' => 'order-uuid']]);

    $controller = fleetopsInternalDriverAssignmentController();
    $controller->assignOrder(new Request([
        'driver' => 'driver-body-id',
        'order'  => 'order-body-id',
    ]));

    expect($controller->driver->lookup_id)->toBe('driver-body-id')
        ->and($controller->order->lookup_id)->toBe('order-body-id');
});

test('internal driver controller rejects duplicate order assignments', function () {
    $controller                                  = fleetopsInternalDriverAssignmentController();
    $controller->order->hasDriverAssignedForTest = true;

    expect($controller->assignOrder(new Request([
        'driver' => 'driver-public',
        'order'  => 'order-public',
    ]))->getData(true))->toMatchArray([
        'error' => 'A driver is already assigned to this order.',
    ]);

    $controller                               = fleetopsInternalDriverAssignmentController();
    $controller->order->driverAlreadyAssigned = true;

    expect($controller->assignOrder(new Request([
        'driver' => 'driver-public',
        'order'  => 'order-public',
    ]))->getData(true))->toMatchArray([
        'error' => 'The driver is already assigned to this order.',
    ]);
});

test('internal driver controller assigns and unassigns vehicles', function () {
    $controller = fleetopsInternalDriverAssignmentController();

    $assigned = $controller->assignVehicle(new Request([
        'vehicle' => 'vehicle-public',
    ]), 'driver-public');

    expect($assigned->getData(true))->toMatchArray([
        'status'  => 'ok',
        'message' => 'Vehicle assigned to driver.',
        'driver'  => [
            'resource' => 'driver',
            'uuid'     => 'driver-uuid',
            'with'     => ['vehicle', 'currentOrder'],
        ],
        'vehicle' => [
            'resource' => 'vehicle',
            'uuid'     => 'vehicle-uuid',
            'with'     => ['driver'],
        ],
    ])
        ->and($controller->driver->assignedVehicles)->toBe([$controller->vehicle])
        ->and($controller->vehicle->lookup_id)->toBe('vehicle-public');

    $controller->driver->setRelation('vehicle', $controller->vehicle);

    $unassigned = $controller->unassignVehicle('driver-public');

    expect($unassigned->getData(true))->toMatchArray([
        'status'  => 'ok',
        'message' => 'Vehicle unassigned from driver.',
        'driver'  => [
            'resource' => 'driver',
            'uuid'     => 'driver-uuid',
            'with'     => ['vehicle', 'currentOrder'],
        ],
        'vehicle' => [
            'resource' => 'vehicle',
            'uuid'     => 'vehicle-uuid',
            'with'     => ['driver'],
        ],
    ])
        ->and($controller->driver->updates)->toContain(['vehicle_uuid' => null]);
});

test('internal driver controller lists assigned orders for a driver', function () {
    $controller                           = fleetopsInternalDriverAssignmentController();
    $controller->driver->current_job_uuid = 'order-2';
    $controller->orders                   = new FleetOpsInternalDriverAssignmentOrderCollectionFake([
        fleetopsInternalDriverAssignmentOrder('order-1', 'order_public_1'),
        fleetopsInternalDriverAssignmentOrder('order-2', 'order_public_2'),
    ]);

    $response = $controller->assignedOrders('driver-public')->getData(true);

    expect($response)->toMatchArray([
        'status'  => 'ok',
        'driver'  => [
            'resource' => 'driver',
            'uuid'     => 'driver-uuid',
            'with'     => ['vehicle', 'currentOrder'],
        ],
        'orders' => [
            ['resource' => 'order', 'uuid' => 'order-1'],
            ['resource' => 'order', 'uuid' => 'order-2'],
        ],
        'current' => 'order-2',
        'count'   => 2,
    ])
        ->and($controller->driver->lookup_id)->toBe('driver-public');
});

test('internal driver controller unassigns selected assigned orders and clears current job', function () {
    $controller                           = fleetopsInternalDriverAssignmentController();
    $controller->driver->current_job_uuid = 'order-2';
    $controller->orders                   = new FleetOpsInternalDriverAssignmentOrderCollectionFake([
        fleetopsInternalDriverAssignmentOrder('order-1', 'order_public_1'),
        fleetopsInternalDriverAssignmentOrder('order-2', 'order_public_2'),
    ]);

    $response = $controller->unassignOrders(new Request([
        'orders' => ['order-1', 'order-1', 'order_public_2', null],
    ]), 'driver-public')->getData(true);

    expect($response)->toMatchArray([
        'status'  => 'ok',
        'message' => 'Driver unassigned from selected orders.',
        'orders'  => [
            ['resource' => 'order', 'uuid' => 'order-1'],
            ['resource' => 'order', 'uuid' => 'order-2'],
        ],
        'count' => 2,
    ])
        ->and($controller->orders->selectedIds)->toBe(['order-1', 'order_public_2'])
        ->and($controller->clearedOrderUuids)->toBe(['order-1', 'order-2'])
        ->and($controller->orders->freshWith)->toBe(['driverAssigned', 'vehicleAssigned'])
        ->and($controller->transactions)->toBe(1)
        ->and($controller->driver->updates)->toContain(['current_job_uuid' => null]);
});

test('internal driver controller reports empty assigned order selections', function () {
    $controller         = fleetopsInternalDriverAssignmentController();
    $controller->orders = new FleetOpsInternalDriverAssignmentOrderCollectionFake();

    $response = $controller->unassignOrders(new Request([
        'orders' => ['missing-order'],
    ]), 'driver-public');

    expect($response->getStatusCode())->toBe(400)
        ->and($response->getData(true))->toBe([
            'error' => 'No assigned orders were selected for this driver.',
        ]);
});

test('internal driver controller unassigns the current order fallback', function () {
    $controller                       = fleetopsInternalDriverAssignmentController();
    $controller->currentAssignedOrder = null;
    $currentOrder                     = fleetopsInternalDriverAssignmentOrder('current-order', 'current_order_public');

    $controller->driver = new class extends FleetOpsInternalDriverAssignmentDriverFake {
        public ?Order $currentOrderForTest = null;

        public function getCurrentOrder(): ?Order
        {
            return $this->currentOrderForTest;
        }
    };
    $controller->driver->currentOrderForTest = $currentOrder;
    $controller->driver->setRawAttributes([
        'uuid'             => 'driver-uuid',
        'public_id'        => 'driver-public',
        'current_job_uuid' => 'current-order',
    ], true);

    $response = $controller->unassignOrder('driver-public')->getData(true);

    expect($response)->toMatchArray([
        'status'  => 'ok',
        'message' => 'Driver unassigned from order.',
        'order'   => [
            'resource' => 'order',
            'uuid'     => 'current-order',
            'with'     => ['driverAssigned'],
        ],
    ])
        ->and($currentOrder->updates)->toBe([['driver_assigned_uuid' => null]])
        ->and($controller->driver->currentJobCleared)->toBeTrue();
});

test('internal driver controller login with phone normalizes identity and handles missing drivers', function () {
    FleetOpsInternalDriverAuthControllerProbe::resetProbe();

    app()->instance('request', Request::create('/', 'POST', ['phone' => '15551234567']));

    $controller = new FleetOpsInternalDriverAuthControllerProbe();
    $missing    = $controller->loginWithPhone();

    expect($missing->getData(true))->toBe([
        'error' => 'No driver with this phone # found.',
    ])
        ->and(FleetOpsInternalDriverAuthControllerProbe::$loginPhones)->toBe(['+15551234567']);

    FleetOpsInternalDriverAuthControllerProbe::resetProbe();
    FleetOpsInternalDriverAuthControllerProbe::$loginUser = fleetopsInternalDriverAuthUser();

    $response = $controller->loginWithPhone();

    expect($response->getData(true))->toBe(['status' => 'OK'])
        ->and(FleetOpsInternalDriverAuthControllerProbe::$verifications)->toBe([
            ['user' => 'user-uuid', 'for' => 'driver_login'],
        ]);
});

test('internal driver controller verify code covers missing user invalid code and missing driver branches', function () {
    app('config')->set('fleetops.navigator.bypass_verification_code', '000000');

    FleetOpsInternalDriverAuthControllerProbe::resetProbe();
    $controller = new FleetOpsInternalDriverAuthControllerProbe();

    $missingUser = $controller->verifyCode(new Request([
        'identity' => 'driver@example.com',
        'code'     => '111111',
    ]));

    expect($missingUser->getData(true))->toBe([
        'error' => 'Unable to verify code.',
    ]);

    FleetOpsInternalDriverAuthControllerProbe::resetProbe();
    FleetOpsInternalDriverAuthControllerProbe::$verificationUser = fleetopsInternalDriverAuthUser();

    $invalidCode = $controller->verifyCode(new Request([
        'identity' => '+15551234567',
        'code'     => '111111',
    ]));

    expect($invalidCode->getData(true))->toBe([
        'error' => 'Invalid verification code!',
    ]);

    FleetOpsInternalDriverAuthControllerProbe::resetProbe();
    FleetOpsInternalDriverAuthControllerProbe::$verificationUser   = fleetopsInternalDriverAuthUser();
    FleetOpsInternalDriverAuthControllerProbe::$verificationExists = true;

    $missingDriver = $controller->verifyCode(new Request([
        'identity' => '+15551234567',
        'code'     => '111111',
    ]));

    expect($missingDriver->getData(true))->toBe([
        'error' => 'No driver/agent record found for login.',
    ])
        ->and(FleetOpsInternalDriverAuthControllerProbe::$driverLookups)->toBe(['user-uuid']);
});

test('internal driver controller verify code returns driver resource and handles token errors', function () {
    app('config')->set('fleetops.navigator.bypass_verification_code', '000000');

    FleetOpsInternalDriverAuthControllerProbe::resetProbe();
    FleetOpsInternalDriverAuthControllerProbe::$verificationUser   = fleetopsInternalDriverAuthUser();
    FleetOpsInternalDriverAuthControllerProbe::$loginDriver        = fleetopsInternalDriverAuthDriver();
    FleetOpsInternalDriverAuthControllerProbe::$verificationExists = false;

    $controller = new FleetOpsInternalDriverAuthControllerProbe();
    $response   = $controller->verifyCode(new Request([
        'identity' => '15551234567',
        'code'     => '000000',
    ]));

    expect($response->resolve())->toBe([
        'resource' => 'driver',
        'uuid'     => 'driver-uuid',
        'token'    => 'plain-token',
    ])
        ->and(FleetOpsInternalDriverAuthControllerProbe::$verificationChecks)->toContain(['identity' => '+15551234567'])
        ->and(FleetOpsInternalDriverAuthControllerProbe::$tokens)->toBe([
            ['user' => 'user-uuid', 'driver' => 'driver-uuid'],
        ]);

    FleetOpsInternalDriverAuthControllerProbe::resetProbe();
    FleetOpsInternalDriverAuthControllerProbe::$verificationUser   = fleetopsInternalDriverAuthUser();
    FleetOpsInternalDriverAuthControllerProbe::$loginDriver        = fleetopsInternalDriverAuthDriver();
    FleetOpsInternalDriverAuthControllerProbe::$verificationExists = true;
    FleetOpsInternalDriverAuthControllerProbe::$tokenShouldFail    = true;

    $tokenFailure = $controller->verifyCode(new Request([
        'identity' => 'driver@example.com',
        'code'     => '222222',
    ]));

    expect($tokenFailure->getData(true))->toBe([
        'error' => 'Token service unavailable.',
    ]);
});
