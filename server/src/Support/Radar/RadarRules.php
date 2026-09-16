<?php

namespace Fleetbase\FleetOps\Support\Radar;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Turns plain rows from the resource tables into Radar items: the per-record
 * gaps a fleet manager has to decide about.
 *
 * Everything here is pure. The controller loads the rows and hands them over
 * as arrays; this class never queries, never reads the session, and takes the
 * clock as an argument, so a test can feed it a fixture and a fixed "now".
 */
class RadarRules
{
    public const CATEGORY_MAINTENANCE  = 'maintenance';
    public const CATEGORY_INSPECTIONS  = 'inspections';
    public const CATEGORY_STAFFING     = 'staffing';
    public const CATEGORY_COMPLIANCE   = 'compliance';
    public const CATEGORY_FUEL         = 'fuel';
    public const CATEGORY_PARTS        = 'parts';
    public const CATEGORY_CONNECTIVITY = 'connectivity';
    public const CATEGORY_ISSUES       = 'issues';
    public const CATEGORY_NOTICES      = 'notices';

    public const LANE_SHIFTS      = 'shifts';
    public const LANE_MAINTENANCE = 'maintenance';
    public const LANE_EXPIRIES    = 'expiries';
    public const LANE_NOTICES     = 'notices';
    public const LANE_ANYTIME     = 'anytime';

    public const SEVERITY_CRITICAL = 'critical';
    public const SEVERITY_WARNING  = 'warning';
    public const SEVERITY_INFO     = 'info';

    public const NOTICE_TYPE = 'radar_notice';

    /** How far ahead "due soon" and "expiring" look. */
    public const DUE_SOON_DAYS = 7;
    public const EXPIRING_DAYS = 30;

    /** A draft inspection older than this is one nobody is going to finish. */
    public const STALE_DRAFT_HOURS = 24;

    /** An inspection link expiring within this window is worth a nudge. */
    public const LINK_EXPIRY_HOURS = 24;

    /** How late a scheduled shift start is before it becomes an item, and when it turns critical. */
    public const LATE_START_MINUTES     = 15;
    public const LATE_START_CRITICAL    = 60;

    /** A shift ending inside this window with orders still on the driver needs a handover. */
    public const HANDOVER_MINUTES = 60;

    /** The default low-stock threshold when neither the column nor the spec says otherwise. */
    public const DEFAULT_LOW_STOCK = 5;

    /**
     * Every rule, with the category it reports under, the agenda lane it lands
     * on when dated, and the chip label the console shows.
     */
    public const RULES = [
        'maintenance_overdue'       => ['category' => self::CATEGORY_MAINTENANCE, 'lane' => self::LANE_MAINTENANCE, 'chip' => 'Maint'],
        'maintenance_due_soon'      => ['category' => self::CATEGORY_MAINTENANCE, 'lane' => self::LANE_MAINTENANCE, 'chip' => 'Maint'],
        'inspection_due'            => ['category' => self::CATEGORY_INSPECTIONS, 'lane' => self::LANE_MAINTENANCE, 'chip' => 'Inspection'],
        'inspection_failed'         => ['category' => self::CATEGORY_INSPECTIONS, 'lane' => self::LANE_ANYTIME, 'chip' => 'Inspection'],
        'inspection_unresolved'     => ['category' => self::CATEGORY_INSPECTIONS, 'lane' => self::LANE_ANYTIME, 'chip' => 'Inspection'],
        'inspection_draft'          => ['category' => self::CATEGORY_INSPECTIONS, 'lane' => self::LANE_ANYTIME, 'chip' => 'Inspection'],
        'inspection_link_pending'   => ['category' => self::CATEGORY_INSPECTIONS, 'lane' => self::LANE_EXPIRIES, 'chip' => 'Inspection'],
        'vehicle_inspection_failed' => ['category' => self::CATEGORY_INSPECTIONS, 'lane' => self::LANE_ANYTIME, 'chip' => 'Inspection'],
        'work_order_overdue'        => ['category' => self::CATEGORY_MAINTENANCE, 'lane' => self::LANE_MAINTENANCE, 'chip' => 'Work order'],
        'work_order_blocked'        => ['category' => self::CATEGORY_MAINTENANCE, 'lane' => self::LANE_MAINTENANCE, 'chip' => 'Work order'],
        'issue_open'                => ['category' => self::CATEGORY_ISSUES, 'lane' => self::LANE_ANYTIME, 'chip' => 'Issue'],
        'shift_late_start'          => ['category' => self::CATEGORY_STAFFING, 'lane' => self::LANE_SHIFTS, 'chip' => 'Shift'],
        'shift_no_vehicle'          => ['category' => self::CATEGORY_STAFFING, 'lane' => self::LANE_SHIFTS, 'chip' => 'Shift'],
        'shift_handover'            => ['category' => self::CATEGORY_STAFFING, 'lane' => self::LANE_SHIFTS, 'chip' => 'Handover'],
        'driver_without_vehicle'    => ['category' => self::CATEGORY_STAFFING, 'lane' => self::LANE_ANYTIME, 'chip' => 'Unassigned'],
        'vehicle_without_driver'    => ['category' => self::CATEGORY_STAFFING, 'lane' => self::LANE_ANYTIME, 'chip' => 'Idle'],
        'vehicle_without_device'    => ['category' => self::CATEGORY_CONNECTIVITY, 'lane' => self::LANE_ANYTIME, 'chip' => 'Device'],
        'device_unattached'         => ['category' => self::CATEGORY_CONNECTIVITY, 'lane' => self::LANE_ANYTIME, 'chip' => 'Device'],
        'license_expiring'          => ['category' => self::CATEGORY_COMPLIANCE, 'lane' => self::LANE_EXPIRIES, 'chip' => 'Licence'],
        'lease_expiring'            => ['category' => self::CATEGORY_COMPLIANCE, 'lane' => self::LANE_EXPIRIES, 'chip' => 'Lease'],
        'fuel_unmatched'            => ['category' => self::CATEGORY_FUEL, 'lane' => self::LANE_ANYTIME, 'chip' => 'Fuel'],
        'part_low_stock'            => ['category' => self::CATEGORY_PARTS, 'lane' => self::LANE_ANYTIME, 'chip' => 'Parts'],
        'notice'                    => ['category' => self::CATEGORY_NOTICES, 'lane' => self::LANE_NOTICES, 'chip' => 'Notice'],
    ];

    /**
     * The count pills, each the set of rules (or the due bucket) it counts.
     */
    public const PILLS = [
        'overdue'     => ['buckets' => ['overdue']],
        'due_week'    => ['buckets' => ['today', 'week']],
        'unassigned'  => ['rules' => ['driver_without_vehicle', 'shift_no_vehicle', 'vehicle_without_driver']],
        'issues'      => ['rules' => ['issue_open']],
        'inspections' => ['rules' => ['inspection_due', 'inspection_failed', 'inspection_unresolved', 'inspection_draft', 'inspection_link_pending', 'vehicle_inspection_failed']],
        'shifts'      => ['rules' => ['shift_late_start', 'shift_no_vehicle', 'shift_handover']],
        'expiring'    => ['rules' => ['license_expiring', 'lease_expiring', 'inspection_link_pending']],
        'low_stock'   => ['rules' => ['part_low_stock']],
        'fuel'        => ['rules' => ['fuel_unmatched']],
        'notices'     => ['rules' => ['notice']],
    ];

    /** Work order statuses that mean the work is done with. */
    public const WORK_ORDER_CLOSED = ['closed', 'completed', 'done', 'canceled', 'cancelled'];

    /** Work order statuses that mean the work is waiting on something. */
    public const WORK_ORDER_BLOCKED = ['blocked', 'awaiting_parts', 'awaiting_vendor', 'on_hold'];

    /** Issue priorities that make an open issue critical. */
    public const ISSUE_CRITICAL_PRIORITIES = ['high', 'critical', 'urgent'];

    /** Shift statuses that count as a shift the driver is expected to work. */
    public const SHIFT_LIVE_STATUSES = ['pending', 'scheduled', 'confirmed', 'in_progress'];

    /** Shift statuses in which a driver should have shown up by the start time. */
    public const SHIFT_NOT_STARTED_STATUSES = ['pending', 'scheduled', 'confirmed'];

    /**
     * Build every item from the given source rows.
     *
     * @param array $sources keyed by source name; each a list of plain arrays (see the load* methods on RadarController)
     *
     * @return array{items: array, counts: array, summary: array}
     */
    public static function build(array $sources, Carbon $now): array
    {
        $items = [];

        foreach (self::scheduleItems($sources['schedules'] ?? [], $now) as $item) {
            $items[] = $item;
        }
        foreach (self::workOrderItems($sources['workOrders'] ?? [], $now) as $item) {
            $items[] = $item;
        }
        foreach (self::issueItems($sources['issues'] ?? [], $now) as $item) {
            $items[] = $item;
        }
        foreach (self::inspectionItems($sources['inspectionSubmissions'] ?? [], $now) as $item) {
            $items[] = $item;
        }
        foreach (self::inspectionLinkItems($sources['inspectionLinks'] ?? [], $now) as $item) {
            $items[] = $item;
        }
        foreach (self::shiftItems($sources['shifts'] ?? [], $now) as $item) {
            $items[] = $item;
        }
        foreach (self::driverItems($sources['drivers'] ?? [], $sources['shifts'] ?? [], $now) as $item) {
            $items[] = $item;
        }
        foreach (self::vehicleItems($sources['vehicles'] ?? [], $sources['devices'] ?? [], $now) as $item) {
            $items[] = $item;
        }
        foreach (self::trailerItems($sources['trailers'] ?? [], $now) as $item) {
            $items[] = $item;
        }
        foreach (self::deviceItems($sources['devices'] ?? [], $now) as $item) {
            $items[] = $item;
        }
        foreach (self::partItems($sources['parts'] ?? [], $sources['workOrders'] ?? [], $now) as $item) {
            $items[] = $item;
        }
        foreach (self::fuelItems($sources['fuelTransactions'] ?? [], $now) as $item) {
            $items[] = $item;
        }
        foreach (self::noticeItems($sources['notices'] ?? [], $now) as $item) {
            $items[] = $item;
        }

        $items = self::mergeStates($items, $sources['states'] ?? [], $now);
        $items = self::sort($items);

        $open = array_values(array_filter($items, fn ($item) => self::isOpen($item, $now)));

        return [
            'items'   => $items,
            'counts'  => self::counts($open),
            'summary' => self::summary($items, $now),
        ];
    }

    // ------------------------------------------------------------------
    // Rules
    // ------------------------------------------------------------------

    /**
     * Maintenance schedules: overdue, due within a week, and inspection-type
     * schedules as their own rule so the chip says what the job is.
     */
    public static function scheduleItems(array $schedules, Carbon $now): array
    {
        $items = [];

        foreach ($schedules as $schedule) {
            $due = self::carbon($schedule['next_due_date'] ?? null);
            if (!$due || ($schedule['status'] ?? 'active') !== 'active') {
                continue;
            }

            $isInspection = ($schedule['type'] ?? null) === 'inspection';
            $isOverdue    = $due->lt($now);
            $isDueSoon    = !$isOverdue && $due->lte($now->copy()->addDays(self::DUE_SOON_DAYS));

            if (!$isOverdue && !$isDueSoon) {
                continue;
            }

            $rule = $isInspection ? 'inspection_due' : ($isOverdue ? 'maintenance_overdue' : 'maintenance_due_soon');
            $name = $schedule['name'] ?: ($isInspection ? 'Inspection' : 'Service');

            if ($isOverdue) {
                $title = sprintf('%s %s', $name, self::overdueWords($due, $now));
            } else {
                $title = sprintf('%s due %s', $name, self::dueInWords($due, $now));
            }

            $meta = [];
            if (!empty($schedule['next_due_odometer'])) {
                $meta[] = 'due at ' . number_format((int) $schedule['next_due_odometer']) . ' odometer';
            }
            if (!empty($schedule['has_open_work_order'])) {
                $meta[] = 'work order open';
            }

            $items[] = self::item($rule, $schedule['subject'] ?? null, $title, [
                'severity'  => $isOverdue ? self::SEVERITY_CRITICAL : self::SEVERITY_WARNING,
                'due_at'    => $due,
                'meta_line' => implode(' · ', $meta) ?: null,
                'record'    => self::record('maintenance.schedules.index.details', $schedule['public_id'] ?? null),
                'source'    => ['type' => 'schedule', 'uuid' => $schedule['uuid'] ?? null, 'public_id' => $schedule['public_id'] ?? null, 'has_open_work_order' => (bool) ($schedule['has_open_work_order'] ?? false)],
                'actions'   => array_keys(array_filter(['create_work_order' => empty($schedule['has_open_work_order']), 'acknowledge' => true, 'snooze' => true, 'assign' => true, 'open_record' => true])),
            ], $now, $schedule['public_id'] ?? ($schedule['uuid'] ?? ''));
        }

        return $items;
    }

    /**
     * Work orders past due, or waiting on parts, a vendor, or a hold.
     */
    public static function workOrderItems(array $workOrders, Carbon $now): array
    {
        $items = [];

        foreach ($workOrders as $workOrder) {
            $status = $workOrder['status'] ?? 'open';
            if (in_array($status, self::WORK_ORDER_CLOSED, true)) {
                continue;
            }

            $due       = self::carbon($workOrder['due_at'] ?? null);
            $isOverdue = $due && $due->lt($now);
            $isBlocked = in_array($status, self::WORK_ORDER_BLOCKED, true);

            if (!$isOverdue && !$isBlocked) {
                continue;
            }

            $code  = $workOrder['code'] ?? $workOrder['public_id'] ?? 'Work order';
            $title = trim($code . ' ' . ($workOrder['subject'] ?? ''));
            $meta  = [];

            if ($isBlocked) {
                $meta[] = Str::of($status)->replace('_', ' ')->toString();
            }
            if (!empty($workOrder['priority']) && $workOrder['priority'] !== 'normal') {
                $meta[] = $workOrder['priority'] . ' priority';
            }

            $items[] = self::item($isOverdue ? 'work_order_overdue' : 'work_order_blocked', $workOrder['target'] ?? null, $title, [
                'severity'  => $isOverdue ? self::SEVERITY_CRITICAL : self::SEVERITY_WARNING,
                'due_at'    => $due,
                'meta_line' => implode(' · ', $meta) ?: null,
                'record'    => self::record('maintenance.work-orders.index.details', $workOrder['public_id'] ?? null),
                'source'    => ['type' => 'work_order', 'uuid' => $workOrder['uuid'] ?? null, 'public_id' => $workOrder['public_id'] ?? null, 'status' => $status],
                'actions'   => ['acknowledge', 'snooze', 'assign', 'open_record'],
            ], $now, $workOrder['public_id'] ?? ($workOrder['uuid'] ?? ''));
        }

        return $items;
    }

    /**
     * Open issues, critical when their priority says so. An issue an
     * inspection raised says so in its meta line.
     */
    public static function issueItems(array $issues, Carbon $now): array
    {
        $items = [];

        foreach ($issues as $issue) {
            if (in_array($issue['status'] ?? 'pending', ['resolved', 'closed'], true)) {
                continue;
            }

            $priority = strtolower((string) ($issue['priority'] ?? ''));
            $subject  = $issue['vehicle'] ?? $issue['driver'] ?? null;
            $title    = $issue['title'] ?: Str::limit((string) ($issue['report'] ?? 'Open issue'), 80);
            $meta     = [];

            if ($priority) {
                $meta[] = $priority . ' priority';
            }
            if (!empty($issue['meta']['inspection_submission_id'])) {
                $meta[] = 'raised by inspection ' . $issue['meta']['inspection_submission_id'];
            } elseif (!empty($issue['meta']['inspection_submission_uuid'])) {
                $meta[] = 'raised by an inspection';
            }
            if (!empty($issue['reporter_name'])) {
                $meta[] = 'reported by ' . $issue['reporter_name'];
            }

            $items[] = self::item('issue_open', $subject, $title, [
                'severity'  => in_array($priority, self::ISSUE_CRITICAL_PRIORITIES, true) ? self::SEVERITY_CRITICAL : self::SEVERITY_WARNING,
                'meta_line' => implode(' · ', $meta) ?: null,
                'record'    => self::record('management.issues.index.details', $issue['public_id'] ?? null),
                'source'    => ['type' => 'issue', 'uuid' => $issue['uuid'] ?? null, 'public_id' => $issue['public_id'] ?? null, 'status' => $issue['status'] ?? null],
                'actions'   => ['resolve_issue', 'acknowledge', 'snooze', 'assign', 'open_record'],
            ], $now, $issue['public_id'] ?? ($issue['uuid'] ?? ''));
        }

        return $items;
    }

    /**
     * Inspection submissions: failed ones with follow-up still to raise,
     * failed ones waiting on their follow-up, and drafts nobody finished.
     */
    public static function inspectionItems(array $submissions, Carbon $now): array
    {
        $items = [];

        foreach ($submissions as $submission) {
            $status      = $submission['status'] ?? 'draft';
            $result      = $submission['result'] ?? null;
            $failedItems = (int) ($submission['failed_items'] ?? 0);
            $hasFailures = $failedItems > 0 || $result === 'failed';
            $subject     = $submission['vehicle'] ?? $submission['driver'] ?? null;
            $formName    = $submission['form_name'] ?? 'Inspection';
            $publicId    = $submission['public_id'] ?? ($submission['uuid'] ?? '');
            $record      = self::record('maintenance.inspection-submissions.index.details', $submission['public_id'] ?? null);
            $source      = ['type' => 'inspection_submission', 'uuid' => $submission['uuid'] ?? null, 'public_id' => $submission['public_id'] ?? null, 'status' => $status, 'result' => $result, 'issue_uuid' => $submission['issue_uuid'] ?? null, 'work_order_uuid' => $submission['work_order_uuid'] ?? null];

            if ($status === 'draft') {
                $started = self::carbon($submission['started_at'] ?? $submission['created_at'] ?? null);
                if (!$started || $started->gt($now->copy()->subHours(self::STALE_DRAFT_HOURS))) {
                    continue;
                }

                $items[] = self::item('inspection_draft', $subject, sprintf('%s started %s, never filed', $formName, self::agoWords($started, $now)), [
                    'severity'  => self::SEVERITY_INFO,
                    'meta_line' => !empty($submission['driver_name']) ? 'by ' . $submission['driver_name'] : null,
                    'record'    => $record,
                    'source'    => $source,
                    'actions'   => ['open_record', 'acknowledge', 'snooze', 'assign'],
                ], $now, $publicId);
                continue;
            }

            if ($status === 'resolved' || !$hasFailures) {
                continue;
            }

            $needsIssue     = empty($submission['issue_uuid']);
            $needsWorkOrder = empty($submission['work_order_uuid']);
            $severity       = strtolower((string) ($submission['highest_severity'] ?? 'high'));
            $criticalWords  = $severity === 'critical' ? 'critical ' : '';

            if ($needsIssue || $needsWorkOrder) {
                $missing = [];
                if ($needsIssue) {
                    $missing[] = 'no issue';
                }
                if ($needsWorkOrder) {
                    $missing[] = 'no work order';
                }

                $actions = [];
                if ($needsWorkOrder) {
                    $actions[] = 'create_work_order_from_inspection';
                }
                if ($needsIssue) {
                    $actions[] = 'create_issue_from_inspection';
                }

                $items[] = self::item('inspection_failed', $subject, sprintf('%s failed %d %sitem%s', $formName, $failedItems, $criticalWords, $failedItems === 1 ? '' : 's'), [
                    'severity'  => $severity === 'critical' ? self::SEVERITY_CRITICAL : self::SEVERITY_WARNING,
                    'meta_line' => implode(' · ', array_merge([$failedItems . ' failed'], $missing)),
                    'record'    => $record,
                    'source'    => $source,
                    'actions'   => array_merge($actions, ['acknowledge', 'snooze', 'assign', 'open_record']),
                    'details'   => ['failed_items' => $submission['failed_item_labels'] ?? []],
                ], $now, $publicId);
                continue;
            }

            if (empty($submission['resolved_at'])) {
                $items[] = self::item('inspection_unresolved', $subject, sprintf('%s follow-up still open', $formName), [
                    'severity'  => self::SEVERITY_INFO,
                    'meta_line' => $failedItems . ' failed · issue and work order raised',
                    'record'    => $record,
                    'source'    => $source,
                    'actions'   => ['resolve_inspection', 'acknowledge', 'snooze', 'assign', 'open_record'],
                ], $now, $publicId);
            }
        }

        return $items;
    }

    /**
     * Inspection links that were sent but never used, once they are close to
     * expiring or already have.
     */
    public static function inspectionLinkItems(array $links, Carbon $now): array
    {
        $items = [];

        foreach ($links as $link) {
            if (($link['status'] ?? 'active') !== 'active' || !empty($link['used_at'])) {
                continue;
            }

            $expires = self::carbon($link['expires_at'] ?? null);
            if (!$expires || $expires->gt($now->copy()->addHours(self::LINK_EXPIRY_HOURS))) {
                continue;
            }

            $expired  = $expires->lte($now);
            $subject  = $link['driver'] ?? $link['vehicle'] ?? null;
            $formName = $link['form_name'] ?? 'Inspection';
            $title    = $expired
                ? sprintf('%s link expired unused', $formName)
                : sprintf('%s link expires %s, not yet used', $formName, self::dueInWords($expires, $now));

            $items[] = self::item('inspection_link_pending', $subject, $title, [
                'severity'  => $expired ? self::SEVERITY_WARNING : self::SEVERITY_INFO,
                'due_at'    => $expires,
                'meta_line' => !empty($link['last_viewed_at']) ? 'opened, not filed' : 'never opened',
                'record'    => self::record('maintenance.inspection-forms.index.details', $link['form_public_id'] ?? null),
                'source'    => ['type' => 'inspection_link', 'uuid' => $link['uuid'] ?? null, 'public_id' => $link['public_id'] ?? null, 'form_uuid' => $link['form_uuid'] ?? null, 'form_public_id' => $link['form_public_id'] ?? null, 'state' => $expired ? 'expired' : 'active'],
                'actions'   => ['send_pin', 'revoke_link', 'acknowledge', 'snooze', 'assign', 'open_record'],
            ], $now, $link['public_id'] ?? ($link['uuid'] ?? ''));
        }

        return $items;
    }

    /**
     * Shifts happening now: a driver who should have started but is not
     * online, a driver on shift with no vehicle, and a shift ending soon with
     * orders still on the driver.
     */
    public static function shiftItems(array $shifts, Carbon $now): array
    {
        $items = [];

        foreach ($shifts as $shift) {
            $start  = self::carbon($shift['start_at'] ?? null);
            $end    = self::carbon($shift['end_at'] ?? null);
            $status = $shift['status'] ?? 'scheduled';
            $driver = $shift['driver'] ?? null;

            if (!$start || !$end || !$driver || !in_array($status, self::SHIFT_LIVE_STATUSES, true)) {
                continue;
            }

            if ($end->lte($now) || $start->gt($now)) {
                continue;
            }

            $window   = ['start_at' => $start->toIso8601String(), 'end_at' => $end->toIso8601String(), 'status' => $status];
            $name     = $driver['label'] ?? 'Driver';
            $publicId = $driver['public_id'] ?? ($driver['uuid'] ?? '');
            $source   = ['type' => 'shift', 'uuid' => $shift['uuid'] ?? null, 'public_id' => $shift['public_id'] ?? null, 'driver_uuid' => $driver['uuid'] ?? null];
            $record   = self::record('management.drivers.index.details', $driver['public_id'] ?? null);

            $minutesLate = $start->diffInMinutes($now, false);
            if (empty($shift['driver_online']) && in_array($status, self::SHIFT_NOT_STARTED_STATUSES, true) && $minutesLate >= self::LATE_START_MINUTES) {
                $items[] = self::item('shift_late_start', $driver, sprintf('%s scheduled %s, not online', $name, $start->format('H:i')), [
                    'severity'  => $minutesLate >= self::LATE_START_CRITICAL ? self::SEVERITY_CRITICAL : self::SEVERITY_WARNING,
                    'meta_line' => self::minutesWords($minutesLate) . ' late',
                    'window'    => $window,
                    'record'    => $record,
                    'source'    => $source,
                    'actions'   => ['call', 'cover_shift', 'acknowledge', 'snooze', 'assign', 'open_record'],
                ], $now, $publicId);
            }

            if (empty($shift['driver_vehicle_uuid'])) {
                $items[] = self::item('shift_no_vehicle', $driver, sprintf('%s on shift since %s, no vehicle assigned', $name, $start->format('H:i')), [
                    'severity'  => self::SEVERITY_WARNING,
                    'meta_line' => self::minutesWords($start->diffInMinutes($now)) . ' on shift',
                    'window'    => $window,
                    'record'    => $record,
                    'source'    => $source,
                    'actions'   => ['assign_vehicle', 'acknowledge', 'snooze', 'assign', 'open_record'],
                ], $now, $publicId);
            }

            $activeOrders  = (int) ($shift['active_orders'] ?? 0);
            $minutesToEnd  = $now->diffInMinutes($end, false);
            if ($activeOrders > 0 && $minutesToEnd <= self::HANDOVER_MINUTES) {
                $items[] = self::item('shift_handover', $driver, sprintf('%s shift ends in %s, %d active order%s still assigned', $name, self::minutesWords($minutesToEnd), $activeOrders, $activeOrders === 1 ? '' : 's'), [
                    'severity'  => self::SEVERITY_WARNING,
                    'due_at'    => $end,
                    'window'    => $window,
                    'record'    => $record,
                    'source'    => $source + ['active_orders' => $activeOrders],
                    'actions'   => ['handover', 'extend_shift', 'acknowledge', 'snooze', 'assign', 'open_record'],
                ], $now, $publicId);
            }
        }

        return $items;
    }

    /**
     * Drivers with no vehicle (when not on shift — the shift rule covers
     * that) and driver licences expiring.
     */
    public static function driverItems(array $drivers, array $shifts, Carbon $now): array
    {
        $items   = [];
        $onShift = [];

        foreach ($shifts as $shift) {
            $start = self::carbon($shift['start_at'] ?? null);
            $end   = self::carbon($shift['end_at'] ?? null);
            if ($start && $end && $start->lte($now) && $end->gt($now) && in_array($shift['status'] ?? 'scheduled', self::SHIFT_LIVE_STATUSES, true)) {
                $onShift[$shift['driver']['uuid'] ?? ''] = true;
            }
        }

        foreach ($drivers as $driver) {
            $subject  = self::driverSubject($driver);
            $publicId = $driver['public_id'] ?? ($driver['uuid'] ?? '');
            $record   = self::record('management.drivers.index.details', $driver['public_id'] ?? null);

            if (empty($driver['vehicle_uuid']) && empty($onShift[$driver['uuid'] ?? ''])) {
                $items[] = self::item('driver_without_vehicle', $subject, sprintf('%s has no vehicle assigned', $subject['label']), [
                    'severity'  => !empty($driver['online']) ? self::SEVERITY_WARNING : self::SEVERITY_INFO,
                    'meta_line' => !empty($driver['online']) ? 'online now' : null,
                    'record'    => $record,
                    'source'    => ['type' => 'driver', 'uuid' => $driver['uuid'] ?? null, 'public_id' => $driver['public_id'] ?? null],
                    'actions'   => ['assign_vehicle', 'acknowledge', 'snooze', 'assign', 'open_record'],
                ], $now, $publicId);
            }

            $expiry = self::carbon($driver['license_expiry'] ?? null);
            if ($expiry && $expiry->lte($now->copy()->addDays(self::EXPIRING_DAYS))) {
                $expired = $expiry->lt($now->copy()->startOfDay());
                $items[] = self::item('license_expiring', $subject, $expired
                    ? sprintf('%s licence expired %s', $subject['label'], $expiry->format('j M'))
                    : sprintf('%s licence expires %s', $subject['label'], self::dueInWords($expiry, $now)), [
                        'severity'  => $expired ? self::SEVERITY_CRITICAL : self::SEVERITY_WARNING,
                        'due_at'    => $expiry,
                        'meta_line' => !empty($driver['drivers_license_number']) ? 'licence ' . $driver['drivers_license_number'] : null,
                        'record'    => $record,
                        'source'    => ['type' => 'driver', 'uuid' => $driver['uuid'] ?? null, 'public_id' => $driver['public_id'] ?? null],
                        'actions'   => ['acknowledge', 'snooze', 'assign', 'open_record'],
                    ], $now, $publicId);
            }
        }

        return $items;
    }

    /**
     * Vehicles with no driver, no device (while spare devices exist), a
     * failed-inspection status, or a lease running out.
     */
    public static function vehicleItems(array $vehicles, array $devices, Carbon $now): array
    {
        $items           = [];
        $spareDevices    = count(array_filter($devices, fn ($device) => empty($device['attachable_uuid'])));

        foreach ($vehicles as $vehicle) {
            $subject  = self::vehicleSubject($vehicle);
            $publicId = $vehicle['public_id'] ?? ($vehicle['uuid'] ?? '');
            $record   = self::record('management.vehicles.index.details', $vehicle['public_id'] ?? null);
            $source   = ['type' => 'vehicle', 'uuid' => $vehicle['uuid'] ?? null, 'public_id' => $vehicle['public_id'] ?? null, 'status' => $vehicle['status'] ?? null];

            if (($vehicle['status'] ?? null) === 'inspection_failed') {
                $items[] = self::item('vehicle_inspection_failed', $subject, sprintf('%s failed inspection, cannot dispatch', $subject['label']), [
                    'severity'  => self::SEVERITY_CRITICAL,
                    'meta_line' => 'status inspection_failed',
                    'record'    => self::record('management.vehicles.index.details.inspections', $vehicle['public_id'] ?? null),
                    'source'    => $source,
                    'actions'   => ['acknowledge', 'snooze', 'assign', 'open_record'],
                ], $now, $publicId);
            }

            if (empty($vehicle['driver_uuid']) && in_array($vehicle['status'] ?? 'available', ['available', 'active', 'idle', 'operational'], true)) {
                $meta = [];
                if (!empty($vehicle['lease_expires_at'])) {
                    $lease = self::carbon($vehicle['lease_expires_at']);
                    if ($lease) {
                        $meta[] = 'lease ends ' . $lease->format('j M');
                    }
                }

                $items[] = self::item('vehicle_without_driver', $subject, sprintf('%s has no driver', $subject['label']), [
                    'severity'  => self::SEVERITY_INFO,
                    'meta_line' => implode(' · ', $meta) ?: null,
                    'record'    => $record,
                    'source'    => $source,
                    'actions'   => ['assign_driver', 'acknowledge', 'snooze', 'assign', 'open_record'],
                ], $now, $publicId);
            }

            if ((int) ($vehicle['device_count'] ?? 0) === 0 && $spareDevices > 0) {
                $items[] = self::item('vehicle_without_device', $subject, sprintf('%s has no tracking device', $subject['label']), [
                    'severity'  => self::SEVERITY_INFO,
                    'meta_line' => sprintf('%d spare device%s', $spareDevices, $spareDevices === 1 ? '' : 's'),
                    'record'    => self::record('management.vehicles.index.details.devices', $vehicle['public_id'] ?? null),
                    'source'    => $source,
                    'actions'   => ['acknowledge', 'snooze', 'assign', 'open_record'],
                ], $now, $publicId);
            }

            $lease = self::carbon($vehicle['lease_expires_at'] ?? null);
            if ($lease && $lease->lte($now->copy()->addDays(self::EXPIRING_DAYS))) {
                $items[] = self::leaseItem($subject, $lease, $record, $source, $now, $publicId);
            }
        }

        return $items;
    }

    /**
     * Trailers whose lease is running out.
     */
    public static function trailerItems(array $trailers, Carbon $now): array
    {
        $items = [];

        foreach ($trailers as $trailer) {
            $lease = self::carbon($trailer['lease_expires_at'] ?? null);
            if (!$lease || $lease->gt($now->copy()->addDays(self::EXPIRING_DAYS))) {
                continue;
            }

            $subject  = ['type' => 'trailer', 'class' => $trailer['class'] ?? null, 'uuid' => $trailer['uuid'] ?? null, 'public_id' => $trailer['public_id'] ?? null, 'label' => $trailer['label'] ?? ($trailer['public_id'] ?? 'Trailer'), 'photo_url' => $trailer['photo_url'] ?? null];
            $publicId = $trailer['public_id'] ?? ($trailer['uuid'] ?? '');
            $items[]  = self::leaseItem($subject, $lease, self::record('management.trailers.index.details', $trailer['public_id'] ?? null), ['type' => 'trailer', 'uuid' => $trailer['uuid'] ?? null, 'public_id' => $trailer['public_id'] ?? null], $now, $publicId);
        }

        return $items;
    }

    /**
     * Devices not attached to anything.
     */
    public static function deviceItems(array $devices, Carbon $now): array
    {
        $items = [];

        foreach ($devices as $device) {
            if (!empty($device['attachable_uuid'])) {
                continue;
            }

            $subject = ['type' => 'device', 'class' => $device['class'] ?? null, 'uuid' => $device['uuid'] ?? null, 'public_id' => $device['public_id'] ?? null, 'label' => $device['name'] ?? ($device['device_id'] ?? ($device['public_id'] ?? 'Device')), 'photo_url' => null];
            $items[] = self::item('device_unattached', $subject, sprintf('%s not attached to a vehicle', $subject['label']), [
                'severity'  => self::SEVERITY_INFO,
                'meta_line' => !empty($device['online']) ? 'online' : 'offline',
                'record'    => self::record('connectivity.devices.index.details', $device['public_id'] ?? null),
                'source'    => ['type' => 'device', 'uuid' => $device['uuid'] ?? null, 'public_id' => $device['public_id'] ?? null],
                'actions'   => ['attach_device', 'acknowledge', 'snooze', 'assign', 'open_record'],
            ], $now, $device['public_id'] ?? ($device['uuid'] ?? ''));
        }

        return $items;
    }

    /**
     * Parts at or below their reorder point. Critical when there are none
     * left and an open work order's checklist names the part.
     */
    public static function partItems(array $parts, array $workOrders, Carbon $now): array
    {
        $items = [];

        foreach ($parts as $part) {
            $onHand    = (int) ($part['quantity_on_hand'] ?? 0);
            $threshold = self::lowStockThreshold($part);
            if ($onHand > $threshold) {
                continue;
            }

            $name    = $part['name'] ?? ($part['sku'] ?? 'Part');
            $blocks  = self::workOrderNaming($workOrders, [$part['name'] ?? null, $part['sku'] ?? null]);
            $meta    = [sprintf('reorder point %d', $threshold)];
            if ($blocks) {
                $meta[] = 'blocks ' . $blocks;
            }

            $subject = ['type' => 'part', 'class' => $part['class'] ?? null, 'uuid' => $part['uuid'] ?? null, 'public_id' => $part['public_id'] ?? null, 'label' => $name, 'photo_url' => $part['photo_url'] ?? null];
            $items[] = self::item('part_low_stock', $subject, $onHand > 0
                ? sprintf('%s — %d left, reorder point %d', $name, $onHand, $threshold)
                : sprintf('%s — out of stock', $name), [
                    'severity'  => ($onHand <= 0 && $blocks) ? self::SEVERITY_CRITICAL : self::SEVERITY_WARNING,
                    'meta_line' => implode(' · ', $meta),
                    'record'    => self::record('maintenance.parts.index.details', $part['public_id'] ?? null),
                    'source'    => ['type' => 'part', 'uuid' => $part['uuid'] ?? null, 'public_id' => $part['public_id'] ?? null, 'quantity_on_hand' => $onHand, 'threshold' => $threshold],
                    'actions'   => ['acknowledge', 'snooze', 'assign', 'open_record'],
                ], $now, $part['public_id'] ?? ($part['uuid'] ?? ''));
        }

        return $items;
    }

    /**
     * Fuel transactions the provider sync could not match to a vehicle.
     */
    public static function fuelItems(array $transactions, Carbon $now): array
    {
        $items = [];

        foreach ($transactions as $transaction) {
            if (($transaction['sync_status'] ?? 'unmatched') !== 'unmatched') {
                continue;
            }

            $amount  = self::money($transaction['amount'] ?? null, $transaction['currency'] ?? null);
            $station = $transaction['station_name'] ?? ($transaction['provider'] ?? 'fuel provider');
            $meta    = [];
            if (!empty($transaction['vehicle_card_id'])) {
                $meta[] = 'card ' . $transaction['vehicle_card_id'];
            }
            if (!empty($transaction['plate_number'])) {
                $meta[] = 'plate ' . $transaction['plate_number'];
            }
            if (!empty($transaction['suggested_vehicle']['label'])) {
                $meta[] = 'suggest: ' . $transaction['suggested_vehicle']['label'];
            }
            $when = self::carbon($transaction['transaction_at'] ?? null);
            if ($when) {
                $meta[] = self::agoWords($when, $now);
            }

            $subject = ['type' => 'fuel_transaction', 'class' => $transaction['class'] ?? null, 'uuid' => $transaction['uuid'] ?? null, 'public_id' => $transaction['public_id'] ?? null, 'label' => $station, 'photo_url' => null];
            $items[] = self::item('fuel_unmatched', $subject, sprintf('%s at %s — no vehicle matched', $amount, $station), [
                'severity'  => self::SEVERITY_WARNING,
                'meta_line' => implode(' · ', $meta) ?: null,
                'record'    => self::record('management.fuel-transactions.index.details', $transaction['public_id'] ?? null),
                'source'    => ['type' => 'fuel_transaction', 'uuid' => $transaction['uuid'] ?? null, 'public_id' => $transaction['public_id'] ?? null, 'suggested_vehicle' => $transaction['suggested_vehicle'] ?? null],
                'actions'   => ['match_vehicle', 'ignore_transaction', 'acknowledge', 'snooze', 'assign', 'open_record'],
            ], $now, $transaction['public_id'] ?? ($transaction['uuid'] ?? ''));
        }

        return $items;
    }

    /**
     * Notices are the one kind of item a person writes rather than a rule
     * finds. They arrive as alert rows.
     */
    public static function noticeItems(array $notices, Carbon $now): array
    {
        $items = [];

        foreach ($notices as $notice) {
            if (($notice['status'] ?? 'open') === 'resolved') {
                continue;
            }

            $due     = self::carbon($notice['meta']['due_at'] ?? null);
            $subject = $notice['subject'] ?? null;
            $items[] = self::item('notice', $subject, (string) ($notice['message'] ?? 'Notice'), [
                'severity'  => in_array($notice['severity'] ?? 'info', [self::SEVERITY_CRITICAL, self::SEVERITY_WARNING, self::SEVERITY_INFO], true) ? $notice['severity'] : self::SEVERITY_INFO,
                'due_at'    => $due,
                'meta_line' => $notice['meta']['scope'] ?? null,
                'record'    => null,
                'source'    => ['type' => 'notice', 'uuid' => $notice['uuid'] ?? null, 'public_id' => $notice['public_id'] ?? null],
                'actions'   => ['resolve', 'acknowledge', 'snooze', 'assign'],
                'state'     => $notice['state'] ?? null,
            ], $now, $notice['public_id'] ?? ($notice['uuid'] ?? ''));
        }

        return $items;
    }

    // ------------------------------------------------------------------
    // State, filtering, sorting, counting
    // ------------------------------------------------------------------

    /**
     * Attach each item's alert state, keyed by item key. Items with no row
     * get the open default.
     */
    public static function mergeStates(array $items, array $states, Carbon $now): array
    {
        foreach ($items as &$item) {
            $state         = $item['state'] ?? ($states[$item['key']] ?? null);
            $item['state'] = self::normalizeState($state, $now);
        }
        unset($item);

        return $items;
    }

    /**
     * A state row as the console reads it: the stored status, refined by
     * whether the snooze has ended.
     */
    public static function normalizeState(?array $state, Carbon $now): array
    {
        $state  = $state ?? [];
        $until  = self::carbon($state['snoozed_until'] ?? null);
        $status = $state['status'] ?? 'open';

        if ($until && $until->gt($now)) {
            $status = 'snoozed';
        } elseif ($status === 'snoozed') {
            $status = !empty($state['acknowledged_at']) ? 'acknowledged' : 'open';
        }

        return [
            'status'               => $status,
            'alert_id'             => $state['alert_id'] ?? null,
            'acknowledged_at'      => $state['acknowledged_at'] ?? null,
            'acknowledged_by_name' => $state['acknowledged_by_name'] ?? null,
            'snoozed_until'        => $until && $until->gt($now) ? $until->toIso8601String() : null,
            'snoozed_by_name'      => $until && $until->gt($now) ? ($state['snoozed_by_name'] ?? null) : null,
            'assigned_to'          => $state['assigned_to'] ?? null,
            'planned_at'           => $state['planned_at'] ?? null,
            'resolved_at'          => $state['resolved_at'] ?? null,
            'resolved_by_name'     => $state['resolved_by_name'] ?? null,
            'resolution'           => $state['resolution'] ?? null,
            'triggered_at'         => $state['triggered_at'] ?? null,
        ];
    }

    public static function isOpen(array $item, Carbon $now): bool
    {
        return in_array($item['state']['status'] ?? 'open', ['open', 'acknowledged'], true);
    }

    public static function isSnoozed(array $item, Carbon $now): bool
    {
        return ($item['state']['status'] ?? 'open') === 'snoozed';
    }

    /**
     * Keep the items for a status tab.
     */
    public static function forTab(array $items, string $tab, Carbon $now): array
    {
        return array_values(array_filter($items, function ($item) use ($tab, $now) {
            return match ($tab) {
                'snoozed'  => self::isSnoozed($item, $now),
                'resolved' => ($item['state']['status'] ?? null) === 'resolved',
                default    => self::isOpen($item, $now),
            };
        }));
    }

    /**
     * Keep the items every one of the given pills matches (pills AND).
     */
    public static function forPills(array $items, array $pills): array
    {
        $pills = array_values(array_filter(array_map('trim', $pills), fn ($pill) => isset(self::PILLS[$pill])));
        if (!$pills) {
            return array_values($items);
        }

        return array_values(array_filter($items, function ($item) use ($pills) {
            foreach ($pills as $pill) {
                if (!in_array($pill, $item['pills'] ?? [], true)) {
                    return false;
                }
            }

            return true;
        }));
    }

    /**
     * Keep the items whose title, subject, chip or meta line mentions the query.
     */
    public static function search(array $items, ?string $query): array
    {
        $query = trim((string) $query);
        if ($query === '') {
            return array_values($items);
        }

        $needle = Str::lower($query);

        return array_values(array_filter($items, function ($item) use ($needle) {
            $haystack = Str::lower(implode(' ', array_filter([
                $item['title'] ?? '',
                $item['subject']['label'] ?? '',
                $item['subject']['public_id'] ?? '',
                $item['chip'] ?? '',
                $item['meta_line'] ?? '',
                $item['rule'] ?? '',
            ])));

            return str_contains($haystack, $needle);
        }));
    }

    /**
     * Keep the items whose subject belongs to a fleet.
     */
    public static function forFleet(array $items, ?string $fleet): array
    {
        $fleet = trim((string) $fleet);
        if ($fleet === '' || $fleet === 'all') {
            return array_values($items);
        }

        return array_values(array_filter($items, fn ($item) => in_array($fleet, $item['subject']['fleets'] ?? [], true)));
    }

    /**
     * Keep the items of one category.
     */
    public static function forCategory(array $items, ?string $category): array
    {
        $category = trim((string) $category);
        if ($category === '') {
            return array_values($items);
        }

        return array_values(array_filter($items, fn ($item) => ($item['category'] ?? null) === $category));
    }

    /**
     * Keep the items assigned to a user.
     */
    public static function forAssignee(array $items, ?string $userUuid): array
    {
        if (!$userUuid) {
            return [];
        }

        return array_values(array_filter($items, fn ($item) => ($item['state']['assigned_to']['uuid'] ?? null) === $userUuid));
    }

    /**
     * Count the pills over a set of items.
     */
    public static function counts(array $items): array
    {
        $counts = array_fill_keys(array_keys(self::PILLS), 0);

        foreach ($items as $item) {
            foreach ($item['pills'] ?? [] as $pill) {
                $counts[$pill]++;
            }
        }

        return $counts;
    }

    /**
     * The numbers the header and widget show.
     */
    public static function summary(array $items, Carbon $now): array
    {
        $open     = array_filter($items, fn ($item) => self::isOpen($item, $now));
        $snoozed  = array_filter($items, fn ($item) => self::isSnoozed($item, $now));

        return [
            'open'         => count($open),
            'acknowledged' => count(array_filter($open, fn ($item) => $item['state']['status'] === 'acknowledged')),
            'snoozed'      => count($snoozed),
            'overdue'      => count(array_filter($open, fn ($item) => $item['due_bucket'] === 'overdue')),
            'critical'     => count(array_filter($open, fn ($item) => $item['severity'] === self::SEVERITY_CRITICAL)),
            'undated'      => count(array_filter($open, fn ($item) => $item['due_bucket'] === 'none')),
        ];
    }

    /**
     * Overdue first (oldest first), then today, this week and later by due
     * date, then undated by severity.
     */
    public static function sort(array $items): array
    {
        $bucketOrder   = ['overdue' => 0, 'today' => 1, 'week' => 2, 'later' => 3, 'none' => 4];
        $severityOrder = [self::SEVERITY_CRITICAL => 0, self::SEVERITY_WARNING => 1, self::SEVERITY_INFO => 2];

        usort($items, function ($a, $b) use ($bucketOrder, $severityOrder) {
            $bucket = ($bucketOrder[$a['due_bucket'] ?? 'none'] ?? 9) <=> ($bucketOrder[$b['due_bucket'] ?? 'none'] ?? 9);
            if ($bucket !== 0) {
                return $bucket;
            }

            if (($a['due_bucket'] ?? 'none') !== 'none') {
                $due = strcmp((string) $a['due_at'], (string) $b['due_at']);
                if ($due !== 0) {
                    return $due;
                }
            }

            $severity = ($severityOrder[$a['severity'] ?? ''] ?? 9) <=> ($severityOrder[$b['severity'] ?? ''] ?? 9);
            if ($severity !== 0) {
                return $severity;
            }

            return strcmp((string) ($a['title'] ?? ''), (string) ($b['title'] ?? ''));
        });

        return array_values($items);
    }

    /**
     * Group sorted items by due bucket, in display order, dropping empty groups.
     */
    public static function group(array $items): array
    {
        $groups = [];
        foreach (['overdue', 'today', 'week', 'later', 'none'] as $bucket) {
            $members = array_values(array_filter($items, fn ($item) => ($item['due_bucket'] ?? 'none') === $bucket));
            if ($members) {
                $groups[] = ['key' => $bucket, 'count' => count($members), 'items' => $members];
            }
        }

        return $groups;
    }

    /**
     * A page of items.
     */
    public static function paginate(array $items, int $page, int $limit): array
    {
        $page  = max(1, $page);
        $limit = max(1, min(200, $limit));
        $total = count($items);

        return [
            'items' => array_slice(array_values($items), ($page - 1) * $limit, $limit),
            'meta'  => ['total' => $total, 'page' => $page, 'limit' => $limit, 'pages' => (int) ceil($total / $limit)],
        ];
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * The stable key for a rule applied to a record.
     */
    public static function keyFor(string $rule, string $publicId): string
    {
        return $rule . ':' . $publicId;
    }

    /**
     * Split a key back into its rule and public id.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function parseKey(?string $key): ?array
    {
        $key = (string) $key;
        $at  = strpos($key, ':');
        if ($at === false) {
            return null;
        }

        $rule     = substr($key, 0, $at);
        $publicId = substr($key, $at + 1);

        if (!isset(self::RULES[$rule]) || $publicId === '') {
            return null;
        }

        return [$rule, $publicId];
    }

    /**
     * The rules a state row can carry: every rule but the manual notice,
     * which is a row already.
     */
    public static function stateTypes(): array
    {
        return array_values(array_filter(array_keys(self::RULES), fn ($rule) => $rule !== 'notice'));
    }

    /**
     * The threshold a part is low at: the reorder point when set, else the
     * spec's low-stock threshold, else the default.
     */
    public static function lowStockThreshold(array $part): int
    {
        $reorderPoint = (int) ($part['reorder_point'] ?? 0);
        if ($reorderPoint > 0) {
            return $reorderPoint;
        }

        $specs = $part['specs'] ?? [];
        if (is_string($specs)) {
            $specs = json_decode($specs, true) ?: [];
        }
        $specThreshold = (int) ($specs['low_stock_threshold'] ?? 0);

        return $specThreshold > 0 ? $specThreshold : self::DEFAULT_LOW_STOCK;
    }

    /**
     * Which due bucket a date falls in.
     */
    public static function bucket(?Carbon $due, Carbon $now): string
    {
        if (!$due) {
            return 'none';
        }
        if ($due->lt($now)) {
            return 'overdue';
        }
        if ($due->isSameDay($now)) {
            return 'today';
        }
        if ($due->lte($now->copy()->addDays(self::DUE_SOON_DAYS))) {
            return 'week';
        }

        return 'later';
    }

    /**
     * The short due text a row shows.
     */
    public static function dueLabel(?Carbon $due, Carbon $now): ?string
    {
        if (!$due) {
            return null;
        }

        return match (self::bucket($due, $now)) {
            'overdue' => self::overdueWords($due, $now),
            'today'   => 'Today ' . $due->format('H:i'),
            'week'    => $due->format('D j M'),
            default   => $due->format('j M'),
        };
    }

    public static function overdueWords(Carbon $due, Carbon $now): string
    {
        $minutes = $due->diffInMinutes($now);
        if ($minutes < 60) {
            return max(1, $minutes) . 'm overdue';
        }
        $hours = $due->diffInHours($now);
        if ($hours < 24) {
            return $hours . 'h overdue';
        }

        return $due->diffInDays($now) . 'd overdue';
    }

    public static function dueInWords(Carbon $due, Carbon $now): string
    {
        if ($due->isSameDay($now)) {
            return 'today';
        }
        if ($due->isSameDay($now->copy()->addDay())) {
            return 'tomorrow';
        }

        $days = $now->copy()->startOfDay()->diffInDays($due->copy()->startOfDay());
        if ($days <= self::DUE_SOON_DAYS) {
            return sprintf('in %d day%s', $days, $days === 1 ? '' : 's');
        }

        return $due->format('j M');
    }

    /**
     * "35m ago", "5h ago", "2d ago".
     */
    public static function agoWords(Carbon $then, Carbon $now): string
    {
        $minutes = max(0, $then->diffInMinutes($now, false));
        if ($minutes < 60) {
            return max(1, $minutes) . 'm ago';
        }
        if ($minutes < 1440) {
            return intdiv($minutes, 60) . 'h ago';
        }

        return intdiv($minutes, 1440) . 'd ago';
    }

    public static function minutesWords(int $minutes): string
    {
        $minutes = max(0, $minutes);
        if ($minutes < 60) {
            return $minutes . 'm';
        }

        $hours = intdiv($minutes, 60);
        $rest  = $minutes % 60;

        return $rest ? sprintf('%dh %dm', $hours, $rest) : $hours . 'h';
    }

    /**
     * Accepts a Carbon, a DateTime, or a string; anything else is null.
     */
    public static function carbon(mixed $value): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value->copy();
        }
        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value);
        }
        if (is_string($value) && trim($value) !== '') {
            try {
                return Carbon::parse($value);
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    /**
     * The route the "open record" link goes to.
     */
    public static function record(?string $route, ?string $model): ?array
    {
        if (!$route || !$model) {
            return null;
        }

        return ['route' => $route, 'model' => $model];
    }

    protected static function item(string $rule, ?array $subject, string $title, array $extra, Carbon $now, string $publicId): array
    {
        $meta      = self::RULES[$rule];
        $due       = self::carbon($extra['due_at'] ?? null);
        $bucket    = self::bucket($due, $now);
        $subject   = $subject ? self::subject($subject) : null;
        $item      = [
            'key'        => self::keyFor($rule, $publicId),
            'rule'       => $rule,
            'category'   => $meta['category'],
            'lane'       => $due || !empty($extra['window']) ? $meta['lane'] : self::LANE_ANYTIME,
            'chip'       => $meta['chip'],
            'severity'   => $extra['severity'] ?? self::SEVERITY_INFO,
            'title'      => $title,
            'subject'    => $subject,
            'meta_line'  => $extra['meta_line'] ?? null,
            'due_at'     => $due?->toIso8601String(),
            'due_bucket' => $bucket,
            'due_label'  => self::dueLabel($due, $now),
            'window'     => $extra['window'] ?? null,
            'planned_at' => null,
            'record'     => $extra['record'] ?? null,
            'source'     => $extra['source'] ?? null,
            'details'    => $extra['details'] ?? null,
            'actions'    => array_values($extra['actions'] ?? ['acknowledge', 'snooze', 'assign']),
            'state'      => $extra['state'] ?? null,
        ];

        $item['pills'] = self::pillsFor($item);

        return $item;
    }

    protected static function pillsFor(array $item): array
    {
        $pills = [];
        foreach (self::PILLS as $pill => $definition) {
            if (in_array($item['rule'], $definition['rules'] ?? [], true) || in_array($item['due_bucket'], $definition['buckets'] ?? [], true)) {
                $pills[] = $pill;
            }
        }

        return $pills;
    }

    protected static function subject(array $subject): array
    {
        return [
            'type'      => $subject['type'] ?? null,
            'class'     => $subject['class'] ?? null,
            'uuid'      => $subject['uuid'] ?? null,
            'public_id' => $subject['public_id'] ?? null,
            'label'     => $subject['label'] ?? ($subject['public_id'] ?? ''),
            'photo_url' => $subject['photo_url'] ?? null,
            'phone'     => $subject['phone'] ?? null,
            'fleets'    => array_values($subject['fleets'] ?? []),
        ];
    }

    protected static function driverSubject(array $driver): array
    {
        return [
            'type'      => 'driver',
            'class'     => $driver['class'] ?? null,
            'uuid'      => $driver['uuid'] ?? null,
            'public_id' => $driver['public_id'] ?? null,
            'label'     => $driver['name'] ?? ($driver['label'] ?? ($driver['public_id'] ?? 'Driver')),
            'photo_url' => $driver['photo_url'] ?? null,
            'phone'     => $driver['phone'] ?? null,
            'fleets'    => $driver['fleets'] ?? [],
        ];
    }

    protected static function vehicleSubject(array $vehicle): array
    {
        return [
            'type'      => 'vehicle',
            'class'     => $vehicle['class'] ?? null,
            'uuid'      => $vehicle['uuid'] ?? null,
            'public_id' => $vehicle['public_id'] ?? null,
            'label'     => $vehicle['label'] ?? ($vehicle['display_name'] ?? ($vehicle['public_id'] ?? 'Vehicle')),
            'photo_url' => $vehicle['photo_url'] ?? null,
            'fleets'    => $vehicle['fleets'] ?? [],
        ];
    }

    protected static function leaseItem(array $subject, Carbon $lease, ?array $record, array $source, Carbon $now, string $publicId): array
    {
        $expired = $lease->lt($now->copy()->startOfDay());

        return self::item('lease_expiring', $subject, $expired
            ? sprintf('%s lease ended %s', $subject['label'], $lease->format('j M'))
            : sprintf('%s lease ends %s', $subject['label'], self::dueInWords($lease, $now)), [
                'severity' => $expired ? self::SEVERITY_CRITICAL : self::SEVERITY_WARNING,
                'due_at'   => $lease,
                'record'   => $record,
                'source'   => $source,
                'actions'  => ['acknowledge', 'snooze', 'assign', 'open_record'],
            ], $now, $publicId);
    }

    /**
     * The code of the first open work order whose checklist names any of the
     * given words, or null.
     */
    protected static function workOrderNaming(array $workOrders, array $words): ?string
    {
        $words = array_values(array_filter(array_map(fn ($word) => Str::lower(trim((string) $word)), $words)));
        if (!$words) {
            return null;
        }

        foreach ($workOrders as $workOrder) {
            if (in_array($workOrder['status'] ?? 'open', self::WORK_ORDER_CLOSED, true)) {
                continue;
            }

            $checklist = $workOrder['checklist'] ?? [];
            if (is_string($checklist)) {
                $checklist = json_decode($checklist, true) ?: [];
            }

            $text = Str::lower(implode(' ', array_map(fn ($entry) => is_array($entry) ? ($entry['title'] ?? '') : (string) $entry, $checklist)) . ' ' . ($workOrder['subject'] ?? ''));

            foreach ($words as $word) {
                if ($word !== '' && str_contains($text, $word)) {
                    return $workOrder['code'] ?? ($workOrder['public_id'] ?? null);
                }
            }
        }

        return null;
    }

    /**
     * The company vehicle a fuel transaction's identity columns point at, or
     * null. Reports which field matched so the console can say why.
     *
     * @param array $vehicles rows with uuid, public_id, label, plate_number, vin, fuel_card_number
     */
    public static function suggestVehicleForFuel(array $transaction, array $vehicles): ?array
    {
        $candidates = [
            'fuel_card_number' => $transaction['vehicle_card_id'] ?? null,
            'plate_number'     => $transaction['plate_number'] ?? null,
            'vin'              => $transaction['vin'] ?? null,
        ];

        foreach ($candidates as $field => $value) {
            $value = Str::lower(trim((string) $value));
            if ($value === '') {
                continue;
            }

            foreach ($vehicles as $vehicle) {
                if (Str::lower(trim((string) ($vehicle[$field] ?? ''))) === $value) {
                    return [
                        'uuid'      => $vehicle['uuid'] ?? null,
                        'public_id' => $vehicle['public_id'] ?? null,
                        'label'     => $vehicle['label'] ?? ($vehicle['public_id'] ?? ''),
                        'matched'   => $field,
                    ];
                }
            }
        }

        return null;
    }

    protected static function money(mixed $amount, ?string $currency): string
    {
        if ($amount === null || $amount === '') {
            return 'Fuel purchase';
        }

        $value = (float) $amount;
        // Provider amounts are stored in minor units when they are integers.
        if (is_int($amount) || (is_string($amount) && ctype_digit($amount))) {
            $value = $value / 100;
        }

        return trim(($currency ? strtoupper($currency) . ' ' : '') . number_format($value, 2));
    }
}
