<?php

namespace Fleetbase\FleetOps\Support;

use Fleetbase\FleetOps\Events\OrderChanged;
use Fleetbase\FleetOps\Models\Entity;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Payload;
use Fleetbase\FleetOps\Models\Place;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * One edit of an order, from the request that changes it to the events that announce it.
 *
 * begin() records the order as it is; guard() refuses changes its status locks
 * (see OrderEditPolicy); commit() works out what actually changed and then:
 *
 * - keeps the driver on the right stop when the route changed (reconcileCurrentWaypoint),
 * - recomputes the order's distance and time and republishes public tracking,
 * - flags item changes made while the order is being prepared (`meta.kitchen_changed_at`),
 * - broadcasts `order.changed` and pushes the change to the assigned driver.
 *
 *     $edit = OrderEdit::begin($order);
 *     $edit->guard($edit->requestedChanges($input));
 *     // ...apply the edit...
 *     $edit->commit();
 */
class OrderEdit
{
    public const ENTITIES_APPEND  = 'append';
    public const ENTITIES_REPLACE = 'replace';

    protected OrderEditPolicy $policy;

    protected ?array $routeBefore = null;

    protected array $entitiesBefore = [];

    protected mixed $codBefore = null;

    protected ?string $scheduledBefore = null;

    protected ?string $notesBefore = null;

    protected ?string $customerBefore = null;

    protected array $marked = [];

    public function __construct(public Order $order)
    {
        $this->policy = OrderEditPolicy::for($order);

        $payload = $this->currentPayload();
        if ($payload instanceof Payload) {
            $this->routeBefore    = $payload->routeSignature();
            $this->entitiesBefore = $this->entitySnapshot($payload);
            $this->codBefore      = $payload->cod_amount;
        }

        $this->scheduledBefore = static::timeString($order->scheduled_at);
        $this->notesBefore     = $order->notes;
        $this->customerBefore  = $order->customer_uuid;
    }

    public static function begin(Order $order): static
    {
        return new static($order);
    }

    public function policy(): OrderEditPolicy
    {
        return $this->policy;
    }

    /**
     * Normalize an entities mode from a request value: `replace` (or `sync`) replaces
     * the list, anything else appends as the API always has.
     */
    public static function entitiesMode(mixed $value): string
    {
        return in_array(strtolower((string) $value), ['replace', 'sync'], true) ? self::ENTITIES_REPLACE : self::ENTITIES_APPEND;
    }

    /**
     * The policy fields an edit request touches, with the context the policy checks.
     *
     * Keys read from $input (presence matters): pickup, dropoff, return, waypoints,
     * entities, scheduled_at, notes, cod_amount, customer.
     *
     * @return array<string, array|bool>
     */
    public function requestedChanges(array $input, string $entitiesMode = self::ENTITIES_APPEND): array
    {
        $changes = [];

        foreach (OrderEditPolicy::ROUTE_FIELDS as $field) {
            if (array_key_exists($field, $input)) {
                $changes[$field] = true;
            }
        }

        $entities = $input['entities'] ?? null;
        if (is_array($entities) && ($entities !== [] || $entitiesMode === self::ENTITIES_REPLACE)) {
            $payload = $this->currentPayload();
            $adds    = true;
            if ($entitiesMode === self::ENTITIES_REPLACE && $payload instanceof Payload) {
                $adds = !$payload->entitiesListOnlyNamesExisting($entities);
            }
            $changes[OrderEditPolicy::FIELD_ITEMS] = ['adds' => $adds];
        }

        if (array_key_exists('scheduled_at', $input) && static::timeString($input['scheduled_at']) !== $this->scheduledBefore) {
            $changes[OrderEditPolicy::FIELD_SCHEDULE] = ['from' => $this->scheduledBefore, 'to' => $input['scheduled_at']];
        }

        if (array_key_exists('notes', $input) && (string) $input['notes'] !== (string) $this->notesBefore) {
            $changes[OrderEditPolicy::FIELD_NOTES] = true;
        }

        if (array_key_exists('cod_amount', $input) && !static::sameAmount($input['cod_amount'], $this->codBefore)) {
            $changes[OrderEditPolicy::FIELD_COD_AMOUNT] = true;
        }

        if (array_key_exists('customer', $input) && !empty($input['customer']) && !$this->isCurrentCustomer($input['customer'])) {
            $changes[OrderEditPolicy::FIELD_CONTACT] = true;
        }

        return $changes;
    }

    /**
     * Refuse the edit when its status locks any requested field.
     *
     * @throws \Fleetbase\FleetOps\Exceptions\OrderEditLockedException
     */
    public function guard(array $changes): static
    {
        $this->policy->assert($changes);

        return $this;
    }

    /**
     * Record a change the snapshot cannot see (for example a total recomputed elsewhere).
     */
    public function markChanged(string ...$changes): static
    {
        $this->marked = array_values(array_unique(array_merge($this->marked, $changes)));

        return $this;
    }

    /**
     * Work out what changed since begin(), apply the follow-up effects and announce it.
     *
     * @param array $extra        order.changed data overrides (summary, total, currency, changed_by, ...)
     * @param bool  $notifyDriver push the change to the assigned driver when it concerns them
     *
     * @return string[] the changes announced
     */
    public function commit(array $extra = [], bool $notifyDriver = true): array
    {
        $order   = $this->order;
        $payload = $this->freshPayload();
        $changes = [];

        $routeChanges = [];
        if ($payload instanceof Payload) {
            $routeAfter   = $payload->routeSignature();
            $routeChanges = $this->routeBefore ? Payload::routeChanges($this->routeBefore, $routeAfter) : ['pickup', 'dropoff', 'waypoints'];
            $routeChanges = array_values(array_filter($routeChanges, function ($part) use ($routeAfter) {
                // A route part that was and still is empty did not change.
                return !empty($this->routeBefore[$part] ?? null) || !empty($routeAfter[$part]);
            }));

            if ($routeChanges) {
                $payload->reconcileCurrentWaypoint($this->routeBefore ?? [], $this->isUnderWay());
            }

            if ($this->entitySnapshot($payload) !== $this->entitiesBefore) {
                $changes[] = OrderEditPolicy::FIELD_ITEMS;
            }

            if (!static::sameAmount($payload->cod_amount, $this->codBefore)) {
                $changes[] = OrderEditPolicy::FIELD_COD_AMOUNT;
            }

            $order->setRelation('payload', $payload);
        }

        $changes = array_merge($routeChanges, $changes);

        if (static::timeString($order->scheduled_at) !== $this->scheduledBefore) {
            $changes[] = OrderEditPolicy::FIELD_SCHEDULE;
        }

        if ((string) $order->notes !== (string) $this->notesBefore) {
            $changes[] = OrderEditPolicy::FIELD_NOTES;
        }

        if ($order->customer_uuid !== $this->customerBefore) {
            $changes[] = OrderEditPolicy::FIELD_CONTACT;
        }

        $changes = array_values(array_unique(array_merge($changes, $this->marked)));

        if ($routeChanges) {
            $this->afterRouteChanged();
        }

        if (in_array(OrderEditPolicy::FIELD_ITEMS, $changes, true) && $this->policy->flagsKitchenChanges()) {
            $this->attempt(fn () => $order->updateMeta('kitchen_changed_at', Carbon::now()->toIso8601ZuluString()));
        }

        if ($changes) {
            $summary = $extra['summary'] ?? static::summarize($changes, $payload, $order);
            unset($extra['summary']);
            $this->announce($changes, (string) $summary, $extra, $notifyDriver);
        }

        return $changes;
    }

    /**
     * One line describing the changes, e.g. "Drop-off changed to 8 Marina View · Items changed".
     */
    public static function summarize(array $changes, ?Payload $payload = null, ?Order $order = null): string
    {
        $parts = [];

        foreach ($changes as $change) {
            $parts[] = match ($change) {
                'dropoff'    => static::placeLine('Drop-off', $payload?->dropoff),
                'pickup'     => static::placeLine('Pickup', $payload?->pickup),
                'return'     => static::placeLine('Return', $payload?->return),
                'waypoints'  => 'Stops changed',
                'items'      => 'Items changed',
                'schedule'   => $order?->scheduled_at ? 'Rescheduled to ' . Carbon::parse($order->scheduled_at)->format('j M H:i') : 'Schedule cleared',
                'notes'      => 'Notes updated',
                'cod_amount' => $payload && $payload->cod_amount !== null ? 'Cash to collect changed to ' . trim(($payload->cod_currency ?? '') . ' ' . $payload->cod_amount) : 'Cash to collect changed',
                'contact'    => 'Customer changed',
                'total'      => 'Total changed',
                default      => ucfirst(str_replace('_', ' ', (string) $change)) . ' changed',
            };
        }

        return implode(' · ', array_filter($parts));
    }

    protected function afterRouteChanged(): void
    {
        $order = $this->order;

        $this->attempt(fn () => $order->setDistanceAndTime());
        $this->attempt(fn () => app(TrackingPublisher::class)->routeChanged($order));
    }

    protected function announce(array $changes, string $summary, array $extra, bool $notifyDriver): void
    {
        OrderChanged::dispatchFor($this->order, $changes, $summary, $extra, $notifyDriver);
    }

    /**
     * Whether the driver is already working the order, so a route change must keep their place.
     */
    protected function isUnderWay(): bool
    {
        return (bool) $this->order->started || $this->policy->stage() === OrderEditPolicy::STAGE_IN_TRANSIT;
    }

    protected function currentPayload(): ?Payload
    {
        $payload = $this->order->relationLoaded('payload') ? $this->order->getRelation('payload') : $this->attempt(fn () => $this->order->payload);

        return $payload instanceof Payload ? $payload : null;
    }

    protected function freshPayload(): ?Payload
    {
        if (!$this->order->payload_uuid) {
            return $this->currentPayload();
        }

        $payload = $this->attempt(fn () => Payload::where('uuid', $this->order->payload_uuid)->withoutGlobalScopes()->first());

        return $payload instanceof Payload ? $payload : $this->currentPayload();
    }

    /**
     * Entity uuid => fingerprint of its stored attributes.
     */
    protected function entitySnapshot(Payload $payload): array
    {
        if (!$payload->uuid) {
            return [];
        }

        $entities = $this->attempt(fn () => Entity::where('payload_uuid', $payload->uuid)->get()) ?? collect();

        return $entities->mapWithKeys(function (Entity $entity) {
            $attributes = Arr::except($entity->getAttributes(), ['id', 'created_at', 'updated_at', 'qr_code', 'barcode']);
            ksort($attributes);

            return [$entity->uuid => md5((string) json_encode($attributes))];
        })->sortKeys()->all();
    }

    protected function isCurrentCustomer(mixed $customer): bool
    {
        if (!is_string($customer)) {
            return false;
        }

        if ($customer === $this->customerBefore) {
            return true;
        }

        return $this->attempt(fn () => data_get($this->order, 'customer.public_id')) === $customer;
    }

    protected static function placeLine(string $label, mixed $place): string
    {
        if (!$place instanceof Place) {
            return $label . ' changed';
        }

        $address = trim((string) ($place->address ?: $place->name ?: $place->street1));

        return $address !== '' ? $label . ' changed to ' . $address : $label . ' changed';
    }

    protected static function timeString(mixed $value): ?string
    {
        if (empty($value)) {
            return null;
        }

        try {
            return Carbon::parse($value)->toIso8601ZuluString();
        } catch (\Throwable) {
            return (string) $value;
        }
    }

    protected static function sameAmount(mixed $a, mixed $b): bool
    {
        $a = ($a === '' || $a === null) ? null : (is_numeric($a) ? (float) $a : $a);
        $b = ($b === '' || $b === null) ? null : (is_numeric($b) ? (float) $b : $b);

        return $a === $b;
    }

    protected function attempt(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (\Throwable $exception) {
            Log::debug('Order edit follow-up failed', ['order' => $this->order->public_id, 'error' => $exception->getMessage()]);

            return null;
        }
    }
}
