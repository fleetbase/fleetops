<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drop redundant denormalised capacity columns from the payloads table.
 *
 * The columns capacity_weight_kg, capacity_volume_m3, capacity_pallets, and
 * capacity_parcels were added in migration 2026_04_08_000004 as a cache of
 * aggregated entity dimensions. Storing them creates a synchronisation problem:
 * any entity add, update, or removal requires explicit cache invalidation.
 *
 * The OrchestrationPayloadBuilder now computes these values dynamically from
 * the payload->entities relationship at orchestration time, so the columns are
 * no longer needed.
 *
 * Rollback restores the columns as nullable so existing data is not lost if
 * this migration is reversed.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('payloads', function (Blueprint $table) {
            $table->dropColumn([
                'capacity_weight_kg',
                'capacity_volume_m3',
                'capacity_pallets',
                'capacity_parcels',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('payloads', function (Blueprint $table) {
            $table->decimal('capacity_weight_kg', 10, 2)->unsigned()->nullable()->after('cod_currency');
            $table->decimal('capacity_volume_m3', 10, 3)->unsigned()->nullable()->after('capacity_weight_kg');
            $table->unsignedInteger('capacity_pallets')->nullable()->after('capacity_volume_m3');
            $table->unsignedInteger('capacity_parcels')->nullable()->after('capacity_pallets');
        });
    }
};
