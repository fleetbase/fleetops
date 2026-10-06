<?php

namespace Fleetbase\FleetOps\Listeners;

use Fleetbase\FleetOps\Support\TrackingPublisher;

/**
 * Queues public tracking updates when a waypoint's or an entity's activity changes.
 *
 * Runs synchronously but only looks up the order and queues a job, and does nothing at all
 * while socket authentication is off.
 */
class PublishTrackingUpdates
{
    public function __construct(protected ?TrackingPublisher $publisher = null)
    {
        $this->publisher ??= app(TrackingPublisher::class);
    }

    /**
     * @param \Fleetbase\FleetOps\Events\WaypointActivityChanged|\Fleetbase\FleetOps\Events\WaypointCompleted|\Fleetbase\FleetOps\Events\EntityActivityChanged|\Fleetbase\FleetOps\Events\EntityCompleted $event
     */
    public function handle(object $event): bool
    {
        return $this->publisher->stopChanged($event->waypoint ?? $event->entity ?? null);
    }
}
