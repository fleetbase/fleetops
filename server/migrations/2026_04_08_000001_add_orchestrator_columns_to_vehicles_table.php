<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add Orchestrator constraint columns to the vehicles table.
 *
 * Note: payload weight capacity is already covered by the existing
 * `payload_capacity` column on the vehicles table and is NOT duplicated here.
 *
 * New columns:
 *   - skills                      JSON array of vehicle capability flags (e.g. ["tail_lift","refrigerated"])
 *   - payload_capacity_volume     Maximum cargo volume in cubic metres
 *   - payload_capacity_pallets    Maximum pallet count
 *   - payload_capacity_parcels    Maximum parcel/package count
 *   - max_tasks                   Maximum number of stops per route
 *   - time_window_start           Default availability start time (HH:MM)
 *   - time_window_end             Default availability end time (HH:MM)
 *   - return_to_depot             Whether the vehicle must return to its start depot after a route
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            // Skills / capabilities
            $table->json('skills')->nullable()->after('status');

            // Additional capacity dimensions (weight is already payload_capacity)
            $table->unsignedDecimal('payload_capacity_volume', 10, 3)->nullable()->comment('Maximum cargo volume in cubic metres')->after('skills');
            $table->unsignedInteger('payload_capacity_pallets')->nullable()->comment('Maximum pallet count')->after('payload_capacity_volume');
            $table->unsignedInteger('payload_capacity_parcels')->nullable()->comment('Maximum parcel/package count')->after('payload_capacity_pallets');

            // Route constraints
            $table->unsignedInteger('max_tasks')->nullable()->after('payload_capacity_parcels');
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
                'payload_capacity_volume',
                'payload_capacity_pallets',
                'payload_capacity_parcels',
                'max_tasks',
                'time_window_start',
                'time_window_end',
                'return_to_depot',
            ]);
        });
    }
};
