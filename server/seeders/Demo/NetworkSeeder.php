<?php

namespace Fleetbase\FleetOps\Seeders\Demo;

use Fleetbase\FleetOps\Seeders\Demo\Concerns\SeedsDemoData;
use Fleetbase\FleetOps\Seeders\Testing\NetworkSeeder as TestingNetworkSeeder;

/**
 * Service areas, zones, places and service rates are inherited unchanged — Raffles Quay,
 * ION Orchard and Changi Airfreight are already the right kind of thing to put on a website.
 * Only the addresses on the people and vendors differ.
 */
class NetworkSeeder extends TestingNetworkSeeder
{
    use SeedsDemoData;

    protected function contactFixtures(): array
    {
        return [
            'customer_alice' => ['Alice Tan', 'customer', 'alice.tan@demo.fleetbase.io', '+6591000001', 'orchard_store'],
            'customer_ben'   => ['Ben Lim', 'customer', 'ben.lim@demo.fleetbase.io', '+6591000002', 'rochor_store'],
            'ops_manager'    => ['Nadia Rahman', 'contact', 'nadia.rahman@demo.fleetbase.io', '+6591000003', 'central_depot'],
        ];
    }

    protected function vendorFixtures(): array
    {
        // demo.fleetbase.io rather than the vendor's own apparent domain: fastline.example.test
        // reads as test data, and a plausible-looking real domain would be worse — it would put
        // an address Fleetbase does not control on the marketing site.
        return [
            'facilitator_fastline' => ['Fastline Logistics', 'facilitator', 'fastline@demo.fleetbase.io', '+6592000001', 'central_depot'],
            'supplier_parts'       => ['Apex Parts Supply', 'supplier', 'apex@demo.fleetbase.io', '+6592000002', 'west_depot'],
        ];
    }
}
