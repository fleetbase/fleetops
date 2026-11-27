<?php

namespace Fleetbase\FleetOps\Orchestration\Support;

/**
 * Why each order a plan run left out was left out, keyed by order public id.
 *
 * Engines report this unevenly: the capacity engine lists a code per order in
 * `summary.unassigned_reasons`, VROOM adds orders it could not route to
 * `summary.invalid` with a sentence, and the greedy engine and VROOM's own
 * solver say only that an order is unassigned. This turns all of them into one
 * shape a dispatcher can act on:
 *
 *   { "order_x": { "reason": "capacity_exceeded", "message": "..." } }
 *
 * Every id in `unassigned` gets an entry. An order no engine explained gets the
 * running engine's usual cause, or `not_placed`.
 */
class UnassignedReasons
{
    /**
     * Messages for the reason codes engines report. A code outside this list
     * is passed through with a generic message.
     */
    public const MESSAGES = [
        'no_available_vehicle'    => 'No vehicle was available for this order.',
        'no_available_driver'     => 'No driver was available for this order.',
        'no_matching_driver'      => 'No available driver matches this order\'s vehicle, skills or shift.',
        'no_vehicle_assigned'     => 'The order has no vehicle, so its stops could not be sequenced.',
        'capacity_exceeded'       => 'The order does not fit in the remaining capacity of any vehicle.',
        'missing_required_skills' => 'No vehicle\'s driver has the skills this order requires.',
        'max_tasks_exceeded'      => 'Every vehicle has reached its maximum number of stops.',
        'not_feasible'            => 'The engine could not fit this order into any route within its time windows, capacity and shifts.',
        'invalid_order'           => 'The order is missing data the engine needs, such as coordinates or a payload.',
        'invalid_task'            => 'The order could not be planned.',
        'not_placed'              => 'The engine did not place this order.',
    ];

    /**
     * The cause an engine implies when it lists an order as unassigned without
     * saying why.
     */
    public const ENGINE_DEFAULTS = [
        'greedy'            => 'no_available_vehicle',
        'capacity'          => 'no_available_vehicle',
        'driver_assignment' => 'no_matching_driver',
        'route_sequencing'  => 'no_vehicle_assigned',
        'vroom'             => 'not_feasible',
    ];

    /**
     * @param array       $result an engine result: { assignments, unassigned, summary }
     * @param string|null $engine the engine id that ran, to choose the default reason
     *
     * @return array<string, array{reason: string, message: string}>
     */
    public static function fromResult(array $result, ?string $engine = null): array
    {
        $summary  = is_array($result['summary'] ?? null) ? $result['summary'] : [];
        $engine ??= $summary['engine'] ?? null;
        $reasons  = [];

        foreach (['unassigned_reasons', 'invalid'] as $key) {
            foreach ((array) ($summary[$key] ?? []) as $entry) {
                if (!is_array($entry) || empty($entry['order_id'])) {
                    continue;
                }

                $reasons[(string) $entry['order_id']] = static::describe($entry['reason'] ?? null);
            }
        }

        $default = static::ENGINE_DEFAULTS[$engine] ?? 'not_placed';
        $keyed   = [];
        foreach ((array) ($result['unassigned'] ?? []) as $orderId) {
            if (!is_scalar($orderId) || $orderId === '') {
                continue;
            }

            $orderId         = (string) $orderId;
            $keyed[$orderId] = $reasons[$orderId] ?? static::describe($default);
        }

        return $keyed;
    }

    /**
     * Give every id the same reason; used when a run stops before any engine
     * runs (for example, no vehicles).
     *
     * @return array<string, array{reason: string, message: string}>
     */
    public static function all(iterable $orderIds, string $reason): array
    {
        $keyed = [];
        foreach ($orderIds as $orderId) {
            $keyed[(string) $orderId] = static::describe($reason);
        }

        return $keyed;
    }

    /**
     * A code becomes { reason: code, message }; a sentence (what the payload
     * builder writes for an order without coordinates or a payload) becomes
     * `invalid_order` with the sentence as its message.
     */
    public static function describe(?string $reason): array
    {
        $reason = trim((string) $reason);
        if ($reason === '') {
            $reason = 'not_placed';
        }

        if (preg_match('/^[a-z][a-z0-9_]*$/', $reason)) {
            return ['reason' => $reason, 'message' => static::MESSAGES[$reason] ?? static::MESSAGES['not_placed']];
        }

        return ['reason' => 'invalid_order', 'message' => $reason];
    }
}
