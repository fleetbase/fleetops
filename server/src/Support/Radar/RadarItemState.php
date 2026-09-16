<?php

namespace Fleetbase\FleetOps\Support\Radar;

use Fleetbase\Models\Alert;
use Fleetbase\Models\User;
use Illuminate\Support\Carbon;

/**
 * The one Radar helper that touches the database: it keeps each item's
 * acknowledge / snooze / assign / resolve state on the core `alerts` table.
 *
 * Items are computed live from the resource tables; a row here exists only
 * once somebody acted on an item. The row is found by the item key it was
 * created for (`context.key`), so a rule can change how it describes a record
 * without losing the record's state.
 *
 * State is written column by column and user names are looked up by uuid,
 * rather than through `Alert::snooze()`, `assignTo()` or the `assignedTo`
 * and `snoozedBy` relations. Those arrived in a later core-api than the one
 * FleetOps is locked to, so leaning on them would break Radar wherever the
 * older core is installed; the columns themselves come from core's migration.
 */
class RadarItemState
{
    /** The alert columns that point at a user, whose names a state row shows. */
    public const USER_COLUMNS = ['acknowledged_by_uuid', 'resolved_by_uuid', 'snoozed_by_uuid', 'assigned_to_uuid'];

    /**
     * Every live state row for the company, keyed by item key.
     *
     * @return array<string, array>
     */
    public static function statesFor(?string $companyUuid): array
    {
        $rows   = self::rows($companyUuid)->get();
        $users  = self::usersFor($rows);
        $states = [];

        foreach ($rows as $alert) {
            $key = self::keyOf($alert);
            if ($key) {
                $states[$key] = self::toState($alert, $users);
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

    // ------------------------------------------------------------------
    // Writing state
    // ------------------------------------------------------------------

    /**
     * Mark a row acknowledged by a user. An already acknowledged row keeps
     * who acknowledged it first.
     */
    public static function acknowledge(Alert $alert, ?User $user): Alert
    {
        if (RadarRules::carbon($alert->getAttribute('acknowledged_at'))) {
            return $alert;
        }

        $attributes = ['acknowledged_at' => now(), 'acknowledged_by_uuid' => $user?->uuid];
        if (($alert->status ?? 'open') === 'open') {
            $attributes['status'] = 'acknowledged';
        }

        return self::write($alert, $attributes);
    }

    /**
     * Snooze a row until a moment, noting who did it and why.
     */
    public static function snooze(Alert $alert, Carbon $until, ?string $reason, ?User $user): Alert
    {
        return self::write($alert, [
            'snoozed_until'   => $until,
            'snoozed_by_uuid' => $user?->uuid,
            'meta'            => array_merge($alert->meta ?? [], ['snooze_reason' => $reason]),
        ]);
    }

    /**
     * End a snooze early.
     */
    public static function wake(Alert $alert): Alert
    {
        return self::write($alert, ['snoozed_until' => null, 'snoozed_by_uuid' => null]);
    }

    /**
     * Give a row an owner, or clear it with null.
     */
    public static function assign(Alert $alert, ?User $user): Alert
    {
        return self::write($alert, ['assigned_to_uuid' => $user?->uuid]);
    }

    /**
     * Set the time the owner plans to deal with a row, or clear it.
     */
    public static function plan(Alert $alert, ?Carbon $at): Alert
    {
        return self::write($alert, ['planned_at' => $at]);
    }

    /**
     * Resolve a row, recording who resolved it and how.
     */
    public static function resolve(Alert $alert, ?User $user, ?string $resolution): Alert
    {
        return self::write($alert, [
            'status'           => 'resolved',
            'resolved_at'      => now(),
            'resolved_by_uuid' => $user?->uuid,
            'meta'             => array_merge($alert->meta ?? [], ['resolution' => $resolution]),
        ]);
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

            self::resolve($alert, null, 'auto');
            $closed++;
        }

        return $closed;
    }

    // ------------------------------------------------------------------
    // Reading state
    // ------------------------------------------------------------------

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
            ->orderByDesc('resolved_at')
            ->get();
        $users = self::usersFor($rows);

        foreach ($rows as $alert) {
            $context         = $alert->context ?? [];
            $rule            = $alert->type === RadarRules::NOTICE_TYPE ? 'notice' : $alert->type;
            $meta            = RadarRules::RULES[$rule] ?? ['category' => 'notices', 'lane' => 'anytime', 'chip' => 'Notice'];
            $due             = RadarRules::carbon($context['due_at'] ?? null);
            $state           = self::toState($alert, $users);
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
                'meta_line'  => $state['resolution'] === 'auto' ? 'closed on the record' : ('resolved by ' . ($state['resolved_by_name'] ?? 'you')),
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
                'snoozed_until' => self::iso($alert, 'snoozed_until'),
            ])
            ->values()
            ->all();
    }

    /**
     * Notices as source rows for RadarRules::noticeItems().
     */
    public static function notices(?string $companyUuid): array
    {
        $rows  = self::rows($companyUuid, [RadarRules::NOTICE_TYPE])->orderByDesc('created_at')->get();
        $users = self::usersFor($rows);

        return $rows
            ->map(function (Alert $alert) use ($users) {
                $context = $alert->context ?? [];

                return [
                    'uuid'      => $alert->uuid,
                    'public_id' => $alert->public_id,
                    'message'   => $alert->message,
                    'severity'  => $alert->severity,
                    'status'    => $alert->status,
                    'meta'      => $alert->meta ?? [],
                    'subject'   => $context['subject'] ?? null,
                    'state'     => self::toState($alert, $users),
                ];
            })
            ->all();
    }

    /**
     * The users the given rows point at, keyed by uuid.
     *
     * @param iterable<Alert> $alerts
     *
     * @return array<string, array{uuid: string, public_id: ?string, name: ?string}>
     */
    public static function usersFor(iterable $alerts): array
    {
        $uuids = [];
        foreach ($alerts as $alert) {
            foreach (self::USER_COLUMNS as $column) {
                $uuid = $alert->getAttribute($column);
                if (is_string($uuid) && $uuid !== '') {
                    $uuids[$uuid] = true;
                }
            }
        }

        if (!$uuids) {
            return [];
        }

        return User::query()
            ->whereIn('uuid', array_keys($uuids))
            ->get(['uuid', 'public_id', 'name'])
            ->mapWithKeys(fn (User $user) => [$user->uuid => ['uuid' => $user->uuid, 'public_id' => $user->public_id, 'name' => $user->name]])
            ->all();
    }

    /**
     * A row as the console reads it.
     *
     * @param array<string, array> $users the users the row points at, from usersFor()
     */
    public static function toState(Alert $alert, array $users = []): array
    {
        $assignee = $users[$alert->getAttribute('assigned_to_uuid')] ?? null;
        $name     = fn (string $column) => $users[$alert->getAttribute($column)]['name'] ?? null;

        return [
            'status'               => $alert->status ?? 'open',
            'alert_id'             => $alert->public_id ?? $alert->uuid,
            'acknowledged_at'      => self::iso($alert, 'acknowledged_at'),
            'acknowledged_by_name' => $name('acknowledged_by_uuid'),
            'snoozed_until'        => self::iso($alert, 'snoozed_until'),
            'snoozed_by_name'      => $name('snoozed_by_uuid'),
            'assigned_to'          => $assignee ? $assignee + ['initials' => self::initials($assignee['name'])] : null,
            'planned_at'           => self::iso($alert, 'planned_at'),
            'resolved_at'          => self::iso($alert, 'resolved_at'),
            'resolved_by_name'     => $name('resolved_by_uuid'),
            'resolution'           => $alert->meta['resolution'] ?? null,
            'triggered_at'         => self::iso($alert, 'triggered_at'),
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
     * A row after a write, as the database now has it.
     */
    public static function refresh(Alert $alert): Alert
    {
        return ($alert->exists ? $alert->fresh() : null) ?? $alert;
    }

    /**
     * Live rows for the company: every Radar type, not resolved.
     */
    protected static function rows(?string $companyUuid, ?array $types = null)
    {
        return Alert::query()
            ->where('company_uuid', $companyUuid)
            ->whereIn('type', $types ?? array_merge(RadarRules::stateTypes(), [RadarRules::NOTICE_TYPE]))
            ->where('status', '!=', 'resolved');
    }

    /**
     * Write columns straight onto the row: some of them are not fillable on
     * every core-api the model may come from.
     */
    protected static function write(Alert $alert, array $attributes): Alert
    {
        $alert->forceFill($attributes)->save();

        return $alert;
    }

    /**
     * A timestamp column as ISO 8601, whether or not the model casts it.
     */
    protected static function iso(Alert $alert, string $column): ?string
    {
        return RadarRules::carbon($alert->getAttribute($column))?->toIso8601String();
    }
}
