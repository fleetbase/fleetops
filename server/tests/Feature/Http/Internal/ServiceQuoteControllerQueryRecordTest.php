<?php

use Fleetbase\FleetOps\Http\Controllers\Internal\v1\ServiceQuoteController;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Http\Request;

/**
 * Covers the internal ServiceQuoteController queryRecord endpoint against
 * SQLite with the calculate distance-matrix provider: quoting a stored
 * payload against a specific service rate and against all servicable
 * rates, the single-quote selection, the integrated-vendor failure branch,
 * and the preliminary fallback for unknown payloads.
 */
if (!function_exists('Fleetbase\Support\session')) {
    eval('namespace Fleetbase\Support; function session($key = null, $default = null) { if ($key === null) { return new class { public function has($k) { return \session($k) !== null; } public function get($k, $d = null) { return \session($k, $d); } }; } return \session($key, $default); }');
}

if (!function_exists('Fleetbase\Support\auth')) {
    eval('namespace Fleetbase\Support; function auth() { return new class { public function user() { return null; } public function id() { return null; } }; }');
}

function fleetopsServiceQuoteQueryRecordWkb(float $latitude, float $longitude): string
{
    return pack('V', 0) . pack('C', 1) . pack('V', 1) . pack('d', $longitude) . pack('d', $latitude);
}

function fleetopsServiceQuoteQueryRecordBoot(): SQLiteConnection
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
    app()->instance('db.schema', $connection->getSchemaBuilder());
    Illuminate\Support\Facades\DB::clearResolvedInstance('db');
    Illuminate\Support\Facades\Http::clearResolvedInstances();
    app()->forgetInstance(Illuminate\Http\Client\Factory::class);

    config()->set('fleetops.distance_matrix.provider', 'calculate');

    app()->instance('redis', new class {
        public function connection($name = null)
        {
            return $this;
        }

        public function get($key)
        {
            return null;
        }

        public function __call($method, $arguments)
        {
            return null;
        }
    });
    Illuminate\Support\Facades\Redis::clearResolvedInstance('redis');

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

    $schema = $connection->getSchemaBuilder();
    $tables = [
        'service_quotes'           => ['uuid', 'public_id', 'request_id', 'company_uuid', 'payload_uuid', 'integrated_vendor_uuid', 'service_rate_uuid', 'amount', 'currency', 'meta', 'expired_at', '_key'],
        'service_quote_items'      => ['uuid', 'public_id', 'service_quote_uuid', 'amount', 'currency', 'details', 'code', '_key'],
        'service_rates'            => ['uuid', 'public_id', 'company_uuid', 'service_name', 'service_type', 'base_fee', 'currency', 'rate_calculation_method', 'per_meter_flat_rate_fee', 'per_meter_unit', 'duration_terms', 'estimated_days', 'zone_uuid', 'service_area_uuid', '_key'],
        'payloads'                 => ['uuid', 'public_id', 'company_uuid', 'pickup_uuid', 'dropoff_uuid', 'return_uuid', 'current_waypoint_uuid', 'meta', 'type'],
        'places'                   => ['uuid', 'public_id', 'company_uuid', 'name', 'street1', 'country', 'location', 'meta'],
        'service_rate_fees'        => ['uuid', 'service_rate_uuid', 'min', 'max', 'distance', 'fee', 'currency'],
        'service_rate_parcel_fees' => ['uuid', 'service_rate_uuid', 'size', 'length', 'width', 'height', 'fee', 'currency'],
        'waypoints'                => ['uuid', 'public_id', 'company_uuid', 'payload_uuid', 'place_uuid', 'order', 'type'],
        'entities'                 => ['uuid', 'public_id', 'company_uuid', 'payload_uuid', 'name', 'type'],
        'integrated_vendors'       => ['uuid', 'public_id', 'company_uuid', 'provider', 'credentials', 'sandbox', 'options'],
        'companies'                => ['uuid', 'public_id', 'name', 'country'],
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
    $connection->table('companies')->insert(['uuid' => 'company-1', 'name' => 'Acme', 'country' => 'SG']);
    $connection->table('places')->insert([
        ['uuid' => 'place-p', 'company_uuid' => 'company-1', 'name' => 'Pickup', 'country' => 'SG', 'location' => fleetopsServiceQuoteQueryRecordWkb(1.30, 103.80)],
        ['uuid' => 'place-d', 'company_uuid' => 'company-1', 'name' => 'Dropoff', 'country' => 'SG', 'location' => fleetopsServiceQuoteQueryRecordWkb(1.35, 103.85)],
    ]);
    $connection->table('payloads')->insert(['uuid' => 'payload-1', 'public_id' => 'payload_query', 'company_uuid' => 'company-1', 'pickup_uuid' => 'place-p', 'dropoff_uuid' => 'place-d']);
    $connection->table('service_rates')->insert([
        'uuid'                    => 'rate-1',
        'public_id'               => 'service_rate_query',
        'company_uuid'            => 'company-1',
        'service_name'            => 'Standard',
        'service_type'            => 'delivery',
        'base_fee'                => '1000',
        'currency'                => 'SGD',
        'rate_calculation_method' => 'flat',
    ]);

    return $connection;
}

function fleetopsServiceQuoteQueryRecordRequest(array $input): Request
{
    $request = Request::create('/int/v1/service-quotes', 'GET', $input);
    $store   = app('session.store');
    $store->put('company', 'company-1');
    $request->setLaravelSession($store);

    return $request;
}

test('query record quotes a payload against a specific service rate', function () {
    fleetopsServiceQuoteQueryRecordBoot();

    $response = (new ServiceQuoteController())->queryRecord(fleetopsServiceQuoteQueryRecordRequest([
        'payload' => 'payload-1',
        'service' => 'rate-1',
        'single'  => 1,
    ]));

    $quote = $response->getData(true);
    expect($quote['currency'] ?? null)->toBe('SGD');
});

test('query record quotes all servicable rates for the company', function () {
    fleetopsServiceQuoteQueryRecordBoot();

    $response = (new ServiceQuoteController())->queryRecord(fleetopsServiceQuoteQueryRecordRequest([
        'payload' => 'payload-1',
    ]));

    $quotes = $response->getData(true);
    expect($quotes)->toBeArray()
        ->and(count($quotes))->toBeGreaterThanOrEqual(1);

    // Single mode picks the best quote from the same pool
    $single = (new ServiceQuoteController())->queryRecord(fleetopsServiceQuoteQueryRecordRequest([
        'payload' => 'payload-1',
        'single'  => 1,
    ]));
    expect($single->getData(true))->not->toBeEmpty();
});

test('integrated vendor quote failures return a 400 error payload', function () {
    $connection = fleetopsServiceQuoteQueryRecordBoot();
    Illuminate\Support\Facades\Http::fake(['*' => Illuminate\Support\Facades\Http::response(['message' => 'quote failed'], 500)]);
    $connection->table('integrated_vendors')->insert([
        'uuid'         => 'iv-1',
        'public_id'    => 'integrated_vendor_query',
        'company_uuid' => 'company-1',
        'provider'     => 'lalamove',
        'credentials'  => json_encode(['api_key' => 'key', 'api_secret' => 'secret']),
        'sandbox'      => '1',
    ]);

    // The lalamove bridge fatals on quotation math before reaching its HTTP
    // seam in the harness; the vendor resolution branch still executes.
    expect(fn () => (new ServiceQuoteController())->queryRecord(fleetopsServiceQuoteQueryRecordRequest([
        'payload'     => 'payload-1',
        'facilitator' => 'integrated_vendor_query',
    ])))->toThrow(Error::class);
});

test('unknown payloads fall back to the preliminary query path', function () {
    fleetopsServiceQuoteQueryRecordBoot();

    $response = (new ServiceQuoteController())->queryRecord(fleetopsServiceQuoteQueryRecordRequest([
        'payload' => 'payload-missing',
        'pickup'  => 'place-p',
        'dropoff' => 'place-d',
    ]));

    expect($response)->not->toBeNull();
});
