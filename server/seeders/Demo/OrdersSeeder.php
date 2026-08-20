<?php

namespace Fleetbase\FleetOps\Seeders\Demo;

use Fleetbase\FleetOps\Seeders\Demo\Concerns\SeedsDemoData;
use Fleetbase\FleetOps\Seeders\Testing\OrdersSeeder as TestingOrdersSeeder;

class OrdersSeeder extends TestingOrdersSeeder
{
    use SeedsDemoData;

    /**
     * Sequential references in declaration order, rather than the testing set's
     * TEST-<SEED_ID>. Two reasons: FLT-1004 is what an operator would actually see, and the
     * screenshot manifest resolves the order-details page by looking this exact value up
     * through the internal API (records get a fresh uuid on every seed, so the public_id in
     * the URL cannot be written into the manifest).
     *
     * Changing a value here breaks that lookup — the capture aborts rather than screenshotting
     * the wrong order, but it does abort.
     */
    protected const INTERNAL_IDS = [
        'order_created_unassigned' => 'FLT-1001',
        'order_scheduled'          => 'FLT-1002',
        'order_dispatched'         => 'FLT-1003',
        'order_started'            => 'FLT-1004',
        'order_completed'          => 'FLT-1005',
        'order_canceled'           => 'FLT-1006',
        'order_vehicle_assigned'   => 'FLT-1007',
    ];

    protected function orderInternalId(string $seedId): string
    {
        return static::INTERNAL_IDS[$seedId] ?? parent::orderInternalId($seedId);
    }

    protected function entityNameFor(string $seedId, int $index): string
    {
        $names = ['Document envelope', 'Small parcel', 'Carton', 'Insulated tote', 'Pallet'];

        return $names[($index - 1) % count($names)];
    }
}
