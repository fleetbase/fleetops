<?php

use Fleetbase\FleetOps\Exports\DriverExport;
use Fleetbase\FleetOps\Http\Controllers\Internal\v1\DriverController;
use Fleetbase\FleetOps\Imports\DriverImport;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\Http\Requests\ExportRequest;
use Fleetbase\Http\Requests\ImportRequest;
use Fleetbase\Models\User;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

if (!class_exists('Fleetbase\Http\Requests\ExportRequest', false)) {
    eval('namespace Fleetbase\Http\Requests; class ExportRequest extends \Illuminate\Http\Request {}');
}

if (!class_exists('Fleetbase\Http\Requests\ImportRequest', false)) {
    eval('namespace Fleetbase\Http\Requests; class ImportRequest extends \Illuminate\Http\Request {}');
}

class FleetOpsInternalDriverExportImportRequestFake extends ExportRequest
{
    public function array($key = null, $default = [])
    {
        $value = $this->input($key, $default);

        return is_array($value) ? $value : $default;
    }
}

class FleetOpsInternalDriverImportRequestFake extends ImportRequest
{
    public array $resolvedFiles = [];

    public function resolveFilesFromIds(string $param = 'files')
    {
        return collect($this->resolvedFiles);
    }
}

class FleetOpsInternalDriverImportFake extends DriverImport
{
    public function __construct(int $imported)
    {
        $this->imported = $imported;
    }
}

class FleetOpsInternalDriverOptionsControllerProbe extends DriverController
{
    public static array $downloads = [];
    public array $imports          = [];
    public array $imported         = [3, 5];
    public array $statuses         = ['available', null, 'busy'];
    public array $avatars          = ['avatar-a.png', 'avatar-b.png'];
    public bool $failImport        = false;
    public ?string $statusCompany  = null;

    public static function resetProbe(): void
    {
        static::$downloads = [];
    }

    protected function statusOptionsForCompany(?string $companyUuid)
    {
        $this->statusCompany = $companyUuid;

        return collect($this->statuses)->filter()->values();
    }

    protected function driverAvatarOptions(): array
    {
        return $this->avatars;
    }

    protected static function downloadExport(DriverExport $export, string $fileName)
    {
        static::$downloads[] = [$export, $fileName];

        return ['download' => $fileName, 'headings' => $export->headings()];
    }

    protected function createImport(): DriverImport
    {
        return new FleetOpsInternalDriverImportFake(array_shift($this->imported) ?? 0);
    }

    protected function importFile(DriverImport $import, string $path, string $disk): void
    {
        if ($this->failImport) {
            throw new RuntimeException('invalid driver import');
        }

        $this->imports[] = [$import->imported, $path, $disk];
    }
}

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

class FleetOpsInternalDriverHelperControllerProbe extends DriverController
{
    public function normalizeVehicleInput(Request $request, ?array &$input): void
    {
        $this->normalizeDriverVehicleInput($request, $input);
    }

    public function statusOptions(?string $companyUuid)
    {
        return $this->statusOptionsForCompany($companyUuid);
    }

    public function lookupDriver(?string $id): Driver
    {
        return $this->findDriver($id);
    }

    public function lookupOrder(?string $id): Order
    {
        return $this->findOrder($id);
    }

    public function lookupVehicle(?string $id): Vehicle
    {
        return $this->findVehicle($id);
    }

    public function assignedFor(Driver $driver)
    {
        return $this->assignedOrdersForDriver($driver);
    }

    public function selectedFor(Driver $driver, Collection $ids)
    {
        return $this->selectedAssignedOrdersForDriver($driver, $ids);
    }

    public function currentFor(Driver $driver): ?Order
    {
        return $this->currentAssignedOrderForDriver($driver);
    }

    public function clearAssignments($orders): void
    {
        $this->clearDriverAssignmentsForOrders($orders);
    }

    public function runInTransaction(callable $callback): mixed
    {
        return $this->runTransaction($callback);
    }
}

class FleetOpsInternalDriverHelperDatabaseProbe
{
    public function __construct(private SQLiteConnection $connection)
    {
    }

    public function table(string $table)
    {
        return $this->connection->table($table);
    }

    public function connection(): SQLiteConnection
    {
        return $this->connection;
    }

    public function transaction(callable $callback): mixed
    {
        return $callback();
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

function fleetopsInternalDriverExportRequest(array $input): FleetOpsInternalDriverExportImportRequestFake
{
    return FleetOpsInternalDriverExportImportRequestFake::create('/internal/drivers/export', 'POST', $input);
}

function fleetopsInternalDriverImportRequest(array $input, array $files): FleetOpsInternalDriverImportRequestFake
{
    $request                = FleetOpsInternalDriverImportRequestFake::create('/internal/drivers/import', 'POST', $input);
    $request->resolvedFiles = $files;

    return $request;
}

function fleetopsInternalDriverExportSelections(DriverExport $export): array
{
    $property = new ReflectionProperty($export, 'selections');
    $property->setAccessible(true);

    return $property->getValue($export);
}

function fleetopsInternalDriverUseHelperDatabase(): SQLiteConnection
{
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
    $resolver   = new ConnectionResolver([
        'default' => $connection,
        'mysql'   => $connection,
    ]);

    $resolver->setDefaultConnection('mysql');
    EloquentModel::setConnectionResolver($resolver);
    app()->instance('db', new FleetOpsInternalDriverHelperDatabaseProbe($connection));

    $schema = $connection->getSchemaBuilder();
    $schema->create('users', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('drivers', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('user_uuid')->nullable();
        $table->string('current_job_uuid')->nullable();
        $table->string('status')->nullable();
        $table->timestamp('created_at')->nullable();
        $table->timestamp('updated_at')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('vehicles', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('orders', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('driver_assigned_uuid')->nullable();
        $table->timestamp('created_at')->nullable();
        $table->timestamp('updated_at')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });

    return $connection;
}

test('internal driver controller returns status and avatar options', function () {
    session(['company' => 'company-uuid']);

    $controller = new FleetOpsInternalDriverOptionsControllerProbe();

    expect($controller->statuses()->getData(true))->toBe(['available', 'busy'])
        ->and($controller->statusCompany)->toBe('company-uuid')
        ->and($controller->avatars()->getData(true))->toBe(['avatar-a.png', 'avatar-b.png']);
});

test('internal driver controller helper branches normalize vehicles phone and default statuses', function () {
    $connection = fleetopsInternalDriverUseHelperDatabase();
    $connection->table('drivers')->insert([
        ['company_uuid' => 'company-uuid', 'status' => 'available'],
        ['company_uuid' => 'company-uuid', 'status' => 'busy'],
        ['company_uuid' => 'company-uuid', 'status' => null],
        ['company_uuid' => 'company-uuid', 'status' => 'available'],
        ['company_uuid' => 'other-company', 'status' => 'offline'],
    ]);

    $controller = new FleetOpsInternalDriverHelperControllerProbe();
    $request    = Request::create('/internal/drivers', 'POST', [
        'driver' => [
            'vehicle' => [
                'public_id' => 'vehicle-public',
                'uuid'      => 'vehicle-uuid',
            ],
        ],
    ]);
    $input      = $request->input('driver');

    $controller->normalizeVehicleInput($request, $input);

    $passthroughRequest = Request::create('/internal/drivers', 'POST', [
        'driver' => ['vehicle' => 'vehicle-public'],
    ]);
    $passthroughInput = $passthroughRequest->input('driver');
    $controller->normalizeVehicleInput($passthroughRequest, $passthroughInput);

    app()->instance('request', Request::create('/', 'POST', ['phone' => '15551234567']));

    expect($input['vehicle'])->toBe('vehicle-public')
        ->and($request->input('driver.vehicle'))->toBe('vehicle-public')
        ->and($passthroughInput['vehicle'])->toBe('vehicle-public')
        ->and($passthroughRequest->input('driver.vehicle'))->toBe('vehicle-public')
        ->and(DriverController::phone())->toBe('+15551234567')
        ->and(DriverController::phone('+15550000000'))->toBe('+15550000000')
        ->and($controller->statusOptions('company-uuid')->all())->toBe(['available', 'busy']);
});

test('internal driver controller query helpers resolve company scoped records', function () {
    session(['company' => 'company-uuid']);

    $connection = fleetopsInternalDriverUseHelperDatabase();
    $connection->table('users')->insert([
        ['uuid' => 'user-uuid'],
    ]);
    $connection->table('drivers')->insert([
        [
            'uuid'             => 'driver-uuid',
            'public_id'        => 'driver-public',
            'company_uuid'     => 'company-uuid',
            'user_uuid'        => 'user-uuid',
            'current_job_uuid' => 'order-current',
            'status'           => 'available',
        ],
        [
            'uuid'             => 'other-driver',
            'public_id'        => 'other-driver-public',
            'company_uuid'     => 'other-company',
            'user_uuid'        => 'user-uuid',
            'current_job_uuid' => null,
            'status'           => 'busy',
        ],
    ]);
    $connection->table('vehicles')->insert([
        [
            'uuid'         => 'vehicle-uuid',
            'public_id'    => 'vehicle-public',
            'company_uuid' => 'company-uuid',
        ],
    ]);
    $connection->table('orders')->insert([
        [
            'uuid'                 => 'order-current',
            'public_id'            => 'order-current-public',
            'company_uuid'         => 'company-uuid',
            'driver_assigned_uuid' => 'driver-uuid',
            'created_at'           => '2026-07-27 12:00:00',
            'updated_at'           => '2026-07-27 12:00:00',
        ],
        [
            'uuid'                 => 'order-old',
            'public_id'            => 'order-old-public',
            'company_uuid'         => 'company-uuid',
            'driver_assigned_uuid' => 'driver-uuid',
            'created_at'           => '2026-07-27 08:00:00',
            'updated_at'           => '2026-07-27 08:00:00',
        ],
        [
            'uuid'                 => 'order-other-driver',
            'public_id'            => 'order-other-public',
            'company_uuid'         => 'company-uuid',
            'driver_assigned_uuid' => 'other-driver',
            'created_at'           => '2026-07-27 13:00:00',
            'updated_at'           => '2026-07-27 13:00:00',
        ],
    ]);

    $controller = new FleetOpsInternalDriverHelperControllerProbe();
    $driver     = $controller->lookupDriver('driver-public');
    $orders     = $controller->assignedFor($driver);
    $selected   = $controller->selectedFor($driver, collect(['order-old-public', 'missing-order']));
    $current    = $controller->currentFor($driver);

    expect($driver)->toBeInstanceOf(Driver::class)
        ->and($driver->uuid)->toBe('driver-uuid')
        ->and($controller->lookupOrder('order-current-public')->uuid)->toBe('order-current')
        ->and($controller->lookupVehicle('vehicle-public')->uuid)->toBe('vehicle-uuid')
        ->and($orders->pluck('uuid')->all())->toBe(['order-current', 'order-old'])
        ->and($selected->pluck('uuid')->all())->toBe(['order-old'])
        ->and($current?->uuid)->toBe('order-current');

    $controller->runInTransaction(function () use ($controller, $selected): void {
        $controller->clearAssignments($selected);
    });

    expect($connection->table('orders')->where('uuid', 'order-old')->value('driver_assigned_uuid'))->toBeNull()
        ->and($connection->table('orders')->where('uuid', 'order-current')->value('driver_assigned_uuid'))->toBe('driver-uuid');
});

test('internal driver controller downloads selected exports', function () {
    FleetOpsInternalDriverOptionsControllerProbe::resetProbe();

    $response = FleetOpsInternalDriverOptionsControllerProbe::export(fleetopsInternalDriverExportRequest([
        'format'     => 'csv',
        'selections' => ['driver-a', 'driver-b'],
    ]));

    expect($response['download'])->toMatch('/^drivers-[0-9-]+\\.csv$/')
        ->and($response['headings'])->toContain('Name', 'Phone', 'Status')
        ->and(FleetOpsInternalDriverOptionsControllerProbe::$downloads)->toHaveCount(1)
        ->and(fleetopsInternalDriverExportSelections(FleetOpsInternalDriverOptionsControllerProbe::$downloads[0][0]))->toBe(['driver-a', 'driver-b']);
});

test('internal driver controller imports files and reports invalid files', function () {
    $controller = new FleetOpsInternalDriverOptionsControllerProbe();

    $response = $controller->import(fleetopsInternalDriverImportRequest([
        'disk' => 'imports',
    ], [
        (object) ['path' => 'drivers/a.csv'],
        (object) ['path' => 'drivers/b.csv'],
    ]));

    expect($response->getData(true))->toBe([
        'status'   => 'ok',
        'message'  => 'Import completed',
        'imported' => 8,
    ])
        ->and($controller->imports)->toBe([
            [3, 'drivers/a.csv', 'imports'],
            [5, 'drivers/b.csv', 'imports'],
        ]);

    $controller             = new FleetOpsInternalDriverOptionsControllerProbe();
    $controller->failImport = true;

    $failed = $controller->import(fleetopsInternalDriverImportRequest([], [
        (object) ['path' => 'drivers/bad.csv'],
    ]));

    expect($failed->getStatusCode())->toBe(500)
        ->and($failed->getData(true))->toBe([
            'error' => 'Invalid file, unable to proccess.',
        ]);
});

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

test('internal driver controller delegates login and create driver verification flows to api controller', function () {
    $request = new Request(['for' => 'create_driver']);

    $delegate = new class {
        public array $calls = [];

        public function login(Request $request): array
        {
            $this->calls[] = ['login', $request];

            return ['delegated' => 'login'];
        }

        public function create(Request $request): array
        {
            $this->calls[] = ['create', $request];

            return ['delegated' => 'create'];
        }
    };

    app()->instance(Fleetbase\FleetOps\Http\Controllers\Api\v1\DriverController::class, $delegate);

    $controller = new DriverController();

    expect($controller->login($request))->toBe(['delegated' => 'login'])
        ->and($controller->verifyCode($request))->toBe(['delegated' => 'create'])
        ->and($delegate->calls)->toBe([
            ['login', $request],
            ['create', $request],
        ]);

    app()->forgetInstance(Fleetbase\FleetOps\Http\Controllers\Api\v1\DriverController::class);
});
