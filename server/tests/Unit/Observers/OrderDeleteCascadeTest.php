<?php

use Fleetbase\FleetOps\Models\Entity;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Waypoint;
use Fleetbase\FleetOps\Observers\OrderObserver;
use Fleetbase\Tests\Support\TrackingPageDatabase;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class OrderDeleteCascadeBulkProbe extends Order
{
    protected $table = 'orders';

    public static array $deleted = [];

    public function delete()
    {
        static::$deleted[] = $this->uuid;

        return parent::delete();
    }
}

beforeEach(function () {
    Cache::swap(new Repository(new ArrayStore()));
    session(['company' => 'co-1']);
    OrderDeleteCascadeBulkProbe::$deleted = [];
});

afterEach(function () {
    Carbon::setTestNow();
    EloquentModel::unsetConnectionResolver();
});

function orderDeleteCascadeFixture(): SQLiteConnection
{
    $db = TrackingPageDatabase::boot();

    $rows = [
        'orders' => [
            ['uuid' => 'order-1', 'public_id' => 'order_one', 'company_uuid' => 'co-1', 'payload_uuid' => 'payload-1', 'tracking_number_uuid' => 'tn-order'],
            ['uuid' => 'order-shared-a', 'public_id' => 'order_shared_a', 'company_uuid' => 'co-1', 'payload_uuid' => 'payload-shared', 'tracking_number_uuid' => 'tn-shared-a'],
            ['uuid' => 'order-shared-b', 'public_id' => 'order_shared_b', 'company_uuid' => 'co-1', 'payload_uuid' => 'payload-shared'],
            ['uuid' => 'order-bare', 'public_id' => 'order_bare', 'company_uuid' => 'co-1'],
            ['uuid' => 'order-other', 'public_id' => 'order_other', 'company_uuid' => 'co-2', 'payload_uuid' => 'payload-other'],
        ],
        'payloads' => [
            ['uuid' => 'payload-1', 'dropoff_tracking_number_uuid' => 'tn-dropoff'],
            ['uuid' => 'payload-shared'],
            ['uuid' => 'payload-other'],
        ],
        'entities' => [
            ['uuid' => 'entity-1', 'payload_uuid' => 'payload-1', 'tracking_number_uuid' => 'tn-entity'],
            ['uuid' => 'entity-owned', 'payload_uuid' => 'payload-1'],
            ['uuid' => 'entity-gone', 'payload_uuid' => 'payload-1', 'deleted_at' => '2026-01-01 00:00:00'],
            ['uuid' => 'entity-shared', 'payload_uuid' => 'payload-shared'],
        ],
        'waypoints' => [
            ['uuid' => 'wp-1', 'payload_uuid' => 'payload-1', 'tracking_number_uuid' => 'tn-waypoint'],
        ],
        'tracking_numbers' => [
            ['uuid' => 'tn-order', 'owner_uuid' => 'order-1', 'owner_type' => Order::class],
            ['uuid' => 'tn-entity', 'owner_uuid' => 'entity-1', 'owner_type' => Entity::class],
            ['uuid' => 'tn-entity-owned', 'owner_uuid' => 'entity-owned', 'owner_type' => Entity::class],
            ['uuid' => 'tn-waypoint', 'owner_uuid' => 'wp-1', 'owner_type' => Waypoint::class],
            ['uuid' => 'tn-dropoff', 'owner_uuid' => 'place-dropoff'],
            ['uuid' => 'tn-shared-a', 'owner_uuid' => 'order-shared-a', 'owner_type' => Order::class],
            ['uuid' => 'tn-unrelated', 'owner_uuid' => 'order-other', 'owner_type' => Order::class],
        ],
        'tracking_statuses' => [
            ['uuid' => 'ts-order', 'tracking_number_uuid' => 'tn-order'],
            ['uuid' => 'ts-entity', 'tracking_number_uuid' => 'tn-entity-owned'],
            ['uuid' => 'ts-shared-a', 'tracking_number_uuid' => 'tn-shared-a'],
            ['uuid' => 'ts-unrelated', 'tracking_number_uuid' => 'tn-unrelated'],
        ],
    ];

    foreach ($rows as $table => $records) {
        foreach ($records as $record) {
            TrackingPageDatabase::insert($db, $table, $record);
        }
    }

    return $db;
}

function orderDeleteCascadeDeletedAt(SQLiteConnection $db, string $table, string $uuid): ?string
{
    return $db->table($table)->where('uuid', $uuid)->value('deleted_at');
}

function orderDeleteCascadeSoftDelete(SQLiteConnection $db, string $uuid): Order
{
    $db->table('orders')->where('uuid', $uuid)->update(['deleted_at' => now()->toDateTimeString()]);

    return Order::withTrashed()->where('uuid', $uuid)->first();
}

test('deleting an order soft-deletes its payload tree and tracking data with the order timestamp', function () {
    Carbon::setTestNow('2026-10-09 12:00:00');
    $db    = orderDeleteCascadeFixture();
    $order = orderDeleteCascadeSoftDelete($db, 'order-1');

    (new OrderObserver())->deleted($order);

    $stamp = '2026-10-09 12:00:00';
    foreach ([
        ['payloads', 'payload-1'],
        ['entities', 'entity-1'],
        ['entities', 'entity-owned'],
        ['waypoints', 'wp-1'],
        ['tracking_numbers', 'tn-order'],
        ['tracking_numbers', 'tn-entity'],
        ['tracking_numbers', 'tn-entity-owned'],
        ['tracking_numbers', 'tn-waypoint'],
        ['tracking_numbers', 'tn-dropoff'],
        ['tracking_statuses', 'ts-order'],
        ['tracking_statuses', 'ts-entity'],
    ] as [$table, $uuid]) {
        expect(orderDeleteCascadeDeletedAt($db, $table, $uuid))->toBe($stamp, "{$table}.{$uuid}");
    }

    // Rows outside the order, and children deleted earlier, keep their state.
    expect(orderDeleteCascadeDeletedAt($db, 'entities', 'entity-gone'))->toBe('2026-01-01 00:00:00')
        ->and(orderDeleteCascadeDeletedAt($db, 'tracking_numbers', 'tn-unrelated'))->toBeNull()
        ->and(orderDeleteCascadeDeletedAt($db, 'tracking_statuses', 'ts-unrelated'))->toBeNull()
        ->and(orderDeleteCascadeDeletedAt($db, 'payloads', 'payload-other'))->toBeNull()
        ->and(Cache::get('live:co-1:orders:version'))->toBe(1);
});

test('restoring an order brings back only the children deleted with it', function () {
    Carbon::setTestNow('2026-10-09 12:00:00');
    $db       = orderDeleteCascadeFixture();
    $order    = orderDeleteCascadeSoftDelete($db, 'order-1');
    $observer = new OrderObserver();
    $observer->deleted($order);

    // An entity deleted on its own after the order keeps its own timestamp.
    $db->table('entities')->insert(['uuid' => 'entity-later', 'payload_uuid' => 'payload-1', 'deleted_at' => '2026-10-10 08:00:00']);

    $observer->restoring($order);
    $db->table('orders')->where('uuid', 'order-1')->update(['deleted_at' => null]);
    $order->deleted_at = null;
    $observer->restored($order);

    foreach ([
        ['payloads', 'payload-1'],
        ['entities', 'entity-1'],
        ['entities', 'entity-owned'],
        ['waypoints', 'wp-1'],
        ['tracking_numbers', 'tn-order'],
        ['tracking_numbers', 'tn-entity'],
        ['tracking_numbers', 'tn-entity-owned'],
        ['tracking_numbers', 'tn-waypoint'],
        ['tracking_numbers', 'tn-dropoff'],
        ['tracking_statuses', 'ts-order'],
        ['tracking_statuses', 'ts-entity'],
    ] as [$table, $uuid]) {
        expect(orderDeleteCascadeDeletedAt($db, $table, $uuid))->toBeNull("{$table}.{$uuid}");
    }

    expect(orderDeleteCascadeDeletedAt($db, 'entities', 'entity-gone'))->toBe('2026-01-01 00:00:00')
        ->and(orderDeleteCascadeDeletedAt($db, 'entities', 'entity-later'))->toBe('2026-10-10 08:00:00');

    // A second "restored" without a matching "restoring" has nothing to restore.
    $db->table('tracking_numbers')->where('uuid', 'tn-order')->update(['deleted_at' => '2026-10-09 12:00:00']);
    $observer->restored($order);
    $observer->restoring($order);

    expect(orderDeleteCascadeDeletedAt($db, 'tracking_numbers', 'tn-order'))->toBe('2026-10-09 12:00:00');
});

test('a payload shared with another live order is left alone', function () {
    Carbon::setTestNow('2026-10-09 12:00:00');
    $db    = orderDeleteCascadeFixture();
    $order = orderDeleteCascadeSoftDelete($db, 'order-shared-a');

    (new OrderObserver())->deleted($order);

    expect(orderDeleteCascadeDeletedAt($db, 'payloads', 'payload-shared'))->toBeNull()
        ->and(orderDeleteCascadeDeletedAt($db, 'entities', 'entity-shared'))->toBeNull()
        ->and(orderDeleteCascadeDeletedAt($db, 'tracking_numbers', 'tn-shared-a'))->toBe('2026-10-09 12:00:00')
        ->and(orderDeleteCascadeDeletedAt($db, 'tracking_statuses', 'ts-shared-a'))->toBe('2026-10-09 12:00:00');

    // An order without a payload or deletion timestamp (force delete) still cascades cleanly.
    $bare = Order::where('uuid', 'order-bare')->first();
    (new OrderObserver())->deleted($bare);

    expect(orderDeleteCascadeDeletedAt($db, 'payloads', 'payload-other'))->toBeNull();
});

test('bulk removal deletes each matched order of the session company one by one', function () {
    $db = orderDeleteCascadeFixture();

    $count = (new OrderDeleteCascadeBulkProbe())->bulkRemove(['order-1', 'order_bare', 'order-other']);

    expect($count)->toBe(2)
        ->and(OrderDeleteCascadeBulkProbe::$deleted)->toBe(['order-1', 'order-bare'])
        ->and(orderDeleteCascadeDeletedAt($db, 'orders', 'order-1'))->not->toBeNull()
        ->and(orderDeleteCascadeDeletedAt($db, 'orders', 'order-other'))->toBeNull();

    // Without a session company the lookup is not tenant constrained, like core-api's.
    session(['company' => null]);
    OrderDeleteCascadeBulkProbe::$deleted = [];

    expect((new OrderDeleteCascadeBulkProbe())->bulkRemove(['order-other']))->toBe(1)
        ->and(OrderDeleteCascadeBulkProbe::$deleted)->toBe(['order-other']);
});
