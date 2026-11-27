<?php

namespace Fleetbase\FleetOps\Http\Controllers\Internal\v1;

use Fleetbase\FleetOps\Models\Manifest;
use Fleetbase\FleetOps\Models\ManifestStop;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Support\Dispatch\DispatchDate;
use Fleetbase\FleetOps\Support\Dispatch\InsertionEstimator;
use Fleetbase\FleetOps\Support\Dispatch\ManifestPublisher;
use Fleetbase\FleetOps\Support\Dispatch\RouteReassignment;
use Fleetbase\FleetOps\Traits\AuthorizesMethods;
use Fleetbase\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
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
 *   POST   /int/v1/fleet-ops/manifests/publish      — publish drafts (by ids or date), now or later
 *   GET    /int/v1/fleet-ops/manifests/best-fit     — cheapest insertion of an order, per driver
 *   POST   /int/v1/fleet-ops/manifests/{id}/publish — publish one draft, now or later
 *   POST   /int/v1/fleet-ops/manifests/{id}/stops   — insert an order as a stop at a position
 *   GET    /int/v1/fleet-ops/manifest-stops/{id}    — get a single stop
 *   PATCH  /int/v1/fleet-ops/manifest-stops/{id}    — update a stop (status, sequence)
 */
class ManifestController extends Controller
{
    use AuthorizesMethods;

    public function __construct()
    {
        $this->authorizeMethods([
            'index'       => 'list order',
            'show'        => 'view order',
            'cancel'      => 'cancel order',
            'destroy'     => 'delete order',
            'showStop'    => 'view order',
            'updateStop'  => 'update order',
            'publish'     => 'dispatch order',
            'publishMany' => 'dispatch order',
            'insertStop'  => 'assign-driver-for order',
            'bestFit'     => 'list order',
        ]);
    }

    /**
     * List manifests for the current company.
     * Supports filtering by status, driver_id, vehicle_id, and scheduled_date.
     *
     * GET /int/v1/fleet-ops/manifests
     */
    public function index(Request $request): JsonResponse
    {
        $companyUuid = session('company');
        $query       = $this->manifestQueryForCompany($companyUuid);

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
        $manifest = $this->manifestQueryByPublicId($id)
            ->with($this->manifestShowRelations())
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
        $manifest = $this->manifestQueryByPublicId($id)->firstOrFail();
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
        $manifest = $this->manifestQueryByPublicId($id)->firstOrFail();
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
        $stop = $this->manifestStopQueryByPublicId($id)
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
        $stop = $this->manifestStopQueryByPublicId($id)->firstOrFail();

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

    /**
     * Publish one draft to its driver, now or at `publish_at`.
     *
     * POST /int/v1/fleet-ops/manifests/{id}/publish
     * Body: { publish_at?: ISO 8601 }
     * 200 { manifest }; 422 when the manifest is not a draft or the time is unreadable.
     */
    public function publish(Request $request, string $id): JsonResponse
    {
        $manifest = $this->manifestQueryByPublicId($id)->firstOrFail();

        $at = null;
        if ($request->filled('publish_at')) {
            $at = DispatchDate::moment($request->input('publish_at'));
            if (!$at) {
                return response()->json(['error' => 'publish_at must be a date and time.'], 422);
            }
        }

        $publisher = $this->manifestPublisher();
        $done      = $at ? $publisher->schedule($manifest, $at) : $publisher->publish($manifest);
        if (!$done) {
            return response()->json(['error' => sprintf('Only a draft can be published; this route is %s.', $manifest->status)], 422);
        }

        return response()->json(['manifest' => $manifest->fresh($this->manifestShowRelations())]);
    }

    /**
     * Publish several drafts: the ones named, or every draft of a day.
     *
     * POST /int/v1/fleet-ops/manifests/publish
     * Body: { ids?: string[], scheduled_date?: Y-m-d, publish_at?: ISO 8601 }
     * 200 { published: [ids], scheduled: [ids], skipped: [{ id, status }] }
     */
    public function publishMany(Request $request): JsonResponse
    {
        $ids  = array_values(array_filter((array) $request->input('ids', []), 'is_string'));
        $date = $request->filled('scheduled_date') ? DispatchDate::normalize($request->input('scheduled_date')) : null;

        if ($request->filled('scheduled_date') && !$date) {
            return response()->json(['error' => 'scheduled_date must be a date (Y-m-d).'], 422);
        }
        if (!$ids && !$date) {
            return response()->json(['error' => 'Send ids or a scheduled_date.'], 422);
        }

        $at = null;
        if ($request->filled('publish_at')) {
            $at = DispatchDate::moment($request->input('publish_at'));
            if (!$at) {
                return response()->json(['error' => 'publish_at must be a date and time.'], 422);
            }
        }

        $query = $this->scopedToCompany(Manifest::query());
        if ($ids) {
            $query->whereIn('public_id', $ids);
        } else {
            $query->whereDate('scheduled_date', $date)->where('status', Manifest::STATUS_DRAFT);
        }

        $publisher = $this->manifestPublisher();
        $result    = ['published' => [], 'scheduled' => [], 'skipped' => []];
        foreach ($query->get() as $manifest) {
            if ($at ? $publisher->schedule($manifest, $at) : $publisher->publish($manifest)) {
                $result[$manifest->status === Manifest::STATUS_PUBLISHED ? 'published' : 'scheduled'][] = $manifest->public_id;
            } else {
                $result['skipped'][] = ['id' => $manifest->public_id, 'status' => $manifest->status];
            }
        }

        return response()->json($result);
    }

    /**
     * Insert an order into a route as a stop, re-numbering the route.
     *
     * The order leaves whatever route it was on (that route closes up) and
     * takes the route's driver and vehicle; both drivers are told. Without a
     * position the order goes where it adds the least distance.
     *
     * POST /int/v1/fleet-ops/manifests/{id}/stops
     * Body: { order: public id or uuid, position?: 1-based int }
     * 200 { manifest, order, position }; 422 when the route is finished or the order cannot be routed.
     */
    public function insertStop(Request $request, string $id): JsonResponse
    {
        $manifest = $this->manifestQueryByPublicId($id)->with(['driver', 'stops.place'])->firstOrFail();
        if (!$manifest->isOpen()) {
            return response()->json(['error' => sprintf('Stops cannot be added to a %s route.', $manifest->status)], 422);
        }

        $order = $this->findOrder((string) $request->input('order', ''));
        if (!$order) {
            return response()->json(['error' => 'No order found to insert.'], 422);
        }
        if (in_array($order->status, ['completed', 'canceled'], true)) {
            return response()->json(['error' => sprintf('A %s order cannot be added to a route.', $order->status)], 422);
        }

        $position = $request->input('position');
        if ($position !== null && (filter_var($position, FILTER_VALIDATE_INT) === false || (int) $position < 1)) {
            return response()->json(['error' => 'position must be a whole number from 1.'], 422);
        }

        if ($position === null) {
            $estimator = $this->insertionEstimator();
            $point     = $estimator->orderPoint($order);
            $position  = $point ? $estimator->bestPosition($manifest, $point)['position'] : null;
        }

        $this->routeReassignment()->reassign($order, null, ['manifest' => $manifest, 'position' => $position === null ? null : (int) $position]);

        $manifest = $manifest->fresh($this->manifestShowRelations());
        $stop     = $manifest->stops->first(fn (ManifestStop $stop) => $stop->order_uuid === $order->uuid && !$stop->isDone());

        return response()->json([
            'manifest' => $manifest,
            'order'    => $order->public_id,
            'position' => $stop?->sequence,
        ]);
    }

    /**
     * Where an order would fit best on each driver's open route for a day.
     *
     * GET /int/v1/fleet-ops/manifests/best-fit?order={id}&scheduled_date=Y-m-d
     * The date defaults to the order's scheduled day, then today.
     * 200 { order, scheduled_date, candidates: [{ manifest_id, driver_id, driver_name, vehicle_id, status,
     *       position, extra_distance_m, extra_duration_s, extra_km, extra_minutes, stop_count }] }
     */
    public function bestFit(Request $request): JsonResponse
    {
        $order = $this->findOrder((string) $request->input('order', ''));
        if (!$order) {
            return response()->json(['error' => 'No order found.'], 422);
        }

        if ($request->filled('scheduled_date')) {
            $date = DispatchDate::normalize($request->input('scheduled_date'));
            if (!$date) {
                return response()->json(['error' => 'scheduled_date must be a date (Y-m-d).'], 422);
            }
        } else {
            $date = $order->scheduled_at ? $order->scheduled_at->toDateString() : now()->toDateString();
        }

        $estimator = $this->insertionEstimator();
        if (!$estimator->orderPoint($order)) {
            return response()->json(['error' => 'The order has no drop-off coordinates to measure from.'], 422);
        }

        $manifests = $this->scopedToCompany(Manifest::query())
            ->open()
            ->whereNotNull('driver_uuid')
            ->whereDate('scheduled_date', $date)
            ->with(['driver', 'vehicle', 'stops.place'])
            ->get();

        return response()->json([
            'order'          => $order->public_id,
            'scheduled_date' => $date,
            'candidates'     => $estimator->bestFit($order, $manifests),
        ]);
    }

    protected function findOrder(string $id): ?Order
    {
        if ($id === '') {
            return null;
        }

        /** @var Order|null $order */
        $order = $this->scopedToCompany(Order::query())
            ->where(fn (Builder $query) => $query->where('public_id', $id)->orWhere('uuid', $id))
            ->first();

        return $order;
    }

    protected function manifestPublisher(): ManifestPublisher
    {
        return app(ManifestPublisher::class);
    }

    protected function routeReassignment(): RouteReassignment
    {
        return app(RouteReassignment::class);
    }

    protected function insertionEstimator(): InsertionEstimator
    {
        return app(InsertionEstimator::class);
    }

    protected function manifestQueryForCompany(?string $companyUuid)
    {
        return Manifest::forCompany($companyUuid)
            ->with(['driver', 'vehicle', 'stops.place', 'stops.order.trackingNumber']);
    }

    protected function manifestQueryByPublicId(string $id)
    {
        return $this->scopedToCompany(Manifest::where('public_id', $id));
    }

    /**
     * A stop carries no company of its own, so it belongs to the caller only
     * through its manifest.
     */
    protected function manifestStopQueryByPublicId(string $id)
    {
        return ManifestStop::where('public_id', $id)
            ->whereHas('manifest', fn (Builder $query) => $this->scopedToCompany($query));
    }

    /**
     * Constrain a query to the caller's company.
     *
     * The record arrives as a route identifier, and nothing upstream narrows
     * which tenant it may resolve to: there is no global company scope, and
     * `fleetbase.protected` only checks that the caller holds the named
     * capability. A missing company session fails closed instead of letting
     * the query run across every tenant.
     */
    protected function scopedToCompany(Builder $query): Builder
    {
        $companyUuid = session('company');
        if (!$companyUuid) {
            $query->whereRaw('1 = 0');

            return $query;
        }

        return $query->where($query->getModel()->qualifyColumn('company_uuid'), $companyUuid);
    }

    protected function manifestShowRelations(): array
    {
        return ['driver', 'vehicle', 'stops' => fn ($q) => $q->orderBy('sequence')->with(['place', 'order.trackingNumber', 'order.payload.dropoff'])];
    }
}
