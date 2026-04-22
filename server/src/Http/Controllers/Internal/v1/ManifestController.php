<?php

namespace Fleetbase\FleetOps\Http\Controllers\Internal\v1;

use Fleetbase\FleetOps\Models\Manifest;
use Fleetbase\FleetOps\Models\ManifestStop;
use Fleetbase\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ManifestController.
 *
 * Provides CRUD and status-transition endpoints for Manifests and their stops.
 *
 * Endpoints:
 *   GET    /int/v1/fleet-ops/manifests              — list manifests for company
 *   GET    /int/v1/fleet-ops/manifests/{id}         — get a single manifest with stops
 *   DELETE /int/v1/fleet-ops/manifests/{id}         — cancel/delete a manifest
 *   POST   /int/v1/fleet-ops/manifests/{id}/cancel  — cancel a manifest
 *   GET    /int/v1/fleet-ops/manifest-stops/{id}    — get a single stop
 *   PATCH  /int/v1/fleet-ops/manifest-stops/{id}    — update a stop (status, sequence)
 */
class ManifestController extends Controller
{
    /**
     * List manifests for the current company.
     * Supports filtering by status, driver_id, vehicle_id, and scheduled_date.
     *
     * GET /int/v1/fleet-ops/manifests
     */
    public function index(Request $request): JsonResponse
    {
        $companyUuid = session('company');
        $query       = Manifest::forCompany($companyUuid)
            ->with(['driver', 'vehicle', 'stops.place', 'stops.order.trackingNumber']);

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('driver_id')) {
            $query->whereHas('driver', fn ($q) => $q->where('public_id', $request->input('driver_id')));
        }
        if ($request->filled('vehicle_id')) {
            $query->whereHas('vehicle', fn ($q) => $q->where('public_id', $request->input('vehicle_id')));
        }
        if ($request->filled('scheduled_date')) {
            $query->whereDate('scheduled_date', $request->input('scheduled_date'));
        }

        $manifests = $query->orderByDesc('created_at')->paginate($request->input('limit', 30));

        return response()->json($manifests);
    }

    /**
     * Get a single manifest with its full stop list.
     *
     * GET /int/v1/fleet-ops/manifests/{id}
     */
    public function show(string $id): JsonResponse
    {
        $manifest = Manifest::where('public_id', $id)
            ->with(['driver', 'vehicle', 'stops' => fn ($q) => $q->orderBy('sequence')->with(['place', 'order.trackingNumber', 'order.payload.dropoff'])])
            ->firstOrFail();

        return response()->json(['manifest' => $manifest]);
    }

    /**
     * Cancel a manifest (soft delete + status update).
     *
     * POST /int/v1/fleet-ops/manifests/{id}/cancel
     */
    public function cancel(string $id): JsonResponse
    {
        $manifest = Manifest::where('public_id', $id)->firstOrFail();
        $manifest->cancel();

        return response()->json(['status' => 'cancelled', 'manifest' => $manifest]);
    }

    /**
     * Delete (soft-delete) a manifest.
     *
     * DELETE /int/v1/fleet-ops/manifests/{id}
     */
    public function destroy(string $id): JsonResponse
    {
        $manifest = Manifest::where('public_id', $id)->firstOrFail();
        $manifest->delete();

        return response()->json(['deleted' => true]);
    }

    /**
     * Get a single manifest stop.
     *
     * GET /int/v1/fleet-ops/manifest-stops/{id}
     */
    public function showStop(string $id): JsonResponse
    {
        $stop = ManifestStop::where('public_id', $id)
            ->with(['place', 'order.trackingNumber', 'order.payload.dropoff', 'waypoint'])
            ->firstOrFail();

        return response()->json(['stop' => $stop]);
    }

    /**
     * Update a manifest stop (status, sequence, notes).
     * Used by the dispatcher for manual reordering and by the Navigator app
     * for arrived/completed/skipped status transitions.
     *
     * PATCH /int/v1/fleet-ops/manifest-stops/{id}
     */
    public function updateStop(Request $request, string $id): JsonResponse
    {
        $stop = ManifestStop::where('public_id', $id)->firstOrFail();

        $allowed = ['status', 'sequence', 'actual_arrival', 'meta'];
        $data    = $request->only($allowed);

        // Handle status transitions via dedicated methods for side-effect logic
        if (isset($data['status'])) {
            match ($data['status']) {
                'arrived'   => $stop->markArrived(),
                'completed' => $stop->markCompleted(),
                'skipped'   => $stop->markSkipped(),
                default     => $stop->update($data),
            };
        } else {
            $stop->update($data);
        }

        return response()->json(['stop' => $stop->fresh(['place', 'order.trackingNumber'])]);
    }
}
