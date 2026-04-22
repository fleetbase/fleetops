<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     *
     * Adds geofence trigger configuration columns to the zones table,
     * enabling each zone to be configured as an active geofence with
     * entry/exit/dwell event triggers.
     */
    public function up(): void
    {
        Schema::table('zones', function (Blueprint $table) {
            // Whether to fire a GeofenceEntered event when a driver enters this zone
            $table->boolean('trigger_on_entry')->default(true)->after('stroke_color');

            // Whether to fire a GeofenceExited event when a driver exits this zone
            $table->boolean('trigger_on_exit')->default(true)->after('trigger_on_entry');

            // Minutes a driver must remain inside before a GeofenceDwelled event fires.
            // NULL disables dwell tracking for this zone.
            $table->unsignedSmallInteger('dwell_threshold_minutes')->nullable()->after('trigger_on_exit');

            // Optional speed limit (km/h) enforced within this zone.
            // NULL means no speed limit is configured.
            $table->unsignedSmallInteger('speed_limit_kmh')->nullable()->after('dwell_threshold_minutes');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('zones', function (Blueprint $table) {
            $table->dropColumn([
                'trigger_on_entry',
                'trigger_on_exit',
                'dwell_threshold_minutes',
                'speed_limit_kmh',
            ]);
        });
    }
};
