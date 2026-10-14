<?php

namespace Fleetbase\FleetOps\Http\Resources\v1;

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Support\Utils;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What the public Track Order page gets for a tracking number.
 *
 * The lookup has no session, so whoever holds the tracking number sees this.
 * It carries only what `order-tracking-lookup` renders: status, the tracking
 * timeline, ETA and progress, the stop coordinates the map routes between,
 * and the item list. Notes, meta, internal ids, files, rates, and customer
 * and facilitator details stay out. The driver's position is included only
 * while the order is under way.
 *
 * It extends `JsonResource`, not `FleetbaseResource`, so transformers that
 * other extensions register for resources cannot add fields to it. Records are
 * keyed by public id, which Ember Data needs to build them, so the page never
 * learns an internal uuid.
 */
class PublicOrderTracking extends JsonResource
{
    /**
     * Never wrapped, whatever another resource set the shared wrapper to.
     *
     * @var string|null
     */
    public static $wrap;

    /**
     * Statuses after which the order is no longer under way.
     */
    public const FINISHED_STATUSES = ['completed', 'canceled', 'cancelled'];

    /**
     * Transform the resource into an array.
     *
     * @param \Illuminate\Http\Request $request
     */
    public function toArray($request): array
    {
        /** @var Order $order */
        $order          = $this->resource;
        $trackingNumber = $order->trackingNumber;
        $tracker        = is_array($order->tracker_data) ? $order->tracker_data : [];

        return [
            'uuid'                => $order->public_id,
            'tracking'            => data_get($trackingNumber, 'tracking_number'),
            'status'              => $order->status,
            'has_driver_assigned' => (bool) $order->has_driver_assigned,
            'tracking_number'     => $trackingNumber ? [
                'uuid'            => $trackingNumber->public_id,
                'tracking_number' => $trackingNumber->tracking_number,
            ] : null,
            'tracking_statuses'   => collect($order->trackingStatuses)->map(fn ($status) => [
                'uuid'       => $status->public_id,
                'status'     => $status->status,
                'details'    => $status->details,
                'created_at' => $status->created_at,
            ])->values()->all(),
            'payload'             => $this->payload($order->payload),
            'tracker_data'        => $this->trackerData($tracker, static::isInProgress($order)),
            'created_at'          => $order->created_at,
        ];
    }

    /**
     * Whether the order is under way: started, and not yet completed or canceled.
     */
    public static function isInProgress(Order $order): bool
    {
        return (bool) $order->started && !in_array(strtolower((string) $order->status), static::FINISHED_STATUSES, true);
    }

    /**
     * The stops the map routes between and the items the page lists.
     */
    protected function payload($payload): ?array
    {
        if (!$payload) {
            return null;
        }

        return [
            'uuid'      => $payload->public_id,
            'pickup'    => $this->place($payload->pickup),
            'dropoff'   => $this->place($payload->dropoff),
            'waypoints' => collect($payload->waypoints)->map(fn ($place) => $this->place($place))->values()->all(),
            'entities'  => collect($payload->entities)->map(fn ($entity) => [
                'uuid'        => $entity->public_id,
                'name'        => $entity->name,
                'description' => $entity->description,
                'tracking'    => data_get($entity, 'tracking'),
                'price'       => $entity->price,
                'currency'    => $entity->currency,
                'photo_url'   => data_get($entity, 'photo_url'),
            ])->values()->all(),
        ];
    }

    /**
     * A stop as the map needs it: its coordinates, with no address or contact.
     */
    protected function place($place): ?array
    {
        if (!$place) {
            return null;
        }

        return [
            'uuid'     => $place->public_id,
            'location' => Utils::castPoint($place->location),
        ];
    }

    /**
     * The tracker fields the page reads; the driver's position only while under way.
     */
    protected function trackerData(array $tracker, bool $inProgress): array
    {
        return [
            'driver'      => [
                'location' => $inProgress ? data_get($tracker, 'driver.location') : null,
            ],
            'progress'    => [
                'percentage'      => data_get($tracker, 'progress.percentage'),
                'completed_stops' => data_get($tracker, 'progress.completed_stops'),
            ],
            'eta'         => [
                'active_stop_seconds' => data_get($tracker, 'eta.active_stop_seconds'),
                'completion_at'       => data_get($tracker, 'eta.completion_at'),
            ],
            'active_stop' => $this->stop(data_get($tracker, 'active_stop')),
            'next_stop'   => $this->stop(data_get($tracker, 'next_stop')),
        ];
    }

    /**
     * A stop the page names under "Current/Next Destination".
     */
    protected function stop($stop): ?array
    {
        if (!is_array($stop)) {
            return null;
        }

        return ['address' => data_get($stop, 'address')];
    }
}
