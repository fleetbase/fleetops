<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Orchestrator payload migration — intentionally empty.
 *
 * Payload capacity (weight, volume, pallets, parcels) is computed dynamically
 * from the payload's entities at orchestration time and does not need to be
 * stored as denormalised columns on the payloads table. Storing them would
 * create a synchronisation problem: any entity add/update/remove would require
 * explicit cache invalidation.
 *
 * The OrchestrationPayloadBuilder aggregates entity.weight and entity dimensions
 * directly from the payload->entities relationship instead.
 *
 * This migration is kept as a no-op so the migration history remains intact
 * and the file can be referenced if the decision is ever revisited.
 */
return new class extends Migration {
    public function up(): void
    {
        // No schema changes — see docblock above.
    }

    public function down(): void
    {
        // No schema changes to reverse.
    }
};
