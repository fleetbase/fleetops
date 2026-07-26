<?php

if (!function_exists('Fleetbase\FleetOps\Models\session')) {
    eval('namespace Fleetbase\FleetOps\Models; function session($key = null, $default = null) { return $key === "company" ? "company-driver" : $default; }');
}

if (!function_exists('Fleetbase\Traits\config')) {
    eval('namespace Fleetbase\Traits; function config($key = null, $default = null) { return $key === "api.cache.enabled" ? false : $default; }');
}

if (!function_exists('Fleetbase\Models\config')) {
    eval('namespace Fleetbase\Models; function config($key = null, $default = null) { return $key === "fleetbase.connection.db" ? "mysql" : $default; }');
}

if (!function_exists('Fleetbase\FleetOps\Support\base_path')) {
    eval('namespace Fleetbase\FleetOps\Support; function base_path($path = "") { return getcwd() . "/" . str_replace("vendor/fleetbase/fleetops-api/", "", $path); }');
}

use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\Models\User;
use Illuminate\Broadcasting\Channel;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Carbon;

class FleetOpsDriverUnitDatabaseProbe
{
    public function __construct(private SQLiteConnection $connection)
    {
    }

    public function connection(): SQLiteConnection
    {
        return $this->connection;
    }

    public function raw(string $value)
    {
        return $this->connection->raw($value);
    }
}

class FleetOpsDriverUnitFake extends Driver
{
    public array $loadedMissing = [];
    public array $loaded        = [];
    public array $updates       = [];
    public bool $saved          = false;

    public function loadMissing($relations)
    {
        $this->loadedMissing[] = $relations;

        return $this;
    }

    public function load($relations)
    {
        $this->loaded[] = $relations;

        return $this;
    }

    public function update(array $attributes = [], array $options = []): bool
    {
        $this->updates[] = $attributes;
        $this->forceFill($attributes);

        return true;
    }

    public function save(array $options = []): bool
    {
        $this->saved = true;

        return true;
    }
}

function fleetopsDriverUnitUseInMemoryConnection(): SQLiteConnection
{
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
    $resolver   = new ConnectionResolver([
        'default' => $connection,
        'mysql'   => $connection,
    ]);

    $resolver->setDefaultConnection('mysql');
    EloquentModel::setConnectionResolver($resolver);
    app()->instance('db', new FleetOpsDriverUnitDatabaseProbe($connection));
    app()->instance('db.schema', $connection->getSchemaBuilder());

    $schema = $connection->getSchemaBuilder();
    $schema->create('drivers', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('user_uuid')->nullable();
        $table->string('vehicle_uuid')->nullable();
        $table->timestamp('created_at')->nullable();
        $table->timestamp('updated_at')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('users', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('files', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('url')->nullable();
        $table->string('type')->nullable();
        $table->string('original_filename')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });

    return $connection;
}

test('driver mutators notification routes broadcast and assignment helpers are stable', function () {
    $connection = fleetopsDriverUnitUseInMemoryConnection();
    $connection->table('users')->insert(['uuid' => 'other-user']);
    $connection->table('drivers')->insert([
        'uuid'         => 'other-driver',
        'user_uuid'    => 'other-user',
        'vehicle_uuid' => 'vehicle-uuid',
    ]);

    Carbon::setTestNow(Carbon::parse('2026-07-27 09:30:00'));

    $fresh = new FleetOpsDriverUnitFake();
    $fresh->setLicenseExpiryAttribute(null);

    $existing         = new FleetOpsDriverUnitFake();
    $existing->exists = true;
    $existing->setRawAttributes(['license_expiry' => '2026-12-31'], true);
    $existing->setLicenseExpiryAttribute('');

    $driver = new FleetOpsDriverUnitFake();
    $driver->setRawAttributes([
        'uuid'      => 'driver-uuid',
        'public_id' => 'driver_public',
        'heading'   => 90,
        'status'    => 'available',
    ], true);
    $driver->setLicenseExpiryAttribute('August 15 2026');
    $driver->status = 'active';
    $driver->status = null;
    $driver->status = 'off-duty';
    $driver->setRelation('devices', collect([
        (object) ['platform' => 'android', 'token' => 'fcm-a'],
        (object) ['platform' => 'ios', 'token' => 'apn-a'],
        (object) ['platform' => 'web', 'token' => 'web-a'],
        (object) ['platform' => 'android', 'token' => 'fcm-b'],
    ]));

    $vehicle = new Vehicle();
    $vehicle->setRawAttributes([
        'uuid'         => 'vehicle-uuid',
        'display_name' => 'Truck 42',
        'avatar_url'   => 'https://cdn.test/vehicle.png',
    ], true);

    expect($fresh->getAttributes()['license_expiry'])->toBeNull()
        ->and($existing->getAttributes()['license_expiry'])->toBe('2026-12-31')
        ->and($driver->getAttributes()['license_expiry'])->toBe('2026-08-15')
        ->and($driver->status)->toBe('off-duty')
        ->and($driver->routeNotificationForFcm())->toBe([0 => 'fcm-a', 3 => 'fcm-b'])
        ->and($driver->routeNotificationForApn())->toBe([1 => 'apn-a'])
        ->and($driver->receivesBroadcastNotificationsOn(new stdClass()))->toBeInstanceOf(Channel::class)
        ->and((string) $driver->receivesBroadcastNotificationsOn(new stdClass()))->toBe('driver.driver_public')
        ->and($driver->rotation)->toBe(180.0)
        ->and($driver->isVehicleNotAssigned())->toBeTrue()
        ->and($driver->isVehicleAssigned())->toBeFalse()
        ->and($driver->assignVehicle($vehicle))->toBe($driver)
        ->and($driver->saved)->toBeTrue()
        ->and($driver->vehicle_uuid)->toBe('vehicle-uuid')
        ->and($driver->vehicle)->toBe($vehicle)
        ->and($connection->table('drivers')->where('uuid', 'other-driver')->value('vehicle_uuid'))->toBeNull()
        ->and($driver->isVehicleAssigned())->toBeTrue()
        ->and($driver->unassignCurrentJob())->toBeTrue()
        ->and($driver->updates)->toBe([['current_job_uuid' => null]])
        ->and($driver->unassignCurrentOrder())->toBeTrue()
        ->and($driver->updates[1])->toBe(['current_job_uuid' => null]);

    Carbon::setTestNow();
});

test('driver avatar user and current order helpers prefer loaded relations', function () {
    fleetopsDriverUnitUseInMemoryConnection();

    $vehicle = (object) [
        'display_name' => 'Loaded Vehicle',
        'avatar_url'   => 'https://cdn.test/loaded-vehicle.png',
    ];

    $userProfile = (object) [
        'avatar'    => 'avatar-object',
        'avatarUrl' => 'https://cdn.test/driver.png',
        'name'      => 'Dana Driver',
        'phone'     => '+15551234567',
        'email'     => 'dana@example.test',
    ];

    $driver = new FleetOpsDriverUnitFake();
    $driver->setRelation('vehicle', $vehicle);
    $driver->setRelation('user', $userProfile);

    $user = new User();
    $user->setRawAttributes([
        'uuid' => 'loaded-user',
    ], true);

    $userDriver = new FleetOpsDriverUnitFake();
    $userDriver->setRawAttributes([
        'user_uuid' => 'not-a-real-uuid',
    ], true);
    $userDriver->setRelation('user', $user);

    $order = new class extends Order {
        public array $loadedMissing = [];

        public function loadMissing($relations)
        {
            $this->loadedMissing[] = $relations;

            return $this;
        }
    };
    $order->setRawAttributes(['uuid' => 'order-uuid'], true);

    $orderDriver = new FleetOpsDriverUnitFake();
    $orderDriver->setRelation('currentOrder', $order);

    $emptyOrderDriver = new FleetOpsDriverUnitFake();
    $emptyOrderDriver->setRelation('currentOrder', null);

    expect($driver->getAvatarUrlAttribute(null))->toBe('https://cdn.test/loaded-vehicle.png')
        ->and($driver->loadedMissing)->toBe(['vehicle'])
        ->and($driver->vehicle_name)->toBe('Loaded Vehicle')
        ->and($driver->photo)->toBe('avatar-object')
        ->and($driver->photo_url)->toBe('https://cdn.test/driver.png')
        ->and($driver->name)->toBe('Dana Driver')
        ->and($driver->phone)->toBe('+15551234567')
        ->and($driver->email)->toBe('dana@example.test')
        ->and($userDriver->getUser())->toBe($user)
        ->and($userDriver->loaded)->toBe([['user']])
        ->and($orderDriver->getCurrentOrder())->toBe($order)
        ->and($orderDriver->loadedMissing)->toBe(['currentOrder'])
        ->and($order->loadedMissing)->toBe(['payload'])
        ->and($emptyOrderDriver->getCurrentOrder())->toBeNull()
        ->and($emptyOrderDriver->loadedMissing)->toBe(['currentOrder']);
});

test('driver avatar options merge custom driver avatars with bundled defaults', function () {
    $connection = fleetopsDriverUnitUseInMemoryConnection();
    $connection->table('files')->insert([
        'uuid'              => 'avatar-uuid',
        'url'               => 'https://cdn.test/custom-driver.png',
        'type'              => 'driver-avatar',
        'original_filename' => 'helmet.png',
    ]);

    $options = Driver::getAvatarOptions();

    expect($options->get('Custom: helmet'))->toBe('avatar-uuid')
        ->and($options->has('moto-driver'))->toBeTrue()
        ->and(Driver::getAvatar('moto-driver'))->toBe($options->get('moto-driver'))
        ->and(Driver::getAvatar('11111111-1111-4111-8111-111111111111'))->toBeNull()
        ->and((new FleetOpsDriverUnitFake())->getAvatarUrlAttribute(null))->toBe($options->get('moto-driver'));
});
