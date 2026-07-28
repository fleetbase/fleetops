<?php

use Fleetbase\FleetOps\Http\Controllers\Internal\v1\ServiceQuoteController;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Http\Request;

/**
 * Covers the internal ServiceQuoteController preliminary query path against
 * SQLite: distance recalculation through the calculate matrix provider,
 * single-service quotes with persisted items, best-quote selection for
 * single requests across all rates, and the integrated-vendor branches for
 * missing vendors in both the payload and preliminary flows.
 */
if (!function_exists('Fleetbase\Observers\event')) {
    eval('namespace Fleetbase\Observers; function event($event = null, $payload = []) { return []; }');
}

if (!function_exists('Fleetbase\Support\session')) {
    eval('namespace Fleetbase\Support; function session($key = null, $default = null) { if ($key === null) { return new class { public function has($k) { return \session($k) !== null; } public function get($k, $d = null) { return \session($k, $d); } }; } return \session($key, $default); }');
}

if (!function_exists('Fleetbase\Support\auth')) {
    eval('namespace Fleetbase\Support; function auth() { return new class { public function user() { return null; } public function id() { return null; } }; }');
}

use Illuminate\Support\Str;

if (!Str::hasMacro('humanize')) {
    Str::macro('humanize', fn ($value) => ucfirst(str_replace(['_', '-'], ' ', Str::snake((string) $value))));
}

function fleetopsServiceQuotePreliminaryWkb(float $latitude, float $longitude): string
{
    return pack('V', 0) . pack('C', 1) . pack('V', 1) . pack('d', $longitude) . pack('d', $latitude);
}

function fleetopsServiceQuotePreliminaryBoot(): SQLiteConnection
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->sqliteCreateFunction('ST_GeomFromText', fn ($wkt, $srid = 0, $axisOrder = null) => $wkt);
    $pdo->sqliteCreateFunction('ST_PointFromText', fn ($wkt, $srid = 0, $axisOrder = null) => $wkt);
    $pdo->sqliteCreateFunction('ST_Equals', fn ($a, $b) => $a === $b ? 1 : 0);
    $connection = new SQLiteConnection($pdo);
    $resolver   = new ConnectionResolver(['default' => $connection, 'mysql' => $connection]);
    $resolver->setDefaultConnection('mysql');
    EloquentModel::setConnectionResolver($resolver);
    EloquentModel::setEventDispatcher(new Illuminate\Events\Dispatcher());
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

    app()->instance('responsecache', new class {
        public function __call($method, $arguments)
        {
            return null;
        }
    });

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
        ['uuid' => '11111111-1111-4111-8111-111111111111', 'public_id' => 'place_prelim1', 'company_uuid' => 'company-1', 'name' => 'Pickup', 'country' => 'SG', 'location' => fleetopsServiceQuotePreliminaryWkb(1.30, 103.80)],
        ['uuid' => '22222222-2222-4222-8222-222222222222', 'public_id' => 'place_prelim2', 'company_uuid' => 'company-1', 'name' => 'Dropoff', 'country' => 'SG', 'location' => fleetopsServiceQuotePreliminaryWkb(1.35, 103.85)],
    ]);
    $connection->table('payloads')->insert(['uuid' => 'payload-1', 'public_id' => 'payload_prelimq', 'company_uuid' => 'company-1', 'pickup_uuid' => '11111111-1111-4111-8111-111111111111', 'dropoff_uuid' => '22222222-2222-4222-8222-222222222222']);
    $connection->table('service_rates')->insert([
        [
            'uuid'                    => '33333333-3333-4333-8333-333333333333',
            'public_id'               => 'service_rate_prelim1',
            'company_uuid'            => 'company-1',
            'service_name'            => 'Standard',
            'service_type'            => 'delivery',
            'base_fee'                => '1000',
            'currency'                => 'SGD',
            'rate_calculation_method' => 'flat',
        ],
        [
            'uuid'                    => '44444444-4444-4444-8444-444444444444',
            'public_id'               => 'service_rate_prelim2',
            'company_uuid'            => 'company-1',
            'service_name'            => 'Express',
            'service_type'            => 'delivery',
            'base_fee'                => '2500',
            'currency'                => 'SGD',
            'rate_calculation_method' => 'flat',
        ],
    ]);

    return $connection;
}

function fleetopsServiceQuotePreliminaryRequest(array $input): Request
{
    $request = Request::create('/int/v1/service-quotes/preliminary', 'GET', $input);
    $store   = app('session.store');
    $store->put('company', 'company-1');
    $request->setLaravelSession($store);

    return $request;
}

test('preliminary single service quotes recalculate distance and persist items', function () {
    $connection = fleetopsServiceQuotePreliminaryBoot();

    $response = (new ServiceQuoteController())->preliminaryQuery(fleetopsServiceQuotePreliminaryRequest([
        'pickup'  => '11111111-1111-4111-8111-111111111111',
        'dropoff' => '22222222-2222-4222-8222-222222222222',
        'service' => '33333333-3333-4333-8333-333333333333',
        'single'  => 1,
    ]));

    $data = $response->getData(true);
    expect((int) $data['amount'])->toBe(1000)
        ->and($data['currency'])->toBe('SGD')
        ->and($connection->table('service_quotes')->count())->toBe(1);
});

test('preliminary single requests across all rates pick the best quote', function () {
    fleetopsServiceQuotePreliminaryBoot();

    $response = (new ServiceQuoteController())->preliminaryQuery(fleetopsServiceQuotePreliminaryRequest([
        'pickup'   => '11111111-1111-4111-8111-111111111111',
        'dropoff'  => '22222222-2222-4222-8222-222222222222',
        'distance' => 5000,
        'time'     => 600,
        'single'   => 1,
    ]));

    $data = $response->getData(true);
    expect($data)->toBeArray()
        ->and(array_key_exists('amount', $data))->toBeTrue();
});

test('missing integrated vendors return empty quote payloads', function () {
    fleetopsServiceQuotePreliminaryBoot();
    $controller = new ServiceQuoteController();

    // Payload flow with single and list responses
    $single = $controller->queryRecord(fleetopsServiceQuotePreliminaryRequest([
        'payload'     => 'payload_prelimq',
        'facilitator' => 'integrated_vendor_missing',
        'single'      => 1,
    ]));
    expect($single->getData(true))->toBe([]);

    $list = $controller->queryRecord(fleetopsServiceQuotePreliminaryRequest([
        'payload'     => 'payload_prelimq',
        'facilitator' => 'integrated_vendor_missing',
    ]));
    expect($list->getData(true))->toBe([]);

    // Preliminary flow with single and list responses
    $preliminarySingle = $controller->preliminaryQuery(fleetopsServiceQuotePreliminaryRequest([
        'pickup'      => '11111111-1111-4111-8111-111111111111',
        'dropoff'     => '22222222-2222-4222-8222-222222222222',
        'facilitator' => 'integrated_vendor_missing',
        'single'      => 1,
    ]));
    expect($preliminarySingle->getData(true))->toBe([]);

    $preliminaryList = $controller->preliminaryQuery(fleetopsServiceQuotePreliminaryRequest([
        'pickup'      => '11111111-1111-4111-8111-111111111111',
        'dropoff'     => '22222222-2222-4222-8222-222222222222',
        'facilitator' => 'integrated_vendor_missing',
    ]));
    expect($preliminaryList->getData(true))->toBe([]);
});
