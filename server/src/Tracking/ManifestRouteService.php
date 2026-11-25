<?php

namespace Fleetbase\FleetOps\Tracking;

use Fleetbase\FleetOps\Models\Manifest;
use Fleetbase\FleetOps\Models\ManifestStop;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Support\Encoding\Polyline;
use Fleetbase\FleetOps\Support\Encoding\Polyline6;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * The road route through a manifest's stops, in driving sequence.
 *
 * An order's tracker already knows how to ask the organisation's routing engine
 * for a route through a list of points: which engine, whether it is allowed, what
 * to fall back to, and how long an answer may be reused. A manifest needs exactly
 * the same answer for a different list of points, so this hands the tracker's
 * provider chain a list built from manifest stops instead of writing a second
 * routing client that would drift from the first.
 *
 * The answer is geometry for a map, not an ETA: it carries no lifecycle, no
 * lateness and no per-stop arrival times. Those stay with the order tracker.
 */
class ManifestRouteService
{
    /** A stop in one of these states has been dealt with and is not driven to again. */
    protected const DONE_STATUSES = ['completed', 'skipped'];

    /**
     * How long an answer without road geometry is kept. Short, so a routing engine
     * that was down for a moment is asked again soon instead of leaving a driver
     * with straight lines for ten minutes.
     */
    protected const NO_GEOMETRY_TTL = 60;

    public function __construct(protected TrackingProviderManager $providerManager)
    {
    }

    /**
     * @param bool $remaining route only through the stops still to do, starting
     *                        from the driver's last known position when there is one
     */
    public function route(Manifest $manifest, bool $remaining = false): array
    {
        $manifest->loadMissing(['driver', 'stops.place']);

        $options  = TrackingOptions::fromArray([], $manifest->company_uuid);
        $warnings = [];
        $nodes    = $this->nodes($manifest, $remaining, $options, $warnings);
        $cacheKey = $this->cacheKey($manifest, $nodes, $remaining, $options);

        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $result = $this->build($manifest, $nodes, $remaining, $options, $warnings);
        Cache::put($cacheKey, $result, $result['polyline'] ? max($options->routeCacheTtlSeconds, 60) : static::NO_GEOMETRY_TTL);

        return $result;
    }

    /**
     * The answer given when nothing could be routed. Shaped like a real one so a
     * client has a single response to read.
     */
    public static function unavailable(array $warnings, bool $remaining = false): array
    {
        return [
            'polyline'     => null,
            'distance_m'   => null,
            'duration_s'   => null,
            'provider'     => 'none',
            'remaining'    => $remaining,
            'leg_geometry' => null,
            'legs'         => [],
            'generated_at' => now()->toISOString(),
            'warnings'     => array_values(array_unique($warnings)),
        ];
    }

    protected function build(Manifest $manifest, array $nodes, bool $remaining, TrackingOptions $options, array $warnings): array
    {
        if (count($nodes) < 2) {
            return static::unavailable([...$warnings, 'not_enough_points'], $remaining);
        }

        $providerResult = $this->providerManager->track($this->context($manifest, $nodes), $options);
        $warnings       = [...$warnings, ...$providerResult->warnings];

        $polyline = filled($providerResult->polyline) ? $providerResult->polyline : null;
        if (!$polyline) {
            // The straight-line estimate still knows roughly how far and how long,
            // but it has no road to draw.
            $warnings[] = 'no_route_geometry';
        }

        [$legPolylines, $legGeometry] = $this->legPolylines($providerResult, $nodes, $polyline);

        $legs = [];
        for ($i = 0; $i < count($nodes) - 1; $i++) {
            $leg    = $providerResult->legs[$i] ?? [];
            $legs[] = [
                // Null when the leg starts at the driver's position rather than at a stop
                'from_stop'  => $nodes[$i]['stop'],
                'to_stop'    => $nodes[$i + 1]['stop'],
                'distance_m' => data_get($leg, 'distance_m'),
                'duration_s' => data_get($leg, 'duration_in_traffic_s') ?? data_get($leg, 'duration_s'),
                'polyline'   => $legPolylines[$i] ?? null,
            ];
        }

        return [
            'polyline'     => $polyline,
            'distance_m'   => $providerResult->distanceMeters,
            'duration_s'   => $providerResult->durationInTrafficSeconds ?? $providerResult->durationSeconds,
            'provider'     => $providerResult->provider,
            'remaining'    => $remaining,
            // `provider`: each leg's line is the engine's own. `derived`: the single
            // line was cut at the stops. Null: there are no per-leg lines.
            'leg_geometry' => $legGeometry,
            'legs'         => $legs,
            'generated_at' => now()->toISOString(),
            'warnings'     => array_values(array_unique($warnings)),
        ];
    }

    /**
     * The points to route through, in order: optionally the driver, then each stop
     * that has somewhere to go.
     *
     * @return array<int, array{stop: ?string, uuid: ?string, status: ?string, sequence: ?int, point: Point, place: mixed}>
     */
    protected function nodes(Manifest $manifest, bool $remaining, TrackingOptions $options, array &$warnings): array
    {
        $nodes = [];
        $stops = $manifest->stops->sortBy('sequence')->values();

        if ($remaining) {
            $stops  = $stops->reject(fn (ManifestStop $stop) => in_array($stop->status, static::DONE_STATUSES, true))->values();
            $driver = $manifest->driver;
            $point  = $driver && $driver->location ? $this->pointOf($driver) : null;

            if ($point) {
                $age = $driver->updated_at ? (int) now()->diffInSeconds($driver->updated_at, true) : null;
                if ($age !== null && $age > $options->staleLocationThresholdSeconds) {
                    $warnings[] = 'stale_driver_location';
                }
                $nodes[] = ['stop' => null, 'uuid' => null, 'status' => null, 'sequence' => null, 'point' => $point, 'place' => null];
            } else {
                // Without a position the route starts at the first stop still to do
                $warnings[] = 'missing_driver_location';
            }
        }

        foreach ($stops as $stop) {
            $point = $this->pointOf($stop->place);
            if (!$point) {
                // A stop with nowhere to go is left out rather than sinking the whole route
                $warnings[] = 'missing_stop_location';
                continue;
            }

            $nodes[] = [
                'stop'     => $stop->public_id,
                'uuid'     => $stop->uuid,
                'status'   => $stop->status,
                'sequence' => $stop->sequence,
                'point'    => $point,
                'place'    => $stop->place,
            ];
        }

        return $nodes;
    }

    /**
     * The tracker's providers read three things from their context: the points to
     * route through, whether there are at least two, and the organisation they are
     * routing for. This gives them those from a manifest. The order is a stand-in
     * that only carries the organisation (and a name for the log line when an
     * engine fails); it is never saved.
     */
    protected function context(Manifest $manifest, array $nodes): TrackingContext
    {
        $order = new Order();
        $order->setAttribute('company_uuid', $manifest->company_uuid);
        $order->setAttribute('public_id', $manifest->public_id);

        $first = array_shift($nodes);
        $rest  = collect($nodes)->map(fn (array $node, int $index) => new TrackingStop(
            uuid: $node['uuid'],
            publicId: $node['stop'],
            type: 'waypoint',
            status: $node['status'],
            place: $node['place'],
            sequence: $index + 1,
        ))->values();

        return new TrackingContext(
            order: $order,
            payload: null,
            driver: $manifest->driver,
            origin: $first['point'],
            driverLocation: $first['stop'] === null ? $first['point'] : null,
            stops: $rest,
            completedStops: collect(),
            remainingStops: $rest,
            activeStop: $rest->first(),
            nextStop: $rest->get(1),
            driverLocationAgeSeconds: null,
        );
    }

    /**
     * One encoded line (precision 5) per leg, and where they came from.
     *
     * Valhalla returns a shape per leg, so those are used as they are. OSRM and
     * Google are asked for one overview line (asking them for per-leg geometry
     * would mean changing the request the order tracker shares), so that line is
     * cut at the vertex where it passes each stop.
     *
     * @return array{0: array<int, ?string>, 1: ?string}
     */
    protected function legPolylines(TrackingProviderResult $result, array $nodes, ?string $polyline): array
    {
        $legCount = count($nodes) - 1;
        if (!$polyline || $legCount < 1) {
            return [[], null];
        }

        if ($legCount === 1) {
            return [[$polyline], 'provider'];
        }

        try {
            $shapes = collect(data_get($result->raw, 'legs', []))->map(fn ($leg) => data_get($leg, 'shape'))->filter()->values();
            if ($shapes->count() === $legCount) {
                return [
                    $shapes->map(fn ($shape) => $this->encode($this->decode((string) $shape, Polyline6::class)))->all(),
                    'provider',
                ];
            }

            $cut = $this->cutAtStops($this->decode($polyline, Polyline::class), $nodes, $result->legs);

            return $cut ? [$cut, 'derived'] : [[], null];
        } catch (\Throwable $e) {
            // Per-leg lines are a nicety; the single line is still a full answer
            Log::warning('Manifest route legs could not be derived.', ['error' => $e->getMessage()]);

            return [[], null];
        }
    }

    /**
     * Cut one line through every stop into a line per leg.
     *
     * The cut for a stop is the vertex nearest to it, looked for only around the
     * distance along the line where the engine says that leg ends. Looking along
     * the whole line would pick the wrong pass when a route goes by a stop early
     * and returns to it later, or drives out and back along the same street.
     *
     * @param array<int, array{0: float, 1: float}> $vertices [lat, lng]
     *
     * @return array<int, string>|null
     */
    protected function cutAtStops(array $vertices, array $nodes, array $providerLegs): ?array
    {
        $count    = count($vertices);
        $legCount = count($nodes) - 1;
        if ($count < 2) {
            return null;
        }

        // Distance along the line at each vertex
        $along = [0.0];
        for ($i = 1; $i < $count; $i++) {
            $along[$i] = $along[$i - 1] + $this->metresBetween($vertices[$i - 1], $vertices[$i]);
        }
        $lineLength = $along[$count - 1];

        // Where each leg should end, as a share of the engine's own leg distances
        // scaled onto the line (the two never agree to the metre).
        $legDistances = array_map(fn ($leg) => (float) data_get($leg, 'distance_m', 0), $providerLegs);
        $legTotal     = array_sum($legDistances);
        $hasDistances = count($legDistances) === $legCount && $legTotal > 0 && $lineLength > 0;

        $cuts     = [0];
        $previous = 0;
        $covered  = 0.0;

        for ($leg = 0; $leg < $legCount - 1; $leg++) {
            $stop     = [(float) $nodes[$leg + 1]['point']->getLat(), (float) $nodes[$leg + 1]['point']->getLng()];
            $expected = null;
            $slack    = null;

            if ($hasDistances) {
                $covered += $legDistances[$leg];
                $expected = $lineLength * ($covered / $legTotal);
                // Room for the engine's distances and the line's to disagree
                $slack    = max(300.0, 0.25 * $lineLength * (($legDistances[$leg] + $legDistances[$leg + 1]) / $legTotal));
            }

            $best         = null;
            $bestDistance = null;
            for ($i = $previous; $i < $count; $i++) {
                if ($expected !== null) {
                    if ($along[$i] < $expected - $slack) {
                        continue;
                    }
                    if ($along[$i] > $expected + $slack) {
                        break;
                    }
                }

                $distance = $this->metresBetween($vertices[$i], $stop);
                if ($bestDistance === null || $distance < $bestDistance) {
                    $bestDistance = $distance;
                    $best         = $i;
                }
            }

            if ($best === null) {
                // Nothing in the window (a very sparse line): take the vertex at the expected distance
                $best = $previous;
                for ($i = $previous; $i < $count && $expected !== null && $along[$i] <= $expected; $i++) {
                    $best = $i;
                }
            }

            $cuts[]   = $best;
            $previous = $best;
        }
        $cuts[] = $count - 1;

        $lines = [];
        for ($leg = 0; $leg < $legCount; $leg++) {
            $slice = array_slice($vertices, $cuts[$leg], $cuts[$leg + 1] - $cuts[$leg] + 1);
            if (count($slice) < 2) {
                // Two stops at the same vertex: a zero-length leg still gets a drawable line
                $slice = [$vertices[$cuts[$leg]], $vertices[$cuts[$leg + 1]]];
            }
            $lines[] = $this->encode($slice);
        }

        return $lines;
    }

    /**
     * Keyed on everything that changes the line, so re-sequencing a manifest,
     * finishing a stop or moving a place asks again without anything having to
     * clear the cache.
     */
    protected function cacheKey(Manifest $manifest, array $nodes, bool $remaining, TrackingOptions $options): string
    {
        return 'manifest_route:' . md5(json_encode([
            'manifest'  => $manifest->uuid,
            'remaining' => $remaining,
            // Every stop's state, including the ones left out of the route
            'stops'     => $manifest->stops->sortBy('sequence')->map(fn (ManifestStop $stop) => [$stop->uuid, $stop->status, $stop->sequence])->values()->all(),
            // The driver's position is rounded to about a hundred metres so a parked
            // or crawling driver reuses the answer instead of asking for a new route
            // on every small GPS movement.
            'points'    => array_map(fn (array $node) => $node['stop'] === null
                ? [round((float) $node['point']->getLat(), 3), round((float) $node['point']->getLng(), 3)]
                : [(float) $node['point']->getLat(), (float) $node['point']->getLng()], $nodes),
            'provider'  => $options->provider,
            'traffic'   => $options->trafficEnabled,
        ]));
    }

    protected function pointOf($subject): ?Point
    {
        try {
            $point = $subject ? Utils::getPointFromMixed($subject) : null;
        } catch (\Throwable) {
            return null;
        }

        if (!$point) {
            return null;
        }

        $lat = (float) $point->getLat();
        $lng = (float) $point->getLng();

        // 0,0 is what a place or driver with no real position is stored as
        $valid = is_finite($lat) && is_finite($lng) && abs($lat) <= 90 && abs($lng) <= 180 && !(abs($lat) < 0.000001 && abs($lng) < 0.000001);

        return $valid ? $point : null;
    }

    /**
     * @param class-string<Polyline> $decoder
     *
     * @return array<int, array{0: float, 1: float}> [lat, lng]
     */
    protected function decode(string $encoded, string $decoder): array
    {
        // The decoder hands back points with latitude and longitude swapped (the
        // Valhalla tracking provider reads them the same way), so they are swapped
        // back here once.
        return array_map(fn ($point) => [(float) $point->getLng(), (float) $point->getLat()], $decoder::decode($encoded));
    }

    /** @param array<int, array{0: float, 1: float}> $vertices [lat, lng] */
    protected function encode(array $vertices): string
    {
        return Polyline::encode($vertices);
    }

    protected function metresBetween(array $from, array $to): float
    {
        $lat1 = deg2rad($from[0]);
        $lat2 = deg2rad($to[0]);
        $a    = sin(($lat2 - $lat1) / 2) ** 2 + cos($lat1) * cos($lat2) * sin(deg2rad($to[1] - $from[1]) / 2) ** 2;

        return 6371000.0 * 2 * atan2(sqrt($a), sqrt(max(0.0, 1 - $a)));
    }
}
