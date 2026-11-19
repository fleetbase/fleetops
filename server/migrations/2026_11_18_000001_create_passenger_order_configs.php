<?php

use Fleetbase\FleetOps\Support\FleetOps;
use Fleetbase\Models\Company;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;

/**
 * Gives every existing organization the system Passenger order config, as new organizations
 * get it when they are created. A company that already has it (from
 * `fleetops:create-order-config passenger`) keeps the one it has.
 */
return new class extends Migration {
    public function up(): void
    {
        Company::query()->select(['id', 'uuid', 'name'])->chunkById(200, function ($companies) {
            foreach ($companies as $company) {
                try {
                    FleetOps::createPassengerConfig($company);
                } catch (Throwable $e) {
                    Log::warning('Could not create the passenger order config for company ' . $company->uuid . ': ' . $e->getMessage());
                }
            }
        }, 'id');
    }

    public function down(): void
    {
        // The configs may already have orders, so they stay.
    }
};
