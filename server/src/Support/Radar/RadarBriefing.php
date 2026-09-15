<?php

namespace Fleetbase\FleetOps\Support\Radar;

use Illuminate\Support\Carbon;

/**
 * The morning brief: a score whose arithmetic is visible, a few sentences
 * that name the records behind it, and the decisions that can be made in
 * one click. Everything is derived from the open items; nothing is stored
 * except yesterday's score.
 *
 * Pure, like RadarRules: items and a clock in, arrays out.
 */
class RadarBriefing
{
    /** Category order and labels for the score rows. */
    public const CATEGORIES = [
        RadarRules::CATEGORY_MAINTENANCE  => 'Maintenance',
        RadarRules::CATEGORY_INSPECTIONS  => 'Inspections',
        RadarRules::CATEGORY_STAFFING     => 'Staffing',
        RadarRules::CATEGORY_COMPLIANCE   => 'Compliance',
        RadarRules::CATEGORY_FUEL         => 'Fuel',
        RadarRules::CATEGORY_PARTS        => 'Parts',
        RadarRules::CATEGORY_CONNECTIVITY => 'Connectivity',
        RadarRules::CATEGORY_ISSUES       => 'Issues',
    ];

    /** Points a gap takes off its category, by severity. */
    public const WEIGHTS = [
        RadarRules::SEVERITY_CRITICAL => 10,
        RadarRules::SEVERITY_WARNING  => 4,
        RadarRules::SEVERITY_INFO     => 1,
    ];

    /** The most one rule can take off a category, so one runaway rule cannot zero it. */
    public const RULE_CAP = 40;

    /** How the score rows describe a rule's count. */
    public const GAP_LABELS = [
        'maintenance_overdue'       => ['overdue', 'overdue'],
        'maintenance_due_soon'      => ['due this week', 'due this week'],
        'inspection_due'            => ['inspection due', 'inspections due'],
        'inspection_failed'         => ['failed, no follow-up', 'failed, no follow-up'],
        'inspection_unresolved'     => ['follow-up open', 'follow-ups open'],
        'inspection_draft'          => ['draft never filed', 'drafts never filed'],
        'inspection_link_pending'   => ['link expiring unused', 'links expiring unused'],
        'vehicle_inspection_failed' => ['vehicle failed inspection', 'vehicles failed inspection'],
        'work_order_overdue'        => ['work order overdue', 'work orders overdue'],
        'work_order_blocked'        => ['work order blocked', 'work orders blocked'],
        'issue_open'                => ['open', 'open'],
        'shift_late_start'          => ['late start', 'late starts'],
        'shift_no_vehicle'          => ['on shift, no vehicle', 'on shift, no vehicle'],
        'shift_handover'            => ['handover', 'handovers'],
        'driver_without_vehicle'    => ['driver unassigned', 'drivers unassigned'],
        'vehicle_without_driver'    => ['vehicle idle', 'vehicles idle'],
        'vehicle_without_device'    => ['vehicle untracked', 'vehicles untracked'],
        'device_unattached'         => ['device spare', 'devices spare'],
        'license_expiring'          => ['licence expiring', 'licences expiring'],
        'lease_expiring'            => ['lease expiring', 'leases expiring'],
        'fuel_unmatched'            => ['unmatched transaction', 'unmatched transactions'],
        'part_low_stock'            => ['below reorder point', 'below reorder point'],
        'notice'                    => ['notice', 'notices'],
    ];

    /** How many decision cards the brief offers at most. */
    public const MAX_DECISIONS = 9;

    /**
     * @param array $items   every item RadarRules built, states merged
     * @param array $history [{date: 'Y-m-d', score: int}] previous days
     *
     * @return array{score: array, categories: array, brief: array, decisions: array, open: int}
     */
    public static function build(array $items, Carbon $now, array $history = []): array
    {
        $open       = array_values(array_filter($items, fn ($item) => RadarRules::isOpen($item, $now)));
        $categories = self::categories($open);
        $score      = self::score($categories);

        return [
            'score'      => [
                'value'   => $score,
                'delta'   => self::delta($score, $history, $now),
                'summary' => self::summaryLine($open),
            ],
            'categories' => $categories,
            'brief'      => self::brief($open, $now),
            'decisions'  => self::decisions($open, $now),
            'open'       => count($open),
        ];
    }

    /**
     * One row per category: its score, the counts driving it, and the
     * filter that shows exactly those items.
     */
    public static function categories(array $open): array
    {
        $rows = [];

        foreach (self::CATEGORIES as $category => $label) {
            $members = array_values(array_filter($open, fn ($item) => ($item['category'] ?? null) === $category));
            $byRule  = [];
            foreach ($members as $item) {
                $byRule[$item['rule']][] = $item;
            }

            $penalty = 0;
            $gaps    = [];
            foreach ($byRule as $rule => $ruleItems) {
                $rulePenalty = 0;
                foreach ($ruleItems as $item) {
                    $rulePenalty += self::WEIGHTS[$item['severity']] ?? 1;
                }
                $penalty += min(self::RULE_CAP, $rulePenalty);

                $count  = count($ruleItems);
                $labels = self::GAP_LABELS[$rule] ?? [$rule, $rule];
                $gaps[] = ['rule' => $rule, 'count' => $count, 'label' => $count . ' ' . ($count === 1 ? $labels[0] : $labels[1])];
            }

            usort($gaps, fn ($a, $b) => $b['count'] <=> $a['count']);

            $rows[] = [
                'key'      => $category,
                'label'    => $label,
                'score'    => max(0, 100 - $penalty),
                'count'    => count($members),
                'critical' => count(array_filter($members, fn ($item) => $item['severity'] === RadarRules::SEVERITY_CRITICAL)),
                'gaps'     => $gaps,
                'filter'   => ['category' => $category],
            ];
        }

        return $rows;
    }

    /**
     * The mean of the category scores, rounded.
     */
    public static function score(array $categories): int
    {
        if (!$categories) {
            return 100;
        }

        $total = array_sum(array_column($categories, 'score'));

        return (int) round($total / count($categories));
    }

    /**
     * Today's score against the most recent earlier day in the history.
     */
    public static function delta(int $score, array $history, Carbon $now): ?int
    {
        $today    = $now->toDateString();
        $previous = null;

        foreach ($history as $entry) {
            $date = $entry['date'] ?? null;
            if (!$date || $date >= $today || !isset($entry['score'])) {
                continue;
            }
            if ($previous === null || $date > $previous['date']) {
                $previous = $entry;
            }
        }

        return $previous === null ? null : $score - (int) $previous['score'];
    }

    /**
     * Append today's score to the history, replacing today's earlier entry
     * and keeping the last 30 days.
     */
    public static function pushHistory(array $history, int $score, Carbon $now): array
    {
        $today     = $now->toDateString();
        $history   = array_values(array_filter($history, fn ($entry) => ($entry['date'] ?? null) !== $today && isset($entry['date'], $entry['score'])));
        $history[] = ['date' => $today, 'score' => $score];
        usort($history, fn ($a, $b) => strcmp($a['date'], $b['date']));

        return array_slice($history, -30);
    }

    /**
     * "3 overdue · 12 due this week · 5 unassigned · 8 open issues".
     */
    public static function summaryLine(array $open): string
    {
        $counts = RadarRules::counts($open);
        $parts  = [];
        foreach (['overdue' => 'overdue', 'due_week' => 'due this week', 'unassigned' => 'unassigned', 'issues' => 'open issues', 'inspections' => 'inspection items'] as $pill => $label) {
            if (($counts[$pill] ?? 0) > 0) {
                $parts[] = $counts[$pill] . ' ' . $label;
            }
        }

        return implode(' · ', $parts) ?: 'nothing open';
    }

    /**
     * Up to four sentences, each a list of segments: plain text, or text
     * with the route of the record it names.
     */
    public static function brief(array $open, Carbon $now): array
    {
        if (!$open) {
            return [[self::text('All clear: nothing needs a decision this morning.')]];
        }

        $sentences = array_values(array_filter([
            self::maintenanceSentence($open),
            self::staffingSentence($open),
            self::housekeepingSentence($open),
        ]));

        $sentences[] = [self::text(count($sentences) ? 'Everything else is routine.' : sprintf('%d item%s open, none critical.', count($open), count($open) === 1 ? '' : 's'))];

        return $sentences;
    }

    /**
     * The decisions that can be made in one click, most urgent first.
     */
    public static function decisions(array $open, Carbon $now): array
    {
        $decisions = array_merge(
            self::inspectionDecisions($open),
            self::workOrderDecisions($open),
            self::vehicleDecisions($open),
            self::fuelDecisions($open),
        );

        $order = [RadarRules::SEVERITY_CRITICAL => 0, RadarRules::SEVERITY_WARNING => 1, RadarRules::SEVERITY_INFO => 2];
        usort($decisions, fn ($a, $b) => ($order[$a['severity']] ?? 9) <=> ($order[$b['severity']] ?? 9));

        return array_slice($decisions, 0, self::MAX_DECISIONS);
    }

    // ------------------------------------------------------------------
    // Sentences
    // ------------------------------------------------------------------

    protected static function maintenanceSentence(array $open): ?array
    {
        $overdue  = self::ofRule($open, ['maintenance_overdue', 'work_order_overdue', 'inspection_due'], fn ($item) => $item['due_bucket'] === 'overdue');
        $failed   = self::ofRule($open, ['inspection_failed']);
        $segments = [];

        if ($overdue) {
            $named      = array_slice($overdue, 0, 2);
            $segments[] = self::text(sprintf('%d %s past due: ', count($overdue), count($overdue) === 1 ? 'job is' : 'jobs are'));
            foreach ($named as $index => $item) {
                if ($index > 0) {
                    $segments[] = self::text(count($named) === 2 && $index === 1 ? ' and ' : ', ');
                }
                $segments[] = self::link($item['subject']['label'] ?? 'a record', $item);
                $segments[] = self::text(' (' . ($item['due_label'] ?? $item['title']) . ')');
            }
            if (count($overdue) > 2) {
                $segments[] = self::text(sprintf(' and %d more', count($overdue) - 2));
            }
            $segments[] = self::text('.');
        }

        if ($failed) {
            $first      = $failed[0];
            $segments[] = self::text(($segments ? ' ' : '') . sprintf('%d failed inspection%s %s no follow-up yet', count($failed), count($failed) === 1 ? '' : 's', count($failed) === 1 ? 'has' : 'have'));
            $segments[] = self::text(': ');
            $segments[] = self::link($first['subject']['label'] ?? 'a vehicle', $first);
            $segments[] = self::text(' ' . ($first['meta_line'] ?? '') . '.');
        }

        return $segments ?: null;
    }

    protected static function staffingSentence(array $open): ?array
    {
        $segments = [];
        $clauses  = [];

        foreach (self::ofRule($open, ['shift_late_start']) as $item) {
            $clauses[] = [self::link($item['subject']['label'] ?? 'a driver', $item), self::text(' is ' . ($item['meta_line'] ?? 'late') . ' for a shift that started ' . self::hourFromWindow($item))];
        }
        foreach (self::ofRule($open, ['shift_no_vehicle']) as $item) {
            $clauses[] = [self::link($item['subject']['label'] ?? 'a driver', $item), self::text(' has been on shift ' . ($item['meta_line'] ?? '') . ' without a vehicle')];
        }
        foreach (self::ofRule($open, ['shift_handover']) as $item) {
            $orders    = (int) ($item['source']['active_orders'] ?? 0);
            $clauses[] = [self::link($item['subject']['label'] ?? 'a driver', $item), self::text(sprintf(' ends at %s still holding %d order%s', self::hourFromWindow($item, 'end_at'), $orders, $orders === 1 ? '' : 's'))];
        }

        $idle = self::ofRule($open, ['vehicle_without_driver']);
        if ($idle && $clauses) {
            $first     = $idle[0];
            $clauses[] = [self::link($first['subject']['label'] ?? 'a vehicle', $first), self::text(sprintf(' is idle%s', count($idle) > 1 ? sprintf(' with %d other vehicle%s', count($idle) - 1, count($idle) === 2 ? '' : 's') : ''))];
        }

        if (!$clauses) {
            return null;
        }

        $clauses = array_slice($clauses, 0, 3);
        foreach ($clauses as $index => $clause) {
            if ($index > 0) {
                $segments[] = self::text($index === count($clauses) - 1 ? ', and ' : ', ');
            }
            foreach ($clause as $segment) {
                $segments[] = $segment;
            }
        }
        $segments[] = self::text('.');

        return $segments;
    }

    protected static function housekeepingSentence(array $open): ?array
    {
        $parts = [];

        $expiring = self::ofRule($open, ['license_expiring', 'lease_expiring']);
        if ($expiring) {
            $parts[] = sprintf('%d licence%s or lease%s expire%s within 30 days', count($expiring), count($expiring) === 1 ? '' : 's', count($expiring) === 1 ? '' : 's', count($expiring) === 1 ? 's' : '');
        }

        $fuel = self::ofRule($open, ['fuel_unmatched']);
        if ($fuel) {
            $suggested = count(array_filter($fuel, fn ($item) => !empty($item['source']['suggested_vehicle'])));
            $parts[]   = sprintf('%d fuel transaction%s %s unmatched%s', count($fuel), count($fuel) === 1 ? '' : 's', count($fuel) === 1 ? 'is' : 'are', $suggested ? sprintf(' (%d with a suggested vehicle)', $suggested) : '');
        }

        $parts_ = self::ofRule($open, ['part_low_stock']);
        if ($parts_) {
            $blocking = array_filter($parts_, fn ($item) => str_contains((string) ($item['meta_line'] ?? ''), 'blocks'));
            $parts[]  = sprintf('%d part%s %s below reorder point%s', count($parts_), count($parts_) === 1 ? '' : 's', count($parts_) === 1 ? 'is' : 'are', $blocking ? sprintf(', %d blocking a work order', count($blocking)) : '');
        }

        if (!$parts) {
            return null;
        }

        return [self::text(ucfirst(implode('; ', $parts)) . '.')];
    }

    // ------------------------------------------------------------------
    // Decisions
    // ------------------------------------------------------------------

    protected static function inspectionDecisions(array $open): array
    {
        $decisions = [];

        foreach (self::ofRule($open, ['inspection_failed']) as $item) {
            $needsWorkOrder = in_array('create_work_order_from_inspection', $item['actions'] ?? [], true);
            $needsIssue     = in_array('create_issue_from_inspection', $item['actions'] ?? [], true);
            if (!$needsWorkOrder && !$needsIssue) {
                continue;
            }

            $submission = $item['source']['public_id'] ?? $item['source']['uuid'] ?? null;
            $label      = $item['subject']['label'] ?? 'this vehicle';

            $decisions[] = self::decision('inspection_follow_up:' . $submission, $item, [
                'title'       => $needsWorkOrder ? sprintf('Raise a work order for %s', $label) : sprintf('Raise an issue for %s', $label),
                'subtitle'    => $item['meta_line'] ?? null,
                'reasoning'   => [self::link($label, $item), self::text(' ' . $item['title'] . '. ' . ($item['severity'] === RadarRules::SEVERITY_CRITICAL ? 'A critical item blocks dispatch until the repair is booked.' : 'Booking the repair now keeps the vehicle on the road.'))],
                'confirm'     => $needsWorkOrder
                    ? self::call('Raise work order', 'create_work_order_from_inspection', 'POST', sprintf('inspection-submissions/%s/create-work-order', $submission))
                    : self::call('Raise issue', 'create_issue_from_inspection', 'POST', sprintf('inspection-submissions/%s/create-issue', $submission)),
                'alternatives' => $needsWorkOrder && $needsIssue
                    ? [self::call('Issue only', 'create_issue_from_inspection', 'POST', sprintf('inspection-submissions/%s/create-issue', $submission))]
                    : [],
            ]);
        }

        return $decisions;
    }

    protected static function workOrderDecisions(array $open): array
    {
        $decisions = [];

        foreach (self::ofRule($open, ['maintenance_overdue', 'inspection_due'], fn ($item) => in_array('create_work_order', $item['actions'] ?? [], true)) as $item) {
            $schedule = $item['source']['public_id'] ?? $item['source']['uuid'] ?? null;
            $label    = $item['subject']['label'] ?? 'this vehicle';

            $decisions[] = self::decision('open_work_order:' . $schedule, $item, [
                'title'        => sprintf('Open a work order for %s', $label),
                'subtitle'     => $item['title'],
                'reasoning'    => [self::link($label, $item), self::text(' is ' . ($item['due_label'] ?? 'due') . ' on this schedule and no work order is open for it.')],
                'confirm'      => self::call('Open work order', 'create_work_order', 'POST', sprintf('maintenance-schedules/%s/trigger', $schedule)),
                'alternatives' => [],
            ]);
        }

        return $decisions;
    }

    protected static function vehicleDecisions(array $open): array
    {
        $decisions = [];
        $drivers   = self::ofRule($open, ['shift_no_vehicle', 'driver_without_vehicle'], fn ($item) => $item['rule'] === 'shift_no_vehicle' || $item['severity'] === RadarRules::SEVERITY_WARNING);
        $vehicles  = self::ofRule($open, ['vehicle_without_driver']);

        foreach ($drivers as $index => $driverItem) {
            $vehicleItem = $vehicles[$index] ?? null;
            if (!$vehicleItem) {
                break;
            }

            $driverId  = $driverItem['subject']['uuid'] ?? $driverItem['subject']['public_id'] ?? null;
            $vehicleId = $vehicleItem['subject']['uuid'] ?? $vehicleItem['subject']['public_id'] ?? null;

            $decisions[] = self::decision('assign_vehicle:' . ($driverItem['subject']['public_id'] ?? $driverId), $driverItem, [
                'title'        => sprintf('Assign %s to %s', $vehicleItem['subject']['label'] ?? 'a vehicle', $driverItem['subject']['label'] ?? 'the driver'),
                'subtitle'     => 'clears 2 gaps',
                'reasoning'    => [self::link($driverItem['subject']['label'] ?? 'Driver', $driverItem), self::text(' ' . lcfirst(preg_replace('/^\S+\s+\S+\s+/', '', $driverItem['title'])) . '; '), self::link($vehicleItem['subject']['label'] ?? 'the vehicle', $vehicleItem), self::text(' has no driver' . (!empty($vehicleItem['meta_line']) ? ' (' . $vehicleItem['meta_line'] . ')' : '') . '. Assigning it ends both gaps.')],
                'confirm'      => self::call('Confirm assignment', 'assign_vehicle', 'POST', sprintf('drivers/%s/assign-vehicle', $driverId), ['vehicle' => $vehicleId], self::call('Undo', 'unassign_vehicle', 'POST', sprintf('drivers/%s/unassign-vehicle', $driverId))),
                'alternatives' => [['label' => 'Pick another vehicle', 'action' => 'pick_vehicle']],
                'keys'         => [$driverItem['key'], $vehicleItem['key']],
            ]);
        }

        return $decisions;
    }

    protected static function fuelDecisions(array $open): array
    {
        $decisions = [];

        foreach (self::ofRule($open, ['fuel_unmatched'], fn ($item) => !empty($item['source']['suggested_vehicle'])) as $item) {
            $suggested   = $item['source']['suggested_vehicle'];
            $transaction = $item['source']['uuid'] ?? $item['source']['public_id'] ?? null;

            $decisions[] = self::decision('match_fuel:' . ($item['source']['public_id'] ?? $transaction), $item, [
                'title'        => sprintf('Match %s to %s', preg_replace('/ — .*$/', '', $item['title']), $suggested['label'] ?? 'the suggested vehicle'),
                'subtitle'     => 'matched by ' . str_replace('_', ' ', (string) ($suggested['matched'] ?? 'identity')),
                'reasoning'    => [self::text(sprintf('The transaction\'s %s equals the %s recorded on ', str_replace('_', ' ', (string) ($suggested['matched'] ?? 'identity')), str_replace('_', ' ', (string) ($suggested['matched'] ?? 'identity')))), self::link($suggested['label'] ?? 'the vehicle', $item), self::text('.')],
                'confirm'      => self::call('Confirm match', 'match_vehicle', 'POST', sprintf('fuel-provider-transactions/%s/match-vehicle', $transaction), ['vehicle' => $suggested['uuid'] ?? $suggested['public_id'] ?? null]),
                'alternatives' => [self::call('Ignore', 'ignore_transaction', 'POST', sprintf('fuel-provider-transactions/%s/review', $transaction), ['status' => 'ignored'])],
                'severity'     => RadarRules::SEVERITY_INFO,
            ]);
        }

        return $decisions;
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    protected static function decision(string $key, array $item, array $extra): array
    {
        return [
            'key'          => $key,
            'severity'     => $extra['severity'] ?? $item['severity'],
            'category'     => $item['category'],
            'title'        => $extra['title'],
            'subtitle'     => $extra['subtitle'] ?? null,
            'reasoning'    => $extra['reasoning'] ?? [],
            'confirm'      => $extra['confirm'],
            'alternatives' => $extra['alternatives'] ?? [],
            'keys'         => $extra['keys'] ?? [$item['key']],
            'subject'      => $item['subject'] ?? null,
            'record'       => $item['record'] ?? null,
        ];
    }

    /**
     * A call the console makes when a card is confirmed, with the call
     * that undoes it when there is one.
     */
    protected static function call(string $label, string $action, string $method, string $endpoint, array $body = [], ?array $undo = null): array
    {
        return array_filter(['label' => $label, 'action' => $action, 'method' => $method, 'endpoint' => $endpoint, 'body' => $body, 'undo' => $undo], fn ($value) => $value !== null);
    }

    protected static function ofRule(array $items, array $rules, ?callable $where = null): array
    {
        return array_values(array_filter($items, fn ($item) => in_array($item['rule'], $rules, true) && (!$where || $where($item))));
    }

    protected static function text(string $text): array
    {
        return ['text' => $text];
    }

    protected static function link(string $text, array $item): array
    {
        $record = $item['record'] ?? null;

        return $record ? ['text' => $text, 'route' => $record['route'], 'model' => $record['model']] : ['text' => $text];
    }

    protected static function hourFromWindow(array $item, string $edge = 'start_at'): string
    {
        $at = RadarRules::carbon($item['window'][$edge] ?? null);

        return $at ? $at->format('H:i') : 'earlier';
    }
}
