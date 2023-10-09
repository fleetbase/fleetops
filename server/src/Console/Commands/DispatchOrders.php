<?php

namespace Fleetbase\FleetOps\Console\Commands;

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Support\Utils;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class DispatchOrders extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'fleetops:dispatch-orders {--sandbox=false}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Dispatches scheduled orders.';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        // Set UTC as default timezone
        date_default_timezone_set('UTC');

        // Get all scheduled dispatchable orders
        $orders = $this->getScheduledOrders();

        // Notify Current Time and # of Orders in Alera
        $this->alert('Found (' . $orders->count() . ') Orders Scheduled for Dispatched -- Current Time: ' . Carbon::now()->toDateTimeString());

        // Dispatch each order
        $orders->each(
            function ($order) {
                if ($order->shouldDispatch()) {
                    $order->dispatch();

                    $this->info('Order ' . $order->public_id . ' dispatched! (' . $order->scheduled_at . ')');
                } else {
                    $this->warn('Order ' . $order->public_id . ' will be dispatched today today AT ' . $order->scheduled_at);
                }
            }
        );
    }

    /**
     * Fetches scheduled dispatchable orders based on certain criteria.
     */
    public function getScheduledOrders(): \Illuminate\Database\Eloquent\Collection
    {
        $sandbox = Utils::castBoolean($this->option('sandbox'));

        return Order::on($sandbox ? 'sandbox' : 'mysql')
            ->withoutGlobalScopes()
            ->where('dispatched', 0)
            ->whereIn('status', ['pending', 'created'])
            ->whereDate('scheduled_at', Carbon::today())
            ->whereNull('deleted_at')
            ->get();
    }
}
