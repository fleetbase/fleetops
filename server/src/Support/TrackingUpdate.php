<?php

namespace Fleetbase\FleetOps\Support;

use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\TrackingStatus;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\FleetOps\Tracking\TrackingStop;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The payload published to one customer's public tracking channel.
 *
 * It carries only what that customer may see: their own stops (status, ETA, timeline), whole-route
 * counts, the driver's first name, the vehicle's label and online flag, and the vehicle's rounded
 * position. It never carries another customer's stops, places, items or people, the driver's
 * photo or phone, or free-text activity details. The driver and position are included only while
 * the order is en route and this customer still has a stop to be served.
 */
final class TrackingUpdate
{
    public const EVENT_UPDATED = 'tracking.updated';

    public const EVENT_LOCATION = 'tracking.location';

    public const REASON_STATUS = 'status';

    public const REASON_STOP = 'stop';

    public const REASON_ASSIGNMENT = 'assignment';

    public const REASON_ETA = 'eta';

    public const REASON_LOCATION = 'location';

    /**
     * Decimal places kept on published coordinates: 4 places is roughly 11 m.
     */
    public const COORDINATE_PRECISION = 4;

    public const TERMINAL_STATUSES = ['completed', 'canceled'];

    /**
     * Most timeline entries published per tracking number.
     */
    public const TIMELINE_LIMIT = 20;

    /**
     * @var Collection<int, TrackingStop>
     */
    private Collection $stops;

    /**
     * @param iterable<TrackingStop>                  $stops     every stop of the order, in route order
     * @param array<int, array>                       $etas      ETA per stop sequence: seconds, at, distance_m
     * @param array<string, array<int, array>>        $timelines timeline entries per tracking number uuid
     */
    public function __construct(
        private Order $order,
        private TrackingScope $scope,
        iterable $stops = [],
        private array $etas = [],
        private array $timelines = [],
    ) {
        $this->stops = collect($stops)->filter(fn ($stop) => $stop instanceof TrackingStop)->values();
    }

    /**
     * Build the update for a scope from the order's stops and, optionally, a tracking intelligence result.
     *
     * @param iterable<TrackingStop> $stops
     * @param array                  $tracker a TrackingIntelligenceService::track() result; its route legs give the ETAs
     */
    public static function for(Order $order, TrackingScope $scope, iterable $stops, array $tracker = []): self
    {
        $update = new self($order, $scope, $stops, static::etasFromTracker($tracker));

        $update->timelines = static::loadTimelines($update->timelineTrackingNumbers(), $order->getConnectionName());

        return $update;
    }

    /**
     * The full update: order state, route counts, this customer's stops, driver and position.
     */
    public function toArray(string $reason = self::REASON_STATUS): array
    {
        return $this->envelope(self::EVENT_UPDATED, $reason, [
            'order'    => $this->orderState(),
            'route'    => $this->route(),
            'stops'    => $this->ownStops()->map(fn (TrackingStop $stop) => $this->stop($stop))->values()->all(),
            'driver'   => $this->driver(),
            'location' => $this->location(),
        ]);
    }

    /**
     * The position-only update, or null when this customer may not see the vehicle right now.
     */
    public function locationPayload(): ?array
    {
        $location = $this->location();
        if ($location === null) {
            return null;
        }

        return $this->envelope(self::EVENT_LOCATION, self::REASON_LOCATION, [
            'driver'   => $this->driver(),
            'location' => $location,
        ]);
    }

    /**
     * This customer's stops.
     *
     * @return Collection<int, TrackingStop>
     */
    public function ownStops(): Collection
    {
        return $this->stops->filter(fn (TrackingStop $stop) => $this->scope->ownsStop($this->order, $stop->waypoint))->values();
    }

    public function isTerminal(): bool
    {
        return in_array(strtolower((string) $this->order->status), self::TERMINAL_STATUSES, true);
    }

    /**
     * Whether the order is under way: started and not yet completed or canceled.
     */
    public function isEnRoute(): bool
    {
        if ($this->isTerminal()) {
            return false;
        }

        return (bool) $this->order->started || !empty($this->order->started_at) || strtolower((string) $this->order->status) === 'started';
    }

    public function customerHasIncompleteStop(): bool
    {
        return $this->ownStops()->contains(fn (TrackingStop $stop) => !$stop->completed);
    }

    /**
     * Whether the driver and the vehicle position may be shown to this customer now.
     */
    public function showsVehicle(): bool
    {
        return $this->isEnRoute() && $this->customerHasIncompleteStop();
    }

    /**
     * Whole-route counts: stops in total, stops done, and stops still ahead of this customer's next one.
     */
    public function route(): array
    {
        $next = $this->ownStops()->first(fn (TrackingStop $stop) => !$stop->completed);

        return [
            'total_stops'     => $this->stops->count(),
            'completed_stops' => $this->stops->filter(fn (TrackingStop $stop) => $stop->completed)->count(),
            'stops_before'    => $next ? $this->stops->filter(fn (TrackingStop $stop) => !$stop->completed && (int) $stop->sequence < (int) $next->sequence)->count() : 0,
        ];
    }

    /**
     * The driver as this customer may see them: first name, vehicle label, online flag.
     */
    public function driver(): ?array
    {
        $driver = $this->assignedDriver();
        if (!$driver || !$this->showsVehicle()) {
            return null;
        }

        return [
            'name'    => static::firstName($driver->name),
            'vehicle' => $this->vehicleLabel(),
            'online'  => (bool) $driver->online,
        ];
    }

    /**
     * The vehicle position rounded to ~10 m, its heading and when it was seen.
     */
    public function location(): ?array
    {
        if (!$this->showsVehicle()) {
            return null;
        }

        $source = $this->locationSource();
        if (!$source) {
            return null;
        }

        [$subject, $point] = $source;

        return [
            'latitude'  => round($point->getLat(), self::COORDINATE_PRECISION),
            'longitude' => round($point->getLng(), self::COORDINATE_PRECISION),
            'heading'   => is_numeric($subject->heading) ? (int) round((float) $subject->heading) : null,
            'seen_at'   => $subject->updated_at ? Carbon::parse($subject->updated_at)->toISOString() : null,
        ];
    }

    /**
     * Tracking numbers whose timeline this customer may read: their stops', plus the order's own for the order customer.
     *
     * @return array<int, string>
     */
    public function timelineTrackingNumbers(): array
    {
        $uuids = $this->ownStops()->map(fn (TrackingStop $stop) => $stop->trackingNumberUuid)->all();

        if ($this->scope->isOrderCustomer($this->order)) {
            $uuids[] = $this->order->tracking_number_uuid;
        }

        return array_values(array_unique(array_filter($uuids, fn ($uuid) => is_string($uuid) && $uuid !== '')));
    }

    /**
     * ETAs keyed by stop sequence from a tracking intelligence result's route legs.
     */
    public static function etasFromTracker(array $tracker): array
    {
        $etas = [];
        foreach ((array) data_get($tracker, 'route.legs', []) as $leg) {
            $sequence = data_get($leg, 'stop.sequence');
            if ($sequence === null) {
                continue;
            }

            $etas[(int) $sequence] = [
                'seconds'    => is_numeric(data_get($leg, 'eta_seconds')) ? (int) round((float) data_get($leg, 'eta_seconds')) : null,
                'at'         => data_get($leg, 'eta_at'),
                'distance_m' => is_numeric(data_get($leg, 'distance_m')) ? (int) round((float) data_get($leg, 'distance_m')) : null,
            ];
        }

        return $etas;
    }

    /**
     * Timeline entries per tracking number: status, code, complete and time only.
     *
     * @param array<int, string> $trackingNumberUuids
     *
     * @return array<string, array<int, array>>
     */
    public static function loadTimelines(array $trackingNumberUuids, ?string $connection = null): array
    {
        if ($trackingNumberUuids === []) {
            return [];
        }

        return TrackingStatus::on($connection)
            ->whereIn('tracking_number_uuid', $trackingNumberUuids)
            ->orderBy('created_at')
            ->get(['tracking_number_uuid', 'status', 'code', 'complete', 'created_at'])
            ->groupBy('tracking_number_uuid')
            ->map(fn (Collection $statuses) => $statuses->take(-self::TIMELINE_LIMIT)->map(fn (TrackingStatus $status) => static::timelineEntry($status))->values()->all())
            ->all();
    }

    /**
     * The first word of a name.
     */
    public static function firstName(?string $name): ?string
    {
        $name = trim((string) $name);

        return $name === '' ? null : Str::before($name, ' ');
    }

    private function envelope(string $event, string $reason, array $data): array
    {
        return [
            'event'   => $event,
            'reason'  => $reason,
            'sent_at' => Carbon::now()->toISOString(),
            'data'    => $data,
        ];
    }

    private function orderState(): array
    {
        $status = strtolower((string) $this->order->status);

        return [
            'status'    => $status !== '' ? $status : null,
            'en_route'  => $this->isEnRoute(),
            'completed' => $status === 'completed',
            'canceled'  => $status === 'canceled',
            'timeline'  => $this->scope->isOrderCustomer($this->order) ? $this->timeline($this->order->tracking_number_uuid) : [],
        ];
    }

    private function stop(TrackingStop $stop): array
    {
        $eta = $stop->completed ? null : ($this->etas[(int) $stop->sequence] ?? null);

        return [
            'id'       => $stop->waypoint?->public_id,
            'type'     => $stop->type,
            'sequence' => $stop->sequence,
            'status'   => $stop->status,
            'complete' => $stop->completed,
            'eta'      => $eta,
            'timeline' => $this->timeline($stop->trackingNumberUuid),
        ];
    }

    private function timeline(?string $trackingNumberUuid): array
    {
        return $trackingNumberUuid ? ($this->timelines[$trackingNumberUuid] ?? []) : [];
    }

    private static function timelineEntry(TrackingStatus $status): array
    {
        $createdAt = $status->getRawOriginal('created_at') ?? $status->created_at;

        return [
            'status'     => $status->status,
            'code'       => $status->code !== null ? strtolower((string) $status->code) : null,
            'complete'   => (bool) $status->complete,
            'created_at' => $createdAt ? Carbon::parse($createdAt)->toISOString() : null,
        ];
    }

    private function assignedDriver(): ?Driver
    {
        $driver = $this->order->driverAssigned;

        return $driver instanceof Driver ? $driver : null;
    }

    private function assignedVehicle(): ?Vehicle
    {
        $vehicle = $this->order->vehicleAssigned ?? $this->assignedDriver()?->vehicle;

        return $vehicle instanceof Vehicle ? $vehicle : null;
    }

    private function vehicleLabel(): ?string
    {
        $label = trim((string) $this->assignedVehicle()?->display_name);

        return $label === '' ? null : $label;
    }

    /**
     * The most recently updated of the driver and the vehicle that has a usable position.
     *
     * @return array{0: Driver|Vehicle, 1: \Fleetbase\LaravelMysqlSpatial\Types\Point}|null
     */
    private function locationSource(): ?array
    {
        $candidates = [];
        foreach ([$this->assignedDriver(), $this->assignedVehicle()] as $subject) {
            $point = $subject ? static::usablePoint($subject) : null;
            if ($point) {
                $candidates[] = [$subject, $point];
            }
        }

        usort($candidates, fn (array $a, array $b) => static::seenAt($b[0]) <=> static::seenAt($a[0]));

        return $candidates[0] ?? null;
    }

    private static function seenAt(Driver|Vehicle $subject): int
    {
        return $subject->updated_at ? Carbon::parse($subject->updated_at)->getTimestamp() : 0;
    }

    private static function usablePoint(Driver|Vehicle $subject)
    {
        try {
            $point = $subject->location ? Utils::getPointFromMixed($subject->location) : null;
        } catch (\Throwable) {
            return null;
        }

        if (!$point) {
            return null;
        }

        $lat = (float) $point->getLat();
        $lng = (float) $point->getLng();
        $valid = is_finite($lat) && is_finite($lng) && abs($lat) <= 90 && abs($lng) <= 180 && !(abs($lat) < 0.000001 && abs($lng) < 0.000001);

        return $valid ? $point : null;
    }
}
