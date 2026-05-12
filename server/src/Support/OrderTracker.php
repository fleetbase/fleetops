<?php

namespace Fleetbase\FleetOps\Support;

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Tracking\TrackingIntelligenceService;
use Fleetbase\FleetOps\Tracking\TrackingOptions;

class OrderTracker
{
    public function __construct(protected Order $order)
    {
    }

    public function eta(array $options = []): array
    {
        return app(TrackingIntelligenceService::class)->eta($this->order, TrackingOptions::fromArray($options));
    }

    public function toArray(array $options = []): array
    {
        return app(TrackingIntelligenceService::class)->track($this->order, TrackingOptions::fromArray($options));
    }
}
