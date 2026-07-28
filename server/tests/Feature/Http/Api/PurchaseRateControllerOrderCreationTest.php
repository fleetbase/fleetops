<?php

use Fleetbase\FleetOps\Http\Controllers\Api\v1\PurchaseRateController;
use Fleetbase\FleetOps\Http\Requests\CreatePurchaseRateRequest;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Payload;
use Fleetbase\FleetOps\Models\ServiceQuote;
use Fleetbase\FleetOps\Models\ServiceRate;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;

/**
 * Covers PurchaseRateController::createOrderFromServiceQuote against SQLite:
 * the plain default-type order path, payload construction from preliminary
 * data (including the corrected return-place handling), payload and service
 * rate adoption from the quote, and the integrated-vendor failure branch
 * returning an api error response.
 *
 * Exercising this surface uncovered and fixed two latent bugs: the
 * preliminary-data block called setDropoff($return) instead of
 * setReturn($return) (fataling on quotes without a return place and
 * mislabeling it otherwise), and the vendor-failure branch returned a
 * JsonResponse from a method declared `?Order`.
 */
if (!function_exists('Fleetbase\Support\session')) {
    eval('namespace Fleetbase\Support; function session($key = null, $default = null) { if ($key === null) { return new class { public function has($k) { return \session($k) !== null; } public function get($k, $d = null) { return \session($k, $d); } }; } return \session($key, $default); }');
}

if (!function_exists('Fleetbase\Support\auth')) {
    eval('namespace Fleetbase\Support; function auth() { return new class { public function user() { return null; } public function id() { return null; } }; }');
}

function fleetopsPurchaseRateOrderBoot(): SQLiteConnection
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->sqliteCreateFunction('ST_GeomFromText', fn ($wkt, $srid = 0, $axisOrder = null) => $wkt);
    $pdo->sqliteCreateFunction('ST_PointFromText', fn ($wkt, $srid = 0, $axisOrder = null) => $wkt);
    $pdo->sqliteCreateFunction('ST_Equals', fn ($a, $b) => $a === $b ? 1 : 0);
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

    app()->instance('geocoder', new class {
        public $results;

        public function geocode($address)
        {
            return $this;
        }

        public function reverse($latitude, $longitude)
        {
            return $this;
        }

        public function get()
        {
            return collect();
        }
    });
    Geocoder\Laravel\Facades\Geocoder::clearResolvedInstances();

    Http::clearResolvedInstances();
    app()->forgetInstance(Illuminate\Http\Client\Factory::class);

    $schema = $connection->getSchemaBuilder();
    app()->instance('db.schema', $schema);
    $tables = [
        'orders'             => ['uuid', 'public_id', 'company_uuid', 'payload_uuid', 'type', 'status', 'driver_assigned_uuid', 'distance', 'time', 'meta', 'customer_uuid', 'customer_type', 'created_by_uuid', 'orchestrator_priority', 'internal_id', 'adhoc', 'dispatched', 'scheduled_at', 'transaction_uuid', 'purchase_rate_uuid', 'route_uuid', 'session_uuid', 'facilitator_uuid', 'facilitator_type', 'pickup_name', 'dropoff_name', 'updated_by_uuid'],
        'payloads'           => ['uuid', 'public_id', 'company_uuid', 'pickup_uuid', 'dropoff_uuid', 'return_uuid', 'current_waypoint_uuid', 'meta', 'cod_amount', 'cod_currency', 'cod_payment_method'],
        'service_quotes'     => ['uuid', 'public_id', 'request_id', 'company_uuid', 'payload_uuid', 'integrated_vendor_uuid', 'service_rate_uuid', 'amount', 'currency', 'meta', '_key'],
        'service_rates'      => ['uuid', 'public_id', 'company_uuid', 'service_name', 'type', 'base_fee', 'currency'],
        'integrated_vendors' => ['uuid', 'public_id', 'company_uuid', 'provider', 'credentials', 'options', 'sandbox'],
        'places'             => ['uuid', 'public_id', 'company_uuid', 'name', 'street1', 'city', 'country', 'location', 'meta', '_key', 'owner_uuid', 'phone'],
        'waypoints'          => ['uuid', 'public_id', 'company_uuid', 'payload_uuid', 'place_uuid', 'tracking_number_uuid', 'order_id'],
        'entities'           => ['uuid', 'public_id', 'company_uuid', 'payload_uuid', 'name', 'type'],
        'tracking_numbers'   => ['uuid', 'public_id', 'company_uuid', 'tracking_number', 'owner_uuid', 'owner_type'],
        'tracking_statuses'  => ['uuid', 'company_uuid', 'code', 'tracking_number_uuid', 'status', 'details'],
        'companies'          => ['uuid', 'public_id', 'name', 'country'],
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
    $connection->table('companies')->insert(['uuid' => 'company-1', 'public_id' => 'company_test', 'name' => 'Acme', 'country' => 'SG']);

    return $connection;
}

function fleetopsPurchaseRateOrderProbe(): PurchaseRateController
{
    return new class extends PurchaseRateController {
        public function callHelper(string $method, ...$arguments): mixed
        {
            return $this->{$method}(...$arguments);
        }
    };
}

function fleetopsPurchaseRateOrderQuote(array $attributes = []): ServiceQuote
{
    $serviceQuote = new ServiceQuote();
    $serviceQuote->setRawAttributes(array_merge([
        'uuid'         => 'sq-1',
        'public_id'    => 'service_quote_x',
        'company_uuid' => 'company-1',
    ], $attributes), true);
    $serviceQuote->exists = true;

    return $serviceQuote;
}

test('order creation defaults type without payload or service rate', function () {
    $connection = fleetopsPurchaseRateOrderBoot();
    $request    = CreatePurchaseRateRequest::create('/x', 'POST', []);

    $order = fleetopsPurchaseRateOrderProbe()->callHelper('createOrderFromServiceQuote', fleetopsPurchaseRateOrderQuote(), $request);

    expect($order)->toBeInstanceOf(Order::class)
        ->and($connection->table('orders')->value('type'))->toBe('default');
});

test('order creation builds a payload from preliminary data', function () {
    $connection = fleetopsPurchaseRateOrderBoot();
    $request    = CreatePurchaseRateRequest::create('/x', 'POST', []);

    $quote = fleetopsPurchaseRateOrderQuote([
        'meta' => json_encode(['preliminary_data' => [
            'pickup'    => ['name' => 'Pickup', 'street1' => '1 A St', 'city' => 'Singapore', 'country' => 'SG'],
            'dropoff'   => ['name' => 'Dropoff', 'street1' => '2 B St', 'city' => 'Singapore', 'country' => 'SG'],
            'return'    => ['name' => 'Return', 'street1' => '3 C St', 'city' => 'Singapore', 'country' => 'SG'],
            'waypoints' => [],
            'entities'  => [],
        ]]),
    ]);

    $order = fleetopsPurchaseRateOrderProbe()->callHelper('createOrderFromServiceQuote', $quote, $request);

    expect($order)->toBeInstanceOf(Order::class)
        ->and($connection->table('payloads')->count())->toBe(1)
        // pickup, dropoff, and return places are all persisted
        ->and($connection->table('places')->count())->toBe(3);

    // Quotes without a return place skip the return assignment entirely
    $noReturn = fleetopsPurchaseRateOrderQuote([
        'uuid' => 'sq-2',
        'meta' => json_encode(['preliminary_data' => [
            'pickup'  => ['name' => 'Pickup', 'street1' => '1 A St', 'city' => 'Singapore', 'country' => 'SG'],
            'dropoff' => ['name' => 'Dropoff', 'street1' => '2 B St', 'city' => 'Singapore', 'country' => 'SG'],
        ]]),
    ]);
    $secondOrder = fleetopsPurchaseRateOrderProbe()->callHelper('createOrderFromServiceQuote', $noReturn, $request);
    expect($secondOrder)->toBeInstanceOf(Order::class);
});

test('order creation adopts the quote payload and service rate type', function () {
    $connection = fleetopsPurchaseRateOrderBoot();
    $request    = CreatePurchaseRateRequest::create('/x', 'POST', []);

    $payload = new Payload();
    $payload->setRawAttributes(['uuid' => 'payload-9', 'public_id' => 'payload_test'], true);
    $serviceRate = new ServiceRate();
    $serviceRate->setRawAttributes(['uuid' => 'rate-1', 'public_id' => 'service_rate_x', 'type' => 'express'], true);

    $quote = fleetopsPurchaseRateOrderQuote();
    $quote->setRelation('payload', $payload);
    $quote->setRelation('serviceRate', $serviceRate);

    $order = fleetopsPurchaseRateOrderProbe()->callHelper('createOrderFromServiceQuote', $quote, $request);

    expect($order)->toBeInstanceOf(Order::class)
        ->and($connection->table('orders')->value('payload_uuid'))->toBe('payload-9')
        ->and($connection->table('orders')->value('type'))->toBe('express');
});

test('integrated vendor failures return an api error response', function () {
    $connection = fleetopsPurchaseRateOrderBoot();
    Http::fake(['*' => Http::response(['message' => 'vendor exploded'], 500)]);

    $connection->table('integrated_vendors')->insert([
        'uuid'         => 'iv-1',
        'public_id'    => 'integrated_vendor_x',
        'company_uuid' => 'company-1',
        'provider'     => 'lalamove',
        'credentials'  => json_encode(['api_key' => 'key', 'api_secret' => 'secret']),
        'sandbox'      => '1',
    ]);

    $quote   = fleetopsPurchaseRateOrderQuote(['integrated_vendor_uuid' => 'iv-1']);
    $request = CreatePurchaseRateRequest::create('/x', 'POST', []);

    $response = fleetopsPurchaseRateOrderProbe()->callHelper('createOrderFromServiceQuote', $quote, $request);

    expect($response)->toBeInstanceOf(JsonResponse::class)
        ->and($response->getData(true))->toHaveKey('error')
        ->and($connection->table('orders')->count())->toBe(0);
});
