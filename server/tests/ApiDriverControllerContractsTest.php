<?php

use Fleetbase\FleetOps\Http\Controllers\Api\v1\DriverController;
use Fleetbase\FleetOps\Http\Requests\CreateDriverRequest;
use Fleetbase\FleetOps\Http\Requests\UpdateDriverRequest;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Vehicle;
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
            'vehicle_uuid'     => 'vehicles-uuid',
            'vendor_uuid'      => 'vendors-uuid',
            'current_job_uuid' => 'orders-uuid',
            'online'           => 0,
            'user_uuid'        => 'user-uuid',
            'company_uuid'     => 'company-uuid',
        ])
        ->and($controller->createdDrivers[0]['location'])->toBeInstanceOf(Point::class)
        ->and($controller->uuidLookups)->toContain(
            ['vehicles', ['public_id' => 'vehicle_public', 'company_uuid' => 'company-uuid'], []],
            ['vendors', ['public_id' => 'vendor_public', 'company_uuid' => 'company-uuid'], []],
            ['orders', ['public_id'  => 'order_public', 'company_uuid' => 'company-uuid'], []]
        )
        ->and($controller->resolvedFiles)->toBe([['file_public', 'uploads/company-uuid/drivers']])
        ->and($controller->user->updates)->toContain(['photo_uuid' => 'photo-file-uuid'])
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
            'vehicle_uuid'     => 'vehicles-uuid',
            'vendor_uuid'      => 'vendors-uuid',
            'current_job_uuid' => 'orders-uuid',
        ])
        ->and($driver->updates[0]['location'])->toBeInstanceOf(Point::class)
        ->and($driver->flushedForTest)->toBeTrue()
        ->and($controller->resolvedFiles)->toBe([['photo-input', 'uploads/session-company-uuid/drivers']])
        ->and($user->updates)->toContain(['photo_uuid' => 'photo-file-uuid'])
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
