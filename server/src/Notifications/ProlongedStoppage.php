<?php

namespace Fleetbase\FleetOps\Notifications;

use Fleetbase\FleetOps\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ProlongedStoppage extends Notification implements ShouldQueue
{
    use Queueable;

    public static string $name = 'Prolonged Stoppage';
    public static string $description = 'Notify when a vehicle remains stopped during an active trip beyond the configured threshold.';
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
            ->subject("Prolonged stoppage detected for {$orderId}")
            ->line("The vehicle assigned to order {$orderId} has remained stopped beyond the configured threshold.")
            ->line('Please review the active trip and vehicle location.');
    }

    public function toArray($notifiable): array
    {
        return [
            'event'      => 'order.prolonged_stoppage',
            'order_id'   => $this->order->public_id,
            'order_uuid' => $this->order->uuid,
            'context'    => $this->context,
            'message'    => sprintf('The vehicle assigned to order %s has remained stopped beyond the configured threshold.', $this->order->tracking ?? $this->order->public_id),
        ];
    }
}
