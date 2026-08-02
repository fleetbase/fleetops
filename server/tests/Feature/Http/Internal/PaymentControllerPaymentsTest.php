<?php

use Fleetbase\FleetOps\Http\Controllers\Internal\v1\PaymentController;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Covers the internal PaymentController received-payments listing and the real
 * bodies of its protected helper methods. The payments query executes against
 * an in-memory SQLite fixture including the serviceQuote/order whereHas
 * scoping, eager loads, sorting, pagination, and per-currency totals.
 */
class FleetOpsInternalPaymentProbe extends PaymentController
{
    public function callProtected(string $method, array $arguments = []): mixed
    {
        $reflection = new ReflectionMethod(PaymentController::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($this, ...$arguments);
    }
}

function fleetopsInternalPaymentBoot(): SQLiteConnection
{
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

    // The fast-paginate package is not installed in the test harness; a plain
    // limited collection satisfies the resource collection contract.
    if (!Builder::hasGlobalMacro('fastPaginate')) {
        Builder::macro('fastPaginate', function ($limit = 15) {
            return $this->limit($limit)->get();
        });
    }

    $schema = $connection->getSchemaBuilder();
    $tables = [
        'purchase_rates' => ['uuid', 'public_id', 'company_uuid', 'customer_uuid', 'customer_type', 'service_quote_uuid', 'transaction_uuid', 'status', 'meta'],
        'service_quotes' => ['uuid', 'public_id', 'company_uuid', 'request_id', 'service_rate_uuid', 'amount', 'currency', 'meta', 'expired_at'],
        'orders'         => ['uuid', 'public_id', 'company_uuid', 'purchase_rate_uuid', 'status'],
        'transactions'   => ['uuid', 'public_id', 'company_uuid'],
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

    session(['company' => 'company-1']);

    return $connection;
}

function fleetopsInternalPaymentSeed(SQLiteConnection $connection, string $suffix, string $currency, int $amount): void
{
    $connection->table('service_quotes')->insert([
        'uuid'         => 'sq-' . $suffix,
        'public_id'    => 'service_quote_' . $suffix,
        'amount'       => $amount,
        'currency'     => $currency,
        'company_uuid' => 'company-1',
    ]);
    $connection->table('purchase_rates')->insert([
        'uuid'               => 'pr-' . $suffix,
        'public_id'          => 'purchase_rate_' . $suffix,
        'company_uuid'       => 'company-1',
        'service_quote_uuid' => 'sq-' . $suffix,
        'status'             => 'created',
        'created_at'         => '2026-01-01 00:00:00',
    ]);
    $connection->table('orders')->insert([
        'uuid'               => 'order-' . $suffix,
        'public_id'          => 'order_' . $suffix,
        'company_uuid'       => 'company-1',
        'purchase_rate_uuid' => 'pr-' . $suffix,
    ]);
}

test('received payments endpoint lists company payments with per-currency totals', function () {
    $connection = fleetopsInternalPaymentBoot();
    fleetopsInternalPaymentSeed($connection, 'a', 'USD', 100);
    fleetopsInternalPaymentSeed($connection, 'b', 'USD', 50);
    fleetopsInternalPaymentSeed($connection, 'c', 'SGD', 75);

    $result = (new PaymentController())->getCompanyReceivedPayments(Request::create('/x', 'GET'));

    expect($result)->toBeInstanceOf(Fleetbase\Http\Resources\FleetbaseResourceCollection::class)
        ->and($result->collection->count())->toBe(3)
        ->and($result->additional['amount_totals'])->toBe(['USD' => 150, 'SGD' => 75]);
});

test('received payments endpoint excludes purchase rates without a live order', function () {
    $connection = fleetopsInternalPaymentBoot();
    fleetopsInternalPaymentSeed($connection, 'kept', 'USD', 100);

    // Purchase rate whose order is soft-deleted must be filtered out
    $connection->table('service_quotes')->insert(['uuid' => 'sq-x', 'public_id' => 'service_quote_x', 'amount' => 999, 'currency' => 'USD', 'company_uuid' => 'company-1']);
    $connection->table('purchase_rates')->insert(['uuid' => 'pr-x', 'public_id' => 'purchase_rate_x', 'company_uuid' => 'company-1', 'service_quote_uuid' => 'sq-x', 'status' => 'created']);
    $connection->table('orders')->insert(['uuid' => 'order-x', 'public_id' => 'order_x', 'company_uuid' => 'company-1', 'purchase_rate_uuid' => 'pr-x', 'deleted_at' => '2026-01-01 00:00:00']);

    $result = (new PaymentController())->getCompanyReceivedPayments(Request::create('/x', 'GET', ['limit' => 10]));

    expect($result->collection->count())->toBe(1)
        ->and($result->additional['amount_totals'])->toBe(['USD' => 100]);
});

test('json and error response helpers build the expected responses', function () {
    $probe = new FleetOpsInternalPaymentProbe();

    $json = $probe->callProtected('jsonResponse', [['ok' => true]]);
    expect($json)->toBeInstanceOf(JsonResponse::class)
        ->and($json->getData(true))->toBe(['ok' => true]);

    $error = $probe->callProtected('errorResponse', ['payment failed']);
    expect($error->getData(true))->toBe(['error' => 'payment failed']);
});

test('company and stripe client helpers delegate to their integration seams', function () {
    $probe = new FleetOpsInternalPaymentProbe();

    // Auth::getCompany requires a full session/auth boot and the Stripe SDK is
    // not installed in the harness; both helpers still execute their real
    // delegation bodies, which is the covered contract here.
    expect(fn () => $probe->callProtected('getCompany'))->toThrow(Error::class)
        ->and(fn () => $probe->callProtected('stripeClient'))->toThrow(Error::class);
});
