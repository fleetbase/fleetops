<?php

namespace Fleetbase\FleetOps\Tracking\Support;

use Fleetbase\FleetOps\Tracking\Contracts\TrackingProviderInterface;
use Fleetbase\FleetOps\Tracking\Providers\CalculatedTrackingProvider;
use Fleetbase\FleetOps\Tracking\TrackingContext;
use Fleetbase\FleetOps\Tracking\TrackingOptions;
use Fleetbase\FleetOps\Tracking\TrackingProviderCapabilities;
use Fleetbase\FleetOps\Tracking\TrackingProviderResult;

class FakeTrackingProvider implements TrackingProviderInterface
{
    public function __construct(protected string $providerKey = 'fake')
    {
    }

    public function key(): string
    {
        return $this->providerKey;
    }

    public function capabilities(): TrackingProviderCapabilities
    {
        return new TrackingProviderCapabilities(traffic: true, perLegEta: true);
    }

    public function canTrack(TrackingContext $context): bool
    {
        return $context->canRoute();
    }

    public function track(TrackingContext $context, TrackingOptions $options): TrackingProviderResult
    {
        $result             = (new CalculatedTrackingProvider())->track($context, $options);
        $result->provider   = $this->providerKey;
        $result->confidence = 'high';
        $result->warnings   = [];

        return $result;
    }
}
