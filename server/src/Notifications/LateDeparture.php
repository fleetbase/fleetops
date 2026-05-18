<?php

namespace Fleetbase\FleetOps\Notifications;

use Fleetbase\FleetOps\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class LateDeparture extends Notification implements ShouldQueue
{
    use Queueable;

    public static string $name = 'Late Departure';
    public static string $description = 'Notify when an order has not departed after the configured grace period.';
    public static string $package = 'fleet-ops';

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

        return (new MailMessage())
            ->subject("Late departure detected for {$orderId}")
            ->line("Order {$orderId} has not departed within the configured grace period.")
            ->line('Please review the order and dispatch status.');
    }

    public function toArray($notifiable): array
    {
        return [
            'event'      => 'order.late_departure',
            'order_id'   => $this->order->public_id,
            'order_uuid' => $this->order->uuid,
            'context'    => $this->context,
            'message'    => sprintf('Order %s has not departed within the configured grace period.', $this->order->tracking ?? $this->order->public_id),
        ];
    }
}
