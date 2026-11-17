<?php

namespace Fleetbase\FleetOps\Contracts;

use Fleetbase\FleetOps\Models\Order;

/**
 * Something an order config does when one of its triggers fires: when an order reaches an
 * activity (the activity's `actions`), or on an order event (the config's `triggers`).
 */
interface OrderConfigAction
{
    /**
     * The name triggers use to refer to the action.
     */
    public function name(): string;

    public function label(): string;

    public function description(): string;

    /**
     * The parameters a trigger can give the action, as `name => description`.
     */
    public function parameters(): array;

    /**
     * Run the action for the order. The context names the event and, for an activity, its code,
     * status and details.
     */
    public function handle(Order $order, array $params = [], array $context = []): void;
}
