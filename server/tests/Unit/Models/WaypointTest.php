<?php

if (!function_exists('Fleetbase\Traits\config')) {
    eval('namespace Fleetbase\Traits; function config($key = null, $default = null) { return $key === "api.cache.enabled" ? false : $default; }');
}

if (!function_exists('Fleetbase\Models\config')) {
    eval('namespace Fleetbase\Models; function config($key = null, $default = null) { return $key === "fleetbase.connection.db" ? "mysql" : $default; }');
}

if (!function_exists('Fleetbase\Traits\app')) {
    eval('namespace Fleetbase\Traits; function app($abstract = null, array $parameters = []) { return \app($abstract, $parameters); }');
}

if (!function_exists('Fleetbase\FleetOps\Models\view')) {
    eval('namespace Fleetbase\FleetOps\Models; function view($name = null, $data = []) { \FleetOpsWaypointViewRecorder::$views[] = [$name, array_keys($data)]; return new class { public function render() { return "<html>waypoint-label</html>"; } }; }');
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

class FleetOpsWaypointViewRecorder
{
    public static array $views = [];
}

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

test('waypoint labels render views and stream pdf output', function () {
    $connection = new Illuminate\Database\SQLiteConnection(new PDO('sqlite::memory:'));
    $resolver   = new Illuminate\Database\ConnectionResolver(['default' => $connection, 'mysql' => $connection]);
    $resolver->setDefaultConnection('mysql');
    Illuminate\Database\Eloquent\Model::setConnectionResolver($resolver);
    $schema = $connection->getSchemaBuilder();
    $tables = [
        'waypoints'        => ['uuid', 'public_id', 'company_uuid', 'payload_uuid', 'place_uuid', 'tracking_number_uuid', 'order', 'type', 'status', '_key'],
        'places'           => ['uuid', 'public_id', 'company_uuid', 'name', 'location', '_key'],
        'entities'         => ['uuid', 'public_id', 'company_uuid', 'payload_uuid', 'destination_uuid', 'name', 'type', '_key'],
        'tracking_numbers' => ['uuid', 'public_id', 'company_uuid', 'tracking_number', 'owner_uuid', 'owner_type', '_key'],
        'companies'        => ['uuid', 'public_id', 'name', 'country'],
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

    $connection->table('waypoints')->insert(['uuid' => 'waypoint-label-1', 'company_uuid' => 'company-1', 'payload_uuid' => 'payload-1', 'place_uuid' => 'place-1', 'tracking_number_uuid' => 'tn-1']);
    $connection->table('places')->insert(['uuid' => 'place-1', 'company_uuid' => 'company-1', 'name' => 'Label Stop']);
    $connection->table('entities')->insert(['uuid' => 'entity-1', 'company_uuid' => 'company-1', 'payload_uuid' => 'payload-1', 'destination_uuid' => 'place-1', 'name' => 'Boxed Goods']);
    $connection->table('tracking_numbers')->insert(['uuid' => 'tn-1', 'company_uuid' => 'company-1', 'tracking_number' => 'FLB-LABEL-1']);
    $connection->table('companies')->insert(['uuid' => 'company-1', 'name' => 'Label Co']);

    $waypoint = Waypoint::where('uuid', 'waypoint-label-1')->first();

    // The label view renders with the waypoint context
    FleetOpsWaypointViewRecorder::$views = [];
    expect($waypoint->label())->toBe('<html>waypoint-label</html>')
        ->and(FleetOpsWaypointViewRecorder::$views[0][0])->toBe('fleetops::labels/waypoint-label')
        ->and(FleetOpsWaypointViewRecorder::$views[0][1])->toContain('waypoint', 'dropoff', 'entities', 'trackingNumber', 'company');

    // Entities scope to the waypoint payload and destination place
    expect($waypoint->entities()->count())->toBe(1);

    // Pdf labels load through the shared renderer and stream output
    $wrapper = new class {
        public array $loadedHtml = [];

        public function loadHTML(string $html, ?string $encoding = null): self
        {
            $this->loadedHtml[] = [$html, $encoding];

            return $this;
        }

        public function stream()
        {
            return 'pdf-stream';
        }

        public function __call($method, $arguments)
        {
            return $this;
        }
    };
    Illuminate\Container\Container::getInstance()->instance('dompdf.wrapper', $wrapper);
    app()->instance('dompdf.wrapper', $wrapper);
    Barryvdh\DomPDF\Facade\Pdf::clearResolvedInstance('dompdf.wrapper');

    expect($waypoint->pdfLabel())->toBe($wrapper)
        ->and($waypoint->pdfLabelStream())->toBe('pdf-stream');
});
