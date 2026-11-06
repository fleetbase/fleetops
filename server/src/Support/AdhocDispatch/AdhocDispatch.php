<?php

namespace Fleetbase\FleetOps\Support\AdhocDispatch;

use Fleetbase\FleetOps\Contracts\AdhocDispatchStrategy;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\Models\Company;

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
        foreach ([$order->adhoc_strategy, $order->config()?->adhoc_strategy] as $name) {
            if (static::has($name)) {
                return $name;
            }
        }

        return static::companyDefault($order->company);
    }

    /**
     * The company's default strategy (`fleetops.adhoc_strategy`), or the default when it has none.
     */
    public static function companyDefault(?Company $company): string
    {
        $name = $company?->getOption('fleetops.adhoc_strategy');

        return static::has($name) ? $name : self::DEFAULT;
    }

    public static function for(Order $order): AdhocDispatchStrategy
    {
        return static::strategy(static::nameFor($order));
    }

    /**
     * The registered strategy of that name.
     */
    public static function strategy(string $name): AdhocDispatchStrategy
    {
        return app(static::$strategies[$name]);
    }

    /**
     * Every registered strategy, for people choosing one.
     *
     * @return array<int, array{name: string, label: string, description: string}>
     */
    public static function describe(): array
    {
        return array_map(function (string $name) {
            $strategy = static::strategy($name);

            return ['name' => $name, 'label' => $strategy->label(), 'description' => $strategy->description()];
        }, static::names());
    }

    /**
     * Strategies whose orders drivers see without holding an offer.
     */
    public static function openlyListed(): array
    {
        return array_values(array_filter(static::names(), fn (string $name) => static::strategy($name)->listsOpenly()));
    }

    /**
     * Seconds a driver has to accept a one-at-a-time offer: the config's, the company's, or the default.
     */
    public static function offerTimeout(Order $order): int
    {
        $timeout = $order->config()?->adhoc_offer_timeout ?? $order->company?->getOption('fleetops.adhoc_offer_timeout') ?? self::OFFER_TIMEOUT;

        return max(self::MIN_OFFER_TIMEOUT, min(self::MAX_OFFER_TIMEOUT, (int) $timeout));
    }
}
