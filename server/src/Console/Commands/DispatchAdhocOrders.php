<?php

namespace Fleetbase\FleetOps\Console\Commands;

use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Support\Utils;
use Grimzy\LaravelMysqlSpatial\Types\Point;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class DispatchAdhocOrders extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'fleetops:dispatch-adhoc {--sandbox} {--testing}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Dispatch & ping adhoc orders where no driver is assigned, only if order isn\'t assigned after 2 minutes';

    /**
     * Execute the console command.
     *
     * This method is responsible for the main logic of the command.
     * It fetches dispatchable orders, notifies about the current time and number of orders,
     * and then dispatches each order if there are nearby drivers.
     *
     * @return int
     */
    public function handle()
    {
        // Set UTC as default timezone
        date_default_timezone_set('UTC');

        // Get all dispatchable orders
        $orders = $this->getDispatchableOrders();

        // Notify Current Time and # of Orders in Alera
        $this->alert('Found (' . $orders->count() . ') Orders to be Dispatched -- Current Time: ' . Carbon::now()->toDateTimeString());

        // Iterate for each order
        $orders->each(
            function ($order) {
                $pickup   = $order->getPickupLocation();
                $distance = $order->getAdhocPingDistance();

                if (!Utils::isPoint($pickup)) {
                    return;
                }

                // Get nearby drivers for this order
                $drivers = $this->getNearbyDriversForOrder($order, $pickup, $distance);

                // Inform
                $this->alert('Found (' . $drivers->count() . ') driver within ' . $distance . ' meters of the pickup location for order ' . $order->public_id);

                // Dispatch if there is nearby drivers
                if ($drivers->count()) {
                    $order->dispatch(true);
                    $this->info('Order ' . $order->public_id . ' dispatched!');
                }
            }
        );
    }

    /**
     * Fetches dispatchable orders based on certain criteria.
     */
    public function getDispatchableOrders(): \Illuminate\Database\Eloquent\Collection
    {
        $sandbox  = Utils::castBoolean($this->option('sandbox'));
        $interval = 4;

        return Order::on($sandbox ? 'sandbox' : 'mysql')
            ->withoutGlobalScopes()
            ->where(['adhoc' => 1, 'dispatched' => 1, 'started' => 0])
            ->whereDate('dispatched_at', '<=', Carbon::now()->subMinutes($interval)->toDateTimeString())
            ->whereNull('driver_assigned_uuid')
            ->whereNull('deleted_at')
            ->where('status', '!=', 'canceled')
            ->whereHas('company', function ($q) {
                $q->whereHas('users', function ($q) {
                    $q->whereHas('driver', function ($q) {
                        $q->where(['status' => 'active', 'online' => 1]);
                        $q->whereNull('deleted_at');
                    });
                });
            })
            ->whereHas('payload')
            ->with(['company', 'payload'])
            ->get();
    }

    /**
     * Fetches nearby drivers for a given order based on the pickup location and distance.
     */
    public function getNearbyDriversForOrder(Order $order, Point $pickup, int $distance): \Illuminate\Database\Eloquent\Collection
    {
        $testing = Utils::castBoolean($this->option('testing'));

        if ($testing) {
            // one for testing when cannoty be geospatially accurate
            $drivers = Driver::where(['status' => 'active', 'online' => 1])
                ->where(function ($q) use ($order) {
                    $q->where('company_uuid', $order->company_uuid);
                    $q->orWhereHas('user', function ($q) use ($order) {
                        $q->where('company_uuid', $order->company_uuid);
                    });
                })
                ->whereNull('deleted_at')
                ->withoutGlobalScopes()
                ->get();
        } else {
            $drivers = Driver::where(['status' => 'active', 'online' => 1])
                ->where(function ($q) use ($order) {
                    $q->where('company_uuid', $order->company_uuid);
                    $q->orWhereHas('user', function ($q) use ($order) {
                        $q->where('company_uuid', $order->company_uuid);
                    });
                })
                ->whereNull('deleted_at')
                ->distanceSphere('location', $pickup, $distance)
                ->distanceSphereValue('location', $pickup)
                ->withoutGlobalScopes()
                ->get();
        }

        return $drivers;
    }
}
