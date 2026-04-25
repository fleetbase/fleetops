<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     *
     * Creates the geofence_events_log table which provides a persistent,
     * queryable audit trail of all geofence events (entered, exited, dwelled).
     * This table powers the reporting dashboard, dwell time analytics, and
     * geofence activity history views.
     */
    public function up(): void
    {
        Schema::create('geofence_events_log', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique()->index();
            $table->uuid('company_uuid')->index();
            $table->uuid('driver_uuid')->index();
            $table->uuid('vehicle_uuid')->nullable()->index();
            $table->uuid('order_uuid')->nullable()->index();
            $table->uuid('geofence_uuid')->index();

            // Discriminator: 'zone' or 'service_area'
            $table->string('geofence_type', 50)->default('zone');

            // Snapshot of the geofence name at the time of the event
            $table->string('geofence_name')->nullable();

            // The type of geofence event
            $table->enum('event_type', ['entered', 'exited', 'dwelled'])->index();

            // Driver location at the time of the event
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();

            // Driver speed at the time of the event (km/h)
            $table->decimal('speed_kmh', 8, 2)->nullable();

            // Populated for 'exited' and 'dwelled' events
            $table->unsignedInteger('dwell_duration_minutes')->nullable();

            // The precise time the event occurred (not the DB insert time)
            $table->timestamp('occurred_at')->index();

            $table->timestamps();

            // Composite indexes for common query patterns
            $table->index(['company_uuid', 'occurred_at'], 'gel_company_occurred_idx');
            $table->index(['geofence_uuid', 'occurred_at'], 'gel_geofence_occurred_idx');
            $table->index(['driver_uuid', 'occurred_at'], 'gel_driver_occurred_idx');
            $table->index(['order_uuid', 'event_type'], 'gel_order_event_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('geofence_events_log');
    }
};
