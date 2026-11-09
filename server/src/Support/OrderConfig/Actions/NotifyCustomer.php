<?php

namespace Fleetbase\FleetOps\Support\OrderConfig\Actions;

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Notifications\OrderUpdate;

/**
 * Push a message to the order's customer. Without a title or message of its own it uses the
 * activity's status and details, so a flow already worded for customers needs no copy here.
 */
class NotifyCustomer extends Action
{
    protected string $name        = 'notify_customer';
    protected string $label       = 'Notify customer';
    protected string $description = 'Send the order\'s customer a push notification.';
    protected array $parameters   = [
        'title'   => 'Notification title. Defaults to the activity\'s status. Placeholders such as {driver.first_name} are filled in.',
        'message' => 'Notification message. Defaults to the activity\'s details.',
    ];

    public function handle(Order $order, array $params = [], array $context = []): void
    {
        $customer = $order->customer;
        if (!$customer || !method_exists($customer, 'notify')) {
            return;
        }

        $title   = static::render($params['title'] ?? data_get($context, 'activity.status'), $order, $context);
        $message = static::render($params['message'] ?? data_get($context, 'activity.details'), $order, $context);
        if ($title === '' && $message === '') {
            return;
        }

        $customer->notify(new OrderUpdate($order, $title, $message, static::eventType($context)));
    }

    /**
     * The `type` apps receive in the push: the activity code or event, as `order_<name>`.
     */
    public static function eventType(array $context): string
    {
        $name = data_get($context, 'activity.code') ?? data_get($context, 'event', 'update');

        return 'order_' . preg_replace('/[^a-z0-9_]+/', '_', strtolower((string) $name));
    }
}
