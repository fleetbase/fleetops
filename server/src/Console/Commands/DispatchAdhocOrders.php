<?php

namespace Fleetbase\FleetOps\Console\Commands;

use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\OrderOffer;
use Fleetbase\FleetOps\Notifications\OrderPing;
use Fleetbase\FleetOps\Support\DriverMatching;
use Fleetbase\FleetOps\Support\NavigatorReview;
use Fleetbase\FleetOps\Support\OrderOffers;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
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
    protected $description = 'Dispatch & ping ad-hoc orders without assigned drivers (within a recent time window)';

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
            // Orders with timed offers go to one driver at a time, so only a stuck offer is moved on here.
            if ($this->usesTimedOffers($order)) {
                $this->advanceOffers($order);
                continue;
            }

            $pickup   = $order->getPickupLocation();
            $distance = $order->getAdhocPingDistance();

            if (!Utils::isPoint($pickup)) {
                $this->error('Invalid pickup location for order ' . $order->public_id);
                continue;
            }

            $drivers = $this->getNearbyDriversForOrder($order, $pickup, $distance, $testingMode);

            $this->line('Checking order ' . $order->public_id . ' for nearby drivers within ' . $distance . ' meters.');

            if ($drivers->count()) {
                // Dispatching again would fire OrderDispatched, whose listener pings every driver a second time.
                $this->markPinged($order);
                $this->info('Order ' . $order->public_id . ' dispatched successfully to ' . $drivers->count() . ' nearby drivers.');
                foreach ($drivers as $driver) {
                    $this->info('Pinging driver ' . $driver->name . ' (' . $driver->public_id . ') ...');
                    $driver->notify(new OrderPing($order, $distance));
                }
            } else {
                $this->warn('No available drivers found for order ' . $order->public_id);
            }
        }
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

    /**
     * Fetch nearby drivers for a given order.
     */
    public function getNearbyDriversForOrder(Order $order, Point $pickup, int $distance, bool $testing = false): Collection
    {
        $driverQuery = $this->newDriverQuery()
            ->where(['online' => 1])
            ->where(function ($q) use ($order) {
                $q->where('company_uuid', $order->company_uuid)
                    ->orWhereHas('user', fn ($q) => $q->where('company_uuid', $order->company_uuid));
            })
            ->whereNull('deleted_at')
            ->withoutGlobalScopes();

        // An app store reviewer's driver is never offered a real order.
        NavigatorReview::excludeFrom($driverQuery);

        if (!$testing) {
            $driverQuery->whereNotNull('location')->whereRaw('
                ST_Y(location) BETWEEN -90 AND 90
                AND ST_X(location) BETWEEN -180 AND 180
                AND NOT (ST_X(location) = 0 AND ST_Y(location) = 0)
            ');
            $driverQuery->distanceSphere('location', $pickup, $distance);
            $driverQuery->distanceSphereValue('location', $pickup);
        }

        return $this->matchDrivers($driverQuery, $order)->get();
    }

    /**
     * Leave out drivers already on another job and drivers of another vehicle class.
     */
    protected function matchDrivers($driverQuery, Order $order)
    {
        DriverMatching::notBusy($driverQuery);

        return DriverMatching::forVehicleClass($driverQuery, DriverMatching::vehicleClass($order));
    }

    /**
     * Restart the order's re-ping interval without dispatching it again.
     */
    protected function markPinged(Order $order): void
    {
        $order->forceFill(['dispatched_at' => Carbon::now()])->saveQuietly();
    }

    protected function usesTimedOffers(Order $order): bool
    {
        return (bool) $order->config()?->usesTimedOffers();
    }

    /**
     * Close an offer whose expiry job was lost, or start offers that never began.
     */
    protected function advanceOffers(Order $order): void
    {
        $pending = OrderOffers::pending($order);
        if ($pending && $pending->expires_at?->isPast()) {
            OrderOffers::expire($pending);
            $this->info('Moved order ' . $order->public_id . ' to the next driver.');
        } elseif (!$pending && !OrderOffer::where('order_uuid', $order->uuid)->exists()) {
            OrderOffers::start($order);
            $this->info('Started offers for order ' . $order->public_id . '.');
        }
    }

    protected function newOrderQuery(string $connection)
    {
        return Order::on($connection);
    }

    protected function newDriverQuery()
    {
        return Driver::query();
    }
}
