<?php

if (!function_exists('Fleetbase\Traits\config')) {
    eval('namespace Fleetbase\Traits; function config($key = null, $default = null) { return $key === "api.cache.enabled" ? false : $default; }');
}

if (!function_exists('Fleetbase\Models\config')) {
    eval('namespace Fleetbase\Models; function config($key = null, $default = null) { return $key === "fleetbase.connection.db" ? "mysql" : $default; }');
}

if (!function_exists('Fleetbase\FleetOps\Models\event')) {
    eval('namespace Fleetbase\FleetOps\Models; function event($event = null) { \FleetOpsPayloadUnitEventRecorder::$events[] = $event; return $event; }');
}

use Fleetbase\FleetOps\Events\EntityActivityChanged;
use Fleetbase\FleetOps\Events\EntityCompleted;
use Fleetbase\FleetOps\Events\WaypointActivityChanged;
use Fleetbase\FleetOps\Events\WaypointCompleted;
use Fleetbase\FleetOps\Flow\Activity;
use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\FleetOps\Models\Entity;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Payload;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Models\Waypoint;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\SQLiteConnection;

class FleetOpsPayloadUnitRelationCountFake
{
    public function __construct(private readonly Payload $payload, private readonly string $relation)
    {
    }

    public function count(): int
    {
        return $this->payload->getRelation($this->relation)?->count() ?? 0;
    }
}

class FleetOpsPayloadUnitDatabaseProbe
{
    public function __construct(private SQLiteConnection $connection)
    {
    }

    public function connection(): SQLiteConnection
    {
        return $this->connection;
    }
}

class FleetOpsPayloadUnitOrderFake extends Order
{
    public bool $distanceAndTimeSet = false;

    public function setDistanceAndTime(array $options = []): Order
    {
        $this->distanceAndTimeSet = true;

        return $this;
    }
}

class FleetOpsPayloadUnitEventRecorder
{
    public static array $events = [];
}

class FleetOpsPayloadUnitPlaceFake extends Place
{
    public function getAddressAttribute(): ?string
    {
        return $this->attributes['address'] ?? null;
    }
}

class FleetOpsPayloadUnitActivityWaypointFake extends Waypoint
{
    public array $activityInserts = [];

    public function insertActivity(Activity $activity, $location = [], $proof = null): string
    {
        $this->activityInserts[] = [$activity->code, $location, $proof];

        return 'waypoint-activity-uuid';
    }
}

class FleetOpsPayloadUnitActivityEntityFake extends Entity
{
    public array $activityInserts = [];

    public function insertActivity(Activity $activity, $location = [], $proof = null): string
    {
        $this->activityInserts[] = [$activity->code, $location, $proof];

        return 'entity-activity-uuid';
    }
}

class FleetOpsPayloadUnitWaypointFake extends Waypoint
{
    public bool $completeForTest = false;

    public function __construct(private ?Place $placeForTest = null, array $attributes = [])
    {
        parent::__construct($attributes);
    }

    public function getPlace(): ?Place
    {
        return $this->placeForTest;
    }

    public function getCompleteAttribute(): bool
    {
        return $this->completeForTest;
    }
}

class FleetOpsPayloadUnitActivityFake extends Payload
{
    public array $loadedMissingRelations = [];

    public function loadMissing($relations)
    {
        $this->loadedMissingRelations[] = $relations;

        return $this;
    }
}

class FleetOpsPayloadUnitFake extends Payload
{
    public array $loadedMissingRelations = [];
    public array $loadedRelations        = [];
    public array $quietUpdates           = [];
    public bool $quietlySaved            = false;
    public bool $pickupIsDriverLocation  = false;
    public array $activityUpdates        = [];
    public array $currentWaypointWrites  = [];

    public function waypoints()
    {
        return new FleetOpsPayloadUnitRelationCountFake($this, 'waypoints');
    }

    public function entities()
    {
        return new FleetOpsPayloadUnitRelationCountFake($this, 'entities');
    }

    public function hasMeta($key): bool
    {
        return $key === 'pickup_is_driver_location' && $this->pickupIsDriverLocation;
    }

    public function loadMissing($relations)
    {
        $this->loadedMissingRelations[] = $relations;

        return $this;
    }

    public function load($relations)
    {
        $this->loadedRelations[] = $relations;

        return $this;
    }

    public function updateQuietly(array $attributes = [], array $options = [])
    {
        $this->quietUpdates[] = $attributes;
        $this->forceFill($attributes);

        return true;
    }

    public function saveQuietly(array $options = [])
    {
        $this->quietlySaved = true;

        return true;
    }

    public function updateWaypointActivity(?Activity $activity = null, $location = null, $proof = null)
    {
        $this->activityUpdates[] = [$activity?->code, $location, $proof];

        return $this;
    }

    public function setCurrentWaypoint(Place|Waypoint $destination, bool $save = true): Payload
    {
        if ($destination instanceof Waypoint) {
            $destination = $destination->getPlace();
        }

        $this->currentWaypointWrites[] = [$destination?->uuid, $save];
        $this->current_waypoint_uuid   = $destination?->uuid;

        return $this;
    }
}

function fleetopsPayloadUnitPlace(string $uuid, array $attributes = []): FleetOpsPayloadUnitPlaceFake
{
    $place = new FleetOpsPayloadUnitPlaceFake();
    $place->setRawAttributes(array_merge([
        'uuid'      => $uuid,
        'public_id' => 'place_' . str_replace('-', '_', $uuid),
        'name'      => 'Place ' . $uuid,
        'country'   => 'SG',
    ], $attributes), true);

    return $place;
}

function fleetopsPayloadUnitPayload(array $attributes = []): FleetOpsPayloadUnitFake
{
    $payload = new FleetOpsPayloadUnitFake();
    $payload->setRawAttributes(array_merge(['uuid' => 'payload-uuid'], $attributes), true);
    $payload->setRelation('pickup', null);
    $payload->setRelation('dropoff', null);
    $payload->setRelation('return', null);
    $payload->setRelation('order', null);
    $payload->setRelation('entities', collect());
    $payload->setRelation('waypoints', collect());
    $payload->setRelation('waypointMarkers', collect());

    return $payload;
}

function fleetopsPayloadUnitUseRelationConnection(): void
{
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
    $connection->getPdo()->sqliteCreateFunction('ST_GeomFromText', fn ($wkt) => $wkt, -1);
    $connection->getPdo()->sqliteCreateFunction('ST_Equals', fn () => 0, -1);
    $connection->statement('create table payloads (uuid varchar(64) primary key, current_waypoint_uuid varchar(64) null, pickup_uuid varchar(64) null, dropoff_uuid varchar(64) null, return_uuid varchar(64) null, deleted_at datetime null, created_at datetime null, updated_at datetime null)');
    $connection->statement('create table places (uuid varchar(64) primary key, public_id varchar(64) null, _key varchar(64) null, company_uuid varchar(64) null, owner_uuid varchar(64) null, owner_type varchar(255) null, name varchar(255) null, type varchar(64) null, street1 varchar(255) null, street2 varchar(255) null, city varchar(255) null, province varchar(255) null, postal_code varchar(64) null, neighborhood varchar(255) null, district varchar(255) null, building varchar(255) null, security_access_code varchar(255) null, country varchar(8) null, location text null, meta text null, phone varchar(64) null, deleted_at datetime null, created_at datetime null, updated_at datetime null)');
    $connection->statement('create table contacts (uuid varchar(64) primary key, public_id varchar(64) null, _key varchar(64) null, internal_id varchar(64) null, company_uuid varchar(64) null, user_uuid varchar(64) null, place_uuid varchar(64) null, photo_uuid varchar(64) null, name varchar(255) null, title varchar(255) null, email varchar(255) null, phone varchar(64) null, type varchar(64) null, notes text null, meta text null, slug varchar(255) null, deleted_at datetime null, created_at datetime null, updated_at datetime null)');
    $connection->statement('create table entities (uuid varchar(64) primary key, public_id varchar(64) null, internal_id varchar(64) null, _key varchar(64) null, company_uuid varchar(64) null, payload_uuid varchar(64) null, destination_uuid varchar(64) null, tracking_number_uuid varchar(64) null, name varchar(255) null, description text null, type varchar(64) null, meta text null, deleted_at datetime null, created_at datetime null, updated_at datetime null)');
    $connection->statement('create table waypoints (uuid varchar(64) primary key, public_id varchar(64) null, _key varchar(64) null, company_uuid varchar(64) null, payload_uuid varchar(64), place_uuid varchar(64), tracking_number_uuid varchar(64) null, customer_uuid varchar(64) null, customer_type varchar(255) null, type varchar(64) null, "order" integer null, time_window_start datetime null, time_window_end datetime null, service_time integer null, notes text null, pod_method varchar(64) null, pod_required integer null, deleted_at datetime null, created_at datetime null, updated_at datetime null)');
    $resolver   = new ConnectionResolver([
        'default' => $connection,
        'mysql'   => $connection,
    ]);

    $resolver->setDefaultConnection('mysql');
    EloquentModel::setConnectionResolver($resolver);
    app()->instance('db', new FleetOpsPayloadUnitDatabaseProbe($connection));
}

test('payload relationship contracts resolve expected relation types and models', function () {
    fleetopsPayloadUnitUseRelationConnection();

    $payload = new Payload();

    expect($payload->entities())->toBeInstanceOf(HasMany::class)
        ->and($payload->entities()->getRelated())->toBeInstanceOf(Entity::class)
        ->and($payload->waypointMarkers())->toBeInstanceOf(HasMany::class)
        ->and($payload->waypointMarkers()->getRelated())->toBeInstanceOf(Waypoint::class)
        ->and($payload->firstWaypointMarker())->toBeInstanceOf(HasOne::class)
        ->and($payload->firstWaypointMarker()->getRelated())->toBeInstanceOf(Waypoint::class)
        ->and($payload->lastWaypointMarker())->toBeInstanceOf(HasOne::class)
        ->and($payload->lastWaypointMarker()->getRelated())->toBeInstanceOf(Waypoint::class)
        ->and($payload->order())->toBeInstanceOf(HasOne::class)
        ->and($payload->order()->getRelated())->toBeInstanceOf(Order::class)
        ->and($payload->dropoff())->toBeInstanceOf(BelongsTo::class)
        ->and($payload->dropoff()->getRelated())->toBeInstanceOf(Place::class)
        ->and($payload->pickup())->toBeInstanceOf(BelongsTo::class)
        ->and($payload->pickup()->getRelated())->toBeInstanceOf(Place::class)
        ->and($payload->return())->toBeInstanceOf(BelongsTo::class)
        ->and($payload->return()->getRelated())->toBeInstanceOf(Place::class)
        ->and($payload->currentWaypoint())->toBeInstanceOf(BelongsTo::class)
        ->and($payload->currentWaypoint()->getRelated())->toBeInstanceOf(Place::class)
        ->and($payload->currentWaypointMarker())->toBeInstanceOf(BelongsTo::class)
        ->and($payload->currentWaypointMarker()->getRelated())->toBeInstanceOf(Waypoint::class)
        ->and($payload->waypoints())->toBeInstanceOf(HasManyThrough::class)
        ->and($payload->waypoints()->getRelated())->toBeInstanceOf(Place::class);
});

test('payload exposes endpoint names cod amount and relation count accessors', function () {
    $pickup   = fleetopsPayloadUnitPlace('pickup-uuid', ['address' => 'Pickup Address']);
    $dropoff  = fleetopsPayloadUnitPlace('dropoff-uuid', ['name' => null, 'street1' => 'Dropoff Street']);
    $return   = fleetopsPayloadUnitPlace('return-uuid', ['name' => 'Return Name']);
    $waypoint = fleetopsPayloadUnitPlace('waypoint-uuid', ['address' => 'Waypoint Address']);
    $payload  = fleetopsPayloadUnitPayload();
    $payload->setRelation('pickup', $pickup);
    $payload->setRelation('dropoff', $dropoff);
    $payload->setRelation('return', $return);
    $payload->setRelation('entities', collect(['box', 'crate']));
    $payload->setRelation('waypoints', collect([$waypoint]));

    $payload->cod_amount = '$1,234.56';

    expect($payload->dropoff_name)->toBe('Dropoff Street')
        ->and($payload->pickup_name)->toBe('Pickup Address')
        ->and($payload->return_name)->toBe('Return Name')
        ->and($payload->cod_amount)->toBe(123456)
        ->and($payload->total_entities)->toBe(2)
        ->and($payload->total_waypoints)->toBe(1);
});

test('payload resolves pickup and dropoff fallbacks from waypoints and driver-location meta', function () {
    $first   = fleetopsPayloadUnitPlace('11111111-1111-4111-8111-111111111111', ['address' => 'First Stop', 'country' => 'MY']);
    $last    = fleetopsPayloadUnitPlace('22222222-2222-4222-8222-222222222222', ['address' => 'Last Stop', 'country' => 'ID']);
    $dropoff = fleetopsPayloadUnitPlace('dropoff-uuid', ['address' => 'Dropoff']);
    $payload = fleetopsPayloadUnitPayload(['current_waypoint_uuid' => '22222222-2222-4222-8222-222222222222']);
    $payload->setRelation('waypoints', collect([$first, $last]));

    expect($payload->getPickupOrFirstWaypoint())->toBe($first)
        ->and($payload->getDropoffOrLastWaypoint())->toBe($last)
        ->and($payload->getPickupOrCurrentWaypoint())->toBe($last)
        ->and($payload->getPickupRegion())->toBe('ID')
        ->and($payload->getCountryCode())->toBe('ID');

    $payload = fleetopsPayloadUnitPayload();
    $payload->setRelation('dropoff', $dropoff);
    $payload->pickupIsDriverLocation = true;

    expect($payload->getPickupOrCurrentWaypoint())->toBe($dropoff);
});

test('payload resolves index pickup and dropoff fallbacks from waypoint marker places', function () {
    $firstPlace  = fleetopsPayloadUnitPlace('first-place-uuid');
    $lastPlace   = fleetopsPayloadUnitPlace('last-place-uuid');
    $pickup      = fleetopsPayloadUnitPlace('pickup-uuid');
    $dropoff     = fleetopsPayloadUnitPlace('dropoff-uuid');
    $firstMarker = new Waypoint();
    $lastMarker  = new Waypoint();

    $firstMarker->setRelation('place', $firstPlace);
    $lastMarker->setRelation('place', $lastPlace);

    $payload = fleetopsPayloadUnitPayload();
    $payload->setRelation('firstWaypointMarker', $firstMarker);
    $payload->setRelation('lastWaypointMarker', $lastMarker);

    expect($payload->index_pickup_place)->toBe($firstPlace)
        ->and($payload->index_dropoff_place)->toBe($lastPlace)
        ->and($payload->loadedMissingRelations)->toContain('firstWaypointMarker.place')
        ->and($payload->loadedMissingRelations)->toContain('lastWaypointMarker.place');

    $payload->setRelation('pickup', $pickup);
    $payload->setRelation('dropoff', $dropoff);

    expect($payload->index_pickup_place)->toBe($pickup)
        ->and($payload->index_dropoff_place)->toBe($dropoff);
});

test('payload composes stops and pickup locations with sensible fallbacks', function () {
    $pickup   = fleetopsPayloadUnitPlace('pickup-uuid', ['location' => new Point(1.23, 4.56)]);
    $dropoff  = fleetopsPayloadUnitPlace('dropoff-uuid');
    $waypoint = fleetopsPayloadUnitPlace('waypoint-uuid');
    $payload  = fleetopsPayloadUnitPayload();
    $payload->setRelation('pickup', $pickup);
    $payload->setRelation('dropoff', $dropoff);
    $payload->setRelation('waypoints', collect([$waypoint, ['uuid' => 'array-stop', 'name' => 'Array Stop']]));

    $stops = $payload->getAllStops()->values();

    expect($stops)->toHaveCount(4)
        ->and($stops[0])->toBe($pickup)
        ->and($stops[1])->toBe($dropoff)
        ->and($stops[2])->toBe($waypoint)
        ->and($stops[3])->toBeInstanceOf(Place::class)
        ->and($payload->getPickupLocation())->toBe($pickup->location);

    $empty = fleetopsPayloadUnitPayload();

    expect($empty->getPickupLocation())->toBeInstanceOf(Point::class);
});

test('payload removes places and invokes callbacks for single and bulk removals', function () {
    $payload = fleetopsPayloadUnitPayload([
        'pickup_uuid'  => 'pickup-uuid',
        'dropoff_uuid' => 'dropoff-uuid',
    ]);
    $payload->setRelation('pickup', fleetopsPayloadUnitPlace('pickup-uuid'));
    $payload->setRelation('dropoff', fleetopsPayloadUnitPlace('dropoff-uuid'));
    $callbacks = 0;

    $result = $payload->removePlace(['pickup', 'dropoff', 99], [
        'save'     => true,
        'callback' => function (Payload $payload) use (&$callbacks): void {
            $callbacks++;
            expect($payload)->toBeInstanceOf(Payload::class);
        },
    ]);

    expect($result)->toBe($payload)
        ->and($payload->pickup_uuid)->toBeNull()
        ->and($payload->dropoff_uuid)->toBeNull()
        ->and($payload->getRelation('pickup'))->toBeNull()
        ->and($payload->getRelation('dropoff'))->toBeNull()
        ->and($payload->quietUpdates)->toBe([['pickup_uuid' => null], ['dropoff_uuid' => null]])
        ->and($callbacks)->toBe(2);
});

test('payload real mutators remove waypoints set places and handle driver pickup meta', function () {
    fleetopsPayloadUnitUseRelationConnection();

    $pickup  = fleetopsPayloadUnitPlace('pickup-uuid');
    $dropoff = fleetopsPayloadUnitPlace('dropoff-uuid');
    $return  = fleetopsPayloadUnitPlace('return-uuid');

    $payload = new Payload();
    $payload->setRawAttributes(['uuid' => 'payload-uuid'], true);
    $payload->setRelation('waypoints', collect([fleetopsPayloadUnitPlace('waypoint-uuid')]));

    expect($payload->removeWaypoints())->toBe($payload)
        ->and($payload->getRelation('waypoints'))->toHaveCount(0);

    $callbacks = [];

    expect($payload->setPlace('pickup', $pickup, [
        'callback' => function ($place, Payload $payload) use (&$callbacks): void {
            $callbacks[] = [$place->uuid, $payload->pickup_uuid];
        },
    ]))->toBe($payload)
        ->and($payload->pickup_uuid)->toBe('pickup-uuid')
        ->and($payload->getRelation('pickup'))->toBe($pickup)
        ->and($callbacks)->toBe([['pickup-uuid', 'pickup-uuid']])
        ->and($payload->setDropoff($dropoff))->toBe($payload)
        ->and($payload->dropoff_uuid)->toBe('dropoff-uuid')
        ->and($payload->setReturn($return))->toBe($payload)
        ->and($payload->return_uuid)->toBe('return-uuid')
        ->and($payload->setPickup('[driver]'))->toBeNull()
        ->and($payload->getMeta('pickup_is_driver_location'))->toBeTrue();
});

test('payload real current waypoint and fallback helpers handle non uuid current destinations', function () {
    $pickup     = fleetopsPayloadUnitPlace('pickup-uuid', ['country' => 'TH']);
    $firstPlace = fleetopsPayloadUnitPlace('first-place-uuid', ['country' => 'MY']);
    $waypoint   = new FleetOpsPayloadUnitWaypointFake($firstPlace);
    $waypoint->setRawAttributes(['uuid' => 'waypoint-uuid', 'place_uuid' => 'first-place-uuid'], true);

    $payload = fleetopsPayloadUnitPayload([
        'current_waypoint_uuid' => 'not-a-uuid',
    ]);
    $payload->setRelation('waypoints', collect([$firstPlace]));

    expect($payload->getPickupOrCurrentWaypoint())->toBe($firstPlace);

    $payload->setRelation('pickup', $pickup);

    expect($payload->getPickupOrFirstWaypoint())->toBe($pickup)
        ->and($payload->getPickupOrCurrentWaypoint())->toBe($pickup)
        ->and($payload->getPickupRegion())->toBe('TH');

    $payload = new Payload();
    $payload->setRawAttributes([
        'uuid' => 'payload-uuid',
    ], true);

    expect($payload->setCurrentWaypoint($waypoint, false))->toBe($payload)
        ->and($payload->current_waypoint_uuid)->toBe('first-place-uuid');
});

test('payload updates waypoint markers by reusing resolving creating and assigning persisted rows', function () {
    fleetopsPayloadUnitUseRelationConnection();
    session(['company' => 'company-uuid', 'api_key' => 'api-key']);

    Payload::query()->insert(['uuid' => 'payload-uuid']);
    Place::query()->insert([
        ['uuid' => '11111111-1111-4111-8111-111111111111', 'public_id' => 'place_EXIST1', 'name' => 'Existing Place', 'company_uuid' => 'company-uuid'],
        ['uuid' => '22222222-2222-4222-8222-222222222222', 'public_id' => 'place_PUBID1', 'name' => 'Public Place', 'company_uuid' => 'company-uuid'],
        ['uuid' => '33333333-3333-4333-8333-333333333333', 'public_id' => 'place_STALE1', 'name' => 'Stale Place', 'company_uuid' => 'company-uuid'],
    ]);
    Contact::query()->insert([
        ['uuid' => '44444444-4444-4444-8444-444444444444', 'public_id' => 'contact_PUBLIC1', 'name' => 'Existing Contact', 'company_uuid' => 'company-uuid'],
    ]);
    Waypoint::query()->insert([
        ['uuid' => '55555555-5555-4555-8555-555555555555', 'payload_uuid' => 'payload-uuid', 'place_uuid' => '11111111-1111-4111-8111-111111111111', 'type' => 'dropoff', 'order' => 5],
        ['uuid' => '66666666-6666-4666-8666-666666666666', 'payload_uuid' => 'payload-uuid', 'place_uuid' => '33333333-3333-4333-8333-333333333333', 'type' => 'dropoff', 'order' => 6],
    ]);

    $payload = Payload::query()->where('uuid', 'payload-uuid')->first();

    $result = $payload->updateWaypoints([
        [
            'place_uuid'    => '11111111-1111-4111-8111-111111111111',
            'type'          => 'pickup',
            'customer_uuid' => '44444444-4444-4444-8444-444444444444',
        ],
        [
            'id'          => 'place_PUBID1',
            'type'        => 'dropoff',
            'customer_id' => 'contact_PUBLIC1',
        ],
        [
            'place' => [
                'name'     => 'Created Stop',
                'location' => [0, 0],
            ],
            'type'        => 'return',
            'customer_id' => 'contact_MISSING1',
        ],
    ]);

    $rows         = Waypoint::query()->whereNull('deleted_at')->orderBy('order')->get()->keyBy('place_uuid');
    $createdPlace = Place::query()->where('name', 'Created Stop')->first();

    expect($result)->toBeInstanceOf(Payload::class)
        ->and($rows->has('11111111-1111-4111-8111-111111111111'))->toBeTrue()
        ->and($rows->has('22222222-2222-4222-8222-222222222222'))->toBeTrue()
        ->and($rows['11111111-1111-4111-8111-111111111111']->uuid)->toBe('55555555-5555-4555-8555-555555555555')
        ->and($rows['11111111-1111-4111-8111-111111111111']->type)->toBe('pickup')
        ->and($rows['11111111-1111-4111-8111-111111111111']->customer_uuid)->toBe('44444444-4444-4444-8444-444444444444')
        ->and($rows['22222222-2222-4222-8222-222222222222']->type)->toBe('dropoff')
        ->and($rows['22222222-2222-4222-8222-222222222222']->customer_uuid)->toBe('44444444-4444-4444-8444-444444444444')
        ->and($createdPlace)->toBeInstanceOf(Place::class)
        ->and($rows[$createdPlace->uuid]->type)->toBe('return')
        ->and($rows[$createdPlace->uuid]->customer_uuid)->toBeNull();
});

test('payload sets waypoint markers from explicit uuids public ids nested places and customer lookups', function () {
    fleetopsPayloadUnitUseRelationConnection();
    session(['company' => 'company-uuid', 'api_key' => 'api-key']);

    Payload::query()->insert(['uuid' => 'payload-uuid']);
    Place::query()->insert([
        ['uuid' => '11111111-1111-4111-8111-111111111111', 'public_id' => 'place_EXIST1', 'name' => 'Existing Place', 'company_uuid' => 'company-uuid'],
        ['uuid' => '22222222-2222-4222-8222-222222222222', 'public_id' => 'place_UUID1', 'name' => 'Uuid Place', 'company_uuid' => 'company-uuid'],
        ['uuid' => '33333333-3333-4333-8333-333333333333', 'public_id' => 'place_PUBLIC1', 'name' => 'Public Place', 'company_uuid' => 'company-uuid'],
    ]);
    Contact::query()->insert([
        ['uuid' => '44444444-4444-4444-8444-444444444444', 'public_id' => 'contact_UUID1', 'name' => 'Uuid Contact', 'company_uuid' => 'company-uuid'],
        ['uuid' => '55555555-5555-4555-8555-555555555555', 'public_id' => 'contact_PUBLIC1', 'name' => 'Public Contact', 'company_uuid' => 'company-uuid'],
    ]);

    $payload = Payload::query()->where('uuid', 'payload-uuid')->first();
    $payload->setRelation('waypointMarkers', collect());

    $result = $payload->setWaypoints([
        [
            'place_uuid'    => '11111111-1111-4111-8111-111111111111',
            'type'          => 'pickup',
            'customer_uuid' => '44444444-4444-4444-8444-444444444444',
        ],
        [
            'uuid'        => '22222222-2222-4222-8222-222222222222',
            'type'        => 'dropoff',
            'customer_id' => 'contact_PUBLIC1',
        ],
        [
            'id'          => 'place_PUBLIC1',
            'type'        => 'return',
            'customer_id' => 'contact_MISSING1',
        ],
        [
            'place' => [
                'name'     => 'Nested Created Stop',
                'location' => [1, 1],
            ],
            'type' => 'dropoff',
        ],
    ]);

    $rows         = Waypoint::query()->whereNull('deleted_at')->orderBy('order')->get()->keyBy('place_uuid');
    $createdPlace = Place::query()->where('name', 'Nested Created Stop')->first();

    expect($result)->toBe($payload)
        ->and($payload->getRelation('waypointMarkers'))->toHaveCount(4)
        ->and($rows['11111111-1111-4111-8111-111111111111']->type)->toBe('pickup')
        ->and($rows['11111111-1111-4111-8111-111111111111']->customer_uuid)->toBe('44444444-4444-4444-8444-444444444444')
        ->and($rows['22222222-2222-4222-8222-222222222222']->customer_uuid)->toBe('55555555-5555-4555-8555-555555555555')
        ->and($rows['33333333-3333-4333-8333-333333333333']->customer_uuid)->toBeNull()
        ->and($createdPlace)->toBeInstanceOf(Place::class)
        ->and($rows[$createdPlace->uuid]->type)->toBe('dropoff');
});

test('payload sets current first and next waypoint destinations without database writes in fakes', function () {
    $pickup                 = fleetopsPayloadUnitPlace('pickup-uuid');
    $firstPlace             = fleetopsPayloadUnitPlace('first-place-uuid');
    $secondPlace            = fleetopsPayloadUnitPlace('second-place-uuid');
    $first                  = new FleetOpsPayloadUnitWaypointFake($firstPlace);
    $second                 = new FleetOpsPayloadUnitWaypointFake($secondPlace);
    $first->completeForTest = true;
    $first->setRawAttributes(['uuid' => 'waypoint-one', 'place_uuid' => 'first-place-uuid'], true);
    $second->setRawAttributes(['uuid' => 'waypoint-two', 'place_uuid' => 'second-place-uuid'], true);

    $payload = fleetopsPayloadUnitPayload();
    $payload->setRelation('pickup', $pickup);
    $payload->setRelation('waypoints', collect([$firstPlace, $secondPlace]));
    $payload->setRelation('waypointMarkers', collect([$first, $second]));

    $activity = new Activity(['code' => 'started']);

    expect($payload->setFirstWaypoint($activity, 'point'))->toBe($payload)
        ->and($payload->current_waypoint_uuid)->toBe('pickup-uuid')
        ->and($payload->quietlySaved)->toBeTrue()
        ->and($payload->activityUpdates)->toBe([['started', 'point', null]])
        ->and($payload->loadedRelations)->toContain('currentWaypoint');

    $payload->current_waypoint_uuid = 'first-place-uuid';

    expect($payload->setNextWaypointDestination())->toBe($payload)
        ->and($payload->currentWaypointWrites)->toBe([['second-place-uuid', true]])
        ->and($payload->getRelation('currentWaypoint'))->toBe($secondPlace);

    $empty = fleetopsPayloadUnitPayload();

    expect($empty->setFirstWaypoint())->toBe($empty)
        ->and($empty->quietlySaved)->toBeFalse();

    $multipleDrop = fleetopsPayloadUnitPayload();
    $multipleDrop->setRelation('pickup', null);
    $multipleDrop->setRelation('waypoints', collect([$firstPlace, $secondPlace]));

    expect($multipleDrop->is_multiple_drop_order)->toBeTrue()
        ->and($multipleDrop->setFirstWaypoint())->toBe($multipleDrop)
        ->and($multipleDrop->current_waypoint_uuid)->toBe('first-place-uuid');
});

test('payload resolves order distance updates and destination keys', function () {
    $pickup   = fleetopsPayloadUnitPlace('33333333-3333-4333-8333-333333333333', ['public_id' => 'place_PICKUP1']);
    $dropoff  = fleetopsPayloadUnitPlace('44444444-4444-4444-8444-444444444444', ['public_id' => 'place_DROPOF1']);
    $waypoint = fleetopsPayloadUnitPlace('11111111-1111-4111-8111-111111111111', ['public_id' => 'place_waypoint']);
    $order    = new FleetOpsPayloadUnitOrderFake();
    $payload  = fleetopsPayloadUnitPayload();
    $payload->setRelation('pickup', $pickup);
    $payload->setRelation('dropoff', $dropoff);
    $payload->setRelation('waypoints', collect([$waypoint]));
    $payload->setRelation('order', $order);

    expect($payload->getOrder())->toBe($order)
        ->and($payload->updateOrderDistanceAndTime())->toBe($order)
        ->and($order->distanceAndTimeSet)->toBeTrue()
        ->and($payload->findDestinationFromKey(null))->toBeNull()
        ->and($payload->findDestinationFromKey('0'))->toBe($waypoint)
        ->and($payload->findDestinationFromKey('pickup'))->toBe($pickup)
        ->and($payload->findDestinationFromKey('dropoff'))->toBe($dropoff)
        ->and($payload->findDestinationFromKey('place_waypoint'))->toBe($waypoint)
        ->and($payload->findDestinationFromKey('place_PICKUP1'))->toBe($pickup)
        ->and($payload->findDestinationFromKey('place_DROPOF1'))->toBe($dropoff)
        ->and($payload->findDestinationFromKey('11111111-1111-4111-8111-111111111111'))->toBe($waypoint)
        ->and($payload->findDestinationFromKey('33333333-3333-4333-8333-333333333333'))->toBe($pickup)
        ->and($payload->findDestinationFromKey('44444444-4444-4444-8444-444444444444'))->toBe($dropoff)
        ->and($payload->findDestinationFromKey('missing'))->toBeNull();

    $payloadWithoutOrder = fleetopsPayloadUnitPayload();

    expect($payloadWithoutOrder->getOrder())->toBeNull()
        ->and($payloadWithoutOrder->loadedRelations)->toContain('order')
        ->and($payloadWithoutOrder->updateOrderDistanceAndTime())->toBeNull();
});

test('payload updates waypoint activity for multiple drop orders and dispatches lifecycle events', function () {
    FleetOpsPayloadUnitEventRecorder::$events = [];

    $order = new Order();
    $order->setRawAttributes(['uuid' => 'order-uuid'], true);

    $currentWaypoint = new FleetOpsPayloadUnitActivityWaypointFake();
    $currentWaypoint->setRawAttributes([
        'uuid'       => 'waypoint-uuid',
        'place_uuid' => 'current-place-uuid',
    ], true);

    $entity = new FleetOpsPayloadUnitActivityEntityFake();
    $entity->setRawAttributes([
        'uuid'             => 'entity-uuid',
        'destination_uuid' => 'current-place-uuid',
    ], true);

    $payload = new FleetOpsPayloadUnitActivityFake();
    $payload->setRawAttributes([
        'uuid'                  => 'payload-uuid',
        'current_waypoint_uuid' => 'current-place-uuid',
    ], true);
    $payload->setRelation('pickup', null);
    $payload->setRelation('order', $order);
    $payload->setRelation('waypoints', collect([fleetopsPayloadUnitPlace('current-place-uuid')]));
    $payload->setRelation('waypointMarkers', collect([$currentWaypoint]));
    $payload->setRelation('entities', collect([$entity]));

    $activity = new Activity(['code' => 'arrived', 'complete' => false]);

    expect($payload->updateWaypointActivity($activity, 'point', 'proof'))->toBe($payload)
        ->and($payload->loadedMissingRelations)->toBe(['order'])
        ->and($currentWaypoint->activityInserts)->toBe([['arrived', 'point', 'proof']])
        ->and($entity->activityInserts)->toBe([['arrived', 'point', 'proof']])
        ->and(array_map(fn ($event) => $event::class, FleetOpsPayloadUnitEventRecorder::$events))->toBe([
            EntityActivityChanged::class,
            WaypointActivityChanged::class,
        ]);

    FleetOpsPayloadUnitEventRecorder::$events = [];
    $currentWaypoint->activityInserts         = [];
    $entity->activityInserts                  = [];

    $payload->updateWaypointActivity(new Activity(['code' => 'delivered', 'complete' => true]), 'dropoff', null);

    expect($currentWaypoint->activityInserts)->toBe([['delivered', 'dropoff', null]])
        ->and($entity->activityInserts)->toBe([['delivered', 'dropoff', null]])
        ->and(array_map(fn ($event) => $event::class, FleetOpsPayloadUnitEventRecorder::$events))->toBe([
            EntityCompleted::class,
            WaypointCompleted::class,
        ]);
});
