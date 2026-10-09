<?php

namespace Fleetbase\FleetOps\Observers;

use Fleetbase\FleetOps\Models\Entity;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Payload;
use Fleetbase\FleetOps\Models\TrackingNumber;
use Fleetbase\FleetOps\Models\TrackingStatus;
use Fleetbase\FleetOps\Models\Waypoint;
use Fleetbase\FleetOps\Support\LiveCacheService;
use Fleetbase\FleetOps\Support\TrackingPublisher;
use Illuminate\Support\Facades\Cache;

class OrderObserver
{
    /**
     * Deletion timestamps captured while an order is being restored, keyed by order uuid.
     * Restoring clears the order's deleted_at before "restored" fires, so the timestamp the
     * children were deleted with is read in "restoring" and used once the restore succeeds.
     *
     * @var array<string, string>
     */
    protected static array $restoringFrom = [];

    /**
     * Handle the Order "created" event.
     *
     * @return void
     */
    public function created(Order $order)
    {
        $this->invalidateCache($order);
    }

    /**
     * Handle the Order "updating" event.
     *
     * This event is fired before the order is persisted to the database.
     * It is used to mutate attributes as part of the same update operation
     * without triggering additional save cycles.
     *
     * @param Order $order The order being updated
     */
    public function updating(Order $order): void
    {
        $this->ensureOrderStarted($order);
    }

    /**
     * Handle the Order "updated" event.
     *
     * @return void
     */
    public function updated(Order $order)
    {
        $order->setDriverLocationAsPickup();

        if ($order->wasChanged('driver_assigned_uuid')) {
            $order->notifyDriverAssigned();
        }

        $this->invalidateCache($order);

        // Public tracking channels follow status, assignment and ETA changes.
        app(TrackingPublisher::class)->orderChanged($order);
    }

    /**
     * Handle the Order "deleted" event.
     *
     * @return void
     */
    public function deleted(Order $order)
    {
        if ($order->isIntegratedVendorOrder()) {
            $order->facilitator->provider()->callback('onDeleted', $order);
        }

        $this->cascadeDelete($order);
        $this->invalidateCache($order);
    }

    /**
     * Handle the Order "restoring" event: remember when the order was deleted.
     */
    public function restoring(Order $order): void
    {
        if ($order->uuid && $order->deleted_at) {
            static::$restoringFrom[$order->uuid] = $order->fromDateTime($order->deleted_at);
        }
    }

    /**
     * Handle the Order "restored" event: bring back the children deleted with the order.
     */
    public function restored(Order $order): void
    {
        $deletedAt = static::$restoringFrom[$order->uuid] ?? null;
        unset(static::$restoringFrom[$order->uuid]);

        if ($deletedAt) {
            $this->cascadeRestore($order, $deletedAt);
        }

        $this->invalidateCache($order);
    }

    /**
     * Soft-deletes what belongs to the order: its tracking number and, unless another live
     * order shares it, its payload with the payload's entities and waypoints and their
     * tracking numbers, plus every one of those tracking numbers' statuses. Children are
     * stamped with the order's own deleted_at so a restore can find exactly those rows.
     */
    protected function cascadeDelete(Order $order): void
    {
        $deletedAt = $order->fromDateTime($order->deleted_at ?? $order->freshTimestamp());
        $tree      = $this->childTree($order, Payload::query(), Entity::query(), Waypoint::query());

        if ($tree['payload']) {
            Payload::where('uuid', $tree['payload'])->update(['deleted_at' => $deletedAt]);
            Entity::whereIn('uuid', $tree['entities'])->update(['deleted_at' => $deletedAt]);
            Waypoint::whereIn('uuid', $tree['waypoints'])->update(['deleted_at' => $deletedAt]);
        }

        $trackingNumbers = TrackingNumber::where(fn ($query) => $query->whereIn('uuid', $tree['tracking_numbers'])->orWhereIn('owner_uuid', $tree['owners']))->pluck('uuid')->all();
        TrackingStatus::whereIn('tracking_number_uuid', $trackingNumbers)->update(['deleted_at' => $deletedAt]);
        TrackingNumber::whereIn('uuid', $trackingNumbers)->update(['deleted_at' => $deletedAt]);
    }

    /**
     * Restores the children cascadeDelete() removed: the rows of the same tree whose
     * deleted_at matches the order's. Children deleted on their own beforehand carry
     * a different timestamp and stay deleted.
     */
    protected function cascadeRestore(Order $order, string $deletedAt): void
    {
        $trashed = fn (string $model) => $model::onlyTrashed()->where('deleted_at', $deletedAt);
        $tree    = $this->childTree($order, $trashed(Payload::class), $trashed(Entity::class), $trashed(Waypoint::class));

        $trackingNumbers = $trashed(TrackingNumber::class)->where(fn ($query) => $query->whereIn('uuid', $tree['tracking_numbers'])->orWhereIn('owner_uuid', $tree['owners']))->pluck('uuid')->all();
        $trashed(TrackingNumber::class)->whereIn('uuid', $trackingNumbers)->update(['deleted_at' => null]);
        $trashed(TrackingStatus::class)->whereIn('tracking_number_uuid', $trackingNumbers)->update(['deleted_at' => null]);

        if ($tree['payload']) {
            $trashed(Payload::class)->where('uuid', $tree['payload'])->update(['deleted_at' => null]);
            $trashed(Entity::class)->whereIn('uuid', $tree['entities'])->update(['deleted_at' => null]);
            $trashed(Waypoint::class)->whereIn('uuid', $tree['waypoints'])->update(['deleted_at' => null]);
        }
    }

    /**
     * Collects the uuids under an order from the given payload, entity and waypoint
     * queries. The payload is left out when another live order still references it.
     *
     * @return array{payload: ?string, entities: array, waypoints: array, tracking_numbers: array, owners: array}
     */
    protected function childTree(Order $order, $payloads, $entities, $waypoints): array
    {
        $tree = ['payload' => null, 'entities' => [], 'waypoints' => [], 'tracking_numbers' => [$order->tracking_number_uuid], 'owners' => [$order->uuid]];

        $shared  = $order->payload_uuid && Order::where('payload_uuid', $order->payload_uuid)->where('uuid', '!=', $order->uuid)->exists();
        $payload = $order->payload_uuid && !$shared ? $payloads->where('uuid', $order->payload_uuid)->first() : null;

        if ($payload) {
            $children = $entities->where('payload_uuid', $payload->uuid)->get(['uuid', 'tracking_number_uuid'])
                ->concat($waypoints->where('payload_uuid', $payload->uuid)->get(['uuid', 'tracking_number_uuid']));

            $tree['payload']          = $payload->uuid;
            $tree['entities']         = $children->whereInstanceOf(Entity::class)->pluck('uuid')->all();
            $tree['waypoints']        = $children->whereInstanceOf(Waypoint::class)->pluck('uuid')->all();
            $tree['tracking_numbers'] = array_merge($tree['tracking_numbers'], [$payload->pickup_tracking_number_uuid, $payload->dropoff_tracking_number_uuid, $payload->return_tracking_number_uuid], $children->pluck('tracking_number_uuid')->all());
            $tree['owners']           = array_merge($tree['owners'], $children->pluck('uuid')->all());
        }

        $tree['tracking_numbers'] = array_values(array_filter($tree['tracking_numbers']));

        return $tree;
    }

    /**
     * Invalidate relevant cache tags for live endpoints.
     *
     * @param Order|null $order Optional order to invalidate specific tracker cache
     */
    protected function invalidateCache(?Order $order = null): void
    {
        LiveCacheService::invalidateMultiple(['orders', 'routes', 'coordinates']);

        // Invalidate order-specific tracker cache if order is provided
        if ($order && $order->uuid) {
            Cache::forget("order:{$order->uuid}:tracker");
        }
    }

    /**
     * Detects when an order has just transitioned to the "started" status
     * and initializes start-related fields.
     *
     * This method should be called during the "updating" lifecycle event
     * to ensure that the changes are persisted as part of the same database
     * update and do not trigger additional observer events.
     *
     * An order is considered "started" when:
     * - The "status" attribute is being changed in the current update
     * - The previous status was not "started"
     * - The new status is "started"
     *
     * When these conditions are met, the order's start timestamp and
     * started flag are set if they have not already been initialized.
     *
     * @param Order $order The order being evaluated for a start transition
     */
    protected function ensureOrderStarted(Order $order): void
    {
        if (
            $order->isDirty('status')
            && $order->getOriginal('status') === 'dispatched'
            && $order->status === 'started'
        ) {
            // Only set defaults if not explicitly provided
            if (is_null($order->started_at)) {
                $order->started_at = now();
            }

            if (!$order->started) {
                $order->started = true;
            }
        }
    }
}
