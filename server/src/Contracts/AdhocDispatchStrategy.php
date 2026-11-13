<?php

namespace Fleetbase\FleetOps\Contracts;

use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;

/**
 * How an adhoc order, one without an assigned driver, is offered to drivers. Strategies are
 * registered by name with `AdhocDispatch::register()`, and an order picks one through its
 * `adhoc_strategy`, its config's `adhoc_strategy` or the company's `fleetops.adhoc_strategy` option.
 *
 * Every strategy keeps its offers in `order_offers`, so declines, history and "taken by another
 * driver" work the same way whichever offers the order.
 */
interface AdhocDispatchStrategy
{
    /**
     * The name orders use to pick this strategy.
     */
    public function name(): string;

    /**
     * A short name for people choosing a strategy, for example "One driver at a time".
     */
    public function label(): string;

    /**
     * One sentence on how orders are offered.
     */
    public function description(): string;

    /**
     * Whether a driver nearby sees the order in their list without holding an offer for it.
     * True for everyone-at-once offering, false when it goes to one driver at a time. A driver
     * who declined never sees it, and one holding an open offer always does.
     */
    public function listsOpenly(): bool;

    /**
     * The order was just dispatched: offer it to drivers.
     */
    public function dispatch(Order $order): void;

    /**
     * The scheduled check (`fleetops:dispatch-adhoc`) found the order still without a driver.
     * Returns how many drivers it was offered to.
     */
    public function redispatch(Order $order, bool $testing = false): int;

    /**
     * The driver accepts the order. False when this strategy doesn't let them, for example
     * because it is offered to someone else. Called before the order is claimed.
     */
    public function accept(Order $order, Driver $driver): bool;

    /**
     * The driver turned the order down. False when there was nothing to decline.
     */
    public function decline(Order $order, Driver $driver): bool;
}
