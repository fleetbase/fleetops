<?php

namespace Fleetbase\FleetOps\Notifications;

use Fleetbase\FleetOps\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * An inspection a step of an order needed found the vehicle unsafe, so the order is held at that
 * step. Sent through the notification settings like the other order alerts, to whoever the
 * organisation has chosen to hear about it.
 */
class UnsafeInspection extends Notification implements ShouldQueue
{
    use Queueable;

    public static string $name        = 'Unsafe Inspection';
    public static string $description = 'Notify when an inspection an order step requires finds the vehicle unsafe, holding the order at that step.';
    public static string $package     = 'fleet-ops';

    public function __construct(public Order $order, public array $context = [])
    {
    }

    public function via($notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail($notifiable): MailMessage
    {
        $orderId = $this->order->tracking ?? $this->order->public_id;
        $vehicle = data_get($this->context, 'vehicle_name') ?? 'The vehicle';

        return (new MailMessage())
            ->subject("Unsafe inspection holds order {$orderId}")
            ->line("{$vehicle} was found unsafe by the inspection order {$orderId} needs before it can move on.")
            ->line('The order is held at its step until the vehicle is fixed or another vehicle is inspected.');
    }

    public function toArray($notifiable): array
    {
        return [
            'event'      => 'order.inspection_unsafe',
            'order_id'   => $this->order->public_id,
            'order_uuid' => $this->order->uuid,
            'context'    => $this->context,
            'message'    => sprintf('An inspection found the vehicle unsafe; order %s is held at its step.', $this->order->tracking ?? $this->order->public_id),
        ];
    }
}
