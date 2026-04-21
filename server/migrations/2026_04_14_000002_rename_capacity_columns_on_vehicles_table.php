<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Correct orchestrator capacity column names on the vehicles table.
 *
 * The columns added in migration 2026_04_08_000001 did not follow the Fleetbase
 * naming convention for capacity fields. This migration:
 *
 *   1. Drops capacity_weight_kg — this is a duplicate of the pre-existing
 *      payload_capacity column which already represents maximum payload weight.
 *
 *   2. Renames the remaining capacity columns to follow the payload_capacity_*
 *      convention used by the existing payload_capacity column:
 *        capacity_volume_m3  → payload_capacity_volume
 *        capacity_pallets    → payload_capacity_pallets
 *        capacity_parcels    → payload_capacity_parcels
 *
 * Rollback reverses the renames and restores capacity_weight_kg as nullable.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            // Drop the redundant weight capacity column (payload_capacity already exists)
            $table->dropColumn('capacity_weight_kg');

            // Rename remaining columns to follow payload_capacity_* convention
            $table->renameColumn('capacity_volume_m3', 'payload_capacity_volume');
            $table->renameColumn('capacity_pallets', 'payload_capacity_pallets');
            $table->renameColumn('capacity_parcels', 'payload_capacity_parcels');
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            // Restore the dropped column
            $table->unsignedDecimal('capacity_weight_kg', 10, 2)->nullable()->after('skills');

            // Reverse the renames
            $table->renameColumn('payload_capacity_volume', 'capacity_volume_m3');
            $table->renameColumn('payload_capacity_pallets', 'capacity_pallets');
            $table->renameColumn('payload_capacity_parcels', 'capacity_parcels');
        });
    }
};
