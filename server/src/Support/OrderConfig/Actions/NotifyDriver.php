<?php

namespace Fleetbase\FleetOps\Support\OrderConfig\Actions;

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Notifications\OrderUpdate;

/**
 * Push a message to the order's assigned driver.
 */
class NotifyDriver extends Action
{
    protected string $name        = 'notify_driver';
    protected string $label       = 'Notify driver';
    protected string $description = 'Send the order\'s assigned driver a push notification.';
    protected array $parameters   = [
        'title'   => 'Notification title. Defaults to the activity\'s status.',
        'message' => 'Notification message. Defaults to the activity\'s details.',
    ];

    public function handle(Order $order, array $params = [], array $context = []): void
    {
        $driver = $order->driver_assigned_uuid ? $order->loadAssignedDriver()->driverAssigned : null;
        if (!$driver) {
            return;
        }

        $title   = static::render($params['title'] ?? data_get($context, 'activity.status'), $order, $context);
        $message = static::render($params['message'] ?? data_get($context, 'activity.details'), $order, $context);
        if ($title === '' && $message === '') {
            return;
        }

        $driver->notify(new OrderUpdate($order, $title, $message, NotifyCustomer::eventType($context)));
    }
}
