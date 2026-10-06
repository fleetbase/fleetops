<?php

namespace Fleetbase\FleetOps\Support;

use Fleetbase\FleetOps\Jobs\PublishTrackingUpdate;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Entity;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\FleetOps\Models\Waypoint;
use Fleetbase\FleetOps\Tracking\TrackingContextBuilder;
use Fleetbase\FleetOps\Tracking\TrackingIntelligenceService;
use Fleetbase\FleetOps\Tracking\TrackingOptions;
use Fleetbase\FleetOps\Tracking\TrackingStop;
use Fleetbase\Support\SocketCluster\SocketClusterService;
use Fleetbase\Support\SocketCluster\SocketToken;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Publishes per-customer updates to public tracking channels.
 *
 * The request-path hooks (orderChanged, stopChanged, driverMoved, vehicleMoved) do almost
 * nothing: they bail out when socket authentication is off, throttle location changes through
 * the cache, and queue a PublishTrackingUpdate job. The job builds and publishes the updates.
 */
class TrackingPublisher
{
    /**
     * Seconds between position updates for one order (and between order lookups for one driver or vehicle).
     */
    public const LOCATION_INTERVAL = 5;

    /**
     * Seconds between ETA refreshes for one order driven by position changes.
     */
    public const ETA_INTERVAL = 60;

    /**
     * Most active orders a single driver or vehicle position change fans out to.
     */
    public const MAX_ORDERS_PER_SUBJECT = 25;

    /**
     * Order attributes whose change is published, with the reason reported.
     */
    public const ORDER_ATTRIBUTES = [
        'status'                => TrackingUpdate::REASON_STATUS,
        'started'               => TrackingUpdate::REASON_STATUS,
        'dispatched'            => TrackingUpdate::REASON_STATUS,
        'driver_assigned_uuid'  => TrackingUpdate::REASON_ASSIGNMENT,
        'vehicle_assigned_uuid' => TrackingUpdate::REASON_ASSIGNMENT,
        'time'                  => TrackingUpdate::REASON_ETA,
        'distance'              => TrackingUpdate::REASON_ETA,
    ];

    public static function enabled(): bool
    {
        return SocketToken::enabled();
    }

    /**
     * An order was saved: queue an update when a published attribute changed.
     */
    public function orderChanged(Order $order): bool
    {
        if (!static::enabled() || !$order->uuid) {
            return false;
        }

        $changed = array_intersect_key(self::ORDER_ATTRIBUTES, $order->getChanges());
        if ($changed === []) {
            return false;
        }

        $reasons = array_values($changed);
        $reason  = in_array(TrackingUpdate::REASON_STATUS, $reasons, true) ? TrackingUpdate::REASON_STATUS : $reasons[0];

        return $this->queue($order->uuid, $reason, $order->getConnectionName());
    }

    /**
     * A waypoint or entity activity changed: queue an update for its order.
     */
    public function stopChanged(Waypoint|Entity|null $subject): bool
    {
        if (!static::enabled() || !$subject || !$subject->payload_uuid) {
            return false;
        }

        $connection = $subject->getConnectionName();
        $orderUuid  = Order::on($connection)->where('payload_uuid', $subject->payload_uuid)->value('uuid');

        return $orderUuid ? $this->queue($orderUuid, TrackingUpdate::REASON_STOP, $connection) : false;
    }

    /**
     * A driver reported a position: queue a location update for each of their en-route orders.
     */
    public function driverMoved(Driver $driver): int
    {
        if (!static::enabled() || !$driver->uuid || !Cache::add('fleetops:tracking:driver:' . $driver->uuid, 1, self::LOCATION_INTERVAL)) {
            return 0;
        }

        $connection = $driver->getConnectionName();

        return $this->queueLocations($this->enRouteOrders($connection)->where('driver_assigned_uuid', $driver->uuid)->limit(self::MAX_ORDERS_PER_SUBJECT)->pluck('uuid'), $connection);
    }

    /**
     * A vehicle reported a position: queue a location update for each en-route order it serves.
     */
    public function vehicleMoved(Vehicle $vehicle): int
    {
        if (!static::enabled() || !$vehicle->uuid || !Cache::add('fleetops:tracking:vehicle:' . $vehicle->uuid, 1, self::LOCATION_INTERVAL)) {
            return 0;
        }

        $connection = $vehicle->getConnectionName();
        $drivers    = Driver::on($connection)->where('vehicle_uuid', $vehicle->uuid)->select('uuid')->toBase();
        $orders     = $this->enRouteOrders($connection)
            ->where(function ($query) use ($vehicle, $drivers) {
                $query->where('vehicle_assigned_uuid', $vehicle->uuid)->orWhereIn('driver_assigned_uuid', $drivers);
            })
            ->limit(self::MAX_ORDERS_PER_SUBJECT)
            ->pluck('uuid');

        return $this->queueLocations($orders, $connection);
    }

    /**
     * Publish the full update to every customer of the order. Returns the channels published to.
     */
    public function publishOrder(Order $order, string $reason = TrackingUpdate::REASON_STATUS): int
    {
        if (!static::enabled()) {
            return 0;
        }

        $stops   = $this->stopsFor($order);
        $tracker = $this->trackerFor($order);
        $sent    = 0;

        foreach (TrackingScope::allForOrder($order, $this->waypointsOf($stops)) as $scope) {
            $update = TrackingUpdate::for($order, $scope, $stops, $tracker);
            $sent += (int) $this->send(TrackingChannel::name($scope), $update->toArray($reason));
        }

        return $sent;
    }

    /**
     * Publish the position to every customer of the order who may see it. Returns the channels published to.
     */
    public function publishLocation(Order $order): int
    {
        if (!static::enabled()) {
            return 0;
        }

        $stops = $this->stopsFor($order);
        $sent  = 0;

        foreach (TrackingScope::allForOrder($order, $this->waypointsOf($stops)) as $scope) {
            $payload = (new TrackingUpdate($order, $scope, $stops))->locationPayload();
            if ($payload !== null) {
                $sent += (int) $this->send(TrackingChannel::name($scope), $payload);
            }
        }

        return $sent;
    }

    /**
     * Every stop of the order in route order, with completion state.
     *
     * @return Collection<int, TrackingStop>
     */
    protected function stopsFor(Order $order): Collection
    {
        return app(TrackingContextBuilder::class)->build($order, TrackingOptions::fromArray([], $order->company_uuid))->stops;
    }

    /**
     * The tracking intelligence result for the order's ETAs; empty when it cannot be computed.
     */
    protected function trackerFor(Order $order): array
    {
        if (in_array(strtolower((string) $order->status), TrackingUpdate::TERMINAL_STATUSES, true)) {
            return [];
        }

        try {
            return app(TrackingIntelligenceService::class)->track($order);
        } catch (\Throwable) {
            return [];
        }
    }

    protected function send(string $channel, array $payload): bool
    {
        return SocketClusterService::publish($channel, $payload);
    }

    protected function queue(string $orderUuid, string $reason, ?string $connection = null): bool
    {
        PublishTrackingUpdate::dispatch($orderUuid, $reason, $connection);

        return true;
    }

    /**
     * Queue a location update for each order not updated within the location interval.
     */
    protected function queueLocations(iterable $orderUuids, ?string $connection): int
    {
        $queued = 0;
        foreach ($orderUuids as $orderUuid) {
            if (Cache::add('fleetops:tracking:location:' . $orderUuid, 1, self::LOCATION_INTERVAL)) {
                $queued += (int) $this->queue($orderUuid, TrackingUpdate::REASON_LOCATION, $connection);
            }
        }

        return $queued;
    }

    /**
     * Orders that have started and are not yet completed or canceled.
     */
    protected function enRouteOrders(?string $connection)
    {
        return Order::on($connection)
            ->whereNotIn('status', TrackingUpdate::TERMINAL_STATUSES)
            ->where(function ($query) {
                $query->where('started', 1)->orWhereNotNull('started_at')->orWhere('status', 'started');
            });
    }

    /**
     * @param Collection<int, TrackingStop> $stops
     *
     * @return array<int, Waypoint>
     */
    protected function waypointsOf(Collection $stops): array
    {
        return $stops->map(fn (TrackingStop $stop) => $stop->waypoint)->filter()->values()->all();
    }
}
