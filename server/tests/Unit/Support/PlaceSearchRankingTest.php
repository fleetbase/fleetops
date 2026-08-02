<?php

if (!function_exists('Fleetbase\Support\session')) {
    eval('namespace Fleetbase\Support; function session($key = null, $default = null) { if ($key === null) { return new class { public function has($k) { return \session($k) !== null; } public function get($k, $d = null) { return \session($k, $d); } }; } return \session($key, $default); }');
}

if (!function_exists('Fleetbase\FleetOps\Support\env')) {
    eval('namespace Fleetbase\FleetOps\Support; function env($key = null, $default = null) { return $default; }');
}

use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Support\Geocoding;
use Fleetbase\FleetOps\Support\PlaceSearch;
use Geocoder\Model\AdminLevelCollection;
use Geocoder\Provider\GoogleMaps\Model\GoogleAddress;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;

/**
 * Covers PlaceSearch saved-place searching with relevance ranking, distance
 * and latest orderings, geocode fallbacks through the facade including
 * failures, query ranking and strong-match helpers, and the Geocoding
 * geocoder construction plus place mapping from google addresses.
 */
function fleetopsPlaceSearchBoot(): SQLiteConnection
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->sqliteCreateFunction('ST_PointFromText', function ($wkt, $srid = 0, $axisOrder = null) {
        if (is_string($wkt) && sscanf($wkt, 'POINT(%f %f)', $lng, $lat) === 2) {
            return pack('V', 0) . pack('C', 1) . pack('V', 1) . pack('d', $lng) . pack('d', $lat);
        }

        return $wkt;
    });
    $pdo->sqliteCreateFunction('ST_GeomFromText', fn ($wkt, $srid = 0, $axisOrder = null) => $wkt);
    $pdo->sqliteCreateFunction('ST_X', fn ($value) => 103.8);
    $pdo->sqliteCreateFunction('ST_Y', fn ($value) => 1.3);
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
    $schema->create('places', function ($blueprint) {
        $blueprint->increments('id');
        foreach (['uuid', 'public_id', 'company_uuid', 'owner_uuid', 'name', 'street1', 'street2', 'city', 'province', 'postal_code', 'country', 'location', 'meta', 'type', '_key'] as $column) {
            $blueprint->string($column)->nullable();
        }
        $blueprint->timestamps();
        $blueprint->timestamp('deleted_at')->nullable();
    });

    session(['company' => 'company-1']);

    return $connection;
}

function fleetopsPlaceSearchWkb(float $lat, float $lng): string
{
    return pack('V', 0) . pack('C', 1) . pack('V', 1) . pack('d', $lng) . pack('d', $lat);
}

function fleetopsPlaceSearchGeocoderFake(bool $throws = false): void
{
    app()->instance('geocoder', new class($throws) {
        public function __construct(public bool $throws)
        {
        }

        public function geocode($query)
        {
            if ($this->throws) {
                throw new RuntimeException('geocoder down');
            }

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
}

test('saved place search ranks relevance and merges results', function () {
    $connection = fleetopsPlaceSearchBoot();
    fleetopsPlaceSearchGeocoderFake();
    $connection->table('places')->insert([
        ['uuid' => 'p-1', 'public_id' => 'place_rank1', 'company_uuid' => 'company-1', 'name' => 'Depot', 'street1' => 'Central Rd 1', 'city' => 'Singapore', 'location' => fleetopsPlaceSearchWkb(1.30, 103.80)],
        ['uuid' => 'p-2', 'public_id' => 'place_rank2', 'company_uuid' => 'company-1', 'name' => 'Depot Two', 'street1' => 'North Ave 2', 'city' => 'Singapore', 'location' => fleetopsPlaceSearchWkb(1.35, 103.85)],
        ['uuid' => 'p-3', 'public_id' => 'place_rank3', 'company_uuid' => 'company-1', 'name' => 'Unrelated', 'street1' => 'South St 3', 'city' => 'Jakarta', 'location' => fleetopsPlaceSearchWkb(1.40, 103.90)],
    ]);

    $results = PlaceSearch::search(Place::where('company_uuid', 'company-1'), 'depot', ['geo' => true, 'limit' => 5]);
    expect($results->count())->toBe(2)
        ->and($results->first()->name)->toBe('Depot');
});

test('no query searches honor latest distance and default orderings', function () {
    $connection = fleetopsPlaceSearchBoot();
    fleetopsPlaceSearchGeocoderFake();
    $connection->table('places')->insert([
        ['uuid' => 'p-1', 'public_id' => 'place_ord1', 'company_uuid' => 'company-1', 'name' => 'Alpha', 'location' => fleetopsPlaceSearchWkb(1.30, 103.80), 'created_at' => now()->subDay()],
        ['uuid' => 'p-2', 'public_id' => 'place_ord2', 'company_uuid' => 'company-1', 'name' => 'Beta', 'location' => fleetopsPlaceSearchWkb(1.31, 103.81), 'created_at' => now()],
    ]);

    $latest = PlaceSearch::search(Place::where('company_uuid', 'company-1'), null, ['no_query_order' => 'latest']);
    expect($latest->first()->name)->toBe('Beta');

    $default = PlaceSearch::search(Place::where('company_uuid', 'company-1'), null, []);
    expect($default->first()->name)->toBe('Beta');

    $nearby = PlaceSearch::search(Place::where('company_uuid', 'company-1'), null, ['latitude' => 1.30, 'longitude' => 103.80]);
    expect($nearby->count())->toBe(2);
});

test('geocode falls back through the facade and swallows failures', function () {
    fleetopsPlaceSearchBoot();
    fleetopsPlaceSearchGeocoderFake();

    expect(PlaceSearch::geocode(null))->toBeEmpty()
        ->and(PlaceSearch::geocode('somewhere'))->toBeEmpty();

    fleetopsPlaceSearchGeocoderFake(true);
    expect(PlaceSearch::geocode('somewhere'))->toBeEmpty();
});

test('query ranking and strong match helpers compare normalized values', function () {
    fleetopsPlaceSearchBoot();

    $place = new Place();
    $place->setRawAttributes(['uuid' => 'p-1', 'name' => 'Depot', 'street1' => 'Central Rd 1'], true);

    $rank = new ReflectionMethod(PlaceSearch::class, 'placeQueryRank');
    $rank->setAccessible(true);
    expect($rank->invoke(null, $place, 'depot'))->toBe(0)
        ->and($rank->invoke(null, $place, 'dep'))->toBe(1)
        ->and($rank->invoke(null, $place, 'tral rd'))->toBe(2)
        ->and($rank->invoke(null, $place, 'zzz'))->toBe(3)
        ->and($rank->invoke(null, $place, null))->toBe(4);

    $ranked = new ReflectionMethod(PlaceSearch::class, 'rankPlacesByQuery');
    $ranked->setAccessible(true);
    expect($ranked->invoke(null, collect([$place]), null)->count())->toBe(1);

    $strong = new ReflectionMethod(PlaceSearch::class, 'isStrongSavedMatch');
    $strong->setAccessible(true);
    expect($strong->invoke(null, $place, 'depot'))->toBeTrue()
        ->and($strong->invoke(null, $place, 'other'))->toBeFalse()
        ->and($strong->invoke(null, $place, null))->toBeFalse();
});

test('geocoding builds geocoders and maps google addresses to places', function () {
    fleetopsPlaceSearchBoot();
    config()->set('services.google_maps.api_key', 'test-key');

    expect(Geocoding::canGoogleGeocode())->toBeTrue();

    $make = new ReflectionMethod(Geocoding::class, 'makeGeocoder');
    $make->setAccessible(true);
    expect($make->invoke(null))->toBeObject();

    $address = new GoogleAddress('google', new AdminLevelCollection());
    $mapper  = new ReflectionMethod(Geocoding::class, 'makePlaceFromGoogleAddress');
    $mapper->setAccessible(true);
    expect($mapper->invoke(null, $address))->toBeInstanceOf(Place::class);
});
