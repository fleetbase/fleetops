<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add Orchestrator constraint columns to the vehicles table.
 *
 * New columns:
 *   - skills              JSON array of vehicle capability flags (e.g. ["tail_lift","refrigerated"])
 *   - capacity_weight_kg  Maximum payload weight in kilograms
 *   - capacity_volume_m3  Maximum cargo volume in cubic metres
 *   - capacity_pallets    Maximum pallet count
 *   - capacity_parcels    Maximum parcel/package count
 *   - max_tasks           Maximum number of stops per route
 *   - time_window_start   Default shift start time (HH:MM)
 *   - time_window_end     Default shift end time (HH:MM)
 *   - return_to_depot     Whether the vehicle must return to its start depot
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            // Skills / capabilities
            $table->json('skills')->nullable()->after('status');
            // Multi-dimensional capacity
            $table->unsignedDecimal('capacity_weight_kg', 10, 2)->nullable()->after('skills');
            $table->unsignedDecimal('capacity_volume_m3', 10, 3)->nullable()->after('capacity_weight_kg');
            $table->unsignedInteger('capacity_pallets')->nullable()->after('capacity_volume_m3');
            $table->unsignedInteger('capacity_parcels')->nullable()->after('capacity_pallets');
            // Route constraints
            $table->unsignedInteger('max_tasks')->nullable()->after('capacity_parcels');
            $table->time('time_window_start')->nullable()->after('max_tasks');
            $table->time('time_window_end')->nullable()->after('time_window_start');
            $table->boolean('return_to_depot')->default(true)->after('time_window_end');
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn([
                'skills',
                'capacity_weight_kg',
                'capacity_volume_m3',
                'capacity_pallets',
                'capacity_parcels',
                'max_tasks',
                'time_window_start',
                'time_window_end',
                'return_to_depot',
            ]);
        });
    }
};
