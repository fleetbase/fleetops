<?php

namespace Fleetbase\FleetOps\Notifications;

use Fleetbase\FleetOps\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells dispatch that a driver declined the order offered to them, which left it unassigned.
 */
class OrderOfferDeclined extends Notification implements ShouldQueue
{
    use Queueable;

    public static string $name        = 'Order Declined By Driver';
    public static string $description = 'Notify when a driver declines an order offered to them.';
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

        return (new MailMessage())
            ->subject("Driver declined {$orderId}")
            ->line($this->message())
            ->line('The order is unassigned and needs a driver.');
    }

    public function toArray($notifiable): array
    {
        return [
            'event'      => 'order.offer_declined',
            'order_id'   => $this->order->public_id,
            'order_uuid' => $this->order->uuid,
            'context'    => $this->context,
            'message'    => $this->message(),
        ];
    }

    protected function message(): string
    {
        $driver = $this->context['driver_name'] ?? 'The driver';
        $reason = $this->context['reason'] ?? null;

        return sprintf('%s declined order %s%s', $driver, $this->order->tracking ?? $this->order->public_id, $reason ? ': ' . $reason : '.');
    }
}
