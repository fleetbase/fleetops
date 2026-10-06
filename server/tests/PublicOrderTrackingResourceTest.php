<?php

use Fleetbase\FleetOps\Http\Resources\v1\PublicOrderTracking;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * An order as the lookup loads it: tracking number, timeline, payload and
 * tracker data, plus every field the public page must never see.
 */
function fleetopsPublicTrackingOrder(array $attributes = [], array $tracker = []): Order
{
    $order = new Order();
    $order->setRawAttributes(array_merge([
        'uuid'                 => 'order-internal-uuid',
        'public_id'            => 'order_public',
        'internal_id'          => 'INT-0042',
        'company_uuid'         => 'company-uuid',
        'customer_uuid'        => 'customer-uuid',
        'facilitator_uuid'     => 'facilitator-uuid',
        'driver_assigned_uuid' => 'driver-uuid',
        'purchase_rate_uuid'   => 'rate-uuid',
        'notes'                => 'Gate code 1234',
        'meta'                 => '{"secret":"value"}',
        'status'               => 'started',
        'started'              => true,
        'created_at'           => Carbon::parse('2026-10-01 09:00:00'),
    ], $attributes), true);

    $order->setRelation('trackingNumber', (object) [
        'uuid'            => 'tracking-internal-uuid',
        'public_id'       => 'track_public',
        'tracking_number' => 'FLB1234567890SG',
        'qr_code'         => 'qr-data',
        'barcode'         => 'barcode-data',
    ]);
    $order->setRelation('trackingStatuses', collect([
        (object) [
            'uuid'       => 'status-internal-uuid',
            'public_id'  => 'status_public',
            'status'     => 'Order Created',
            'details'    => 'New order created.',
            'code'       => 'created',
            'location'   => new Point(1.3, 103.8),
            'created_at' => Carbon::parse('2026-10-01 09:00:00'),
        ],
    ]));
    $order->setRelation('payload', (object) [
        'uuid'      => 'payload-internal-uuid',
        'public_id' => 'payload_public',
        'meta'      => ['secret' => 'value'],
        'cod_amount' => '100',
        'pickup'    => (object) [
            'uuid'      => 'pickup-internal-uuid',
            'public_id' => 'place_pickup',
            'name'      => 'Sender House',
            'street1'   => '1 Sender Road',
            'phone'     => '+6500000000',
            'location'  => new Point(1.30, 103.80),
        ],
        'dropoff'   => (object) [
            'uuid'      => 'dropoff-internal-uuid',
            'public_id' => 'place_dropoff',
            'name'      => 'Recipient House',
            'street1'   => '2 Recipient Road',
            'location'  => new Point(1.35, 103.85),
        ],
        'waypoints' => [],
        'entities'  => collect([
            (object) [
                'uuid'          => 'entity-internal-uuid',
                'public_id'     => 'entity_public',
                'internal_id'   => 'ENT-INT',
                'customer_uuid' => 'customer-uuid',
                'name'          => 'Parcel',
                'description'   => 'A box',
                'tracking'      => 'FLB9999999999SG',
                'price'         => '1500',
                'currency'      => 'SGD',
                'photo_url'     => 'https://example.test/parcel.png',
                'meta'          => ['secret' => 'value'],
            ],
        ]),
    ]);

    $order->setAttribute('tracker_data', array_merge([
        'provider'    => 'internal',
        'driver'      => [
            'location'             => ['type' => 'Point', 'coordinates' => [103.82, 1.32]],
            'location_age_seconds' => 12,
            'online'               => true,
        ],
        'progress'    => ['percentage' => 40, 'completed_stops' => 1, 'total_stops' => 2],
        'stops'       => [['uuid' => 'stop-uuid', 'address' => '1 Sender Road']],
        'active_stop' => ['uuid' => 'stop-uuid', 'address' => '2 Recipient Road', 'name' => 'Recipient House', 'latitude' => 1.35],
        'next_stop'   => null,
        'route'       => ['polyline' => 'abc'],
        'eta'         => ['active_stop_seconds' => 600, 'completion_seconds' => 600, 'completion_at' => '2026-10-01T09:30:00.000Z'],
        'insights'    => ['is_delayed' => false],
    ], $tracker));

    return $order;
}

test('public order tracking resource carries only what the track order page renders', function () {
    $data = (new PublicOrderTracking(fleetopsPublicTrackingOrder()))->toArray(new Request());

    expect(array_keys($data))->toBe([
        'uuid', 'tracking', 'status', 'has_driver_assigned', 'tracking_number',
        'tracking_statuses', 'payload', 'tracker_data', 'created_at',
    ])
        ->and($data['uuid'])->toBe('order_public')
        ->and($data['tracking'])->toBe('FLB1234567890SG')
        ->and($data['status'])->toBe('started')
        ->and($data['has_driver_assigned'])->toBeTrue()
        ->and($data['tracking_number'])->toBe(['uuid' => 'track_public', 'tracking_number' => 'FLB1234567890SG'])
        ->and($data['created_at']->toDateTimeString())->toBe('2026-10-01 09:00:00');

    expect($data['tracking_statuses'])->toHaveCount(1)
        ->and(array_keys($data['tracking_statuses'][0]))->toBe(['uuid', 'status', 'details', 'created_at'])
        ->and($data['tracking_statuses'][0]['uuid'])->toBe('status_public')
        ->and($data['tracking_statuses'][0]['status'])->toBe('Order Created')
        ->and($data['tracking_statuses'][0]['details'])->toBe('New order created.');

    // Stops are coordinates only: the map routes between them, nothing names them.
    $payload = $data['payload'];
    expect(array_keys($payload))->toBe(['uuid', 'pickup', 'dropoff', 'waypoints', 'entities'])
        ->and($payload['uuid'])->toBe('payload_public')
        ->and(array_keys($payload['pickup']))->toBe(['uuid', 'location'])
        ->and($payload['pickup']['uuid'])->toBe('place_pickup')
        ->and($payload['pickup']['location']->getLat())->toBe(1.30)
        ->and($payload['dropoff']['location']->getLng())->toBe(103.85)
        ->and($payload['waypoints'])->toBe([])
        ->and($payload['entities'])->toBe([[
            'uuid'        => 'entity_public',
            'name'        => 'Parcel',
            'description' => 'A box',
            'tracking'    => 'FLB9999999999SG',
            'price'       => '1500',
            'currency'    => 'SGD',
            'photo_url'   => 'https://example.test/parcel.png',
        ]]);

    expect($data['tracker_data'])->toBe([
        'driver'      => ['location' => ['type' => 'Point', 'coordinates' => [103.82, 1.32]]],
        'progress'    => ['percentage' => 40, 'completed_stops' => 1],
        'eta'         => ['active_stop_seconds' => 600, 'completion_at' => '2026-10-01T09:30:00.000Z'],
        'active_stop' => ['address' => '2 Recipient Road'],
        'next_stop'   => null,
    ]);

    // Nothing internal reaches the page, at any depth.
    $json = json_encode($data);
    foreach (['order-internal-uuid', 'INT-0042', 'company-uuid', 'customer-uuid', 'facilitator-uuid', 'driver-uuid', 'rate-uuid', 'Gate code', 'secret', 'qr-data', 'barcode-data', 'Sender House', '1 Sender Road', '+6500000000', 'ENT-INT', 'abc', 'internal'] as $leak) {
        expect($json)->not->toContain($leak);
    }
});

test('public order tracking resource drops the driver position unless the order is under way', function (array $attributes) {
    $data = (new PublicOrderTracking(fleetopsPublicTrackingOrder($attributes)))->toArray(new Request());

    expect($data['tracker_data']['driver'])->toBe(['location' => null])
        ->and($data['tracker_data']['progress']['percentage'])->toBe(40);
})->with([
    'not started yet' => [['status' => 'dispatched', 'started' => false]],
    'completed'       => [['status' => 'completed', 'started' => true]],
    'canceled'        => [['status' => 'canceled', 'started' => true]],
    'cancelled'       => [['status' => 'CANCELLED', 'started' => true]],
]);

test('public order tracking resource reports in progress only for started unfinished orders', function () {
    expect(PublicOrderTracking::isInProgress(fleetopsPublicTrackingOrder()))->toBeTrue()
        ->and(PublicOrderTracking::isInProgress(fleetopsPublicTrackingOrder(['started' => false])))->toBeFalse()
        ->and(PublicOrderTracking::isInProgress(fleetopsPublicTrackingOrder(['status' => 'completed'])))->toBeFalse();
});

test('public order tracking resource tolerates missing relations and tracker data', function () {
    $order = new Order();
    $order->setRawAttributes(['public_id' => 'order_bare', 'status' => 'created'], true);
    $order->setRelation('trackingNumber', null);
    $order->setRelation('trackingStatuses', collect());
    $order->setRelation('payload', null);

    $data = (new PublicOrderTracking($order))->toArray(new Request());

    expect($data['uuid'])->toBe('order_bare')
        ->and($data['tracking'])->toBeNull()
        ->and($data['has_driver_assigned'])->toBeFalse()
        ->and($data['tracking_number'])->toBeNull()
        ->and($data['tracking_statuses'])->toBe([])
        ->and($data['payload'])->toBeNull()
        ->and($data['tracker_data'])->toBe([
            'driver'      => ['location' => null],
            'progress'    => ['percentage' => null, 'completed_stops' => null],
            'eta'         => ['active_stop_seconds' => null, 'completion_at' => null],
            'active_stop' => null,
            'next_stop'   => null,
        ]);

    // A payload with stops removed still serializes, leaving the gaps empty.
    $order->setRelation('payload', (object) [
        'public_id' => 'payload_bare',
        'pickup'    => null,
        'dropoff'   => null,
        'waypoints' => collect([(object) ['public_id' => 'place_waypoint', 'location' => new Point(1.4, 103.9)]]),
        'entities'  => [],
    ]);
    $payload = (new PublicOrderTracking($order))->toArray(new Request())['payload'];

    expect($payload['pickup'])->toBeNull()
        ->and($payload['dropoff'])->toBeNull()
        ->and($payload['waypoints'][0]['uuid'])->toBe('place_waypoint')
        ->and($payload['waypoints'][0]['location']->getLat())->toBe(1.4)
        ->and($payload['entities'])->toBe([]);
});

test('public order tracking resource is never wrapped', function () {
    expect(PublicOrderTracking::$wrap)->toBeNull();
});
