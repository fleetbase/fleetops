<?php

use Fleetbase\FleetOps\Http\Controllers\Api\v1\ServiceQuoteController;
use Fleetbase\FleetOps\Http\Requests\QueryServiceQuotesRequest;
use Fleetbase\FleetOps\Http\Resources\v1\ServiceQuote as ServiceQuoteResource;
use Fleetbase\FleetOps\Models\ServiceQuote;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Http\JsonResponse;

/**
 * Covers the API ServiceQuoteController integrated-vendor quoting branches
 * through the container-injectable vendor bridge: payload-backed vendor
 * quotes (single and collection), preliminary vendor quotes resolved by
 * provider name, and bridge failures surfacing as 400 error responses.
 */
if (!function_exists('Fleetbase\Support\session')) {
    eval('namespace Fleetbase\Support; function session($key = null, $default = null) { if ($key === null) { return new class { public function has($k) { return \session($k) !== null; } public function get($k, $d = null) { return \session($k, $d); } }; } return \session($key, $default); }');
}

class FleetOpsQuoteBridgeQuoteFake extends ServiceQuote
{
    protected $guarded = [];
    public $exists     = true;
}

function fleetopsQuoteVendorBridgeBoot(): SQLiteConnection
{
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
    $resolver   = new ConnectionResolver(['default' => $connection, 'mysql' => $connection]);
    $resolver->setDefaultConnection('mysql');
    EloquentModel::setConnectionResolver($resolver);
    if (!EloquentModel::getEventDispatcher()) {
        EloquentModel::setEventDispatcher(new Illuminate\Events\Dispatcher());
    }
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
    app()->instance('db.schema', $schema);
    $tables = [
        'service_quotes'     => ['uuid', 'public_id', 'request_id', 'company_uuid', 'service_rate_uuid', 'payload_uuid', 'integrated_vendor_uuid', 'amount', 'currency', 'meta', 'expired_at', '_key'],
        'integrated_vendors' => ['uuid', 'public_id', 'company_uuid', 'provider', 'webhook_url', 'host', 'namespace', 'credentials', 'options', 'sandbox', 'status', '_key'],
        'payloads'           => ['uuid', 'public_id', 'company_uuid', 'pickup_uuid', 'dropoff_uuid', 'return_uuid', 'current_waypoint_uuid', 'type', 'meta', '_key'],
        'places'             => ['uuid', 'public_id', 'company_uuid', 'name', 'location', '_key'],
        'waypoints'          => ['uuid', 'public_id', 'company_uuid', 'payload_uuid', 'place_uuid', 'order', 'type', 'status', '_key'],
        'entities'           => ['uuid', 'public_id', 'company_uuid', 'payload_uuid', 'name', 'type', 'status', 'meta', '_key'],
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
    $connection->table('integrated_vendors')->insert(['uuid' => 'iv-quote-1', 'public_id' => 'integrated_vendor_quotelala1', 'company_uuid' => 'company-1', 'provider' => 'lalamove', 'credentials' => json_encode([]), 'options' => json_encode([]), 'sandbox' => '1']);
    $connection->table('payloads')->insert(['uuid' => 'payload-quote-1', 'public_id' => 'payload_vendorquote1', 'company_uuid' => 'company-1']);
    $connection->table('places')->insert([
        ['uuid' => 'place-quote-pickup', 'public_id' => 'place_quotepickup1', 'company_uuid' => 'company-1', 'name' => 'Pickup'],
        ['uuid' => 'place-quote-dropoff', 'public_id' => 'place_quotedropoff1', 'company_uuid' => 'company-1', 'name' => 'Dropoff'],
    ]);
    $connection->table('service_quotes')->insert(['uuid' => 'quote-bridge-1', 'public_id' => 'quote_bridgeone1', 'company_uuid' => 'company-1', 'amount' => '3500', 'currency' => 'SGD', 'meta' => json_encode([])]);

    $GLOBALS['fleetopsQuoteBridgeMode'] = 'ok';
    app()->bind(Fleetbase\FleetOps\Integrations\Lalamove\Lalamove::class, function () {
        return new class {
            public function setIntegratedVendor($vendor)
            {
                return $this;
            }

            public function setRequestId($id)
            {
                return $this;
            }

            protected function makeQuote(): ServiceQuote
            {
                $quote = new FleetOpsQuoteBridgeQuoteFake();
                $quote->setRawAttributes(['uuid' => 'quote-bridge-1', 'public_id' => 'quote_bridgeone1', 'company_uuid' => 'company-1', 'amount' => '3500', 'currency' => 'SGD', 'meta' => json_encode([])], true);

                return $quote;
            }

            public function getQuoteFromPayload($payload, $serviceType = null, $scheduledAt = null, $isRouteOptimized = true)
            {
                if ($GLOBALS['fleetopsQuoteBridgeMode'] === 'fail') {
                    throw new Exception('vendor quote unavailable');
                }

                return $this->makeQuote();
            }

            public function getQuoteFromPreliminaryPayload($waypoints, $entities, $serviceType = null, $scheduledAt = null, $isRouteOptimized = true)
            {
                if ($GLOBALS['fleetopsQuoteBridgeMode'] === 'fail') {
                    throw new Exception('vendor preliminary quote unavailable');
                }

                return $this->makeQuote();
            }

            public function __call($method, $arguments)
            {
                return $this;
            }
        };
    });

    return $connection;
}

function fleetopsQuoteVendorBridgeRequest(array $input): QueryServiceQuotesRequest
{
    $request = new QueryServiceQuotesRequest($input);
    $request->setLaravelSession(app('session.store'));

    return $request;
}

test('payload backed vendor quotes return single and collection responses', function () {
    fleetopsQuoteVendorBridgeBoot();
    $controller = new ServiceQuoteController();

    $single = $controller->query(fleetopsQuoteVendorBridgeRequest([
        'payload'     => 'payload_vendorquote1',
        'facilitator' => 'integrated_vendor_quotelala1',
        'single'      => '1',
    ]));
    expect($single)->toBeInstanceOf(ServiceQuoteResource::class);

    $collection = $controller->query(fleetopsQuoteVendorBridgeRequest([
        'payload'     => 'payload_vendorquote1',
        'facilitator' => 'integrated_vendor_quotelala1',
    ]));
    expect($collection)->toBeInstanceOf(Illuminate\Http\Resources\Json\ResourceCollection::class);
});

test('payload vendor bridge failures surface as 400 error responses', function () {
    fleetopsQuoteVendorBridgeBoot();
    $GLOBALS['fleetopsQuoteBridgeMode'] = 'fail';

    $response = (new ServiceQuoteController())->query(fleetopsQuoteVendorBridgeRequest([
        'payload'     => 'payload_vendorquote1',
        'facilitator' => 'integrated_vendor_quotelala1',
    ]));
    $GLOBALS['fleetopsQuoteBridgeMode'] = 'ok';

    expect($response)->toBeInstanceOf(JsonResponse::class)
        ->and($response->getStatusCode())->toBe(400)
        ->and(json_encode($response->getData(true)))->toContain('vendor quote unavailable');
});

test('preliminary vendor quotes resolve by provider name through the bridge', function () {
    $connection = fleetopsQuoteVendorBridgeBoot();
    $controller = new ServiceQuoteController();

    // Model pickups exercise the non-scalar place resolution branch, and the
    // public-id facilitator hits the fast integrated-vendor id check
    $pickupPlace = Fleetbase\FleetOps\Models\Place::where('uuid', 'place-quote-pickup')->first();
    $single      = $controller->queryFromPreliminary(fleetopsQuoteVendorBridgeRequest([
        'pickup'      => $pickupPlace,
        'dropoff'     => 'place_quotedropoff1',
        'facilitator' => 'integrated_vendor_quotelala1',
        'single'      => '1',
    ]));
    expect($single)->toBeInstanceOf(ServiceQuoteResource::class)
        ->and((string) $connection->table('service_quotes')->where('uuid', 'quote-bridge-1')->value('meta'))->toContain('preliminary_data');

    $collection = $controller->queryFromPreliminary(fleetopsQuoteVendorBridgeRequest([
        'pickup'      => 'place_quotepickup1',
        'dropoff'     => 'place_quotedropoff1',
        'facilitator' => 'lalamove',
    ]));
    expect($collection)->toBeInstanceOf(Illuminate\Http\Resources\Json\ResourceCollection::class);
});

test('preliminary vendor bridge failures surface as 400 error responses', function () {
    fleetopsQuoteVendorBridgeBoot();
    $GLOBALS['fleetopsQuoteBridgeMode'] = 'fail';

    $response = (new ServiceQuoteController())->queryFromPreliminary(fleetopsQuoteVendorBridgeRequest([
        'pickup'      => 'place_quotepickup1',
        'dropoff'     => 'place_quotedropoff1',
        'facilitator' => 'lalamove',
    ]));
    $GLOBALS['fleetopsQuoteBridgeMode'] = 'ok';

    expect($response)->toBeInstanceOf(JsonResponse::class)
        ->and($response->getStatusCode())->toBe(400)
        ->and(json_encode($response->getData(true)))->toContain('vendor preliminary quote unavailable');
});
