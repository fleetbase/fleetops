<?php

namespace Fleetbase\FleetOps\Support\OrderConfig\Actions;

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Support\OrderChat;

/**
 * Open the chat between the order's customer and its driver, so either can write first.
 */
class OpenChat extends Action
{
    protected string $name        = 'open_chat';
    protected string $label       = 'Open customer chat';
    protected string $description = 'Open a chat between the order\'s customer and its assigned driver.';

    public function handle(Order $order, array $params = [], array $context = []): void
    {
        OrderChat::open($order);
    }
}
