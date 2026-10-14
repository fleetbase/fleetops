<?php

namespace Fleetbase\FleetOps\Support;

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Waypoint;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * One customer's view of one order, the unit a public tracking channel is scoped to.
 *
 * A stop's customer is the waypoint's `customer` morph (a Contact or a Vendor); stops without
 * one belong to the order's customer. Every customer of an order therefore gets their own
 * scope, their own `tracking.{opaque}` channel and their own sanitized updates.
 *
 * Identity is (order_uuid, customer_type, customer_uuid). customer_type is the morph class as
 * stored on the order or waypoint (a fully qualified model class), normalized without a
 * leading backslash so that every caller derives the same channel.
 */
final class TrackingScope implements \JsonSerializable
{
    public readonly string $order_uuid;

    public readonly string $customer_type;

    public readonly string $customer_uuid;

    /**
     * The order's company, when known. Not part of the scope's identity.
     */
    public readonly ?string $company_uuid;

    private ?Order $order;

    public function __construct(string $orderUuid, string $customerType, string $customerUuid, ?Order $order = null)
    {
        $customerType = static::normalizeType($customerType);

        if ($orderUuid === '' || $customerType === '' || $customerUuid === '') {
            throw new \InvalidArgumentException('A tracking scope requires an order, a customer type and a customer.');
        }

        $this->order_uuid    = $orderUuid;
        $this->customer_type = $customerType;
        $this->customer_uuid = $customerUuid;
        $this->order         = $order && $order->uuid === $orderUuid ? $order : null;
        $this->company_uuid  = $this->order?->company_uuid ?: null;
    }

    /**
     * The scope of the order's own customer, or null when the order has none.
     */
    public static function forOrder(Order $order): ?self
    {
        return static::make($order, $order->customer_type, $order->customer_uuid);
    }

    /**
     * The scope a stop belongs to: the waypoint's customer, else the order's customer.
     */
    public static function forStop(Order $order, ?Waypoint $waypoint = null): ?self
    {
        return static::make($order, $waypoint?->customer_type, $waypoint?->customer_uuid) ?? static::forOrder($order);
    }

    /**
     * The scope of an explicit customer on an order.
     */
    public static function forCustomer(Order $order, Model $customer): self
    {
        return new self((string) $order->uuid, $customer->getMorphClass(), (string) $customer->uuid, $order);
    }

    /**
     * Every customer scope of an order: the order's customer first, then each distinct waypoint customer.
     *
     * @param iterable<Waypoint>|null $waypoints the order's waypoints; loaded from the payload when null
     *
     * @return array<int, self>
     */
    public static function allForOrder(Order $order, ?iterable $waypoints = null): array
    {
        if ($waypoints === null) {
            $waypoints = $order->payload_uuid ? Waypoint::on($order->getConnectionName())->where('payload_uuid', $order->payload_uuid)->without(['place'])->get(['uuid', 'payload_uuid', 'customer_uuid', 'customer_type']) : [];
        }

        $scopes = [];
        if ($scope = static::forOrder($order)) {
            $scopes[$scope->key()] = $scope;
        }

        foreach ($waypoints as $waypoint) {
            $scope = $waypoint instanceof Waypoint ? static::make($order, $waypoint->customer_type, $waypoint->customer_uuid) : null;
            if ($scope && !isset($scopes[$scope->key()])) {
                $scopes[$scope->key()] = $scope;
            }
        }

        return array_values($scopes);
    }

    /**
     * The message the channel id is derived from: "{order_uuid}:{customer_type}:{customer_uuid}".
     */
    public function key(): string
    {
        return $this->order_uuid . ':' . $this->customer_type . ':' . $this->customer_uuid;
    }

    /**
     * Whether this scope is the given customer (type and uuid).
     */
    public function is(?string $customerType, ?string $customerUuid): bool
    {
        return $customerUuid !== null
            && $customerUuid !== ''
            && $customerUuid === $this->customer_uuid
            && static::normalizeType((string) $customerType) === $this->customer_type;
    }

    /**
     * Whether this scope is the order's own customer.
     */
    public function isOrderCustomer(Order $order): bool
    {
        return $order->uuid === $this->order_uuid && $this->is($order->customer_type, $order->customer_uuid);
    }

    /**
     * Whether a stop belongs to this scope: its waypoint's customer, else the order's customer.
     */
    public function ownsStop(Order $order, ?Waypoint $waypoint = null): bool
    {
        if ($waypoint && $waypoint->customer_uuid && $waypoint->customer_type) {
            return $this->is($waypoint->customer_type, $waypoint->customer_uuid);
        }

        return $this->isOrderCustomer($order);
    }

    /**
     * The order this scope is about, loaded once when not given.
     */
    public function order(): ?Order
    {
        return $this->order ??= Order::where('uuid', $this->order_uuid)->first();
    }

    public function toArray(): array
    {
        return [
            'order_uuid'    => $this->order_uuid,
            'customer_type' => $this->customer_type,
            'customer_uuid' => $this->customer_uuid,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * A morph type as a model class without a leading backslash; morph map aliases resolve to their class.
     */
    public static function normalizeType(string $type): string
    {
        $type = ltrim(trim($type), '\\');

        return ltrim((string) (Relation::getMorphedModel($type) ?? $type), '\\');
    }

    private static function make(Order $order, ?string $customerType, ?string $customerUuid): ?self
    {
        if (!$order->uuid || !$customerType || !$customerUuid) {
            return null;
        }

        return new self((string) $order->uuid, $customerType, $customerUuid, $order);
    }
}
