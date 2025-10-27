<?php

namespace Fleetbase\FleetOps\Http\Controllers\Internal\v1;

use Fleetbase\FleetOps\Http\Controllers\FleetOpsController;
use Fleetbase\FleetOps\Jobs\ReplayPositions;
use Fleetbase\FleetOps\Models\Position;
use Fleetbase\FleetOps\Support\Utils;
use Illuminate\Http\Request;

class PositionController extends FleetOpsController
{
    /**
     * The resource to query.
     *
     * @var string
     */
    public $resource = 'position';

    /**
     * Replay positions on a custom channel.
     *
     * @return \Illuminate\Http\Response
     */
    public function replay(Request $request)
    {
        $positionIds = $request->input('position_ids', []);
        $channelId   = $request->input('channel_id');
        $speed       = max((float) $request->input('speed', 1), 0.1); // avoid division by zero
        $subjectUuid = $request->input('subject_uuid');

        if (!$channelId) {
            return response()->error('Channel ID is required');
        }

        if (empty($positionIds)) {
            return response()->error('Position IDs are required');
        }

        $positions = Position::whereIn('uuid', $positionIds)
            ->where('company_uuid', session('company'))
            ->orderBy('created_at')
            ->get();

        if ($positions->isEmpty()) {
            return response()->error('No positions found');
        }

        // Dispatch async job (will handle replay logic)
        ReplayPositions::dispatch($positions, $channelId, $speed, $subjectUuid);

        return response()->json([
            'status'          => 'ok',
            'message'         => 'Replay started',
            'channel_id'      => $channelId,
            'total_positions' => $positions->count(),
        ]);
    }

    /**
     * Get position statistics/metrics.
     *
     * @return \Illuminate\Http\Response
     */
    public function metrics(Request $request)
    {
        $positionIds = $request->input('position_ids', []);

        if (empty($positionIds)) {
            return response()->error('Position IDs are required');
        }

        $positions = Position::whereIn('uuid', $positionIds)
            ->where('company_uuid', session('company'))
            ->orderBy('created_at', 'asc')
            ->get();

        if ($positions->isEmpty()) {
            return response()->json([
                'metrics' => [],
            ]);
        }

        // Calculate metrics
        $metrics = $this->calculateMetrics($positions);

        return response()->json([
            'metrics' => $metrics,
        ]);
    }

    /**
     * Calculate metrics from positions.
     *
     * @param \Illuminate\Support\Collection $positions
     *
     * @return array
     */
    private function calculateMetrics($positions)
    {
        $totalDistance         = 0;
        $maxSpeed              = 0;
        $avgSpeed              = 0;
        $speedingEvents        = [];
        $dwellTimes            = [];
        $accelerationEvents    = [];
        $speedLimit            = 100; // km/h - configurable
        $dwellThreshold        = 300; // 5 minutes in seconds
        $accelerationThreshold = 2.5; // m/s²

        $previousPosition = null;
        $previousSpeed    = null;
        $dwellStart       = null;

        foreach ($positions as $index => $position) {
            $speed = $position->speed ?? 0;

            // Track max speed
            if ($speed > $maxSpeed) {
                $maxSpeed = $speed;
            }

            // Check for speeding (convert m/s to km/h)
            $speedKmh = $speed * 3.6;
            if ($speedKmh > $speedLimit) {
                $speedingEvents[] = [
                    'position_uuid' => $position->uuid,
                    'speed'         => round($speedKmh, 2),
                    'timestamp'     => $position->created_at->toDateTimeString(),
                ];
            }

            // Calculate distance and dwell time
            if ($previousPosition) {
                // Calculate distance using Haversine formula
                // $distance = $this->calculateDistance($previousPosition, $position);
                $distance = Utils::vincentyGreatCircleDistance($previousPosition->coordinates, $position->coordinates);
                // dd($previousPosition, $position, $distance);
                $totalDistance += $distance;

                // Check for dwell (low speed or no movement)
                if ($speed < 0.5) { // Less than 0.5 m/s
                    if ($dwellStart === null) {
                        $dwellStart = $previousPosition->created_at;
                    }
                } else {
                    if ($dwellStart !== null) {
                        $dwellDuration = $previousPosition->created_at->diffInSeconds($dwellStart);
                        if ($dwellDuration >= $dwellThreshold) {
                            $dwellTimes[] = [
                                'start'    => $dwellStart->toDateTimeString(),
                                'end'      => $previousPosition->created_at->toDateTimeString(),
                                'duration' => $dwellDuration,
                            ];
                        }
                        $dwellStart = null;
                    }
                }

                // Calculate acceleration
                if ($previousSpeed !== null) {
                    $timeDiff = $position->created_at->diffInSeconds($previousPosition->created_at);
                    if ($timeDiff > 0) {
                        $acceleration = abs($speed - $previousSpeed) / $timeDiff;
                        if ($acceleration > $accelerationThreshold) {
                            $accelerationEvents[] = [
                                'position_uuid' => $position->uuid,
                                'acceleration'  => round($acceleration, 2),
                                'type'          => $speed > $previousSpeed ? 'acceleration' : 'deceleration',
                                'timestamp'     => $position->created_at->toDateTimeString(),
                            ];
                        }
                    }
                }
            }

            $previousPosition = $position;
            $previousSpeed    = $speed;
        }

        // Calculate average speed
        $speeds   = $positions->pluck('speed')->filter()->toArray();
        $avgSpeed = count($speeds) > 0 ? array_sum($speeds) / count($speeds) : 0;

        // Calculate total duration
        $firstPosition = $positions->first();
        $lastPosition  = $positions->last();
        $totalDuration = $lastPosition->created_at->diffInSeconds($firstPosition->created_at);

        return [
            'total_distance'      => round($totalDistance / 1000, 2), // Convert to km
            'total_duration'      => $totalDuration, // seconds
            'max_speed'           => round($maxSpeed * 3.6, 2), // km/h
            'avg_speed'           => round($avgSpeed * 3.6, 2), // km/h
            'speeding_events'     => $speedingEvents,
            'speeding_count'      => count($speedingEvents),
            'dwell_times'         => $dwellTimes,
            'dwell_count'         => count($dwellTimes),
            'acceleration_events' => $accelerationEvents,
            'acceleration_count'  => count($accelerationEvents),
            'total_positions'     => $positions->count(),
        ];
    }

    /**
     * Calculate distance between two positions using Haversine formula.
     *
     * @param Position $pos1
     * @param Position $pos2
     *
     * @return float Distance in meters
     */
    private function calculateDistance($pos1, $pos2)
    {
        $coords1 = [$pos1->latitude, $pos1->longitude];
        $coords2 = [$pos2->latitude, $pos2->longitude];

        if (!isset($coords1[0]) || !isset($coords2[0]) || !isset($coords1[1]) || !isset($coords2[1])) {
            return 0;
        }

        $lat1 = deg2rad($coords1[0]);
        $lon1 = deg2rad($coords1[1]);
        $lat2 = deg2rad($coords2[0]);
        $lon2 = deg2rad($coords2[1]);

        $earthRadius = 6371000; // meters

        $dLat = $lat2 - $lat1;
        $dLon = $lon2 - $lon1;

        $a = sin($dLat / 2) * sin($dLat / 2) +
            cos($lat1) * cos($lat2) *
            sin($dLon / 2) * sin($dLon / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }
}
