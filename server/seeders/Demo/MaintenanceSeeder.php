<?php

namespace Fleetbase\FleetOps\Seeders\Demo;

use Fleetbase\FleetOps\Seeders\Demo\Concerns\SeedsDemoData;
use Fleetbase\FleetOps\Seeders\Testing\MaintenanceSeeder as TestingMaintenanceSeeder;

/** Everything is inherited; only the seed tag, identifier prefix and note text differ. */
class MaintenanceSeeder extends TestingMaintenanceSeeder
{
    use SeedsDemoData;
}
