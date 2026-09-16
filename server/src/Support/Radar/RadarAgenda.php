<?php

namespace Fleetbase\FleetOps\Support\Radar;

use Illuminate\Support\Carbon;

/**
 * The Agenda view: the same items laid out on a time scale. Four lanes
 * across the next 24 hours or 7 days, an Overdue band left of now that is
 * not part of the scale, a Later rail for dated work beyond the window, and
 * an Anytime tray for everything with no date. Shifts ending with orders
 * still assigned get a handover card with a suggested cover driver.
 *
 * Pure: items, shifts, drivers, orders and a clock in; a layout out.
 */
class RadarAgenda
{
    public const WINDOWS = ['24h' => 24, '7d' => 168];

    public const LANES = [RadarRules::LANE_SHIFTS, RadarRules::LANE_MAINTENANCE, RadarRules::LANE_EXPIRIES, RadarRules::LANE_NOTICES];

    /** Where an undated item goes once someone gives it a time. */
    public const LANE_BY_CATEGORY = [
        RadarRules::CATEGORY_STAFFING     => RadarRules::LANE_SHIFTS,
        RadarRules::CATEGORY_MAINTENANCE  => RadarRules::LANE_MAINTENANCE,
        RadarRules::CATEGORY_INSPECTIONS  => RadarRules::LANE_MAINTENANCE,
        RadarRules::CATEGORY_ISSUES       => RadarRules::LANE_MAINTENANCE,
        RadarRules::CATEGORY_PARTS        => RadarRules::LANE_MAINTENANCE,
        RadarRules::CATEGORY_CONNECTIVITY => RadarRules::LANE_MAINTENANCE,
        RadarRules::CATEGORY_COMPLIANCE   => RadarRules::LANE_EXPIRIES,
        RadarRules::CATEGORY_FUEL         => RadarRules::LANE_EXPIRIES,
        RadarRules::CATEGORY_NOTICES      => RadarRules::LANE_NOTICES,
    ];

    /** A cover driver must still be on shift this long after the handover. */
    public const COVER_MARGIN_HOURS = 2;

    /** Orders a driver can carry in a day when the driver record does not say. */
    public const DEFAULT_CAPACITY = 6;

    /**
     * @param array $items   RadarRules items with state merged
     * @param array $shifts  shift rows (see RadarController::loadShifts)
     * @param array $drivers driver rows (uuid, public_id, name, online, location, active_orders, max_daily_orders)
     * @param array $orders  active orders keyed by driver uuid: [{public_id, uuid, destination, ends_at}]
     */
    public static function build(array $items, array $shifts, string $window, Carbon $now, array $drivers = [], array $orders = []): array
    {
        $hours = self::WINDOWS[$window] ?? self::WINDOWS['24h'];
        $start = $now->copy()->startOfHour();
        $end   = $start->copy()->addHours($hours);
        $open  = array_values(array_filter($items, fn ($item) => RadarRules::isOpen($item, $now)));

        $lanes   = array_fill_keys(self::LANES, []);
        $overdue = [];
        $later   = [];
        $anytime = [];

        foreach ($open as $item) {
            $placed = self::place($item, $start, $end, $now);

            switch ($placed['zone']) {
                case 'overdue':
                    $overdue[] = $placed['entry'];
                    break;
                case 'lane':
                    $lanes[$placed['entry']['lane']][] = $placed['entry'];
                    break;
                case 'later':
                    $later[] = $placed['entry'];
                    break;
                default:
                    $anytime[] = $placed['entry'];
            }
        }

        // Every live shift is a bar on the shifts lane, gap or not, so the
        // lane reads as the day's roster.
        $byDriver = [];
        foreach ($open as $item) {
            if (($item['category'] ?? null) === RadarRules::CATEGORY_STAFFING && !empty($item['subject']['uuid'])) {
                $byDriver[$item['subject']['uuid']][] = $item;
            }
        }
        foreach ($shifts as $shift) {
            $bar = self::shiftBar($shift, $byDriver, $start, $end, $now);
            if ($bar) {
                $lanes[RadarRules::LANE_SHIFTS][] = $bar;
            }
        }

        usort($overdue, fn ($a, $b) => strcmp((string) $a['at'], (string) $b['at']));
        usort($later, fn ($a, $b) => strcmp((string) $a['at'], (string) $b['at']));
        foreach ($lanes as &$entries) {
            usort($entries, fn ($a, $b) => strcmp((string) $a['at'], (string) $b['at']));
        }
        unset($entries);

        return [
            'now'       => $now->toIso8601String(),
            'window'    => [
                'key'      => isset(self::WINDOWS[$window]) ? $window : '24h',
                'hours'    => $hours,
                'start_at' => $start->toIso8601String(),
                'end_at'   => $end->toIso8601String(),
                'ticks'    => self::ticks($start, $hours),
                'now_pct'  => self::pct($now, $start, $hours),
            ],
            'lanes'     => $lanes,
            'overdue'   => $overdue,
            'later'     => $later,
            'anytime'   => $anytime,
            'handovers' => self::handovers($open, $shifts, $drivers, $orders, $now),
            'counts'    => [
                'overdue' => count($overdue),
                'later'   => count($later),
                'anytime' => count($anytime),
                'shifts'  => count($lanes[RadarRules::LANE_SHIFTS]),
            ],
        ];
    }

    /**
     * Which zone an item lands in, and the entry the lane draws.
     *
     * @return array{zone: string, entry: array}
     */
    public static function place(array $item, Carbon $start, Carbon $end, Carbon $now): array
    {
        $planned = RadarRules::carbon($item['state']['planned_at'] ?? null);
        $due     = RadarRules::carbon($item['due_at'] ?? null);
        $at      = $planned ?? $due;

        // A shift gap with no due date is happening now: it sits on the
        // shifts lane from the moment the window opened.
        if ($at === null && !empty($item['window']['start_at'])) {
            $windowStart = RadarRules::carbon($item['window']['start_at']);
            $at          = $windowStart ? $windowStart->max($now) : null;
        }

        $entry = self::entry($item, $at, $planned !== null, $start, $end);

        if ($at === null) {
            return ['zone' => 'anytime', 'entry' => $entry];
        }
        if ($at->lt($now) && !$planned) {
            return ['zone' => 'overdue', 'entry' => $entry];
        }
        if ($at->gt($end)) {
            return ['zone' => 'later', 'entry' => $entry];
        }

        return ['zone' => 'lane', 'entry' => $entry];
    }

    /**
     * The handover cards: shifts ending soon with orders still assigned,
     * each with the best cover driver we can find.
     */
    public static function handovers(array $open, array $shifts, array $drivers, array $orders, Carbon $now, array $rules = ['shift_handover']): array
    {
        $cards = [];

        foreach ($open as $item) {
            if (!in_array($item['rule'] ?? null, $rules, true)) {
                continue;
            }

            $driverUuid   = $item['subject']['uuid'] ?? null;
            $end          = RadarRules::carbon($item['window']['end_at'] ?? null);
            $driverRows   = array_column($drivers, null, 'uuid');
            $driver       = $driverRows[$driverUuid] ?? null;
            $driverOrders = array_values($orders[$driverUuid] ?? []);
            $suggested    = $end ? self::suggestCover($driverUuid, $driver, $end, $shifts, $driverRows, $now) : null;

            $cards[] = [
                'key'           => $item['key'],
                'driver'        => $item['subject'],
                'rule'          => $item['rule'],
                'shift'         => ($item['window'] ?? []) + ['uuid' => $item['source']['uuid'] ?? null, 'public_id' => $item['source']['public_id'] ?? null, 'minutes_left' => $end ? max(0, $now->diffInMinutes($end, false)) : null],
                'orders'        => array_map(fn ($order) => $order + ['finishes_after_shift' => self::finishesAfter($order, $end)], $driverOrders),
                'active_orders' => (int) ($item['source']['active_orders'] ?? count($driverOrders)),
                'suggested'     => $suggested,
                'record'        => $item['record'] ?? null,
            ];
        }

        return $cards;
    }

    /**
     * The on-shift driver best placed to take the orders: still on shift
     * past the handover, online, nearest, with capacity to spare.
     */
    public static function suggestCover(?string $driverUuid, ?array $driver, Carbon $shiftEnd, array $shifts, array $driverRows, Carbon $now): ?array
    {
        $needUntil  = $shiftEnd->copy()->addHours(self::COVER_MARGIN_HOURS);
        $candidates = [];

        foreach ($shifts as $shift) {
            $uuid = $shift['driver']['uuid'] ?? null;
            if (!$uuid || $uuid === $driverUuid || !in_array($shift['status'] ?? 'scheduled', RadarRules::SHIFT_LIVE_STATUSES, true)) {
                continue;
            }

            $start = RadarRules::carbon($shift['start_at'] ?? null);
            $end   = RadarRules::carbon($shift['end_at'] ?? null);
            if (!$start || !$end || $start->gt($shiftEnd) || $end->lt($needUntil)) {
                continue;
            }

            $row      = $driverRows[$uuid] ?? [];
            $capacity = (int) ($row['max_daily_orders'] ?? self::DEFAULT_CAPACITY) ?: self::DEFAULT_CAPACITY;
            $active   = (int) ($row['active_orders'] ?? $shift['active_orders'] ?? 0);
            if ($active >= $capacity) {
                continue;
            }

            $distance = self::distanceKm($driver['location'] ?? null, $row['location'] ?? null);

            $candidates[] = [
                'driver'         => $shift['driver'],
                'on_shift_until' => $end->toIso8601String(),
                'online'         => (bool) ($shift['driver_online'] ?? $row['online'] ?? false),
                'distance_km'    => $distance,
                'active_orders'  => $active,
                'capacity'       => $capacity,
                'capacity_label' => sprintf('%d of %d orders', $active, $capacity),
            ];
        }

        if (!$candidates) {
            return null;
        }

        usort($candidates, function ($a, $b) {
            $online = ($b['online'] ? 1 : 0) <=> ($a['online'] ? 1 : 0);
            if ($online !== 0) {
                return $online;
            }
            $distance = ($a['distance_km'] ?? PHP_FLOAT_MAX) <=> ($b['distance_km'] ?? PHP_FLOAT_MAX);
            if ($distance !== 0) {
                return $distance;
            }

            return $a['active_orders'] <=> $b['active_orders'];
        });

        return $candidates[0];
    }

    /**
     * Great-circle distance in km between two {lat, lng} points, or null.
     */
    public static function distanceKm(?array $from, ?array $to): ?float
    {
        if (!isset($from['lat'], $from['lng'], $to['lat'], $to['lng'])) {
            return null;
        }

        $earth = 6371.0;
        $dLat  = deg2rad((float) $to['lat'] - (float) $from['lat']);
        $dLng  = deg2rad((float) $to['lng'] - (float) $from['lng']);
        $a     = sin($dLat / 2) ** 2 + cos(deg2rad((float) $from['lat'])) * cos(deg2rad((float) $to['lat'])) * sin($dLng / 2) ** 2;

        return round($earth * 2 * atan2(sqrt($a), sqrt(1 - $a)), 1);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    protected static function entry(array $item, ?Carbon $at, bool $planned, Carbon $start, Carbon $end): array
    {
        $lane  = $item['lane'] ?? RadarRules::LANE_ANYTIME;
        if ($lane === RadarRules::LANE_ANYTIME) {
            $lane = self::LANE_BY_CATEGORY[$item['category'] ?? ''] ?? RadarRules::LANE_MAINTENANCE;
        }
        $hours = max(1, $start->diffInHours($end));

        return [
            'kind'      => 'item',
            'key'       => $item['key'],
            'rule'      => $item['rule'],
            'chip'      => $item['chip'] ?? null,
            'category'  => $item['category'] ?? null,
            'severity'  => $item['severity'] ?? 'info',
            'title'     => $item['title'],
            'subject'   => $item['subject'] ?? null,
            'meta_line' => $item['meta_line'] ?? null,
            'lane'      => $lane,
            'at'        => $at?->toIso8601String(),
            'label'     => $at ? $at->format($start->diffInHours($end) > 24 ? 'D H:i' : 'H:i') : null,
            'planned'   => $planned,
            'pct'       => $at ? self::pct($at, $start, $hours) : null,
            'state'     => $item['state'] ?? null,
            'actions'   => $item['actions'] ?? [],
            'record'    => $item['record'] ?? null,
        ];
    }

    protected static function shiftBar(array $shift, array $byDriver, Carbon $start, Carbon $end, Carbon $now): ?array
    {
        $from   = RadarRules::carbon($shift['start_at'] ?? null);
        $to     = RadarRules::carbon($shift['end_at'] ?? null);
        $driver = $shift['driver'] ?? null;
        if (!$from || !$to || !$driver || $to->lt($start) || $from->gt($end)) {
            return null;
        }

        $hours    = max(1, $start->diffInHours($end));
        $gaps     = $byDriver[$driver['uuid'] ?? ''] ?? [];
        $handover = null;
        $worst    = null;
        foreach ($gaps as $gap) {
            if ($gap['rule'] === 'shift_handover') {
                $handover = $gap['key'];
            }
            if ($worst === null || self::severityRank($gap['severity']) > self::severityRank($worst)) {
                $worst = $gap['severity'];
            }
        }

        $status = $shift['status'] ?? 'scheduled';
        $state  = $to->lte($now) ? 'ended' : ($from->gt($now) ? 'upcoming' : (!empty($shift['driver_online']) || $status === 'in_progress' ? 'on_shift' : 'not_online'));

        return [
            'kind'          => 'shift',
            'key'           => 'shift:' . ($shift['public_id'] ?? $shift['uuid'] ?? ($driver['public_id'] ?? '')),
            'driver'        => $driver,
            'status'        => $status,
            'state'         => $state,
            'at'            => $from->toIso8601String(),
            'end_at'        => $to->toIso8601String(),
            'label'         => $from->format('H:i') . '–' . $to->format('H:i'),
            'pct'           => self::pct($from->max($start), $start, $hours),
            'width_pct'     => max(1.0, round(($from->max($start)->diffInMinutes($to->min($end)) / 60) / $hours * 100, 2)),
            'lane'          => RadarRules::LANE_SHIFTS,
            'severity'      => $worst,
            'gap_keys'      => array_column($gaps, 'key'),
            'handover_key'  => $handover,
            'active_orders' => (int) ($shift['active_orders'] ?? 0),
            'vehicle_uuid'  => $shift['driver_vehicle_uuid'] ?? null,
        ];
    }

    protected static function finishesAfter(array $order, ?Carbon $shiftEnd): bool
    {
        $ends = RadarRules::carbon($order['ends_at'] ?? null);

        return (bool) ($shiftEnd && $ends && $ends->gt($shiftEnd));
    }

    protected static function ticks(Carbon $start, int $hours): array
    {
        $ticks = [];
        $step  = $hours > 24 ? 24 : 2;
        for ($offset = 0; $offset <= $hours; $offset += $step) {
            $at      = $start->copy()->addHours($offset);
            $ticks[] = ['at' => $at->toIso8601String(), 'label' => $hours > 24 ? $at->format('D j') : $at->format('H'), 'pct' => round($offset / $hours * 100, 2)];
        }

        return $ticks;
    }

    protected static function pct(Carbon $at, Carbon $start, int $hours): float
    {
        return round(max(0, min(100, $start->diffInMinutes($at, false) / 60 / $hours * 100)), 2);
    }

    protected static function severityRank(?string $severity): int
    {
        return [RadarRules::SEVERITY_CRITICAL => 3, RadarRules::SEVERITY_WARNING => 2, RadarRules::SEVERITY_INFO => 1][$severity] ?? 0;
    }
}
