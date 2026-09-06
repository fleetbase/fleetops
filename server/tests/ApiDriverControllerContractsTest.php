<?php

use Fleetbase\FleetOps\Http\Controllers\Api\v1\DriverController;
use Fleetbase\FleetOps\Http\Requests\CreateDriverRequest;
use Fleetbase\FleetOps\Http\Requests\UpdateDriverRequest;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\FleetOps\Models\Vendor;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Fleetbase\Models\Company;
use Fleetbase\Models\User;
use Fleetbase\Models\UserDevice;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;

if (!class_exists('Illuminate\Foundation\Auth\User')) {
    eval('namespace Illuminate\Foundation\Auth; class User extends \Illuminate\Database\Eloquent\Model {}');
}

class FleetOpsApiDriverControllerProbe extends DriverController
{
    public ?FleetOpsApiDriverFake $driver   = null;
    public ?FleetOpsApiDriverUserFake $user = null;
    public ?Company $company                = null;
    public mixed $queryResults              = null;
    public bool $driverNotFound             = false;
    public bool $missingCompany             = false;
    public array $findCalls                 = [];
    public array $deviceCreates             = [];
    public array $companyCalls              = [];
    public array $createdUsers              = [];
    public array $uuidLookups               = [];
    public array $relationLookups           = [];
    public array $relationCompanyScopes     = [];
    public array $unresolvable              = [];
    public array $pointInputs               = [];
    public array $createdDrivers            = [];
    public array $resolvedFiles             = [];
    public string $sessionCompanyUuid       = 'company-uuid';

    protected function companyFromRequest(Request $request): ?Company
    {
        $this->companyCalls[] = ['request', $request->input('company')];

        return $this->missingCompany ? null : $this->company();
    }

    protected function currentCompany(): ?Company
    {
        $this->companyCalls[] = ['current'];

        return $this->missingCompany ? null : $this->company();
    }

    protected function sessionCompany(): ?string
    {
        return $this->sessionCompanyUuid;
    }

    protected function applyUserInfoFromRequest(Request $request, array $userDetails): array
    {
        $userDetails['applied'] = true;

        return $userDetails;
    }

    protected function createUser(array $userDetails): User
    {
        $this->createdUsers[] = $userDetails;
        $this->user ??= new FleetOpsApiDriverUserFake();
        $this->user->setRawAttributes(array_merge(['uuid' => 'user-uuid'], $userDetails), true);

        return $this->user;
    }

    protected function getUuid(array|string $table, array $where, array $options = []): mixed
    {
        $this->uuidLookups[] = [$table, $where, $options];

        return $table . '-uuid';
    }

    /**
     * Stand in for the company-scoped public-id lookup.
     *
     * Anything listed in `$unresolvable` behaves as a cross-company or missing
     * identifier does in production.
     */
    protected function resolveUuid(string $modelClass, ?string $id, ?string $companyUuid = null): ?string
    {
        if (empty($id)) {
            return null;
        }

        $this->relationLookups[]       = [$modelClass, $id];
        $this->relationCompanyScopes[] = $companyUuid;

        if (in_array($id, $this->unresolvable, true)) {
            throw (new ModelNotFoundException())->setModel($modelClass, $id);
        }

        return strtolower(class_basename($modelClass)) . '-uuid';
    }

    public function inputForTest(Request $request): array
    {
        return $this->driverInputFromRequest($request);
    }

    protected function pointFromCoordinates(array $coordinates): Point
    {
        $this->pointInputs[] = $coordinates;

        return new Point((float) $coordinates['latitude'], (float) $coordinates['longitude']);
    }

    protected function createDriver(array $attributes): Driver
    {
        $this->createdDrivers[] = $attributes;
        $this->driver ??= new FleetOpsApiDriverFake();
        $this->driver->setRawAttributes(array_merge(['uuid' => 'driver-uuid', 'public_id' => 'driver_public'], $attributes), true);

        return $this->driver;
    }

    protected function resolveFile(mixed $input, string $path): mixed
    {
        $this->resolvedFiles[] = [$input, $path];

        return (object) ['uuid' => 'photo-file-uuid'];
    }

    protected function findDriver(string $id, array $with = []): Driver
    {
        $this->findCalls[] = [$id, $with];

        if ($this->driverNotFound) {
            throw new ModelNotFoundException();
        }

        $this->driver ??= new FleetOpsApiDriverFake();
        $this->driver->setAttribute('lookup_id', $id);

        return $this->driver;
    }

    protected function currentDriver(?Request $request): Driver
    {
        $this->findCalls[] = ['current', $request];

        if ($this->driverNotFound) {
            throw new ModelNotFoundException();
        }

        return $this->driver ??= new FleetOpsApiDriverFake();
    }

    protected function queryDrivers(Request $request)
    {
        return $this->queryResults ?? [['uuid' => 'driver-uuid']];
    }

    protected function firstOrCreateUserDevice(array $attributes, array $values): UserDevice
    {
        $this->deviceCreates[] = [$attributes, $values];

        $device = new UserDevice();
        $device->setRawAttributes(['public_id' => 'device_public'], true);

        return $device;
    }

    protected function driverResource(Driver $driver)
    {
        return ['resource' => 'driver', 'driver' => $driver];
    }

    protected function driverResourceCollection($results)
    {
        return ['collection' => 'driver', 'items' => $results];
    }

    protected function deletedDriverResource(Driver $driver)
    {
        return ['resource' => 'deleted-driver', 'driver' => $driver];
    }

    protected function jsonResponse(array $payload, int $status)
    {
        return ['json' => $payload, 'status' => $status];
    }

    protected function apiError(string $message, int $status = 400)
    {
        return ['apiError' => $message, 'status' => $status];
    }

    private function company(): Company
    {
        if (!$this->company) {
            $this->company = new Company();
            $this->company->setRawAttributes(['uuid' => 'company-uuid', 'public_id' => 'company_public'], true);
        }

        return $this->company;
    }
}

class FleetOpsApiDriverFake extends Driver
{
    public array $quietUpdates                     = [];
    public array $updates                          = [];
    public array $loaded                           = [];
    public bool $deletedForTest                    = false;
    public bool $flushedForTest                    = false;
    public ?FleetOpsApiDriverUserFake $userForTest = null;

    public function updateQuietly(array $attributes = [], array $options = []): bool
    {
        $this->quietUpdates[] = $attributes;
        $this->setRawAttributes(array_merge($this->getAttributes(), $attributes), true);

        return true;
    }

    public function loadMissing($relations)
    {
        $this->loaded[] = $relations;

        return $this;
    }

    public function delete()
    {
        $this->deletedForTest = true;

        return true;
    }

    public function update(array $attributes = [], array $options = []): bool
    {
        $this->updates[] = $attributes;
        $this->setRawAttributes(array_merge($this->getAttributes(), $attributes), true);

        return true;
    }

    public function flushAttributesCache(): bool
    {
        $this->flushedForTest = true;

        return true;
    }

    public function load($relations)
    {
        $this->loaded[] = $relations;

        return $this;
    }

    public function getUser(): ?User
    {
        return $this->userForTest;
    }
}

class FleetOpsApiDriverUserFake extends User
{
    public array $updates           = [];
    public array $assignedCompanies = [];
    public array $assignedRoles     = [];
    public array $assignedTypes     = [];
    public bool $deletedQuietly     = false;

    public function update(array $attributes = [], array $options = []): bool
    {
        $this->updates[] = $attributes;
        $this->setRawAttributes(array_merge($this->getAttributes(), $attributes), true);

        return true;
    }

    public function assignCompany(Company $company, string $role = 'Administrator'): User
    {
        $this->assignedCompanies[] = $company->uuid;

        return $this;
    }

    public function deleteQuietly()
    {
        $this->deletedQuietly = true;

        return true;
    }

    public function setUserType(string $type): User
    {
        $this->assignedTypes[] = $type;
        $this->type            = $type;

        return $this;
    }

    public function assignSingleRole($role): User
    {
        $this->assignedRoles[] = $role;

        return $this;
    }

    public function setPasswordAttribute($password): void
    {
        $this->attributes['password'] = $password;
    }
}

class FleetOpsApiDriverVehicleFake extends Vehicle
{
    public array $quietUpdates = [];

    public function updateQuietly(array $attributes = [], array $options = []): bool
    {
        $this->quietUpdates[] = $attributes;
        $this->setRawAttributes(array_merge($this->getAttributes(), $attributes), true);

        return true;
    }
}

class FleetOpsApiDriverRegisterDeviceRequest extends Request
{
    public function or(array $keys, mixed $default = null): mixed
    {
        foreach ($keys as $key) {
            if ($this->has($key)) {
                return $this->input($key);
            }
        }

        return $default;
    }
}

test('api driver controller creates drivers with user company assignment and related ids', function () {
    $controller = new FleetOpsApiDriverControllerProbe();

    $response = $controller->create(new CreateDriverRequest([
        'company'   => 'company_public',
        'name'      => 'Driver One',
        'email'     => 'driver@example.test',
        'password'  => 'secret-password',
        'phone'     => '+15551234567',
        'vehicle'   => 'vehicle_public',
        'vendor'    => 'vendor_public',
        'job'       => 'order_public',
        'latitude'  => 1.31,
        'longitude' => 103.81,
        'photo'     => 'file_public',
    ]));

    expect($response)->toBe(['resource' => 'driver', 'driver' => $controller->driver])
        ->and($controller->companyCalls)->toBe([['request', 'company_public']])
        ->and($controller->createdUsers[0])->toMatchArray([
            'name'         => 'Driver One',
            'email'        => 'driver@example.test',
            'phone'        => '+15551234567',
            'company_uuid' => 'company-uuid',
            'applied'      => true,
        ])
        ->and($controller->user->assignedCompanies)->toBe(['company-uuid'])
        ->and($controller->user->assignedTypes)->toBe(['driver'])
        ->and($controller->user->assignedRoles)->toBe(['Driver'])
        ->and($controller->createdDrivers[0])->toMatchArray([
            'status'           => 'available',
            'vehicle_uuid'     => 'vehicle-uuid',
            'vendor_uuid'      => 'vendor-uuid',
            'current_job_uuid' => 'order-uuid',
            'online'           => 0,
            'user_uuid'        => 'user-uuid',
            'company_uuid'     => 'company-uuid',
        ])
        ->and($controller->createdDrivers[0]['location'])->toBeInstanceOf(Point::class)
        ->and($controller->relationLookups)->toBe([
            [Vehicle::class, 'vehicle_public'],
            [Vendor::class, 'vendor_public'],
            [Order::class, 'order_public'],
        ])
        ->and($controller->resolvedFiles)->toBe([['file_public', 'uploads/company-uuid/drivers']])
        // `photo_uuid` is not a column on users; the avatar lives in `avatar_uuid`
        // and the old key was silently dropped by mass assignment.
        ->and($controller->user->updates)->toContain(['avatar_uuid' => 'photo-file-uuid'])
        ->and($controller->driver->loaded)->toContain(['user', 'vehicle', 'vendor', 'currentJob']);
});

test('api driver controller reports missing company before creating drivers', function () {
    $controller                 = new FleetOpsApiDriverControllerProbe();
    $controller->missingCompany = true;

    expect($controller->create(new CreateDriverRequest()))->toBe([
        'apiError' => 'Company not found.',
        'status'   => 400,
    ])
        ->and($controller->createdUsers)->toBe([]);
});

test('api driver controller updates drivers user details assignments location and photo', function () {
    $user = new FleetOpsApiDriverUserFake();
    $user->setRawAttributes(['uuid' => 'user-uuid'], true);

    $driver = new FleetOpsApiDriverFake();
    $driver->setRawAttributes([
        'uuid'      => 'driver-uuid',
        'public_id' => 'driver_public',
        'user_uuid' => 'user-uuid',
    ], true);
    $driver->userForTest = $user;
    $driver->setRelation('user', $user);

    $controller                     = new FleetOpsApiDriverControllerProbe();
    $controller->driver             = $driver;
    $controller->sessionCompanyUuid = 'session-company-uuid';

    $response = $controller->update('driver_public', new UpdateDriverRequest([
        'name'      => 'Driver Updated',
        'email'     => 'updated@example.test',
        'phone'     => '+15557654321',
        'vehicle'   => 'vehicle_public',
        'vendor'    => 'vendor_public',
        'job'       => 'order_public',
        'latitude'  => 1.35,
        'longitude' => 103.85,
        'photo'     => 'photo-input',
        'status'    => 'busy',
    ]));

    expect($response)->toBe(['resource' => 'driver', 'driver' => $driver])
        ->and($controller->findCalls)->toContain(['driver_public', ['user']])
        ->and($user->updates)->toContain([
            'name'  => 'Driver Updated',
            'email' => 'updated@example.test',
            'phone' => '+15557654321',
        ])
        ->and($driver->updates[0])->toMatchArray([
            'status'           => 'busy',
            'vehicle_uuid'     => 'vehicle-uuid',
            'vendor_uuid'      => 'vendor-uuid',
            'current_job_uuid' => 'order-uuid',
        ])
        ->and($driver->updates[0]['location'])->toBeInstanceOf(Point::class)
        ->and($driver->flushedForTest)->toBeTrue()
        ->and($controller->resolvedFiles)->toBe([['photo-input', 'uploads/session-company-uuid/drivers']])
        ->and($user->updates)->toContain(['avatar_uuid' => 'photo-file-uuid'])
        ->and($driver->loaded)->toContain(['user', 'vehicle', 'vendor', 'currentJob']);
});

test('api driver controller reports missing driver updates', function () {
    $controller                 = new FleetOpsApiDriverControllerProbe();
    $controller->driverNotFound = true;

    expect($controller->update('missing-driver', new UpdateDriverRequest()))->toBe([
        'json'   => ['error' => 'Driver resource not found.'],
        'status' => 404,
    ]);
});

test('api driver controller queries finds deletes and tracks empty coordinate payloads', function () {
    $driver = new FleetOpsApiDriverFake();
    $driver->setRawAttributes([
        'uuid'      => 'driver-uuid',
        'public_id' => 'driver_public',
        'online'    => false,
    ], true);

    $controller               = new FleetOpsApiDriverControllerProbe();
    $controller->driver       = $driver;
    $controller->queryResults = [['uuid' => 'driver-a'], ['uuid' => 'driver-b']];

    $query   = $controller->query(new Request(['limit' => 2]));
    $found   = $controller->find('driver_public');
    $tracked = $controller->track('driver_public', new Request());
    $deleted = $controller->delete('driver_public', new Request());

    expect($query)->toBe([
        'collection' => 'driver',
        'items'      => [['uuid' => 'driver-a'], ['uuid' => 'driver-b']],
    ])
        ->and($found)->toBe(['resource' => 'driver', 'driver' => $driver])
        ->and($tracked)->toBe(['resource' => 'driver', 'driver' => $driver])
        ->and($deleted)->toBe(['resource' => 'deleted-driver', 'driver' => $driver])
        ->and($driver->deletedForTest)->toBeTrue()
        ->and($controller->findCalls)->toContain(
            ['driver_public', ['user', 'vehicle', 'vendor', 'currentJob']],
            ['driver_public', []]
        );
});

test('api driver controller toggles driver and vehicle online state', function (mixed $incoming, bool $initial, bool $expected) {
    $vehicle = new FleetOpsApiDriverVehicleFake();
    $vehicle->setRawAttributes(['uuid' => 'vehicle-uuid', 'online' => !$expected], true);

    $driver = new FleetOpsApiDriverFake();
    $driver->setRawAttributes([
        'uuid'      => 'driver-uuid',
        'public_id' => 'driver_public',
        'online'    => $initial,
    ], true);
    $driver->setRelation('vehicle', $vehicle);

    $controller         = new FleetOpsApiDriverControllerProbe();
    $controller->driver = $driver;

    $request  = $incoming === '__missing__' ? new Request() : new Request(['online' => $incoming]);
    $response = $controller->toggleOnline('driver_public', $request);

    expect($response)->toBe(['resource' => 'driver', 'driver' => $driver])
        ->and($driver->quietUpdates)->toContain(['online' => $expected])
        ->and($vehicle->quietUpdates)->toContain(['online' => $expected])
        ->and($driver->loaded)->toContain('vehicle');
})->with([
    'toggles missing input' => ['__missing__', false, true],
    'casts explicit false'  => ['false', true, false],
    'casts explicit true'   => ['1', false, true],
]);

test('api driver controller registers devices and validates required inputs', function () {
    $driver = new FleetOpsApiDriverFake();
    $driver->setRawAttributes([
        'uuid'      => 'driver-uuid',
        'public_id' => 'driver_public',
        'user_uuid' => 'user-uuid',
    ], true);

    $controller         = new FleetOpsApiDriverControllerProbe();
    $controller->driver = $driver;

    expect($controller->registerDevice('driver_public', new FleetOpsApiDriverRegisterDeviceRequest()))->toBe([
        'apiError' => 'Token is required to register device.',
        'status'   => 400,
    ])
        ->and($controller->registerDevice('driver_public', new FleetOpsApiDriverRegisterDeviceRequest(['token' => 'push-token'])))->toBe([
            'apiError' => 'Platform is required to register device.',
            'status'   => 400,
        ]);

    $response = $controller->registerDevice('driver_public', new FleetOpsApiDriverRegisterDeviceRequest([
        'token'    => 'push-token',
        'platform' => 'ios',
    ]));

    expect($response)->toBe([
        'json'   => ['device' => 'device_public'],
        'status' => 200,
    ])
        ->and($controller->deviceCreates)->toBe([
            [
                ['token' => 'push-token', 'platform' => 'ios'],
                ['user_uuid' => 'user-uuid', 'platform' => 'ios', 'token' => 'push-token', 'status' => 'active'],
            ],
        ]);
});

test('api driver controller registers a device for the authenticated driver without an id', function () {
    $driver = new FleetOpsApiDriverFake();
    $driver->setRawAttributes([
        'uuid'      => 'driver-uuid',
        'public_id' => 'driver_public',
        'user_uuid' => 'user-uuid',
    ], true);

    $controller         = new FleetOpsApiDriverControllerProbe();
    $controller->driver = $driver;

    $response = $controller->registerDevice(null, new FleetOpsApiDriverRegisterDeviceRequest([
        'token'    => 'push-token',
        'platform' => 'ios',
    ]));

    expect($response)->toBe([
        'json'   => ['device' => 'device_public'],
        'status' => 200,
    ]);
});

test('api driver controller falls back to the container request when the router injects null', function () {
    // Laravel's ResolvesRouteDependencies::transformDependency() returns null — it does
    // NOT resolve from the container — for any class-typed parameter that declares a
    // default value. registerDevice() has to declare one, because the internal controller
    // delegates with an id only, so every routed call arrived with $request === null.
    // Every other test here passes a request explicitly, which is why they stayed green
    // while POST /v1/drivers/{id}/register-device answered 500.
    $driver = new FleetOpsApiDriverFake();
    $driver->setRawAttributes([
        'uuid'      => 'driver-uuid',
        'public_id' => 'driver_public',
        'user_uuid' => 'user-uuid',
    ], true);

    $controller         = new FleetOpsApiDriverControllerProbe();
    $controller->driver = $driver;

    $bound = new FleetOpsApiDriverRegisterDeviceRequest(['token' => 'push-token', 'platform' => 'android']);
    app()->instance('request', $bound);

    expect($controller->registerDevice('driver_public', null))->toBe([
        'json'   => ['device' => 'device_public'],
        'status' => 200,
    ])
        ->and($controller->deviceCreates)->toBe([
            [
                ['token' => 'push-token', 'platform' => 'android'],
                ['user_uuid' => 'user-uuid', 'platform' => 'android', 'token' => 'push-token', 'status' => 'active'],
            ],
        ]);
});

test('api driver controller reports missing driver branches', function () {
    $controller                 = new FleetOpsApiDriverControllerProbe();
    $controller->driverNotFound = true;

    $json404 = [
        'json'   => ['error' => 'Driver resource not found.'],
        'status' => 404,
    ];

    expect($controller->find('missing-driver'))->toBe($json404)
        ->and($controller->delete('missing-driver', new Request()))->toBe($json404)
        ->and($controller->toggleOnline('missing-driver', new Request()))->toBe($json404)
        ->and($controller->registerDevice('missing-driver', new FleetOpsApiDriverRegisterDeviceRequest()))->toBe($json404)
        ->and($controller->registerDevice(null, new FleetOpsApiDriverRegisterDeviceRequest()))->toBe($json404)
        ->and($controller->track('missing-driver', new Request(['latitude' => 1, 'longitude' => 2])))->toBe([
            'apiError' => 'Driver resource not found.',
            'status'   => 404,
        ]);
});

test('api driver controller refuses to set a password through a general update', function () {
    /*
     * Regression for a takeover path: `update()` passed `password` straight
     * through to the user, so anyone holding a driver's token — or an unlocked
     * handset — could set a new one without proving they knew the old one, and
     * the account was theirs. A password change is an authorisation decision
     * and now has its own endpoint that demands the current password.
     */
    $user = new FleetOpsApiDriverUserFake();
    $user->setRawAttributes(['uuid' => 'user-uuid'], true);
    $driver = new FleetOpsApiDriverFake();
    $driver->setRawAttributes([
        'uuid'      => 'driver-uuid',
        'public_id' => 'driver_public',
        'user_uuid' => 'user-uuid',
    ], true);
    $driver->userForTest = $user;
    $driver->setRelation('user', $user);

    $controller                     = new FleetOpsApiDriverControllerProbe();
    $controller->driver             = $driver;
    $controller->sessionCompanyUuid = 'session-company-uuid';

    $controller->update('driver_public', new UpdateDriverRequest([
        'name'     => 'Driver Updated',
        'password' => 'attacker-chosen-password',
    ]));

    expect($user->updates)->not->toBeEmpty()
        ->and($user->updates[0])->not->toHaveKey('password')
        ->and($user->updates[0])->toHaveKey('name');
});

test('api driver controller accepts every safe driver field the model exposes', function () {
    $payload = [
        // Identity
        'internal_id'    => 'DRV-9001', 'drivers_license_number' => 'S1234567A',
        'license_expiry' => '2030-06-30',
        // Operational
        'country'        => 'SG', 'currency' => 'SGD', 'city' => 'Singapore', 'online' => true,
        'current_status' => 'on_break', 'status' => 'available',
        'heading'        => 180, 'bearing' => 90, 'altitude' => 15, 'speed' => 42,
        // Structured / orchestrator
        'meta'              => ['depot' => 'north'], 'skills' => ['hazmat'],
        'max_travel_time'   => 28800, 'max_distance' => 250000,
        'time_window_start' => '08:00', 'time_window_end' => '18:00',
    ];

    $controller = new FleetOpsApiDriverControllerProbe();
    $input      = $controller->inputForTest(new Request($payload));

    $missing = array_values(array_diff(array_keys($payload), array_keys($input)));

    expect($missing)->toBe([])
        ->and($input)->toMatchArray($payload);
});

test('api driver controller input excludes authentication tenancy and generated columns', function () {
    // The projection used to be an `except()` blocklist, so anything nobody had
    // thought to name reached Driver::create() intact — including the auth token.
    $controller = new FleetOpsApiDriverControllerProbe();

    $input = $controller->inputForTest(new Request([
        'internal_id'       => 'DRV-1',
        'auth_token'        => 'forged',
        'signup_token_used' => true,
        'user_uuid'         => 'forged',
        'company_uuid'      => 'someone-elses-company',
        'vehicle_uuid'      => 'forged',
        'vendor_uuid'       => 'forged',
        'current_job_uuid'  => 'forged',
        '_key'              => 'forged',
        'uuid'              => 'forged',
        'public_id'         => 'driver_forged',
        'slug'              => 'forged',
        'avatar_url'        => 'forged',
    ]));

    expect(array_keys($input))->toBe(['internal_id']);
});

test('api driver controller persists meta location and telemetry that the blocklist used to drop', function () {
    // `location`, `heading`, `altitude`, `speed` and `meta` were all on the old
    // `except()` list, so every write silently discarded them — and `meta` could
    // not be mass assigned anyway because the model spelled it `meta,`.
    $controller = new FleetOpsApiDriverControllerProbe();

    $input = $controller->inputForTest(new Request([
        'meta'     => ['badge' => 'A12'],
        'heading'  => 270,
        'altitude' => 8,
        'speed'    => 55,
    ]));

    expect($input)->toMatchArray([
        'meta'     => ['badge' => 'A12'],
        'heading'  => 270,
        'altitude' => 8,
        'speed'    => 55,
    ])->and(in_array('meta', (new Driver())->getFillable(), true))->toBeTrue();
});

test('api driver controller creates an operational driver with no email or phone', function () {
    $controller = new FleetOpsApiDriverControllerProbe();

    $response = $controller->create(new CreateDriverRequest([
        'name'        => 'Yard Driver',
        'internal_id' => 'DRV-7788',
    ]));

    // No invented address, no invented number: the user record simply carries
    // neither, which the users table permits. The driver cannot sign in to
    // Navigator until credentials are supplied.
    expect($response)->toBe(['resource' => 'driver', 'driver' => $controller->driver])
        ->and($controller->createdUsers[0])->toMatchArray(['name' => 'Yard Driver'])
        ->and($controller->createdUsers[0]['email'] ?? null)->toBeNull()
        ->and($controller->createdUsers[0]['phone'] ?? null)->toBeNull()
        ->and($controller->createdDrivers[0])->toMatchArray([
            'internal_id'  => 'DRV-7788',
            'user_uuid'    => 'user-uuid',
            'company_uuid' => 'company-uuid',
            'status'       => 'available',
        ])
        // The Driver-to-User relationship, organization membership, user type
        // and role are all preserved for a credential-less driver.
        ->and($controller->user->assignedCompanies)->toBe(['company-uuid'])
        ->and($controller->user->assignedTypes)->toBe(['driver'])
        ->and($controller->user->assignedRoles)->toBe(['Driver']);
});

test('api driver controller creates a driver with only one contact method', function () {
    $emailOnly = new FleetOpsApiDriverControllerProbe();
    $emailOnly->create(new CreateDriverRequest([
        'name'  => 'Email Only',
        'email' => 'email.only@example.test',
    ]));

    $phoneOnly = new FleetOpsApiDriverControllerProbe();
    $phoneOnly->create(new CreateDriverRequest([
        'name'  => 'Phone Only',
        'phone' => '+15550001111',
    ]));

    expect($emailOnly->createdUsers[0])->toMatchArray(['email' => 'email.only@example.test'])
        ->and($emailOnly->createdUsers[0]['phone'] ?? null)->toBeNull()
        ->and($phoneOnly->createdUsers[0])->toMatchArray(['phone' => '+15550001111'])
        ->and($phoneOnly->createdUsers[0]['email'] ?? null)->toBeNull();
});

test('api driver controller rejects a relationship that belongs to another company', function () {
    $controller               = new FleetOpsApiDriverControllerProbe();
    $controller->unresolvable = ['vehicle_other_company'];

    $created = $controller->create(new CreateDriverRequest([
        'name'    => 'Driver One',
        'vehicle' => 'vehicle_other_company',
    ]));

    $controller               = new FleetOpsApiDriverControllerProbe();
    $controller->unresolvable = ['vendor_other_company'];

    $updated = $controller->update('driver_public', new UpdateDriverRequest([
        'vendor' => 'vendor_other_company',
    ]));

    expect($created)->toBe([
        'json'   => ['error' => 'No vehicle resource found for the identifier provided.'],
        'status' => 404,
    ])->and($updated)->toBe([
        'json'   => ['error' => 'No vendor resource found for the identifier provided.'],
        'status' => 404,
    ]);
});

test('api driver controller clears a relationship when the input is sent empty', function () {
    $controller = new FleetOpsApiDriverControllerProbe();

    $input = $controller->inputForTest(new Request(['vehicle' => null, 'vendor' => '', 'job' => null]));

    expect($input)->toMatchArray([
        'vehicle_uuid'     => null,
        'vendor_uuid'      => null,
        'current_job_uuid' => null,
    ])->and($controller->relationLookups)->toBe([]);
});

test('api driver controller resolves driver relationships inside the company the driver is created in', function () {
    // A create request may name a company explicitly, and the relationships must
    // be looked up in that company rather than in whatever the session holds.
    $controller = new FleetOpsApiDriverControllerProbe();

    $controller->create(new CreateDriverRequest([
        'company' => 'company_public',
        'name'    => 'Driver One',
        'vehicle' => 'vehicle_public',
        'vendor'  => 'vendor_public',
    ]));

    expect($controller->relationCompanyScopes)->toBe(['company-uuid', 'company-uuid']);
});

test('api driver controller keeps every relationship the base contract returned', function () {
    // The SDK stores what the API returns verbatim, and Navigator interpolates
    // `driver.user` straight into a socket channel name — an object there would
    // subscribe it to `user.[object Object]` and quietly stop delivering
    // messages. These endpoints have always loaded user, vehicle, vendor and
    // currentJob, so they must keep doing it without a `with` parameter.
    $controller = new FleetOpsApiDriverControllerProbe();

    $controller->create(new CreateDriverRequest([
        'name'    => 'Driver One',
        'vehicle' => 'vehicle_public',
        'vendor'  => 'vendor_public',
        'job'     => 'order_public',
    ]));

    expect($controller->driver->loaded)->toContain(['user', 'vehicle', 'vendor', 'currentJob']);

    $updateController         = new FleetOpsApiDriverControllerProbe();
    $updateController->update('driver_public', new UpdateDriverRequest(['name' => 'Renamed']));

    expect($updateController->driver->loaded)->toContain(['user', 'vehicle', 'vendor', 'currentJob'])
        ->and($updateController->findCalls)->toContain(['driver_public', ['user']]);
});

test('api driver controller copies timezone through to the linked user account', function () {
    // `timezone` is documented and accepted on both create and update, but the
    // update only ever copied name, email and phone — so the request validated,
    // answered 200, and dropped it. The driver has no timezone column; the
    // linked user does.
    $create = new FleetOpsApiDriverControllerProbe();
    $create->create(new CreateDriverRequest([
        'name'     => 'Driver One',
        'timezone' => 'Asia/Singapore',
    ]));

    $user = new FleetOpsApiDriverUserFake();
    $user->setRawAttributes(['uuid' => 'user-uuid'], true);

    $driver = new FleetOpsApiDriverFake();
    $driver->setRawAttributes(['uuid' => 'driver-uuid', 'public_id' => 'driver_public', 'user_uuid' => 'user-uuid'], true);
    $driver->userForTest = $user;
    $driver->setRelation('user', $user);

    $update         = new FleetOpsApiDriverControllerProbe();
    $update->driver = $driver;
    $update->update('driver_public', new UpdateDriverRequest([
        'name'     => 'Driver One',
        'timezone' => 'Europe/Amsterdam',
    ]));

    expect($create->createdUsers[0])->toMatchArray(['timezone' => 'Asia/Singapore'])
        ->and($user->updates)->toContain([
            'name'     => 'Driver One',
            'timezone' => 'Europe/Amsterdam',
        ]);
});

test('api driver controller only expands relationships the public contract allows', function () {
    $controller = new FleetOpsApiDriverControllerProbe();
    $request    = new CreateDriverRequest(['name' => 'Driver One', 'with' => ['vehicle', 'current_job', 'user', 'company', 'nope']]);

    $controller->create($request);

    // `user` and `company` are published as public-id strings. Expanding either
    // would retype a released field, so neither is expandable at all; an unknown
    // name is dropped rather than reaching Eloquent, where it would be a 500.
    expect($request->input('with'))->toBe(['vehicle', 'currentJob']);
});
