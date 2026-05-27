<?php

namespace Fleetbase\FleetOps\Notifications;

use Fleetbase\FleetOps\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class RouteDeviation extends Notification implements ShouldQueue
{
    use Queueable;

    public static string $name        = 'Route Deviation';
    public static string $description = 'Notify when tracked vehicle location deviates from the active route beyond the configured threshold.';
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
            ->subject("Route deviation detected for {$orderId}")
            ->line("The vehicle assigned to order {$orderId} has deviated from the active route beyond the configured threshold.")
            ->line('Please review the route and current vehicle position.');
    }

    public function toArray($notifiable): array
    {
        return [
            'event'      => 'order.route_deviation',
            'order_id'   => $this->order->public_id,
            'order_uuid' => $this->order->uuid,
            'context'    => $this->context,
            'message'    => sprintf('The vehicle assigned to order %s has deviated from the active route beyond the configured threshold.', $this->order->tracking ?? $this->order->public_id),
        ];
    }
}
