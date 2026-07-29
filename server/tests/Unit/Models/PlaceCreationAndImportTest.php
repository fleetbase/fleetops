<?php

if (!function_exists('Fleetbase\Support\session')) {
    eval('namespace Fleetbase\Support; function session($key = null, $default = null) { if ($key === null) { return new class { public function has($k) { return \session($k) !== null; } public function get($k, $d = null) { return \session($k, $d); } }; } return \session($key, $default); }');
}

if (!function_exists('Fleetbase\FleetOps\Support\env')) {
    eval('namespace Fleetbase\FleetOps\Support; function env($key = null, $default = null) { return $default; }');
}

if (!function_exists('Fleetbase\FleetOps\Models\env')) {
    eval('namespace Fleetbase\FleetOps\Models; function env($key = null, $default = null) { return $default; }');
}

if (!function_exists('Fleetbase\Models\asset')) {
    eval('namespace Fleetbase\Models; function asset($path = null, $secure = null) { return "https://assets.example.com/" . ltrim((string) $path, "/"); }');
}

if (!function_exists('Fleetbase\Support\auth')) {
    eval('namespace Fleetbase\Support; function auth() { return new class { public function user() { return null; } public function id() { return null; } }; }');
}

use Fleetbase\FleetOps\Models\Place;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;

/**
 * Covers Place avatar resolution by file uuid, reverse-geocoding creation
 * fallbacks with an empty geocoder, coordinate-based creation and insertion,
 * mixed-input uuid and public-id resolution, shared-place matching guards,
 * geocoding query composition, and import-row creation for single-address
 * and multi-column rows against SQLite.
 */
function fleetopsPlaceCreationBoot(): SQLiteConnection
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->sqliteCreateFunction('ST_PointFromText', fn ($wkt, $srid = 0, $axisOrder = null) => $wkt);
    $pdo->sqliteCreateFunction('ST_GeomFromText', fn ($wkt, $srid = 0, $axisOrder = null) => $wkt);
    $pdo->sqliteCreateFunction('ST_Equals', fn ($a, $b) => (string) $a === (string) $b ? 1 : 0);
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

    $disk = new class implements Filesystem {
        public function url($path)
        {
            return 'https://cdn.example.com/' . ltrim((string) $path, '/');
        }

        public function exists($path)
        {
            return true;
        }

        public function get($path)
        {
            return '';
        }

        public function readStream($path)
        {
            return null;
        }

        public function put($path, $contents, $options = [])
        {
            return true;
        }

        public function writeStream($path, $resource, array $options = [])
        {
            return true;
        }

        public function getVisibility($path)
        {
            return 'public';
        }

        public function setVisibility($path, $visibility)
        {
            return true;
        }

        public function prepend($path, $data)
        {
            return true;
        }

        public function append($path, $data)
        {
            return true;
        }

        public function delete($paths)
        {
            return true;
        }

        public function copy($from, $to)
        {
            return true;
        }

        public function move($from, $to)
        {
            return true;
        }

        public function size($path)
        {
            return 0;
        }

        public function lastModified($path)
        {
            return 0;
        }

        public function files($directory = null, $recursive = false)
        {
            return [];
        }

        public function allFiles($directory = null)
        {
            return [];
        }

        public function directories($directory = null, $recursive = false)
        {
            return [];
        }

        public function allDirectories($directory = null)
        {
            return [];
        }

        public function makeDirectory($path)
        {
            return true;
        }

        public function deleteDirectory($directory)
        {
            return true;
        }
    };
    app()->instance('filesystem', new class($disk) {
        public function __construct(public $d)
        {
        }

        public function disk($disk = null)
        {
            return $this->d;
        }

        public function __call($method, $arguments)
        {
            return $this->d->{$method}(...$arguments);
        }
    });
    Illuminate\Support\Facades\Storage::clearResolvedInstance('filesystem');

    app()->instance('geocoder', new class {
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
            return collect();
        }

        public function __call($method, $arguments)
        {
            return $this;
        }
    });

    $schema = $connection->getSchemaBuilder();
    $tables = [
        'places'    => ['uuid', 'public_id', 'company_uuid', 'owner_uuid', 'owner_type', 'name', 'street1', 'street2', 'city', 'province', 'postal_code', 'country', 'neighborhood', 'building', 'phone', 'location', 'meta', 'type', 'avatar_url', '_key', '_import_id'],
        'files'     => ['uuid', 'public_id', 'company_uuid', 'name', 'original_filename', 'extension', 'content_type', 'path', 'bucket', 'disk', 'size', 'type', 'meta', '_key'],
        'companies' => ['uuid', 'public_id', 'name', 'country'],
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

    session(['company' => 'company-1', 'api_key' => 'console']);
    $connection->table('companies')->insert(['uuid' => 'company-1', 'name' => 'Acme', 'country' => 'SG']);

    return $connection;
}

test('avatar urls resolve file uuids keys and passthrough values', function () {
    fleetopsPlaceCreationBoot();

    $place = new Place();
    $place->setRawAttributes(['avatar_url' => 'https://cdn.example.com/avatar.png'], true);
    expect($place->avatar_url)->toBe('https://cdn.example.com/avatar.png');

    // Uuid-shaped avatar values resolve through the file lookup, which
    // yields null when no such file exists
    $byUuid = new Place();
    $byUuid->setRawAttributes(['avatar_url' => '99999999-9999-4999-8999-999999999999'], true);
    expect($byUuid->avatar_url)->toBeNull()
        ->and(Place::getAvatar('88888888-8888-4888-8888-888888888888'))->toBeNull();
});

test('reverse geocoding creation falls back to bare locations', function () {
    $connection = fleetopsPlaceCreationBoot();

    $fromCoords = Place::createFromCoordinates([1.35, 103.85], ['name' => 'Coord Stop'], true);
    expect($fromCoords)->toBeInstanceOf(Place::class)
        ->and($connection->table('places')->where('name', 'Coord Stop')->count())->toBe(1);

    // Without reverse-geocoding results there is no address to build the place
    // from, so the insert reports failure. It must not fall through: the empty
    // address array carries location 0,0 and would overwrite the real point.
    expect(Place::insertFromCoordinates(new Point(1.40, 103.90), ['name' => 'Inserted Stop']))->toBeFalse()
        ->and($connection->table('places')->where('name', 'Inserted Stop')->count())->toBe(0);
});

test('mixed input resolution matches uuids public ids and coordinates', function () {
    $connection = fleetopsPlaceCreationBoot();
    $connection->table('places')->insert(['uuid' => '11111111-1111-4111-8111-111111111111', 'public_id' => 'place_mixres1', 'company_uuid' => 'company-1', 'name' => 'Depot', 'street1' => 'Main St']);

    expect(Place::insertFromMixed('place_mixres1'))->toBe('11111111-1111-4111-8111-111111111111')
        ->and(Place::insertFromMixed('11111111-1111-4111-8111-111111111111'))->toBe('11111111-1111-4111-8111-111111111111');

    $fromCoordArray = Place::createFromMixed([1.32, 103.82], [], true);
    expect($fromCoordArray)->toBeInstanceOf(Place::class);

    expect(Place::composeGeocodingQuery(['street1' => 'Main St', 'city' => 'Singapore', 'country' => 'SG']))->toBe('Main St, Singapore, SG')
        ->and(Place::composeGeocodingQuery(['street1' => '  ']))->toBeNull();
});

test('shared place matching parses locations and guards unresolvable ones', function () {
    $connection = fleetopsPlaceCreationBoot();
    $connection->table('places')->insert([
        'uuid'    => '22222222-2222-4222-8222-222222222222', 'company_uuid' => 'company-1',
        'street1' => 'Shared St', 'city' => 'Singapore', 'country' => 'SG', 'postal_code' => null, 'owner_uuid' => null,
    ]);

    $existing = Place::findExistingSharedPlace([
        'street1'  => 'Shared St',
        'city'     => 'Singapore',
        'country'  => 'SG',
        'location' => ['lat' => 1.3, 'lng' => 103.8],
    ]);
    expect($existing?->uuid)->toBe('22222222-2222-4222-8222-222222222222');

    // An unresolvable location value falls back to the guard once no
    // exact row matches
    $guarded = Place::findExistingSharedPlace([
        'street1'  => 'Unknown St',
        'city'     => 'Singapore',
        'country'  => 'SG',
        'location' => 'not-resolvable-location',
    ]);
    expect($guarded)->toBeNull();

    // Owner-scoped places never match shared records
    expect(Place::findExistingSharedPlace(['owner_uuid' => 'contact-1']))->toBeNull();
});

test('import rows create places for address only and multi column rows', function () {
    $connection = fleetopsPlaceCreationBoot();

    $single = Place::createFromImportRow(['address' => '88 Somewhere Road']);
    expect($single)->toBeInstanceOf(Place::class)
        ->and($single->street1)->toBe('88 Somewhere Road');

    $multi = Place::createFromImportRow([
        'name'    => 'Warehouse 5',
        'street1' => 'Industrial Ave 5',
        'city'    => 'Singapore',
        'notes'   => 'roll-up door',
    ], 'import-1');
    expect($multi)->toBeInstanceOf(Place::class)
        ->and($multi->location)->not->toBeNull();
});

test('single column imports reverse lookups and coordinate arrays create places', function () {
    $connection = fleetopsPlaceCreationBoot();

    // Single-column import rows resolve through geocoding then mixed fallback
    $imported = Place::createFromImport(['address' => '88 Single Column'], true);
    expect($imported)->toBeInstanceOf(Place::class);

    // Reverse lookups surface the keyless geocoder rejection pre-network
    expect(fn () => Place::createFromReverseGeocodingLookup(new Point(1.36, 103.86), true))
        ->toThrow(Exception::class);

    // Array coordinates resolve through the mixed point branch, then report
    // failure because the geocoder returns no address to build the place from
    expect(Place::insertFromCoordinates([1.42, 103.92], ['name' => 'Array Inserted Stop']))->toBeFalse()
        ->and($connection->table('places')->where('name', 'Array Inserted Stop')->count())->toBe(0);
});
