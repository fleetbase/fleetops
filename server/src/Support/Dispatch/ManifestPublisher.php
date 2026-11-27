<?php

namespace Fleetbase\FleetOps\Support\Dispatch;

use Fleetbase\FleetOps\Events\ManifestUpdated;
use Fleetbase\FleetOps\Jobs\NotifyBulkAssignedDriver;
use Fleetbase\FleetOps\Jobs\PublishScheduledManifest;
use Fleetbase\FleetOps\Models\Manifest;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Notifications\ManifestPublished;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Releases draft routes to their drivers, now or at a booked time.
 *
 * Publishing is the moment a driver first hears about a route: the
 * Orchestrator commits drafts quietly, and this sends
 *   - one `manifest_published` push to the route's driver,
 *   - a `manifest.updated` broadcast with `change: published`,
 *   - the per-order `order.driver_assigned` notices older Navigator builds
 *     load their orders from (queued, one job for the route).
 */
class ManifestPublisher
{
    /**
     * Publish a draft now. False when the manifest is not a draft.
     */
    public function publish(Manifest $manifest): bool
    {
        if (!$manifest->markPublished()) {
            return false;
        }

        $this->tellDriver($manifest);

        return true;
    }

    /**
     * Book a publish for later. The draft keeps its status until the queued
     * job runs; booking again replaces the earlier time (the earlier job finds
     * its time no longer matches and does nothing). A time that has passed
     * publishes now. False when the manifest is not a draft.
     */
    public function schedule(Manifest $manifest, Carbon $at): bool
    {
        if (!$manifest->isDraft()) {
            return false;
        }

        if ($at->lessThanOrEqualTo(now())) {
            return $this->publish($manifest);
        }

        $manifest->update(['publish_at' => $at]);
        $this->queue($manifest, $at);

        return true;
    }

    /**
     * Run a booked publish. Does nothing when the manifest was published,
     * cancelled or re-booked since, so a stale job is harmless.
     */
    public function publishIfDue(Manifest $manifest, string $bookedFor): bool
    {
        if (!$manifest->isDraft() || !$manifest->publish_at) {
            return false;
        }

        if (!$manifest->publish_at->equalTo(Carbon::parse($bookedFor)) || $manifest->publish_at->isFuture()) {
            return false;
        }

        return $this->publish($manifest);
    }

    protected function queue(Manifest $manifest, Carbon $at): void
    {
        PublishScheduledManifest::dispatch($manifest->uuid, $at->toIso8601String())->delay($at);
    }

    protected function tellDriver(Manifest $manifest): void
    {
        $driver = $manifest->driver;
        if (!$driver) {
            return;
        }

        try {
            $driver->notify(new ManifestPublished($manifest));
        } catch (\Throwable $exception) {
            Log::warning('Unable to notify driver of published route', ['manifest' => $manifest->public_id, 'error' => $exception->getMessage()]);
        }

        try {
            event(new ManifestUpdated($manifest, ManifestUpdated::PUBLISHED, $this->orderIds($manifest), $driver));
        } catch (\Throwable $exception) {
            Log::warning('Unable to broadcast manifest.updated', ['manifest' => $manifest->public_id, 'error' => $exception->getMessage()]);
        }

        $orderUuids = Order::where('manifest_uuid', $manifest->uuid)->where('driver_assigned_uuid', $driver->uuid)->pluck('uuid')->all();
        if ($orderUuids) {
            NotifyBulkAssignedDriver::dispatch($orderUuids, $driver->uuid);
        }
    }

    protected function orderIds(Manifest $manifest): array
    {
        return Order::where('manifest_uuid', $manifest->uuid)->pluck('public_id')->all();
    }
}
