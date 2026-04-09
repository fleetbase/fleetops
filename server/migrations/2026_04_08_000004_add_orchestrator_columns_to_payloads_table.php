<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add Orchestrator capacity columns to the payloads table.
 *
 * New columns:
 *   - capacity_weight_kg  Total payload weight in kilograms (aggregated from entities)
 *   - capacity_volume_m3  Total payload volume in cubic metres
 *   - capacity_pallets    Total pallet count
 *   - capacity_parcels    Total parcel/package count
 *
 * These columns allow the Orchestrator to quickly compare payload requirements
 * against vehicle capacity without re-aggregating entity dimensions on every run.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('payloads', function (Blueprint $table) {
            $table->unsignedDecimal('capacity_weight_kg', 10, 2)->nullable()->after('cod_currency');
            $table->unsignedDecimal('capacity_volume_m3', 10, 3)->nullable()->after('capacity_weight_kg');
            $table->unsignedInteger('capacity_pallets')->nullable()->after('capacity_volume_m3');
            $table->unsignedInteger('capacity_parcels')->nullable()->after('capacity_pallets');
        });
    }

    public function down(): void
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
};
