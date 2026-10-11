<?php

namespace Fleetbase\FleetOps\Support\TrackingPage;

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\TrackingNumber;
use Fleetbase\FleetOps\Models\Waypoint;
use Fleetbase\FleetOps\Support\TrackingScope;

/**
 * What a tracking number opened on the public page: the order, the customer within it
 * whose stops the visitor may see (null when the order names nobody), the stop the number
 * belongs to when it is a stop's or an item's, and the owning company's page config.
 */
final class TrackingTarget
{
    public const KIND_ORDER = 'order';
    public const KIND_STOP  = 'stop';
    public const KIND_ITEM  = 'item';

    public function __construct(
        public readonly Order $order,
        public readonly ?TrackingScope $scope,
        public readonly TrackingNumber $trackingNumber,
        public readonly string $kind = self::KIND_ORDER,
        public readonly ?Waypoint $waypoint = null,
        public readonly array $config = [],
    ) {
    }

    public function withConfig(array $config): self
    {
        return new self($this->order, $this->scope, $this->trackingNumber, $this->kind, $this->waypoint, $config);
    }

    public function companyUuid(): ?string
    {
        return $this->order->company_uuid;
    }
}
