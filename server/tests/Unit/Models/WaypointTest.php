<?php

if (!function_exists('Fleetbase\Traits\config')) {
    eval('namespace Fleetbase\Traits; function config($key = null, $default = null) { return $key === "api.cache.enabled" ? false : $default; }');
}

if (!function_exists('Fleetbase\Models\config')) {
    eval('namespace Fleetbase\Models; function config($key = null, $default = null) { return $key === "fleetbase.connection.db" ? "mysql" : $default; }');
}

if (!function_exists('Fleetbase\FleetOps\Models\session')) {
    eval('namespace Fleetbase\FleetOps\Models; function session($key = null) { return \FleetOpsWaypointSessionStore::get($key); }');
}

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Payload;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Models\Waypoint;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\LaravelMysqlSpatial\Types\Point;

class FleetOpsWaypointSessionStore
{
    public static array $data = [];

    public static function get(?string $key = null): mixed
    {
        return $key === null ? static::$data : (static::$data[$key] ?? null);
    }
}

class FleetOpsWaypointInsertFake extends Waypoint
{
    public static array $insertedValues       = [];
    public static array $createdTracking      = [];
    public static array $trackingNumberWrites = [];
    public static bool $insertResult          = true;

    public static function resetInsertFake(): void
    {
        static::$insertedValues       = [];
        static::$createdTracking      = [];
        static::$trackingNumberWrites = [];
        static::$insertResult         = true;
    }

    public function getFillable()
    {
        return array_merge(parent::getFillable(), ['meta']);
    }

    protected static function newUuid(): string
    {
        return 'waypoint-uuid';
    }

    protected static function newPublicId(): string
    {
        return 'waypoint_public';
    }

    protected static function currentTimestamp(): string
    {
        return '2026-08-04 11:22:33';
    }

    protected static function insertWaypoint(array $values): bool
    {
        static::$insertedValues[] = $values;

        return static::$insertResult;
    }

    protected static function createTrackingNumber(array $values)
    {
        static::$createdTracking[] = $values;

        return 'tracking-uuid';
    }

    protected static function pickupLocationWkt(Payload $payload): string
    {
        return 'POINT(55.2708 25.2048)';
    }

    protected static function updateTrackingNumberUuid(string $uuid, mixed $trackingNumberId): void
    {
        static::$trackingNumberWrites[] = [$uuid, $trackingNumberId];
    }
}

class FleetOpsWaypointPayloadFake extends Payload
{
    public function getPickupRegion(): string
    {
        return 'AE';
    }

    public function getPickupLocation()
    {
        return new Point(25.2048, 55.2708);
    }
}

class FleetOpsWaypointQueryFake
{
    public array $calls = [];

    public function __construct(public ?Waypoint $result = null)
    {
    }

    public function select(array $columns): self
    {
        $this->calls[] = ['select', $columns];

        return $this;
    }

    public function where(string $column, mixed $value): self
    {
        $this->calls[] = ['where', $column, $value];

        return $this;
    }

    public function whereHas(string $relation, Closure $callback): self
    {
        $nested = new self();
        $callback($nested);

        $this->calls[] = ['whereHas', $relation, $nested->calls];

        return $this;
    }

    public function first(): ?Waypoint
    {
        $this->calls[] = ['first'];

        return $this->result;
    }
}

class FleetOpsWaypointFindFake extends Waypoint
{
    public static ?FleetOpsWaypointQueryFake $query = null;
    public static array $withCalls                  = [];

    public static function resetFindFake(?Waypoint $result = null): void
    {
        static::$query     = new FleetOpsWaypointQueryFake($result);
        static::$withCalls = [];
    }

    public static function with($relations)
    {
        static::$withCalls[] = $relations;

        return static::$query;
    }
}

beforeEach(function () {
    FleetOpsWaypointInsertFake::resetInsertFake();
    FleetOpsWaypointFindFake::resetFindFake(new FleetOpsWaypointFindFake());
    FleetOpsWaypointSessionStore::$data = [];
});

test('waypoint insert filters values and creates payload tracking number', function () {
    FleetOpsWaypointSessionStore::$data = [
        'api_key' => 'api-key-uuid',
        'company' => 'company-uuid',
    ];

    $payload = new FleetOpsWaypointPayloadFake();
    $payload->setRawAttributes(['uuid' => 'payload-uuid'], true);

    $uuid = FleetOpsWaypointInsertFake::insertGetUuid([
        'place_uuid'   => 'place-uuid',
        'type'         => 'dropoff',
        'order'        => 2,
        'meta'         => ['source' => 'api'],
        'not_allowed'  => 'ignored',
        'pod_required' => true,
    ], $payload);

    expect($uuid)->toBe('waypoint-uuid')
        ->and(FleetOpsWaypointInsertFake::$insertedValues)->toHaveCount(1);

    $inserted = FleetOpsWaypointInsertFake::$insertedValues[0];

    expect($inserted)->toMatchArray([
        'uuid'         => 'waypoint-uuid',
        'public_id'    => 'waypoint_public',
        '_key'         => 'api-key-uuid',
        'created_at'   => '2026-08-04 11:22:33',
        'company_uuid' => 'company-uuid',
        'payload_uuid' => 'payload-uuid',
        'place_uuid'   => 'place-uuid',
        'type'         => 'dropoff',
        'order'        => 2,
        'meta'         => '{"source":"api"}',
        'pod_required' => true,
    ])->and($inserted)->not->toHaveKey('not_allowed')
        ->and(FleetOpsWaypointInsertFake::$createdTracking)->toHaveCount(1)
        ->and(FleetOpsWaypointInsertFake::$createdTracking[0])->toMatchArray([
            'owner_uuid' => 'waypoint-uuid',
            'owner_type' => Utils::getModelClassName('waypoint'),
            'region'     => 'AE',
        ])
        ->and(FleetOpsWaypointInsertFake::$createdTracking[0]['location'])->toBe('POINT(55.2708 25.2048)')
        ->and(FleetOpsWaypointInsertFake::$trackingNumberWrites)->toBe([
            ['waypoint-uuid', 'tracking-uuid'],
        ]);
});

test('waypoint insert skips payload side effects when insert fails', function () {
    FleetOpsWaypointInsertFake::$insertResult = false;

    $payload = new FleetOpsWaypointPayloadFake();
    $payload->setRawAttributes(['uuid' => 'payload-uuid'], true);

    expect(FleetOpsWaypointInsertFake::insertGetUuid(['place_uuid' => 'place-uuid'], $payload))->toBeFalse()
        ->and(FleetOpsWaypointInsertFake::$insertedValues)->toHaveCount(1)
        ->and(FleetOpsWaypointInsertFake::$createdTracking)->toBe([])
        ->and(FleetOpsWaypointInsertFake::$trackingNumberWrites)->toBe([]);
});

test('waypoint find by place scopes lookups by payload and place identity', function () {
    $result = new FleetOpsWaypointFindFake();
    FleetOpsWaypointFindFake::resetFindFake($result);

    $order = new Order();
    $order->setRawAttributes(['payload_uuid' => 'payload-uuid'], true);

    $place = new Place();
    $place->setRawAttributes(['uuid' => 'place-uuid'], true);

    expect(FleetOpsWaypointFindFake::findByPlace($place, $order, ['place'], ['uuid']))->toBe($result)
        ->and(FleetOpsWaypointFindFake::$withCalls)->toBe([['place']])
        ->and(FleetOpsWaypointFindFake::$query->calls)->toBe([
            ['select', ['uuid']],
            ['where', 'payload_uuid', 'payload-uuid'],
            ['where', 'place_uuid', 'place-uuid'],
            ['first'],
        ]);

    FleetOpsWaypointFindFake::resetFindFake($result);

    $payload = new Payload();
    $payload->setRawAttributes(['uuid' => 'payload-uuid'], true);
    $uuidPlace = '11111111-1111-4111-8111-111111111111';

    expect(FleetOpsWaypointFindFake::findByPlace($uuidPlace, $payload))->toBe($result)
        ->and(FleetOpsWaypointFindFake::$query->calls)->toBe([
            ['select', ['*']],
            ['where', 'payload_uuid', 'payload-uuid'],
            ['where', 'place_uuid', $uuidPlace],
            ['first'],
        ]);

    FleetOpsWaypointFindFake::resetFindFake($result);

    expect(FleetOpsWaypointFindFake::findByPlace('place_public', $payload))->toBe($result)
        ->and(FleetOpsWaypointFindFake::$query->calls)->toBe([
            ['select', ['*']],
            ['where', 'payload_uuid', 'payload-uuid'],
            ['whereHas', 'place', [
                ['where', 'public_id', 'place_public'],
            ]],
            ['first'],
        ]);
});

test('waypoint find by place requires an order payload uuid', function () {
    $order = new Order();
    $place = new Place();
    $place->setRawAttributes(['uuid' => 'place-uuid'], true);

    expect(fn () => FleetOpsWaypointFindFake::findByPlace($place, $order))
        ->toThrow(InvalidArgumentException::class, 'Missing payload UUID for lookup.');
});
