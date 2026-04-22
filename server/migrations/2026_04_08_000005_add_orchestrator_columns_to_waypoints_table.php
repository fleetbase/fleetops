<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add Orchestrator constraint columns to the waypoints table.
 *
 * New columns:
 *   - time_window_start   Earliest acceptable arrival datetime at this stop
 *   - time_window_end     Latest acceptable arrival datetime at this stop
 *   - service_time        Expected dwell time in seconds (loading/unloading/POD)
 *
 * These per-stop constraints are passed directly to the VROOM engine as
 * shipment/job time_windows and service fields.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('waypoints', function (Blueprint $table) {
            $table->dateTime('time_window_start')->nullable()->after('type');
            $table->dateTime('time_window_end')->nullable()->after('time_window_start');
            $table->unsignedInteger('service_time')->nullable()->comment('Seconds')->after('time_window_end');
        });
    }

    public function down(): void
    {
        Schema::table('waypoints', function (Blueprint $table) {
            $table->dropColumn([
                'time_window_start',
                'time_window_end',
                'service_time',
            ]);
        });
    }
};
