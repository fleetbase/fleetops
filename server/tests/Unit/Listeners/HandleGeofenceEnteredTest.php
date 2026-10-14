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
use Fleetbase\FleetOps\Models\Payload;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Models\TrackingStatus;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\FleetOps\Models\Waypoint;
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

    $connection->statement('create table tracking_numbers (id integer primary key autoincrement, uuid varchar(64), public_id varchar(64) null, company_uuid varchar(64) null, status_uuid varchar(64) null, created_at datetime null, updated_at datetime null, deleted_at datetime null)');
    $connection->statement('create table tracking_statuses (id integer primary key autoincrement, uuid varchar(64), public_id varchar(64) null, company_uuid varchar(64) null, tracking_number_uuid varchar(64) null, code varchar(64) null, complete integer null, created_at datetime null, updated_at datetime null, deleted_at datetime null)');

    $resolver = new ConnectionResolver([
        'default' => $connection,
        'mysql'   => $connection,
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

function fleetopsEnteredZone(string $name, float $lat, float $lng): object
{
    return new class($name, $lat, $lng) {
        public string $uuid;
        public string $public_id;

        public function __construct(public string $name, private float $lat, private float $lng)
        {
            $this->uuid      = $name . '-uuid';
            $this->public_id = $name . '-public';
        }

        public function getLatitudeAttribute(): float
        {
            return $this->lat;
        }

        public function getLongitudeAttribute(): float
        {
            return $this->lng;
        }
    };
}

function fleetopsEnteredPlace(string $uuid, ?Point $location): Place
{
    $place = new Place();
    $place->setRawAttributes(['uuid' => $uuid, 'public_id' => $uuid . '-public', 'location' => $location], true);

    return $place;
}

/**
 * Build a pickup -> dropoff payload. The pickup sits on the default event
 * zone centroid; the dropoff is roughly 9km away.
 */
function fleetopsEnteredEndpointPayload(?string $currentWaypointUuid = null, ?string $pickupTrackingNumberUuid = null): Payload
{
    $payload = new Payload();
    $payload->setRawAttributes([
        'uuid'                        => 'payload-uuid',
        'current_waypoint_uuid'       => $currentWaypointUuid,
        'pickup_tracking_number_uuid' => $pickupTrackingNumberUuid,
    ], true);
    $payload->setRelation('pickup', fleetopsEnteredPlace('pickup-place', new Point(1.3521, 103.8198)));
    $payload->setRelation('dropoff', fleetopsEnteredPlace('dropoff-place', new Point(1.3000, 103.9000)));
    $payload->setRelation('waypoints', collect());
    $payload->setRelation('waypointMarkers', collect());

    return $payload;
}

function fleetopsEnteredCompleteStatus(SQLiteConnection $connection, string $trackingNumberUuid): void
{
    $connection->table('tracking_numbers')->insert(['uuid' => $trackingNumberUuid, 'status_uuid' => $trackingNumberUuid . '-status']);
    $connection->table('tracking_statuses')->insert(['uuid' => $trackingNumberUuid . '-status', 'tracking_number_uuid' => $trackingNumberUuid, 'code' => 'completed', 'complete' => 1]);
}

function fleetopsEnteredArrive(Order $order, object $zone): void
{
    $driver = fleetopsEnteredDriver($order);

    (new HandleGeofenceEntered())->handle(fleetopsEnteredEvent($driver, $zone));
}

test('geofence entered arrival handles destination and status failures', function () {
    fleetopsHandleGeofenceEnteredConnection();
    $listener = new HandleGeofenceEntered();
    $arrival  = new ReflectionMethod(HandleGeofenceEntered::class, 'handleOrderArrival');
    $arrival->setAccessible(true);

    $driver = fleetopsEnteredDriver();
    $event  = fleetopsEnteredEvent($driver);

    $throwingPayload = new class extends Payload {
        public function loadMissing($relations)
        {
            throw new RuntimeException('destination failed');
        }
    };
    $destinationFailure = fleetopsEnteredOrder($throwingPayload);
    $arrival->invoke($listener, $driver, $event->geofence, $destinationFailure, $event);

    $emptyPayload = new Payload();
    $emptyPayload->setRelation('pickup', null);
    $emptyPayload->setRelation('dropoff', null);
    $emptyPayload->setRelation('waypoints', collect());
    $emptyPayload->setRelation('waypointMarkers', collect());
    $noStops = fleetopsEnteredOrder($emptyPayload);
    $arrival->invoke($listener, $driver, $event->geofence, $noStops, $event);

    $statusFailure                = fleetopsEnteredOrder(fleetopsEnteredEndpointPayload());
    $statusFailure->throwOnStatus = true;
    $arrival->invoke($listener, $driver, $event->geofence, $statusFailure, $event);

    expect($destinationFailure->calls)->toBe([])
        ->and($noStops->calls)->toBe([])
        ->and($statusFailure->calls)->toBe([]);
});

test('geofence entered arrival targets the pickup before the pickup is completed', function () {
    fleetopsHandleGeofenceEnteredConnection();

    $atDropoff = fleetopsEnteredOrder(fleetopsEnteredEndpointPayload());
    fleetopsEnteredArrive($atDropoff, fleetopsEnteredZone('dropoff-zone', 1.3000, 103.9000));

    $atPickup = fleetopsEnteredOrder(fleetopsEnteredEndpointPayload('pickup-place'));
    fleetopsEnteredArrive($atPickup, fleetopsEnteredZone('pickup-zone', 1.3521, 103.8198));

    expect($atDropoff->calls)->toBe([])
        ->and($atDropoff->status)->toBe('dispatched')
        ->and($atPickup->calls[0])->toBe(['setStatus', 'arrived', true])
        ->and($atPickup->calls[1][1]->get('details'))->toBe('Driver entered destination geofence "pickup-zone".');
});

test('geofence entered arrival targets the dropoff once the order has advanced past the pickup', function () {
    fleetopsHandleGeofenceEnteredConnection();

    $atPickup = fleetopsEnteredOrder(fleetopsEnteredEndpointPayload('dropoff-place'));
    fleetopsEnteredArrive($atPickup, fleetopsEnteredZone('pickup-zone', 1.3521, 103.8198));

    $atDropoff = fleetopsEnteredOrder(fleetopsEnteredEndpointPayload('dropoff-place'));
    fleetopsEnteredArrive($atDropoff, fleetopsEnteredZone('dropoff-zone', 1.3000, 103.9000));

    expect($atPickup->calls)->toBe([])
        ->and($atDropoff->calls[0])->toBe(['setStatus', 'arrived', true])
        ->and($atDropoff->calls[1][1]->get('details'))->toBe('Driver entered destination geofence "dropoff-zone".');
});

test('geofence entered arrival skips a completed pickup the payload pointer has not advanced past', function () {
    $connection = fleetopsHandleGeofenceEnteredConnection();
    fleetopsEnteredCompleteStatus($connection, 'pickup-tracking');

    $atPickup = fleetopsEnteredOrder(fleetopsEnteredEndpointPayload('pickup-place', 'pickup-tracking'));
    fleetopsEnteredArrive($atPickup, fleetopsEnteredZone('pickup-zone', 1.3521, 103.8198));

    $atDropoff = fleetopsEnteredOrder(fleetopsEnteredEndpointPayload(null, 'pickup-tracking'));
    fleetopsEnteredArrive($atDropoff, fleetopsEnteredZone('dropoff-zone', 1.3000, 103.9000));

    expect($atPickup->calls)->toBe([])
        ->and($atDropoff->calls[0])->toBe(['setStatus', 'arrived', true]);
});

test('geofence entered arrival targets the current stop of a multi-waypoint order', function () {
    $connection = fleetopsHandleGeofenceEnteredConnection();
    fleetopsEnteredCompleteStatus($connection, 'first-tracking');

    $multiDropPayload = function (?string $currentWaypointUuid): Payload {
        $payload = new Payload();
        $payload->setRawAttributes(['uuid' => 'multi-payload-uuid', 'current_waypoint_uuid' => $currentWaypointUuid], true);
        $payload->setRelation('pickup', null);
        $payload->setRelation('dropoff', null);
        $payload->setRelation('waypoints', collect());

        $markers = collect([
            ['first', 'first-tracking', new Point(1.3521, 103.8198)],
            ['second', null, new Point(1.3000, 103.9000)],
            ['third', null, new Point(1.4000, 103.7000)],
        ])->map(function (array $stop, int $index) {
            [$name, $trackingNumberUuid, $location] = $stop;

            $waypoint = new Waypoint();
            $waypoint->setRawAttributes([
                'uuid'                 => $name . '-waypoint',
                'place_uuid'           => $name . '-place',
                'tracking_number_uuid' => $trackingNumberUuid,
                'order'                => $index,
            ], true);
            $waypoint->setRelation('place', fleetopsEnteredPlace($name . '-place', $location));
            $waypoint->setRelation('trackingNumber', null);

            return $waypoint;
        });
        $payload->setRelation('waypointMarkers', $markers);

        return $payload;
    };

    $atCompletedStop = fleetopsEnteredOrder($multiDropPayload('second-place'));
    fleetopsEnteredArrive($atCompletedStop, fleetopsEnteredZone('first-zone', 1.3521, 103.8198));

    $atLaterStop = fleetopsEnteredOrder($multiDropPayload('second-place'));
    fleetopsEnteredArrive($atLaterStop, fleetopsEnteredZone('third-zone', 1.4000, 103.7000));

    $atCurrentStop = fleetopsEnteredOrder($multiDropPayload('second-place'));
    fleetopsEnteredArrive($atCurrentStop, fleetopsEnteredZone('second-zone', 1.3000, 103.9000));

    $staleFirstStop = fleetopsEnteredOrder($multiDropPayload(null));
    fleetopsEnteredArrive($staleFirstStop, fleetopsEnteredZone('second-zone', 1.3000, 103.9000));

    expect($atCompletedStop->calls)->toBe([])
        ->and($atLaterStop->calls)->toBe([])
        ->and($atCurrentStop->calls[0])->toBe(['setStatus', 'arrived', true])
        ->and($staleFirstStop->calls[0])->toBe(['setStatus', 'arrived', true]);
});
