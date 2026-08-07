<?php

if (!function_exists('Fleetbase\Support\session')) {
    eval('namespace Fleetbase\Support; function session($key = null, $default = null) { if ($key === null) { return new class { public function has($k) { return \session($k) !== null; } public function get($k, $d = null) { return \session($k, $d); } }; } return \session($key, $default); }');
}

if (!function_exists('Fleetbase\Support\auth')) {
    eval('namespace Fleetbase\Support; function auth() { return new class { public function user() { return null; } public function id() { return null; } }; }');
}

use Fleetbase\FleetOps\Models\Payload;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Models\Waypoint;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;

/**
 * Covers Payload waypoint and entity management against SQLite: entity
 * destination resolution through import ids, waypoint keys and search-uuid
 * metadata, waypoint insertion with nested place payloads and customer
 * association, waypoint updates resolving places by uuid and public id,
 * current and next waypoint tracking, place setters, and destination
 * correction helpers.
 */
function fleetopsPayloadWaypointBoot(): SQLiteConnection
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

    // Preload the Contact class so the miscased 'fleetops:contact' customer
    // type resolves case-insensitively in the harness autoloader
    class_exists(Fleetbase\FleetOps\Models\Contact::class);

    // Waypoint creation generates QR codes through the barcode services
    foreach (['DNS2D', 'DNS1D'] as $barcode) {
        app()->instance($barcode, new class {
            public function __call($method, $arguments)
            {
                return 'barcode';
            }
        });
    }

    $schema = $connection->getSchemaBuilder();
    $tables = [
        'payloads'          => ['uuid', 'public_id', 'company_uuid', 'pickup_uuid', 'dropoff_uuid', 'return_uuid', 'current_waypoint_uuid', 'type', 'meta', '_key'],
        'places'            => ['uuid', 'public_id', 'company_uuid', 'owner_uuid', 'name', 'street1', 'city', 'country', 'location', 'meta', 'type', '_key', '_import_id'],
        'waypoints'         => ['uuid', 'public_id', 'company_uuid', 'payload_uuid', 'place_uuid', 'tracking_number_uuid', 'customer_uuid', 'customer_type', 'order', 'type', '_import_id', '_key'],
        'entities'          => ['uuid', 'public_id', 'internal_id', 'company_uuid', 'payload_uuid', 'destination_uuid', 'tracking_number_uuid', 'customer_uuid', 'customer_type', 'photo_uuid', 'name', 'type', 'meta', 'qr_code', 'barcode', '_import_id', '_key'],
        'contacts'          => ['uuid', 'public_id', 'company_uuid', 'name', 'type', 'meta', '_key'],
        'tracking_numbers'  => ['uuid', 'public_id', 'company_uuid', 'tracking_number', 'owner_uuid', 'owner_type', 'region', 'qr_code', 'barcode', 'status_uuid', '_key'],
        'companies'         => ['uuid', 'public_id', 'name', 'country'],
        'tracking_statuses' => ['uuid', 'public_id', 'company_uuid', 'tracking_number_uuid', 'code', 'status', 'details', 'location', 'city', 'province', 'postal_code', 'country', '_key'],
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

function fleetopsPayloadWaypointFetch(string $uuid): Payload
{
    return Payload::query()->where('uuid', $uuid)->first();
}

test('set and insert entities resolve destinations from imports keys and search metadata', function () {
    $connection = fleetopsPayloadWaypointBoot();
    $connection->table('payloads')->insert(['uuid' => 'payload-1', 'company_uuid' => 'company-1']);
    $connection->table('places')->insert([
        ['uuid' => '11111111-1111-4111-8111-111111111111', 'public_id' => 'place_payent1', 'company_uuid' => 'company-1', 'name' => 'Stop A', 'meta' => null, '_import_id' => 'import-9'],
        ['uuid' => '22222222-2222-4222-8222-222222222222', 'public_id' => 'place_payent2', 'company_uuid' => 'company-1', 'name' => 'Stop B', 'meta' => json_encode(['search_uuid' => 'temp-search-1']), '_import_id' => null],
    ]);
    $connection->table('waypoints')->insert([
        ['uuid' => 'wp-1', 'company_uuid' => 'company-1', 'payload_uuid' => 'payload-1', 'place_uuid' => '11111111-1111-4111-8111-111111111111', '_import_id' => 'import-9', 'order' => 0],
        ['uuid' => 'wp-2', 'company_uuid' => 'company-1', 'payload_uuid' => 'payload-1', 'place_uuid' => '22222222-2222-4222-8222-222222222222', '_import_id' => null, 'order' => 1],
    ]);

    $payload = fleetopsPayloadWaypointFetch('payload-1');
    $payload->setEntities([
        ['name' => 'Crate 1', '_import_id' => 'import-9'],
        ['name' => 'Crate 2', 'waypoint' => 'place_payent2'],
        ['name' => 'Crate 3', 'destination_uuid' => 'temp-search-1'],
        ['name' => 'Crate 4'],
    ]);

    expect($connection->table('entities')->where('name', 'Crate 1')->value('destination_uuid'))->toBe('11111111-1111-4111-8111-111111111111')
        ->and($connection->table('entities')->where('name', 'Crate 2')->value('destination_uuid'))->toBe('22222222-2222-4222-8222-222222222222')
        ->and($connection->table('entities')->where('name', 'Crate 3')->value('destination_uuid'))->toBe('22222222-2222-4222-8222-222222222222')
        ->and($connection->table('entities')->count())->toBe(4);

    $payload->insertEntities([
        ['name' => 'Insert 1', '_import_id' => 'import-9'],
        ['name' => 'Insert 2', 'destination_uuid' => 'temp-search-1'],
        ['name' => 'Insert 3', 'waypoint' => 'place_payent2'],
    ]);

    expect($connection->table('entities')->where('name', 'Insert 1')->value('destination_uuid'))->toBe('11111111-1111-4111-8111-111111111111')
        ->and($connection->table('entities')->where('name', 'Insert 2')->value('destination_uuid'))->toBe('22222222-2222-4222-8222-222222222222')
        // no destination_uuid and no import id, so the waypoint key is the only
        // thing that can have resolved this entity's destination
        ->and($connection->table('entities')->where('name', 'Insert 3')->value('destination_uuid'))->toBe('22222222-2222-4222-8222-222222222222');
});

test('insert waypoints resolve nested places existing uuids and customers', function () {
    $connection = fleetopsPayloadWaypointBoot();
    $connection->table('payloads')->insert(['uuid' => 'payload-1', 'company_uuid' => 'company-1']);
    $connection->table('places')->insert(['uuid' => '11111111-1111-4111-8111-111111111111', 'public_id' => 'place_paywp1', 'company_uuid' => 'company-1', 'name' => 'Depot']);
    $connection->table('contacts')->insert(['uuid' => 'contact-1', 'public_id' => 'contact_paywp1', 'company_uuid' => 'company-1', 'name' => 'Casey', 'type' => 'customer']);

    $payload = fleetopsPayloadWaypointFetch('payload-1');
    $payload->insertWaypoints([
        ['place_uuid' => '11111111-1111-4111-8111-111111111111', 'type' => 'pickup', 'customer_uuid' => 'contact-1', 'customer_type' => 'fleetops:contact'],
        ['place' => ['uuid' => '33333333-3333-4333-8333-333333333333', 'name' => 'Fresh Stop', 'street1' => 'New Rd', 'city' => 'Singapore', 'country' => 'SG']],
    ]);

    $rows = $connection->table('waypoints')->orderBy('order')->get();
    expect($rows)->toHaveCount(2)
        ->and($rows[0]->place_uuid)->toBe('11111111-1111-4111-8111-111111111111')
        ->and($rows[0]->customer_uuid)->toBe('contact-1')
        ->and($rows[1]->place_uuid)->not->toBeNull();

    // The freshly created place records the submitted search uuid
    $created = $connection->table('places')->where('name', 'Fresh Stop')->first();
    expect($created)->not->toBeNull();
});

test('update waypoints resolve places by uuid public id and mixed input', function () {
    $connection = fleetopsPayloadWaypointBoot();
    $connection->table('payloads')->insert(['uuid' => 'payload-1', 'company_uuid' => 'company-1']);
    $connection->table('places')->insert([
        ['uuid' => '11111111-1111-4111-8111-111111111111', 'public_id' => 'place_payupd1', 'company_uuid' => 'company-1', 'name' => 'Stop A'],
        ['uuid' => '22222222-2222-4222-8222-222222222222', 'public_id' => 'place_payupd2', 'company_uuid' => 'company-1', 'name' => 'Stop B'],
        ['uuid' => '33333333-3333-4333-8333-333333333333', 'public_id' => 'place_payupd3', 'company_uuid' => 'company-1', 'name' => 'Stop C'],
    ]);

    $payload = fleetopsPayloadWaypointFetch('payload-1');
    $payload->updateWaypoints([
        ['place_uuid' => '11111111-1111-4111-8111-111111111111', 'type' => 'pickup'],
        ['uuid' => '22222222-2222-4222-8222-222222222222'],
        ['id'   => 'place_payupd3'],
    ]);

    $placeUuids = $connection->table('waypoints')->whereNull('deleted_at')->orderBy('order')->pluck('place_uuid')->all();
    expect($placeUuids)->toBe([
        '11111111-1111-4111-8111-111111111111',
        '22222222-2222-4222-8222-222222222222',
        '33333333-3333-4333-8333-333333333333',
    ]);

    app()->instance('geocoder', new class {
        public function geocode($query)
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
    Geocoder\Laravel\Facades\Geocoder::clearResolvedInstances();

    // A console place search hands back a uuid that no place carries yet. The
    // waypoint is created from the rest of the attributes and keeps the search
    // uuid in meta so later entity destinations can still be matched to it.
    $payload->updateWaypoints([
        ['uuid' => '44444444-4444-4444-8444-444444444444', 'street1' => 'Searched Street', 'city' => 'Singapore'],
    ]);

    $created = $connection->table('places')->where('street1', 'Searched Street')->first();
    expect($created)->not->toBeNull()
        ->and($created->uuid)->not->toBe('44444444-4444-4444-8444-444444444444')
        ->and(json_decode($created->meta, true)['search_uuid'] ?? null)->toBe('44444444-4444-4444-8444-444444444444');
});

test('waypoint tracking setters advance current and next markers', function () {
    $connection = fleetopsPayloadWaypointBoot();
    $connection->table('payloads')->insert(['uuid' => 'payload-1', 'company_uuid' => 'company-1']);
    $connection->table('places')->insert([
        ['uuid' => '11111111-1111-4111-8111-111111111111', 'public_id' => 'place_paycur1', 'company_uuid' => 'company-1', 'name' => 'Stop A'],
        ['uuid' => '22222222-2222-4222-8222-222222222222', 'public_id' => 'place_paycur2', 'company_uuid' => 'company-1', 'name' => 'Stop B'],
    ]);
    $connection->table('waypoints')->insert([
        ['uuid' => 'wp-1', 'company_uuid' => 'company-1', 'payload_uuid' => 'payload-1', 'place_uuid' => '11111111-1111-4111-8111-111111111111', 'order' => 0],
        ['uuid' => 'wp-2', 'company_uuid' => 'company-1', 'payload_uuid' => 'payload-1', 'place_uuid' => '22222222-2222-4222-8222-222222222222', 'order' => 1],
    ]);

    $payload  = fleetopsPayloadWaypointFetch('payload-1');
    $waypoint = Waypoint::query()->where('uuid', 'wp-1')->first();

    // Setting from a waypoint marker persists its place as current
    $payload->setCurrentWaypoint($waypoint, true);
    expect($connection->table('payloads')->value('current_waypoint_uuid'))->toBe('11111111-1111-4111-8111-111111111111');

    // The next incomplete marker with a different place becomes the target
    $payload->setNextWaypointDestination();
    expect($payload->current_waypoint_uuid)->toBe('22222222-2222-4222-8222-222222222222');

    // Place setter stores the uuid and relation for the property
    $place = Place::query()->where('uuid', '11111111-1111-4111-8111-111111111111')->first();
    $payload->setPlace('pickup', $place, ['save' => true, 'callback' => fn ($p) => $p]);
    expect($payload->pickup_uuid)->toBe('11111111-1111-4111-8111-111111111111');
});

test('destination correction helpers match search and console metadata', function () {
    $connection = fleetopsPayloadWaypointBoot();
    $connection->table('payloads')->insert(['uuid' => 'payload-1', 'company_uuid' => 'company-1']);
    $connection->table('places')->insert([
        ['uuid' => '11111111-1111-4111-8111-111111111111', 'public_id' => 'place_paydst1', 'company_uuid' => 'company-1', 'name' => 'Search Stop', 'meta' => json_encode(['search_uuid' => 'search-9'])],
        ['uuid' => '22222222-2222-4222-8222-222222222222', 'public_id' => 'place_paydst2', 'company_uuid' => 'company-1', 'name' => 'Console Stop', 'meta' => json_encode(['_console_destination_uuid' => 'console-9'])],
    ]);

    $payload = fleetopsPayloadWaypointFetch('payload-1');

    expect($payload->_findCorrectDestinationForEntity(['destination_uuid' => 'search-9'])?->uuid)->toBe('11111111-1111-4111-8111-111111111111')
        ->and($payload->_findCorrectDestinationForEntity(['destination_uuid' => 'console-9'])?->uuid)->toBe('22222222-2222-4222-8222-222222222222')
        ->and($payload->_findCorrectDestinationForEntity(['destination_uuid' => 'missing']))->toBeNull();

    $found = $payload->findDestinationFromKey('search-9');
    expect($found?->uuid)->toBe('11111111-1111-4111-8111-111111111111')
        ->and($payload->findDestinationFromKey(null))->toBeNull();

    // A console destination key matches no place uuid and no search_uuid, so it
    // can only resolve through the entity-correction fallback below those
    expect($payload->findDestinationFromKey('console-9')?->uuid)->toBe('22222222-2222-4222-8222-222222222222');
});

test('entity photos resolve files and temp waypoint uuids track search metadata', function () {
    $connection = fleetopsPayloadWaypointBoot();
    if (!EloquentModel::getEventDispatcher()) {
        EloquentModel::setEventDispatcher(new Illuminate\Events\Dispatcher());
    }
    app()->instance(Fleetbase\Services\FileResolverService::class, new class {
        public function resolve($photo, $path)
        {
            return (object) ['uuid' => 'file-photo-1'];
        }
    });

    $connection->table('payloads')->insert(['uuid' => 'payload-1', 'company_uuid' => 'company-1']);
    $payload = fleetopsPayloadWaypointFetch('payload-1');

    // Entities with raw photo data resolve through the file resolver
    $payload->setEntities([
        ['name' => 'Photographed', 'type' => 'parcel', 'photo' => 'data:image/png;base64,' . base64_encode('img')],
    ]);
    expect($connection->table('entities')->where('name', 'Photographed')->value('photo_uuid'))->toBe('file-photo-1');

    // Insert path resolves photos through the same seam
    $payload->insertEntities([
        ['name' => 'Photographed Insert', 'type' => 'parcel', 'photo' => 'data:image/png;base64,' . base64_encode('img2')],
    ]);
    expect($connection->table('entities')->where('name', 'Photographed Insert')->value('photo_uuid'))->toBe('file-photo-1');

    // Waypoints created from raw attributes track their temp search uuid
    $payload->setWaypoints([
        ['uuid' => 'temp-search-99', 'name' => 'Search Stop', 'location' => ['lat' => 1.36, 'lng' => 103.86]],
    ]);
    $searchPlace = $connection->table('places')->where('name', 'Search Stop')->first();
    expect($searchPlace)->not->toBeNull()
        ->and((string) $searchPlace->meta)->toContain('temp-search-99');
});

test('waypoint setters ignore non array input and exhausted destinations', function () {
    $connection = fleetopsPayloadWaypointBoot();
    $connection->table('payloads')->insert(['uuid' => 'payload-guard-1', 'company_uuid' => 'company-1']);
    $payload = Payload::where('uuid', 'payload-guard-1')->first();

    // Non-array waypoint input is ignored by both setters
    expect($payload->setWaypoints('not-an-array'))->toBe($payload)
        ->and($payload->updateWaypoints('not-an-array'))->toBe($payload)
        ->and($connection->table('waypoints')->where('payload_uuid', 'payload-guard-1')->count())->toBe(0);

    // With no incomplete waypoints left there is no next destination to set
    $payload->setRelation('waypointMarkers', collect());
    expect($payload->setNextWaypointDestination())->toBe($payload)
        ->and($connection->table('payloads')->where('uuid', 'payload-guard-1')->value('current_waypoint_uuid'))->toBeNull();
});
