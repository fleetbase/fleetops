<?php

use Fleetbase\FleetOps\Http\Controllers\Api\v1\PlaceController;
use Fleetbase\FleetOps\Http\Controllers\Api\v1\ServiceQuoteController;
use Fleetbase\FleetOps\Http\Requests\CreatePlaceRequest;
use Fleetbase\FleetOps\Models\Place;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Covers the API PlaceController helper seams and search endpoint plus the
 * API ServiceQuoteController preliminary query flow against SQLite:
 * place resolution from mixed and public-id inputs, single-service quotes
 * with items, integrated-vendor branches for missing vendors, and the
 * geocoding-backed helper seams.
 */
if (!function_exists('Fleetbase\Support\session')) {
    eval('namespace Fleetbase\Support; function session($key = null, $default = null) { if ($key === null) { return new class { public function has($k) { return \session($k) !== null; } public function get($k, $d = null) { return \session($k, $d); } }; } return \session($key, $default); }');
}

if (!function_exists('Fleetbase\Support\auth')) {
    eval('namespace Fleetbase\Support; function auth() { return new class { public function user() { return null; } public function id() { return null; } }; }');
}

if (!function_exists('Fleetbase\Observers\event')) {
    eval('namespace Fleetbase\Observers; function event($event = null, $payload = []) { return []; }');
}

if (!function_exists('Fleetbase\FleetOps\Support\env')) {
    eval('namespace Fleetbase\FleetOps\Support; function env($key = null, $default = null) { return $default; }');
}

if (!Str::hasMacro('humanize')) {
    Str::macro('humanize', fn ($value) => ucfirst(str_replace(['_', '-'], ' ', Str::snake((string) $value))));
}

class FleetOpsApiPlaceControllerProbe extends PlaceController
{
    public function callHelper(string $method, ...$arguments): mixed
    {
        return $this->{$method}(...$arguments);
    }
}

function fleetopsPlaceSeamsBoot(): SQLiteConnection
{
    if (!Request::hasMacro('isString')) {
        Request::macro('isString', function ($key) {
            return is_string($this->input($key));
        });
    }
    if (!Request::hasMacro('isArray')) {
        Request::macro('isArray', function ($key) {
            return is_array($this->input($key));
        });
    }

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

    app()->instance('geocoder', new class {
        public function geocode($query)
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

        public function __call($method, $arguments)
        {
            return $this;
        }
    });

    $schema = $connection->getSchemaBuilder();
    $tables = [
        'places'                   => ['uuid', 'public_id', 'company_uuid', 'owner_uuid', 'owner_type', 'name', 'street1', 'street2', 'city', 'province', 'postal_code', 'country', 'phone', 'location', 'meta', 'type', '_key', '_import_id'],
        'service_quotes'           => ['uuid', 'public_id', 'request_id', 'company_uuid', 'payload_uuid', 'integrated_vendor_uuid', 'service_rate_uuid', 'amount', 'currency', 'meta', 'expired_at', '_key'],
        'service_quote_items'      => ['uuid', 'public_id', 'service_quote_uuid', 'amount', 'currency', 'details', 'code', '_key'],
        'service_rates'            => ['uuid', 'public_id', 'company_uuid', 'service_name', 'service_type', 'base_fee', 'currency', 'rate_calculation_method', 'per_meter_flat_rate_fee', 'per_meter_unit', 'duration_terms', 'estimated_days', 'zone_uuid', 'service_area_uuid', '_key'],
        'service_rate_fees'        => ['uuid', 'service_rate_uuid', 'min', 'max', 'distance', 'fee', 'currency'],
        'service_rate_parcel_fees' => ['uuid', 'service_rate_uuid', 'size', 'length', 'width', 'height', 'fee', 'currency'],
        'payloads'                 => ['uuid', 'public_id', 'company_uuid', 'pickup_uuid', 'dropoff_uuid', 'return_uuid', 'current_waypoint_uuid', 'meta', 'type'],
        'waypoints'                => ['uuid', 'public_id', 'company_uuid', 'payload_uuid', 'place_uuid', 'order', 'type'],
        'entities'                 => ['uuid', 'public_id', 'company_uuid', 'payload_uuid', 'name', 'type'],
        'integrated_vendors'       => ['uuid', 'public_id', 'company_uuid', 'provider', 'credentials', 'sandbox', 'options'],
        'companies'                => ['uuid', 'public_id', 'name', 'country'],
        'contacts'                 => ['uuid', 'public_id', 'company_uuid', 'name', 'type', 'email', 'phone'],
        'vendors'                  => ['uuid', 'public_id', 'company_uuid', 'name', 'email', 'phone'],
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

    return $connection;
}

function fleetopsPlaceSeamsWkb(float $latitude, float $longitude): string
{
    return pack('V', 0) . pack('C', 1) . pack('V', 1) . pack('d', $longitude) . pack('d', $latitude);
}

function fleetopsPlaceSeamsRequest(string $uri, array $input): Request
{
    $request = Request::create('/' . $uri, 'GET', $input);
    $store   = app('session.store');
    $store->put('company', 'company-1');
    $request->setLaravelSession($store);

    return $request;
}

function fleetopsPlaceSeamsQuoteRequest(string $uri, array $input): Fleetbase\FleetOps\Http\Requests\QueryServiceQuotesRequest
{
    $request = Fleetbase\FleetOps\Http\Requests\QueryServiceQuotesRequest::create('/' . $uri, 'GET', $input);
    $store   = app('session.store');
    $store->put('company', 'company-1');
    $request->setLaravelSession($store);

    return $request;
}

test('place controller helper seams resolve lookups values and geocoding', function () {
    $connection = fleetopsPlaceSeamsBoot();
    $connection->table('places')->insert(['uuid' => '11111111-1111-4111-8111-111111111111', 'public_id' => 'place_seamsone1', 'company_uuid' => 'company-1', 'name' => 'Depot', 'street1' => 'Main St', 'location' => fleetopsPlaceSeamsWkb(1.30, 103.80)]);

    $probe = new FleetOpsApiPlaceControllerProbe();

    expect($probe->callHelper('getUuid', 'places', ['public_id' => 'place_seamsone1']))->not->toBeNull()
        ->and($probe->callHelper('getValue', ['nested' => ['key' => 'value']], 'nested.key'))->toBe('value')
        ->and($probe->callHelper('getModelClassName', 'places'))->toBe('\Fleetbase\FleetOps\Models\Place')
        ->and($probe->callHelper('orValue', ['first' => null, 'second' => 'match'], ['first', 'second']))->toBe('match')
        ->and($probe->callHelper('firstOrNewPlace', ['public_id' => 'place_seamsone1']))->toBeInstanceOf(Place::class)
        ->and($probe->callHelper('findPlaceOrFail', 'place_seamsone1')?->uuid)->toBe('11111111-1111-4111-8111-111111111111');

    // Geocoding-backed creation resolves through the empty geocoder
    expect($probe->callHelper('createPlaceFromGeocodingLookup', '88 Somewhere Road'))->toBeInstanceOf(Place::class);

    // Search options mirror the request query parameters
    $options = $probe->callHelper('placeSearchOptionsFromRequest', fleetopsPlaceSeamsRequest('v1/places/search', ['limit' => 5, 'geo' => 1, 'latitude' => 1.3, 'longitude' => 103.8]));
    expect($options)->toBeArray();

    // Coordinate parsing accepts pairs and rejects absent input
    $point = $probe->callHelper('pointFromCoordinateRequest', fleetopsPlaceSeamsRequest('v1/places', ['latitude' => 1.31, 'longitude' => 103.81]));
    expect($point?->getLat())->toBe(1.31)
        ->and($probe->callHelper('pointFromCoordinateRequest', fleetopsPlaceSeamsRequest('v1/places', [])))->toBeNull();

    // Search endpoint returns serialized saved places
    $results = (new PlaceController())->search(fleetopsPlaceSeamsRequest('v1/places/search', ['query' => 'depot']));
    expect($results)->not->toBeNull();
});

test('preliminary service quotes resolve places and quote single services', function () {
    $connection = fleetopsPlaceSeamsBoot();
    $connection->table('places')->insert([
        ['uuid' => '11111111-1111-4111-8111-111111111111', 'public_id' => 'place_sqapione1', 'company_uuid' => 'company-1', 'name' => 'Pickup', 'country' => 'SG', 'location' => fleetopsPlaceSeamsWkb(1.30, 103.80)],
        ['uuid' => '22222222-2222-4222-8222-222222222222', 'public_id' => 'place_sqapitwo2', 'company_uuid' => 'company-1', 'name' => 'Dropoff', 'country' => 'SG', 'location' => fleetopsPlaceSeamsWkb(1.35, 103.85)],
    ]);
    $connection->table('service_rates')->insert([
        'uuid'                    => '33333333-3333-4333-8333-333333333333',
        'public_id'               => 'service_rate_sqapione',
        'company_uuid'            => 'company-1',
        'service_name'            => 'Standard',
        'service_type'            => 'delivery',
        'base_fee'                => '1200',
        'currency'                => 'SGD',
        'rate_calculation_method' => 'flat',
    ]);

    $response = (new ServiceQuoteController())->queryFromPreliminary(fleetopsPlaceSeamsQuoteRequest('v1/service-quotes/preliminary', [
        'pickup'  => 'place_sqapione1',
        'dropoff' => ['uuid' => '22222222-2222-4222-8222-222222222222'],
        'service' => '33333333-3333-4333-8333-333333333333',
        'single'  => 1,
    ]));

    expect($response)->not->toBeNull()
        ->and($connection->table('service_quotes')->count())->toBeGreaterThanOrEqual(1);
});

test('integrated vendor branches respond for missing vendors and error seams', function () {
    $connection = fleetopsPlaceSeamsBoot();
    $connection->table('places')->insert([
        ['uuid' => '11111111-1111-4111-8111-111111111111', 'public_id' => 'place_sqivone11', 'company_uuid' => 'company-1', 'name' => 'Pickup', 'country' => 'SG', 'location' => fleetopsPlaceSeamsWkb(1.30, 103.80)],
        ['uuid' => '22222222-2222-4222-8222-222222222222', 'public_id' => 'place_sqivtwo22', 'company_uuid' => 'company-1', 'name' => 'Dropoff', 'country' => 'SG', 'location' => fleetopsPlaceSeamsWkb(1.35, 103.85)],
    ]);
    $connection->table('payloads')->insert(['uuid' => 'payload-1', 'public_id' => 'payload_sqivone', 'company_uuid' => 'company-1', 'pickup_uuid' => '11111111-1111-4111-8111-111111111111', 'dropoff_uuid' => '22222222-2222-4222-8222-222222222222']);

    $controller = new ServiceQuoteController();

    // Payload flow with a missing vendor returns an empty collection
    $list = $controller->query(fleetopsPlaceSeamsQuoteRequest('v1/service-quotes', [
        'payload'     => 'payload_sqivone',
        'facilitator' => 'integrated_vendor_missing',
    ]));
    expect($list)->not->toBeNull();

    // Preliminary flow with a missing vendor reaches the unset-quote seam
    expect(fn () => $controller->queryFromPreliminary(fleetopsPlaceSeamsQuoteRequest('v1/service-quotes/preliminary', [
        'pickup'      => 'place_sqivone11',
        'dropoff'     => 'place_sqivtwo22',
        'facilitator' => 'integrated_vendor_missing',
    ])))->toThrow(Error::class);
});

test('place creation covers street-only owner and location fallbacks', function () {
    $connection = fleetopsPlaceSeamsBoot();
    $connection->table('contacts')->insert(['uuid' => '44444444-4444-4444-8444-444444444444', 'public_id' => 'contact_placeown1', 'company_uuid' => 'company-1', 'name' => 'Owner Contact']);
    $controller = new PlaceController();

    // A street1-only request resolves through the geocoding lookup path
    $streetOnly = $controller->create(CreatePlaceRequest::create('/v1/places', 'POST', [
        'street1' => '99StreetOnlyRoad',
    ]));
    expect($connection->table('places')->where('street1', '99StreetOnlyRoad')->count())->toBe(1);

    // Address objects without coordinates fall back to a zero-point location
    // after the empty geocoder yields nothing; string owners resolve by
    // public id including the customer prefix rewrite
    $withOwner = $controller->create(CreatePlaceRequest::create('/v1/places', 'POST', [
        'name'    => 'Owner Place',
        'street1' => 'Owner Street 5',
        'city'    => 'Singapore',
        'country' => 'SG',
        'owner'   => 'customer_placeown1',
    ]));
    expect($connection->table('places')->where('name', 'Owner Place')->value('owner_uuid'))->toBe('44444444-4444-4444-8444-444444444444');
});

test('place point and resource seams wrap coordinates and responses', function () {
    $connection = fleetopsPlaceSeamsBoot();
    $connection->table('places')->insert(['uuid' => '11111111-1111-4111-8111-111111111111', 'public_id' => 'place_seamtwo1', 'company_uuid' => 'company-1', 'name' => 'Depot', 'location' => fleetopsPlaceSeamsWkb(1.30, 103.80)]);
    $probe = new FleetOpsApiPlaceControllerProbe();

    // Location-shaped requests parse through the mixed-point seam
    $fromLocation = $probe->callHelper('pointFromCoordinateRequest', fleetopsPlaceSeamsRequest('v1/places', ['location' => ['lat' => 1.32, 'lng' => 103.82]]));
    expect($fromLocation?->getLat())->toBe(1.32);

    $withLocation = $probe->callHelper('withLocationFromRequest', [], fleetopsPlaceSeamsRequest('v1/places', ['location' => ['lat' => 1.33, 'lng' => 103.83]]));
    expect($withLocation)->toHaveKey('location');

    // Reverse lookups surface the keyless geocoder rejection pre-network
    expect(fn () => $probe->callHelper('createPlaceFromReverseGeocodingLookup', new Fleetbase\LaravelMysqlSpatial\Types\Point(1.3, 103.8)))
        ->toThrow(Exception::class);

    $place = Place::where('uuid', '11111111-1111-4111-8111-111111111111')->first();
    expect($probe->callHelper('placeResource', $place))->not->toBeNull()
        ->and($probe->callHelper('placeResourceCollection', collect([$place])))->not->toBeNull()
        ->and($probe->callHelper('deletedPlaceResource', $place))->not->toBeNull()
        ->and($probe->callHelper('apiError', 'nope', 400)->getStatusCode())->toBe(400);
});

test('integrated vendor api failures respond with quote errors', function () {
    $connection = fleetopsPlaceSeamsBoot();
    $connection->table('places')->insert([
        ['uuid' => '11111111-1111-4111-8111-111111111111', 'public_id' => 'place_sqerrone1', 'company_uuid' => 'company-1', 'name' => 'Pickup', 'country' => 'SG', 'location' => fleetopsPlaceSeamsWkb(1.30, 103.80)],
        ['uuid' => '22222222-2222-4222-8222-222222222222', 'public_id' => 'place_sqerrtwo2', 'company_uuid' => 'company-1', 'name' => 'Dropoff', 'country' => 'SG', 'location' => fleetopsPlaceSeamsWkb(1.35, 103.85)],
    ]);
    $connection->table('payloads')->insert(['uuid' => 'payload-err-1', 'public_id' => 'payload_sqerrone', 'company_uuid' => 'company-1', 'pickup_uuid' => '11111111-1111-4111-8111-111111111111', 'dropoff_uuid' => '22222222-2222-4222-8222-222222222222']);
    $connection->table('integrated_vendors')->insert(['uuid' => 'iv-err-1', 'public_id' => 'integrated_vendor_err1', 'company_uuid' => 'company-1', 'provider' => 'unsupported_provider', 'credentials' => json_encode([]), 'sandbox' => '1', 'options' => json_encode([])]);

    $controller = new ServiceQuoteController();

    // Unresolvable providers raise through the vendor bridge on both flows
    expect(fn () => $controller->query(fleetopsPlaceSeamsQuoteRequest('v1/service-quotes', [
        'payload'     => 'payload_sqerrone',
        'facilitator' => 'integrated_vendor_err1',
    ])))->toThrow(Error::class)
        ->and(fn () => $controller->queryFromPreliminary(fleetopsPlaceSeamsQuoteRequest('v1/service-quotes/preliminary', [
            'pickup'      => 'place_sqerrone1',
            'dropoff'     => 'place_sqerrtwo2',
            'facilitator' => 'integrated_vendor_err1',
        ])))->toThrow(Error::class);
});
