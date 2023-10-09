<?php

namespace Fleetbase\FleetOps\Console\Commands;

use Fleetbase\FleetOps\Models\Order;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class TrackOrderDistanceAndTime extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'fleetops:update-estimations';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Track and update order distance and time estimations';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        // Set UTC as default timezone
        date_default_timezone_set('UTC');

        // Get all active/ready order
        $orders = $this->getActiveOrders();

        // Track updated orders
        $updated = [];

        // Notify Current Time and # of Orders in Alera
        $this->alert('Found (' . $orders->count() . ') Orders to update Tracking Estimation -- Current Time: ' . Carbon::now()->toDateTimeString());

        // Update for each order
        $orders->each(
            function ($order) use (&$updated) {
                $updated[] = $order->setDistanceAndTime()->id;
            }
        );

        // Update info
        $this->info('Updated ' . count($updated) . '/' . $orders->count() . ' orders Distance & Time Estimations.');
    }

    /**
     * Fetches active orders.
     */
    public function getActiveOrders(): \Illuminate\Database\Eloquent\Collection
    {
        return Order::whereNotIn('status', ['completed', 'canceled'])->whereNull(['deleted_at'])->whereNotNull('company_uuid')->whereHas('payload')->with(['payload', 'payload.waypoints', 'payload.pickup', 'payload.dropoff'])->withoutGlobalScopes()->get();
    }
}
