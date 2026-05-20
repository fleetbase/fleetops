<?php

namespace Fleetbase\FleetOps\Seeders\Testing;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;

class TestingSeeder extends Seeder
{
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
    }

    protected function purgeSeedData(): void
    {
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
}
