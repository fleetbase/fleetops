<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per stretch a driver spent on duty: opened when `online` turns true,
 * closed when it turns false.
 *
 * `drivers.online_since` only knows the stretch a driver is in now. A driver who
 * goes off duty for lunch and back on has two stretches in one day, and a day's
 * total has to add both, for any day asked about, in whatever timezone the day
 * is cut. A running total on the driver row cannot answer that; the stretches
 * themselves can.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('driver_duty_periods')) {
            return;
        }

        Schema::create('driver_duty_periods', function (Blueprint $table) {
            $table->increments('id');
            $table->uuid('uuid')->unique();
            $table->uuid('company_uuid')->nullable()->index();
            $table->uuid('driver_uuid');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            $table->index(['driver_uuid', 'started_at'], 'driver_duty_periods_driver_started_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_duty_periods');
    }
};
