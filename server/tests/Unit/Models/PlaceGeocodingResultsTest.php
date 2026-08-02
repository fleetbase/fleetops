<?php

use Fleetbase\FleetOps\Models\Place;
use Fleetbase\LaravelMysqlSpatial\Types\Point as SpatialPoint;
use Geocoder\Model\AddressCollection;
use Geocoder\Provider\GoogleMaps\Model\GoogleAddress;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;

/**
 * Covers how places are built from geocoding results: a hit is mapped through
 * the Google address translation, while an empty reverse lookup falls back to
 * a bare place carrying only the requested point.
 */
if (!function_exists('Fleetbase\Support\session')) {
    eval('namespace Fleetbase\Support; function session($key = null, $default = null) { if ($key === null) { return new class { public function has($k) { return \session($k) !== null; } public function get($k, $d = null) { return \session($k, $d); } }; } return \session($key, $default); }');
}

function fleetopsPlaceGeocodeBoot(array $results): SQLiteConnection
{
    $pdo = new PDO('sqlite::memory:');
    foreach (['ST_PointFromText', 'ST_GeomFromText'] as $spatialFunction) {
        $pdo->sqliteCreateFunction($spatialFunction, fn ($wkt, $srid = 0, $axisOrder = null) => $wkt);
    }
    // The shared-place lookup compares locations spatially; string equality is
    // enough here because both sides are the same passthrough WKT
    $pdo->sqliteCreateFunction('ST_Equals', fn ($left, $right) => (int) ((string) $left === (string) $right));
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

    $connection->getSchemaBuilder()->create('places', function ($blueprint) {
        $blueprint->increments('id');
        foreach (['uuid', 'public_id', 'company_uuid', 'owner_uuid', 'owner_type', 'name', 'street1', 'street2', 'building', 'neighborhood', 'district', 'city', 'province', 'postal_code', 'country', 'phone', 'type', 'location', 'meta', '_key'] as $column) {
            $blueprint->string($column)->nullable();
        }
        $blueprint->timestamps();
        $blueprint->timestamp('deleted_at')->nullable();
    });

    $geocoder = new class($results) {
        public function __construct(private array $results)
        {
        }

        public function geocode($address)
        {
            return $this;
        }

        public function reverse($latitude, $longitude)
        {
            return $this;
        }

        public function geocodeQuery($query)
        {
            return new AddressCollection($this->results);
        }

        public function reverseQuery($query)
        {
            return new AddressCollection($this->results);
        }

        public function get()
        {
            return collect($this->results);
        }
    };

    app()->instance('redis', new class {
        public function connection(): self
        {
            return $this;
        }

        public function __call(string $method, array $arguments): mixed
        {
            return null;
        }
    });

    app()->instance('geocoder', $geocoder);
    app()->instance('fleetops.geocoder', $geocoder);
    Geocoder\Laravel\Facades\Geocoder::clearResolvedInstances();

    session(['company' => 'company-geo-1']);

    return $connection;
}

test('geocoding lookups map hits and fall back to the raw address', function () {
    $address = GoogleAddress::createFromArray([
        'streetNumber' => '1',
        'streetName'   => 'Marina Boulevard',
        'locality'     => 'Singapore',
        'postalCode'   => '018989',
        'country'      => 'Singapore',
        'countryCode'  => 'SG',
        'latitude'     => 1.2816,
        'longitude'    => 103.8636,
    ]);

    // A geocoding hit is translated into place attributes
    fleetopsPlaceGeocodeBoot([$address]);
    $values = Place::getValuesFromGeocodingLookup('1 Marina Boulevard');
    expect($values)->toBeArray()
        ->and($values['city'] ?? null)->toBe('Singapore')
        ->and($values['postal_code'] ?? null)->toBe('018989')
        ->and($values['country'] ?? null)->toBe('SG');

    // With no results the raw query is kept as the street and the point is empty
    fleetopsPlaceGeocodeBoot([]);
    $fallback = Place::getValuesFromGeocodingLookup('Nowhere Road');
    expect($fallback['street1'])->toBe('Nowhere Road')
        ->and($fallback['location'])->toBeInstanceOf(SpatialPoint::class);
});

test('reverse geocoding with no results yields a place holding just the point', function () {
    fleetopsPlaceGeocodeBoot([]);

    $point = new SpatialPoint(1.30, 103.80);
    $place = Place::createFromReverseGeocodingLookup($point);

    expect($place)->toBeInstanceOf(Place::class)
        ->and($place->location)->not->toBeNull();
});

test('inserting from coordinates persists the reverse geocoded address', function () {
    $address = GoogleAddress::createFromArray([
        'streetNumber' => '10',
        'streetName'   => 'Bayfront Avenue',
        'locality'     => 'Singapore',
        'postalCode'   => '018956',
        'country'      => 'Singapore',
        'countryCode'  => 'SG',
        'latitude'     => 1.2834,
        'longitude'    => 103.8607,
    ]);

    $connection = fleetopsPlaceGeocodeBoot([$address]);
    $uuid       = Place::insertFromCoordinates([1.2834, 103.8607]);

    expect($uuid)->toBeString();
    $row = $connection->table('places')->where('uuid', $uuid)->first();
    expect($row->city)->toBe('Singapore')
        ->and($row->postal_code)->toBe('018956')
        ->and($row->street1)->toBe('10 Bayfront Avenue');

    // No reverse geocoding result means nothing is inserted
    fleetopsPlaceGeocodeBoot([]);
    expect(Place::insertFromCoordinates([1.2834, 103.8607]))->toBeFalse();
});

test('inserting from a mixed address string geocodes and persists', function () {
    $address = GoogleAddress::createFromArray([
        'streetNumber' => '2',
        'streetName'   => 'Orchard Turn',
        'locality'     => 'Singapore',
        'postalCode'   => '238801',
        'country'      => 'Singapore',
        'countryCode'  => 'SG',
        'latitude'     => 1.3040,
        'longitude'    => 103.8318,
    ]);

    $connection = fleetopsPlaceGeocodeBoot([$address]);
    $uuid       = Place::insertFromMixed('2 Orchard Turn Singapore');

    expect($uuid)->toBeString();
    expect($connection->table('places')->where('uuid', $uuid)->value('postal_code'))->toBe('238801');
});

test('inserting from a google address routes through the google address handler', function () {
    $address = GoogleAddress::createFromArray([
        'streetNumber' => '12',
        'streetName'   => 'Marina View',
        'locality'     => 'Singapore',
        'postalCode'   => '018961',
        'country'      => 'Singapore',
        'countryCode'  => 'SG',
        'latitude'     => 1.2795,
        'longitude'    => 103.8543,
    ]);

    $connection = fleetopsPlaceGeocodeBoot([]);

    // A GoogleAddress is an object, so it must be matched before the generic
    // array/object arm — otherwise it gets flattened to an array and loses the
    // address translation entirely
    $uuid = Place::insertFromMixed($address);

    expect($uuid)->toBeString();
    $row = $connection->table('places')->where('uuid', $uuid)->first();
    expect($row->city)->toBe('Singapore')
        ->and($row->postal_code)->toBe('018961')
        ->and($row->street1)->toBe('12 Marina View');
});

test('shared place lookups bail out when the location cannot be resolved', function () {
    fleetopsPlaceGeocodeBoot([]);

    // An unresolvable place public id makes Utils::getPointFromMixed() return
    // null, so there is no point to match a shared place on
    $resolved = Place::findExistingSharedPlace([
        'company_uuid' => 'company-geo-1',
        'street1'      => '1 Nonexistent Way',
        'city'         => 'Singapore',
        'country'      => 'SG',
        'location'     => 'place_doesnotexist',
    ]);

    expect($resolved)->toBeNull();
});

test('import rows resolving to a coordinateless address fall back to the null island', function () {
    // A geocoding hit carrying no coordinates leaves the place without a
    // location, and the row supplies no latitude/longitude columns either
    $address = GoogleAddress::createFromArray([
        'streetNumber' => '5',
        'streetName'   => 'Raffles Place',
        'locality'     => 'Singapore',
        'country'      => 'Singapore',
        'countryCode'  => 'SG',
    ]);

    fleetopsPlaceGeocodeBoot([$address]);
    $place = Place::createFromImportRow(['street' => '5 Raffles Place', 'town' => 'Singapore']);

    expect($place)->toBeInstanceOf(Place::class)
        ->and($place->location)->toBeInstanceOf(SpatialPoint::class)
        ->and($place->location->getLat())->toBe(0.0)
        ->and($place->location->getLng())->toBe(0.0);
});
