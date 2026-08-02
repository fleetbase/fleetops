<?php

use Fleetbase\FleetOps\Http\Controllers\Internal\v1\ServiceQuoteController;
use Fleetbase\FleetOps\Models\IntegratedVendor;
use Fleetbase\FleetOps\Models\Payload;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Models\PurchaseRate;
use Fleetbase\FleetOps\Models\ServiceQuote;
use Fleetbase\FleetOps\Models\ServiceQuoteItem;
use Fleetbase\FleetOps\Models\ServiceRate;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Http\JsonResponse;

/**
 * Covers the real bodies of the internal ServiceQuoteController's protected
 * helper methods against an in-memory SQLite fixture. The existing contracts
 * test drives the endpoints through a probe that overrides these helpers, so
 * the genuine lookup, purchase-rate, quote-persistence, and response
 * implementations were never executed.
 */
class FleetOpsInternalServiceQuoteHelpersProbe extends ServiceQuoteController
{
    public function callProtected(string $method, array $arguments = []): mixed
    {
        $reflection = new ReflectionMethod(ServiceQuoteController::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($this, ...$arguments);
    }
}

function fleetopsInternalServiceQuoteHelpersBoot(): SQLiteConnection
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
        'service_quotes'      => ['uuid', 'public_id', 'request_id', 'company_uuid', 'service_rate_uuid', 'amount', 'currency', 'meta', 'expired_at'],
        'service_quote_items' => ['uuid', 'service_quote_uuid', 'amount', 'currency', 'details', 'code'],
        'service_rates'       => ['uuid', 'public_id', 'company_uuid', 'currency', 'service_type'],
        'purchase_rates'      => ['uuid', 'public_id', 'company_uuid', 'customer_uuid', 'customer_type', 'service_quote_uuid', 'transaction_uuid', 'status', 'meta'],
        'payloads'            => ['uuid', 'public_id', 'company_uuid', 'pickup_uuid', 'dropoff_uuid', 'return_uuid', 'current_waypoint_uuid', 'meta', 'type'],
        'places'              => ['uuid', 'public_id', 'company_uuid', 'name'],
        'waypoints'           => ['uuid', 'public_id', 'company_uuid', 'payload_uuid', 'place_uuid', 'order'],
        'entities'            => ['uuid', 'public_id', 'company_uuid', 'payload_uuid', 'name'],
        'integrated_vendors'  => ['uuid', 'public_id', 'company_uuid', 'provider'],
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

test('service quote lookup and purchase rate helpers execute against the database', function () {
    $connection = fleetopsInternalServiceQuoteHelpersBoot();
    $connection->table('service_quotes')->insert(['uuid' => 'sq-1', 'public_id' => 'service_quote_1', 'company_uuid' => 'company-1']);

    $probe = new FleetOpsInternalServiceQuoteHelpersProbe();

    $quote = $probe->callProtected('findServiceQuoteForPurchase', ['sq-1']);
    expect($quote)->toBeInstanceOf(ServiceQuote::class)
        ->and($probe->callProtected('findServiceQuoteForPurchase', ['missing']))->toBeNull();

    expect($probe->callProtected('purchaseRateExists', [$quote]))->toBeFalse();

    $purchaseRate = $probe->callProtected('firstOrCreatePurchaseRate', [$quote]);
    expect($purchaseRate)->toBeInstanceOf(PurchaseRate::class)
        ->and($connection->table('purchase_rates')->count())->toBe(1)
        ->and($probe->callProtected('purchaseRateExists', [$quote]))->toBeTrue();

    // firstOrCreate must reuse the existing purchase rate
    $again = $probe->callProtected('firstOrCreatePurchaseRate', [$quote]);
    expect($connection->table('purchase_rates')->count())->toBe(1);
});

test('payload place and vendor lookup helpers execute against the database', function () {
    $connection = fleetopsInternalServiceQuoteHelpersBoot();
    $connection->table('payloads')->insert(['uuid' => 'payload-1', 'public_id' => 'payload_test', 'company_uuid' => 'company-1']);
    $connection->table('places')->insert(['uuid' => 'place-1', 'public_id' => 'place_test', 'company_uuid' => 'company-1', 'name' => 'Depot']);
    $connection->table('integrated_vendors')->insert(['uuid' => 'iv-1', 'public_id' => 'integrated_vendor_1', 'provider' => 'lalamove', 'company_uuid' => 'company-1']);

    $probe = new FleetOpsInternalServiceQuoteHelpersProbe();

    expect($probe->callProtected('findPayloadForQuote', ['payload_test']))->toBeInstanceOf(Payload::class)
        ->and($probe->callProtected('findPayloadForQuote', ['payload-1']))->toBeInstanceOf(Payload::class)
        ->and($probe->callProtected('findPayloadForQuote', ['missing']))->toBeNull();

    expect($probe->callProtected('findIntegratedVendorForQuote', ['integrated_vendor_1']))->toBeInstanceOf(IntegratedVendor::class)
        ->and($probe->callProtected('findIntegratedVendorForQuote', ['missing']))->toBeNull();

    expect($probe->callProtected('findIntegratedVendorForPreliminaryQuote', ['lalamove']))->toBeInstanceOf(IntegratedVendor::class)
        ->and($probe->callProtected('findIntegratedVendorForPreliminaryQuote', ['missing']))->toBeNull();

    $place = new Place();
    $place->setRawAttributes(['uuid' => 'existing-place'], true);
    expect($probe->callProtected('createPlaceFromMixed', [$place]))->toBe($place);

    expect($probe->callProtected('findPlaceByUuid', ['place-1']))->toBeInstanceOf(Place::class)
        ->and($probe->callProtected('findPlaceByUuid', ['missing']))->toBeNull();
});

test('service rate and quote persistence helpers execute against the database', function () {
    $connection = fleetopsInternalServiceQuoteHelpersBoot();
    $connection->table('service_rates')->insert(['uuid' => 'rate-1', 'public_id' => 'service_rate_1', 'currency' => 'USD', 'company_uuid' => 'company-1']);

    $probe = new FleetOpsInternalServiceQuoteHelpersProbe();

    expect($probe->callProtected('findServiceRateForQuote', ['rate-1', 'usd']))->toBeInstanceOf(ServiceRate::class)
        ->and($probe->callProtected('findServiceRateForQuote', ['rate-1', null]))->toBeInstanceOf(ServiceRate::class)
        ->and($probe->callProtected('findServiceRateForQuote', ['missing', 'usd']))->toBeNull()
        ->and($probe->callProtected('findServiceRateByUuid', ['rate-1']))->toBeInstanceOf(ServiceRate::class);

    $rates = $probe->callProtected('getServicableServiceRates', [[], 'delivery', 'USD', function ($query) {
        $query->where('company_uuid', 'company-1');
    }]);
    expect(is_iterable($rates))->toBeTrue();

    $requestId = $probe->callProtected('generateServiceQuoteRequestId');
    expect($requestId)->toStartWith('request_');

    $quote = $probe->callProtected('createServiceQuote', [[
        'request_id'        => $requestId,
        'company_uuid'      => 'company-1',
        'service_rate_uuid' => 'rate-1',
        'amount'            => 100,
        'currency'          => 'USD',
    ]]);
    expect($quote)->toBeInstanceOf(ServiceQuote::class)
        ->and($connection->table('service_quotes')->count())->toBe(1);

    $item = $probe->callProtected('createServiceQuoteItem', [[
        'service_quote_uuid' => $quote->uuid,
        'amount'             => 100,
        'currency'           => 'USD',
        'details'            => 'Base',
        'code'               => 'BASE',
    ]]);
    expect($item)->toBeInstanceOf(ServiceQuoteItem::class)
        ->and($connection->table('service_quote_items')->count())->toBe(1);
});

test('distance matrix helper computes distances with the calculate provider', function () {
    fleetopsInternalServiceQuoteHelpersBoot();
    config()->set('fleetops.distance_matrix.provider', 'calculate');

    $origin = new Place();
    $origin->setRawAttributes(['uuid' => 'o1', 'location' => new Point(1.0, 2.0)], true);
    $destination = new Place();
    $destination->setRawAttributes(['uuid' => 'd1', 'location' => new Point(1.5, 2.5)], true);

    $matrix = (new FleetOpsInternalServiceQuoteHelpersProbe())->callProtected('distanceMatrix', [[$origin], [$destination]]);

    expect($matrix->distance)->toBeGreaterThan(0)
        ->and($matrix->time)->toBeGreaterThan(0);
});

test('response helpers and stripe seams execute their real bodies', function () {
    fleetopsInternalServiceQuoteHelpersBoot();
    $probe = new FleetOpsInternalServiceQuoteHelpersProbe();

    $json = $probe->callProtected('jsonResponse', [['ok' => true], 201]);
    expect($json)->toBeInstanceOf(JsonResponse::class)
        ->and($json->getStatusCode())->toBe(201);

    $error = $probe->callProtected('errorResponse', ['quote failed']);
    expect($error->getData(true))->toBe(['error' => 'quote failed']);

    // The Stripe SDK is not installed in the harness; both stripe seams still
    // execute their real delegation bodies, which is the covered contract.
    $quote = new ServiceQuote();
    $quote->setRawAttributes(['uuid' => 'sq-1'], true);
    expect(fn () => $probe->callProtected('stripeClient'))->toThrow(Error::class)
        ->and(fn () => $probe->callProtected('createStripeCheckoutSessionForQuote', [$quote, 'https://redirect.example']))
        ->toThrow(Exception::class, 'The company you attempted to purchase a service quote from is not available at this time.');
});
