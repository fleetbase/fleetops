<?php

use Fleetbase\Ai\Models\AiTask;
use Fleetbase\Ai\Support\AiRelativeDateResolver;
use Fleetbase\FleetOps\Support\Ai\Capabilities\OrderInsightsCapability;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class FleetOpsOrderInsightsQueryFake
{
    public array $calls = [];
    public stdClass $recorder;

    public function __construct(
        public int $count = 7,
        public array $countsByStatus = ['completed' => 5, 'active' => 2],
        public array $sampleOrderIds = ['order_1', null, 'order_2'],
    ) {
        $this->recorder        = new stdClass();
        $this->recorder->calls = [];
    }

    public function __clone()
    {
        $this->record(['clone']);
    }

    public function recordedCalls(): array
    {
        return $this->recorder->calls;
    }

    private function record(array $call): void
    {
        $this->calls[]           = $call;
        $this->recorder->calls[] = $call;
    }

    public function whereBetween(string $column, array $window): self
    {
        $this->record(['whereBetween', $column, $window]);

        return $this;
    }

    public function whereHas(string $relation, Closure $callback): self
    {
        $transaction = new FleetOpsOrderInsightsTransactionQueryFake();
        $callback($transaction);
        $this->record(['whereHas', $relation, $transaction->calls]);

        return $this;
    }

    public function count(): int
    {
        $this->record(['count']);

        return $this->count;
    }

    public function selectRaw(string $raw): self
    {
        $this->record(['selectRaw', $raw]);

        return $this;
    }

    public function groupBy(string $column): self
    {
        $this->record(['groupBy', $column]);

        return $this;
    }

    public function pluck(string $value, ?string $key = null): Collection
    {
        $this->record(['pluck', $value, $key]);

        if ($key === 'status') {
            return collect($this->countsByStatus);
        }

        return collect($this->sampleOrderIds);
    }

    public function latest(): self
    {
        $this->record(['latest']);

        return $this;
    }

    public function limit(int $limit): self
    {
        $this->record(['limit', $limit]);

        return $this;
    }
}

class FleetOpsOrderInsightsTransactionQueryFake
{
    public array $calls = [];

    public function where(string $column, string $operator, float $amount): self
    {
        $this->calls[] = ['where', $column, $operator, $amount];

        return $this;
    }
}

class FleetOpsOrderInsightsCapabilityFake extends OrderInsightsCapability
{
    public ?FleetOpsOrderInsightsQueryFake $query  = null;
    public bool $allowed                           = true;
    public array $companies                        = [];
    public ?array $window                          = null;

    protected function can(string $permission): bool
    {
        return $this->allowed && $permission === 'fleet-ops see order';
    }

    protected function orderQuery(?string $companyUuid): mixed
    {
        $this->companies[] = $companyUuid;

        return $this->query;
    }

    protected function relativeDateResolver(): AiRelativeDateResolver
    {
        return new class($this->window) extends AiRelativeDateResolver {
            public function __construct(private ?array $window)
            {
            }

            public function resolveWindow(string $prompt, ?string $timezone = null): ?array
            {
                return $this->window;
            }
        };
    }
}

function fleetopsOrderInsightsTask(string $prompt): AiTask
{
    return new AiTask(['prompt' => $prompt]);
}

test('order insights resolve denies unauthorized users before querying orders', function () {
    $capability          = new FleetOpsOrderInsightsCapabilityFake();
    $capability->allowed = false;
    $capability->query   = new FleetOpsOrderInsightsQueryFake();

    expect($capability->resolve(fleetopsOrderInsightsTask('how many orders today')))->toBe([
        'authorized' => false,
        'message'    => 'Current user cannot access Fleet-Ops orders.',
    ])
        ->and($capability->companies)->toBe([]);
});

test('order insights resolve returns bounded aggregate order metrics', function () {
    session(['company' => 'company-123']);

    $start = Carbon::parse('2026-07-01 00:00:00', 'UTC');
    $end   = Carbon::parse('2026-07-31 23:59:59', 'UTC');

    $capability         = new FleetOpsOrderInsightsCapabilityFake();
    $capability->query  = new FleetOpsOrderInsightsQueryFake();
    $capability->window = [
        'label'    => 'this month',
        'timezone' => 'UTC',
        'start'    => $start,
        'end'      => $end,
    ];

    $result = $capability->resolve(fleetopsOrderInsightsTask('orders over $125.50 this month by status'));

    expect($result)->toMatchArray([
        'authorized'       => true,
        'metric'           => 'orders',
        'amount_threshold' => 125.50,
        'count'            => 7,
        'counts_by_status' => ['completed' => 5, 'active' => 2],
        'sample_order_ids' => ['order_1', 'order_2'],
    ])
        ->and($result['date_window'])->toBe([
            'label'    => 'this month',
            'timezone' => 'UTC',
            'start'    => '2026-07-01T00:00:00+00:00',
            'end'      => '2026-07-31T23:59:59+00:00',
        ])
        ->and($capability->companies)->toBe(['company-123'])
        ->and($capability->query->recordedCalls())->toContain(
            ['whereBetween', 'created_at', [$start, $end]],
            ['whereHas', 'transaction', [['where', 'amount', '>', 125.50]]],
            ['count'],
            ['selectRaw', 'status, count(*) as aggregate'],
            ['groupBy', 'status'],
            ['pluck', 'aggregate', 'status'],
            ['latest'],
            ['limit', 10],
            ['pluck', 'public_id', null],
        );
});

test('order insights resolve omits optional filters when prompt has no date or amount window', function () {
    session(['company' => 'company-456']);

    $capability        = new FleetOpsOrderInsightsCapabilityFake();
    $capability->query = new FleetOpsOrderInsightsQueryFake(count: 0, countsByStatus: [], sampleOrderIds: []);

    $result = $capability->resolve(fleetopsOrderInsightsTask('order status report'));

    expect($result['date_window'])->toBeNull()
        ->and($result['amount_threshold'])->toBeNull()
        ->and($result['count'])->toBe(0)
        ->and($result['counts_by_status'])->toBe([])
        ->and($result['sample_order_ids'])->toBe([])
        ->and($capability->companies)->toBe(['company-456'])
        ->and($capability->query->recordedCalls())->not->toContain(['whereBetween'])
        ->and($capability->query->recordedCalls())->not->toContain(['whereHas']);
});
