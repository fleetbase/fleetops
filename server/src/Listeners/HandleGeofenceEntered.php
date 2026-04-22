<?php

namespace Fleetbase\FleetOps\Listeners;

use Fleetbase\FleetOps\Events\GeofenceEntered;
use Fleetbase\FleetOps\Models\GeofenceEventLog;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Notifications\DriverArrivedAtGeofence;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * HandleGeofenceEntered.
 *
 * Handles the business logic triggered when a driver enters a geofence:
 *   1. Writes a record to the geofence_events_log table.
 *   2. Checks if the entered geofence corresponds to the driver's current
 *      order destination and auto-transitions the order to "arrived" status.
 *   3. Sends a customer notification if the order destination matches.
 */
class HandleGeofenceEntered implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * Handle the event.
     */
    public function handle(GeofenceEntered $event): void
    {
        $driver   = $event->driver;
        $vehicle  = $event->vehicle;
        $geofence = $event->geofence;

        // Set company session context for any subsequent queries
        session(['company' => $event->getCompanyUuid()]);

        // ----------------------------------------------------------------
        // 1. Write to the geofence event log
        // ----------------------------------------------------------------
        $order   = $driver?->getCurrentOrder();
        $subject = $event->subjectType === 'vehicle' ? $vehicle : $driver;

        GeofenceEventLog::create([
            'uuid'          => Str::uuid()->toString(),
            'company_uuid'  => $event->getCompanyUuid(),
            'driver_uuid'   => $driver?->uuid,
            'vehicle_uuid'  => $vehicle?->uuid,
            'order_uuid'    => $order?->uuid,
            'subject_uuid'  => $subject?->uuid,
            'subject_type'  => $event->subjectType,
            'subject_name'  => $event->subjectType === 'vehicle' ? ($vehicle?->display_name ?? $vehicle?->plate_number) : $driver?->name,
            'geofence_uuid' => $geofence->uuid,
            'geofence_type' => $event->geofenceType,
            'geofence_name' => $geofence->name,
            'event_type'    => 'entered',
            'latitude'      => $event->location->getLat(),
            'longitude'     => $event->location->getLng(),
            'occurred_at'   => $event->timestamp,
        ]);

        // ----------------------------------------------------------------
        // 2. Order status automation
        //
        //    If the driver has an active order and the geofence is in
        //    proximity to the current destination waypoint, auto-transition
        //    the order to "arrived" status and notify the customer.
        // ----------------------------------------------------------------
        if ($driver && $order) {
            $this->handleOrderArrival($driver, $geofence, $order, $event);
        }
    }

    /**
     * Attempt to auto-transition an order to "arrived" status when the
     * driver enters a geofence near the order's current destination.
     */
    private function handleOrderArrival($driver, $geofence, Order $order, GeofenceEntered $event): void
    {
        // Do not re-trigger if the order is already arrived or completed
        if (in_array($order->status, ['arrived', 'completed', 'canceled'])) {
            return;
        }

        // Get the current destination waypoint
        $destination = null;
        try {
            $destination = $order->payload?->getPickupOrCurrentWaypoint();
        } catch (\Throwable $e) {
            Log::warning('GeofenceEntered: Could not resolve order destination', [
                'order_uuid'  => $order->uuid,
                'driver_uuid' => $driver->uuid,
                'error'       => $e->getMessage(),
            ]);

            return;
        }

        if (!$destination) {
            return;
        }

        // Resolve the destination place
        $place = null;
        try {
            $place = $destination->place ?? $destination->getPlace();
        } catch (\Throwable $e) {
            return;
        }

        if (!$place || !$place->location) {
            return;
        }

        // Check proximity: is the geofence centroid within 500m of the destination place?
        try {
            $geofenceLat = $geofence->getLatitudeAttribute();
            $geofenceLng = $geofence->getLongitudeAttribute();
        } catch (\Throwable $e) {
            return;
        }

        $placeLat = $place->location->getLat();
        $placeLng = $place->location->getLng();

        $distanceMeters = $this->haversineDistance($geofenceLat, $geofenceLng, $placeLat, $placeLng);

        // Only trigger if the geofence is within 500m of the destination
        if ($distanceMeters > 500) {
            return;
        }

        // Auto-transition order to "arrived"
        try {
            $order->setStatus('arrived');
            $order->createActivity(
                [
                    'status'  => 'arrived',
                    'details' => sprintf('Driver entered destination geofence "%s".', $geofence->name),
                ],
                $event->location
            );
        } catch (\Throwable $e) {
            Log::error('GeofenceEntered: Failed to set order status to arrived', [
                'order_uuid' => $order->uuid,
                'error'      => $e->getMessage(),
            ]);

            return;
        }

        // Notify the customer
        if ($order->customer) {
            try {
                $order->customer->notify(new DriverArrivedAtGeofence($order, $geofence));
            } catch (\Throwable $e) {
                // Notification failure must not interrupt the geofence pipeline
                Log::warning('GeofenceEntered: Failed to notify customer', [
                    'order_uuid' => $order->uuid,
                    'error'      => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Calculate the haversine distance in metres between two lat/lng points.
     *
     * @return float Distance in metres
     */
    private function haversineDistance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371000; // metres
        $dLat        = deg2rad($lat2 - $lat1);
        $dLng        = deg2rad($lng2 - $lng1);
        $a           = sin($dLat / 2) ** 2
                     + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
