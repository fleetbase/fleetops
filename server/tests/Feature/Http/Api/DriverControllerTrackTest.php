<?php

use Fleetbase\FleetOps\Http\Controllers\Api\v1\DriverController;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Position;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\FleetOps\Support\GeofenceIntersectionService;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Fleetbase\Models\Model as FleetbaseModel;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

if (!function_exists('Fleetbase\Traits\config')) {
    eval('namespace Fleetbase\Traits; function config($key = null, $default = null) { return $key === "api.cache.enabled" ? false : $default; }');
}

if (!function_exists('Fleetbase\Models\config')) {
    eval('namespace Fleetbase\Models; function config($key = null, $default = null) { return $key === "fleetbase.connection.db" ? "mysql" : $default; }');
}

if (!function_exists('Fleetbase\FleetOps\Http\Controllers\Api\v1\broadcast')) {
    eval('namespace Fleetbase\FleetOps\Http\Controllers\Api\v1; function broadcast($event) { $GLOBALS["fleetops_api_driver_track_broadcasts"][] = $event; return $event; }');
}

class FleetOpsApiDriverTrackControllerProbe extends DriverController
{
    public FleetOpsApiDriverTrackDriverFake $driver;

    protected function findDriver(string $id, array $with = []): Driver
    {
        $this->driver->lookupId = $id;

        return $this->driver;
    }

    protected function driverResource(Driver $driver)
    {
        return ['resource' => 'driver', 'driver' => $driver];
    }

    protected function apiError(string $message, int $status = 400)
    {
        return ['apiError' => $message, 'status' => $status];
    }
}

class FleetOpsApiDriverTrackDriverFake extends Driver
{
    public array $quietUpdates  = [];
    public array $positions     = [];
    public array $loaded        = [];
    public ?string $lookupId    = null;
    public ?Order $orderForTest = null;

    public function getAttribute($key)
    {
        if ($this->relationLoaded($key)) {
            return $this->relations[$key];
        }

        return $this->attributes[$key] ?? null;
    }

    public function updateQuietly(array $attributes = [], array $options = []): bool
    {
        $this->quietUpdates[] = $attributes;
        $this->setRawAttributes(array_merge($this->getAttributes(), $attributes), true);

        return true;
    }

    public function createPosition(array $attributes = [], FleetbaseModel|string|null $destination = null): ?Position
    {
        $this->positions[] = $attributes;

        return null;
    }

    public function loadMissing($relations)
    {
        $this->loaded[] = $relations;

        return $this;
    }

    public function getCurrentOrder(): ?Order
    {
        return $this->orderForTest;
    }
}

class FleetOpsApiDriverTrackOrderFake extends Order
{
    public function getAttribute($key)
    {
        if ($this->relationLoaded($key)) {
            return $this->relations[$key];
        }

        return $this->attributes[$key] ?? null;
    }
}

class FleetOpsApiDriverTrackVehicleFake extends Vehicle
{
    public array $quietUpdates = [];
    public array $positions    = [];
    public array $loaded       = [];

    public function getAttribute($key)
    {
        if ($this->relationLoaded($key)) {
            return $this->relations[$key];
        }

        return $this->attributes[$key] ?? null;
    }

    public function updateQuietly(array $attributes = [], array $options = []): bool
    {
        $this->quietUpdates[] = $attributes;
        $this->setRawAttributes(array_merge($this->getAttributes(), $attributes), true);

        return true;
    }

    public function createPosition(array $attributes = [], FleetbaseModel|string|null $destination = null): ?Position
    {
        $this->positions[] = $attributes;

        return null;
    }

    public function loadMissing($relations)
    {
        $this->loaded[] = $relations;

        return $this;
    }
}

class FleetOpsApiDriverTrackGeofenceServiceFake
{
    public array $driverCalls  = [];
    public array $vehicleCalls = [];

    public function detectDriverCrossings(Driver $driver, Point $location): array
    {
        $this->driverCalls[] = [$driver, $location];

        return [];
    }

    public function detectVehicleCrossings(Vehicle $vehicle, Point $location): array
    {
        $this->vehicleCalls[] = [$vehicle, $location];

        return [];
    }
}

test('api driver controller tracks driver locations and syncs vehicle telemetry', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-27 12:00:00'));
    $GLOBALS['fleetops_api_driver_track_broadcasts'] = [];

    $destination = (object) ['uuid' => 'destination-uuid'];
    $order       = new FleetOpsApiDriverTrackOrderFake();
    $order->setRawAttributes(['uuid' => 'order-uuid'], true);
    $order->setRelation('payload', new class($destination) {
        public function __construct(private object $destination)
        {
        }

        public function getPickupOrCurrentWaypoint(): object
        {
            return $this->destination;
        }
    });

    $vehicle = new FleetOpsApiDriverTrackVehicleFake();
    $vehicle->setRawAttributes([
        'uuid'         => 'vehicle-uuid',
        'public_id'    => 'vehicle_public',
        'company_uuid' => 'company-uuid',
        'display_name' => 'Truck 9',
        'plate_number' => 'PLATE9',
        'online'       => false,
    ], true);

    $driver = new FleetOpsApiDriverTrackDriverFake();
    $driver->setRawAttributes([
        'uuid'        => 'driver-uuid',
        'public_id'   => 'driver_public',
        'internal_id' => 'internal-driver',
        'name'        => 'Driver One',
        'phone'       => '+15550001111',
        'online'      => true,
        'updated_at'  => Carbon::now(),
        'country'     => 'SG',
        'city'        => 'Singapore',
    ], true);
    $driver->orderForTest = $order;
    $driver->setRelation('vehicle', $vehicle);

    $geofenceService = new FleetOpsApiDriverTrackGeofenceServiceFake();
    Container::getInstance()->instance(GeofenceIntersectionService::class, $geofenceService);

    $controller         = new FleetOpsApiDriverTrackControllerProbe();
    $controller->driver = $driver;

    $response = $controller->track('driver_public', new Request([
        'latitude'  => '1.3521',
        'longitude' => '103.8198',
        'altitude'  => '12',
        'heading'   => '90',
        'speed'     => '42',
    ]));

    expect($response)->toBe(['resource' => 'driver', 'driver' => $driver])
        ->and($driver->lookupId)->toBe('driver_public')
        ->and($driver->quietUpdates)->toHaveCount(1)
        ->and($driver->quietUpdates[0])->toMatchArray([
            'latitude'         => 1.3521,
            'longitude'        => 103.8198,
            'altitude'         => '12',
            'heading'          => '90',
            'speed'            => '42',
            'order_uuid'       => 'order-uuid',
            'destination_uuid' => 'destination-uuid',
        ])
        ->and($driver->quietUpdates[0]['location'])->toBeInstanceOf(Point::class)
        ->and($driver->positions)->toHaveCount(1)
        ->and($driver->loaded)->toContain('vehicle')
        ->and($vehicle->quietUpdates)->toHaveCount(1)
        ->and($vehicle->quietUpdates[0])->toMatchArray([
            'latitude'  => 1.3521,
            'longitude' => 103.8198,
            'online'    => true,
        ])
        ->and($vehicle->positions)->toHaveCount(1)
        ->and($vehicle->loaded)->toContain('driver')
        ->and($geofenceService->driverCalls[0][0])->toBe($driver)
        ->and($geofenceService->driverCalls[0][1])->toBeInstanceOf(Point::class)
        ->and($geofenceService->vehicleCalls[0][0])->toBe($vehicle)
        ->and($GLOBALS['fleetops_api_driver_track_broadcasts'])->toHaveCount(2);

    Carbon::setTestNow();
});

test('stale drivers geocode their locality and geofence failures stay silent', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-27 12:00:00'));
    $GLOBALS['fleetops_api_driver_track_broadcasts'] = [];

    $driver = new FleetOpsApiDriverTrackDriverFake();
    $driver->setRawAttributes([
        'uuid'       => 'driver-geo-uuid',
        'public_id'  => 'driver_geopublic',
        'name'       => 'Geocoded Driver',
        'online'     => true,
        'updated_at' => Carbon::now()->subHours(2),
        'country'    => null,
        'city'       => null,
    ], true);
    $driver->orderForTest = null;
    $driver->setRelation('vehicle', null);

    // Reverse geocoding resolves the locality onto the driver record
    app()->instance('geocoder', new class {
        public function reverse($lat, $lng)
        {
            return $this;
        }

        public function get()
        {
            return collect([new class {
                public function getLocality()
                {
                    return 'Singapore';
                }

                public function getCountry()
                {
                    return new class {
                        public function getCode()
                        {
                            return 'SG';
                        }
                    };
                }
            }]);
        }

        public function __call($method, $arguments)
        {
            return $this;
        }
    });
    Geocoder\Laravel\Facades\Geocoder::clearResolvedInstance('geocoder');

    // Geofence detection failures never block the tracking response
    $throwingService = new class extends GeofenceIntersectionService {
        public function detectDriverCrossings($driver, $location): array
        {
            throw new RuntimeException('geofence backend down');
        }
    };
    Container::getInstance()->instance(GeofenceIntersectionService::class, $throwingService);

    $controller         = new FleetOpsApiDriverTrackControllerProbe();
    $controller->driver = $driver;

    $response = $controller->track('driver_geopublic', new Request([
        'latitude'  => '1.30',
        'longitude' => '103.80',
    ]));

    expect($response)->toBe(['resource' => 'driver', 'driver' => $driver])
        ->and(collect($driver->quietUpdates)->contains(fn ($update) => ($update['city'] ?? null) === 'Singapore'))->toBeTrue();
});

test('geocode and geofence failures report to sentry when bound', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-27 12:00:00'));
    $GLOBALS['fleetops_api_driver_track_broadcasts'] = [];

    $driver = new FleetOpsApiDriverTrackDriverFake();
    $driver->setRawAttributes([
        'uuid'       => 'driver-sentry-uuid',
        'public_id'  => 'driver_sentrypublic',
        'name'       => 'Sentry Driver',
        'online'     => true,
        'updated_at' => Carbon::now()->subHours(2),
        'country'    => null,
        'city'       => null,
    ], true);
    $driver->orderForTest = null;
    $driver->setRelation('vehicle', null);

    $sentry = new class {
        public array $captured = [];

        public function captureException($exception)
        {
            $this->captured[] = $exception->getMessage();
        }
    };
    app()->instance('sentry', $sentry);

    app()->instance('geocoder', new class {
        public function reverse($lat, $lng)
        {
            throw new RuntimeException('geocoder offline');
        }

        public function __call($method, $arguments)
        {
            return $this;
        }
    });
    Geocoder\Laravel\Facades\Geocoder::clearResolvedInstance('geocoder');

    $throwingService = new class extends GeofenceIntersectionService {
        public function detectDriverCrossings($driver, $location): array
        {
            throw new RuntimeException('geofence offline');
        }
    };
    Container::getInstance()->instance(GeofenceIntersectionService::class, $throwingService);

    $controller         = new FleetOpsApiDriverTrackControllerProbe();
    $controller->driver = $driver;

    $response = $controller->track('driver_sentrypublic', new Request([
        'latitude'  => '1.30',
        'longitude' => '103.80',
    ]));

    expect($response)->toBe(['resource' => 'driver', 'driver' => $driver])
        ->and($sentry->captured)->toContain('geocoder offline')
        ->and($sentry->captured)->toContain('geofence offline');
});
