<?php

namespace Fleetbase\FleetOps\Support\OrderConfig;

use Fleetbase\FleetOps\Contracts\OrderConfigAction;
use Fleetbase\FleetOps\Support\OrderConfig\Actions\CancelOrder;
use Fleetbase\FleetOps\Support\OrderConfig\Actions\CloseChat;
use Fleetbase\FleetOps\Support\OrderConfig\Actions\NotifyCustomer;
use Fleetbase\FleetOps\Support\OrderConfig\Actions\NotifyDriver;
use Fleetbase\FleetOps\Support\OrderConfig\Actions\OpenChat;
use Fleetbase\FleetOps\Support\OrderConfig\Actions\SetMeta;

/**
 * The registry of actions order config triggers can run. Extensions add their own with
 * `register()`, for example Ledger's `send_invoice`.
 */
class OrderConfigActions
{
    /**
     * @var array<string, class-string<OrderConfigAction>>
     */
    protected static array $actions = [
        'notify_customer' => NotifyCustomer::class,
        'notify_driver'   => NotifyDriver::class,
        'open_chat'       => OpenChat::class,
        'close_chat'      => CloseChat::class,
        'set_meta'        => SetMeta::class,
        'cancel'          => CancelOrder::class,
    ];

    /**
     * Add an action, or replace one, under its name.
     *
     * @param class-string<OrderConfigAction> $class
     */
    public static function register(string $class): void
    {
        if (!is_subclass_of($class, OrderConfigAction::class)) {
            throw new \InvalidArgumentException($class . ' does not implement ' . OrderConfigAction::class . '.');
        }

        static::$actions[app($class)->name()] = $class;
    }

    public static function has(?string $name): bool
    {
        return is_string($name) && isset(static::$actions[$name]);
    }

    public static function get(string $name): ?OrderConfigAction
    {
        return static::has($name) ? app(static::$actions[$name]) : null;
    }

    public static function names(): array
    {
        return array_keys(static::$actions);
    }

    /**
     * Every action, for people configuring triggers.
     *
     * @return array<int, array{name: string, label: string, description: string, parameters: array}>
     */
    public static function describe(): array
    {
        return array_map(function (string $name) {
            $action = static::get($name);

            return ['name' => $name, 'label' => $action->label(), 'description' => $action->description(), 'parameters' => $action->parameters()];
        }, static::names());
    }
}
