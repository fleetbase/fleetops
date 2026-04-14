<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Change order time_window_start and time_window_end from dateTime to time.
 *
 * The delivery window only needs a time-of-day (HH:MM), not a full timestamp.
 * The date context comes from the order's scheduled_at field. Storing a full
 * datetime was confusing because it created three separate date/time fields
 * (scheduled_at, time_window_start, time_window_end) with overlapping semantics.
 *
 * After this migration the columns store only the time portion (e.g. "09:00:00").
 * Any existing datetime values are truncated to their time portion automatically
 * by MySQL's implicit cast during the ALTER TABLE.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->time('time_window_start')->nullable()->change();
            $table->time('time_window_end')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dateTime('time_window_start')->nullable()->change();
            $table->dateTime('time_window_end')->nullable()->change();
        });
    }
};
