<?php

use Fleetbase\FleetOps\Models\Place;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;

/**
 * Covers the Place model's shared-place deduplication, uuid insertion,
 * geocoding lookup fallbacks, coordinate-based creation, and avatar
 * resolution against an in-memory SQLite fixture with a geocoder fake.
 */
class FleetOpsPlaceGeocoderFake
{
    public $results;

    public function __construct()
    {
        $this->results = collect();
    }

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
        return $this->results;
    }
}

function fleetopsPlaceSharedBoot(): SQLiteConnection
{
    $pdo = new PDO('sqlite::memory:');
    // Store points as package WKB so models rehydrate them on read back
    $wkbPoint = function ($wkt) {
        if (is_string($wkt) && sscanf($wkt, 'POINT(%f %f)', $lng, $lat) === 2) {
            return pack('V', 0) . pack('C', 1) . pack('V', 1) . pack('d', $lng) . pack('d', $lat);
        }

        return $wkt;
    };
    $pdo->sqliteCreateFunction('ST_PointFromText', fn ($wkt, $srid = 0, $axisOrder = null) => $wkbPoint($wkt));
    $pdo->sqliteCreateFunction('ST_GeomFromText', fn ($wkt, $srid = 0, $axisOrder = null) => $wkbPoint($wkt));
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
    $geocoder = new FleetOpsPlaceGeocoderFake();
    app()->instance('geocoder', $geocoder);
    Geocoder\Laravel\Facades\Geocoder::clearResolvedInstances();
    Illuminate\Support\Facades\DB::clearResolvedInstance('db');

    $schema = $connection->getSchemaBuilder();
    $tables = [
        'places' => ['uuid', 'public_id', 'company_uuid', 'owner_uuid', 'name', 'street1', 'street2', 'city', 'province', 'postal_code', 'country', 'location', 'meta', 'avatar_url', '_key'],
        'files'  => ['uuid', 'public_id', 'company_uuid', 'type', 'original_filename', 'path', 'bucket', 'disk'],
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

test('find existing shared place matches on normalized address fields', function () {
    $connection = fleetopsPlaceSharedBoot();
    $connection->table('places')->insert([
        'uuid'         => 'place-1',
        'company_uuid' => 'company-1',
        'owner_uuid'   => null,
        'street1'      => '1 Main Street',
        'city'         => 'Singapore',
        'country'      => 'SG',
        'postal_code'  => '018989',
    ]);

    // Owned places never dedupe
    expect(Place::findExistingSharedPlace(['owner_uuid' => 'owner-1']))->toBeNull();

    // Incomplete addresses never dedupe
    expect(Place::findExistingSharedPlace(['street1' => '1 Main Street']))->toBeNull();

    $match = Place::findExistingSharedPlace([
        'street1'     => '1 Main Street',
        'city'        => 'Singapore',
        'country'     => 'SG',
        'postal_code' => '018989',
    ]);
    expect($match)->toBeInstanceOf(Place::class)
        ->and($match->uuid)->toBe('place-1');

    // Unmatched postal codes fall through to the spatial comparison and miss
    $missed = Place::findExistingSharedPlace([
        'street1'     => '1 Main Street',
        'city'        => 'Singapore',
        'country'     => 'SG',
        'postal_code' => '999999',
        'location'    => new Point(1.3, 103.8),
    ]);
    expect($missed)->toBeNull();
});

test('insert get uuid persists filtered values and reuses shared places', function () {
    $connection = fleetopsPlaceSharedBoot();

    $uuid = Place::insertGetUuid([
        'name'          => 'Depot',
        'street1'       => '2 Harbor Road',
        'city'          => 'Singapore',
        'country'       => 'SG',
        'meta'          => ['zone' => 'port'],
        'not_a_column'  => 'dropped',
    ]);

    expect($uuid)->toBeString()
        ->and($connection->table('places')->count())->toBe(1)
        ->and($connection->table('places')->value('company_uuid'))->toBe('company-1')
        ->and($connection->table('places')->value('_key'))->toBe('console');
});

test('geocoding lookups fall back to plain street records when empty', function () {
    fleetopsPlaceSharedBoot();

    $place = Place::createFromGeocodingLookup('55 Unknown Road');
    expect($place)->toBeInstanceOf(Place::class)
        ->and($place->street1)->toBe('55 Unknown Road');

    $values = Place::getValuesFromGeocodingLookup('55 Unknown Road');
    expect($values['street1'])->toBe('55 Unknown Road')
        ->and($values['location'])->toBeInstanceOf(Point::class);
});

test('create from coordinates builds a located place without geocoder results', function () {
    $connection = fleetopsPlaceSharedBoot();

    $place = Place::createFromCoordinates(new Point(1.3521, 103.8198), ['name' => 'Marina'], true);

    expect($place)->toBeInstanceOf(Place::class)
        ->and($place->name)->toBe('Marina')
        ->and($connection->table('places')->count())->toBe(1);

    $fromArray = Place::createFromCoordinates(['latitude' => 1.29, 'longitude' => 103.85]);
    expect($fromArray)->toBeInstanceOf(Place::class);
});

test('avatar resolution covers urls uuids and named defaults', function () {
    config()->set('filesystems.default', 'local');
    config()->set('filesystems.disks.local', ['driver' => 'local', 'root' => sys_get_temp_dir()]);
    app()->instance('filesystem', new class {
        public function disk($name = null)
        {
            return new class {
                public function url($path)
                {
                    return 'https://files.example.test/' . ltrim((string) $path, '/');
                }

                public function __call($method, $arguments)
                {
                    return null;
                }
            };
        }

        public function __call($method, $arguments)
        {
            return null;
        }
    });
    Illuminate\Support\Facades\Storage::clearResolvedInstances();
    $connection = fleetopsPlaceSharedBoot();
    $connection->table('files')->insert(['uuid' => '77777777-7777-4777-8777-777777777777', 'type' => 'place-avatar', 'original_filename' => 'depot.png', 'path' => 'uploads/depot.png', 'disk' => 'local']);

    $place = new Place();
    $place->setRawAttributes(['uuid' => 'place-1'], true);

    // Raw urls pass straight through
    expect($place->getAvatarUrlAttribute('https://cdn.test/avatar.png'))->toBe('https://cdn.test/avatar.png');

    // Named defaults resolve through the avatar options
    expect($place->getAvatarUrlAttribute(null))->toBeString()
        ->and(Place::getAvatar('basic-building'))->toBeString();

    // Unknown uuid keys resolve to null
    expect(Place::getAvatar('88888888-8888-4888-8888-888888888888'))->toBeNull();
});

test('insert from coordinates reports failure when reverse geocoding is empty', function () {
    fleetopsPlaceSharedBoot();

    // The geocoder fake returns no results, so the insert reports failure
    // rather than trying to read an address off a missing result
    $point = new Point(1.30, 103.80);
    expect(Place::insertFromCoordinates($point))->toBeFalse();
});

test('insert get uuid parses locations and reuses an existing shared place', function () {
    $connection = fleetopsPlaceSharedBoot();

    // The first insert stores the parsed point alongside the address
    $first = Place::insertGetUuid([
        'name'     => 'Shared Depot',
        'street1'  => '5 Shared Way',
        'city'     => 'Singapore',
        'country'  => 'SG',
        'location' => new Point(1.30, 103.80),
    ]);

    expect($connection->table('places')->count())->toBe(1)
        ->and($connection->table('places')->value('location'))->not->toBeNull();

    // Re-inserting the same unowned address reuses the stored record instead
    // of creating a duplicate
    $second = Place::insertGetUuid([
        'name'     => 'Shared Depot Again',
        'street1'  => '5 Shared Way',
        'city'     => 'Singapore',
        'country'  => 'SG',
        'location' => new Point(1.30, 103.80),
    ]);

    expect($second)->toBe($first)
        ->and($connection->table('places')->count())->toBe(1);
});

test('shared place lookup degrades unparseable locations to the empty point', function () {
    fleetopsPlaceSharedBoot();

    // An unusable location value is caught and replaced with the zero point,
    // so the spatial fallback simply finds no match rather than erroring
    expect(Place::findExistingSharedPlace([
        'street1'     => '1 Main Street',
        'city'        => 'Singapore',
        'country'     => 'SG',
        'postal_code' => '999999',
        'location'    => 'not-a-point',
    ]))->toBeNull();
});
