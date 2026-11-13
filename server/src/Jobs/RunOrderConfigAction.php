<?php

namespace Fleetbase\FleetOps\Jobs;

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Support\OrderConfig\OrderConfigActions;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Runs one action of an order config trigger for an order. The order is read again when the
 * job runs, so the action sees it as it is then.
 */
class RunOrderConfigAction implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public $tries = 3;

    public string $orderUuid;

    public function __construct(Order $order, public string $action, public array $params = [], public array $context = [])
    {
        $this->orderUuid = $order->uuid;
    }

    public function handle(): bool
    {
        $order  = Order::withoutGlobalScopes()->where('uuid', $this->orderUuid)->first();
        $action = OrderConfigActions::get($this->action);
        if (!$order || !$action) {
            return false;
        }

        $action->handle($order, $this->params, $this->context);

        return true;
    }
}
