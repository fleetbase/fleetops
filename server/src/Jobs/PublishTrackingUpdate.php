<?php

namespace Fleetbase\FleetOps\Jobs;

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Support\TrackingPublisher;
use Fleetbase\FleetOps\Support\TrackingUpdate;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;

/**
 * Builds and publishes an order's public tracking updates off the request path.
 *
 * Location jobs publish the position only, except that at most once per ETA interval they
 * publish the full update instead so that customers' ETAs follow the vehicle.
 */
class PublishTrackingUpdate implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public $tries   = 1;
    public $timeout = 60;

    public function __construct(public string $orderUuid, public string $reason = TrackingUpdate::REASON_STATUS, public ?string $dbConnection = null)
    {
    }

    public function handle(TrackingPublisher $publisher): int
    {
        $order = Order::on($this->dbConnection)->where('uuid', $this->orderUuid)->first();
        if (!$order) {
            return 0;
        }

        if ($this->reason !== TrackingUpdate::REASON_LOCATION) {
            return $publisher->publishOrder($order, $this->reason);
        }

        if (Cache::add('fleetops:tracking:eta:' . $order->uuid, 1, TrackingPublisher::ETA_INTERVAL)) {
            return $publisher->publishOrder($order, TrackingUpdate::REASON_ETA);
        }

        return $publisher->publishLocation($order);
    }
}
