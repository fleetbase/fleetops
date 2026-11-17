<?php

namespace Fleetbase\FleetOps\Support\OrderConfig;

use Fleetbase\FleetOps\Flow\Activity;
use Fleetbase\FleetOps\Flow\Logic;
use Fleetbase\FleetOps\Jobs\RunOrderConfigAction;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Support\Utils;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Runs an order config's triggers. A trigger is `{action, params, when}`: the action to run,
 * what to give it, and conditions (logic blocks, like an activity's `logic`) that must all pass.
 *
 * Triggers sit in two places. An activity's `actions` run when an order reaches that activity.
 * The config's `triggers` add an `event` and run on that order event:
 *
 * - `order.created`, `order.completed`, `order.canceled`
 * - `dispatch.offer_exhausted`: every driver an adhoc order was offered to declined or let it run out
 * - `driver.approaching`: the assigned driver came within `options.distance` metres (default 500)
 *   of the order's `options.target`, `pickup` (default) or `dropoff`; once per order and target
 *
 * Actions are queued, so a slow one never holds up the change that fired it.
 */
class OrderConfigTriggers
{
    public const ORDER_CREATED            = 'order.created';
    public const ORDER_COMPLETED          = 'order.completed';
    public const ORDER_CANCELED           = 'order.canceled';
    public const DISPATCH_OFFER_EXHAUSTED = 'dispatch.offer_exhausted';
    public const DRIVER_APPROACHING       = 'driver.approaching';

    public const EVENTS = [
        self::ORDER_CREATED,
        self::ORDER_COMPLETED,
        self::ORDER_CANCELED,
        self::DISPATCH_OFFER_EXHAUSTED,
        self::DRIVER_APPROACHING,
    ];

    public const APPROACHING_DISTANCE = 500;

    /**
     * Run actions in this process instead of queueing them (tests, and the queue's own job).
     */
    public static bool $runImmediately = false;

    /**
     * The order reached an activity: run that activity's actions.
     */
    public static function activity(Order $order, Activity|array|null $activity): int
    {
        $activity = is_array($activity) ? new Activity($activity) : $activity;
        if (!$activity) {
            return 0;
        }

        $context = [
            'event'    => 'order.activity',
            'activity' => ['code' => $activity->code, 'status' => $activity->status, 'details' => $activity->details],
        ];

        return static::run($order, (array) ($activity->get('actions') ?? []), $context);
    }

    /**
     * An order event happened: run the config's triggers for it.
     */
    public static function fire(Order $order, string $event, array $context = []): int
    {
        return static::run($order, static::forEvent($order, $event), ['event' => $event] + $context);
    }

    /**
     * The driver of an order reported a position: fire `driver.approaching` for each target
     * the driver has just come close to.
     */
    public static function driverMoved(Driver $driver, ?Order $order): int
    {
        if (!$order || !Utils::isPoint($driver->location)) {
            return 0;
        }

        $ran = 0;
        foreach (static::forEvent($order, self::DRIVER_APPROACHING) as $trigger) {
            $target   = data_get($trigger, 'options.target', 'pickup') === 'dropoff' ? 'dropoff' : 'pickup';
            $distance = (int) data_get($trigger, 'options.distance', self::APPROACHING_DISTANCE);
            $point    = $target === 'dropoff' ? $order->payload?->dropoff?->location : $order->getPickupLocation();

            if (!Utils::isPoint($point) || Utils::vincentyGreatCircleDistance($driver->location, $point) > $distance) {
                continue;
            }

            $onceKey = 'fleetops:order-config:approaching:' . $order->uuid . ':' . $target . ':' . $distance;
            if (!static::passes($order, $trigger) || !Cache::add($onceKey, 1, now()->addHours(12))) {
                continue;
            }

            $ran += static::run($order, [$trigger], ['event' => self::DRIVER_APPROACHING, 'target' => $target]);
        }

        return $ran;
    }

    /**
     * The config's triggers for the event.
     */
    public static function forEvent(Order $order, string $event): array
    {
        $triggers = $order->currentConfig()?->triggers;

        return array_values(array_filter(is_array($triggers) ? $triggers : [], fn ($trigger) => is_array($trigger) && ($trigger['event'] ?? null) === $event));
    }

    /**
     * Whether every condition of the trigger passes for the order. A trigger without any always passes.
     */
    public static function passes(Order $order, array $trigger): bool
    {
        $when = $trigger['when'] ?? [];
        if (!is_array($when) || $when === []) {
            return true;
        }

        try {
            return collect($when)->every(fn ($logic) => is_array($logic) && (new Logic($logic))->passes($order));
        } catch (\Throwable $exception) {
            Log::warning('Skipped an order config trigger whose conditions could not be read', ['order' => $order->public_id, 'error' => $exception->getMessage()]);

            return false;
        }
    }

    /**
     * Queue (or run) each trigger whose action is registered and whose conditions pass.
     */
    protected static function run(Order $order, array $triggers, array $context): int
    {
        $ran = 0;
        foreach ($triggers as $trigger) {
            $name = is_array($trigger) ? ($trigger['action'] ?? null) : null;
            if (!OrderConfigActions::has($name) || !static::passes($order, $trigger)) {
                continue;
            }

            $params = is_array($trigger['params'] ?? null) ? $trigger['params'] : [];

            try {
                if (static::$runImmediately) {
                    OrderConfigActions::get($name)->handle($order, $params, $context);
                } else {
                    dispatch(new RunOrderConfigAction($order, $name, $params, $context));
                }
                $ran++;
            } catch (\Throwable $exception) {
                Log::warning('An order config action failed', ['order' => $order->public_id, 'action' => $name, 'error' => $exception->getMessage()]);
            }
        }

        return $ran;
    }
}
