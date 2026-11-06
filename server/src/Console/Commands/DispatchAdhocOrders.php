<?php

namespace Fleetbase\FleetOps\Console\Commands;

use Fleetbase\FleetOps\Contracts\AdhocDispatchStrategy;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Support\AdhocDispatch\AdhocDispatch;
use Fleetbase\FleetOps\Support\Utils;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

class DispatchAdhocOrders extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'fleetops:dispatch-adhoc 
        {--sandbox : Run in sandbox mode} 
        {--testing : Enable testing mode (no geospatial filters)} 
        {--days=2 : Only include orders dispatched in the past N days (default: 2)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Offer ad-hoc orders still without a driver again, through each order\'s dispatch strategy (within a recent time window)';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        date_default_timezone_set('UTC');

        $sandboxMode = Utils::castBoolean($this->option('sandbox'));
        $testingMode = Utils::castBoolean($this->option('testing'));
        $days        = max(1, (int) $this->option('days'));

        $this->info('Running in ' . ($sandboxMode ? 'sandbox' : 'production') . ' mode.');
        $this->info("Looking back {$days} day(s) for dispatchable orders...");

        $orders = $this->getDispatchableOrders($days);

        if ($orders->isEmpty()) {
            $this->info('No dispatchable orders found in the given timeframe.');

            return;
        }

        $this->alert($orders->count() . ' orders found for ad-hoc dispatch. Current Time: ' . Carbon::now()->toDateTimeString());
        $this->table(['Order', 'Dispatched At'], $orders->map(fn ($order) => [$order->public_id, $order->dispatched_at]));

        foreach ($orders as $order) {
            $strategy = $this->strategyFor($order);
            $offered  = $strategy->redispatch($order, $testingMode);

            if ($offered) {
                $this->info('Order ' . $order->public_id . ' offered to ' . $offered . ' driver(s) (' . $strategy->name() . ').');
            } else {
                $this->line('No new offer for order ' . $order->public_id . ' (' . $strategy->name() . ').');
            }
        }
    }

    /**
     * The strategy that offers the order to drivers.
     */
    protected function strategyFor(Order $order): AdhocDispatchStrategy
    {
        return AdhocDispatch::for($order);
    }

    /**
     * Retrieve a collection of dispatchable orders created or dispatched within the last N days.
     *
     * @param int $days            Number of days to look back (default: 2)
     * @param int $intervalMinutes Minimum age of the order in minutes to be considered dispatchable (default: 4)
     * @param int $expiryHours     Maximum dispatchable age in hours (default: 72)
     *
     * @return \Illuminate\Support\Collection
     */
    public function getDispatchableOrders(int $days = 2, int $intervalMinutes = 4, int $expiryHours = 72): Collection
    {
        $sandbox = Utils::castBoolean($this->option('sandbox'));

        // Each bound gets its own instance: subtracting from one shared Carbon moved both ends
        // back 72 hours, leaving a window that never matched.
        $cutoffDate = Carbon::now()->subDays($days);
        $oldest     = Carbon::now()->subHours($expiryHours);
        $newest     = Carbon::now()->subMinutes($intervalMinutes);

        return $this->newOrderQuery($sandbox ? 'sandbox' : 'mysql')
            ->withoutGlobalScopes()
            ->where(['adhoc' => 1, 'dispatched' => 1, 'started' => 0])
            ->whereBetween('dispatched_at', [$oldest, $newest])
            ->where('created_at', '>=', $cutoffDate)
            ->whereNull('driver_assigned_uuid')
            ->whereNull('deleted_at')
            ->where('status', '!=', 'canceled')
            ->whereHas('company', function ($q) {
                $q->whereHas('users', function ($q) {
                    $q->whereHas('driver', function ($q) {
                        $q->where('online', 1)->whereNull('deleted_at');
                    });
                });
            })
            ->whereHas('payload')
            ->with(['company', 'payload'])
            ->get();
    }

    protected function newOrderQuery(string $connection)
    {
        return Order::on($connection);
    }
}
