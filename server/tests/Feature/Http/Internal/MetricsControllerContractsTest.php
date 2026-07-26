<?php

use Fleetbase\FleetOps\Http\Controllers\Internal\v1\MetricsController;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Stringable;

class FleetOpsInternalMetricsControllerProbe extends MetricsController
{
    public ?string $metricClass = FleetOpsInternalMetricFake::class;
    public FleetOpsInternalMetricsCollectionFake $metrics;
    public FleetOpsInternalMetricFake $metric;
    public array $bulkCalls       = [];
    public array $metricCalls     = [];
    public ?Throwable $bulkError  = null;
    public array $resolvedPeriods = [];

    public function __construct()
    {
        $this->metrics = new FleetOpsInternalMetricsCollectionFake();
        $this->metric  = new FleetOpsInternalMetricFake();
    }

    protected function metricsForCompany($company, $start, $end): FleetOpsInternalMetricsCollectionFake
    {
        $this->bulkCalls[] = compact('company', 'start', 'end');

        if ($this->bulkError) {
            throw $this->bulkError;
        }

        return $this->metrics;
    }

    protected function resolveMetricClass(string $slug): ?string
    {
        return $this->metricClass;
    }

    protected function metricForCompany(string $class, $company): FleetOpsInternalMetricFake
    {
        $this->metricCalls[] = compact('class', 'company');

        return $this->metric;
    }

    protected function resolvePeriod(Request $request): array
    {
        $period = parent::resolvePeriod($request);

        $this->resolvedPeriods[] = array_map(
            fn ($date) => $date instanceof DateTimeInterface ? $date->format('Y-m-d H:i:s') : $date,
            $period
        );

        return $period;
    }
}

class FleetOpsInternalMetricsCollectionFake
{
    public array $discoveries = [];
    public array $data        = ['orders_completed' => 12, 'earnings' => 4500];

    public function with(array $discover): static
    {
        $this->discoveries[] = $discover;

        return $this;
    }

    public function get(): array
    {
        return $this->data;
    }
}

class FleetOpsInternalMetricFake
{
    public array $betweenCalls    = [];
    public array $compareCalls    = [];
    public array $sparklineCalls  = [];
    public array $data            = ['slug' => 'orders_completed', 'value' => 12, 'format' => 'number'];

    public static function forCompany($company): static
    {
        return new static();
    }

    public function between(DateTimeInterface $start, DateTimeInterface $end): static
    {
        $this->betweenCalls[] = [$start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')];

        return $this;
    }

    public function compareTo(DateTimeInterface $start, DateTimeInterface $end): static
    {
        $this->compareCalls[] = [$start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')];

        return $this;
    }

    public function withSparkline(int $buckets, string $unit): static
    {
        $this->sparklineCalls[] = [$buckets, $unit];

        return $this;
    }

    public function get(): array
    {
        return $this->data;
    }
}

class FleetOpsInternalMetricsRequestFake extends Request
{
    public function __construct(private array $values = [], private ?object $user = null)
    {
        parent::__construct($values);
    }

    public function user($guard = null): ?object
    {
        return $this->user;
    }

    public function string($key = null, $default = null): Stringable
    {
        return str((string) ($this->values[$key] ?? $default ?? ''));
    }

    public function array($key = null, $default = []): array
    {
        $value = $this->values[$key] ?? $default;

        return is_array($value) ? $value : $default;
    }

    public function boolean($key = null, $default = false): bool
    {
        return filter_var($this->values[$key] ?? $default, FILTER_VALIDATE_BOOLEAN);
    }

    public function input($key = null, $default = null): mixed
    {
        return $key === null ? $this->values : ($this->values[$key] ?? $default);
    }

    public function date($key = null, $format = null, $tz = null): ?Carbon
    {
        if (!array_key_exists($key, $this->values) || $this->values[$key] === null) {
            return null;
        }

        return Carbon::parse($this->values[$key], $tz);
    }
}

function fleetopsInternalMetricsUser(): object
{
    return (object) [
        'company' => (object) [
            'uuid' => 'company-uuid',
            'name' => 'Fleetbase Test Co',
        ],
    ];
}

test('internal metrics controller returns legacy bulk metrics and discovery selections', function () {
    $controller = new FleetOpsInternalMetricsControllerProbe();
    $request    = new FleetOpsInternalMetricsRequestFake([
        'start'    => '2026-07-01 00:00:00',
        'end'      => '2026-07-31 23:59:59',
        'discover' => ['orders_completed', 'earnings'],
    ], fleetopsInternalMetricsUser());

    $response = $controller->all($request);

    expect($response->getData(true))->toBe(['orders_completed' => 12, 'earnings' => 4500])
        ->and($controller->metrics->discoveries)->toBe([['orders_completed', 'earnings']])
        ->and($controller->bulkCalls[0]['company']->uuid)->toBe('company-uuid')
        ->and($controller->bulkCalls[0]['start']->format('Y-m-d H:i:s'))->toBe('2026-07-01 00:00:00')
        ->and($controller->bulkCalls[0]['end']->format('Y-m-d H:i:s'))->toBe('2026-07-31 23:59:59');
});

test('internal metrics controller converts bulk metric exceptions into error responses', function () {
    $controller            = new FleetOpsInternalMetricsControllerProbe();
    $controller->bulkError = new RuntimeException('Metrics backend unavailable.');

    $response = $controller->all(new FleetOpsInternalMetricsRequestFake([], fleetopsInternalMetricsUser()));

    expect($response->getData(true))->toBe(['error' => 'Metrics backend unavailable.'])
        ->and($response->getStatusCode())->toBe(500);
});

test('internal metrics controller reports unknown metric slugs', function () {
    $controller              = new FleetOpsInternalMetricsControllerProbe();
    $controller->metricClass = null;

    $response = $controller->show(new FleetOpsInternalMetricsRequestFake([], fleetopsInternalMetricsUser()), 'missing_metric');

    expect($response->getStatusCode())->toBe(404)
        ->and($response->getData(true))->toBe(['error' => 'Unknown metric: missing_metric']);
});

test('internal metrics controller applies compare and sparkline options for metric payloads', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-27 12:00:00'));

    try {
        $controller = new FleetOpsInternalMetricsControllerProbe();
        $request    = new FleetOpsInternalMetricsRequestFake([
            'period'            => '7d',
            'compare'           => true,
            'sparkline'         => true,
            'sparkline_buckets' => 9,
        ], fleetopsInternalMetricsUser());

        $response = $controller->show($request, 'orders_completed');

        expect($response->getData(true))->toBe(['slug' => 'orders_completed', 'value' => 12, 'format' => 'number'])
            ->and($controller->metricCalls)->toHaveCount(1)
            ->and($controller->metricCalls[0]['class'])->toBe(FleetOpsInternalMetricFake::class)
            ->and($controller->metricCalls[0]['company']->uuid)->toBe('company-uuid')
            ->and($controller->metric->betweenCalls)->toBe([['2026-07-20 12:00:00', '2026-07-27 12:00:00']])
            ->and($controller->metric->compareCalls)->toBe([['2026-07-13 12:00:00', '2026-07-20 12:00:00']])
            ->and($controller->metric->sparklineCalls)->toBe([[9, 'day']])
            ->and($controller->resolvedPeriods)->toBe([['2026-07-20 12:00:00', '2026-07-27 12:00:00']]);
    } finally {
        Carbon::setTestNow();
    }
});

test('internal metrics controller skips compare and uses explicit windows when requested', function () {
    $controller = new FleetOpsInternalMetricsControllerProbe();
    $request    = new FleetOpsInternalMetricsRequestFake([
        'start'   => '2026-06-01 00:00:00',
        'end'     => '2026-06-30 23:59:59',
        'compare' => false,
    ], fleetopsInternalMetricsUser());

    $response = $controller->show($request, 'orders_completed');

    expect($response->getStatusCode())->toBe(200)
        ->and($controller->metric->betweenCalls)->toBe([['2026-06-01 00:00:00', '2026-06-30 23:59:59']])
        ->and($controller->metric->compareCalls)->toBe([])
        ->and($controller->metric->sparklineCalls)->toBe([]);
});
