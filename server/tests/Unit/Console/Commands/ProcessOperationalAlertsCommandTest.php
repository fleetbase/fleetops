<?php

use Carbon\Carbon;
use Fleetbase\FleetOps\Console\Commands\ProcessOperationalAlerts;
use Fleetbase\FleetOps\Models\Order;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Cache;

class FleetOpsProcessOperationalAlertsCommandLockFake
{
    public bool $released = false;

    public function __construct(private bool $locked)
    {
    }

    public function get(): bool
    {
        return $this->locked;
    }

    public function release(): void
    {
        $this->released = true;
    }
}

class FleetOpsProcessOperationalAlertsCommandCacheFake
{
    public array $locks = [];

    public function __construct(private FleetOpsProcessOperationalAlertsCommandLockFake $lock)
    {
    }

    public function lock(string $key, int $seconds): FleetOpsProcessOperationalAlertsCommandLockFake
    {
        $this->locks[] = [$key, $seconds];

        return $this->lock;
    }
}

class FleetOpsProcessOperationalAlertsCommandOrderCollectionFake extends EloquentCollection
{
    public array $loadedMissing = [];

    public function loadMissing($relations)
    {
        $this->loadedMissing[] = $relations;

        return $this;
    }
}

class FleetOpsProcessOperationalAlertsCommandQueryFake
{
    public ArrayObject $operations;

    public function __construct(public FleetOpsProcessOperationalAlertsCommandOrderCollectionFake $orders, private int $count)
    {
        $this->operations = new ArrayObject();
    }

    public function count($columns = '*'): int
    {
        $this->operations[] = ['count', $columns];

        return $this->count;
    }

    public function orderBy($column, $direction = 'asc'): self
    {
        $this->operations[] = ['orderBy', $column, $direction];

        return $this;
    }

    public function chunkById($count, callable $callback, $column = null, $alias = null): bool
    {
        $this->operations[] = ['chunkById', $count, $column, $alias];
        $callback($this->orders);

        return true;
    }
}

class FleetOpsProcessOperationalAlertsCommandProbe extends ProcessOperationalAlerts
{
    public array $messages        = [];
    public array $cutoffs         = [];
    public array $settingsCalls   = [];
    public array $lateCalls       = [];
    public array $routeCalls      = [];
    public array $stoppageCalls   = [];
    public array $lateResults     = [];
    public array $routeResults    = [];
    public array $stoppageResults = [];

    public function __construct(private array $testOptions, private FleetOpsProcessOperationalAlertsCommandQueryFake $query)
    {
        parent::__construct();
    }

    public function option($key = null)
    {
        return $key === null ? $this->testOptions : ($this->testOptions[$key] ?? null);
    }

    public function info($string, $verbosity = null): void
    {
        $this->messages[] = ['info', $string];
    }

    public function warn($string, $verbosity = null): void
    {
        $this->messages[] = ['warn', $string];
    }

    protected function ordersQuery(Carbon $cutoff)
    {
        $this->cutoffs[] = $cutoff->toDateTimeString();

        return $this->query;
    }

    protected function alertSettings(): array
    {
        $this->settingsCalls[] = session('company');

        return [
            'late_departures'     => ['enabled' => true],
            'route_deviations'    => ['enabled' => true],
            'prolonged_stoppages' => ['enabled' => true],
        ];
    }

    protected function processLateDeparture(Order $order, array $settings, bool $dryRun): bool
    {
        $this->lateCalls[] = [$order->uuid, $settings, $dryRun];

        return array_shift($this->lateResults) ?? false;
    }

    protected function processRouteDeviation(Order $order, array $settings, bool $dryRun): bool
    {
        $this->routeCalls[] = [$order->uuid, $settings, $dryRun];

        return array_shift($this->routeResults) ?? false;
    }

    protected function processProlongedStoppage(Order $order, array $settings, bool $dryRun): bool
    {
        $this->stoppageCalls[] = [$order->uuid, $settings, $dryRun];

        return array_shift($this->stoppageResults) ?? false;
    }
}

function fleetopsProcessOperationalAlertsOrder(string $uuid, string $companyUuid): Order
{
    $order = new Order();
    $order->setRawAttributes([
        'uuid'         => $uuid,
        'company_uuid' => $companyUuid,
    ], true);

    return $order;
}

function fleetopsProcessOperationalAlertsCommand(array $options, array $orders, int $count): array
{
    $collection = new FleetOpsProcessOperationalAlertsCommandOrderCollectionFake($orders);
    $query      = new FleetOpsProcessOperationalAlertsCommandQueryFake($collection, $count);
    $command    = new FleetOpsProcessOperationalAlertsCommandProbe(array_merge([
        'days'    => 2,
        'chunk'   => 250,
        'dry'     => false,
        'no-lock' => false,
    ], $options), $query);

    return [$command, $query, $collection];
}

test('process operational alerts handle exits when another locked run is active', function () {
    $lock  = new FleetOpsProcessOperationalAlertsCommandLockFake(false);
    $cache = new FleetOpsProcessOperationalAlertsCommandCacheFake($lock);
    app()->instance('cache', $cache);
    Cache::clearResolvedInstance('cache');

    [$command] = fleetopsProcessOperationalAlertsCommand([], [], 1);

    expect($command->handle())->toBe(Command::SUCCESS)
        ->and($cache->locks)->toBe([['fleetops:process-operational-alerts', 600]])
        ->and($command->messages)->toBe([
            ['warn', 'Another operational alert run appears to be in progress.'],
        ])
        ->and($lock->released)->toBeFalse();
});

test('process operational alerts handle reports empty qualifying order sets without locking', function () {
    Carbon::setTestNow('2026-07-27 12:00:00');

    [$command, $query] = fleetopsProcessOperationalAlertsCommand([
        'days'    => 0,
        'chunk'   => 1,
        'no-lock' => true,
    ], [], 0);

    expect($command->handle())->toBe(Command::SUCCESS)
        ->and($command->cutoffs)->toBe(['2026-07-26 12:00:00'])
        ->and($query->operations->getArrayCopy())->toBe([['count', 'id']])
        ->and($command->messages)->toBe([
            ['info', 'No qualifying orders found.'],
        ]);

    Carbon::setTestNow();
});

test('process operational alerts handle processes chunks and releases acquired locks', function () {
    Carbon::setTestNow('2026-07-27 12:00:00');

    $lock  = new FleetOpsProcessOperationalAlertsCommandLockFake(true);
    $cache = new FleetOpsProcessOperationalAlertsCommandCacheFake($lock);
    app()->instance('cache', $cache);
    Cache::clearResolvedInstance('cache');

    [$command, $query, $collection] = fleetopsProcessOperationalAlertsCommand([
        'days'  => 3,
        'chunk' => 10,
        'dry'   => true,
    ], [
        fleetopsProcessOperationalAlertsOrder('order-one', 'company-one'),
        fleetopsProcessOperationalAlertsOrder('order-two', 'company-two'),
    ], 2);

    $command->lateResults     = [true, false];
    $command->routeResults    = [false, true];
    $command->stoppageResults = [true, false];

    expect($command->handle())->toBe(Command::SUCCESS)
        ->and($cache->locks)->toBe([['fleetops:process-operational-alerts', 600]])
        ->and($lock->released)->toBeTrue()
        ->and($command->cutoffs)->toBe(['2026-07-24 12:00:00'])
        ->and($query->operations->getArrayCopy())->toBe([
            ['count', 'id'],
            ['orderBy', 'id', 'asc'],
            ['chunkById', 50, null, null],
        ])
        ->and($collection->loadedMissing)->toBe([
            ['driverAssigned', 'vehicleAssigned', 'route'],
        ])
        ->and($command->settingsCalls)->toBe(['company-one', 'company-two'])
        ->and(session('company'))->toBe('company-two')
        ->and(array_column($command->lateCalls, 0))->toBe(['order-one', 'order-two'])
        ->and(array_column($command->routeCalls, 0))->toBe(['order-one', 'order-two'])
        ->and(array_column($command->stoppageCalls, 0))->toBe(['order-one', 'order-two'])
        ->and($command->lateCalls[0][2])->toBeTrue()
        ->and($command->messages)->toBe([
            ['info', '[Dry Run] Operational alerts triggered: 3'],
        ]);

    Carbon::setTestNow();
});
