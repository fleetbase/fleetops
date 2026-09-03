<?php

namespace Fleetbase\FleetOps\Seeders\Demo;

use Fleetbase\FleetOps\Seeders\Demo\Concerns\SeedsDemoData;
use Fleetbase\FleetOps\Seeders\Testing\ConnectivitySeeder as TestingConnectivitySeeder;

/** Everything is inherited; only the seed tag and the note text differ. */
class ConnectivitySeeder extends TestingConnectivitySeeder
{
    use SeedsDemoData;
}
