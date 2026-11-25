<?php

namespace Fleetbase\FleetOps\Support;

use Fleetbase\FleetOps\Models\ManifestStop;
use Fleetbase\FleetOps\Models\Waypoint;

/**
 * What a manifest stop is to the order it belongs to.
 *
 * A manifest lists places to visit in driving order, and a driver's route mixes
 * the waypoints of several orders. Each stop therefore says what it is within its
 * own order (the pickup, the drop-off, the return, or one of the waypoints in
 * between), where it falls among that order's points, and the time window and
 * handover requirements that apply at it.
 *
 * An order's payload takes four shapes: pickup + drop-off; pickup + waypoints +
 * drop-off; pickup + waypoints; waypoints only. The position counts whichever of
 * those points the order has, in the order they are visited.
 */
class ManifestStopRole
{
    public const PICKUP   = 'pickup';
    public const WAYPOINT = 'waypoint';
    public const DROPOFF  = 'dropoff';
    public const RETURN   = 'return';

    /** Waypoint rows per payload, kept for the length of the request: a route asks once per stop. */
    protected static array $waypointsByPayload = [];

    public static function describe(ManifestStop $stop): array
    {
        $order    = $stop->order;
        $payload  = $order?->payload;
        $place    = $stop->place_uuid;
        $waypoint = null;
        $role     = null;

        $waypoints = $payload ? static::waypointsOf($payload->uuid) : collect();

        if ($stop->waypoint_uuid) {
            $waypoint = $waypoints->firstWhere('uuid', $stop->waypoint_uuid);
        }
        if (!$waypoint && $place) {
            $waypoint = $waypoints->firstWhere('place_uuid', $place);
        }

        if ($payload && $place) {
            if ($payload->pickup_uuid && $payload->pickup_uuid === $place) {
                $role = static::PICKUP;
            } elseif ($payload->dropoff_uuid && $payload->dropoff_uuid === $place) {
                $role = static::DROPOFF;
            } elseif ($payload->return_uuid && $payload->return_uuid === $place) {
                $role = static::RETURN;
            }
        }
        if (!$role && $waypoint) {
            $role = static::WAYPOINT;
        }
        // A stop with no place of its own stands for the order's drop-off.
        if (!$role && !$place && $payload?->dropoff_uuid) {
            $role = static::DROPOFF;
        }

        // The order's points in visiting order, to place this one among them.
        $points = [];
        if ($payload?->pickup_uuid) {
            $points[] = static::PICKUP;
        }
        foreach ($waypoints as $row) {
            $points[] = 'waypoint:' . $row->uuid;
        }
        if ($payload?->dropoff_uuid) {
            $points[] = static::DROPOFF;
        }
        $key      = $role === static::WAYPOINT && $waypoint ? 'waypoint:' . $waypoint->uuid : $role;
        $position = $key ? array_search($key, $points, true) : false;

        $waypointIndex = $waypoint ? $waypoints->search(fn ($row) => $row->uuid === $waypoint->uuid) : false;

        // The window that applies here: the waypoint's own, else the order's for its drop-off.
        $windowStart = $waypoint?->time_window_start;
        $windowEnd   = $waypoint?->time_window_end;
        if (!$windowStart && !$windowEnd && $role === static::DROPOFF) {
            $windowStart = $order?->time_window_start;
            $windowEnd   = $order?->time_window_end;
        }

        return [
            'type'              => $role,
            'position'          => $position === false ? null : $position + 1,
            'position_total'    => count($points) ?: null,
            'waypoint_id'       => $role === static::WAYPOINT ? $waypoint?->public_id : null,
            'waypoint_index'    => $role === static::WAYPOINT && $waypointIndex !== false ? $waypointIndex + 1 : null,
            'waypoint_total'    => $waypoints->count() ?: null,
            'time_window_start' => $windowStart,
            'time_window_end'   => $windowEnd,
            'service_time'      => $waypoint?->service_time,
            'notes'             => $role === static::WAYPOINT ? $waypoint?->notes : null,
            'pod_required'      => $role === static::WAYPOINT && $waypoint ? (bool) $waypoint->pod_required : null,
            'pod_method'        => $role === static::WAYPOINT ? $waypoint?->pod_method : null,
        ];
    }

    protected static function waypointsOf(string $payloadUuid)
    {
        return static::$waypointsByPayload[$payloadUuid] ??= Waypoint::where('payload_uuid', $payloadUuid)->orderBy('order')->orderBy('created_at')->get();
    }

    /** For long-lived workers and tests: forget what a previous request loaded. */
    public static function flush(): void
    {
        static::$waypointsByPayload = [];
    }
}
