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
        $order = $this->findOrder();
        if (!$order) {
            return;
        }

        $serviceQuote = $this->findServiceQuote();

        $order->notifyDriverAssigned();
        $order->setPreliminaryDistanceAndTime();
        $order->purchaseServiceQuote($serviceQuote);

        if ($this->shouldDispatch) {
            $order->dispatchWithActivity();
        }

        $this->fireOrderReady($order);
    }

    protected function findOrder(): ?Order
    {
        return Order::where('uuid', $this->orderUuid)->first();
    }

    protected function findServiceQuote(): ?ServiceQuote
    {
        return $this->serviceQuoteUuid ? ServiceQuote::where('uuid', $this->serviceQuoteUuid)->first() : null;
    }

    protected function fireOrderReady(Order $order): void
    {
        event(new OrderReady($order));
    }
}
