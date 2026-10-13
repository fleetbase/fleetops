<?php

use Fleetbase\FleetOps\Models\AssetConnection;
use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\FleetOps\Models\Device;
use Fleetbase\FleetOps\Models\DeviceEvent;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\FleetDriver;
use Fleetbase\FleetOps\Models\FleetVehicle;
use Fleetbase\FleetOps\Models\IntegratedVendor;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Payload;
use Fleetbase\FleetOps\Models\ServiceArea;
use Fleetbase\FleetOps\Models\TrackingStatus;
use Fleetbase\FleetOps\Models\Trailer;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\FleetOps\Models\Vendor;
use Fleetbase\FleetOps\Models\Waypoint;
use Fleetbase\FleetOps\Support\ApiCacheThrottle;
use Fleetbase\Tests\Support\TrackingPageDatabase;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

if (!function_exists('Fleetbase\FleetOps\Models\dispatch')) {
    // Order::dispatch() queues its domain event; only the write and its invalidation matter here
    eval('namespace Fleetbase\FleetOps\Models; function dispatch($job) { return new class { public function afterCommit() { return $this; } }; }');
}

beforeEach(function () {
    Cache::swap(new Repository(new ArrayStore()));
    session(['company' => 'co-1']);
});

afterEach(function () {
    Carbon::setTestNow();
    EloquentModel::unsetConnectionResolver();
    EloquentModel::unsetEventDispatcher();
});

/**
 * The model's declared dependencies, read from the property because
 * getApiCacheDependencies() only exists from fleetbase/core-api#301 on.
 */
function apiCacheDeclaredDependencies(EloquentModel $model): array
{
    $property = new ReflectionProperty($model, 'apiCacheDependencies');
    $property->setAccessible(true);

    return $property->getValue($model);
}

/**
 * The relation method a dependency segment names, matched the way core does (as written, camel or snake case).
 */
function apiCacheRelationMethod(EloquentModel $model, string $segment): ?string
{
    foreach (array_unique([$segment, Str::camel($segment), Str::snake($segment)]) as $method) {
        if (!method_exists($model, $method)) {
            continue;
        }

        $reflection = new ReflectionMethod($model, $method);
        if ($reflection->isPublic() && !$reflection->isStatic() && $reflection->getNumberOfRequiredParameters() === 0) {
            return $method;
        }
    }

    return null;
}

function apiCacheQueryVersion(string $table, ?string $companyUuid): int
{
    return (int) Cache::get("api_query_version:{$table}:{$companyUuid}", 0);
}

function apiCacheDatabase(): SQLiteConnection
{
    $db     = TrackingPageDatabase::boot();
    $schema = $db->getSchemaBuilder();

    // Model hooks bind to the dispatcher present when a class boots
    EloquentModel::setEventDispatcher(new Dispatcher());
    EloquentModel::clearBootedModels();
    // Core models clear the HTTP response cache on writes
    app()->instance('responsecache', new class {
        public function __call($method, $arguments)
        {
            return null;
        }
    });

    foreach (['fleet_drivers' => 'driver_uuid', 'fleet_vehicles' => 'vehicle_uuid'] as $table => $member) {
        $schema->create($table, function ($table) use ($member) {
            $table->increments('id');
            $table->string('uuid')->nullable();
            $table->string('fleet_uuid')->nullable();
            $table->string($member)->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    $schema->create('asset_connections', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        foreach (['connector_type', 'connector_uuid', 'connected_type', 'connected_uuid', 'relationship_type'] as $column) {
            $table->string($column)->nullable();
        }
        $table->timestamps();
        $table->softDeletes();
    });

    $schema->table('orders', function ($table) {
        $table->timestamp('dispatched_at')->nullable();
    });

    return $db;
}

test('declared api cache dependencies resolve to relations or to the tables of morphTo targets', function (string $modelClass, array $morphTargets) {
    TrackingPageDatabase::boot();

    $model    = new $modelClass();
    $declared = apiCacheDeclaredDependencies($model);
    $named    = [];

    expect($declared)->not->toBeEmpty();

    foreach ($declared as $path) {
        $current  = $model;
        $segments = explode('.', $path);

        foreach ($segments as $segment) {
            $method = apiCacheRelationMethod($current, $segment);

            // core treats a single segment naming no method as a table name
            if ($method === null && count($segments) === 1) {
                $named[] = $path;
                continue 2;
            }

            expect($method)->not->toBeNull("{$modelClass} dependency {$path} has no relation {$segment}");

            $relation = Relation::noConstraints(fn () => $current->newInstance()->{$method}());

            expect($relation)->toBeInstanceOf(Relation::class)
                ->and($relation)->not->toBeInstanceOf(MorphTo::class);

            $current = $relation->getRelated();
        }
    }

    expect($named)->toEqualCanonicalizing(array_map(fn (string $target) => (new $target())->getTable(), $morphTargets));
})->with([
    'order'        => [Order::class, [Contact::class, Vendor::class, IntegratedVendor::class]],
    'driver'       => [Driver::class, []],
    'vehicle'      => [Vehicle::class, []],
    'device'       => [Device::class, [Vehicle::class, Trailer::class]],
    'device event' => [DeviceEvent::class, []],
    'vendor'       => [Vendor::class, []],
    'service area' => [ServiceArea::class, []],
]);

test('models only embedded by cached lists invalidate their table on writes', function () {
    $db = apiCacheDatabase();

    $fleetDriver = FleetDriver::create(['fleet_uuid' => 'fleet-1', 'driver_uuid' => 'driver-1']);
    expect(apiCacheQueryVersion('fleet_drivers', null))->toBe(1);

    $fleetDriver->delete();
    expect(apiCacheQueryVersion('fleet_drivers', null))->toBe(2);

    FleetVehicle::create(['fleet_uuid' => 'fleet-1', 'vehicle_uuid' => 'vehicle-1']);
    expect(apiCacheQueryVersion('fleet_vehicles', null))->toBe(1);

    AssetConnection::create(['company_uuid' => 'co-1', 'connector_type' => Vehicle::class, 'connector_uuid' => 'vehicle-1', 'connected_type' => Trailer::class, 'connected_uuid' => 'trailer-1']);
    expect(apiCacheQueryVersion('asset_connections', 'co-1'))->toBe(1);

    TrackingPageDatabase::insert($db, 'waypoints', ['uuid' => 'wp-1', 'company_uuid' => 'co-1', 'payload_uuid' => 'payload-1', 'order' => 0]);
    Waypoint::where('uuid', 'wp-1')->first()->update(['order' => 1]);
    expect(apiCacheQueryVersion('waypoints', 'co-1'))->toBe(1);
});

test('writes that skip model events invalidate the tables they write', function () {
    $db = apiCacheDatabase();

    // Order::dispatch() saves quietly
    TrackingPageDatabase::insert($db, 'orders', ['uuid' => 'order-1', 'company_uuid' => 'co-1', 'dispatched' => false]);
    Order::where('uuid', 'order-1')->first()->dispatch();
    expect(apiCacheQueryVersion('orders', 'co-1'))->toBe(1)
        ->and($db->table('orders')->where('uuid', 'order-1')->value('dispatched'))->toBe(1);

    // Waypoint::insertGetUuid() inserts with the query builder
    $waypointUuid = Waypoint::insertGetUuid(['place_uuid' => 'place-1', 'order' => 0]);
    expect($waypointUuid)->toBeString()
        ->and(apiCacheQueryVersion('waypoints', 'co-1'))->toBe(1);

    // Payload::removeWaypoints() deletes with the query builder
    TrackingPageDatabase::insert($db, 'payloads', ['uuid' => 'payload-1', 'company_uuid' => 'co-1']);
    $payload = new Payload();
    $payload->setRawAttributes(['uuid' => 'payload-1', 'company_uuid' => 'co-1'], true);
    $payload->removeWaypoints();
    expect(apiCacheQueryVersion('waypoints', 'co-1'))->toBe(2);

    // TrackingStatus::insertGetUuid() inserts with the query builder
    expect(TrackingStatus::insertGetUuid(['tracking_number_uuid' => 'tn-1', 'status' => 'Created']))->toBeString()
        ->and(apiCacheQueryVersion('tracking_statuses', 'co-1'))->toBe(1);
});

test('throttled invalidation runs at most once per window and table', function () {
    Carbon::setTestNow('2026-10-12 12:00:00');

    ApiCacheThrottle::invalidate(new Vehicle(), 'co-1');
    ApiCacheThrottle::invalidate(new Vehicle(), 'co-1');
    ApiCacheThrottle::invalidate(new Driver(), 'co-1');

    expect(apiCacheQueryVersion('vehicles', 'co-1'))->toBe(1)
        ->and(apiCacheQueryVersion('drivers', 'co-1'))->toBe(1);

    Carbon::setTestNow(Carbon::now()->addSeconds(ApiCacheThrottle::LOCATION_WINDOW + 1));
    ApiCacheThrottle::invalidate(new Vehicle(), 'co-1');

    expect(apiCacheQueryVersion('vehicles', 'co-1'))->toBe(2);
});

test('attaching or detaching a device invalidates the attached model lists, and device pings do not', function () {
    $db = apiCacheDatabase();
    $db->getSchemaBuilder()->create('devices', function ($table) {
        $table->increments('id');
        foreach (['uuid', 'public_id', 'company_uuid', 'attachable_type', 'attachable_uuid', 'name', 'status'] as $column) {
            $table->string($column)->nullable();
        }
        $table->text('meta')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    TrackingPageDatabase::insert($db, 'devices', ['uuid' => 'device-1', 'company_uuid' => 'co-1']);
    // attachTo() and detach() log activity; only the cache versions matter here
    app()->instance(Spatie\Activitylog\ActivityLogger::class, new class extends Spatie\Activitylog\ActivityLogger {
        public function __construct()
        {
        }

        public function useLog(?string $logName): static
        {
            return $this;
        }

        public function setLogStatus(Spatie\Activitylog\ActivityLogStatus $logStatus): static
        {
            return $this;
        }

        public function performedOn(EloquentModel $model): static
        {
            return $this;
        }

        public function withProperties(mixed $properties): static
        {
            return $this;
        }

        public function log(string $description): ?Spatie\Activitylog\Contracts\Activity
        {
            return null;
        }
    });
    app()->instance(Spatie\Activitylog\ActivityLogStatus::class, new Spatie\Activitylog\ActivityLogStatus(new Illuminate\Config\Repository(['activitylog' => ['enabled' => false]])));

    $vehicle = new Vehicle();
    $vehicle->setRawAttributes(['uuid' => 'vehicle-1', 'company_uuid' => 'co-1'], true);
    $device = Device::where('uuid', 'device-1')->first();

    expect($device->attachTo($vehicle))->toBeTrue()
        ->and(apiCacheQueryVersion('vehicles', 'co-1'))->toBe(1);

    // Telemetry saves the device row on every ping; vehicle lists no longer depend on it
    $device->update(['status' => 'online']);
    expect(apiCacheQueryVersion('vehicles', 'co-1'))->toBe(1);

    expect($device->detach())->toBeTrue()
        ->and(apiCacheQueryVersion('vehicles', 'co-1'))->toBe(2);

    app()->forgetInstance(Spatie\Activitylog\ActivityLogger::class);
    app()->forgetInstance(Spatie\Activitylog\ActivityLogStatus::class);
});

test('a cache outage never fails a throttled location invalidation', function () {
    Cache::swap(new Repository(new class extends ArrayStore {
        public function add($key, $value, $seconds)
        {
            throw new RuntimeException('cache unavailable');
        }
    }));

    ApiCacheThrottle::invalidate(new Vehicle(), 'co-1');

    expect(true)->toBeTrue();
});

test('telemetry writes fire their model events but invalidate at most once per window', function () {
    Carbon::setTestNow('2026-10-12 12:00:00');
    $vehicle = new Vehicle();
    $vehicle->setRawAttributes(['uuid' => 'vehicle-1', 'company_uuid' => 'co-1'], true);
    $writes = 0;
    // Stands in for an evented save, whose model hooks invalidate the table
    $save = function () use ($vehicle, &$writes) {
        $writes++;
        Fleetbase\Support\ApiModelCache::invalidateModelCache($vehicle, 'co-1');

        return true;
    };

    foreach (range(1, 3) as $ping) {
        expect(ApiCacheThrottle::telemetryWrite($vehicle, $save))->toBeTrue();
    }

    expect($writes)->toBe(3);
    if (method_exists(Fleetbase\Support\ApiModelCache::class, 'withoutInvalidating')) {
        // fleetbase/core-api#301: the saves' own invalidations are coalesced into one per window
        expect(apiCacheQueryVersion('vehicles', 'co-1'))->toBe(1);
    } else {
        // An older core keeps invalidating on every save, as before, with no extra throttled bump
        expect(apiCacheQueryVersion('vehicles', 'co-1'))->toBe(3);
    }
});
