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
    protected $signature = 'fleetops:update-estimations {--provider : The distance and time calculation provider (calculate, google, or osrm)}';

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

        // Determine the provider
        $provider = $this->option('provider') ?? config('fleetops.distance_matrix.provider');
        $this->info("Using '{$provider}' as the provider for calculations.");

        // Get all active/ready orders
        $orders = $this->getActiveOrders();

        // Notify Current Time and # of Orders
        $this->alert('Found ' . $orders->count() . ' orders to update tracking estimations. Current Time: ' . Carbon::now()->toDateTimeString());

        // Update for each order
        $updated = 0;
        foreach ($orders as $order) {
            try {
                // Update order distance and time estimations based on the provider
                $order->setDistanceAndTime(['provider' => $provider]);
                $updated++;
                $this->info("Order ID {$order->id} - Distance & Time estimations updated.");
            } catch (\Exception $e) {
                $this->error("Failed to update Order ID {$order->id} - Error: " . $e->getMessage());
            }
        }

        // Summary info
        $this->info("Updated $updated/" . $orders->count() . " orders' Distance & Time Estimations.");
    }

    /**
     * Fetches active orders.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    protected function getActiveOrders()
    {
        return Order::whereNotIn('status', ['completed', 'canceled'])
                    ->whereNull('deleted_at')
                    ->whereNotNull('company_uuid')
                    ->whereHas('payload')
                    ->with(['payload', 'payload.waypoints', 'payload.pickup', 'payload.dropoff'])
                    ->withoutGlobalScopes()
                    ->get();
    }
}
