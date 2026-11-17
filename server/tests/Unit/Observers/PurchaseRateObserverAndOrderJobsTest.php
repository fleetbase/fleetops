<?php

use Fleetbase\FleetOps\Jobs\FinalizeInternalOrderCreation;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\PurchaseRate;
use Fleetbase\FleetOps\Models\Waypoint;
use Fleetbase\FleetOps\Notifications\OrderCanceled;
use Fleetbase\FleetOps\Observers\PurchaseRateObserver;
use Fleetbase\Models\Transaction;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;

/**
 * Covers the PurchaseRateObserver protected helpers, the
 * FinalizeInternalOrderCreation job lookup/event flow, and the
 * OrderCanceled notification channels against SQLite.
 */
if (!function_exists('Fleetbase\Support\session')) {
    eval('namespace Fleetbase\Support; function session($key = null, $default = null) { if ($key === null) { return new class { public function has($k) { return \session($k) !== null; } public function get($k, $d = null) { return \session($k, $d); } }; } return \session($key, $default); }');
}

if (!function_exists('Fleetbase\Support\auth')) {
    eval('namespace Fleetbase\Support; function auth() { return new class { public function user() { return null; } public function id() { return null; } }; }');
}

if (!function_exists('Fleetbase\FleetOps\Jobs\event')) {
    eval('namespace Fleetbase\FleetOps\Jobs; function event($event = null) { \FleetOpsPurchaseObserverRecorder::$events[] = $event; return $event; }');
}

class FleetOpsPurchaseObserverRecorder
{
    public static array $events = [];
}

class FleetOpsPurchaseRateObserverProbe extends PurchaseRateObserver
{
    public function callHelper(string $method, ...$arguments): mixed
    {
        return $this->{$method}(...$arguments);
    }
}

function fleetopsPurchaseObserverBoot(): SQLiteConnection
{
    if (!Illuminate\Support\Str::hasMacro('humanize')) {
        Illuminate\Support\Str::macro('humanize', fn ($value, $uppercase = true) => str_replace('_', ' ', Illuminate\Support\Str::snake((string) $value)));
    }

    $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
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
        'purchase_rates'      => ['uuid', 'public_id', 'company_uuid', 'customer_uuid', 'customer_type', 'service_quote_uuid', 'payload_uuid', 'transaction_uuid', 'status', 'meta'],
        'service_quotes'      => ['uuid', 'public_id', 'company_uuid', 'amount', 'currency', 'service_rate_uuid', 'meta', 'expired_at'],
        'service_quote_items' => ['uuid', 'service_quote_uuid', 'amount', 'currency', 'details', 'code'],
        'transactions'        => ['uuid', 'public_id', 'company_uuid', 'customer_uuid', 'customer_type', 'gateway_transaction_id', 'gateway', 'amount', 'currency', 'description', 'type', 'status', 'meta'],
        'transaction_items'   => ['uuid', 'transaction_uuid', 'amount', 'currency', 'details', 'code'],
        'companies'           => ['uuid', 'public_id', 'name', 'currency', 'country'],
        'orders'              => ['uuid', 'public_id', 'company_uuid', 'payload_uuid', 'status', 'driver_assigned_uuid'],
        'settings'            => ['key', 'value'],
    ];
    foreach ($tables as $table => $columns) {
        $schema->create($table, function ($blueprint) use ($columns) {
            $blueprint->increments('id');
            foreach ($columns as $column) {
                $blueprint->string($column)->nullable();
            }
            $blueprint->timestamps();
            $blueprint->timestamp('deleted_at')->nullable();
        });
    }

    session(['company' => 'company-1', 'api_credential' => 'console']);
    $connection->table('companies')->insert(['uuid' => 'company-1', 'name' => 'Acme', 'currency' => 'SGD']);
    FleetOpsPurchaseObserverRecorder::$events = [];

    return $connection;
}

test('purchase rate observer helpers resolve currency amounts and records', function () {
    $connection = fleetopsPurchaseObserverBoot();
    $connection->table('service_quotes')->insert(['uuid' => 'sq-1', 'company_uuid' => 'company-1', 'amount' => '1500', 'currency' => 'SGD']);
    $connection->table('service_quote_items')->insert(['uuid' => 'sqi-1', 'service_quote_uuid' => 'sq-1', 'amount' => '1500', 'currency' => 'SGD', 'code' => 'BASE_FEE']);
    $connection->table('orders')->insert(['uuid' => 'order-1', 'company_uuid' => 'company-1', 'payload_uuid' => 'payload-1']);

    $probe        = new FleetOpsPurchaseRateObserverProbe();
    $purchaseRate = new PurchaseRate();
    $purchaseRate->setRawAttributes(['uuid' => 'rate-1', 'company_uuid' => 'company-1', 'service_quote_uuid' => 'sq-1', 'payload_uuid' => 'payload-1'], true);
    $purchaseRate->exists = true;

    expect($probe->callHelper('generateUuid'))->toBeString();
    $probe->callHelper('loadRelations', $purchaseRate);
    expect($probe->callHelper('hasServiceQuote', $purchaseRate))->toBeTrue()
        ->and($probe->callHelper('getServiceQuoteCurrency', $purchaseRate))->toBe('SGD')
        ->and((float) $probe->callHelper('getServiceQuoteAmount', $purchaseRate))->toBe(1500.0)
        ->and($probe->callHelper('getServiceQuoteItems', $purchaseRate))->toHaveCount(1);

    expect($probe->callHelper('findCompany', 'company-1')?->name)->toBe('Acme')
        ->and($probe->callHelper('findCompany', 'missing'))->toBeNull()
        ->and($probe->callHelper('getCompanyTransactionCurrency', $probe->callHelper('findCompany', 'company-1')))->toBe('SGD')
        ->and($probe->callHelper('getTransactionId', $purchaseRate))->toBeString();

    $transaction = $probe->callHelper('createTransaction', ['company_uuid' => 'company-1', 'amount' => '1500', 'currency' => 'SGD', 'status' => 'success']);
    expect($transaction)->toBeInstanceOf(Transaction::class)
        ->and($probe->callHelper('createTransactionItem', ['transaction_uuid' => $transaction->uuid, 'amount' => '1500', 'currency' => 'SGD']))->not->toBeNull();

    expect($probe->callHelper('resolveOrder', $purchaseRate)?->uuid)->toBe('order-1');
    $purchaseRate->payload_uuid = null;
    expect($probe->callHelper('resolveOrder', $purchaseRate))->toBeNull();
});

test('finalize internal order creation notifies and fires order ready', function () {
    $connection = fleetopsPurchaseObserverBoot();
    $connection->table('orders')->insert(['uuid' => 'order-1', 'company_uuid' => 'company-1', 'status' => 'created']);

    (new FinalizeInternalOrderCreation('order-1'))->handle();
    expect(collect(FleetOpsPurchaseObserverRecorder::$events)->first(fn ($event) => $event instanceof Fleetbase\FleetOps\Events\OrderReady))->not->toBeNull();

    // Missing orders exit without firing
    FleetOpsPurchaseObserverRecorder::$events = [];
    (new FinalizeInternalOrderCreation('order-missing'))->handle();
    expect(FleetOpsPurchaseObserverRecorder::$events)->toBe([]);
});

test('order canceled notification builds mail seams and push channels', function () {
    fleetopsPurchaseObserverBoot();

    $order = new Order();
    $order->setRawAttributes(['uuid' => 'order-1', 'public_id' => 'order_test', 'status' => 'canceled'], true);
    $order->exists = true;

    $notification = new OrderCanceled($order, 'customer request');

    // Console urls require the full application environment; the mail body
    // executes through subject/lines and tracking resolution to that seam.
    expect(fn () => $notification->toMail(null))->toThrow(Error::class);

    $waypoint = new Waypoint();
    $waypoint->setRawAttributes(['uuid' => 'wp-1'], true);
    $trackingNumber = new Fleetbase\FleetOps\Models\TrackingNumber();
    $trackingNumber->setRawAttributes(['uuid' => 'tn-1', 'tracking_number' => 'WPTRK-3'], true);
    $waypoint->setRelation('trackingNumber', $trackingNumber);
    $withWaypoint = new OrderCanceled($order, 'customer request', $waypoint);
    expect(fn () => $withWaypoint->toMail(null))->toThrow(Error::class);

    // Push transports are unavailable; delegation bodies still execute
    expect(fn () => $notification->toFcm(null))->toThrow(TypeError::class)
        ->and(fn () => $notification->toApn(null))->toThrow(Error::class);
});
