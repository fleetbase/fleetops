<?php

use Fleetbase\FleetOps\Support\Metrics;
use Fleetbase\FleetOps\Support\Metrics\AbstractMetric;
use Fleetbase\FleetOps\Support\Metrics\ActiveRevenueQuery;
use Fleetbase\FleetOps\Support\Metrics\AvgOrderValueMetric;
use Fleetbase\FleetOps\Support\Metrics\DriversOnlineMetric;
use Fleetbase\FleetOps\Support\Metrics\EarningsMetric;
use Fleetbase\FleetOps\Support\Metrics\MoneyMetric;
use Fleetbase\FleetOps\Support\Metrics\OpenIssuesMetric;
use Fleetbase\FleetOps\Support\Metrics\OrdersCanceledMetric;
use Fleetbase\FleetOps\Support\Metrics\OrdersCompletedMetric;
use Fleetbase\FleetOps\Support\Metrics\OrdersInProgressMetric;
use Fleetbase\FleetOps\Support\Metrics\OrdersScheduledMetric;
use Fleetbase\FleetOps\Support\Metrics\Registry;
use Fleetbase\FleetOps\Support\Metrics\ResolvedIssuesMetric;
use Fleetbase\FleetOps\Support\Metrics\TotalCustomersMetric;
use Fleetbase\FleetOps\Support\Metrics\TotalDistanceTraveledMetric;
use Fleetbase\FleetOps\Support\Metrics\TotalDriversMetric;
use Fleetbase\FleetOps\Support\Metrics\TotalTimeTraveledMetric;
use Fleetbase\Models\Company;
use Illuminate\Support\Carbon;

class TestFleetOpsMetric extends AbstractMetric
{
    public array $ranges = [];

    public static function slug(): string
    {
        return 'test_metric';
    }

    public function format(): string
    {
        return 'currency';
    }

    public function currency(): ?string
    {
        return 'USD';
    }

    protected function query(?DateTimeInterface $start, ?DateTimeInterface $end)
    {
        $this->ranges[] = [
            'start' => $start?->format('Y-m-d'),
            'end'   => $end?->format('Y-m-d'),
        ];

        return ['start' => $start, 'end' => $end];
    }

    protected function aggregate($query): float|int
    {
        if (!$query['start'] || !$query['end']) {
            return 10;
        }

        return match ($query['start']->format('Y-m-d')) {
            '2026-01-01' => 20,
            '2025-12-01' => 10,
            default      => (int) $query['start']->format('d'),
        };
    }
}

class TestFleetOpsMoneyMetric extends MoneyMetric
{
    public static function slug(): string
    {
        return 'test_money_metric';
    }

    protected function query(?DateTimeInterface $start, ?DateTimeInterface $end)
    {
        return null;
    }

    protected function aggregate($query): float|int
    {
        return 0;
    }
}

class TestFleetOpsAvgOrderValueMetric extends AvgOrderValueMetric
{
    public function aggregateForTest($query): float|int
    {
        return $this->aggregate($query);
    }
}

class TestFleetOpsOrdersScheduledMetric extends OrdersScheduledMetric
{
    public function aggregateForTest($query): int
    {
        return $this->aggregate($query);
    }
}

class TestFleetOpsOrdersCanceledMetric extends OrdersCanceledMetric
{
    public function aggregateForTest($query): int
    {
        return $this->aggregate($query);
    }
}

class TestFleetOpsOrdersCompletedMetric extends OrdersCompletedMetric
{
    public function aggregateForTest($query): int
    {
        return $this->aggregate($query);
    }
}

class TestFleetOpsOrdersInProgressMetric extends OrdersInProgressMetric
{
    public function aggregateForTest($query): int
    {
        return $this->aggregate($query);
    }
}

class TestFleetOpsOpenIssuesMetric extends OpenIssuesMetric
{
    public function aggregateForTest($query): int
    {
        return $this->aggregate($query);
    }
}

class TestFleetOpsResolvedIssuesMetric extends ResolvedIssuesMetric
{
    public function aggregateForTest($query): int
    {
        return $this->aggregate($query);
    }
}

class TestFleetOpsDriversOnlineMetric extends DriversOnlineMetric
{
    public function aggregateForTest($query): int
    {
        return $this->aggregate($query);
    }
}

class TestFleetOpsTotalDriversMetric extends TotalDriversMetric
{
    public function aggregateForTest($query): int
    {
        return $this->aggregate($query);
    }
}

class TestFleetOpsTotalCustomersMetric extends TotalCustomersMetric
{
    public function aggregateForTest($query): int
    {
        return $this->aggregate($query);
    }
}

class TestFleetOpsTotalDistanceTraveledMetric extends TotalDistanceTraveledMetric
{
    public function aggregateForTest($query): float
    {
        return $this->aggregate($query);
    }
}

class TestFleetOpsTotalTimeTraveledMetric extends TotalTimeTraveledMetric
{
    public function aggregateForTest($query): int
    {
        return $this->aggregate($query);
    }
}

class TestFleetOpsMetricCountQuery
{
    public function __construct(private int $count)
    {
    }

    public function count(): int
    {
        return $this->count;
    }
}

class TestFleetOpsMetricSumQuery
{
    public function __construct(private int|float $sum)
    {
    }

    public function sum(string $column): int|float
    {
        return $this->sum;
    }
}

class TestFleetOpsActiveRevenueQueryRecorder
{
    public array $calls = [];

    public function whereNotNull(string $column): static
    {
        $this->calls[] = ['whereNotNull', $column];

        return $this;
    }

    public function orWhereIn(string $column, array $values): static
    {
        $this->calls[] = ['orWhereIn', $column, $values];

        return $this;
    }

    public function orWhereExists(Closure $callback): static
    {
        $nested = new static();
        $callback($nested);
        $this->calls[] = ['orWhereExists', $nested->calls];

        return $this;
    }

    public function selectRaw(string $expression): static
    {
        $this->calls[] = ['selectRaw', $expression];

        return $this;
    }

    public function from(string $table): static
    {
        $this->calls[] = ['from', $table];

        return $this;
    }

    public function whereColumn(string $first, string $second): static
    {
        $this->calls[] = ['whereColumn', $first, $second];

        return $this;
    }

    public function where(Closure $callback): static
    {
        $nested = new static();
        $callback($nested);
        $this->calls[] = ['where', $nested->calls];

        return $this;
    }
}

test('registry exposes every known metric slug', function () {
    $slugs = Registry::slugs();

    expect($slugs)->toContain('earnings');
    expect($slugs)->toContain('fuel_costs');
    expect($slugs)->toContain('total_distance_traveled');
    expect($slugs)->toContain('total_time_traveled');
    expect($slugs)->toContain('orders_completed');
    expect($slugs)->toContain('orders_canceled');
    expect($slugs)->toContain('orders_in_progress');
    expect($slugs)->toContain('orders_scheduled');
    expect($slugs)->toContain('active_live_orders');
    expect($slugs)->toContain('drivers_online');
    expect($slugs)->toContain('total_drivers');
    expect($slugs)->toContain('total_customers');
    expect($slugs)->toContain('open_issues');
    expect($slugs)->toContain('resolved_issues');
    expect($slugs)->toContain('avg_order_value');
});

test('money metrics expose money format and company or override currency', function () {
    $company = new Company();
    $company->setRawAttributes(['currency' => 'SGD'], true);

    $metric = TestFleetOpsMoneyMetric::forCompany($company);

    expect($metric->format())->toBe('money')
        ->and($metric->currency())->toBe('SGD')
        ->and($metric->inCurrency('EUR'))->toBe($metric)
        ->and($metric->currency())->toBe('EUR');

    $fallbackCompany = new Company();

    expect(TestFleetOpsMoneyMetric::forCompany($fallbackCompany)->currency())->toBe('USD');
});

test('average order value metric exposes slug and returns zero when there are no completed orders', function () {
    $company = new Company();
    $company->setRawAttributes(['uuid' => 'company-metrics', 'currency' => 'SGD'], true);

    $metric = TestFleetOpsAvgOrderValueMetric::forCompany($company);

    expect(TestFleetOpsAvgOrderValueMetric::slug())->toBe('avg_order_value')
        ->and($metric->format())->toBe('money')
        ->and($metric->currency())->toBe('SGD')
        ->and($metric->aggregateForTest(new TestFleetOpsMetricCountQuery(0)))->toBe(0.0);
});

test('registry resolves unknown slugs to null', function () {
    expect(Registry::resolve('does_not_exist'))->toBeNull();
});

test('metrics facade resolves configured metrics and ignores unknown legacy names', function () {
    $company = new Company();
    $company->setRawAttributes(['uuid' => 'company-metrics'], true);
    $start = Carbon::parse('2026-01-01 00:00:00');
    $end   = Carbon::parse('2026-01-31 23:59:59');

    $metrics = Metrics::forCompany($company, $start, $end);

    expect(Metrics::new($company))->toBeInstanceOf(Metrics::class)
        ->and($metrics->start($start))->toBe($metrics)
        ->and($metrics->end($end))->toBe($metrics)
        ->and($metrics->between($start, $end))->toBe($metrics)
        ->and($metrics->resolve('earnings'))->toBeInstanceOf(EarningsMetric::class)
        ->and($metrics->resolve('not_registered'))->toBeNull()
        ->and($metrics->with(['notRegisteredMetric']))->toBe($metrics)
        ->and($metrics->get())->toBe([]);
});

test('every registered metric extends the abstract base', function () {
    foreach (Registry::all() as $slug => $class) {
        expect(is_subclass_of($class, AbstractMetric::class))
            ->toBeTrue("Metric class for slug '{$slug}' must extend AbstractMetric");
        expect($class::slug())->toBe($slug);
    }
});

test('open and resolved issue metrics scope to the constructor company (not session)', function () {
    $sourceOpen     = file_get_contents(dirname(__DIR__) . '/src/Support/Metrics/OpenIssuesMetric.php');
    $sourceResolved = file_get_contents(dirname(__DIR__) . '/src/Support/Metrics/ResolvedIssuesMetric.php');

    expect($sourceOpen)->not->toContain("session('company')");
    expect($sourceOpen)->toContain('$this->company->uuid');
    expect($sourceResolved)->not->toContain("session('company')");
    expect($sourceResolved)->toContain('$this->company->uuid');
});

test('total_time_traveled metric sums the time column, not distance', function () {
    $source = file_get_contents(dirname(__DIR__) . '/src/Support/Metrics/TotalTimeTraveledMetric.php');

    expect($source)->toContain("\$query->sum('time')");
    expect($source)->not->toContain("\$query->sum('distance')");
    expect(TotalTimeTraveledMetric::slug())->toBe('total_time_traveled');
});

test('money metrics return float values to preserve cents', function () {
    $earnings  = file_get_contents(dirname(__DIR__) . '/src/Support/Metrics/EarningsMetric.php');
    $fuelCosts = file_get_contents(dirname(__DIR__) . '/src/Support/Metrics/FuelCostsMetric.php');

    expect($earnings)->toContain('(float) $query->sum');
    expect($earnings)->not->toMatch('/\(int\)\s*\$query->sum/');
    expect($fuelCosts)->toContain('(float) $query->sum');
    expect($fuelCosts)->not->toMatch('/\(int\)\s*\$query->sum/');
});

test('money metrics filter by currency to avoid mixed-currency sums', function () {
    $earnings      = file_get_contents(dirname(__DIR__) . '/src/Support/Metrics/EarningsMetric.php');
    $fuelCosts     = file_get_contents(dirname(__DIR__) . '/src/Support/Metrics/FuelCostsMetric.php');
    $aov           = file_get_contents(dirname(__DIR__) . '/src/Support/Metrics/AvgOrderValueMetric.php');
    $activeRevenue = file_get_contents(dirname(__DIR__) . '/src/Support/Metrics/ActiveRevenueQuery.php');

    expect($earnings)->toContain('ActiveRevenueQuery::forCompany');
    expect($fuelCosts)->toContain("->where('currency'");
    expect($aov)->toContain('ActiveRevenueQuery::forCompany');
    expect($activeRevenue)->toContain("->where('currency'");
});

test('earnings use the shared active revenue query', function () {
    $earnings     = file_get_contents(dirname(__DIR__) . '/src/Support/Metrics/EarningsMetric.php');
    $aov          = file_get_contents(dirname(__DIR__) . '/src/Support/Metrics/AvgOrderValueMetric.php');
    $revenueTrend = file_get_contents(dirname(__DIR__) . '/src/Support/Analytics/RevenueTrend.php');

    expect($earnings)->toContain('ActiveRevenueQuery::forCompany');
    expect($aov)->toContain('ActiveRevenueQuery::forCompany');
    expect($revenueTrend)->toContain('ActiveRevenueQuery::forCompany');
});

test('active revenue query excludes inactive financial and operational lifecycle records', function () {
    $source = file_get_contents(dirname(__DIR__) . '/src/Support/Metrics/ActiveRevenueQuery.php');

    expect($source)->toContain("where('direction', self::CREDIT_DIRECTION)");
    expect($source)->toContain("whereIn('status', self::ACTIVE_STATUSES)");
    expect($source)->toContain("whereNull('voided_at')");
    expect($source)->toContain("whereNull('reversed_at')");
    expect($source)->toContain("whereNull('parent_transaction_uuid')");
    expect($source)->toContain('excludeInactiveOrders');
    expect($source)->toContain('excludeInactiveInvoices');
    expect($source)->toContain('ledger_invoices.deleted_at');
    expect($source)->toContain('orders.deleted_at');
    expect(ActiveRevenueQuery::CREDIT_DIRECTION)->toBe('credit');
    expect(ActiveRevenueQuery::ACTIVE_STATUSES)->toBe(['success']);
    expect(ActiveRevenueQuery::ACTIVE_STATUSES)->not->toContain('completed');
    expect(ActiveRevenueQuery::ACTIVE_STATUSES)->not->toContain('paid');
});

test('active revenue query treats invoice status as invalidation only', function () {
    $inactiveInvoiceStatuses = ActiveRevenueQuery::INACTIVE_INVOICE_STATUSES;

    expect($inactiveInvoiceStatuses)->not->toContain('draft');
    expect($inactiveInvoiceStatuses)->toContain('void');
    expect($inactiveInvoiceStatuses)->toContain('voided');
    expect($inactiveInvoiceStatuses)->toContain('cancelled');
    expect($inactiveInvoiceStatuses)->toContain('canceled');
});

test('active revenue query constraints mark inactive orders and invoices', function () {
    app()->instance('db.schema', new class {
        public function hasTable(string $table): bool
        {
            return $table === 'orders';
        }
    });

    $orderQuery   = new TestFleetOpsActiveRevenueQueryRecorder();
    $invoiceQuery = new TestFleetOpsActiveRevenueQueryRecorder();

    $orderConstraint   = new ReflectionMethod(ActiveRevenueQuery::class, 'inactiveOrderConstraint');
    $invoiceConstraint = new ReflectionMethod(ActiveRevenueQuery::class, 'inactiveInvoiceConstraint');
    $orderConstraint->setAccessible(true);
    $invoiceConstraint->setAccessible(true);

    $orderConstraint->invoke(null, $orderQuery);
    $invoiceConstraint->invoke(null, $invoiceQuery);

    expect($orderQuery->calls)->toContain(['whereNotNull', 'orders.deleted_at'])
        ->and($orderQuery->calls)->toContain(['orWhereIn', 'orders.status', ActiveRevenueQuery::INACTIVE_ORDER_STATUSES])
        ->and($invoiceQuery->calls)->toContain(['whereNotNull', 'ledger_invoices.deleted_at'])
        ->and($invoiceQuery->calls)->toContain(['orWhereIn', 'ledger_invoices.status', ActiveRevenueQuery::INACTIVE_INVOICE_STATUSES])
        ->and($invoiceQuery->calls)->toHaveCount(3)
        ->and($invoiceQuery->calls[2][0])->toBe('orWhereExists');
});

test('ordersInProgress uses an explicit allowlist rather than an exclusion list', function () {
    $company = new Company();
    $company->setRawAttributes(['uuid' => 'company-orders-in-progress'], true);

    $metric   = TestFleetOpsOrdersInProgressMetric::forCompany($company);
    $statuses = OrdersInProgressMetric::IN_PROGRESS_STATUSES;
    $source   = file_get_contents(dirname(__DIR__) . '/src/Support/Metrics/OrdersInProgressMetric.php');

    expect(OrdersInProgressMetric::slug())->toBe('orders_in_progress')
        ->and($metric->format())->toBe('count')
        ->and($metric->aggregateForTest(new TestFleetOpsMetricCountQuery(9)))->toBe(9)
        ->and($statuses)->toBeArray()
        ->and($statuses)->not->toBeEmpty()
        ->and($statuses)->toContain('dispatched')
        ->and($statuses)->toContain('started')
        ->and($source)->toContain("where('company_uuid', \$this->company->uuid)")
        ->and($source)->toContain("whereIn('status', self::IN_PROGRESS_STATUSES)")
        ->and($source)->toContain("whereBetween('created_at', [\$start, \$end])");
});

test('orders scheduled metric scopes to future created orders for the company', function () {
    $company = new Company();
    $company->setRawAttributes(['uuid' => 'company-scheduled'], true);

    $metric = TestFleetOpsOrdersScheduledMetric::forCompany($company);
    $source = file_get_contents(dirname(__DIR__) . '/src/Support/Metrics/OrdersScheduledMetric.php');

    expect(OrdersScheduledMetric::slug())->toBe('orders_scheduled')
        ->and($metric->format())->toBe('count')
        ->and($metric->aggregateForTest(new TestFleetOpsMetricCountQuery(7)))->toBe(7)
        ->and($source)->toContain("where('company_uuid', \$this->company->uuid)")
        ->and($source)->toContain("where('status', 'created')")
        ->and($source)->toContain("whereDate('scheduled_at', '>', Carbon::now())")
        ->and($source)->toContain("whereBetween('created_at', [\$start, \$end])");
});

test('simple count metrics expose query scopes and count aggregation', function (string $class, string $sourceFile, string $slug, array $expectedSource) {
    $company = new Company();
    $company->setRawAttributes(['uuid' => 'company-simple-metric'], true);

    /** @var object $metric */
    $metric = $class::forCompany($company);
    $source = file_get_contents(dirname(__DIR__) . '/src/Support/Metrics/' . $sourceFile);

    expect($class::slug())->toBe($slug)
        ->and($metric->format())->toBe('count')
        ->and($metric->aggregateForTest(new TestFleetOpsMetricCountQuery(11)))->toBe(11);

    foreach ($expectedSource as $fragment) {
        expect($source)->toContain($fragment);
    }
})->with([
    [TestFleetOpsOrdersCanceledMetric::class, 'OrdersCanceledMetric.php', 'orders_canceled', ["where('company_uuid', \$this->company->uuid)", "where('status', 'canceled')", "whereBetween('created_at', [\$start, \$end])"]],
    [TestFleetOpsOrdersCompletedMetric::class, 'OrdersCompletedMetric.php', 'orders_completed', ["where('company_uuid', \$this->company->uuid)", "where('status', 'completed')", "whereBetween('created_at', [\$start, \$end])"]],
    [TestFleetOpsOpenIssuesMetric::class, 'OpenIssuesMetric.php', 'open_issues', ["where('company_uuid', \$this->company->uuid)", "where('status', 'pending')", "whereBetween('created_at', [\$start, \$end])"]],
    [TestFleetOpsResolvedIssuesMetric::class, 'ResolvedIssuesMetric.php', 'resolved_issues', ["where('company_uuid', \$this->company->uuid)", 'whereNotNull(\'resolved_at\')', "whereBetween('resolved_at', [\$start, \$end])"]],
    [TestFleetOpsDriversOnlineMetric::class, 'DriversOnlineMetric.php', 'drivers_online', ["where('company_uuid', \$this->company->uuid)", "where('online', true)", "whereNotNull('current_job_uuid')"]],
    [TestFleetOpsTotalDriversMetric::class, 'TotalDriversMetric.php', 'total_drivers', ["where('company_uuid', \$this->company->uuid)"]],
    [TestFleetOpsTotalCustomersMetric::class, 'TotalCustomersMetric.php', 'total_customers', ["where('company_uuid', \$this->company->uuid)", "where('type', 'customer')"]],
]);

test('distance and time metrics expose completed-order scopes and sum aggregation', function () {
    $company = new Company();
    $company->setRawAttributes(['uuid' => 'company-distance-time'], true);

    $distance       = TestFleetOpsTotalDistanceTraveledMetric::forCompany($company);
    $time           = TestFleetOpsTotalTimeTraveledMetric::forCompany($company);
    $distanceSource = file_get_contents(dirname(__DIR__) . '/src/Support/Metrics/TotalDistanceTraveledMetric.php');
    $timeSource     = file_get_contents(dirname(__DIR__) . '/src/Support/Metrics/TotalTimeTraveledMetric.php');

    expect(TotalDistanceTraveledMetric::slug())->toBe('total_distance_traveled')
        ->and($distance->format())->toBe('meters')
        ->and($distanceSource)->toContain("where('status', 'completed')", "whereBetween('created_at', [\$start, \$end])", "\$query->sum('distance')")
        ->and($distance->aggregateForTest(new TestFleetOpsMetricSumQuery(1234.5)))->toBe(1234.5)
        ->and(TotalTimeTraveledMetric::slug())->toBe('total_time_traveled')
        ->and($time->format())->toBe('duration')
        ->and($timeSource)->toContain("where('status', 'completed')", "whereBetween('created_at', [\$start, \$end])", "\$query->sum('time')")
        ->and($time->aggregateForTest(new TestFleetOpsMetricSumQuery(42)))->toBe(2520);
});

test('abstract metric builds value delta currency and sparkline payloads', function () {
    $company = new Company();
    $company->setRawAttributes(['uuid' => 'company-1'], true);

    $metric = TestFleetOpsMetric::forCompany($company)
        ->between(Carbon::parse('2026-01-01'), Carbon::parse('2026-02-01'))
        ->compareTo(Carbon::parse('2025-12-01'), Carbon::parse('2026-01-01'))
        ->withSparkline(2, 'day');

    expect($metric->value())->toBe(20)
        ->and($metric->delta())->toBe(100.0)
        ->and($metric->sparkline())->toBe([
            'labels' => ['2026-01-30', '2026-01-31'],
            'data'   => [30, 31],
        ])
        ->and($metric->get())->toMatchArray([
            'slug'      => 'test_metric',
            'value'     => 20,
            'format'    => 'currency',
            'currency'  => 'USD',
            'delta_pct' => 100.0,
            'sparkline' => [
                'labels' => ['2026-01-30', '2026-01-31'],
                'data'   => [30, 31],
            ],
        ]);
});

test('abstract metric handles missing comparison and zero previous periods', function () {
    expect((new TestFleetOpsMetric())->delta())->toBeNull()
        ->and((new TestFleetOpsMetric())->withSparkline(0)->sparkline())->toBeNull();

    $zeroPrevious = new class extends TestFleetOpsMetric {
        protected function aggregate($query): float|int
        {
            return $query['start']?->format('Y-m-d') === '2025-12-01' ? 0 : 5;
        }
    };

    $flatPrevious = new class extends TestFleetOpsMetric {
        protected function aggregate($query): float|int
        {
            return 0;
        }
    };

    expect($zeroPrevious
        ->between(Carbon::parse('2026-01-01'), Carbon::parse('2026-02-01'))
        ->compareTo(Carbon::parse('2025-12-01'), Carbon::parse('2026-01-01'))
        ->delta())->toBe(100.0)
        ->and($flatPrevious
            ->between(Carbon::parse('2026-01-01'), Carbon::parse('2026-02-01'))
            ->compareTo(Carbon::parse('2025-12-01'), Carbon::parse('2026-01-01'))
            ->delta())->toBe(0.0);
});
