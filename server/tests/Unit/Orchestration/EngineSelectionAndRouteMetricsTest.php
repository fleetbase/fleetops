<?php

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Orchestration\Contracts\OrchestrationEngineInterface;
use Fleetbase\FleetOps\Orchestration\OrchestrationEngineRegistry;
use Fleetbase\FleetOps\Orchestration\Support\OrchestratorSettings;
use Fleetbase\FleetOps\Orchestration\Support\RouteMetrics;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Collection;

/**
 * Covers how the Orchestrator picks its engine and what a run reports:
 * the organization's saved engine (with the legacy system key and the
 * built-in default behind it), the greedy fallback that names the engine it
 * replaced and why, and the arrival/distance/duration estimates added to
 * routes whose engine did not supply them.
 */
function fleetopsEngineSelectionBoot(): SQLiteConnection
{
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
    $resolver   = new ConnectionResolver(['default' => $connection, 'mysql' => $connection]);
    $resolver->setDefaultConnection('mysql');
    EloquentModel::setConnectionResolver($resolver);

    $connection->getSchemaBuilder()->create('settings', function ($blueprint) {
        $blueprint->increments('id');
        $blueprint->string('key')->nullable();
        $blueprint->text('value')->nullable();
        $blueprint->timestamps();
        $blueprint->timestamp('deleted_at')->nullable();
    });

    return $connection;
}

class FleetOpsEngineSelectionEngineFake implements OrchestrationEngineInterface
{
    public array $calls = [];

    public function __construct(private string $identifier, private array|RuntimeException $result)
    {
    }

    public function allocate(Collection $orders, Collection $vehicles, array $options = []): array
    {
        $this->calls[] = $options;

        if ($this->result instanceof RuntimeException) {
            throw $this->result;
        }

        return $this->result;
    }

    public function getName(): string
    {
        return ucfirst($this->identifier);
    }

    public function getIdentifier(): string
    {
        return $this->identifier;
    }
}

function fleetopsEngineSelectionRegistry(OrchestrationEngineInterface ...$engines): OrchestrationEngineRegistry
{
    $registry = new OrchestrationEngineRegistry();
    foreach ($engines as $engine) {
        $registry->register($engine);
    }

    return $registry;
}

function fleetopsMetricsLocation(float $lat, float $lng): object
{
    return new class($lat, $lng) {
        public function __construct(private float $lat, private float $lng)
        {
        }

        public function getLat(): float
        {
            return $this->lat;
        }

        public function getLng(): float
        {
            return $this->lng;
        }
    };
}

function fleetopsMetricsVehicle(string $publicId, ?object $location, ?object $driver = null): object
{
    return new class($publicId, $location, $driver) {
        public function __construct(public string $public_id, public ?object $location, public ?object $driver)
        {
        }

        public function relationLoaded(string $relation): bool
        {
            return $relation === 'driver' && $this->driver !== null;
        }
    };
}

function fleetopsMetricsOrder(string $publicId, array $pickup, array $dropoff, ?object $vehicle = null): Order
{
    $order            = new Order();
    $order->public_id = $publicId;
    $order->setRelation('payload', (object) [
        'pickup'    => (object) ['location' => fleetopsMetricsLocation(...$pickup)],
        'dropoff'   => (object) ['location' => fleetopsMetricsLocation(...$dropoff)],
        'waypoints' => null,
    ]);

    if ($vehicle) {
        $order->setRelation('vehicle', $vehicle);
    }

    return $order;
}

function fleetopsMetricsLeg(array $from, array $to): array
{
    $leg = Utils::calculateDrivingDistanceAndTime(new Point(...$from), new Point(...$to));

    return [$leg->distance, $leg->time];
}

test('orchestrator settings resolve the organization engine before the legacy key and the default', function () {
    $connection = fleetopsEngineSelectionBoot();

    // nothing saved anywhere: the built-in default
    expect(OrchestratorSettings::engineForCompany('company-1'))->toBe('greedy')
        ->and(OrchestratorSettings::forCompany(null))->toBe(OrchestratorSettings::defaults());

    // the legacy system key still applies to organizations that never saved
    $connection->table('settings')->insert(['key' => 'fleetops.orchestrator_engine', 'value' => json_encode('capacity')]);
    expect(OrchestratorSettings::engineForCompany('company-1'))->toBe('capacity');

    // the organization's saved settings win, and blank values keep their defaults
    $connection->table('settings')->insert(['key' => 'company.company-1.fleet-ops.allocation-settings', 'value' => json_encode([
        'allocation_engine'       => 'vroom',
        'max_travel_time_seconds' => 1800,
        'balance_workload'        => null,
    ])]);

    expect(OrchestratorSettings::forCompany('company-1'))->toBe([
        'allocation_engine'           => 'vroom',
        'auto_allocate_on_create'     => false,
        'auto_reallocate_on_complete' => false,
        'max_travel_time_seconds'     => 1800,
        'balance_workload'            => false,
    ])
        ->and(OrchestratorSettings::engineForCompany('company-1'))->toBe('vroom');

    // a malformed saved value is ignored rather than trusted
    $connection->table('settings')->insert(['key' => 'company.company-2.fleet-ops.allocation-settings', 'value' => json_encode('vroom')]);
    expect(OrchestratorSettings::engineForCompany('company-2'))->toBe('capacity');
});

test('registry runs the selected engine and names it in the summary', function () {
    $vroom    = new FleetOpsEngineSelectionEngineFake('vroom', ['assignments' => [], 'unassigned' => [], 'summary' => ['cost' => 10]]);
    $named    = new FleetOpsEngineSelectionEngineFake('custom', ['assignments' => [], 'unassigned' => [], 'summary' => ['engine' => 'custom-v2']]);
    $registry = fleetopsEngineSelectionRegistry($vroom, $named);

    expect($registry->allocateWithFallback('vroom', collect(), collect(), ['geometry' => true]))->toBe([
        'assignments' => [],
        'unassigned'  => [],
        'summary'     => ['engine' => 'vroom', 'cost' => 10],
    ])
        ->and($vroom->calls)->toBe([['geometry' => true]])
        // an engine that names itself keeps its own label
        ->and($registry->allocateWithFallback('custom', collect(), collect())['summary'])->toBe(['engine' => 'custom-v2']);
});

test('registry falls back to greedy with multi-order routes and the reason', function () {
    $greedy   = new FleetOpsEngineSelectionEngineFake('greedy', ['assignments' => [], 'unassigned' => [], 'summary' => ['engine' => 'greedy']]);
    $vroom    = new FleetOpsEngineSelectionEngineFake('vroom', new RuntimeException('VROOM returned an error: HTTP 401'));
    $registry = fleetopsEngineSelectionRegistry($greedy, $vroom);

    $result = $registry->allocateWithFallback('vroom', collect(), collect(), ['balance_workload' => true]);

    expect($result['summary'])->toBe([
        'engine'           => 'greedy',
        'requested_engine' => 'vroom',
        'fallback_reason'  => 'VROOM returned an error: HTTP 401',
    ])
        ->and($result['warning'])->toBe('The "greedy" engine was used instead of "vroom": VROOM returned an error: HTTP 401')
        ->and($greedy->calls)->toBe([['allow_multi_order' => true, 'balance_workload' => true]]);

    // an unregistered engine falls back the same way, and an explicit option is kept
    $missing = $registry->allocateWithFallback('or-tools', collect(), collect(), ['allow_multi_order' => false]);
    expect($missing['summary']['requested_engine'])->toBe('or-tools')
        ->and($missing['summary']['fallback_reason'])->toContain("No orchestration engine registered with identifier 'or-tools'")
        ->and($greedy->calls[1])->toBe(['allow_multi_order' => false]);

    // markFallback can record an expected substitution without warning about it
    expect(OrchestrationEngineRegistry::markFallback([], 'route_sequencing', 'greedy', 'expected', false))->toBe([
        'summary' => ['engine' => 'route_sequencing', 'requested_engine' => 'greedy', 'fallback_reason' => 'expected'],
    ]);
});

test('registry rethrows when there is nothing to fall back to', function () {
    $failingGreedy = fleetopsEngineSelectionRegistry(new FleetOpsEngineSelectionEngineFake('greedy', new RuntimeException('greedy failed')));
    $noGreedy      = fleetopsEngineSelectionRegistry(new FleetOpsEngineSelectionEngineFake('vroom', new RuntimeException('vroom down')));

    expect(fn () => $failingGreedy->allocateWithFallback('greedy', collect(), collect()))->toThrow(RuntimeException::class, 'greedy failed')
        ->and(fn () => $noGreedy->allocateWithFallback('vroom', collect(), collect()))->toThrow(RuntimeException::class, 'vroom down');
});

test('route metrics measure cumulative distance duration and arrival along a stop list', function () {
    $start = [1.30, 103.80];
    $a     = [1.31, 103.81];
    $b     = [1.33, 103.85];

    [$d1, $t1] = fleetopsMetricsLeg($start, $a);
    [$d2, $t2] = fleetopsMetricsLeg($a, $b);

    $metrics = RouteMetrics::measure($start, [
        ['order_id' => 'order_one', 'lat' => $a[0], 'lng' => $a[1]],
        ['order_id' => 'order_one', 'lat' => $b[0], 'lng' => $b[1]],
    ], 1000);

    expect($metrics)->toBe([
        'orders'   => ['order_one' => ['arrival' => 1000 + (int) round($t1 + $t2), 'distance' => (int) round($d1 + $d2), 'duration' => (int) round($t1 + $t2)]],
        'distance' => (int) round($d1 + $d2),
        'duration' => (int) round($t1 + $t2),
    ])
        ->and($metrics['distance'])->toBeGreaterThan(0);

    // without a start position the route begins at its first stop
    expect(RouteMetrics::measure(null, [['order_id' => 'order_one', 'lat' => $a[0], 'lng' => $a[1]]], 50))->toBe([
        'orders'   => ['order_one' => ['arrival' => 50, 'distance' => 0, 'duration' => 0]],
        'distance' => 0,
        'duration' => 0,
    ]);
});

test('route metrics estimate routes whose engine reported no timing', function () {
    $driver  = (object) ['location' => fleetopsMetricsLocation(1.30, 103.80)];
    $vehicle = fleetopsMetricsVehicle('vehicle_one', fleetopsMetricsLocation(9.0, 9.0), $driver);
    $first   = fleetopsMetricsOrder('order_first', [1.31, 103.81], [1.32, 103.82]);
    $second  = fleetopsMetricsOrder('order_second', [1.33, 103.83], [1.34, 103.84]);

    $result = RouteMetrics::annotate([
        'assignments' => [
            // listed out of sequence, with greedy's pickup distance, plus an order the run no longer has
            ['order_id' => 'order_second', 'vehicle_id' => 'vehicle_one', 'sequence' => 2, 'arrival' => null, 'distance' => 5],
            ['order_id' => 'order_first', 'vehicle_id' => 'vehicle_one', 'sequence' => 1, 'arrival' => null, 'distance' => 5],
            ['order_id' => 'order_gone', 'vehicle_id' => 'vehicle_one', 'sequence' => 3],
        ],
        'unassigned' => [],
        'summary'    => ['engine' => 'greedy'],
    ], collect([$first, $second]), collect([$vehicle]), 1000);

    // the driver's position is the start, then each order's pickup and dropoff in sequence
    $legs = [
        fleetopsMetricsLeg([1.30, 103.80], [1.31, 103.81]),
        fleetopsMetricsLeg([1.31, 103.81], [1.32, 103.82]),
        fleetopsMetricsLeg([1.32, 103.82], [1.33, 103.83]),
        fleetopsMetricsLeg([1.33, 103.83], [1.34, 103.84]),
    ];
    $firstDistance = (int) round($legs[0][0] + $legs[1][0]);
    $firstDuration = (int) round($legs[0][1] + $legs[1][1]);
    $totalDistance = (int) round(array_sum(array_column($legs, 0)));
    $totalDuration = (int) round(array_sum(array_column($legs, 1)));

    [$second, $first, $gone] = $result['assignments'];

    expect($first)->toMatchArray([
        'arrival'        => 1000 + $firstDuration,
        'distance'       => $firstDistance,
        'duration'       => $firstDuration,
        'route_distance' => $totalDistance,
        'route_duration' => $totalDuration,
    ])
        ->and($second)->toMatchArray([
            'arrival'  => 1000 + $totalDuration,
            'distance' => $totalDistance,
        ])
        ->and($gone)->toMatchArray(['arrival' => null, 'route_distance' => $totalDistance])
        ->and($result['summary'])->toBe([
            'engine'   => 'greedy',
            'distance' => $totalDistance,
            'duration' => $totalDuration,
            'metrics'  => 'estimated',
        ]);
});

test('route metrics keep engine timing and only fill its gaps', function () {
    $vehicle = fleetopsMetricsVehicle('vehicle_one', fleetopsMetricsLocation(1.30, 103.80));
    $order   = fleetopsMetricsOrder('order_one', [1.31, 103.81], [1.32, 103.82], $vehicle);
    $engine  = ['order_id' => 'order_one', 'vehicle_id' => 'vehicle_one', 'sequence' => 1, 'arrival' => 1778918400, 'duration' => 900, 'distance' => null, 'route_distance' => null, 'route_duration' => 900];

    // the vehicle is not in the run's list, so its position comes from the order
    $filled = RouteMetrics::annotate(['assignments' => [$engine], 'summary' => ['distance' => 77, 'duration' => 900]], collect([$order]), collect());

    $total = (int) round(fleetopsMetricsLeg([1.30, 103.80], [1.31, 103.81])[0] + fleetopsMetricsLeg([1.31, 103.81], [1.32, 103.82])[0]);

    expect($filled['assignments'][0])->toBe(array_merge($engine, ['distance' => $total, 'route_distance' => $total]))
        ->and($filled['summary'])->toBe(['distance' => 77, 'duration' => 900, 'metrics' => 'estimated']);

    // complete engine timing is left alone and the engine's metrics label is kept
    $complete = array_merge($engine, ['distance' => 4200, 'route_distance' => 4200]);
    $kept     = RouteMetrics::annotate(['assignments' => [$complete], 'summary' => ['metrics' => 'estimated']], collect([$order]), collect());
    $vroom    = RouteMetrics::annotate(['assignments' => [$complete]], collect([$order]), collect());

    expect($kept['assignments'][0])->toBe($complete)
        ->and($kept['summary'])->toBe(['metrics' => 'estimated', 'distance' => 4200, 'duration' => 900])
        ->and($vroom['summary']['metrics'])->toBe('engine');
});

test('route metrics leave empty plans alone and start where the vehicle is unknown', function () {
    expect(RouteMetrics::annotate(['assignments' => [], 'summary' => []], collect(), collect()))->toBe(['assignments' => [], 'summary' => []]);

    // no vehicle anywhere: the route starts at the first pickup
    $order  = fleetopsMetricsOrder('order_one', [1.31, 103.81], [1.32, 103.82]);
    $result = RouteMetrics::annotate([
        'assignments' => [['order_id' => 'order_one', 'vehicle_id' => 'vehicle_missing', 'sequence' => 1]],
    ], collect([$order]), collect(), 500);

    [$distance, $time] = fleetopsMetricsLeg([1.31, 103.81], [1.32, 103.82]);

    expect($result['assignments'][0])->toMatchArray([
        'arrival'        => 500 + (int) round($time),
        'route_distance' => (int) round($distance),
    ]);
});
