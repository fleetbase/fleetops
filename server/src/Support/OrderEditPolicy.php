<?php

namespace Fleetbase\FleetOps\Support;

use Fleetbase\FleetOps\Exceptions\OrderEditLockedException;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\OrderConfig;
use Illuminate\Support\Carbon;

/**
 * Decides which parts of an order may still be edited at its current status.
 *
 * The policy groups statuses into four stages:
 *
 * - open (created, pending, accepted/confirmed, preparing): everything is editable.
 * - ready (ready, pickup_ready): route, contact, notes and cash to collect are editable;
 *   items may only be removed and the schedule may only move earlier.
 * - in transit (dispatched, started, enroute, driver_*): route (the driver is rerouted),
 *   contact, notes and cash to collect are editable; items and schedule are locked.
 * - terminal (the config's complete or terminal activity, canceled, expired): nothing is editable.
 *
 * Fields are named the way edit requests and `order.changed` events name them:
 * pickup, dropoff, return, waypoints, items, schedule, contact, notes, cod_amount.
 */
class OrderEditPolicy
{
    public const STAGE_OPEN       = 'open';
    public const STAGE_READY      = 'ready';
    public const STAGE_IN_TRANSIT = 'in_transit';
    public const STAGE_TERMINAL   = 'terminal';

    public const FIELD_PICKUP     = 'pickup';
    public const FIELD_DROPOFF    = 'dropoff';
    public const FIELD_RETURN     = 'return';
    public const FIELD_WAYPOINTS  = 'waypoints';
    public const FIELD_ITEMS      = 'items';
    public const FIELD_SCHEDULE   = 'schedule';
    public const FIELD_CONTACT    = 'contact';
    public const FIELD_NOTES      = 'notes';
    public const FIELD_COD_AMOUNT = 'cod_amount';

    public const FIELDS = [
        self::FIELD_PICKUP,
        self::FIELD_DROPOFF,
        self::FIELD_RETURN,
        self::FIELD_WAYPOINTS,
        self::FIELD_ITEMS,
        self::FIELD_SCHEDULE,
        self::FIELD_CONTACT,
        self::FIELD_NOTES,
        self::FIELD_COD_AMOUNT,
    ];

    public const ROUTE_FIELDS = [self::FIELD_PICKUP, self::FIELD_DROPOFF, self::FIELD_RETURN, self::FIELD_WAYPOINTS];

    public const OPEN_STATUSES       = ['created', 'pending', 'accepted', 'confirmed', 'preparing'];
    public const READY_STATUSES      = ['ready', 'pickup_ready', 'ready_for_pickup'];
    public const IN_TRANSIT_STATUSES = ['dispatched', 'started', 'enroute', 'en_route', 'in_progress', 'picked_up', 'arrived', 'out_for_delivery'];
    public const TERMINAL_STATUSES   = ['completed', 'canceled', 'cancelled', 'expired'];

    public const RESTRICTION_REMOVAL_ONLY = 'removal_only';
    public const RESTRICTION_EARLIER_ONLY = 'earlier_only';

    protected ?string $stage = null;

    public function __construct(public Order $order)
    {
    }

    public static function for(Order $order): self
    {
        return new static($order);
    }

    /**
     * The order status the policy reads, normalized to snake case.
     */
    public function status(): string
    {
        return strtolower((string) ($this->order->status ?: 'created'));
    }

    /**
     * The edit stage of the order.
     */
    public function stage(): string
    {
        if ($this->stage !== null) {
            return $this->stage;
        }

        $status = $this->status();

        if ($this->isTerminal()) {
            return $this->stage = self::STAGE_TERMINAL;
        }

        if (in_array($status, self::READY_STATUSES, true)) {
            return $this->stage = self::STAGE_READY;
        }

        if ((bool) $this->order->started || in_array($status, self::IN_TRANSIT_STATUSES, true) || str_starts_with($status, 'driver_')) {
            return $this->stage = self::STAGE_IN_TRANSIT;
        }

        if (in_array($status, self::OPEN_STATUSES, true)) {
            return $this->stage = self::STAGE_OPEN;
        }

        // A custom status after dispatch means the order is out with a driver.
        return $this->stage = (bool) $this->order->dispatched ? self::STAGE_IN_TRANSIT : self::STAGE_OPEN;
    }

    /**
     * Whether the order is completed, canceled or expired: completed meaning the
     * order config's complete activity or one of its terminal activity codes.
     */
    public function isTerminal(): bool
    {
        $status = $this->status();

        if (in_array($status, self::TERMINAL_STATUSES, true)) {
            return true;
        }

        if (in_array($status, array_merge(self::OPEN_STATUSES, self::READY_STATUSES, self::IN_TRANSIT_STATUSES), true)) {
            return false;
        }

        $config = $this->orderConfig();
        if (!$config) {
            return false;
        }

        try {
            if ($config->isTerminalActivityCode($status)) {
                return true;
            }

            $activity = $config->getActivityByCode($status);

            return $activity ? $activity->complete() : false;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Fields locked outright at this stage, each with the reason.
     *
     * @return array<string, string>
     */
    public function locked(): array
    {
        $stage = $this->stage();

        if ($stage === self::STAGE_TERMINAL) {
            $reason = sprintf('The order is %s and can no longer be changed.', str_replace('_', ' ', $this->status()));

            return array_fill_keys(self::FIELDS, $reason);
        }

        if ($stage === self::STAGE_IN_TRANSIT) {
            return [
                self::FIELD_ITEMS    => 'Items cannot be changed once a driver has the order.',
                self::FIELD_SCHEDULE => 'The schedule cannot be changed once a driver has the order.',
            ];
        }

        return [];
    }

    /**
     * Fields that are only partly editable at this stage.
     *
     * @return array<string, string> field => removal_only|earlier_only
     */
    public function restrictions(): array
    {
        if ($this->stage() === self::STAGE_READY) {
            return [
                self::FIELD_ITEMS    => self::RESTRICTION_REMOVAL_ONLY,
                self::FIELD_SCHEDULE => self::RESTRICTION_EARLIER_ONLY,
            ];
        }

        return [];
    }

    /**
     * Whether the field may be edited at all at this stage.
     */
    public function allows(string $field): bool
    {
        return !array_key_exists($field, $this->locked());
    }

    /**
     * Why a change to the field is refused, or null when it is allowed.
     *
     * Context keys:
     * - items: `adds` (bool) whether the change adds or changes items rather than only removing them.
     * - schedule: `from` and `to` (the current and requested scheduled time).
     */
    public function check(string $field, array $context = []): ?string
    {
        $locked = $this->locked();
        if (isset($locked[$field])) {
            return $locked[$field];
        }

        $restriction = $this->restrictions()[$field] ?? null;

        if ($restriction === self::RESTRICTION_REMOVAL_ONLY && ($context['adds'] ?? true)) {
            return 'Once the order is ready, items can only be removed.';
        }

        if ($restriction === self::RESTRICTION_EARLIER_ONLY && !static::isEarlier($context['from'] ?? null, $context['to'] ?? null)) {
            return 'Once the order is ready, the schedule can only be moved earlier.';
        }

        return null;
    }

    /**
     * Refuse the edit when any requested field is locked.
     *
     * @param array<string, array|bool> $changes field => context (see check()), or field => true
     *
     * @throws OrderEditLockedException
     */
    public function assert(array $changes): void
    {
        $refused = [];

        foreach ($changes as $field => $context) {
            $reason = $this->check($field, is_array($context) ? $context : []);
            if ($reason !== null) {
                $refused[$field] = $reason;
            }
        }

        if ($refused) {
            throw new OrderEditLockedException($refused);
        }
    }

    /**
     * Whether item changes at this status must be flagged for the kitchen.
     */
    public function flagsKitchenChanges(): bool
    {
        return $this->status() === 'preparing';
    }

    /**
     * The policy as data, for clients that mirror it.
     */
    public function toArray(): array
    {
        return [
            'stage'        => $this->stage(),
            'status'       => $this->status(),
            'locked'       => (object) $this->locked(),
            'restrictions' => (object) $this->restrictions(),
        ];
    }

    protected function orderConfig(): ?OrderConfig
    {
        try {
            $config = $this->order->relationLoaded('orderConfig') ? $this->order->orderConfig : $this->order->orderConfig()->first();
        } catch (\Throwable) {
            return null;
        }

        return $config instanceof OrderConfig ? $config : null;
    }

    protected static function isEarlier(mixed $from, mixed $to): bool
    {
        if (empty($from) || empty($to)) {
            return false;
        }

        try {
            return Carbon::parse($to)->lessThanOrEqualTo(Carbon::parse($from));
        } catch (\Throwable) {
            return false;
        }
    }
}
