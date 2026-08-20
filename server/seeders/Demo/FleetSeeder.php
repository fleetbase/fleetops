<?php

namespace Fleetbase\FleetOps\Seeders\Demo;

use Fleetbase\FleetOps\Seeders\Demo\Concerns\SeedsDemoData;
use Fleetbase\FleetOps\Seeders\Testing\FleetSeeder as TestingFleetSeeder;

/**
 * Vehicles, fleets, fuel reports and issues are inherited unchanged — the testing set already
 * uses real makes, models, plates and Singapore coordinates. Only the people differ.
 */
class FleetSeeder extends TestingFleetSeeder
{
    use SeedsDemoData;

    protected function userFixtures(): array
    {
        // Phone numbers are kept from the testing set: +65 8100 000x is inside Singapore's
        // mobile range but is not an allocated block, so nothing here can dial a real person.
        return [
            'driver_ava_user'  => ['Amara Chen', 'amara.chen@demo.fleetbase.io', '+6581000001'],
            'driver_ken_user'  => ['Kenji Tan', 'kenji.tan@demo.fleetbase.io', '+6581000002'],
            'driver_mira_user' => ['Mira Rahman', 'mira.rahman@demo.fleetbase.io', '+6581000003'],
            'dispatcher_user'  => ['Priya Nair', 'priya.nair@demo.fleetbase.io', '+6581000004'],
        ];
    }
}
