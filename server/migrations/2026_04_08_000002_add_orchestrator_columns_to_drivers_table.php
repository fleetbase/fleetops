<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add Orchestrator constraint columns to the drivers table.
 *
 * New columns:
 *   - skills              JSON array of driver qualifications/certifications
 *   - max_travel_time     Maximum driving time per route in seconds
 *   - max_distance        Maximum driving distance per route in metres
 *   - time_window_start   Default shift start time (HH:MM)
 *   - time_window_end     Default shift end time (HH:MM)
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('drivers', function (Blueprint $table) {
            $table->json('skills')->nullable()->after('status');
            $table->unsignedInteger('max_travel_time')->nullable()->comment('Seconds')->after('skills');
            $table->unsignedInteger('max_distance')->nullable()->comment('Metres')->after('max_travel_time');
            $table->time('time_window_start')->nullable()->after('max_distance');
            $table->time('time_window_end')->nullable()->after('time_window_start');
        });
    }

    public function down(): void
    {
        Schema::table('drivers', function (Blueprint $table) {
            $table->dropColumn([
                'skills',
                'max_travel_time',
                'max_distance',
                'time_window_start',
                'time_window_end',
            ]);
        });
    }
};
