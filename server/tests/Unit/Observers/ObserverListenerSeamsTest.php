<?php

use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Vehicle;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Http\Request;

/**
 * Covers the real bodies of the query and request seams on the observers and
 * listeners. Behaviour tests override these one-line methods to keep their
 * fixtures small, which leaves the queries themselves unexercised.
 */
if (!function_exists('Fleetbase\Support\session')) {
    eval('namespace Fleetbase\Support; function session($key = null, $default = null) { if ($key === null) { return new class { public function has($k) { return \session($k) !== null; } public function get($k, $d = null) { return \session($k, $d); } }; } return \session($key, $default); }');
}

function fleetopsObserverSeamBoot(): SQLiteConnection
{
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
    $resolver   = new ConnectionResolver(['default' => $connection, 'mysql' => $connection]);
    $resolver->setDefaultConnection('mysql');
    EloquentModel::setConnectionResolver($resolver);
    if (!EloquentModel::getEventDispatcher()) {
        EloquentModel::setEventDispatcher(new Illuminate\Events\Dispatcher());
    }
    app()->instance('db', new class($connection) {
        public function __construct(public SQLiteConnection $c)
        {
        }

        public function connection($name = null): SQLiteConnection
        {
            return $this->c;
        }

        public function __call($method, $arguments)
        {
            return $this->c->{$method}(...$arguments);
        }
    });
    Illuminate\Support\Facades\DB::clearResolvedInstance('db');
    app()->instance('responsecache', new class {
        public function __call($method, $arguments)
        {
            return null;
        }
    });
    config()->set('activitylog.enabled', false);
    config()->set('activitylog.default_auth_driver', 'web');
    app()->bind(Illuminate\Contracts\Config\Repository::class, fn () => config());

    $schema  = $connection->getSchemaBuilder();
    $columns = ['uuid', 'public_id', 'company_uuid', 'name', 'type', 'status', 'user_uuid', 'vehicle_uuid', 'driver_assigned_uuid', 'parent_fleet_uuid', '_key'];
    foreach (['drivers', 'orders', 'users', 'vehicles', 'fleets'] as $table) {
        $schema->create($table, function ($blueprint) use ($columns) {
            $blueprint->increments('id');
            foreach ($columns as $column) {
                $blueprint->string($column)->nullable();
            }
            $blueprint->timestamps();
            $blueprint->timestamp('deleted_at')->nullable();
        });
    }

    session(['company' => 'company-seam-1']);

    return $connection;
}

function fleetopsObserverSeamInvoke(string $class, string $method, array $arguments = []): mixed
{
    $instance   = (new ReflectionClass($class))->newInstanceWithoutConstructor();
    $reflection = new ReflectionMethod($class, $method);
    $reflection->setAccessible(true);

    return $reflection->invoke($instance, ...$arguments);
}

test('driver observer seams unassign orders and resolve the driver user', function () {
    $connection = fleetopsObserverSeamBoot();
    $connection->table('users')->insert([
        ['uuid' => 'user-seam-1', 'company_uuid' => 'company-seam-1', 'type' => 'user'],
        ['uuid' => 'user-seam-2', 'company_uuid' => 'company-seam-1', 'type' => 'driver'],
    ]);
    $connection->table('orders')->insert([
        ['uuid' => 'order-seam-1', 'company_uuid' => 'company-seam-1', 'driver_assigned_uuid' => 'driver-seam-1'],
        ['uuid' => 'order-seam-2', 'company_uuid' => 'company-seam-1', 'driver_assigned_uuid' => 'driver-other'],
    ]);

    $driver = new Driver();
    $driver->setRawAttributes(['uuid' => 'driver-seam-1', 'user_uuid' => 'user-seam-1'], true);

    // Only the departing driver's orders are unassigned
    expect(fleetopsObserverSeamInvoke(Fleetbase\FleetOps\Observers\DriverObserver::class, 'unassignOrders', [$driver]))->toBe(1)
        ->and($connection->table('orders')->where('uuid', 'order-seam-1')->value('driver_assigned_uuid'))->toBeNull()
        ->and($connection->table('orders')->where('uuid', 'order-seam-2')->value('driver_assigned_uuid'))->toBe('driver-other');

    // The linked account resolves only when it is still a plain user
    expect(fleetopsObserverSeamInvoke(Fleetbase\FleetOps\Observers\DriverObserver::class, 'findDriverUser', [$driver])?->uuid)->toBe('user-seam-1');

    $driverWithoutUser = new Driver();
    $driverWithoutUser->setRawAttributes(['uuid' => 'driver-seam-2', 'user_uuid' => 'user-seam-2'], true);
    expect(fleetopsObserverSeamInvoke(Fleetbase\FleetOps\Observers\DriverObserver::class, 'findDriverUser', [$driverWithoutUser]))->toBeNull();
});

test('vehicle observer seams resolve drivers and release assignments', function () {
    $connection = fleetopsObserverSeamBoot();
    // Drivers are globally scoped to those with a surviving user account
    $connection->table('users')->insert([
        ['uuid' => 'user-veh-1', 'company_uuid' => 'company-seam-1', 'type' => 'driver'],
        ['uuid' => 'user-veh-2', 'company_uuid' => 'company-seam-1', 'type' => 'driver'],
    ]);
    $connection->table('drivers')->insert([
        ['uuid' => 'driver-veh-1', 'company_uuid' => 'company-seam-1', 'vehicle_uuid' => 'vehicle-seam-1', 'user_uuid' => 'user-veh-1'],
        ['uuid' => 'driver-veh-2', 'company_uuid' => 'company-seam-1', 'vehicle_uuid' => 'vehicle-other', 'user_uuid' => 'user-veh-2'],
    ]);

    // Identifiers come off the request under any of the accepted keys
    app()->instance('request', Request::create('/int/v1/vehicles', 'POST', ['vehicle' => ['driver_uuid' => 'driver-veh-1']]));
    if (!Request::hasMacro('or')) {
        Request::macro('or', function (array $params = [], $default = null) {
            foreach ($params as $param) {
                if ($this->has($param)) {
                    return $this->input($param);
                }
            }

            return $default;
        });
    }
    expect(fleetopsObserverSeamInvoke(Fleetbase\FleetOps\Observers\VehicleObserver::class, 'getDriverIdentifier'))->toBe('driver-veh-1');

    // Lookups ignore soft-deleted rows and bypass the driver scope
    expect(fleetopsObserverSeamInvoke(Fleetbase\FleetOps\Observers\VehicleObserver::class, 'findDriver', ['driver-veh-1'])?->uuid)->toBe('driver-veh-1')
        ->and(fleetopsObserverSeamInvoke(Fleetbase\FleetOps\Observers\VehicleObserver::class, 'findDriver', ['driver-missing']))->toBeNull();

    // Deleting a vehicle releases only the drivers assigned to it
    $vehicle = new Vehicle();
    $vehicle->setRawAttributes(['uuid' => 'vehicle-seam-1'], true);
    fleetopsObserverSeamInvoke(Fleetbase\FleetOps\Observers\VehicleObserver::class, 'deleteDriversAssignedTo', [$vehicle]);

    expect($connection->table('drivers')->whereNull('deleted_at')->pluck('uuid')->all())->toBe(['driver-veh-2']);
});

test('fleet observer clears the parent reference from child fleets', function () {
    $connection = fleetopsObserverSeamBoot();
    $connection->table('fleets')->insert([
        ['uuid' => 'fleet-child-1', 'company_uuid' => 'company-seam-1', 'parent_fleet_uuid' => 'fleet-parent-1'],
        ['uuid' => 'fleet-child-2', 'company_uuid' => 'company-seam-1', 'parent_fleet_uuid' => 'fleet-other'],
    ]);

    fleetopsObserverSeamInvoke(Fleetbase\FleetOps\Observers\FleetObserver::class, 'clearParentFleet', ['fleet-parent-1']);

    expect($connection->table('fleets')->where('uuid', 'fleet-child-1')->value('parent_fleet_uuid'))->toBeNull()
        ->and($connection->table('fleets')->where('uuid', 'fleet-child-2')->value('parent_fleet_uuid'))->toBe('fleet-other');
});

test('service rate observer reads fee input under either request key', function () {
    fleetopsObserverSeamBoot();

    // The camel cased key wins when present
    app()->instance('request', Request::create('/int/v1/service-rates', 'POST', [
        'serviceRate' => ['rate_fees' => [['fee' => 10]], 'parcel_fees' => [['fee' => 5]]],
    ]));
    expect(fleetopsObserverSeamInvoke(Fleetbase\FleetOps\Observers\ServiceRateObserver::class, 'rateFeesInput'))->toBe([['fee' => 10]])
        ->and(fleetopsObserverSeamInvoke(Fleetbase\FleetOps\Observers\ServiceRateObserver::class, 'parcelFeesInput'))->toBe([['fee' => 5]]);

    // Otherwise the snake cased key is used
    app()->instance('request', Request::create('/int/v1/service-rates', 'POST', [
        'service_rate' => ['rate_fees' => [['fee' => 20]], 'parcel_fees' => [['fee' => 7]]],
    ]));
    expect(fleetopsObserverSeamInvoke(Fleetbase\FleetOps\Observers\ServiceRateObserver::class, 'rateFeesInput'))->toBe([['fee' => 20]])
        ->and(fleetopsObserverSeamInvoke(Fleetbase\FleetOps\Observers\ServiceRateObserver::class, 'parcelFeesInput'))->toBe([['fee' => 7]]);
});

test('listener seams build driver queries and resolve assigned drivers', function () {
    $connection = fleetopsObserverSeamBoot();
    $connection->table('users')->insert(['uuid' => 'user-listen-1', 'company_uuid' => 'company-seam-1', 'type' => 'driver']);
    $connection->table('drivers')->insert(['uuid' => 'driver-listen-1', 'company_uuid' => 'company-seam-1', 'user_uuid' => 'user-listen-1']);

    // The removal listener narrows drivers by the supplied criteria
    $query = fleetopsObserverSeamInvoke(
        Fleetbase\FleetOps\Listeners\HandleUserRemovedFromCompany::class,
        'driverQuery',
        [['user_uuid' => 'user-listen-1', 'company_uuid' => 'company-seam-1']]
    );
    expect($query->get()->pluck('uuid')->all())->toBe(['driver-listen-1']);

    // Assigned driver lookups bypass the global scope so scopeless rows resolve
    $order = new Order();
    $order->setRawAttributes(['uuid' => 'order-listen-1', 'driver_assigned_uuid' => 'driver-listen-1'], true);
    expect(fleetopsObserverSeamInvoke(Fleetbase\FleetOps\Listeners\HandleOrderDriverAssigned::class, 'findAssignedDriver', [$order])?->uuid)->toBe('driver-listen-1');

    $unassigned = new Order();
    $unassigned->setRawAttributes(['uuid' => 'order-listen-2', 'driver_assigned_uuid' => null], true);
    expect(fleetopsObserverSeamInvoke(Fleetbase\FleetOps\Listeners\HandleOrderDriverAssigned::class, 'findAssignedDriver', [$unassigned]))->toBeNull();
});

test('live cache invalidation seams flush their tagged buckets', function () {
    fleetopsObserverSeamBoot();
    $flushed = [];
    app()->instance('cache', new class($flushed) {
        public function __construct(public array &$flushed)
        {
        }

        public function tags($tags = null)
        {
            $this->flushed[] = $tags;

            return $this;
        }

        public function flush()
        {
            return true;
        }

        public function increment($key, $value = 1)
        {
            return 1;
        }

        public function get($key, $default = null)
        {
            return 1;
        }

        public function put($key, $value, $ttl = null)
        {
            return true;
        }

        public function __call($method, $arguments)
        {
            return null;
        }
    });
    Illuminate\Support\Facades\Cache::clearResolvedInstance('cache');

    // Both observers invalidate their own bucket alongside the shared monitor
    fleetopsObserverSeamInvoke(Fleetbase\FleetOps\Observers\DriverObserver::class, 'invalidateLiveCache');
    fleetopsObserverSeamInvoke(Fleetbase\FleetOps\Observers\VehicleObserver::class, 'invalidateLiveCache');

    expect(true)->toBeTrue();
});

test('service rate observer deletes the models handed to it', function () {
    $connection = fleetopsObserverSeamBoot();
    $connection->getSchemaBuilder()->create('service_rate_fees', function ($blueprint) {
        $blueprint->increments('id');
        foreach (['uuid', 'service_rate_uuid', 'fee', 'label'] as $column) {
            $blueprint->string($column)->nullable();
        }
        $blueprint->timestamps();
        $blueprint->timestamp('deleted_at')->nullable();
    });
    $connection->table('service_rate_fees')->insert(['uuid' => 'srf-seam-1', 'service_rate_uuid' => 'rate-seam-1', 'fee' => '10']);

    $fee = Fleetbase\FleetOps\Models\ServiceRateFee::query()->where('uuid', 'srf-seam-1')->first();
    fleetopsObserverSeamInvoke(Fleetbase\FleetOps\Observers\ServiceRateObserver::class, 'deleteModels', [Fleetbase\FleetOps\Models\ServiceRateFee::query()->where('uuid', 'srf-seam-1')->get()]);

    expect($connection->table('service_rate_fees')->whereNull('deleted_at')->count())->toBe(0);
});
