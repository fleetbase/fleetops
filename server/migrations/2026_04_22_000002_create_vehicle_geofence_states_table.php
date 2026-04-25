<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('vehicle_geofence_states', function (Blueprint $table) {
            $table->id();
            $table->uuid('vehicle_uuid')->index();
            $table->uuid('geofence_uuid')->index();
            $table->string('geofence_type', 50)->default('zone');
            $table->boolean('is_inside')->default(false)->index();
            $table->timestamp('entered_at')->nullable();
            $table->timestamp('exited_at')->nullable();
            $table->string('dwell_job_id')->nullable();
            $table->timestamps();

            $table->unique(['vehicle_uuid', 'geofence_uuid'], 'vehicle_geofence_unique');
            $table->foreign('vehicle_uuid')
                ->references('uuid')
                ->on('vehicles')
                ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vehicle_geofence_states');
    }
};
