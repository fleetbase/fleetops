<?php

namespace Fleetbase\FleetOps\Notifications;

use Fleetbase\FleetOps\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Operational alert raised when the driver an order was offered to did not accept it in time.
 */
class DriverNotAccepted extends Notification implements ShouldQueue
{
    use Queueable;

    public static string $name        = 'Driver Not Accepted';
    public static string $description = 'Notify when the driver an order was offered to has not accepted it within the offer window.';
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
            ->subject("Driver has not accepted {$orderId}")
            ->line($this->message())
            ->line('Please contact the driver or reassign the order.');
    }

    public function toArray($notifiable): array
    {
        return [
            'event'      => 'order.driver_not_accepted',
            'order_id'   => $this->order->public_id,
            'order_uuid' => $this->order->uuid,
            'context'    => $this->context,
            'message'    => $this->message(),
        ];
    }

    protected function message(): string
    {
        return sprintf('The driver has not accepted order %s within the offer window.', $this->order->tracking ?? $this->order->public_id);
    }
}
