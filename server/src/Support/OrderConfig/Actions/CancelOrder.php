<?php

namespace Fleetbase\FleetOps\Support\OrderConfig\Actions;

use Fleetbase\FleetOps\Models\Order;

/**
 * Cancel the order, with a reason, unless it has already finished.
 */
class CancelOrder extends Action
{
    protected string $name        = 'cancel';
    protected string $label       = 'Cancel order';
    protected string $description = 'Cancel the order with a reason, unless it has already finished.';
    protected array $parameters   = [
        'reason' => 'Why the order was canceled.',
    ];

    public function handle(Order $order, array $params = [], array $context = []): void
    {
        if ($order->config()?->isTerminalActivityCode($order->status)) {
            return;
        }

        $order->cancelWithReason(isset($params['reason']) ? (string) $params['reason'] : null);
    }
}
