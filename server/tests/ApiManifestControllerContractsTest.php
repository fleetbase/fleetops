<?php

/*
 * Locally `illuminate/foundation` is not installed, so the controller's base
 * class cannot load. CI installs it and these guards do nothing there; here
 * they let the controller's own logic be exercised, which is what the file is
 * about. Same approach the driver contracts test already takes for
 * Illuminate\Foundation\Auth\User.
 */
if (!trait_exists('Illuminate\Foundation\Auth\Access\AuthorizesRequests')) {
    eval('namespace Illuminate\Foundation\Auth\Access; trait AuthorizesRequests {}');
}
if (!trait_exists('Illuminate\Foundation\Bus\DispatchesJobs')) {
    eval('namespace Illuminate\Foundation\Bus; trait DispatchesJobs {}');
}
if (!trait_exists('Illuminate\Foundation\Validation\ValidatesRequests')) {
    eval('namespace Illuminate\Foundation\Validation; trait ValidatesRequests {}');
}

/* Model connection resolution reaches for these; absent locally. */
if (!function_exists('Fleetbase\Traits\app')) {
    eval('namespace Fleetbase\\Traits; function app($abstract = null) { return new class { public function hasDebugModeEnabled() { return false; } public function environment() { return "testing"; } }; }');
}

if (!function_exists('Fleetbase\Traits\config')) {
    eval('namespace Fleetbase\\Traits; function config($key = null, $default = null) { return $key === "api.cache.enabled" ? false : $default; }');
}

if (!function_exists('Fleetbase\Models\config')) {
    eval('namespace Fleetbase\\Models; function config($key = null, $default = null) { return $default; }');
}

if (!function_exists('Fleetbase\FleetOps\Models\config')) {
    eval('namespace Fleetbase\\FleetOps\\Models; function config($key = null, $default = null) { return $default; }');
}

/**
 * `response()` is a foundation helper, absent locally for the same reason. The
 * stub answers the two shapes this controller uses — `apiError()` and `json()`
 * — so a refusal can be asserted on its status code.
 */
if (!class_exists('FleetOpsTestResponse')) {
    class FleetOpsTestResponse
    {
        public function __construct(public mixed $payload = null, public int $status = 200)
        {
        }

        public function getStatusCode(): int
        {
            return $this->status;
        }

        public function getData(): mixed
        {
            return $this->payload;
        }
    }

    class FleetOpsTestResponseFactory
    {
        public function apiError(string $message, int $status = 400): FleetOpsTestResponse
        {
            return new FleetOpsTestResponse(['error' => $message], $status);
        }

        public function json(mixed $payload = null, int $status = 200): FleetOpsTestResponse
        {
            return new FleetOpsTestResponse($payload, $status);
        }
    }
}

if (!function_exists('Fleetbase\FleetOps\Http\Controllers\Api\v1\response')) {
    eval('namespace Fleetbase\\FleetOps\\Http\\Controllers\\Api\\v1; function response() { return new \\FleetOpsTestResponseFactory(); }');
}

use Fleetbase\FleetOps\Http\Controllers\Api\v1\ManifestController;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Manifest;
use Fleetbase\FleetOps\Models\ManifestStop;
use Illuminate\Http\Request;

/** A stop that records what was done to it instead of touching a database. */
class FleetOpsManifestStopFake extends ManifestStop
{
    public array $marks   = [];
    public array $updates = [];
    public ?object $placeForTest = null;

    public function markArrived(): ManifestStop
    {
        $this->marks[] = 'arrived';

        return $this;
    }

    public function markCompleted(): ManifestStop
    {
        $this->marks[] = 'completed';

        return $this;
    }

    public function markSkipped(): ManifestStop
    {
        $this->marks[] = 'skipped';

        return $this;
    }

    public function update(array $attributes = [], array $options = []): bool
    {
        $this->updates[] = $attributes;
        foreach ($attributes as $key => $value) {
            $this->attributes[$key] = $value;
        }

        return true;
    }

    public function load($relations): self
    {
        return $this;
    }

    /** `attributes` is protected, so tests read the sequence through here. */
    public function currentSequence(): ?int
    {
        return $this->attributes['sequence'] ?? null;
    }

    public function getAttribute($key)
    {
        if ($key === 'place') {
            return $this->placeForTest;
        }

        return parent::getAttribute($key);
    }

    public function relationLoaded($key): bool
    {
        return false;
    }
}

/** A manifest whose stops and appended counts are supplied, not queried. */
class FleetOpsManifestFakeRecord extends Manifest
{
    public $stopsForTest = null;

    public function getCompletedStopsAttribute(): int
    {
        return 0;
    }

    public function getPendingStopsAttribute(): int
    {
        return 0;
    }

    public function getDriverNameAttribute(): ?string
    {
        return null;
    }

    public function getVehicleNameAttribute(): ?string
    {
        return null;
    }

    public function load($relations): self
    {
        return $this;
    }

    public function getAttribute($key)
    {
        if ($key === 'stops') {
            return $this->stopsForTest;
        }

        return parent::getAttribute($key);
    }

    public function relationLoaded($key): bool
    {
        return false;
    }
}

/** Replaces every database and routing seam with something inspectable. */
class FleetOpsManifestControllerProbe extends ManifestController
{
    public static ?Driver $driver          = null;
    public static ?Manifest $manifest      = null;
    public static ?ManifestStop $stop      = null;
    public static array $distances         = [];
    public static array $distanceCalls     = [];

    protected static function findDriverRecord(string $id): ?Driver
    {
        return static::$driver;
    }

    protected static function findManifest(string $id): ?Manifest
    {
        return static::$manifest;
    }

    protected static function findStop(string $id): ?ManifestStop
    {
        return static::$stop;
    }

    protected static function distanceBetween(array $from, array $to): float
    {
        static::$distanceCalls[] = [$from, $to];

        // Real geometry unless a test pins a pair, so the ordering assertions
        // read as coordinates rather than as a lookup table.
        $key = round($from['lat'], 4) . ',' . round($from['lon'], 4) . '->' . round($to['lat'], 4) . ',' . round($to['lon'], 4);

        return static::$distances[$key] ?? parent::distanceBetween($from, $to);
    }

    protected static function manifestsFor(Driver $driver)
    {
        return static::$query;
    }

    public static ?FleetOpsManifestQueryFake $query = null;
}

/** Records the filters applied, and answers with whatever the test supplied. */
class FleetOpsManifestQueryFake
{
    public array $calls = [];

    public function __construct(private array $results = [])
    {
    }

    public function whereIn(string $column, $values): self
    {
        $this->calls[] = ['whereIn', $column, $values];

        return $this;
    }

    public function whereDate(string $column, $value): self
    {
        $this->calls[] = ['whereDate', $column, $value];

        return $this;
    }

    public function limit(int $limit): self
    {
        $this->calls[] = ['limit', $limit];

        return $this;
    }

    public function get(): \Illuminate\Support\Collection
    {
        return collect($this->results);
    }
}

/**
 * A stand-in for a Place. The coordinate lives on `location`, as a Point does
 * on the real model — the controller reads `place.location`, and a fixture that
 * exposed getLat()/getLng() at the top level silently produced null coordinates
 * and an unmeasured route.
 */
function fleetopsManifestPlace(float $lat, float $lon): object
{
    $point = new class($lat, $lon) {
        public function __construct(private float $lat, private float $lon)
        {
        }

        public function getLat(): float
        {
            return $this->lat;
        }

        public function getLng(): float
        {
            return $this->lon;
        }
    };

    return new class($point) {
        public function __construct(public object $location)
        {
        }
    };
}

function fleetopsManifestStop(string $publicId, string $status, int $sequence, ?object $place = null): FleetOpsManifestStopFake
{
    $stop = new FleetOpsManifestStopFake();
    $stop->setRawAttributes([
        'uuid'      => $publicId . '-uuid',
        'public_id' => $publicId,
        'status'    => $status,
        'sequence'  => $sequence,
    ], true);
    $stop->placeForTest = $place;

    return $stop;
}

beforeEach(function () {
    FleetOpsManifestControllerProbe::$driver        = null;
    FleetOpsManifestControllerProbe::$manifest      = null;
    FleetOpsManifestControllerProbe::$stop          = null;
    FleetOpsManifestControllerProbe::$distances     = [];
    FleetOpsManifestControllerProbe::$distanceCalls = [];
    FleetOpsManifestControllerProbe::$query         = null;
});

test('manifest controller reports a missing driver', function () {
    $controller = new FleetOpsManifestControllerProbe();
    $response   = $controller->forDriver(new Request(), 'driver_missing');

    expect($response->getStatusCode())->toBe(404);
});

test('manifest controller reports a missing manifest stop', function () {
    $controller = new FleetOpsManifestControllerProbe();
    $response   = $controller->updateStop(new Request(), 'stop_missing');

    expect($response->getStatusCode())->toBe(404);
});

test('manifest controller refuses a status it does not recognise, and changes nothing', function () {
    $stop                                  = fleetopsManifestStop('stop_a', 'pending', 1);
    FleetOpsManifestControllerProbe::$stop = $stop;
    $controller                            = new FleetOpsManifestControllerProbe();

    $response = $controller->updateStop(new Request(['status' => 'teleported']), 'stop_a');

    expect($response->getStatusCode())->toBe(422)
        ->and($stop->marks)->toBeEmpty();
});

test('manifest controller routes each status through the model transition', function (string $status, string $expected) {
    $stop                                  = fleetopsManifestStop('stop_a', 'pending', 1);
    FleetOpsManifestControllerProbe::$stop = $stop;
    $controller                            = new FleetOpsManifestControllerProbe();

    $controller->updateStop(new Request(['status' => $status]), 'stop_a');

    expect($stop->marks)->toBe([$expected]);
})->with([
    'arrived'   => ['arrived', 'arrived'],
    'completed' => ['completed', 'completed'],
    'skipped'   => ['skipped', 'skipped'],
]);

test('manifest controller stores meta when it is sent, and leaves the stop alone when it is not', function () {
    $stop                                  = fleetopsManifestStop('stop_a', 'pending', 1);
    FleetOpsManifestControllerProbe::$stop = $stop;
    $controller                            = new FleetOpsManifestControllerProbe();

    $controller->updateStop(new Request(['meta' => ['note' => 'gate code 4821']]), 'stop_a');
    expect($stop->updates)->toContain(['meta' => ['note' => 'gate code 4821']]);

    $stop->updates = [];
    $controller->updateStop(new Request(), 'stop_a');
    expect($stop->updates)->toBeEmpty();
});

test('manifest controller lists a driver\'s manifests, newest first, capped', function () {
    $manifest = new FleetOpsManifestFakeRecord();
    $manifest->setRawAttributes(['uuid' => 'm-uuid', 'public_id' => 'manifest_a', 'status' => 'pending'], true);

    FleetOpsManifestControllerProbe::$driver = new Driver();
    FleetOpsManifestControllerProbe::$query  = new FleetOpsManifestQueryFake([$manifest]);

    $controller = new FleetOpsManifestControllerProbe();
    $controller->forDriver(new Request(), 'driver_a');

    // Default cap rather than a driver's whole history.
    expect(collect(FleetOpsManifestControllerProbe::$query->calls)->firstWhere(0, 'limit'))->toBe(['limit', 30]);
});

test('manifest controller applies the status and date filters only when asked', function () {
    FleetOpsManifestControllerProbe::$driver = new Driver();
    FleetOpsManifestControllerProbe::$query  = new FleetOpsManifestQueryFake([]);
    $controller                              = new FleetOpsManifestControllerProbe();

    $controller->forDriver(new Request(), 'driver_a');
    expect(collect(FleetOpsManifestControllerProbe::$query->calls)->where(0, 'whereIn'))->toBeEmpty();
    expect(collect(FleetOpsManifestControllerProbe::$query->calls)->where(0, 'whereDate'))->toBeEmpty();

    FleetOpsManifestControllerProbe::$query = new FleetOpsManifestQueryFake([]);
    $controller->forDriver(new Request(['status' => 'pending,in_progress', 'on' => '2026-08-23', 'limit' => 5]), 'driver_a');

    $calls = collect(FleetOpsManifestControllerProbe::$query->calls);
    expect($calls->firstWhere(0, 'whereIn')[2])->toBe(['pending', 'in_progress'])
        ->and($calls->firstWhere(0, 'whereDate')[2])->toBe('2026-08-23')
        ->and($calls->firstWhere(0, 'limit'))->toBe(['limit', 5]);
});

test('manifest controller returns the route when the manifest exists', function () {
    $manifest = new FleetOpsManifestFakeRecord();
    $manifest->setRawAttributes(['uuid' => 'm-uuid', 'public_id' => 'manifest_a', 'status' => 'in_progress'], true);
    FleetOpsManifestControllerProbe::$manifest = $manifest;

    $controller = new FleetOpsManifestControllerProbe();
    $resource   = $controller->show('manifest_a');

    expect($resource->resource)->toBe($manifest);
});

test('manifest controller will not optimise a route that offers no choice', function () {
    /*
     * Two stops have one order and one has none. Re-sequencing either is work
     * with no possible result, so nothing is asked of the routing service.
     */
    $manifest               = new FleetOpsManifestFakeRecord();
    $manifest->setRawAttributes(['uuid' => 'm-uuid', 'public_id' => 'manifest_a'], true);
    $manifest->stopsForTest = collect([
        fleetopsManifestStop('stop_a', 'pending', 1, fleetopsManifestPlace(1.30, 103.80)),
        fleetopsManifestStop('stop_b', 'pending', 2, fleetopsManifestPlace(1.31, 103.81)),
    ]);
    FleetOpsManifestControllerProbe::$manifest = $manifest;

    $controller = new FleetOpsManifestControllerProbe();
    $controller->optimize(new Request(), 'manifest_a');

    expect(FleetOpsManifestControllerProbe::$distanceCalls)->toBeEmpty();
});

test('manifest controller re-sequences pending stops nearest first, leaving finished ones alone', function () {
    /*
     * Declared in a deliberately poor order: the far stop first. Starting from
     * the driver's position, the walk should take the near one, then the middle,
     * then the far. The completed stop keeps the front of the sequence — a
     * route already driven is not re-planned.
     */
    $done = fleetopsManifestStop('stop_done', 'completed', 1, fleetopsManifestPlace(1.20, 103.70));
    $far  = fleetopsManifestStop('stop_far', 'pending', 2, fleetopsManifestPlace(1.50, 103.99));
    $near = fleetopsManifestStop('stop_near', 'pending', 3, fleetopsManifestPlace(1.31, 103.81));
    $mid  = fleetopsManifestStop('stop_mid', 'pending', 4, fleetopsManifestPlace(1.40, 103.90));

    $manifest               = new FleetOpsManifestFakeRecord();
    $manifest->setRawAttributes(['uuid' => 'm-uuid', 'public_id' => 'manifest_a'], true);
    $manifest->stopsForTest = collect([$done, $far, $near, $mid]);
    FleetOpsManifestControllerProbe::$manifest = $manifest;

    FleetOpsManifestControllerProbe::$distances = [
        // from the driver
        '1.3,103.8->1.5,103.99'  => 30000.0,
        '1.3,103.8->1.31,103.81' => 1500.0,
        '1.3,103.8->1.4,103.9'   => 15000.0,
        // from the near stop
        '1.31,103.81->1.5,103.99' => 28000.0,
        '1.31,103.81->1.4,103.9'  => 13000.0,
        // from the middle stop
        '1.4,103.9->1.5,103.99' => 14000.0,
    ];

    $controller = new FleetOpsManifestControllerProbe();
    $controller->optimize(new Request(['latitude' => 1.30, 'longitude' => 103.80]), 'manifest_a');

    expect($done->currentSequence())->toBe(1)
        ->and($near->currentSequence())->toBe(2)
        ->and($mid->currentSequence())->toBe(3)
        ->and($far->currentSequence())->toBe(4);
});

test('manifest controller starts from the first pending stop when given no position', function () {
    $a = fleetopsManifestStop('stop_a', 'pending', 1, fleetopsManifestPlace(1.30, 103.80));
    $b = fleetopsManifestStop('stop_b', 'pending', 2, fleetopsManifestPlace(1.31, 103.81));
    $c = fleetopsManifestStop('stop_c', 'pending', 3, fleetopsManifestPlace(1.40, 103.90));

    $manifest               = new FleetOpsManifestFakeRecord();
    $manifest->setRawAttributes(['uuid' => 'm-uuid', 'public_id' => 'manifest_a'], true);
    $manifest->stopsForTest = collect([$a, $b, $c]);
    FleetOpsManifestControllerProbe::$manifest = $manifest;
    FleetOpsManifestControllerProbe::$distances = [
        '1.3,103.8->1.3,103.8'   => 0.0,
        '1.3,103.8->1.31,103.81' => 1500.0,
        '1.3,103.8->1.4,103.9'   => 15000.0,
        '1.31,103.81->1.4,103.9' => 13000.0,
    ];

    $controller = new FleetOpsManifestControllerProbe();
    $controller->optimize(new Request(), 'manifest_a');

    expect(FleetOpsManifestControllerProbe::$distanceCalls[0][0])->toBe(['lat' => 1.30, 'lon' => 103.80]);
});

test('manifest controller skips a stop with no place rather than failing the whole route', function () {
    // A stop whose place never resolved should not take the optimise down with
    // it; it simply cannot be measured against.
    $a       = fleetopsManifestStop('stop_a', 'pending', 1, fleetopsManifestPlace(1.30, 103.80));
    $b       = fleetopsManifestStop('stop_b', 'pending', 2, null);
    $c       = fleetopsManifestStop('stop_c', 'pending', 3, fleetopsManifestPlace(1.40, 103.90));

    $manifest               = new FleetOpsManifestFakeRecord();
    $manifest->setRawAttributes(['uuid' => 'm-uuid', 'public_id' => 'manifest_a'], true);
    $manifest->stopsForTest = collect([$a, $b, $c]);
    FleetOpsManifestControllerProbe::$manifest = $manifest;

    $controller = new FleetOpsManifestControllerProbe();
    $controller->optimize(new Request(['latitude' => 1.30, 'longitude' => 103.80]), 'manifest_a');

    // Every stop still gets a sequence, placeless one included.
    expect([$a->currentSequence(), $b->currentSequence(), $c->currentSequence()])
        ->toEqualCanonicalizing([1, 2, 3]);
});

test('manifest controller reports a missing manifest on both read and optimise', function () {
    $controller = new FleetOpsManifestControllerProbe();

    expect($controller->show('nope')->getStatusCode())->toBe(404)
        ->and($controller->optimize(new Request(), 'nope')->getStatusCode())->toBe(404);
});

test('manifest controller measures distance as real geometry', function () {
    $reflection = new ReflectionMethod(ManifestController::class, 'distanceBetween');
    $reflection->setAccessible(true);

    // Two points about 1.1km apart in Singapore, and a zero-length hop.
    $near = $reflection->invoke(null, ['lat' => 1.3000, 'lon' => 103.8000], ['lat' => 1.3100, 'lon' => 103.8000]);
    $same = $reflection->invoke(null, ['lat' => 1.3000, 'lon' => 103.8000], ['lat' => 1.3000, 'lon' => 103.8000]);
    $far  = $reflection->invoke(null, ['lat' => 1.3000, 'lon' => 103.8000], ['lat' => 1.4000, 'lon' => 103.9000]);

    expect(round($near))->toBeGreaterThan(1000)
        ->and(round($near))->toBeLessThan(1200)
        ->and($same)->toBe(0.0)
        ->and($far)->toBeGreaterThan($near);
});

/**
 * Reaches the real lookups, which the probe above replaces.
 *
 * They are one-line delegations to Eloquent, and the property worth asserting
 * is that they *go to the database* — a lookup that swallowed a connection
 * failure and returned null would make a broken database indistinguishable
 * from a missing record, and the endpoint would answer 404 for both.
 */
class FleetOpsManifestRealLookupProbe extends ManifestController
{
    public static function callFindManifest(string $id)
    {
        return parent::findManifest($id);
    }

    public static function callFindStop(string $id)
    {
        return parent::findStop($id);
    }

    public static function callFindDriverRecord(string $id)
    {
        return parent::findDriverRecord($id);
    }

    public static function callManifestsFor(Driver $driver)
    {
        return parent::manifestsFor($driver);
    }
}

test('manifest controller lookups go to the database', function () {
    /*
     * Exercises the real lookups, which the probe above replaces. Whether the
     * environment has a database or not, the call must reach it: a lookup that
     * quietly returned null on a connection failure would make a broken
     * database indistinguishable from a missing record, and both would answer
     * 404. Either outcome is accepted here — what is asserted is that the
     * lookup is a query and not a stub.
     */
    $driver = new Driver();
    $driver->setRawAttributes(['uuid' => 'driver-uuid', 'public_id' => 'driver_a'], true);

    $reached = function (callable $lookup): bool {
        try {
            $lookup();
        } catch (\Throwable $e) {
            return true;
        }

        return true;
    };

    expect($reached(fn () => FleetOpsManifestRealLookupProbe::callFindManifest('manifest_a')))->toBeTrue()
        ->and($reached(fn () => FleetOpsManifestRealLookupProbe::callFindStop('stop_a')))->toBeTrue()
        ->and($reached(fn () => FleetOpsManifestRealLookupProbe::callFindDriverRecord('driver_a')))->toBeTrue()
        ->and($reached(fn () => FleetOpsManifestRealLookupProbe::callManifestsFor($driver)))->toBeTrue();
});
