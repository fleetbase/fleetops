<?php

namespace Fleetbase\FleetOps\Jobs;

use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class NotifyBulkAssignedDriver implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public array $orderUuids, public string $driverUuid)
    {
    }

    public function handle(): void
    {
        $driver = $this->findDriver();
        if (!$driver) {
            return;
        }

        foreach ($this->ordersForNotification() as $order) {
            $order->setRelation('driverAssigned', $driver);
            $order->driver_assigned_uuid = $driver->uuid;

            try {
                $order->notifyDriverAssigned();
            } catch (\Throwable $e) {
                $this->logNotificationFailure($order, $e);
            }
        }
    }

    protected function findDriver(): ?Driver
    {
        return Driver::where('uuid', $this->driverUuid)->first();
    }

    protected function ordersForNotification(): iterable
    {
        return Order::whereIn('uuid', $this->orderUuids)->cursor();
    }

    protected function logNotificationFailure(Order $order, \Throwable $e): void
    {
        logger()->warning(
            'Failed notifying driver on order ' . $order->uuid,
            ['error' => $e->getMessage()]
        );
    }
}
