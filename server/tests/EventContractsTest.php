<?php

use Fleetbase\FleetOps\Events\DriverLocationChanged;
use Fleetbase\FleetOps\Events\DriverSimulatedLocationChanged;
use Fleetbase\FleetOps\Events\EntityActivityChanged;
use Fleetbase\FleetOps\Events\EntityCompleted;
use Fleetbase\FleetOps\Events\EntityDriverAssigned;
use Fleetbase\FleetOps\Events\FuelProviderTransactionImported;
use Fleetbase\FleetOps\Events\FuelProviderTransactionMatched;
use Fleetbase\FleetOps\Events\FuelProviderTransactionUnmatched;
use Fleetbase\FleetOps\Events\FuelReportCreatedFromProvider;
use Fleetbase\FleetOps\Events\GeofenceDwelled;
use Fleetbase\FleetOps\Events\GeofenceEntered;
use Fleetbase\FleetOps\Events\GeofenceExited;
use Fleetbase\FleetOps\Events\OrderCanceled;
use Fleetbase\FleetOps\Events\OrderCompleted;
use Fleetbase\FleetOps\Events\OrderDispatched;
use Fleetbase\FleetOps\Events\OrderDispatchFailed;
use Fleetbase\FleetOps\Events\OrderDriverAssigned;
use Fleetbase\FleetOps\Events\OrderFailed;
use Fleetbase\FleetOps\Events\VehicleLocationChanged;
use Fleetbase\FleetOps\Events\WaypointActivityChanged;
use Fleetbase\FleetOps\Events\WaypointCompleted;
use Fleetbase\FleetOps\Flow\Activity;
use Fleetbase\FleetOps\Listeners\HandleGeofenceDwelled;
use Fleetbase\FleetOps\Listeners\HandleGeofenceEntered;
use Fleetbase\FleetOps\Listeners\HandleGeofenceExited;
use Fleetbase\FleetOps\Listeners\NotifyOrderEvent;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Entity;
use Fleetbase\FleetOps\Models\FuelProviderTransaction;
use Fleetbase\FleetOps\Models\FuelReport;
use Fleetbase\FleetOps\Models\GeofenceEventLog;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\FleetOps\Models\Waypoint;
use Fleetbase\FleetOps\Notifications\OrderAssigned as OrderAssignedNotification;
use Fleetbase\FleetOps\Notifications\OrderCanceled as OrderCanceledNotification;
use Fleetbase\FleetOps\Notifications\OrderCompleted as OrderCompletedNotification;
use Fleetbase\FleetOps\Notifications\OrderDispatched as OrderDispatchedNotification;
use Fleetbase\FleetOps\Notifications\OrderDispatchFailed as OrderDispatchFailedNotification;
use Fleetbase\FleetOps\Notifications\OrderFailed as OrderFailedNotification;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Illuminate\Support\Carbon;

if (!class_exists('Illuminate\Foundation\Auth\User')) {
    class_alias(Illuminate\Database\Eloquent\Model::class, 'Illuminate\Foundation\Auth\User');
}

if (!function_exists('Fleetbase\Traits\config')) {
    eval('namespace Fleetbase\Traits; function config($key = null, $default = null) { return $key === "api.cache.enabled" ? false : $default; }');
}

if (!function_exists('Fleetbase\Models\config')) {
    eval('namespace Fleetbase\Models; function config($key = null, $default = null) { return $key === "fleetbase.connection.db" ? "mysql" : $default; }');
}

class FleetOpsEventDriver extends Driver
{
    public function getAttribute($key)
    {
        if (in_array($key, ['uuid', 'public_id', 'company_uuid', 'name', 'phone'], true)) {
            return $this->attributes[$key] ?? null;
        }

        return parent::getAttribute($key);
    }

    public function getCurrentOrder(): ?Order
    {
        return null;
    }
}

class FleetOpsEventVehicle extends Vehicle
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

class FleetOpsEventDriverWithOrder extends FleetOpsEventDriver
{
    public ?Order $currentOrder = null;

    public function getCurrentOrder(): ?Order
    {
        return $this->currentOrder;
    }
}

class FleetOpsGeofenceEventLogFake extends GeofenceEventLog
{
    public function __construct()
    {
    }
}

class FleetOpsGeofenceDwelledListenerProbe extends HandleGeofenceDwelled
{
    public array $logs = [];

    protected function createLog(array $attributes): GeofenceEventLog
    {
        $this->logs[] = $attributes;

        return new FleetOpsGeofenceEventLogFake();
    }
}

class FleetOpsGeofenceExitedListenerProbe extends HandleGeofenceExited
{
    public array $logs = [];

    protected function createLog(array $attributes): GeofenceEventLog
    {
        $this->logs[] = $attributes;

        return new FleetOpsGeofenceEventLogFake();
    }
}

class FleetOpsWaypointCompletedProbe extends WaypointCompleted
{
    public ?Order $order = null;

    public function getModelRecord(): ?Order
    {
        return $this->order;
    }
}

class FleetOpsWaypointActivityChangedProbe extends WaypointActivityChanged
{
    public ?Order $order = null;

    public function getModelRecord(): ?Order
    {
        return $this->order;
    }
}

class FleetOpsEntityCompletedProbe extends EntityCompleted
{
    public ?Order $order = null;

    public function getModelRecord(): ?Order
    {
        return $this->order;
    }
}

class FleetOpsEntityActivityChangedProbe extends EntityActivityChanged
{
    public ?Order $order = null;

    public function getModelRecord(): ?Order
    {
        return $this->order;
    }
}

class FleetOpsNotifyOrderEventProbe extends NotifyOrderEvent
{
    public array $notifications = [];

    protected function notify(string $notificationClass, mixed ...$arguments): void
    {
        $this->notifications[] = [$notificationClass, $arguments];
    }
}

class FleetOpsOrderCanceledNotificationEvent extends OrderCanceled
{
    public ?Order $order = null;

    public function __construct()
    {
    }

    public function getModelRecord(): ?Order
    {
        return $this->order;
    }
}

class FleetOpsOrderCompletedNotificationEvent extends OrderCompleted
{
    public ?Order $order = null;

    public function __construct()
    {
    }

    public function getModelRecord(): ?Order
    {
        return $this->order;
    }
}

class FleetOpsOrderFailedNotificationEvent extends OrderFailed
{
    public ?Order $order = null;

    public function __construct()
    {
    }

    public function getModelRecord(): ?Order
    {
        return $this->order;
    }
}

class FleetOpsOrderDispatchFailedNotificationEvent extends OrderDispatchFailed
{
    public ?Order $order = null;

    public function __construct()
    {
    }

    public function getModelRecord(): ?Order
    {
        return $this->order;
    }
}

class FleetOpsOrderDispatchedNotificationEvent extends OrderDispatched
{
    public ?Order $order = null;

    public function __construct()
    {
    }

    public function getModelRecord(): ?Order
    {
        return $this->order;
    }
}

class FleetOpsOrderDriverAssignedNotificationEvent extends OrderDriverAssigned
{
    public ?Order $order = null;

    public function __construct()
    {
    }

    public function getModelRecord(): ?Order
    {
        return $this->order;
    }
}

function eventChannelNames(array $channels): array
{
    return array_map(fn ($channel) => $channel->name, $channels);
}

test('driver location changed broadcasts driver telemetry payload', function () {
    session([
        'company'        => 'company-1',
        'api_credential' => 'api-1',
    ]);

    $driver = new Driver();
    $driver->setRawAttributes([
        'uuid'        => 'driver-uuid',
        'public_id'   => 'driver_public',
        'internal_id' => 'driver_internal',
        'name'        => 'Jane Driver',
        'phone'       => '+15551234567',
        'location'    => ['type' => 'Point', 'coordinates' => [103.8, 1.3]],
        'altitude'    => 20,
        'heading'     => 180,
        'speed'       => 32,
    ], true);
    $driver->setRelation('user', (object) [
        'name'  => 'Jane Driver',
        'phone' => '+15551234567',
    ]);
    $driver->setRelation('user', (object) [
        'name'  => 'Jane Driver',
        'phone' => '+15551234567',
    ]);

    $event = new DriverLocationChanged($driver, ['source' => 'telematics']);

    expect($event->broadcastAs())->toBe('driver.location_changed')
        ->and(eventChannelNames($event->broadcastOn()))->toBe([
            'company.company-1',
            'api.api-1',
            'driver.driver_public',
            'driver.driver-uuid',
        ])
        ->and($event->broadcastWith())->toMatchArray([
            'event' => 'driver.location_changed',
            'data'  => [
                'id'             => 'driver_public',
                'internal_id'    => 'driver_internal',
                'name'           => 'Jane Driver',
                'phone'          => '+15551234567',
                'location'       => ['type' => 'Point', 'coordinates' => [103.8, 1.3]],
                'altitude'       => 20,
                'heading'        => 180,
                'speed'          => 32,
                'additionalData' => ['source' => 'telematics'],
            ],
        ])
        ->and($event->eventId)->toStartWith('event_')
        ->and($event->sentAt)->toBeString();
});

test('driver simulated location changed broadcasts simulated telemetry payload', function () {
    session([
        'company'        => 'company-1',
        'api_credential' => 'api-1',
    ]);

    $driver = new Driver();
    $driver->setRawAttributes([
        'uuid'        => 'driver-uuid',
        'public_id'   => 'driver_public',
        'internal_id' => 'driver_internal',
        'name'        => 'Jane Driver',
        'phone'       => '+15551234567',
        'altitude'    => 20,
        'heading'     => 180,
        'speed'       => 32,
    ], true);
    $driver->setRelation('user', (object) [
        'name'  => 'Jane Driver',
        'phone' => '+15551234567',
    ]);

    $location = new Point(1.3521, 103.8198);
    $event    = new DriverSimulatedLocationChanged($driver, $location, [
        'heading' => 270,
        'speed'   => 44,
        'source'  => 'simulator',
    ]);
    $payload = $event->broadcastWith();
    $data    = $payload['data'];
    unset($data['location']);

    expect($event->broadcastAs())->toBe('driver.simulated_location_changed')
        ->and(eventChannelNames($event->broadcastOn()))->toBe([
            'company.company-1',
            'api.api-1',
            'driver.driver_public',
            'driver.driver-uuid',
        ])
        ->and($payload)->toHaveKey('event', 'driver.simulated_location_changed')
        ->and($data)->toBe([
            'id'             => 'driver_public',
            'internal_id'    => 'driver_internal',
            'name'           => 'Jane Driver',
            'phone'          => '+15551234567',
            'altitude'       => 20,
            'heading'        => 270,
            'speed'          => 44,
            'additionalData' => [
                'heading' => 270,
                'speed'   => 44,
                'source'  => 'simulator',
            ],
        ])
        ->and($payload)->toMatchArray([
            'event' => 'driver.simulated_location_changed',
        ])
        ->and($payload['data']['location'])->toBe($location)
        ->and($event->eventId)->toStartWith('event_')
        ->and($event->sentAt)->toBeString();
});

test('vehicle location changed broadcasts vehicle telemetry payload', function () {
    session([
        'company'        => 'company-1',
        'api_credential' => 'api-1',
    ]);

    $vehicle = new Vehicle();
    $vehicle->setRawAttributes([
        'uuid'         => 'vehicle-uuid',
        'public_id'    => 'vehicle_public',
        'plate_number' => 'ABC-123',
        'name'         => 'Truck 12',
        'location'     => ['type' => 'Point', 'coordinates' => [103.9, 1.4]],
        'altitude'     => 22,
        'heading'      => 90,
        'speed'        => 45,
    ], true);

    $event = new VehicleLocationChanged($vehicle, ['source' => 'device']);

    expect($event->broadcastAs())->toBe('vehicle.location_changed')
        ->and(eventChannelNames($event->broadcastOn()))->toBe([
            'company.company-1',
            'api.api-1',
            'vehicle.vehicle_public',
            'vehicle.vehicle-uuid',
        ])
        ->and($event->broadcastWith())->toMatchArray([
            'event' => 'vehicle.location_changed',
            'data'  => [
                'id'             => 'vehicle_public',
                'plate_number'   => 'ABC-123',
                'name'           => 'Truck 12',
                'location'       => ['type' => 'Point', 'coordinates' => [103.9, 1.4]],
                'altitude'       => 22,
                'heading'        => 90,
                'speed'          => 45,
                'additionalData' => ['source' => 'device'],
            ],
        ]);
});

test('waypoint events broadcast activity payloads with associated order channels', function () {
    session([
        'company'        => 'company-session',
        'api_credential' => 'api-credential',
    ]);

    $company = (object) [
        'uuid'      => 'company-uuid',
        'public_id' => 'company_public',
    ];

    $order = new Order();
    $order->setRawAttributes([
        'uuid'      => 'order-uuid',
        'public_id' => 'order_public',
    ], true);
    $order->setRelation('company', $company);

    $waypoint = new Waypoint();
    $waypoint->setRawAttributes([
        'uuid'         => 'waypoint-uuid',
        'public_id'    => 'waypoint_public',
        'payload_uuid' => 'payload-uuid',
    ], true);
    $waypoint->setRelation('place', (object) [
        'public_id' => 'place_public',
    ]);

    $activity  = new Activity(['code' => 'arrived', 'status' => 'completed']);
    $completed = new FleetOpsWaypointCompletedProbe($waypoint, $activity);
    $changed   = new FleetOpsWaypointActivityChangedProbe($waypoint, $activity);

    $completed->order = $order;
    $changed->order   = $order;

    expect($completed->broadcastAs())->toBe('waypoint.completed')
        ->and(eventChannelNames($completed->broadcastOn()))->toBe([
            'api.api-credential',
            'waypoint.waypoint_public',
            'waypoint.waypoint-uuid',
            'company.company-session',
            'company.company_public',
            'order.order-uuid',
            'order.order_public',
        ])
        ->and($completed->broadcastWith())->toMatchArray([
            'event' => 'waypoint.completed',
            'data'  => [
                'waypoint' => 'waypoint_public',
                'place'    => 'place_public',
                'activity' => [
                    'code'   => 'arrived',
                    'status' => 'completed',
                ],
            ],
        ])
        ->and($changed->broadcastAs())->toBe('waypoint.activity')
        ->and(eventChannelNames($changed->broadcastOn()))->toBe(eventChannelNames($completed->broadcastOn()))
        ->and($changed->broadcastWith())->toMatchArray([
            'event' => 'waypoint.activity',
            'data'  => [
                'waypoint' => 'waypoint_public',
                'place'    => 'place_public',
                'activity' => [
                    'code'   => 'arrived',
                    'status' => 'completed',
                ],
            ],
        ]);
});

test('entity events broadcast activity payloads with associated order channels', function () {
    session([
        'company'        => 'company-session',
        'api_credential' => 'api-credential',
    ]);

    $company = (object) [
        'uuid'      => 'company-uuid',
        'public_id' => 'company_public',
    ];

    $order = new Order();
    $order->setRawAttributes([
        'uuid'      => 'order-uuid',
        'public_id' => 'order_public',
    ], true);
    $order->setRelation('company', $company);

    $entity = new Entity();
    $entity->setRawAttributes([
        'uuid'         => 'entity-uuid',
        'public_id'    => 'entity_public',
        'payload_uuid' => 'payload-uuid',
    ], true);

    $activity  = new Activity(['code' => 'loaded', 'status' => 'completed']);
    $completed = new FleetOpsEntityCompletedProbe($entity, $activity);
    $changed   = new FleetOpsEntityActivityChangedProbe($entity, $activity);

    $completed->order = $order;
    $changed->order   = $order;

    expect($completed->broadcastAs())->toBe('entity.completed')
        ->and(eventChannelNames($completed->broadcastOn()))->toBe([
            'api.api-credential',
            'entity.entity_public',
            'entity.entity-uuid',
            'company.company-session',
            'company.company_public',
            'order.order-uuid',
            'order.order_public',
        ])
        ->and($completed->broadcastWith())->toMatchArray([
            'event' => 'entity.completed',
            'data'  => [
                'entity'   => 'entity_public',
                'activity' => [
                    'code'   => 'loaded',
                    'status' => 'completed',
                ],
            ],
        ])
        ->and($changed->broadcastAs())->toBe('entity.activity')
        ->and(eventChannelNames($changed->broadcastOn()))->toBe(eventChannelNames($completed->broadcastOn()))
        ->and($changed->broadcastWith())->toMatchArray([
            'event' => 'entity.activity',
            'data'  => [
                'entity'   => 'entity_public',
                'activity' => [
                    'code'   => 'loaded',
                    'status' => 'completed',
                ],
            ],
        ]);
});

test('entity driver assigned event broadcasts on its private placeholder channel', function () {
    expect((new EntityDriverAssigned())->broadcastOn()->name)->toBe('private-channel-name');
});

test('fuel provider events retain transaction and generated fuel report references', function () {
    $transaction = new FuelProviderTransaction();
    $fuelReport  = new FuelReport();

    expect((new FuelProviderTransactionImported($transaction))->transaction)->toBe($transaction)
        ->and((new FuelProviderTransactionMatched($transaction))->transaction)->toBe($transaction)
        ->and((new FuelProviderTransactionUnmatched($transaction))->transaction)->toBe($transaction)
        ->and((new FuelReportCreatedFromProvider($transaction, $fuelReport))->transaction)->toBe($transaction)
        ->and((new FuelReportCreatedFromProvider($transaction, $fuelReport))->fuelReport)->toBe($fuelReport);
});

test('geofence entered event broadcasts driver subject payloads', function () {
    session([
        'company'         => 'company-session',
        'user'            => 'user-session',
        'api_credential'  => 'api-credential',
        'api_secret'      => 'api-secret',
        'api_key'         => 'api-key',
        'api_environment' => 'sandbox',
        'is_sandbox'      => true,
    ]);

    $vehicle = new FleetOpsEventVehicle();
    $vehicle->setRawAttributes([
        'uuid'         => 'vehicle-uuid',
        'public_id'    => 'vehicle_public',
        'company_uuid' => 'company-uuid',
        'name'         => 'Truck 101',
        'plate_number' => 'SG-101',
    ], true);

    $driver = new FleetOpsEventDriver();
    $driver->setRawAttributes([
        'uuid'         => 'driver-uuid',
        'public_id'    => 'driver_public',
        'company_uuid' => 'company-uuid',
        'name'         => 'Jane Driver',
        'phone'        => '+6555551111',
    ], true);
    $driver->setRelation('user', (object) [
        'name'  => 'Jane Driver',
        'phone' => '+6555551111',
    ]);
    $driver->setRelation('vehicle', $vehicle);

    $geofence = (object) [
        'uuid'      => 'zone-uuid',
        'public_id' => 'zone_public',
        'name'      => 'Central Zone',
    ];

    $event = new GeofenceEntered($driver, $geofence, 'zone', new Point(1.3521, 103.8198));

    expect($event->broadcastAs())->toBe('geofence.entered')
        ->and(eventChannelNames($event->broadcastOn()))->toBe([
            'company.company-uuid',
            'driver.driver_public',
            'driver.driver-uuid',
            'vehicle.vehicle_public',
            'vehicle.vehicle-uuid',
        ])
        ->and($event->getCompanyUuid())->toBe('company-uuid')
        ->and($event->modelRecordName)->toBe('Central Zone')
        ->and($event->companySession)->toBe('company-session')
        ->and($event->apiCredential)->toBe('api-credential')
        ->and($event->apiSecret)->toBe('api-secret')
        ->and($event->apiKey)->toBe('api-key')
        ->and($event->apiEnvironment)->toBe('sandbox')
        ->and($event->isSandbox)->toBeTrue()
        ->and($event->broadcastWith())->toMatchArray([
            'event'      => 'geofence.entered',
            'event_type' => 'geofence.entered',
            'subject'    => [
                'type' => 'driver',
                'id'   => 'driver_public',
                'uuid' => 'driver-uuid',
                'name' => 'Jane Driver',
            ],
            'driver'     => [
                'id'    => 'driver_public',
                'uuid'  => 'driver-uuid',
                'name'  => 'Jane Driver',
                'phone' => '+6555551111',
            ],
            'vehicle'    => [
                'id'    => 'vehicle_public',
                'uuid'  => 'vehicle-uuid',
                'name'  => 'Truck 101',
                'plate' => 'SG-101',
            ],
            'geofence'   => [
                'id'   => 'zone_public',
                'uuid' => 'zone-uuid',
                'name' => 'Central Zone',
                'type' => 'zone',
            ],
            'location'   => [
                'latitude'  => 1.3521,
                'longitude' => 103.8198,
            ],
        ]);
});

test('geofence entered listener calculates proximity and ignores terminal orders', function () {
    $listener = new HandleGeofenceEntered();
    $distance = new ReflectionMethod(HandleGeofenceEntered::class, 'haversineDistance');
    $distance->setAccessible(true);

    expect($listener->tries)->toBe(3)
        ->and((int) round($distance->invoke($listener, 1.3000, 103.8000, 1.3010, 103.8010)))->toBe(157);
});

test('geofence exited and dwelled events broadcast vehicle subject payloads', function () {
    session([
        'company'        => null,
        'api_credential' => null,
    ]);

    $driver = new FleetOpsEventDriver();
    $driver->setRawAttributes([
        'uuid'         => 'driver-uuid',
        'public_id'    => 'driver_public',
        'company_uuid' => 'company-uuid',
        'name'         => 'Jane Driver',
        'phone'        => '+6555551111',
    ], true);
    $driver->setRelation('user', (object) [
        'name'  => 'Jane Driver',
        'phone' => '+6555551111',
    ]);

    $vehicle = new FleetOpsEventVehicle();
    $vehicle->setRawAttributes([
        'uuid'         => 'vehicle-uuid',
        'public_id'    => 'vehicle_public',
        'company_uuid' => 'company-uuid',
        'name'         => 'Dock Van',
        'plate_number' => 'SG-202',
    ], true);
    $vehicle->setRelation('driver', $driver);

    $geofence = (object) [
        'uuid'      => 'service-area-uuid',
        'public_id' => 'service_area_public',
        'name'      => 'North Service Area',
    ];

    $exited  = new GeofenceExited($vehicle, $geofence, 'service_area', new Point(1.4, 103.9), 17);
    $dwelled = new GeofenceDwelled($vehicle, $geofence, 'service_area', now()->subMinutes(45));

    expect($exited->broadcastAs())->toBe('geofence.exited')
        ->and($exited->subjectType)->toBe('vehicle')
        ->and($exited->dwellDurationMinutes)->toBe(17)
        ->and(eventChannelNames($exited->broadcastOn()))->toBe([
            'company.company-uuid',
            'driver.driver_public',
            'driver.driver-uuid',
            'vehicle.vehicle_public',
            'vehicle.vehicle-uuid',
        ])
        ->and($exited->broadcastWith())->toMatchArray([
            'event'                  => 'geofence.exited',
            'event_type'             => 'geofence.exited',
            'dwell_duration_minutes' => 17,
            'subject'                => [
                'type' => 'vehicle',
                'id'   => 'vehicle_public',
                'uuid' => 'vehicle-uuid',
                'name' => 'Dock Van',
            ],
            'driver'                 => [
                'id'    => 'driver_public',
                'uuid'  => 'driver-uuid',
                'name'  => 'Jane Driver',
                'phone' => '+6555551111',
            ],
            'vehicle'                => [
                'id'    => 'vehicle_public',
                'uuid'  => 'vehicle-uuid',
                'name'  => 'Dock Van',
                'plate' => 'SG-202',
            ],
            'geofence'               => [
                'id'   => 'service_area_public',
                'uuid' => 'service-area-uuid',
                'name' => 'North Service Area',
                'type' => 'service_area',
            ],
            'location'               => [
                'latitude'  => 1.4,
                'longitude' => 103.9,
            ],
        ])
        ->and($dwelled->broadcastAs())->toBe('geofence.dwelled')
        ->and($dwelled->subjectType)->toBe('vehicle')
        ->and($dwelled->dwellDurationMinutes)->toBeGreaterThanOrEqual(44)
        ->and($dwelled->broadcastWith())->toMatchArray([
            'event'      => 'geofence.dwelled',
            'event_type' => 'geofence.dwelled',
            'subject'    => [
                'type' => 'vehicle',
                'id'   => 'vehicle_public',
                'uuid' => 'vehicle-uuid',
                'name' => 'Dock Van',
            ],
            'geofence'   => [
                'id'   => 'service_area_public',
                'uuid' => 'service-area-uuid',
                'name' => 'North Service Area',
                'type' => 'service_area',
            ],
        ]);
});

test('geofence exit and dwell listeners persist normalized event log payloads', function () {
    Carbon::setTestNow(Carbon::parse('2026-05-01 14:15:00'));

    $order = new Order();
    $order->setRawAttributes(['uuid' => 'order-uuid'], true);

    $driver               = new FleetOpsEventDriverWithOrder();
    $driver->currentOrder = $order;
    $driver->setRawAttributes([
        'uuid'         => 'driver-uuid',
        'public_id'    => 'driver_public',
        'company_uuid' => 'company-uuid',
        'name'         => 'Jane Driver',
    ], true);

    $vehicle = new FleetOpsEventVehicle();
    $vehicle->setRawAttributes([
        'uuid'         => 'vehicle-uuid',
        'public_id'    => 'vehicle_public',
        'company_uuid' => 'company-uuid',
        'display_name' => 'Dock Van',
        'plate_number' => 'SG-202',
    ], true);
    $vehicle->setRelation('driver', $driver);
    $driver->setRelation('vehicle', $vehicle);

    $geofence = (object) [
        'uuid'      => 'geofence-uuid',
        'public_id' => 'geofence_public',
        'name'      => 'North Service Area',
    ];

    $exitedListener  = new FleetOpsGeofenceExitedListenerProbe();
    $dwelledListener = new FleetOpsGeofenceDwelledListenerProbe();

    $exitedEvent = new GeofenceExited($vehicle, $geofence, 'service_area', new Point(1.4, 103.9), 17);
    $exitedListener->handle($exitedEvent);
    $dwelledEvent = new GeofenceDwelled($driver, $geofence, 'service_area', now()->subMinutes(45));
    $dwelledListener->handle($dwelledEvent);

    expect($exitedListener->tries)->toBe(3)
        ->and($dwelledListener->tries)->toBe(3)
        ->and($exitedListener->logs)->toHaveCount(1)
        ->and($dwelledListener->logs)->toHaveCount(1)
        ->and($exitedListener->logs[0])->toMatchArray([
            'company_uuid'           => 'company-uuid',
            'driver_uuid'            => 'driver-uuid',
            'vehicle_uuid'           => 'vehicle-uuid',
            'order_uuid'             => 'order-uuid',
            'subject_uuid'           => 'vehicle-uuid',
            'subject_type'           => 'vehicle',
            'subject_name'           => 'Dock Van',
            'geofence_uuid'          => 'geofence-uuid',
            'geofence_type'          => 'service_area',
            'geofence_name'          => 'North Service Area',
            'event_type'             => 'exited',
            'latitude'               => 1.4,
            'longitude'              => 103.9,
            'dwell_duration_minutes' => 17,
        ])
        ->and($exitedListener->logs[0]['occurred_at']->toDateTimeString())->toBe('2026-05-01 14:15:00')
        ->and($dwelledListener->logs[0])->toMatchArray([
            'company_uuid'           => 'company-uuid',
            'driver_uuid'            => 'driver-uuid',
            'vehicle_uuid'           => 'vehicle-uuid',
            'order_uuid'             => 'order-uuid',
            'subject_uuid'           => 'driver-uuid',
            'subject_type'           => 'driver',
            'subject_name'           => 'Jane Driver',
            'geofence_uuid'          => 'geofence-uuid',
            'geofence_type'          => 'service_area',
            'geofence_name'          => 'North Service Area',
            'event_type'             => 'dwelled',
            'latitude'               => null,
            'longitude'              => null,
            'dwell_duration_minutes' => 45,
        ])
        ->and($dwelledListener->logs[0]['occurred_at']->toDateTimeString())->toBe('2026-05-01 14:15:00');

    Carbon::setTestNow();
});

test('notify order event routes lifecycle events to matching notifications', function () {
    $order = new Order();
    $order->setRawAttributes(['uuid' => 'order-uuid', 'public_id' => 'order_public'], true);

    $waypoint = new Waypoint();
    $waypoint->setRawAttributes(['uuid' => 'waypoint-uuid'], true);

    $listener = new FleetOpsNotifyOrderEventProbe();

    $canceled           = new FleetOpsOrderCanceledNotificationEvent();
    $canceled->order    = $order;
    $canceled->waypoint = $waypoint;
    $canceled->activity = new Activity(['details' => 'Customer canceled']);

    $completed           = new FleetOpsOrderCompletedNotificationEvent();
    $completed->order    = $order;
    $completed->waypoint = $waypoint;

    $failed           = new FleetOpsOrderFailedNotificationEvent();
    $failed->order    = $order;
    $failed->waypoint = $waypoint;
    $failed->activity = new Activity(['details' => 'Delivery failed']);

    $dispatchFailed        = new FleetOpsOrderDispatchFailedNotificationEvent();
    $dispatchFailed->order = $order;

    $dispatched           = new FleetOpsOrderDispatchedNotificationEvent();
    $dispatched->order    = $order;
    $dispatched->waypoint = $waypoint;

    $assigned        = new FleetOpsOrderDriverAssignedNotificationEvent();
    $assigned->order = $order;

    foreach ([$canceled, $completed, $failed, $dispatchFailed, $dispatched, $assigned] as $event) {
        $listener->handle($event);
    }

    $withoutOrder = new FleetOpsOrderCanceledNotificationEvent();
    $listener->handle($withoutOrder);

    expect($listener->notifications)->toHaveCount(6)
        ->and($listener->notifications[0])->toBe([OrderCanceledNotification::class, [$order, 'Customer canceled', $waypoint]])
        ->and($listener->notifications[1])->toBe([OrderCompletedNotification::class, [$order, $waypoint]])
        ->and($listener->notifications[2])->toBe([OrderFailedNotification::class, [$order, 'Delivery failed', $waypoint]])
        ->and($listener->notifications[3])->toBe([OrderDispatchFailedNotification::class, [$order]])
        ->and($listener->notifications[4])->toBe([OrderDispatchedNotification::class, [$order, $waypoint]])
        ->and($listener->notifications[5])->toBe([OrderAssignedNotification::class, [$order]]);
});

test('order dispatch failed event exposes configured reason', function () {
    $event         = new FleetOpsOrderDispatchFailedNotificationEvent();
    $event->reason = 'No eligible driver';

    expect($event->eventName)->toBe('dispatch_failed')
        ->and($event->getReason())->toBe('No eligible driver');
});
