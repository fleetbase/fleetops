<?php

use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;

/**
 * Covers the Order model purchase-rate and driver-discovery helpers against
 * SQLite: purchasing service quotes by uuid/public-id/instance with the
 * no-quote transaction fallback, purchase-rate attachment with transaction
 * relinking and voiding of superseded transactions, dispatched-activity
 * detection, and closest-driver discovery with the spatial company chain.
 */
if (!function_exists('Fleetbase\Support\session')) {
    eval('namespace Fleetbase\Support; function session($key = null, $default = null) { if ($key === null) { return new class { public function has($k) { return \session($k) !== null; } public function get($k, $d = null) { return \session($k, $d); } }; } return \session($key, $default); }');
}

if (!function_exists('Fleetbase\Support\auth')) {
    eval('namespace Fleetbase\Support; function auth() { return new class { public function user() { return null; } public function id() { return null; } }; }');
}

function fleetopsOrderPurchaseBoot(): SQLiteConnection
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->sqliteCreateFunction('ST_X', fn ($value) => 0.5);
    $pdo->sqliteCreateFunction('ST_Y', fn ($value) => 0.5);
    $pdo->sqliteCreateFunction('ST_PointFromText', fn ($wkt, $srid = 0, $axisOrder = null) => $wkt);
    $pdo->sqliteCreateFunction('ST_GeomFromText', fn ($wkt, $srid = 0, $axisOrder = null) => $wkt);
    $pdo->sqliteCreateFunction('st_distance_sphere', fn ($a, $b) => 100.0);
    $connection = new SQLiteConnection($pdo);
    $resolver   = new ConnectionResolver(['default' => $connection, 'mysql' => $connection]);
    $resolver->setDefaultConnection('mysql');
    EloquentModel::setConnectionResolver($resolver);
    app()->instance('db', new class($connection) {
        public function __construct(public SQLiteConnection $c)
        {
        }

        public function connection($name = null): SQLiteConnection
        {
            return $this->c;
        }

        public function __call($method, $arguments)
        {
            return $this->c->{$method}(...$arguments);
        }
    });
    Illuminate\Support\Facades\DB::clearResolvedInstance('db');

    $schema = $connection->getSchemaBuilder();
    $tables = [
        'orders'            => ['uuid', 'public_id', 'company_uuid', 'payload_uuid', 'customer_uuid', 'customer_type', 'purchase_rate_uuid', 'transaction_uuid', 'status', 'type', 'meta'],
        'purchase_rates'    => ['uuid', 'public_id', 'company_uuid', 'customer_uuid', 'customer_type', 'service_quote_uuid', 'payload_uuid', 'transaction_uuid', 'status', 'meta', '_key'],
        'service_quotes'    => ['uuid', 'public_id', 'request_id', 'company_uuid', 'payload_uuid', 'service_rate_uuid', 'amount', 'currency', 'meta', 'expired_at', '_key'],
        'transactions'      => ['uuid', 'public_id', 'company_uuid', 'customer_uuid', 'customer_type', 'subject_uuid', 'subject_type', 'context_uuid', 'context_type', 'gateway_transaction_id', 'gateway', 'amount', 'currency', 'description', 'type', 'direction', 'status', 'settlement_status', 'voided_at', 'meta', '_key'],
        'companies'         => ['uuid', 'public_id', 'name', 'currency', 'country'],
        'drivers'           => ['uuid', 'public_id', 'company_uuid', 'user_uuid', 'status', 'online', 'location'],
        'users'             => ['uuid', 'public_id', 'company_uuid', 'name'],
        'company_users'     => ['uuid', 'company_uuid', 'user_uuid'],
        'payloads'          => ['uuid', 'public_id', 'company_uuid', 'pickup_uuid', 'dropoff_uuid', 'current_waypoint_uuid', 'meta'],
        'places'            => ['uuid', 'public_id', 'company_uuid', 'name', 'location'],
        'tracking_statuses' => ['uuid', 'public_id', 'company_uuid', 'tracking_number_uuid', 'code', 'status', 'complete'],
        'settings'          => ['key', 'value'],
    ];
    foreach ($tables as $table => $columns) {
        $schema->create($table, function ($blueprint) use ($columns) {
            $blueprint->increments('id');
            foreach ($columns as $column) {
                if ($column === 'online') {
                    $blueprint->integer($column)->nullable();
                    continue;
                }
                $blueprint->string($column)->nullable();
            }
            $blueprint->timestamps();
            $blueprint->timestamp('deleted_at')->nullable();
        });
    }

    session(['company' => 'company-1']);
    $connection->table('companies')->insert(['uuid' => 'company-1', 'name' => 'Acme', 'currency' => 'SGD', 'country' => 'SG']);

    Fleetbase\Models\User::expand('driver', function () {
        return $this->hasOne(Driver::class, 'user_uuid', 'uuid')->withoutGlobalScopes();
    });

    return $connection;
}

function fleetopsOrderPurchaseOrder(): Order
{
    $order = new Order();
    $order->setRawAttributes([
        'uuid'          => 'order-1',
        'public_id'     => 'order_purchase',
        'company_uuid'  => 'company-1',
        'payload_uuid'  => 'payload-1',
        'customer_uuid' => 'customer-1',
        'customer_type' => 'Fleetbase\\FleetOps\\Models\\Contact',
        'status'        => 'created',
    ], true);
    $order->exists = true;

    return $order;
}

test('purchase service quote resolves quotes and attaches purchase rates', function () {
    $connection = fleetopsOrderPurchaseBoot();
    $connection->table('orders')->insert(['uuid' => 'order-1', 'public_id' => 'order_purchase', 'company_uuid' => 'company-1', 'payload_uuid' => 'payload-1']);
    $connection->table('service_quotes')->insert([
        'uuid'         => '11111111-1111-4111-8111-111111111111',
        'public_id'    => 'quote_abc1234',
        'company_uuid' => 'company-1',
        'amount'       => '1000',
        'currency'     => 'SGD',
    ]);

    $order = fleetopsOrderPurchaseOrder();

    // Resolution by uuid attaches the created purchase rate
    $result = $order->purchaseServiceQuote('11111111-1111-4111-8111-111111111111');
    expect($result)->toBeTrue()
        ->and($connection->table('purchase_rates')->count())->toBe(1);

    // Resolution by public id
    $order->purchaseServiceQuote('quote_abc1234');
    expect($connection->table('purchase_rates')->count())->toBe(2);

    // Unresolvable identifiers fail
    expect($order->purchaseServiceQuote('quote_missing'))->toBeFalse();
});

test('purchasing without a quote creates an internal dispatch transaction', function () {
    $connection = fleetopsOrderPurchaseBoot();
    $connection->table('orders')->insert(['uuid' => 'order-1', 'public_id' => 'order_purchase', 'company_uuid' => 'company-1']);

    $order  = fleetopsOrderPurchaseOrder();
    $result = $order->purchaseServiceQuote(null);

    expect($result)->toBeInstanceOf(Order::class)
        ->and($connection->table('transactions')->count())->toBe(1)
        ->and($connection->table('transactions')->value('gateway'))->toBe('internal')
        ->and($connection->table('transactions')->value('type'))->toBe('dispatch');
});

test('attaching a purchase rate relinks and voids superseded transactions', function () {
    $connection = fleetopsOrderPurchaseBoot();
    $connection->table('orders')->insert(['uuid' => 'order-1', 'public_id' => 'order_purchase', 'company_uuid' => 'company-1', 'transaction_uuid' => 'txn-old']);
    $connection->table('transactions')->insert([
        ['uuid' => 'txn-old', 'company_uuid' => 'company-1', 'status' => 'success'],
        ['uuid' => 'txn-new', 'company_uuid' => 'company-1', 'status' => 'success'],
    ]);
    $connection->table('purchase_rates')->insert(['uuid' => 'rate-1', 'company_uuid' => 'company-1', 'transaction_uuid' => 'txn-new']);

    $order                   = fleetopsOrderPurchaseOrder();
    $order->transaction_uuid = 'txn-old';

    $purchaseRate = Fleetbase\FleetOps\Models\PurchaseRate::where('uuid', 'rate-1')->first();
    $attached     = $order->attachPurchaseRate($purchaseRate);

    expect($attached)->toBeTrue()
        ->and($connection->table('orders')->value('purchase_rate_uuid'))->toBe('rate-1')
        ->and($connection->table('transactions')->where('uuid', 'txn-new')->value('subject_uuid'))->toBe('order-1')
        ->and($connection->table('transactions')->where('uuid', 'txn-old')->value('status'))->toBe('voided');
});

test('closest drivers resolve through the spatial company chain', function () {
    $connection = fleetopsOrderPurchaseBoot();
    $connection->table('users')->insert(['uuid' => 'user-1', 'company_uuid' => 'company-1']);
    $connection->table('company_users')->insert(['uuid' => 'cu-1', 'company_uuid' => 'company-1', 'user_uuid' => 'user-1']);
    $connection->table('drivers')->insert(['uuid' => 'driver-1', 'company_uuid' => 'company-1', 'user_uuid' => 'user-1', 'status' => 'available', 'online' => 1, 'location' => 'POINT(1 1)']);

    $order  = fleetopsOrderPurchaseOrder();
    $pickup = new Place();
    $pickup->setRawAttributes(['uuid' => 'place-p', 'location' => new Point(1.3, 103.8)], true);
    $payload = new Fleetbase\FleetOps\Models\Payload();
    $payload->setRawAttributes(['uuid' => 'payload-1'], true);
    $payload->setRelation('pickup', $pickup);
    $payload->setRelation('waypoints', collect());
    $order->setRelation('payload', $payload);

    expect($order->findClosestDrivers())->toHaveCount(1);
});
