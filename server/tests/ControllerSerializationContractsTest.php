<?php

use Fleetbase\FleetOps\Http\Controllers\Api\v1\GeofenceController;
use Fleetbase\FleetOps\Models\GeofenceEventLog;

function fleetopsCallControllerMethod(object $controller, string $method, array $arguments = []): mixed
{
    $reflection = new ReflectionMethod($controller::class, $method);
    $reflection->setAccessible(true);

    return $reflection->invokeArgs($controller, $arguments);
}

test('geofence event serializer projects subject driver vehicle and order details', function () {
    $driver = (object) [
        'public_id' => 'driver_public',
        'uuid'      => 'driver-uuid',
        'name'      => 'Jane Driver',
        'phone'     => '+6555550000',
    ];
    $vehicle = (object) [
        'public_id'    => 'vehicle_public',
        'uuid'         => 'vehicle-uuid',
        'display_name' => 'Truck 101',
        'name'         => 'Fallback Truck',
        'plate_number' => 'SG-101',
    ];
    $order = (object) [
        'public_id' => 'order_public',
        'uuid'      => 'order-uuid',
        'status'    => 'dispatched',
    ];
    $driver->vehicle = $vehicle;

    $event = new GeofenceEventLog([
        'uuid'                   => 'event-uuid',
        'subject_uuid'           => 'driver-uuid',
        'subject_type'           => 'driver',
        'subject_name'           => 'Jane Driver',
        'event_type'             => 'entered',
        'occurred_at'            => null,
        'dwell_duration_minutes' => 12,
        'geofence_uuid'          => 'zone-uuid',
        'geofence_name'          => 'Central Zone',
        'geofence_type'          => 'zone',
        'latitude'               => 1.3521,
        'longitude'              => 103.8198,
    ]);
    $event->setRelation('driver', $driver);
    $event->setRelation('vehicle', null);
    $event->setRelation('order', $order);

    $payload = fleetopsCallControllerMethod(new GeofenceController(), 'serializeEvent', [$event]);

    expect($payload)->toMatchArray([
        'id'                     => 'event-uuid',
        'event_type'             => 'geofence.entered',
        'occurred_at'            => null,
        'dwell_duration_minutes' => 12,
        'subject'                => [
            'type' => 'driver',
            'id'   => 'driver_public',
            'uuid' => 'driver-uuid',
            'name' => 'Jane Driver',
        ],
        'driver'                 => [
            'id'    => 'driver_public',
            'uuid'  => 'driver-uuid',
            'name'  => 'Jane Driver',
            'phone' => '+6555550000',
        ],
        'vehicle'                => [
            'id'    => 'vehicle_public',
            'uuid'  => 'vehicle-uuid',
            'name'  => 'Truck 101',
            'plate' => 'SG-101',
        ],
        'geofence'               => [
            'uuid' => 'zone-uuid',
            'name' => 'Central Zone',
            'type' => 'zone',
        ],
        'location'               => [
            'latitude'  => 1.3521,
            'longitude' => 103.8198,
        ],
        'order'                  => [
            'id'     => 'order_public',
            'uuid'   => 'order-uuid',
            'status' => 'dispatched',
        ],
    ]);
});

test('geofence event serializer falls back to vehicle subjects without drivers', function () {
    $vehicle = (object) [
        'public_id'    => 'vehicle_public',
        'uuid'         => 'vehicle-uuid',
        'display_name' => null,
        'name'         => 'Vehicle Name',
        'plate_number' => 'SG-202',
    ];

    $event = new GeofenceEventLog([
        'uuid'          => 'vehicle-event-uuid',
        'subject_uuid'  => 'vehicle-uuid',
        'subject_type'  => 'vehicle',
        'subject_name'  => null,
        'event_type'    => 'exited',
        'occurred_at'   => null,
        'geofence_uuid' => 'service-area-uuid',
        'geofence_name' => 'North Service Area',
        'geofence_type' => 'service_area',
        'latitude'      => null,
        'longitude'     => null,
    ]);
    $event->setRelation('driver', null);
    $event->setRelation('vehicle', $vehicle);
    $event->setRelation('order', null);

    $payload = fleetopsCallControllerMethod(new GeofenceController(), 'serializeEvent', [$event]);

    expect($payload)->toMatchArray([
        'id'         => 'vehicle-event-uuid',
        'event_type' => 'geofence.exited',
        'subject'    => [
            'type' => 'vehicle',
            'id'   => 'vehicle_public',
            'uuid' => 'vehicle-uuid',
            'name' => 'SG-202',
        ],
        'driver'     => null,
        'vehicle'    => [
            'id'    => 'vehicle_public',
            'uuid'  => 'vehicle-uuid',
            'name'  => 'Vehicle Name',
            'plate' => 'SG-202',
        ],
        'order'      => null,
    ]);
});
