<?php

if (!function_exists('Fleetbase\Traits\config')) {
    eval('namespace Fleetbase\Traits; function config($key = null, $default = null) { return $key === "api.cache.enabled" ? false : $default; }');
}

if (!function_exists('Fleetbase\Models\config')) {
    eval('namespace Fleetbase\Models; function config($key = null, $default = null) { return $key === "fleetbase.connection.db" ? "mysql" : $default; }');
}

use Fleetbase\FleetOps\Models\Entity;
use Fleetbase\FleetOps\Models\Payload;
use Fleetbase\FleetOps\Models\Place;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;

class FleetOpsEntityUnitDestinationFake extends Entity
{
    public bool $savedForTest = false;

    public function save(array $options = []): bool
    {
        $this->savedForTest = true;

        return true;
    }
}

class FleetOpsEntityUnitDatabaseProbe
{
    public function __construct(private SQLiteConnection $connection)
    {
    }

    public function connection(): SQLiteConnection
    {
        return $this->connection;
    }
}

function fleetopsEntityUnitUseConnection(): void
{
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
    $connection->statement('create table entities (uuid varchar(64) primary key, public_id varchar(64) null, internal_id varchar(64) null, _key varchar(64) null, company_uuid varchar(64) null, payload_uuid varchar(64) null, driver_assigned_uuid varchar(64) null, customer_uuid varchar(64) null, customer_type varchar(255) null, tracking_number_uuid varchar(64) null, destination_uuid varchar(64) null, supplier_uuid varchar(64) null, photo_uuid varchar(64) null, _import_id varchar(64) null, name varchar(255) null, type varchar(64) null, description text null, currency varchar(8) null, barcode text null, qr_code text null, weight decimal(12,2) null, weight_unit varchar(16) null, length decimal(12,2) null, width decimal(12,2) null, height decimal(12,2) null, dimensions_unit varchar(16) null, declared_value integer null, sku varchar(255) null, price integer null, sale_price integer null, meta text null, slug varchar(255) null, deleted_at datetime null, created_at datetime null, updated_at datetime null)');

    $resolver = new ConnectionResolver([
        'default' => $connection,
        'mysql'   => $connection,
    ]);

    $resolver->setDefaultConnection('mysql');
    EloquentModel::setConnectionResolver($resolver);
    app()->instance('db', new FleetOpsEntityUnitDatabaseProbe($connection));
    Illuminate\Support\Facades\DB::clearResolvedInstance('db');
    $connection->statement('create table if not exists places (id integer primary key autoincrement, uuid varchar(64) null, public_id varchar(64) null, company_uuid varchar(64) null, name varchar(255) null, meta text null, deleted_at datetime null, created_at datetime null, updated_at datetime null)');
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
}

function fleetopsEntityUnitPlace(string $uuid, string $publicId): Place
{
    $place = new Place();
    $place->setRawAttributes([
        'uuid'      => $uuid,
        'public_id' => $publicId,
    ], true);

    return $place;
}

test('entity destination resolution covers payload and entity fallback branches with optional save', function () {
    $pickup   = fleetopsEntityUnitPlace('11111111-1111-4111-8111-111111111111', 'place_pickup');
    $dropoff  = fleetopsEntityUnitPlace('22222222-2222-4222-8222-222222222222', 'place_dropoff');
    $waypoint = fleetopsEntityUnitPlace('33333333-3333-4333-8333-333333333333', 'place_waypoint');

    $payload = new Payload();
    $payload->setRelation('pickup', $pickup);
    $payload->setRelation('dropoff', $dropoff);
    $payload->setRelation('waypoints', collect([$waypoint]));

    $entitySaved = new FleetOpsEntityUnitDestinationFake();

    expect((new Entity())->setDestination(0, $payload, false)->destination_uuid)->toBe('33333333-3333-4333-8333-333333333333')
        ->and((new Entity())->setDestination('pickup', $payload, false)->destination_uuid)->toBe('11111111-1111-4111-8111-111111111111')
        ->and((new Entity())->setDestination('dropoff', $payload, false)->destination_uuid)->toBe('22222222-2222-4222-8222-222222222222')
        ->and((new Entity())->setDestination('place_waypoint', $payload, false)->destination_uuid)->toBe('33333333-3333-4333-8333-333333333333')
        ->and((new Entity())->setDestination('22222222-2222-4222-8222-222222222222', $payload, false)->destination_uuid)->toBe('22222222-2222-4222-8222-222222222222')
        ->and((new Entity())->setDestination('place_missing', $payload, false)->destination_uuid)->toBeNull()
        ->and($entitySaved->setDestination('missing', $payload, true))->toBe($entitySaved)
        ->and($entitySaved->savedForTest)->toBeTrue();
});

test('entity insert get uuid updates existing entities and inserts sanitized rows', function () {
    fleetopsEntityUnitUseConnection();
    app('session')->forget(['company', 'api_key']);

    Entity::query()->insert([
        'uuid'        => '11111111-1111-4111-8111-111111111111',
        'public_id'   => 'entity_existing',
        'internal_id' => 'ENT-EXIST',
    ]);

    $payload = new Payload();
    $payload->setRawAttributes(['uuid' => 'payload-uuid'], true);

    expect(Entity::insertGetUuid(['uuid' => '11111111-1111-4111-8111-111111111111'], $payload))->toBe('11111111-1111-4111-8111-111111111111')
        ->and(Entity::query()->where('uuid', '11111111-1111-4111-8111-111111111111')->value('payload_uuid'))->toBe('payload-uuid');

    $createdUuid = Entity::insertGetUuid([
        'name'                  => 'Inserted Parcel',
        'type'                  => 'Box',
        'meta'                  => ['fragile' => true],
        'not_a_fillable_column' => 'ignored',
    ]);

    $created = Entity::query()->where('uuid', $createdUuid)->first();

    expect($createdUuid)->toBeString()
        ->and($created)->toBeInstanceOf(Entity::class)
        ->and($created->public_id)->toStartWith('entity_')
        ->and($created->internal_id)->toBeString()
        ->and($created->name)->toBe('Inserted Parcel')
        ->and($created->type)->toBe('Box')
        ->and($created->meta)->toBe(['fragile' => true])
        ->and(array_key_exists('not_a_fillable_column', $created->getAttributes()))->toBeFalse();
});

test('entity destinations confirm places and resolve temp search uuids', function () {
    fleetopsEntityUnitUseConnection();
    $connection = EloquentModel::resolveConnection('mysql');
    $connection->table('places')->insert(['uuid' => 'place-search-1', 'company_uuid' => 'company-1', 'name' => 'Search Resolved', 'meta' => json_encode(['search_uuid' => '99999999-9999-4999-8999-999999999999'])]);

    $payload = new Payload();
    $payload->setRawAttributes(['uuid' => 'payload-entity-1'], true);
    $payload->setRelation('waypoints', collect());
    $payload->setRelation('pickup', null);
    $payload->setRelation('dropoff', null);

    $entity = new Entity();
    $entity->setRawAttributes(['uuid' => 'entity-search-1'], true);

    // A temp search uuid with no matching waypoint resolves through place metadata
    $entity->setDestination('99999999-9999-4999-8999-999999999999', $payload, false);
    expect($entity->destination_uuid)->toBe('place-search-1');
});
