<?php

use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\FleetOps\Models\Waypoint;
use Fleetbase\FleetOps\Support\TrackingScope;
use Fleetbase\FleetOps\Support\TrackingUpdate;
use Fleetbase\FleetOps\Tracking\TrackingStop;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Fleetbase\Models\User;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Carbon;

/**
 * Covers the per-customer public tracking payload: only the viewing customer's stops, ETAs and
 * timelines; whole-route counts; the driver's first name, vehicle label and online flag with no
 * photo or phone; and the rounded vehicle position only while en route to a stop of theirs.
 */
class TrackingUpdateExplodingDriver extends Driver
{
    public function getLocationAttribute()
    {
        throw new RuntimeException('unreadable location');
    }
}

const TRACKING_UPDATE_CONTACT = 'Fleetbase\\FleetOps\\Models\\Contact';

function trackingUpdateModel(string $class, array $attributes): EloquentModel
{
    $model = (new ReflectionClass($class))->newInstanceWithoutConstructor();
    $model->setRawAttributes($attributes, true);

    return $model;
}

function trackingUpdatePlace(string $name, string $address): Place
{
    return trackingUpdateModel(Place::class, ['uuid' => 'place-' . md5($name), 'public_id' => 'place_' . substr(md5($name), 0, 7), 'name' => $name, 'street1' => $address, 'location' => new Point(1.4, 103.9)]);
}

function trackingUpdateWaypoint(string $publicId, ?string $customerUuid): Waypoint
{
    return trackingUpdateModel(Waypoint::class, [
        'uuid'          => 'uuid-' . $publicId,
        'public_id'     => $publicId,
        'customer_uuid' => $customerUuid,
        'customer_type' => $customerUuid ? TRACKING_UPDATE_CONTACT : null,
    ]);
}

function trackingUpdateStop(string $type, int $sequence, bool $completed, ?Waypoint $waypoint, string $trackingNumber, string $placeName): TrackingStop
{
    return new TrackingStop(
        uuid: 'place-' . $sequence,
        publicId: 'place_' . $sequence,
        type: $type,
        status: $completed ? 'completed' : 'enroute',
        place: trackingUpdatePlace($placeName, $placeName . ' Street'),
        waypoint: $waypoint,
        completed: $completed,
        sequence: $sequence,
        trackingNumberUuid: $trackingNumber,
    );
}

/**
 * A started order for contact-1111 sharing its route with contact-2222:
 * pickup (theirs, done), Jane's stop, their own drop, Jane's second stop.
 */
function trackingUpdateStops(bool $janeDone = false): array
{
    return [
        trackingUpdateStop('pickup', 1, true, null, 'tn-pickup', 'Acme Warehouse'),
        trackingUpdateStop('waypoint', 2, $janeDone, trackingUpdateWaypoint('waypoint_jane1', 'contact-2222'), 'tn-jane1', 'Jane Home'),
        trackingUpdateStop('waypoint', 3, false, trackingUpdateWaypoint('waypoint_own', null), 'tn-own', 'Own Office'),
        trackingUpdateStop('waypoint', 4, $janeDone, trackingUpdateWaypoint('waypoint_jane2', 'contact-2222'), 'tn-jane2', 'Jane Gym'),
    ];
}

function trackingUpdateDriver(array $attributes = [], string $class = Driver::class): Driver
{
    $driver = trackingUpdateModel($class, array_merge([
        'uuid'       => 'driver-1111',
        'public_id'  => 'driver_1111',
        'online'     => true,
        'heading'    => 87.6,
        'location'   => new Point(1.352083, 103.819836),
        'phone'      => '+6591234567',
        'updated_at' => Carbon::parse('2026-10-06 10:00:30'),
    ], $attributes));
    $driver->setRelation('user', trackingUpdateModel(User::class, ['name' => 'Ana Lopez', 'phone' => '+6591234567', 'avatar_url' => 'https://cdn.example/ana.jpg']));
    $driver->setRelation('vehicle', null);

    return $driver;
}

function trackingUpdateVehicle(array $attributes = []): Vehicle
{
    return trackingUpdateModel(Vehicle::class, array_merge([
        'uuid'         => 'vehicle-1111',
        'name'         => 'Van 7',
        'plate_number' => 'SGX1234A',
        'heading'      => 10,
        'location'     => new Point(1.30001, 103.80001),
        'updated_at'   => Carbon::parse('2026-10-06 10:00:00'),
    ], $attributes));
}

function trackingUpdateOrder(array $attributes = [], ?Driver $driver = null, ?Vehicle $vehicle = null): Order
{
    $order = trackingUpdateModel(Order::class, array_merge([
        'uuid'                 => 'order-1111',
        'company_uuid'         => 'company-1111',
        'customer_type'        => TRACKING_UPDATE_CONTACT,
        'customer_uuid'        => 'contact-1111',
        'tracking_number_uuid' => 'tn-order',
        'status'               => 'started',
        'started'              => true,
    ], $attributes));
    $order->setRelation('driverAssigned', $driver);
    $order->setRelation('vehicleAssigned', $vehicle);

    return $order;
}

function trackingUpdateTimelines(): array
{
    $entry = fn (string $status, string $code) => ['status' => $status, 'code' => $code, 'complete' => false, 'created_at' => '2026-10-06T09:00:00.000000Z'];

    return [
        'tn-order'  => [$entry('Order started', 'started')],
        'tn-pickup' => [$entry('Picked up', 'picked_up')],
        'tn-own'    => [$entry('Driver en route', 'enroute')],
        'tn-jane1'  => [$entry('Arriving at Jane Home', 'enroute')],
        'tn-jane2'  => [$entry('Queued for Jane Gym', 'pending')],
    ];
}

function trackingUpdateEtas(): array
{
    return [
        2 => ['seconds' => 300, 'at' => '2026-10-06T10:05:00.000000Z', 'distance_m' => 1200],
        3 => ['seconds' => 600, 'at' => '2026-10-06T10:10:00.000000Z', 'distance_m' => 2500],
        4 => ['seconds' => 900, 'at' => '2026-10-06T10:15:00.000000Z', 'distance_m' => 4100],
    ];
}

beforeEach(function () {
    Carbon::setTestNow('2026-10-06 10:01:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

test('the order customer sees only their own stops with whole-route counts', function () {
    $order  = trackingUpdateOrder([], trackingUpdateDriver(), trackingUpdateVehicle());
    $scope  = TrackingScope::forOrder($order);
    $update = new TrackingUpdate($order, $scope, trackingUpdateStops(), trackingUpdateEtas(), trackingUpdateTimelines());

    $payload = $update->toArray(TrackingUpdate::REASON_STOP);

    expect($payload['event'])->toBe('tracking.updated')
        ->and($payload['reason'])->toBe('stop')
        ->and($payload['sent_at'])->toBe('2026-10-06T10:01:00.000000Z')
        ->and($payload['data']['order'])->toBe([
            'status'    => 'started',
            'en_route'  => true,
            'completed' => false,
            'canceled'  => false,
            'timeline'  => trackingUpdateTimelines()['tn-order'],
        ])
        ->and($payload['data']['route'])->toBe(['total_stops' => 4, 'completed_stops' => 1, 'stops_before' => 1])
        ->and($payload['data']['stops'])->toBe([
            ['id' => null, 'type' => 'pickup', 'sequence' => 1, 'status' => 'completed', 'complete' => true, 'eta' => null, 'timeline' => trackingUpdateTimelines()['tn-pickup']],
            ['id' => 'waypoint_own', 'type' => 'waypoint', 'sequence' => 3, 'status' => 'enroute', 'complete' => false, 'eta' => trackingUpdateEtas()[3], 'timeline' => trackingUpdateTimelines()['tn-own']],
        ])
        ->and($payload['data']['driver'])->toBe(['name' => 'Ana', 'vehicle' => 'Van 7', 'online' => true])
        ->and($payload['data']['location'])->toBe(['latitude' => 1.3521, 'longitude' => 103.8198, 'heading' => 88, 'seen_at' => '2026-10-06T10:00:30.000000Z'])
        ->and($update->timelineTrackingNumbers())->toBe(['tn-pickup', 'tn-own', 'tn-order']);

    // Nothing of the other customer's stops, places or people, and nothing personal about the driver.
    $json = json_encode($payload);
    foreach (['waypoint_jane1', 'waypoint_jane2', 'Jane', 'contact-2222', 'Acme Warehouse', 'Own Office', 'Street', 'Lopez', '+6591234567', 'ana.jpg', 'SGX1234A', 'tn-'] as $forbidden) {
        expect($json)->not->toContain($forbidden);
    }
});

test('a waypoint customer sees only their stops and never the order timeline', function () {
    $order  = trackingUpdateOrder([], trackingUpdateDriver(), trackingUpdateVehicle());
    $scope  = TrackingScope::forStop($order, trackingUpdateWaypoint('waypoint_jane1', 'contact-2222'));
    $update = new TrackingUpdate($order, $scope, trackingUpdateStops(), trackingUpdateEtas(), trackingUpdateTimelines());

    $payload = $update->toArray();

    expect($payload['reason'])->toBe('status')
        ->and(array_column($payload['data']['stops'], 'id'))->toBe(['waypoint_jane1', 'waypoint_jane2'])
        ->and(array_column($payload['data']['stops'], 'eta'))->toBe([trackingUpdateEtas()[2], trackingUpdateEtas()[4]])
        ->and($payload['data']['stops'][0]['timeline'])->toBe(trackingUpdateTimelines()['tn-jane1'])
        ->and($payload['data']['order']['timeline'])->toBe([])
        ->and($payload['data']['route'])->toBe(['total_stops' => 4, 'completed_stops' => 1, 'stops_before' => 0])
        ->and($update->timelineTrackingNumbers())->toBe(['tn-jane1', 'tn-jane2'])
        ->and($payload['data']['location'])->not->toBeNull();

    $json = json_encode($payload);
    foreach (['waypoint_own', 'contact-1111', 'Order started', 'Picked up', 'Driver en route', 'Acme', 'Own Office', 'Lopez', '+6591234567', 'ana.jpg'] as $forbidden) {
        expect($json)->not->toContain($forbidden);
    }
});

test('the driver and position are shown only while en route to a stop of the viewer', function () {
    $driver  = trackingUpdateDriver();
    $vehicle = trackingUpdateVehicle();

    // Not started yet: no driver and no position.
    $order  = trackingUpdateOrder(['status' => 'dispatched', 'started' => false], $driver, $vehicle);
    $update = new TrackingUpdate($order, TrackingScope::forOrder($order), trackingUpdateStops());
    expect($update->isEnRoute())->toBeFalse()
        ->and($update->driver())->toBeNull()
        ->and($update->location())->toBeNull()
        ->and($update->locationPayload())->toBeNull();

    // Started by status or timestamp alone.
    $byStatus = trackingUpdateOrder(['status' => 'started', 'started' => false], $driver, $vehicle);
    $byTime   = trackingUpdateOrder(['status' => 'enroute', 'started' => false, 'started_at' => Carbon::parse('2026-10-06 09:00:00')], $driver, $vehicle);
    expect((new TrackingUpdate($byStatus, TrackingScope::forOrder($byStatus), trackingUpdateStops()))->isEnRoute())->toBeTrue()
        ->and((new TrackingUpdate($byTime, TrackingScope::forOrder($byTime), trackingUpdateStops()))->isEnRoute())->toBeTrue();

    // Delivered or canceled: hidden, and the order state says so.
    foreach (['completed', 'canceled'] as $status) {
        $done    = trackingUpdateOrder(['status' => $status], $driver, $vehicle);
        $payload = (new TrackingUpdate($done, TrackingScope::forOrder($done), trackingUpdateStops()))->toArray();
        expect($payload['data']['driver'])->toBeNull()
            ->and($payload['data']['location'])->toBeNull()
            ->and($payload['data']['order'][$status])->toBeTrue()
            ->and($payload['data']['order']['en_route'])->toBeFalse();
    }

    // En route, but every stop of this customer is done: hidden for them, shown to the other.
    $order = trackingUpdateOrder([], $driver, $vehicle);
    $jane  = new TrackingUpdate($order, TrackingScope::forStop($order, trackingUpdateWaypoint('waypoint_jane1', 'contact-2222')), trackingUpdateStops(true));
    $own   = new TrackingUpdate($order, TrackingScope::forOrder($order), trackingUpdateStops(true));
    expect($jane->customerHasIncompleteStop())->toBeFalse()
        ->and($jane->locationPayload())->toBeNull()
        ->and($jane->route()['stops_before'])->toBe(0)
        ->and($own->locationPayload()['event'])->toBe('tracking.location')
        ->and($own->locationPayload()['reason'])->toBe('location')
        ->and($own->locationPayload()['data'])->toBe([
            'driver'   => ['name' => 'Ana', 'vehicle' => 'Van 7', 'online' => true],
            'location' => ['latitude' => 1.3521, 'longitude' => 103.8198, 'heading' => 88, 'seen_at' => '2026-10-06T10:00:30.000000Z'],
        ]);

    // A customer with no stops on the route sees no vehicle.
    $stranger = new TrackingUpdate($order, new TrackingScope('order-1111', TRACKING_UPDATE_CONTACT, 'contact-9999'), trackingUpdateStops());
    expect($stranger->ownStops())->toHaveCount(0)
        ->and($stranger->location())->toBeNull()
        ->and($stranger->toArray()['data']['stops'])->toBe([]);
});

test('the position comes from the most recently seen of driver and vehicle', function () {
    $stops = trackingUpdateStops();

    // The vehicle reported last.
    $order = trackingUpdateOrder([], trackingUpdateDriver(['updated_at' => Carbon::parse('2026-10-06 09:00:00')]), trackingUpdateVehicle(['updated_at' => Carbon::parse('2026-10-06 10:00:50')]));
    expect((new TrackingUpdate($order, TrackingScope::forOrder($order), $stops))->location())
        ->toBe(['latitude' => 1.3, 'longitude' => 103.8, 'heading' => 10, 'seen_at' => '2026-10-06T10:00:50.000000Z']);

    // The driver's position is unusable (null island), so the vehicle's is used.
    $order = trackingUpdateOrder([], trackingUpdateDriver(['location' => new Point(0, 0)]), trackingUpdateVehicle(['heading' => 'n/a', 'updated_at' => null]));
    expect((new TrackingUpdate($order, TrackingScope::forOrder($order), $stops))->location())
        ->toBe(['latitude' => 1.3, 'longitude' => 103.8, 'heading' => null, 'seen_at' => null]);

    // Unreadable or missing positions everywhere: no position, but the driver is still named.
    $order  = trackingUpdateOrder([], trackingUpdateDriver([], TrackingUpdateExplodingDriver::class), trackingUpdateVehicle(['location' => null]));
    $update = new TrackingUpdate($order, TrackingScope::forOrder($order), $stops);
    expect($update->location())->toBeNull()
        ->and($update->locationPayload())->toBeNull()
        ->and($update->driver()['name'])->toBe('Ana');

    // Without a driver, the assigned vehicle alone gives the position.
    $order = trackingUpdateOrder([], null, trackingUpdateVehicle());
    $update = new TrackingUpdate($order, TrackingScope::forOrder($order), $stops);
    expect($update->driver())->toBeNull()
        ->and($update->location()['latitude'])->toBe(1.3);

    // Without an assigned vehicle the driver's current vehicle is labelled; an unnamed one has no label.
    $driver = trackingUpdateDriver();
    $driver->setRelation('vehicle', trackingUpdateVehicle(['name' => 'Truck 9']));
    $order = trackingUpdateOrder([], $driver, null);
    expect((new TrackingUpdate($order, TrackingScope::forOrder($order), $stops))->driver()['vehicle'])->toBe('Truck 9');

    $driver->setRelation('vehicle', trackingUpdateVehicle(['name' => null]));
    expect((new TrackingUpdate($order, TrackingScope::forOrder($order), $stops))->driver()['vehicle'])->toBeNull();

    // Nobody assigned at all.
    $order = trackingUpdateOrder([], null, null);
    expect((new TrackingUpdate($order, TrackingScope::forOrder($order), $stops))->location())->toBeNull();
});

test('order state, names and etas tolerate missing values', function () {
    $order  = trackingUpdateOrder(['status' => null, 'started' => false], null, null);
    $update = new TrackingUpdate($order, TrackingScope::forOrder($order), ['not a stop', ...trackingUpdateStops()]);

    expect($update->toArray()['data']['order']['status'])->toBeNull()
        ->and($update->route()['total_stops'])->toBe(4)
        ->and($update->isTerminal())->toBeFalse()
        ->and(TrackingUpdate::firstName(null))->toBeNull()
        ->and(TrackingUpdate::firstName('  '))->toBeNull()
        ->and(TrackingUpdate::firstName('Cher'))->toBe('Cher')
        ->and(TrackingUpdate::firstName(' Ana Maria Lopez '))->toBe('Ana');

    expect(TrackingUpdate::etasFromTracker([]))->toBe([])
        ->and(TrackingUpdate::etasFromTracker(['route' => ['legs' => [
            ['stop' => null, 'eta_seconds' => 10],
            ['stop' => ['sequence' => 2], 'eta_seconds' => 299.6, 'eta_at' => '2026-10-06T10:05:00.000000Z', 'distance_m' => 1199.7],
            ['stop' => ['sequence' => '3'], 'eta_seconds' => null, 'eta_at' => null],
        ]]]))->toBe([
            2 => ['seconds' => 300, 'at' => '2026-10-06T10:05:00.000000Z', 'distance_m' => 1200],
            3 => ['seconds' => null, 'at' => null, 'distance_m' => null],
        ]);
});

test('timelines are loaded for the viewer\'s tracking numbers only, without free-text details', function () {
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
    $resolver   = new ConnectionResolver(['default' => $connection, 'mysql' => $connection]);
    $resolver->setDefaultConnection('mysql');
    EloquentModel::setConnectionResolver($resolver);
    $connection->getSchemaBuilder()->create('tracking_statuses', function ($blueprint) {
        $blueprint->increments('id');
        foreach (['uuid', 'public_id', 'tracking_number_uuid', 'status', 'details', 'code', 'complete'] as $column) {
            $blueprint->string($column)->nullable();
        }
        $blueprint->timestamp('created_at')->nullable();
        $blueprint->timestamp('updated_at')->nullable();
        $blueprint->timestamp('deleted_at')->nullable();
    });
    $connection->table('tracking_statuses')->insert([
        ['uuid' => 's1', 'tracking_number_uuid' => 'tn-order', 'status' => 'Order created', 'details' => 'Created by Jane', 'code' => 'CREATED', 'complete' => '0', 'created_at' => '2026-10-06 08:00:00'],
        ['uuid' => 's2', 'tracking_number_uuid' => 'tn-order', 'status' => 'Order started', 'details' => 'Driver left Acme', 'code' => 'started', 'complete' => '0', 'created_at' => '2026-10-06 09:00:00'],
        ['uuid' => 's3', 'tracking_number_uuid' => 'tn-own', 'status' => 'Driver en route', 'details' => null, 'code' => null, 'complete' => '0', 'created_at' => null],
        ['uuid' => 's4', 'tracking_number_uuid' => 'tn-jane1', 'status' => 'Arriving at Jane Home', 'details' => null, 'code' => 'enroute', 'complete' => '0', 'created_at' => '2026-10-06 09:30:00'],
    ]);

    $order   = trackingUpdateOrder([], trackingUpdateDriver(), trackingUpdateVehicle());
    $tracker = ['route' => ['legs' => [['stop' => ['sequence' => 3], 'eta_seconds' => 600, 'eta_at' => '2026-10-06T10:11:00.000000Z', 'distance_m' => 2500]]]];
    $update  = TrackingUpdate::for($order, TrackingScope::forOrder($order), trackingUpdateStops(), $tracker);
    $payload = $update->toArray();

    expect($payload['data']['order']['timeline'])->toBe([
        ['status' => 'Order created', 'code' => 'created', 'complete' => false, 'created_at' => '2026-10-06T08:00:00.000000Z'],
        ['status' => 'Order started', 'code' => 'started', 'complete' => false, 'created_at' => '2026-10-06T09:00:00.000000Z'],
    ])
        ->and($payload['data']['stops'][1]['timeline'])->toBe([['status' => 'Driver en route', 'code' => null, 'complete' => false, 'created_at' => null]])
        ->and($payload['data']['stops'][1]['eta'])->toBe(['seconds' => 600, 'at' => '2026-10-06T10:11:00.000000Z', 'distance_m' => 2500])
        ->and(json_encode($payload))->not->toContain('Jane')
        ->and(json_encode($payload))->not->toContain('Acme')
        ->and(TrackingUpdate::loadTimelines([]))->toBe([]);
});
