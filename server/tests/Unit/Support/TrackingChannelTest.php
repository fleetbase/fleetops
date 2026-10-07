<?php

use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Vendor;
use Fleetbase\FleetOps\Models\Waypoint;
use Fleetbase\FleetOps\Support\TrackingChannel;
use Fleetbase\FleetOps\Support\TrackingScope;
use Fleetbase\Support\SocketCluster\SocketToken;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;

/**
 * Covers the public tracking channel: the per-customer TrackingScope, the opaque channel id
 * (deterministic, keyed, distinct per customer), and the scoped tracking token.
 */
const TRACKING_CHANNEL_KEY = 'fleetops-tracking-channel-test-key-0123456789';

function trackingChannelModel(string $class, array $attributes): EloquentModel
{
    $model = (new ReflectionClass($class))->newInstanceWithoutConstructor();
    $model->setRawAttributes($attributes, true);

    return $model;
}

function trackingChannelOrder(array $attributes = []): Order
{
    return trackingChannelModel(Order::class, array_merge([
        'uuid'          => 'order-1111',
        'company_uuid'  => 'company-1111',
        'customer_type' => 'Fleetbase\\FleetOps\\Models\\Contact',
        'customer_uuid' => 'contact-1111',
        'payload_uuid'  => 'payload-1111',
    ], $attributes));
}

function trackingChannelWaypoint(?string $customerUuid, string $customerType = 'Fleetbase\\FleetOps\\Models\\Contact'): Waypoint
{
    return trackingChannelModel(Waypoint::class, ['uuid' => 'waypoint-' . ($customerUuid ?? 'none'), 'customer_uuid' => $customerUuid, 'customer_type' => $customerUuid ? $customerType : null]);
}

/**
 * Independent base32 (RFC 4648, lowercase, unpadded) to check the channel id against.
 */
function trackingChannelBase32(string $bytes): string
{
    $alphabet = 'abcdefghijklmnopqrstuvwxyz234567';
    $buffer   = 0;
    $bits     = 0;
    $out      = '';
    foreach (unpack('C*', $bytes) as $byte) {
        $buffer = ($buffer << 8) | $byte;
        $bits += 8;
        while ($bits >= 5) {
            $bits -= 5;
            $out .= $alphabet[($buffer >> $bits) & 31];
        }
    }
    if ($bits > 0) {
        $out .= $alphabet[($buffer << (5 - $bits)) & 31];
    }

    return $out;
}

function trackingChannelEnable(?string $key = TRACKING_CHANNEL_KEY): void
{
    config(['broadcasting.connections.socketcluster.auth_key' => $key]);
}

afterEach(function () {
    trackingChannelEnable(null);
});

test('a tracking scope identifies one customer of one order', function () {
    $order = trackingChannelOrder();
    $scope = TrackingScope::forOrder($order);

    expect($scope->order_uuid)->toBe('order-1111')
        ->and($scope->customer_type)->toBe('Fleetbase\\FleetOps\\Models\\Contact')
        ->and($scope->customer_uuid)->toBe('contact-1111')
        ->and($scope->company_uuid)->toBe('company-1111')
        ->and($scope->key())->toBe('order-1111:Fleetbase\\FleetOps\\Models\\Contact:contact-1111')
        ->and($scope->toArray())->toBe(['order_uuid' => 'order-1111', 'customer_type' => 'Fleetbase\\FleetOps\\Models\\Contact', 'customer_uuid' => 'contact-1111'])
        ->and(json_encode($scope))->toBe(json_encode($scope->toArray()))
        ->and($scope->order())->toBe($order)
        ->and($scope->isOrderCustomer($order))->toBeTrue();

    // A leading backslash on a stored morph type does not change the scope.
    expect((new TrackingScope('order-1111', '\\Fleetbase\\FleetOps\\Models\\Contact', 'contact-1111'))->key())->toBe($scope->key())
        ->and($scope->is('\\Fleetbase\\FleetOps\\Models\\Contact', 'contact-1111'))->toBeTrue()
        ->and($scope->is('Fleetbase\\FleetOps\\Models\\Vendor', 'contact-1111'))->toBeFalse()
        ->and($scope->is('Fleetbase\\FleetOps\\Models\\Contact', null))->toBeFalse();

    // An order without a customer has no order scope.
    expect(TrackingScope::forOrder(trackingChannelOrder(['customer_uuid' => null])))->toBeNull();

    expect(fn () => new TrackingScope('order-1111', '', 'contact-1111'))->toThrow(InvalidArgumentException::class);
});

test('each stop belongs to its waypoint customer, else to the order customer', function () {
    $order      = trackingChannelOrder();
    $own        = trackingChannelWaypoint(null);
    $neighbour  = trackingChannelWaypoint('contact-2222');
    $vendorStop = trackingChannelWaypoint('vendor-3333', 'Fleetbase\\FleetOps\\Models\\Vendor');

    $orderScope     = TrackingScope::forOrder($order);
    $neighbourScope = TrackingScope::forStop($order, $neighbour);

    expect(TrackingScope::forStop($order, $own)->key())->toBe($orderScope->key())
        ->and(TrackingScope::forStop($order)->key())->toBe($orderScope->key())
        ->and($neighbourScope->customer_uuid)->toBe('contact-2222')
        ->and($orderScope->ownsStop($order, $own))->toBeTrue()
        ->and($orderScope->ownsStop($order, null))->toBeTrue()
        ->and($orderScope->ownsStop($order, $neighbour))->toBeFalse()
        ->and($neighbourScope->ownsStop($order, $neighbour))->toBeTrue()
        ->and($neighbourScope->ownsStop($order, $own))->toBeFalse()
        ->and($neighbourScope->isOrderCustomer($order))->toBeFalse();

    $scopes = TrackingScope::allForOrder($order, [$own, $neighbour, $vendorStop, $neighbour, 'not a waypoint']);
    expect(array_map(fn (TrackingScope $scope) => $scope->customer_uuid, $scopes))->toBe(['contact-1111', 'contact-2222', 'vendor-3333'])
        ->and($scopes[2]->customer_type)->toBe(Vendor::class);

    // An explicit customer model.
    $contact = trackingChannelModel(Contact::class, ['uuid' => 'contact-2222']);
    expect(TrackingScope::forCustomer($order, $contact)->key())->toBe($neighbourScope->key());
});

test('a tracking scope loads its order and its waypoints when not given', function () {
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
    $resolver   = new ConnectionResolver(['default' => $connection, 'mysql' => $connection]);
    $resolver->setDefaultConnection('mysql');
    EloquentModel::setConnectionResolver($resolver);
    $schema = $connection->getSchemaBuilder();
    foreach (['orders', 'waypoints'] as $table) {
        $schema->create($table, function ($blueprint) {
            $blueprint->increments('id');
            foreach (['uuid', 'public_id', 'company_uuid', 'payload_uuid', 'place_uuid', 'customer_uuid', 'customer_type'] as $column) {
                $blueprint->string($column)->nullable();
            }
            $blueprint->timestamps();
            $blueprint->timestamp('deleted_at')->nullable();
        });
    }
    $connection->table('orders')->insert(['uuid' => 'order-1111', 'company_uuid' => 'company-1111', 'customer_uuid' => 'contact-1111', 'customer_type' => 'Fleetbase\\FleetOps\\Models\\Contact', 'payload_uuid' => 'payload-1111']);
    $connection->table('waypoints')->insert([
        ['uuid' => 'waypoint-1', 'payload_uuid' => 'payload-1111', 'customer_uuid' => 'contact-2222', 'customer_type' => 'Fleetbase\\FleetOps\\Models\\Contact'],
        ['uuid' => 'waypoint-2', 'payload_uuid' => 'payload-1111', 'customer_uuid' => null, 'customer_type' => null],
        ['uuid' => 'waypoint-3', 'payload_uuid' => 'payload-other', 'customer_uuid' => 'contact-9999', 'customer_type' => 'Fleetbase\\FleetOps\\Models\\Contact'],
    ]);

    $scope = new TrackingScope('order-1111', 'Fleetbase\\FleetOps\\Models\\Contact', 'contact-1111');
    expect($scope->company_uuid)->toBeNull()
        ->and($scope->order()?->uuid)->toBe('order-1111');

    $order = $scope->order();
    expect(array_map(fn (TrackingScope $scope) => $scope->customer_uuid, TrackingScope::allForOrder($order)))->toBe(['contact-1111', 'contact-2222'])
        ->and(TrackingScope::allForOrder(trackingChannelOrder(['payload_uuid' => null])))->toHaveCount(1);

    // A scope given another order's model ignores it.
    expect((new TrackingScope('order-1111', 'Fleetbase\\FleetOps\\Models\\Contact', 'contact-1111', trackingChannelOrder(['uuid' => 'order-other'])))->company_uuid)->toBeNull();
});

test('the channel id is an unguessable keyed hmac of the scope, distinct per customer', function () {
    trackingChannelEnable();
    $order     = trackingChannelOrder();
    $scope     = TrackingScope::forOrder($order);
    $neighbour = TrackingScope::forStop($order, trackingChannelWaypoint('contact-2222'));

    $trackingKey = hash_hmac('sha256', 'fleetbase-socket:tracking', TRACKING_CHANNEL_KEY);
    $expected    = substr(trackingChannelBase32(hash_hmac('sha256', 'order-1111:Fleetbase\\FleetOps\\Models\\Contact:contact-1111', $trackingKey, true)), 0, 26);

    expect(TrackingChannel::opaqueId($scope))->toBe($expected)
        ->and(TrackingChannel::opaqueId($scope))->toMatch('/^[a-z2-7]{26}$/')
        ->and(TrackingChannel::name($scope))->toBe('tracking.' . $expected)
        ->and(TrackingChannel::name(TrackingScope::forOrder(trackingChannelOrder())))->toBe('tracking.' . $expected)
        ->and(TrackingChannel::name($neighbour))->not->toBe(TrackingChannel::name($scope))
        ->and(trackingChannelBase32('foobar'))->toBe('mzxw6ytboi');

    // Another key yields other channels: the id cannot be computed without the key.
    trackingChannelEnable('another-fleetops-tracking-channel-key-987654321');
    expect(TrackingChannel::opaqueId($scope))->not->toBe($expected);
});

test('channels and tokens need socket authentication to be configured', function () {
    trackingChannelEnable(null);
    $scope = TrackingScope::forOrder(trackingChannelOrder());

    expect(fn () => TrackingChannel::name($scope))->toThrow(RuntimeException::class)
        ->and(fn () => TrackingChannel::token($scope))->toThrow(RuntimeException::class);
});

test('a tracking token may subscribe to its own channel only', function () {
    trackingChannelEnable();
    $scope = TrackingScope::forOrder(trackingChannelOrder());

    $minted    = TrackingChannel::token($scope);
    $principal = SocketToken::verify($minted['token']);

    expect($minted['expires_in'])->toBe(1800)
        ->and($minted['expires_at'])->toBeString()
        ->and($principal->kind)->toBe('tracking')
        ->and($principal->sub)->toBe(TrackingChannel::opaqueId($scope))
        ->and($principal->scp)->toBe([TrackingChannel::name($scope)])
        ->and($principal->cid)->toBe('company-1111')
        ->and($principal->adm)->toBeFalse();

    // A scope without its order model still mints a token scoped to its channel.
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
    $resolver   = new ConnectionResolver(['default' => $connection, 'mysql' => $connection]);
    $resolver->setDefaultConnection('mysql');
    EloquentModel::setConnectionResolver($resolver);
    $connection->getSchemaBuilder()->create('orders', function ($blueprint) {
        $blueprint->increments('id');
        $blueprint->string('uuid')->nullable();
        $blueprint->string('company_uuid')->nullable();
        $blueprint->timestamps();
        $blueprint->timestamp('deleted_at')->nullable();
    });
    $connection->table('orders')->insert(['uuid' => 'order-2222', 'company_uuid' => 'company-2222']);

    $scope  = new TrackingScope('order-2222', Contact::class, 'contact-1111');
    $loaded = SocketToken::verify(TrackingChannel::token($scope)['token']);
    expect($loaded->cid)->toBe('company-2222')
        ->and($loaded->scp)->toBe([TrackingChannel::name($scope)]);
});
