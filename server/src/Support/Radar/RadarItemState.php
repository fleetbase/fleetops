<?php

namespace Fleetbase\FleetOps\Support\Radar;

use Fleetbase\Models\Alert;
use Illuminate\Support\Carbon;

/**
 * The one Radar helper that touches the database: it keeps each item's
 * acknowledge / snooze / assign / resolve state on the core `alerts` table.
 *
 * Items are computed live from the resource tables; a row here exists only
 * once somebody acted on an item. The row is found by the item key it was
 * created for (`context.key`), so a rule can change how it describes a record
 * without losing the record's state.
 */
class RadarItemState
{
    /**
     * Every live state row for the company, keyed by item key.
     *
     * @return array<string, array>
     */
    public static function statesFor(?string $companyUuid): array
    {
        $states = [];

        foreach (self::rows($companyUuid)->get() as $alert) {
            $key = self::keyOf($alert);
            if ($key) {
                $states[$key] = self::toState($alert);
            }
        }

        return $states;
    }

    /**
     * The row for an item, creating an open one when none exists.
     */
    public static function rowFor(?string $companyUuid, array $item): Alert
    {
        $existing = self::findRow($companyUuid, $item['key']);
        if ($existing) {
            return $existing;
        }

        $subject = $item['subject'] ?? [];

        return Alert::create([
            'company_uuid' => $companyUuid,
            'type'         => $item['rule'],
            'severity'     => $item['severity'] ?? 'info',
            'status'       => 'open',
            'subject_type' => $subject['class'] ?? null,
            'subject_uuid' => $subject['uuid'] ?? null,
            'message'      => $item['title'] ?? '',
            'triggered_at' => now(),
            'context'      => [
                'key'      => $item['key'],
                'rule'     => $item['rule'],
                'category' => $item['category'] ?? null,
                'chip'     => $item['chip'] ?? null,
                'due_at'   => $item['due_at'] ?? null,
                'record'   => $item['record'] ?? null,
                'subject'  => $subject ? [
                    'type'      => $subject['type'] ?? null,
                    'public_id' => $subject['public_id'] ?? null,
                    'label'     => $subject['label'] ?? null,
                    'photo_url' => $subject['photo_url'] ?? null,
                ] : null,
            ],
        ]);
    }

    /**
     * The live (unresolved) row for an item key, or null.
     */
    public static function findRow(?string $companyUuid, string $key): ?Alert
    {
        $parsed = RadarRules::parseKey($key);
        if (!$parsed) {
            return null;
        }

        [$rule, $publicId] = $parsed;

        if ($rule === 'notice') {
            return self::rows($companyUuid, [RadarRules::NOTICE_TYPE])
                ->where(function ($query) use ($publicId) {
                    $query->where('public_id', $publicId)->orWhere('uuid', $publicId);
                })
                ->first();
        }

        foreach (self::rows($companyUuid, [$rule])->get() as $alert) {
            if (self::keyOf($alert) === $key) {
                return $alert;
            }
        }

        return null;
    }

    /**
     * Resolve the rows whose item no longer exists — the gap closed on the
     * record itself. Returns how many rows were closed.
     */
    public static function reconcile(?string $companyUuid, array $liveKeys): int
    {
        $live   = array_fill_keys($liveKeys, true);
        $closed = 0;

        foreach (self::rows($companyUuid)->get() as $alert) {
            $key = self::keyOf($alert);
            if (!$key || isset($live[$key])) {
                continue;
            }

            $alert->update([
                'status'      => 'resolved',
                'resolved_at' => now(),
                'meta'        => array_merge($alert->meta ?? [], ['resolution' => 'auto']),
            ]);
            $closed++;
        }

        return $closed;
    }

    /**
     * Items resolved since a moment, newest first, shaped like live items so
     * the Resolved tab can render them with the same row.
     */
    public static function resolvedSince(?string $companyUuid, Carbon $since, Carbon $now): array
    {
        $items = [];

        $rows = Alert::query()
            ->where('company_uuid', $companyUuid)
            ->whereIn('type', array_merge(RadarRules::stateTypes(), [RadarRules::NOTICE_TYPE]))
            ->where('status', 'resolved')
            ->where('resolved_at', '>=', $since)
            ->with(['resolvedBy', 'acknowledgedBy', 'assignedTo'])
            ->orderByDesc('resolved_at')
            ->get();

        foreach ($rows as $alert) {
            $context         = $alert->context ?? [];
            $rule            = $alert->type === RadarRules::NOTICE_TYPE ? 'notice' : $alert->type;
            $meta            = RadarRules::RULES[$rule] ?? ['category' => 'notices', 'lane' => 'anytime', 'chip' => 'Notice'];
            $due             = RadarRules::carbon($context['due_at'] ?? null);
            $state           = self::toState($alert);
            $state['status'] = 'resolved';

            $items[] = [
                'key'        => self::keyOf($alert) ?? RadarRules::keyFor($rule, $alert->public_id ?? $alert->uuid),
                'rule'       => $rule,
                'category'   => $context['category'] ?? $meta['category'],
                'lane'       => $meta['lane'],
                'chip'       => $context['chip'] ?? $meta['chip'],
                'severity'   => $alert->severity ?? 'info',
                'title'      => $alert->message ?? '',
                'subject'    => $context['subject'] ?? null,
                'meta_line'  => ($alert->meta['resolution'] ?? null) === 'auto' ? 'closed on the record' : ('resolved by ' . ($alert->resolved_by_name ?? 'you')),
                'due_at'     => $due?->toIso8601String(),
                'due_bucket' => 'none',
                'due_label'  => null,
                'window'     => null,
                'planned_at' => null,
                'record'     => $context['record'] ?? null,
                'source'     => null,
                'details'    => null,
                'actions'    => $rule === 'notice' ? [] : ['open_record'],
                'state'      => $state,
                'pills'      => [],
            ];
        }

        return $items;
    }

    /**
     * The snoozed items waking within a week, soonest first.
     */
    public static function snoozeSchedule(?string $companyUuid, Carbon $now): array
    {
        return self::rows($companyUuid)
            ->whereNotNull('snoozed_until')
            ->where('snoozed_until', '>', $now)
            ->where('snoozed_until', '<=', $now->copy()->addDays(7))
            ->orderBy('snoozed_until')
            ->get()
            ->map(fn (Alert $alert) => [
                'key'           => self::keyOf($alert),
                'title'         => $alert->message,
                'snoozed_until' => $alert->snoozed_until?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * Notices as source rows for RadarRules::noticeItems().
     */
    public static function notices(?string $companyUuid): array
    {
        return self::rows($companyUuid, [RadarRules::NOTICE_TYPE])
            ->orderByDesc('created_at')
            ->get()
            ->map(function (Alert $alert) {
                $context = $alert->context ?? [];

                return [
                    'uuid'      => $alert->uuid,
                    'public_id' => $alert->public_id,
                    'message'   => $alert->message,
                    'severity'  => $alert->severity,
                    'status'    => $alert->status,
                    'meta'      => $alert->meta ?? [],
                    'subject'   => $context['subject'] ?? null,
                    'state'     => self::toState($alert),
                ];
            })
            ->all();
    }

    /**
     * A row as the console reads it.
     */
    public static function toState(Alert $alert): array
    {
        $assignee = $alert->assignedTo;

        return [
            'status'               => $alert->status ?? 'open',
            'alert_id'             => $alert->public_id ?? $alert->uuid,
            'acknowledged_at'      => $alert->acknowledged_at?->toIso8601String(),
            'acknowledged_by_name' => $alert->acknowledged_by_name,
            'snoozed_until'        => $alert->snoozed_until?->toIso8601String(),
            'snoozed_by_name'      => $alert->snoozedBy?->name,
            'assigned_to'          => $assignee ? ['uuid' => $assignee->uuid, 'public_id' => $assignee->public_id ?? null, 'name' => $assignee->name, 'initials' => self::initials($assignee->name)] : null,
            'planned_at'           => $alert->planned_at?->toIso8601String(),
            'resolved_at'          => $alert->resolved_at?->toIso8601String(),
            'resolved_by_name'     => $alert->resolved_by_name,
            'resolution'           => $alert->meta['resolution'] ?? null,
            'triggered_at'         => $alert->triggered_at?->toIso8601String(),
        ];
    }

    /**
     * The item key a row was created for.
     */
    public static function keyOf(Alert $alert): ?string
    {
        $key = $alert->context['key'] ?? null;
        if (is_string($key) && $key !== '') {
            return $key;
        }

        if ($alert->type === RadarRules::NOTICE_TYPE && ($alert->public_id || $alert->uuid)) {
            return RadarRules::keyFor('notice', $alert->public_id ?? $alert->uuid);
        }

        return null;
    }

    public static function initials(?string $name): string
    {
        $parts = array_values(array_filter(explode(' ', trim((string) $name))));
        if (!$parts) {
            return '';
        }

        $first = mb_substr($parts[0], 0, 1);
        $last  = count($parts) > 1 ? mb_substr($parts[count($parts) - 1], 0, 1) : '';

        return mb_strtoupper($first . $last);
    }

    /**
     * Live rows for the company: every Radar type, not resolved.
     */
    protected static function rows(?string $companyUuid, ?array $types = null)
    {
        return Alert::query()
            ->where('company_uuid', $companyUuid)
            ->whereIn('type', $types ?? array_merge(RadarRules::stateTypes(), [RadarRules::NOTICE_TYPE]))
            ->where('status', '!=', 'resolved')
            ->with(['acknowledgedBy', 'assignedTo', 'snoozedBy']);
    }
}
