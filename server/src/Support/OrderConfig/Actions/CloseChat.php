<?php

namespace Fleetbase\FleetOps\Support\OrderConfig\Actions;

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Support\OrderChat;

/**
 * Close the order's customer chat: its history stays readable, and nobody can write in it.
 */
class CloseChat extends Action
{
    protected string $name        = 'close_chat';
    protected string $label       = 'Close customer chat';
    protected string $description = 'Stop new messages in the order\'s customer chat. Its history stays readable.';

    public function handle(Order $order, array $params = [], array $context = []): void
    {
        OrderChat::close($order);
    }
}
