<?php

namespace Fleetbase\FleetOps\Seeders\Demo;

use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Seeders\Demo\Concerns\SeedsDemoData;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;

/**
 * The presentable Fleet-Ops fixture set.
 *
 * Structurally identical to Testing\TestingSeeder — purge, then seed in dependency order —
 * but tagged `fleetops-demo`, so the two sets are independently purgeable and can coexist in
 * one install. Idempotent: rerunning replaces the demo rows and leaves everything else alone.
 *
 *   php artisan db:seed --force --class="Fleetbase\FleetOps\Seeders\Demo\DemoSeeder"
 *
 * Primarily consumed by the console screenshot pipeline in the fleetbase/fleetbase monorepo
 * (screenshots/README.md), which publishes the resulting screens to fleetbase.io.
 */
class DemoSeeder extends Seeder
{
    use SeedsDemoData;

    public function run(): void
    {
        Schema::disableForeignKeyConstraints();
        try {
            $this->purgeSeedData();
        } finally {
            Schema::enableForeignKeyConstraints();
        }

        $this->call([
            NetworkSeeder::class,
            FleetSeeder::class,
            OrdersSeeder::class,
            ConnectivitySeeder::class,
            MaintenanceSeeder::class,
        ]);

        $this->announceFixtures();
    }

    protected function purgeSeedData(): void
    {
        // Reverse dependency order, matching Testing\TestingSeeder.
        foreach ([
            new MaintenanceSeeder(),
            new ConnectivitySeeder(),
            new OrdersSeeder(),
            new FleetSeeder(),
            new NetworkSeeder(),
        ] as $seeder) {
            $seeder->purgeSeedData();
        }
    }

    /**
     * Echo the public_ids of the records that detail-page screenshots point at.
     *
     * The screenshot capture resolves these independently, by looking the record up through
     * the internal API by name or internal_id. Printing them here gives it a second, unrelated
     * source to check itself against — two paths agreeing is what distinguishes "resolved the
     * right record" from "resolved a record". A disagreement fails the run rather than quietly
     * publishing a screenshot of the wrong driver.
     *
     * Keys are screenshot manifest entry ids, not seed ids. Follows the same
     * MINTED_ and SEEDED_ echo protocol that scripts/ci/mint-api-key.php established in the monorepo.
     */
    protected function announceFixtures(): void
    {
        $fixtures = array_filter([
            'driver-details' => $this->seededModel(Driver::class, 'driver_ava')?->public_id,
            'order-details'  => $this->seededModel(Order::class, 'order_started')?->public_id,
        ]);

        echo PHP_EOL . 'SEEDED_FIXTURES=' . json_encode($fixtures) . PHP_EOL;
    }
}
