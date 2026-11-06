<?php

namespace Fleetbase\FleetOps\Support\AdhocDispatch;

use Fleetbase\FleetOps\Contracts\AdhocDispatchStrategy;
use Fleetbase\FleetOps\Models\Order;

/**
 * The registry of adhoc dispatch strategies, and which one an order uses.
 */
class AdhocDispatch
{
    public const BROADCAST  = 'broadcast';
    public const SEQUENTIAL = 'sequential';
    public const DEFAULT    = self::SEQUENTIAL;

    /**
     * Seconds a driver has to accept a one-at-a-time offer, and its limits.
     */
    public const OFFER_TIMEOUT     = 20;
    public const MIN_OFFER_TIMEOUT = 10;
    public const MAX_OFFER_TIMEOUT = 120;

    /**
     * @var array<string, class-string<AdhocDispatchStrategy>>
     */
    protected static array $strategies = [
        self::BROADCAST  => BroadcastStrategy::class,
        self::SEQUENTIAL => SequentialStrategy::class,
    ];

    /**
     * Add a strategy, or replace one, under its name.
     *
     * @param class-string<AdhocDispatchStrategy> $class
     */
    public static function register(string $name, string $class): void
    {
        if (!is_subclass_of($class, AdhocDispatchStrategy::class)) {
            throw new \InvalidArgumentException($class . ' does not implement ' . AdhocDispatchStrategy::class . '.');
        }

        static::$strategies[$name] = $class;
    }

    /**
     * Every registered strategy name.
     */
    public static function names(): array
    {
        return array_keys(static::$strategies);
    }

    public static function has(?string $name): bool
    {
        return is_string($name) && isset(static::$strategies[$name]);
    }

    /**
     * The strategy name for the order: its own, its config's, its company's, or the default.
     * A name that isn't registered is skipped.
     */
    public static function nameFor(Order $order): string
    {
        $candidates = [
            $order->adhoc_strategy,
            $order->config()?->adhoc_strategy,
            data_get($order, 'company.options.fleetops.adhoc_strategy'),
        ];

        foreach ($candidates as $name) {
            if (static::has($name)) {
                return $name;
            }
        }

        return self::DEFAULT;
    }

    public static function for(Order $order): AdhocDispatchStrategy
    {
        return app(static::$strategies[static::nameFor($order)]);
    }

    /**
     * Seconds a driver has to accept a one-at-a-time offer: the config's, the company's, or the default.
     */
    public static function offerTimeout(Order $order): int
    {
        $timeout = $order->config()?->adhoc_offer_timeout ?? data_get($order, 'company.options.fleetops.adhoc_offer_timeout') ?? self::OFFER_TIMEOUT;

        return max(self::MIN_OFFER_TIMEOUT, min(self::MAX_OFFER_TIMEOUT, (int) $timeout));
    }
}
