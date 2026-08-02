<?php

if (!function_exists('Fleetbase\Traits\config')) {
    eval('namespace Fleetbase\Traits; function config($key = null, $default = null) { return $key === "api.cache.enabled" ? false : $default; }');
}

if (!function_exists('Fleetbase\Models\config')) {
    eval('namespace Fleetbase\Models; function config($key = null, $default = null) { return $key === "fleetbase.connection.db" ? "mysql" : $default; }');
}

use Fleetbase\FleetOps\Events\GeofenceEntered;
use Fleetbase\FleetOps\Flow\Activity;
use Fleetbase\FleetOps\Listeners\HandleGeofenceEntered;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\GeofenceEventLog;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\TrackingStatus;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;

class FleetOpsHandleGeofenceEnteredDriverFake extends Driver
{
    public ?Order $currentOrder = null;

    public function getAttribute($key)
    {
        if ($key === 'vehicle') {
            return $this->relations['vehicle'] ?? null;
        }

        if (in_array($key, ['uuid', 'public_id', 'company_uuid', 'name', 'phone'], true)) {
            return $this->attributes[$key] ?? null;
        }

        return parent::getAttribute($key);
    }

    public function getCurrentOrder(): ?Order
    {
        return $this->currentOrder;
    }
}

class FleetOpsHandleGeofenceEnteredVehicleFake extends Vehicle
{
    public function getAttribute($key)
    {
        if ($key === 'display_name') {
            return $this->attributes['display_name'] ?? $this->attributes['name'] ?? null;
        }

        if (in_array($key, ['display_name', 'name', 'plate_number', 'uuid', 'public_id', 'company_uuid'], true)) {
            return $this->attributes[$key] ?? null;
        }

        return parent::getAttribute($key);
    }

    public function loadMissing($relations)
    {
        return $this;
    }
}

class FleetOpsHandleGeofenceEnteredOrderFake extends Order
{
    public array $calls        = [];
    public bool $throwOnStatus = false;

    public function setStatus(?string $status, $andSave = true)
    {
        if ($this->throwOnStatus) {
            throw new RuntimeException('status failed');
        }

        $this->calls[]              = ['setStatus', $status, $andSave];
        $this->attributes['status'] = $status;

        return $this;
    }

    public function createActivity(Activity $activity, $location = [], $proof = null): TrackingStatus
    {
        $this->calls[] = ['createActivity', $activity, $location, $proof];

        return new TrackingStatus();
    }
}

function fleetopsHandleGeofenceEnteredConnection(): SQLiteConnection
{
    $pdo        = new PDO('sqlite::memory:');
    $connection = new SQLiteConnection($pdo, 'default');
    $connection->statement('create table geofence_events_log (uuid varchar(64) primary key, company_uuid varchar(64), driver_uuid varchar(64) null, vehicle_uuid varchar(64) null, order_uuid varchar(64) null, subject_uuid varchar(64) null, subject_type varchar(255) null, subject_name varchar(255) null, geofence_uuid varchar(64), geofence_type varchar(64), geofence_name varchar(255) null, event_type varchar(64), latitude numeric null, longitude numeric null, speed_kmh numeric null, dwell_duration_minutes integer null, occurred_at datetime null, created_at datetime null, updated_at datetime null)');

    $resolver = new ConnectionResolver([
        'default' => $connection,
    ]);
    $resolver->setDefaultConnection('default');
    EloquentModel::setConnectionResolver($resolver);
    GeofenceEventLog::setConnectionResolver($resolver);

    return $connection;
}

function fleetopsEnteredDriver(?Order $order = null): FleetOpsHandleGeofenceEnteredDriverFake
{
    $driver = new FleetOpsHandleGeofenceEnteredDriverFake();
    $driver->setRawAttributes([
        'uuid'         => 'driver-uuid',
        'public_id'    => 'driver-public',
        'company_uuid' => 'company-uuid',
        'name'         => 'Jane Driver',
        'phone'        => '+15551230000',
    ], true);
    $driver->setRelation('vehicle', null);
    $driver->currentOrder = $order;

    return $driver;
}

function fleetopsEnteredOrder(mixed $payload): FleetOpsHandleGeofenceEnteredOrderFake
{
    $order = new FleetOpsHandleGeofenceEnteredOrderFake();
    $order->setRawAttributes([
        'uuid'      => 'order-uuid',
        'public_id' => 'order-public',
        'status'    => 'dispatched',
    ], true);
    $order->setRelation('payload', $payload);
    $order->setRelation('trackingNumber', (object) ['last_status' => 'dispatched']);

    return $order;
}

function fleetopsEnteredEvent(Driver|Vehicle $subject, ?object $geofence = null): GeofenceEntered
{
    $geofence ??= new class {
        public string $uuid      = 'zone-uuid';
        public string $public_id = 'zone-public';
        public string $name      = 'Destination Zone';

        public function getLatitudeAttribute(): float
        {
            return 1.3521;
        }

        public function getLongitudeAttribute(): float
        {
            return 103.8198;
        }
    };

    return new GeofenceEntered($subject, $geofence, 'zone', new Point(1.3521, 103.8198));
}

test('geofence entered listener writes driver entry logs through the public handle path', function () {
    $connection = fleetopsHandleGeofenceEnteredConnection();
    $driver     = fleetopsEnteredDriver();

    (new HandleGeofenceEntered())->handle(fleetopsEnteredEvent($driver));

    $log = $connection->table('geofence_events_log')->first();

    expect(session('company'))->toBe('company-uuid')
        ->and($log->company_uuid)->toBe('company-uuid')
        ->and($log->driver_uuid)->toBe('driver-uuid')
        ->and($log->vehicle_uuid)->toBeNull()
        ->and($log->order_uuid)->toBeNull()
        ->and($log->subject_uuid)->toBe('driver-uuid')
        ->and($log->subject_type)->toBe('driver')
        ->and($log->subject_name)->toBe('Jane Driver')
        ->and($log->geofence_uuid)->toBe('zone-uuid')
        ->and($log->geofence_type)->toBe('zone')
        ->and($log->event_type)->toBe('entered')
        ->and((float) $log->latitude)->toBe(1.3521)
        ->and((float) $log->longitude)->toBe(103.8198);
});

test('geofence entered listener writes vehicle entry logs with current order context', function () {
    $connection = fleetopsHandleGeofenceEnteredConnection();
    $order      = fleetopsEnteredOrder(new class {
        public function getPickupOrCurrentWaypoint(): mixed
        {
            return null;
        }
    });
    $driver  = fleetopsEnteredDriver($order);
    $vehicle = new FleetOpsHandleGeofenceEnteredVehicleFake();
    $vehicle->setRawAttributes([
        'uuid'         => 'vehicle-uuid',
        'public_id'    => 'vehicle-public',
        'company_uuid' => 'company-uuid',
        'name'         => 'Dock Van',
        'plate_number' => 'SG-202',
    ], true);
    $vehicle->setRelation('driver', $driver);
    $driver->setRelation('vehicle', $vehicle);

    (new HandleGeofenceEntered())->handle(fleetopsEnteredEvent($vehicle));

    $log = $connection->table('geofence_events_log')->first();

    expect($log->driver_uuid)->toBe('driver-uuid')
        ->and($log->vehicle_uuid)->toBe('vehicle-uuid')
        ->and($log->order_uuid)->toBe('order-uuid')
        ->and($log->subject_uuid)->toBe('vehicle-uuid')
        ->and($log->subject_type)->toBe('vehicle')
        ->and($log->subject_name)->toBe('Dock Van');
});

test('geofence entered arrival handles destination and status failures', function () {
    $listener = new HandleGeofenceEntered();
    $arrival  = new ReflectionMethod(HandleGeofenceEntered::class, 'handleOrderArrival');
    $arrival->setAccessible(true);

    $driver = fleetopsEnteredDriver();
    $event  = fleetopsEnteredEvent($driver);

    $destinationFailure = fleetopsEnteredOrder(new class {
        public function getPickupOrCurrentWaypoint(): mixed
        {
            throw new RuntimeException('destination failed');
        }
    });
    $arrival->invoke($listener, $driver, $event->geofence, $destinationFailure, $event);

    $placeFailure = fleetopsEnteredOrder(new class {
        public function getPickupOrCurrentWaypoint(): object
        {
            return new class {
                public function getPlace(): mixed
                {
                    throw new RuntimeException('place failed');
                }
            };
        }
    });
    $arrival->invoke($listener, $driver, $event->geofence, $placeFailure, $event);

    $statusFailure = fleetopsEnteredOrder(new class {
        public function getPickupOrCurrentWaypoint(): object
        {
            return (object) ['place' => (object) ['location' => new Point(1.3521, 103.8198)]];
        }
    });
    $statusFailure->throwOnStatus = true;
    $arrival->invoke($listener, $driver, $event->geofence, $statusFailure, $event);

    expect($destinationFailure->calls)->toBe([])
        ->and($placeFailure->calls)->toBe([])
        ->and($statusFailure->calls)->toBe([]);
});
