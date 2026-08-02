<?php

use Fleetbase\FleetOps\Http\Controllers\Internal\v1\ProofController;
use Fleetbase\FleetOps\Http\Filter\IssueFilter;
use Fleetbase\FleetOps\Models\Proof;
use Fleetbase\FleetOps\Support\OSRM;
use Fleetbase\Http\Filter\Filter;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Covers the OSRM routing client with faked HTTP and cache, the internal
 * ProofController subject lookup and persistence helpers, and the
 * IssueFilter relation/date filters against SQLite.
 */
if (!function_exists('Fleetbase\Support\session')) {
    eval('namespace Fleetbase\Support; function session($key = null, $default = null) { if ($key === null) { return new class { public function has($k) { return \session($k) !== null; } public function get($k, $d = null) { return \session($k, $d); } }; } return \session($key, $default); }');
}

if (!function_exists('Fleetbase\Support\auth')) {
    eval('namespace Fleetbase\Support; function auth() { return new class { public function user() { return null; } public function id() { return null; } }; }');
}

class FleetOpsProofControllerProbe extends ProofController
{
    public function callHelper(string $method, ...$arguments): mixed
    {
        return $this->{$method}(...$arguments);
    }
}

class FleetOpsIssueFilterQueryFake
{
    public array $calls = [];

    public function whereHas(string $relation, ?Closure $callback = null): self
    {
        $nested = new self();
        if ($callback) {
            $callback($nested);
        }
        $this->calls[] = ['whereHas', $relation, $nested->calls];

        return $this;
    }

    public function __call($method, $arguments)
    {
        $this->calls[] = [$method, $arguments];

        return $this;
    }
}

function fleetopsOsrmProofBoot(): SQLiteConnection
{
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
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

    Http::clearResolvedInstances();
    app()->forgetInstance(Illuminate\Http\Client\Factory::class);

    Cache::swap(new class {
        public array $store = [];

        public function has($key)
        {
            return array_key_exists($key, $this->store);
        }

        public function get($key, $default = null)
        {
            return $this->store[$key] ?? (is_callable($default) ? $default() : $default);
        }

        public function put($key, $value, $ttl = null)
        {
            $this->store[$key] = $value;

            return true;
        }

        public function __call($method, $arguments)
        {
            return null;
        }
    });

    $storageFake = new class {
        public array $writes = [];

        public function disk($disk = null)
        {
            return $this;
        }

        public function put($path, $contents, $options = [])
        {
            $this->writes[] = [$path];

            return true;
        }
    };
    app()->instance('filesystem', $storageFake);
    Illuminate\Support\Facades\Storage::clearResolvedInstance('filesystem');
    $GLOBALS['fleetopsProofStorageFake'] = $storageFake;

    $schema = $connection->getSchemaBuilder();
    $tables = [
        'orders'    => ['uuid', 'public_id', 'company_uuid', 'status'],
        'waypoints' => ['uuid', 'public_id', 'company_uuid', 'payload_uuid', 'place_uuid', 'order'],
        'entities'  => ['uuid', 'public_id', 'company_uuid', 'name'],
        'proofs'    => ['uuid', 'public_id', 'company_uuid', 'order_uuid', 'subject_uuid', 'subject_type', 'remarks', 'raw_data', 'data', 'file_uuid', '_key'],
        'files'     => ['uuid', 'public_id', 'company_uuid', 'name', 'original_filename', 'extension', 'content_type', 'path', 'bucket', 'disk', 'size', 'type', 'meta', '_key', 'subject_uuid', 'subject_type', 'uploader_uuid'],
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

test('osrm builds routes tables and trips with cached responses', function () {
    fleetopsOsrmProofBoot();
    Http::fake(['*' => Http::response(['code' => 'Ok', 'routes' => [['distance' => 1000, 'duration' => 120]], 'waypoints' => []], 200)]);

    $start = new Point(1.30, 103.80);
    $end   = new Point(1.35, 103.85);

    $route = OSRM::getRoute($start, $end);
    expect($route)->toBeArray()
        ->and($route['code'] ?? null)->toBe('Ok');

    // Fewer than two points is rejected
    expect(fn () => OSRM::getRouteFromPoints([$start]))->toThrow(InvalidArgumentException::class);

    $multi = OSRM::getRouteFromPoints([$start, $end]);
    expect($multi)->toBeArray();

    // Cached second call short-circuits the HTTP layer
    $again = OSRM::getRouteFromPoints([$start, $end]);
    expect($again)->toBeArray();

    expect(OSRM::getNearest($start))->toBeArray()
        ->and(OSRM::getTable([$start, $end]))->toBeArray()
        ->and(OSRM::getTrip([$start, $end]))->toBeArray();
});

test('proof subject lookups resolve by uuid and public id per type', function () {
    $connection = fleetopsOsrmProofBoot();
    $connection->table('orders')->insert(['uuid' => 'order-1', 'public_id' => 'order_proof', 'company_uuid' => 'company-1']);
    $connection->table('waypoints')->insert(['uuid' => 'wp-1', 'public_id' => 'waypoint_proof', 'company_uuid' => 'company-1']);
    $connection->table('entities')->insert(['uuid' => 'ent-1', 'public_id' => 'entity_proof', 'company_uuid' => 'company-1']);

    $probe = new FleetOpsProofControllerProbe();

    expect($probe->callHelper('findQrSubject', 'order', 'order-1')?->uuid)->toBe('order-1')
        ->and($probe->callHelper('findQrSubject', 'waypoint', 'wp-1')?->uuid)->toBe('wp-1')
        ->and($probe->callHelper('findQrSubject', 'entity', 'ent-1')?->uuid)->toBe('ent-1')
        ->and($probe->callHelper('findQrSubject', 'unknown', 'x'))->toBeNull();

    expect($probe->callHelper('findPublicSubject', 'order', 'order_proof')?->uuid)->toBe('order-1')
        ->and($probe->callHelper('findPublicSubject', 'waypoint', 'waypoint_proof')?->uuid)->toBe('wp-1')
        ->and($probe->callHelper('findPublicSubject', 'entity', 'entity_proof')?->uuid)->toBe('ent-1')
        ->and($probe->callHelper('findPublicSubject', 'unknown', 'x'))->toBeNull();

    $proof = $probe->callHelper('createProof', ['company_uuid' => 'company-1', 'order_uuid' => 'order-1', 'subject_uuid' => 'order-1', 'remarks' => 'Signed']);
    expect($proof)->toBeInstanceOf(Proof::class)
        ->and($connection->table('proofs')->count())->toBe(1);

    $probe->callHelper('storeSignature', 'signatures/proof.png', 'binary', 'public');
    expect($GLOBALS['fleetopsProofStorageFake']->writes)->toHaveCount(1);

    expect($probe->callHelper('jsonResponse', ['status' => 'ok'])->getData(true))->toBe(['status' => 'ok'])
        ->and($probe->callHelper('errorResponse', 'nope')->getData(true)['error'])->toBe('nope')
        ->and($probe->callHelper('proofSuccessPayload', $proof))->toBeArray();
});

test('issue filter builds relation subqueries and date windows', function () {
    fleetopsOsrmProofBoot();

    $filter = (new ReflectionClass(IssueFilter::class))->newInstanceWithoutConstructor();
    $query  = new FleetOpsIssueFilterQueryFake();
    foreach ([
        'builder' => $query,
        'session' => new class {
            public function get(string $key): ?string
            {
                return $key === 'company' ? 'company-1' : null;
            }
        },
        'request' => new Request(),
    ] as $property => $value) {
        $reflection = new ReflectionProperty(Filter::class, $property);
        $reflection->setAccessible(true);
        $reflection->setValue($filter, $value);
    }

    // uuid, public id, and free-text variants route to different subqueries
    $filter->assignee('11111111-1111-4111-8111-111111111111');
    $filter->reporter('user_abc1234');
    $filter->driver('casey');
    $filter->vehicle('11111111-1111-4111-8111-111111111111');

    $whereHas = collect($query->calls)->where(0, 'whereHas');
    expect($whereHas)->toHaveCount(4)
        ->and($whereHas->pluck(1)->all())->toBe(['assignedTo', 'reportedBy', 'driver', 'vehicle']);

    // Date filters use ranges or single dates
    $filter->createdAt('2026-07-01,2026-07-31');
    $filter->updatedAt('2026-07-15');

    expect(collect($query->calls)->where(0, 'whereBetween'))->toHaveCount(1)
        ->and(collect($query->calls)->where(0, 'whereDate'))->toHaveCount(1);
});
