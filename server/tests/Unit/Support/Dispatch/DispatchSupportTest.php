<?php

if (!function_exists('Fleetbase\Traits\config')) {
    eval('namespace Fleetbase\Traits; function config($key = null, $default = null) { return $key === "api.cache.enabled" ? false : $default; }');
}

use Fleetbase\FleetOps\Events\ManifestUpdated;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Manifest;
use Fleetbase\FleetOps\Models\ManifestStop;
use Fleetbase\FleetOps\Models\OrchestrationRun;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Payload;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Notifications\ManifestPublished;
use Fleetbase\FleetOps\Orchestration\Support\UnassignedReasons;
use Fleetbase\FleetOps\Support\Dispatch\DispatchDate;
use Fleetbase\FleetOps\Support\Dispatch\InsertionEstimator;
use Fleetbase\FleetOps\Support\Dispatch\ManifestPublisher;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Illuminate\Support\Carbon;
use NotificationChannels\Apn\ApnChannel;
use NotificationChannels\Fcm\FcmChannel;

/**
 * The Dispatch workspace's supporting pieces on their own: the reasons map,
 * strict dates, the broadcast and push a driver receives, the plan run model,
 * the insertion estimate's edge cases and the publisher's refusals.
 */
function dispatchSupportManifest(array $attributes = [], ?Driver $driver = null): Manifest
{
    $manifest = new Manifest();
    $manifest->setRawAttributes(array_merge([
        'uuid'           => 'manifest-uuid',
        'public_id'      => 'manifest_public',
        'company_uuid'   => 'company-1',
        'status'         => 'published',
        'scheduled_date' => '2026-11-28',
        'stop_count'     => 3,
    ], $attributes), true);
    $manifest->setRelation('driver', $driver);
    $manifest->setRelation('vehicle', null);

    return $manifest;
}

function dispatchSupportDriver(string $key = 'a', ?Point $location = null): Driver
{
    $driver = new Driver();
    $driver->setRawAttributes(['uuid' => "driver-{$key}", 'public_id' => "driver_{$key}"], true);
    $driver->setRelation('user', (object) ['name' => 'Driver ' . strtoupper($key)]);
    if ($location) {
        $driver->setRawAttributes(array_merge($driver->getAttributes(), ['location' => $location]), true);
    }

    return $driver;
}

function dispatchSupportStop(string $status, ?Point $location, int $sequence): ManifestStop
{
    $place = new Place();
    $place->setRawAttributes(['uuid' => 'place-' . $sequence, 'location' => $location], true);

    $stop = new ManifestStop();
    $stop->setRawAttributes(['uuid' => 'stop-' . $sequence, 'status' => $status, 'sequence' => $sequence], true);
    $stop->setRelation('place', $place);

    return $stop;
}

function dispatchSupportOrder(?Point $dropoff): Order
{
    $place = new Place();
    $place->setRawAttributes(['uuid' => 'place-order', 'location' => $dropoff], true);
    $payload = new Payload();
    $payload->setRawAttributes(['uuid' => 'payload-order'], true);
    $payload->setRelation('dropoff', $dropoff ? $place : null);
    $payload->setRelation('waypoints', collect());

    // Relations are set up front; nothing here has a database to load from.
    $order = new class extends Order {
        public function loadMissing($relations)
        {
            return $this;
        }
    };
    $order->setRawAttributes(['uuid' => 'order-uuid', 'public_id' => 'order_late'], true);
    $order->setRelation('payload', $payload);

    return $order;
}

test('unassigned reasons key every unplaced order by id with a code and a message', function () {
    $result = [
        'unassigned' => ['order_a', 'order_b', 'order_c', 'order_d', '', ['not', 'an', 'id']],
        'summary'    => [
            'engine'             => 'capacity',
            'unassigned_reasons' => [
                ['order_id' => 'order_a', 'reason' => 'capacity_exceeded'],
                ['order_id' => 'order_b', 'reason' => 'some_new_code'],
                'not an entry',
                ['reason' => 'no order id'],
            ],
            'invalid'            => [['order_id' => 'order_c', 'reason' => 'Order dropoff stop is missing valid coordinates.']],
        ],
    ];

    expect(UnassignedReasons::fromResult($result))->toBe([
        'order_a' => ['reason' => 'capacity_exceeded', 'message' => UnassignedReasons::MESSAGES['capacity_exceeded']],
        'order_b' => ['reason' => 'some_new_code', 'message' => UnassignedReasons::MESSAGES['not_placed']],
        'order_c' => ['reason' => 'invalid_order', 'message' => 'Order dropoff stop is missing valid coordinates.'],
        // Not explained by the engine: the capacity engine's usual cause.
        'order_d' => ['reason' => 'no_available_vehicle', 'message' => UnassignedReasons::MESSAGES['no_available_vehicle']],
    ])
        ->and(UnassignedReasons::fromResult(['unassigned' => ['order_x']], 'vroom')['order_x']['reason'])->toBe('not_feasible')
        ->and(UnassignedReasons::fromResult(['unassigned' => ['order_x']], 'someone_elses')['order_x']['reason'])->toBe('not_placed')
        ->and(UnassignedReasons::fromResult(['summary' => 'odd'], null))->toBe([])
        ->and(UnassignedReasons::describe(null))->toBe(['reason' => 'not_placed', 'message' => UnassignedReasons::MESSAGES['not_placed']])
        ->and(UnassignedReasons::all(['order_y'], 'no_available_driver'))->toBe(['order_y' => ['reason' => 'no_available_driver', 'message' => UnassignedReasons::MESSAGES['no_available_driver']]]);
});

test('dispatch dates are strict days and moments are read leniently', function () {
    expect(DispatchDate::normalize('2026-11-28'))->toBe('2026-11-28')
        ->and(DispatchDate::normalize('2026-02-30'))->toBeNull()
        ->and(DispatchDate::normalize('tomorrow'))->toBeNull()
        ->and(DispatchDate::normalize(20261128))->toBeNull()
        ->and(DispatchDate::moment('2026-11-28T05:00:00+08:00')?->toIso8601String())->toBe('2026-11-28T05:00:00+08:00')
        ->and(DispatchDate::moment('not a time at all'))->toBeNull()
        ->and(DispatchDate::moment('  '))->toBeNull()
        ->and(DispatchDate::moment(null))->toBeNull();
});

test('manifest updated broadcasts to the route driver and the company', function () {
    Carbon::setTestNow(Carbon::parse('2026-11-27 18:00:00'));
    $event = new ManifestUpdated(dispatchSupportManifest([], dispatchSupportDriver()), ManifestUpdated::STOP_ADDED, ['order_late']);

    expect($event->broadcastAs())->toBe('manifest.updated')
        ->and(array_map(fn ($channel) => $channel->name, $event->broadcastOn()))->toBe(['driver.driver_a', 'driver.driver-a', 'company.company-1'])
        ->and($event->data)->toBe([
            'id'             => 'manifest_public',
            'driver'         => 'driver_a',
            'status'         => 'published',
            'scheduled_date' => '2026-11-28',
            'change'         => 'stop_added',
            'orders'         => ['order_late'],
            'stop_count'     => 3,
        ])
        ->and($event->broadcastWith())->toMatchArray(['event' => 'manifest.updated', 'created_at' => '2026-11-27 18:00:00', 'data' => $event->data]);

    // A driverless route only reaches the company channel.
    $driverless = new ManifestUpdated(dispatchSupportManifest(['scheduled_date' => null]), ManifestUpdated::RESEQUENCED);
    expect($driverless->channelNames)->toBe(['company.company-1'])
        ->and($driverless->data['driver'])->toBeNull()
        ->and($driverless->data['scheduled_date'])->toBeNull();

    Carbon::setTestNow();
});

test('the published route push names the day and the stop count', function () {
    $notification = new ManifestPublished(dispatchSupportManifest());

    expect($notification->title)->toBe('Your route for 2026-11-28 is ready')
        ->and($notification->message)->toBe('3 stops.')
        ->and($notification->data)->toBe(['type' => 'manifest_published', 'id' => 'manifest_public', 'scheduled_date' => '2026-11-28'])
        ->and($notification->via(null))->toBe([FcmChannel::class, ApnChannel::class, Fleetbase\Push\PushAppChannel::class])
        ->and($notification->toArray())->toMatchArray(['event' => 'manifest.published_notification', 'data' => $notification->data])
        ->and((new ManifestPublished(dispatchSupportManifest(['scheduled_date' => null, 'stop_count' => 1])))->title)->toBe('Your route is ready')
        ->and((new ManifestPublished(dispatchSupportManifest(['stop_count' => 1])))->message)->toBe('1 stop.');

    // The push builders reach for the FCM and APN clients, which the harness does not configure
    expect(fn () => $notification->toFcm(null))->toThrow(TypeError::class)
        ->and(fn () => $notification->toApn(null))->toThrow(Error::class);
});

test('a plan run stores its scope and reasons as json', function () {
    $run = new OrchestrationRun();

    expect($run->getTable())->toBe('orchestration_runs')
        ->and($run->getFillable())->toContain('unassigned_reasons', 'order_ids', 'scheduled_date', 'committed_at')
        ->and($run->getCasts())->toMatchArray(['scheduled_date' => 'date', 'committed_at' => 'datetime']);
});

test('the insertion estimate skips unlocated stops and offers each driver once', function () {
    $estimator = new InsertionEstimator();

    expect($estimator->bestFit(dispatchSupportOrder(null), collect([dispatchSupportManifest()])))->toBe([]);

    // No known start, a finished stop, an unlocated stop, then two located ones.
    $route = dispatchSupportManifest(['public_id' => 'manifest_one'], dispatchSupportDriver('a', new Point(0, 0)));
    $route->setRelation('stops', collect([
        dispatchSupportStop('completed', new Point(1.300, 103.800), 1),
        dispatchSupportStop('pending', null, 2),
        dispatchSupportStop('pending', new Point(1.300, 103.820), 3),
        dispatchSupportStop('pending', new Point(1.300, 103.840), 4),
    ]));
    $fit = $estimator->bestPosition($route, new Point(1.300, 103.830));

    // Between the two located stops; the unlocated one keeps its place.
    expect($fit['position'])->toBe(4)
        ->and($fit['distance'])->toBeGreaterThanOrEqual(0.0);

    // The same driver's second, costlier route is not offered.
    $farther = dispatchSupportManifest(['public_id' => 'manifest_two'], dispatchSupportDriver('a'));
    $farther->setRelation('stops', collect([dispatchSupportStop('pending', new Point(1.500, 104.000), 1)]));
    $offers = $estimator->bestFit(dispatchSupportOrder(new Point(1.300, 103.830)), collect([$farther, $route]));

    expect(array_column($offers, 'manifest_id'))->toBe(['manifest_one']);

    // An empty route with no start costs nothing and appends.
    $empty = dispatchSupportManifest(['public_id' => 'manifest_empty']);
    $empty->setRelation('stops', collect());
    expect($estimator->bestPosition($empty, new Point(1.3, 103.8)))->toBe(['position' => 1, 'distance' => 0.0, 'duration' => 0.0]);
});

test('the publisher refuses anything but a draft and ignores bookings that do not match', function () {
    $publisher = new ManifestPublisher();

    $published = dispatchSupportManifest(['status' => 'published']);
    $unbooked  = dispatchSupportManifest(['status' => 'draft', 'publish_at' => null]);

    expect($publisher->schedule($published, Carbon::parse('2030-01-01')))->toBeFalse()
        ->and($publisher->publishIfDue($published, '2030-01-01T00:00:00+00:00'))->toBeFalse()
        ->and($publisher->publishIfDue($unbooked, '2030-01-01T00:00:00+00:00'))->toBeFalse();
});
