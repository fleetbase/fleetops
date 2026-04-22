<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     *
     * Adds geofence trigger configuration columns to the service_areas table,
     * enabling each service area to act as an active geofence boundary.
     */
    public function up(): void
    {
        Schema::table('service_areas', function (Blueprint $table) {
            $table->boolean('trigger_on_entry')->default(true)->after('stroke_color');
            $table->boolean('trigger_on_exit')->default(true)->after('trigger_on_entry');
            $table->unsignedSmallInteger('dwell_threshold_minutes')->nullable()->after('trigger_on_exit');
            $table->unsignedSmallInteger('speed_limit_kmh')->nullable()->after('dwell_threshold_minutes');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('service_areas', function (Blueprint $table) {
            $table->dropColumn([
                'trigger_on_entry',
                'trigger_on_exit',
                'dwell_threshold_minutes',
                'speed_limit_kmh',
            ]);
        });
    }
};
