<?php

use Fleetbase\FleetOps\Http\Controllers\Api\v1\PurchaseRateController;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\PurchaseRate;
use Fleetbase\FleetOps\Models\ServiceQuote;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Http\Request;

/**
 * Covers the real bodies of the API PurchaseRateController's lookup and
 * persistence helpers against an in-memory SQLite fixture, plus the query
 * pipeline and find-or-fail paths.
 */
if (!function_exists('Fleetbase\Support\auth')) {
    eval('namespace Fleetbase\Support; function auth() { return new class { public function user() { return null; } public function id() { return null; } }; }');
}

if (!function_exists('Fleetbase\Support\session')) {
    eval('namespace Fleetbase\Support; function session($key = null, $default = null) { if ($key === null) { return new class { public function has($k) { return \session($k) !== null; } public function get($k, $d = null) { return \session($k, $d); } }; } return \session($key, $default); }');
}

if (!Request::hasMacro('getController')) {
    Request::macro('getController', fn () => new PurchaseRateController());
}

if (!Request::hasMacro('or')) {
    Request::macro('or', function (array $params = [], $default = null) {
        foreach ($params as $param) {
            if ($this->has($param)) {
                return $this->input($param);
            }
        }

        return $default;
    });
}

class FleetOpsApiPurchaseRateHelpersProbe extends PurchaseRateController
{
    public function callProtected(string $method, mixed ...$arguments): mixed
    {
        $reflection = new ReflectionMethod(PurchaseRateController::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($this, ...$arguments);
    }
}

function fleetopsApiPurchaseRateHelpersBoot(): SQLiteConnection
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
    app()->instance('db.schema', $connection->getSchemaBuilder());
    Illuminate\Support\Facades\DB::clearResolvedInstance('db');

    $schema = $connection->getSchemaBuilder();
    $tables = [
        'purchase_rates' => ['uuid', 'public_id', 'company_uuid', 'customer_uuid', 'customer_type', 'service_quote_uuid', 'transaction_uuid', 'status', 'meta'],
        'service_quotes' => ['uuid', 'public_id', 'company_uuid', 'request_id', 'service_rate_uuid', 'amount', 'currency', 'meta', 'expired_at'],
        'orders'         => ['uuid', 'public_id', 'company_uuid', 'status'],
        'contacts'       => ['uuid', 'public_id', 'company_uuid', 'name'],
        'vendors'        => ['uuid', 'public_id', 'company_uuid', 'name'],
        'directives'     => ['uuid', 'company_uuid', 'permission_uuid', 'subject_type', 'subject_uuid', 'key', 'rules'],
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

test('lookup and persistence helpers execute against the database', function () {
    $connection = fleetopsApiPurchaseRateHelpersBoot();
    $connection->table('orders')->insert(['uuid' => 'order-1', 'public_id' => 'order_test', 'company_uuid' => 'company-1']);
    $connection->table('service_quotes')->insert(['uuid' => 'sq-1', 'public_id' => 'service_quote_test', 'company_uuid' => 'company-1']);
    $connection->table('contacts')->insert(['uuid' => 'contact-1', 'company_uuid' => 'company-1', 'name' => 'Ada']);

    $probe = new FleetOpsApiPurchaseRateHelpersProbe();

    expect($probe->callProtected('findOrderByUuid', 'order-1'))->toBeInstanceOf(Order::class)
        ->and($probe->callProtected('findOrderByUuid', 'missing'))->toBeNull();

    expect($probe->callProtected('findServiceQuoteForPurchaseRate', 'sq-1', null))->toBeInstanceOf(ServiceQuote::class)
        ->and($probe->callProtected('findServiceQuoteForPurchaseRate', null, 'service_quote_test'))->toBeInstanceOf(ServiceQuote::class)
        ->and($probe->callProtected('findServiceQuoteForPurchaseRate', 'missing', 'missing'))->toBeNull();

    expect($probe->callProtected('getResourceUuid', ['contacts', 'vendors'], ['uuid' => 'contact-1', 'company_uuid' => 'company-1']))->toBe('contact-1');

    $purchaseRate = $probe->callProtected('createPurchaseRate', [[
        'company_uuid'       => 'company-1',
        'service_quote_uuid' => 'sq-1',
        'status'             => 'created',
    ]]);
    expect($purchaseRate)->toBeInstanceOf(PurchaseRate::class)
        ->and($connection->table('purchase_rates')->count())->toBe(1);
});

test('find purchase rate resolves records and reports missing ones', function () {
    $connection = fleetopsApiPurchaseRateHelpersBoot();
    $connection->table('purchase_rates')->insert(['uuid' => 'pr-1', 'public_id' => 'purchase_rate_test', 'company_uuid' => 'company-1']);

    $probe = new FleetOpsApiPurchaseRateHelpersProbe();

    expect($probe->callProtected('findPurchaseRate', 'purchase_rate_test'))->toBeInstanceOf(PurchaseRate::class);
    expect(fn () => $probe->callProtected('findPurchaseRate', 'missing'))->toThrow(ModelNotFoundException::class);
});

test('query purchase rates runs the request pipeline', function () {
    $connection = fleetopsApiPurchaseRateHelpersBoot();
    $connection->table('purchase_rates')->insert(['uuid' => 'pr-1', 'public_id' => 'purchase_rate_test', 'company_uuid' => 'company-1']);

    $request = Request::create('/v1/purchase-rates', 'GET');
    $store   = app('session.store');
    $store->put('company', 'company-1');
    $request->setLaravelSession($store);
    $request->setRouteResolver(fn () => new class {
        public function getAction($key = null)
        {
            return PurchaseRateController::class . '@query';
        }

        public function getActionMethod()
        {
            return 'query';
        }

        public function uri()
        {
            return 'v1/purchase-rates';
        }

        public function getName()
        {
            return 'api.v1.purchase-rates.query';
        }

        public function parameters()
        {
            return [];
        }
    });

    $results = (new FleetOpsApiPurchaseRateHelpersProbe())->callProtected('queryPurchaseRates', $request);

    expect($results->count())->toBe(1);
});
