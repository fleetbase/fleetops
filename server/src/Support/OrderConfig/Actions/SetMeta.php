<?php

namespace Fleetbase\FleetOps\Support\OrderConfig\Actions;

use Fleetbase\FleetOps\Models\Order;

/**
 * Write a value to the order's meta, for example to mark a step for a later condition or report.
 */
class SetMeta extends Action
{
    protected string $name        = 'set_meta';
    protected string $label       = 'Set order meta';
    protected string $description = 'Write a value to the order\'s meta.';
    protected array $parameters   = [
        'key'   => 'The meta key, in dot notation.',
        'value' => 'The value. `{now}` writes the current time.',
    ];

    public function handle(Order $order, array $params = [], array $context = []): void
    {
        $key = $params['key'] ?? null;
        if (!is_string($key) || trim($key) === '') {
            return;
        }

        $value = $params['value'] ?? null;
        $order->updateMeta(trim($key), $value === '{now}' ? now()->toISOString() : $value);
    }
}
