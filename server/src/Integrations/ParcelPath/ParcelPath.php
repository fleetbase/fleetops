<?php

namespace Fleetbase\FleetOps\Integrations\ParcelPath;

/**
 * ParcelPath API bridge.
 *
 * Stub class — registered in IntegratedVendors::$supported so the
 * registry entry and constructor wiring resolve. Full bridge
 * implementation (rating, labeling, tracking, void) lands in
 * subsequent tasks on this branch.
 */
class ParcelPath
{
    protected ?string $apiKey;
    protected bool $sandbox;

    public function __construct(?string $apiKey = null, bool $sandbox = false)
    {
        $this->apiKey = $apiKey;
        $this->sandbox = $sandbox;
    }
}
