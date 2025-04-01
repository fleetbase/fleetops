<?php

namespace Fleetbase\FleetOps\Console\Commands;

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Support\Utils;
use Illuminate\Console\Command;

class SendDriverNotification extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'fleetops:send-driver-notification {--id= : The ID of the order} {--event= : The name of the event to trigger}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Manually trigger a push notification for an driver';

    /**
     * Mapping of event names to notification classes.
     *
     * @var array
     */
    protected $eventToNotification = [
        'assigned'           => \Fleetbase\FleetOps\Notifications\OrderAssigned::class,
        'canceled'           => \Fleetbase\FleetOps\Notifications\OrderCanceled::class,
        'dispatched'         => \Fleetbase\FleetOps\Notifications\OrderDispatched::class,
        'ping'               => \Fleetbase\FleetOps\Notifications\OrderPing::class,
    ];

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        // Get order ID and event from options
        $orderId = $this->option('id');
        $event   = $this->option('event');

        // Prompt user to search for order ID if not provided
        if (!$orderId) {
            $orderId = $this->ask('Enter the order ID to trigger the notification');
        }

        // Attempt to find the order
        $order = Order::where('public_id', $orderId)->first();
        if (!$order) {
            $this->error('Order not found!');

            return 1;
        }

        // Load customer relation
        $order->loadMissing(['driverAssigned', 'payload']);
        if (!$order->driverAssigned) {
            $this->error('Order does not have a driver assigned!');

            return 1;
        }

        // Prompt user to select event if not provided
        if (!$event) {
            $event = $this->choice(
                'Select the event to trigger',
                array_keys($this->eventToNotification),
                'created' // Default event
            );
        }

        // Resolve notification class
        $notificationClass = $this->eventToNotification[$event] ?? null;
        if (!$notificationClass) {
            $this->error('Invalid event selected!');

            return 1;
        }

        // nearby notification requires more arguments
        try {
            // Handle order ping notification which requires distance of pickup point from driver
            if ($event === 'ping') {
                $destination = $order->payload->getPickupOrFirstWaypoint();
                $matrix      = Utils::calculateDrivingDistanceAndTime($order->driverAssigned->location, $destination);
                $order->driverAssigned->notify(new $notificationClass($order, $matrix->distance));
            } else {
                // Trigger notification
                $order->driverAssigned->notify(new $notificationClass($order));
            }
        } catch (\Exception $e) {
            $this->error($e->getMessage());

            return 0;
        }

        $this->info("Notification '{$event}' has been triggered for order ID '{$orderId}'.");

        return 0;
    }
}
