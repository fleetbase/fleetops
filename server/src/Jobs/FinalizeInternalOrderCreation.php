<?php

namespace Fleetbase\FleetOps\Jobs;

use Fleetbase\FleetOps\Events\OrderReady;
use Fleetbase\FleetOps\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class FinalizeInternalOrderCreation implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public string $orderUuid)
    {
    }

    public function handle(): void
    {
        $order = Order::where('uuid', $this->orderUuid)->first();
        if (!$order) {
            return;
        }

        $order->notifyDriverAssigned();

        event(new OrderReady($order));
    }
}
