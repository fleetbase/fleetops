<?php

namespace Fleetbase\FleetOps\Support\Analytics;

use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\FleetOps\Support\Metrics\OrdersInProgressMetric;

/**
 * Real-time fleet snapshot used as the initial-state payload for Live Fleet Map.
 * After this single GET the frontend subscribes to `company.{uuid}` and applies
 * `driver.location_changed` / `order.*` events incrementally instead of polling.
 *
 * Reads from `Driver.location` and `Vehicle.location` directly — does NOT scan the
 * high-frequency Position table.
 */
class LiveFleet extends AbstractAnalytics
{
    public function get(): array
    {
        $companyUuid = $this->company->uuid;

        // Driver.name is a virtual attribute backed by users.name — eager-load the
        // user so the accessor resolves cleanly without a per-record query.
        $drivers = Driver::with(['user:uuid,name,avatar_uuid'])
            ->where('drivers.company_uuid', $companyUuid)
            ->whereNotNull('drivers.location')
            ->where(function ($q) {
                $q->where('drivers.online', true)->orWhereNotNull('drivers.current_job_uuid');
            })
            ->get();

        // Vehicles tracked independently of drivers (telematics-connected rigs may
        // report position without an active driver session).
        $vehicles = Vehicle::where('company_uuid', $companyUuid)
            ->whereNotNull('location')
            ->get();

        $activeOrderUuids = $drivers->pluck('current_job_uuid')->filter()->unique()->values();

        $activeOrders = Order::whereIn('uuid', $activeOrderUuids)
            ->where('company_uuid', $companyUuid)
            ->whereIn('status', OrdersInProgressMetric::IN_PROGRESS_STATUSES)
            ->get(['uuid', 'driver_assigned_uuid', 'status', 'tracking_number_uuid']);

        return [
            'drivers' => $drivers->map(fn ($d) => $this->driverPayload($d))
                ->filter(fn ($d) => $d['lat'] !== null && $d['lng'] !== null)
                ->values()
                ->all(),
            'vehicles' => $vehicles->map(fn ($v) => $this->vehiclePayload($v))
                ->filter(fn ($v) => $v['lat'] !== null && $v['lng'] !== null)
                ->values()
                ->all(),
            'active_orders' => $activeOrders->map(fn ($o) => [
                'uuid'        => $o->uuid,
                'driver_uuid' => $o->driver_assigned_uuid,
                'status'      => $o->status,
            ])->all(),
        ];
    }

    private function driverPayload(Driver $d): array
    {
        [$lat, $lng] = $this->extractLatLng($d->location);

        return [
            'uuid'               => $d->uuid,
            'public_id'          => $d->public_id,
            'name'               => $d->name,
            // avatar_url falls back to vehicle avatar then a default;
            // vehicle_avatar is what the operational live-map renders.
            'avatar_url'         => $d->avatar_url,
            'vehicle_avatar'     => $d->vehicle_avatar,
            'online'             => (bool) $d->online,
            'heading'            => (float) ($d->heading ?? 0),
            'current_order_uuid' => $d->current_job_uuid,
            'lat'                => $lat,
            'lng'                => $lng,
            'updated_at'         => $d->last_location_update_at,
        ];
    }

    private function vehiclePayload(Vehicle $v): array
    {
        [$lat, $lng] = $this->extractLatLng($v->location);

        return [
            'uuid'         => $v->uuid,
            'public_id'    => $v->public_id,
            'name'         => $v->display_name,
            'plate_number' => $v->plate_number,
            'avatar_url'   => $v->avatar_url,
            'photo_url'    => $v->photo_url,
            'online'       => (bool) ($v->online ?? false),
            'driver_name'  => $v->driver_name,
            'heading'      => (float) ($v->heading ?? 0),
            'lat'          => $lat,
            'lng'          => $lng,
        ];
    }

    /**
     * Pull [lat, lng] out of whatever shape the spatial accessor handed us.
     * Supports Point objects, [lat, lng] arrays, and {lat, lng} hashes.
     *
     * @return array{0:float|null,1:float|null}
     */
    private function extractLatLng($location): array
    {
        if (is_object($location) && method_exists($location, 'getLat')) {
            return [$location->getLat(), $location->getLng()];
        }

        if (is_array($location)) {
            // Point::toArray usually returns ['lat' => ..., 'lng' => ...] but
            // GeoJSON-shape `coordinates` is [lng, lat]; handle both.
            return [
                $location['lat'] ?? $location[1] ?? null,
                $location['lng'] ?? $location[0] ?? null,
            ];
        }

        return [null, null];
    }
}
