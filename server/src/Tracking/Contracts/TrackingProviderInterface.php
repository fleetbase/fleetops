<?php

namespace Fleetbase\FleetOps\Tracking\Contracts;

use Fleetbase\FleetOps\Tracking\TrackingContext;
use Fleetbase\FleetOps\Tracking\TrackingOptions;
use Fleetbase\FleetOps\Tracking\TrackingProviderCapabilities;
use Fleetbase\FleetOps\Tracking\TrackingProviderResult;

interface TrackingProviderInterface
{
    public function key(): string;

    public function capabilities(): TrackingProviderCapabilities;

    public function canTrack(TrackingContext $context): bool;

    public function track(TrackingContext $context, TrackingOptions $options): TrackingProviderResult;
}
