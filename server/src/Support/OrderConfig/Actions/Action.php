<?php

namespace Fleetbase\FleetOps\Support\OrderConfig\Actions;

use Fleetbase\FleetOps\Contracts\OrderConfigAction;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\Models\User;

/**
 * An action described by its properties, with the text templating the notify actions share.
 */
abstract class Action implements OrderConfigAction
{
    protected string $name;
    protected string $label;
    protected string $description;
    protected array $parameters = [];

    public function name(): string
    {
        return $this->name;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function description(): string
    {
        return $this->description;
    }

    public function parameters(): array
    {
        return $this->parameters;
    }

    /**
     * Fill `{placeholders}` in the text: `{driver.name}`, `{driver.first_name}`,
     * `{customer.name}`, `{order.id}`, `{order.tracking_number}`, `{order.status}`, and the
     * activity's `{activity.status}` and `{activity.details}`. An unknown placeholder is left out.
     */
    public static function render(?string $text, Order $order, array $context = []): string
    {
        if ($text === null || $text === '') {
            return '';
        }

        $driverName = static::driverName($order);
        $values     = [
            'driver.name'           => $driverName ?? '',
            'driver.first_name'     => $driverName ? (string) strtok($driverName, ' ') : '',
            'customer.name'         => (string) data_get($order, 'customer.name', ''),
            'order.id'              => (string) $order->public_id,
            'order.tracking_number' => (string) data_get($order, 'trackingNumber.tracking_number', ''),
            'order.status'          => (string) $order->status,
            'activity.status'       => (string) data_get($context, 'activity.status', ''),
            'activity.details'      => (string) data_get($context, 'activity.details', ''),
        ];

        $rendered = preg_replace_callback('/\{\s*([a-z_]+(?:\.[a-z_]+)*)\s*\}/i', fn ($match) => $values[strtolower($match[1])] ?? '', $text);

        return trim(preg_replace('/\s{2,}/', ' ', $rendered));
    }

    /**
     * The assigned driver's name, read without loading their whole record.
     */
    public static function driverName(Order $order): ?string
    {
        if ($order->relationLoaded('driverAssigned')) {
            return data_get($order, 'driverAssigned.name');
        }

        $userUuid = $order->driver_assigned_uuid ? Driver::withoutGlobalScopes()->where('uuid', $order->driver_assigned_uuid)->value('user_uuid') : null;

        return $userUuid ? User::where('uuid', $userUuid)->value('name') : null;
    }
}
