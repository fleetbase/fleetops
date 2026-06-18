<?php

namespace Fleetbase\FleetOps\Jobs;

use Fleetbase\FleetOps\Events\OrderReady;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\ServiceQuote;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class FinalizeApiOrderCreation implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public string $orderUuid,
        public ?string $serviceQuoteUuid = null,
        public bool $shouldDispatch = false,
    ) {
    }

    public function handle(): void
    {
        $order = Order::where('uuid', $this->orderUuid)->first();
        if (!$order) {
            return;
        }

        $serviceQuote = $this->serviceQuoteUuid ? ServiceQuote::where('uuid', $this->serviceQuoteUuid)->first() : null;

        $order->notifyDriverAssigned();
        $order->setPreliminaryDistanceAndTime();
        $order->purchaseServiceQuote($serviceQuote);

        if ($this->shouldDispatch) {
            $order->dispatchWithActivity();
        }

        event(new OrderReady($order));
    }
}
