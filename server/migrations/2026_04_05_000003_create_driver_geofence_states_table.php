<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     *
     * Creates the driver_geofence_states table which acts as the state machine
     * store for the geofencing engine. It tracks whether each driver is currently
     * inside each geofence, enabling the system to detect transitions (entry/exit)
     * rather than just evaluating current position in isolation.
     *
     * One record exists per driver-geofence pair. Records are upserted on each
     * location update where a crossing is detected.
     */
    public function up(): void
    {
        Schema::create('driver_geofence_states', function (Blueprint $table) {
            $table->id();
            $table->uuid('driver_uuid')->index();
            $table->uuid('geofence_uuid')->index();

            // Discriminator: 'zone' or 'service_area'
            $table->string('geofence_type', 50)->default('zone');

            // Whether the driver is currently inside this geofence
            $table->boolean('is_inside')->default(false)->index();

            // Timestamp of when the driver most recently entered this geofence
            $table->timestamp('entered_at')->nullable();

            // Timestamp of when the driver most recently exited this geofence
            $table->timestamp('exited_at')->nullable();

            // Queue job ID of the pending CheckGeofenceDwell job.
            // Stored so it can be cancelled if the driver exits before the dwell threshold.
            $table->string('dwell_job_id')->nullable();

            $table->timestamps();

            // Composite unique key: one state record per driver-geofence pair
            $table->unique(['driver_uuid', 'geofence_uuid'], 'driver_geofence_unique');

            // Cascade delete when the driver is deleted
            $table->foreign('driver_uuid')
                ->references('uuid')
                ->on('drivers')
                ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('driver_geofence_states');
    }
};
