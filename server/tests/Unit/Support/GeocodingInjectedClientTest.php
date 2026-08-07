<?php

if (!function_exists('Fleetbase\Support\session')) {
    eval('namespace Fleetbase\Support; function session($key = null, $default = null) { if ($key === null) { return new class { public function has($k) { return \session($k) !== null; } public function get($k, $d = null) { return \session($k, $d); } }; } return \session($key, $default); }');
}

if (!function_exists('Fleetbase\\FleetOps\\Support\\env')) {
    eval('namespace Fleetbase\\FleetOps\\Support; function env($key = null, $default = null) { return $default; }');
}

if (!function_exists('Fleetbase\\Observers\\event')) {
    eval('namespace Fleetbase\\Observers; function event($event = null, $payload = []) { return []; }');
}

use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Support\Geocoding;
use Fleetbase\FleetOps\Support\PlaceSearch;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Geocoder\Model\AddressCollection;
use Geocoder\Provider\GoogleMaps\Model\GoogleAddress;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;

/**
 * Covers the geocoding support paths through the injectable geocoder seam:
 * forward and reverse lookups mapping google addresses onto places, the
 * merged query flow, reverse-geocoded place creation, and the place-search
 * google branch — all without a live HTTP client.
 */
function fleetopsGeocodingInjectedBoot(): SQLiteConnection
{
    if (!Illuminate\Support\Str::hasMacro('humanize')) {
        Illuminate\Support\Str::macro('humanize', fn ($value, $uppercase = true) => str_replace('_', ' ', Illuminate\Support\Str::snake((string) $value)));
    }

    $pdo      = new PDO('sqlite::memory:');
    $wkbPoint = fn (float $lng, float $lat) => pack('V', 0) . pack('C', 1) . pack('V', 1) . pack('d', $lng) . pack('d', $lat);
    foreach (['ST_PointFromText', 'ST_GeomFromText'] as $fn) {
        $pdo->sqliteCreateFunction($fn, function ($wkt, $srid = 0, $axisOrder = null) use ($wkbPoint) {
            if (is_string($wkt) && sscanf($wkt, 'POINT(%f %f)', $lng, $lat) === 2) {
                return $wkbPoint($lng, $lat);
            }

            return $wkt;
        });
    }
    $connection = new SQLiteConnection($pdo);
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
    $schema->create('places', function ($blueprint) {
        $blueprint->increments('id');
        foreach (['uuid', 'public_id', 'company_uuid', 'owner_uuid', 'owner_type', 'name', 'street1', 'street2', 'city', 'province', 'postal_code', 'country', 'neighborhood', 'building', 'district', 'phone', 'location', 'meta', 'type', '_key', '_import_id'] as $column) {
            $blueprint->string($column)->nullable();
        }
        $blueprint->timestamps();
        $blueprint->timestamp('deleted_at')->nullable();
    });

    app()->instance('responsecache', new class {
        public function __call($method, $arguments)
        {
            return null;
        }
    });
    config()->set('activitylog.enabled', false);
    config()->set('activitylog.default_auth_driver', 'web');
    app()->bind(Illuminate\Contracts\Config\Repository::class, fn () => config());
    session(['company' => 'company-1']);

    $address = GoogleAddress::createFromArray([
        'providedBy'   => 'google',
        'latitude'     => 1.34,
        'longitude'    => 103.84,
        'streetNumber' => '88',
        'streetName'   => 'Injected Way',
        'locality'     => 'Singapore',
        'postalCode'   => '049080',
        'country'      => 'Singapore',
    ]);

    app()->instance('geocoder', new class($address) {
        public function __construct(public GoogleAddress $address)
        {
        }

        public function geocode($query)
        {
            return $this;
        }

        public function reverse($lat, $lng)
        {
            return $this;
        }

        public function get()
        {
            return collect([$this->address]);
        }

        public function __call($method, $arguments)
        {
            return $this;
        }
    });
    Geocoder\Laravel\Facades\Geocoder::clearResolvedInstance('geocoder');

    app()->instance('fleetops.geocoder', new class($address) {
        public array $queries = [];

        public function __construct(public GoogleAddress $address)
        {
        }

        public function geocodeQuery($query)
        {
            $this->queries[] = ['geocode', $query];

            return new AddressCollection([$this->address]);
        }

        public function reverseQuery($query)
        {
            $this->queries[] = ['reverse', $query];

            return new AddressCollection([$this->address]);
        }
    });

    return $connection;
}

test('injected geocoders resolve forward reverse and merged queries', function () {
    fleetopsGeocodingInjectedBoot();

    // Forward geocoding maps google addresses onto places
    $forward = Geocoding::geocode('88 Injected Way', 1.30, 103.80);
    expect($forward)->toHaveCount(1)
        ->and($forward->first())->toBeInstanceOf(Place::class)
        ->and($forward->first()->street1)->toContain('Injected Way');

    // Forward geocoding without a location bias uses the plain query mode
    expect(Geocoding::geocode('88 Injected Way'))->toHaveCount(1);

    // Reverse geocoding from coordinates and query-biased reverse lookups
    expect(Geocoding::reverseFromCoordinates(1.34, 103.84))->toHaveCount(1)
        ->and(Geocoding::reverseFromCoordinates(1.34, 103.84, '88 Injected Way'))->toHaveCount(1)
        ->and(Geocoding::reverseFromQuery('88 Injected Way', 1.34, 103.84))->toHaveCount(1);

    // The merged query flow dedupes by street
    expect(Geocoding::query('88 Injected Way', 1.34, 103.84))->toHaveCount(1);
});

test('reverse geocoded places and google place search use the injected client', function () {
    $connection = fleetopsGeocodingInjectedBoot();

    // Reverse-geocoded place creation persists the resolved address
    $place = Place::createFromReverseGeocodingLookup(new Point(1.34, 103.84), true);
    expect($place)->toBeInstanceOf(Place::class)
        ->and($connection->table('places')->count())->toBeGreaterThanOrEqual(1);

    // The google-capable place search branch ranks the injected results
    config()->set('services.google_maps.api_key', 'injected-test-key');
    $results = PlaceSearch::geocode('88 Injected Way', 1.34, 103.84);
    expect($results)->toHaveCount(1);

    $reverseOnly = PlaceSearch::geocode(null, 1.34, 103.84);
    expect($reverseOnly)->toHaveCount(1);
    config()->set('services.google_maps.api_key', null);
});

test('place creation flows consume injected geocoding results', function () {
    $connection = fleetopsGeocodingInjectedBoot();

    // Forward lookups resolve into google-address places
    $fromLookup = Place::createFromGeocodingLookup('88 Injected Way');
    expect($fromLookup)->toBeInstanceOf(Place::class)
        ->and($fromLookup->street1)->toContain('Injected Way');

    // Coordinate creation enriches the place from reverse results
    $fromCoordinates = Place::createFromCoordinates([1.34, 103.84], [], true);
    expect($fromCoordinates)->toBeInstanceOf(Place::class);

    // Single-column imports adopt the first geocoding result
    $imported = Place::createFromImport(['address' => '88 Injected Way'], true);
    expect($imported)->toBeInstanceOf(Place::class)
        ->and($connection->table('places')->count())->toBeGreaterThanOrEqual(1);
});

test('place search swallows geocoder failures and returns empty defaults', function () {
    fleetopsGeocodingInjectedBoot();

    // Failing injected geocoders are swallowed inside the google branch
    config()->set('services.google_maps.api_key', 'injected-test-key');
    app()->instance('fleetops.geocoder', new class {
        public function geocodeQuery($query)
        {
            throw new RuntimeException('geocode backend down');
        }

        public function reverseQuery($query)
        {
            throw new RuntimeException('reverse backend down');
        }
    });
    expect(PlaceSearch::geocode('88 Injected Way', 1.34, 103.84))->toHaveCount(0);

    // Without a google key and no query the search returns empty
    config()->set('services.google_maps.api_key', null);
    expect(PlaceSearch::geocode(null))->toHaveCount(0);

    // Failures in the fallback geocoder facade collapse to empty results
    app()->instance('geocoder', new class {
        public function geocode($query)
        {
            throw new RuntimeException('facade geocoder down');
        }

        public function __call($method, $arguments)
        {
            return $this;
        }
    });
    Geocoder\Laravel\Facades\Geocoder::clearResolvedInstance('geocoder');
    expect(PlaceSearch::geocode('anywhere'))->toHaveCount(0);
});

test('locale and region resolve independently from configuration', function () {
    // Google treats these as two different parameters: `language` decides what
    // language results come back in, `region` (a ccTLD) only biases ranking.
    // They are read from separate config keys so setting one cannot silently
    // change the other.
    config()->set('services.google_maps.locale', 'ru');
    config()->set('services.google_maps.region', 'sg');

    expect(Geocoding::getLocale())->toBe('ru')
        ->and(Geocoding::getRegion())->toBe('sg');

    // Unset, each falls back to its own documented default
    config()->set('services.google_maps.locale', null);
    config()->set('services.google_maps.region', null);

    expect(Geocoding::getLocale())->toBe('en')
        ->and(Geocoding::getRegion())->toBe('us');
});
