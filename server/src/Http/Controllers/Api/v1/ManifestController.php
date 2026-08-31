<?php

namespace Fleetbase\FleetOps\Http\Controllers\Api\v1;

use Fleetbase\FleetOps\Http\Resources\v1\Manifest as ManifestResource;
use Fleetbase\FleetOps\Http\Resources\v1\ManifestStop as ManifestStopResource;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Manifest;
use Fleetbase\FleetOps\Models\ManifestStop;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * Driver-facing manifests.
 *
 * A manifest is a driver's route: an order-agnostic sequence of stops, which
 * may span several orders or none the driver has seen as an order. Everything a
 * driver app needs to *run* a route lived only behind the console's internal
 * namespace, so a driver could be assigned a route they had no way to read.
 *
 * These endpoints are deliberately narrow. A driver may read their own
 * manifests, update the stops on them, and re-sequence the ones they have not
 * done yet. They may not create, cancel or delete a manifest — that is dispatch
 * work, and it stays on the internal namespace.
 */
class ManifestController extends Controller
{
    /**
     * GET /v1/drivers/{id}/manifests.
     *
     * Defaults to what a driver actually needs on shift — today and anything
     * still unfinished — rather than their whole history, which on a busy fleet
     * is thousands of rows nobody asked for.
     */
    public function forDriver(Request $request, string $id)
    {
        $driver = static::findDriverRecord($id);
        if (!$driver) {
            return response()->apiError('Driver resource not found.', 404);
        }

        $query = static::manifestsFor($driver);

        if ($request->filled('status')) {
            $query->whereIn('status', Utils::arrayFrom($request->input('status')));
        }

        if ($request->filled('on')) {
            $query->whereDate('scheduled_date', $request->input('on'));
        }

        return ManifestResource::collection($query->limit((int) $request->input('limit', 30))->get());
    }

    /**
     * GET /v1/manifests/{id} — the route, with its stops in sequence.
     */
    public function show(string $id)
    {
        $manifest = static::findManifest($id);
        if (!$manifest) {
            return response()->apiError('Manifest resource not found.', 404);
        }

        $manifest->load(['driver', 'vehicle', 'stops' => fn ($q) => $q->orderBy('sequence')->with(['place', 'order.trackingNumber'])]);

        return new ManifestResource($manifest);
    }

    /**
     * PATCH /v1/manifest-stops/{id}.
     *
     * Status changes go through the model's own transitions rather than being
     * written as a column, because arriving and completing carry side effects —
     * timestamps, and the manifest completing itself once its last stop does.
     */
    public function updateStop(Request $request, string $id)
    {
        $stop = static::findStop($id);
        if (!$stop) {
            return response()->apiError('Manifest stop resource not found.', 404);
        }

        $status = $request->input('status');
        if ($status) {
            // Checked before it is applied: an unknown status must change
            // nothing, not fall through a match and then be refused.
            if (!in_array($status, ['arrived', 'completed', 'skipped'], true)) {
                return response()->apiError('Status must be one of: arrived, completed, skipped.', 422);
            }

            match ($status) {
                'arrived'   => $stop->markArrived(),
                'completed' => $stop->markCompleted(),
                default     => $stop->markSkipped(),
            };
        }

        if ($request->filled('meta')) {
            $stop->update(['meta' => $request->input('meta')]);
        }

        $stop->load(['place', 'order.trackingNumber']);

        return new ManifestStopResource($stop);
    }

    /**
     * POST /v1/manifests/{id}/optimize — re-sequence the stops still to do.
     *
     * This is the *driver's* optimise, not the orchestrator's. The orchestrator
     * allocates orders across a fleet and produces manifests; this reorders the
     * stops on one manifest that is already assigned, which is a different job
     * with a different owner.
     *
     * **It is a nearest-neighbour heuristic, not a solved routing problem.** It
     * walks from the driver's current position to the closest remaining stop,
     * then to the closest from there, by straight-line distance. That is
     * typically a large improvement over an arbitrary order and is not
     * guaranteed optimal; calling it anything stronger would be a claim the
     * implementation does not support. Completed and skipped stops keep their
     * place — a route already driven is not re-planned.
     */
    public function optimize(Request $request, string $id)
    {
        $manifest = static::findManifest($id);
        if (!$manifest) {
            return response()->apiError('Manifest resource not found.', 404);
        }

        $manifest->load(['stops' => fn ($q) => $q->orderBy('sequence')->with('place')]);

        $done    = $manifest->stops->filter(fn ($s) => in_array($s->status, ['completed', 'skipped'], true))->values();
        $pending = $manifest->stops->reject(fn ($s) => in_array($s->status, ['completed', 'skipped'], true))->values();

        if ($pending->count() < 3) {
            // Two stops have only one order, and one has none. Re-sequencing
            // either is work with no possible result.
            return $this->show($id);
        }

        $from = $request->filled(['latitude', 'longitude'])
            ? ['lat' => (float) $request->input('latitude'), 'lon' => (float) $request->input('longitude')]
            : static::coordinatesOf($pending->first());

        $remaining = $pending->all();
        $ordered   = [];

        while (count($remaining)) {
            $closestIndex = 0;
            $closestCost  = null;

            foreach ($remaining as $index => $stop) {
                $to = static::coordinatesOf($stop);
                if (!$from || !$to) {
                    continue;
                }

                $cost = static::distanceBetween($from, $to);

                if ($closestCost === null || $cost < $closestCost) {
                    $closestCost  = $cost;
                    $closestIndex = $index;
                }
            }

            $next      = $remaining[$closestIndex];
            $ordered[] = $next;
            $from      = static::coordinatesOf($next) ?? $from;
            unset($remaining[$closestIndex]);
            $remaining = array_values($remaining);
        }

        // Done stops keep the front of the sequence; the rest follow in the new
        // order. Sequence stays dense so nothing downstream has to cope with
        // gaps.
        $sequence = 1;
        foreach ($done as $stop) {
            $stop->update(['sequence' => $sequence++]);
        }
        foreach ($ordered as $stop) {
            $stop->update(['sequence' => $sequence++]);
        }

        return $this->show($id);
    }

    /** The driver's manifests, newest first. Separated so it can be stubbed. */
    protected static function manifestsFor(Driver $driver)
    {
        return Manifest::where('driver_uuid', $driver->uuid)
            ->with(['driver', 'vehicle'])
            ->orderBy('scheduled_date', 'desc');
    }

    protected static function findDriverRecord(string $id): ?Driver
    {
        return Driver::where('public_id', $id)->orWhere('uuid', $id)->first();
    }

    protected static function findManifest(string $id): ?Manifest
    {
        return Manifest::where('public_id', $id)->orWhere('uuid', $id)->first();
    }

    protected static function findStop(string $id): ?ManifestStop
    {
        return ManifestStop::where('public_id', $id)->orWhere('uuid', $id)->first();
    }

    /**
     * Straight-line distance between two coordinates, in metres.
     *
     * Deliberately not a routing call. A nearest-neighbour walk compares every
     * remaining stop at every step, so a twenty-stop route asks roughly four
     * hundred distance questions — as road lookups that is four hundred network
     * round trips for one tap, which is not a thing to do on a handset waiting
     * on a driver.
     *
     * Straight-line ordering and road ordering rarely disagree about which of
     * several stops is nearest, and where they do the result is still a valid
     * route, just not the shortest one. Since the method is a heuristic either
     * way, the cheap version is the honest choice.
     */
    protected static function distanceBetween(array $from, array $to): float
    {
        $earthRadius = 6371000.0;

        $lat1 = deg2rad($from['lat']);
        $lat2 = deg2rad($to['lat']);
        $dLat = $lat2 - $lat1;
        $dLon = deg2rad($to['lon'] - $from['lon']);

        $a = sin($dLat / 2) ** 2 + cos($lat1) * cos($lat2) * sin($dLon / 2) ** 2;

        return $earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /** Latitude and longitude of a stop's place, when it has one. */
    protected static function coordinatesOf(?ManifestStop $stop): ?array
    {
        $location = data_get($stop, 'place.location');
        if (!$location) {
            return null;
        }

        return ['lat' => (float) $location->getLat(), 'lon' => (float) $location->getLng()];
    }
}
