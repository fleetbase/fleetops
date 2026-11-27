<?php

namespace Fleetbase\FleetOps\Support\Dispatch;

use Fleetbase\FleetOps\Events\ManifestUpdated;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Manifest;
use Fleetbase\FleetOps\Models\ManifestStop;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Vehicle;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * The one way an order changes hands.
 *
 * An order that moves from one driver to another has to move between their
 * routes too: off the old manifest (whose stops close up), onto the new one
 * at a position (whose stops re-number), with the order's driver, vehicle and
 * manifest following, and both drivers told. Before this every entry point
 * (order detail, bulk assign, the Scheduler, the Orchestrator) changed only
 * the order's driver and left the manifests describing a route nobody drove.
 *
 * Rules:
 *   - Only stops the driver has not finished move. Completed and skipped
 *     stops stay on the route they were driven on.
 *   - A stop is never placed before a finished stop: the start of a route is
 *     history.
 *   - Without a target manifest the order leaves its route and becomes an
 *     unrouted order of the new driver (or of nobody).
 *   - The order is saved through the model, so the order observer sends the
 *     usual per-order notices: `order.driver_unassigned` to the driver who
 *     lost it and `order.driver_assigned` to the one who got it. A route the
 *     driver can see also broadcasts `manifest.updated`.
 */
class RouteReassignment
{
    /**
     * Move an order to a driver, or off every driver with `$driver = null`.
     *
     * Options:
     *   manifest  Manifest|null  the route to put the order on. Without it the
     *                            driver's open route for the date is used.
     *   position  int|null       1-based stop position on that route; the end
     *                            when omitted.
     *   vehicle   Vehicle|null   the vehicle when no route decides it.
     *   date      string|null    the day (Y-m-d) to look for the driver's route
     *                            on; defaults to the order's current route's
     *                            day, then the order's scheduled day.
     *   silent    bool           don't send the new driver the per-order
     *                            assigned notice (a caller that notifies in
     *                            bulk, or a draft plan nobody has seen).
     *
     * @return array{order: Order, from: Manifest|null, to: Manifest|null}
     */
    public function reassign(Order $order, ?Driver $driver, array $options = []): array
    {
        $from = $this->currentManifest($order);
        $to   = array_key_exists('manifest', $options) ? $options['manifest'] : null;

        if ($to instanceof Manifest && !$driver && $to->driver_uuid) {
            $driver = $this->findDriver($to->driver_uuid);
        }

        if (!array_key_exists('manifest', $options)) {
            $to = $this->targetManifest($order, $driver, $from, $options['date'] ?? $from?->scheduled_date?->toDateString() ?? $this->scheduledDay($order));
        }

        $position = isset($options['position']) ? (int) $options['position'] : null;
        $moved    = [];

        $this->transaction(function () use ($order, $driver, $from, $to, $position, $options, &$moved) {
            $sameRoute = $from && $to && $from->uuid === $to->uuid;
            $stops     = collect();

            if ($from && !$sameRoute) {
                $stops = $this->pendingStops($from, $order);
                $this->closeUp($from, $stops);
                $moved['from'] = true;
            }

            if ($to) {
                if ($sameRoute) {
                    $stops = $this->pendingStops($to, $order);
                }
                $created = $stops->isEmpty();
                if ($created) {
                    $stops = collect([$this->newStop($to, $order)]);
                }
                if (!$sameRoute || $position !== null || $created) {
                    $this->place($to, $stops, $position);
                    $moved['to'] = true;
                }
            } elseif ($stops->isNotEmpty()) {
                // Leaving every route: the stops have nowhere to go.
                $stops->each(fn (ManifestStop $stop) => $this->removeStop($stop));
            }

            // Written only when they change, so a save carries no column the
            // move did not touch.
            $changes = [
                'driver_assigned_uuid'  => $driver?->uuid,
                'vehicle_assigned_uuid' => $this->vehicleFor($order, $driver, $to, $options['vehicle'] ?? null),
            ];
            if ($to || $from) {
                $changes['manifest_uuid'] = $to?->uuid;
            }
            foreach ($changes as $column => $value) {
                if ($order->getAttribute($column) !== $value) {
                    $order->setAttribute($column, $value);
                }
            }

            $order->silentDriverAssignment = (bool) ($options['silent'] ?? false);
            try {
                $order->save();
            } finally {
                $order->silentDriverAssignment = false;
            }
        });

        if (!empty($moved['from'])) {
            $this->announce($from, ManifestUpdated::STOP_REMOVED, [$order->public_id]);
        }
        if (!empty($moved['to'])) {
            $this->announce($to, $from && $from->uuid === $to->uuid ? ManifestUpdated::RESEQUENCED : ManifestUpdated::STOP_ADDED, [$order->public_id]);
        }

        return ['order' => $order, 'from' => $from, 'to' => $to];
    }

    /**
     * Bring the order's route in line after its driver was changed by a plain
     * save (the order form's PUT). The order is already saved with its new
     * driver, so the per-order notices have been sent; this moves the stops.
     */
    public function followDriverChange(Order $order): ?array
    {
        $from = $this->currentManifest($order);
        if (!$from || $from->driver_uuid === $order->driver_assigned_uuid) {
            return null;
        }

        $driver = $order->driver_assigned_uuid ? $this->findDriver($order->driver_assigned_uuid) : null;

        return $this->reassign($order, $driver);
    }

    /**
     * Take the order's unfinished stops off its current route, unless that
     * route is `$keep`. Used when a new plan is committed: the order is about
     * to get a stop on a new manifest and must not stay on the old one too.
     * The order itself is not saved; the caller does that.
     */
    public function release(Order $order, ?Manifest $keep = null): ?Manifest
    {
        $from = $this->currentManifest($order);
        if (!$from || ($keep && $keep->uuid === $from->uuid)) {
            return null;
        }

        $this->transaction(function () use ($from, $order) {
            $stops = $this->pendingStops($from, $order);
            $this->closeUp($from, $stops);
            $stops->each(fn (ManifestStop $stop) => $this->removeStop($stop));
        });

        $this->announce($from, ManifestUpdated::STOP_REMOVED, [$order->public_id]);

        return $from;
    }

    /**
     * The open route the order is on, in the order's own company.
     */
    public function currentManifest(Order $order): ?Manifest
    {
        if (!$order->manifest_uuid) {
            return null;
        }

        /** @var Manifest|null $manifest */
        $manifest = Manifest::where('uuid', $order->manifest_uuid)
            ->where('company_uuid', $order->company_uuid)
            ->open()
            ->first();

        return $manifest;
    }

    /**
     * The driver's open route for the day. With a draft and a published route
     * on the same day, an order coming off a draft goes to a draft (it is
     * re-planning) and any other goes to the route the driver can see.
     */
    public function targetManifest(Order $order, ?Driver $driver, ?Manifest $from, ?string $date): ?Manifest
    {
        if (!$driver) {
            return null;
        }

        if ($from && $from->driver_uuid === $driver->uuid && ($date === null || $from->scheduled_date?->toDateString() === $date)) {
            return $from;
        }

        if ($date === null) {
            return null;
        }

        $candidates = $this->openManifestsFor($order->company_uuid, $driver->uuid, $date);
        if ($candidates->isEmpty()) {
            return null;
        }

        $preferDraft = $from?->isDraft() ?? false;

        return $candidates->first(fn (Manifest $manifest) => $manifest->isDraft() === $preferDraft) ?? $candidates->first();
    }

    /**
     * Stops the driver still has to do for the order on a route, in sequence.
     */
    public function pendingStops(Manifest $manifest, Order $order): Collection
    {
        return ManifestStop::where('manifest_uuid', $manifest->uuid)
            ->where('order_uuid', $order->uuid)
            ->whereNotIn('status', ManifestStop::DONE_STATUSES)
            ->orderBy('sequence')
            ->get();
    }

    /**
     * Put stops on a route at a 1-based position (the end when null) and
     * re-number the whole route. A position before the last finished stop
     * moves to just after it.
     */
    public function place(Manifest $manifest, Collection $stops, ?int $position = null): void
    {
        $moving   = $stops->pluck('uuid')->all();
        $existing = $this->stopsOf($manifest)->reject(fn (ManifestStop $stop) => in_array($stop->uuid, $moving, true))->values();

        $earliest = 0;
        foreach ($existing as $index => $stop) {
            if ($stop->isDone()) {
                $earliest = $index + 1;
            }
        }

        $index = $position === null ? $existing->count() : min(max($position - 1, $earliest), $existing->count());

        $this->renumber($manifest, $existing->slice(0, $index)->concat($stops)->concat($existing->slice($index))->values());
    }

    /**
     * Re-number a route without the stops that are leaving it.
     */
    protected function closeUp(Manifest $manifest, Collection $leaving): void
    {
        $leavingUuids = $leaving->pluck('uuid')->all();

        $this->renumber($manifest, $this->stopsOf($manifest)->reject(fn (ManifestStop $stop) => in_array($stop->uuid, $leavingUuids, true))->values());
    }

    /**
     * Write a dense 1..n sequence and the stop count. Only rows whose route or
     * position changed are written.
     */
    protected function renumber(Manifest $manifest, Collection $stops): void
    {
        foreach ($stops->values() as $index => $stop) {
            $changes = [];
            if ($stop->manifest_uuid !== $manifest->uuid) {
                $changes['manifest_uuid'] = $manifest->uuid;
                // The leg into a stop that changed routes was measured on the old one.
                $changes['distance_from_prev_m'] = 0;
                $changes['duration_from_prev_s'] = 0;
            }
            if ((int) $stop->sequence !== $index + 1) {
                $changes['sequence'] = $index + 1;
            }
            if ($changes) {
                $stop->update($changes);
            }
        }

        if ((int) $manifest->stop_count !== $stops->count()) {
            $manifest->update(['stop_count' => $stops->count()]);
        }
    }

    protected function stopsOf(Manifest $manifest): Collection
    {
        return ManifestStop::where('manifest_uuid', $manifest->uuid)->orderBy('sequence')->get();
    }

    /**
     * A stop for an order that is not on any route yet: its drop-off, or its
     * last waypoint for a multi-drop order.
     */
    protected function newStop(Manifest $manifest, Order $order): ManifestStop
    {
        $order->loadMissing(['payload.dropoff', 'payload.waypoints']);
        $place = $order->payload?->dropoff ?? $order->payload?->waypoints?->last();

        return ManifestStop::create([
            'manifest_uuid' => $manifest->uuid,
            'order_uuid'    => $order->uuid,
            'place_uuid'    => $place?->uuid,
            'status'        => 'pending',
            'sequence'      => 0,
        ]);
    }

    protected function removeStop(ManifestStop $stop): void
    {
        $stop->delete();
    }

    /**
     * The route's vehicle when the order is on one; otherwise the vehicle
     * asked for, then the new driver's vehicle, then whatever the order had.
     */
    protected function vehicleFor(Order $order, ?Driver $driver, ?Manifest $to, ?Vehicle $vehicle): ?string
    {
        return $to?->vehicle_uuid ?? $vehicle?->uuid ?? $driver?->vehicle_uuid ?? $order->vehicle_assigned_uuid;
    }

    protected function scheduledDay(Order $order): ?string
    {
        $scheduledAt = $order->scheduled_at;
        if (!$scheduledAt) {
            return null;
        }

        return ($scheduledAt instanceof Carbon ? $scheduledAt : Carbon::parse($scheduledAt))->toDateString();
    }

    protected function openManifestsFor(?string $companyUuid, string $driverUuid, string $date): Collection
    {
        return Manifest::where('company_uuid', $companyUuid)
            ->where('driver_uuid', $driverUuid)
            ->whereDate('scheduled_date', $date)
            ->open()
            ->orderBy('created_at')
            ->get();
    }

    protected function findDriver(string $uuid): ?Driver
    {
        /** @var Driver|null $driver */
        $driver = Driver::where('uuid', $uuid)->first();

        return $driver;
    }

    protected function transaction(callable $callback): void
    {
        (new Manifest())->getConnection()->transaction($callback);
    }

    /**
     * Tell the route's driver it changed. Drafts are the dispatcher's alone,
     * so nothing is sent for them. A broadcast that fails must not undo a
     * reassignment that is already saved.
     */
    protected function announce(Manifest $manifest, string $change, array $orders): void
    {
        $manifest->refresh();
        if (!$manifest->isVisibleToDriver()) {
            return;
        }

        try {
            event(new ManifestUpdated($manifest, $change, $orders));
        } catch (\Throwable $exception) {
            Log::warning('Unable to broadcast manifest.updated', ['manifest' => $manifest->public_id, 'error' => $exception->getMessage()]);
        }
    }
}
