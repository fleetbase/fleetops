<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create the manifest_stops table.
 *
 * A ManifestStop is the ordered join between a Manifest and an Order.
 * Each stop represents a single physical location the driver must visit,
 * with its VROOM-optimised sequence, ETA, and progress status.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('manifest_stops', function (Blueprint $table) {
            $table->uuid('uuid')->primary();
            $table->string('public_id', 23)->unique()->nullable();
            $table->char('manifest_uuid', 36)->index();
            $table->char('order_uuid', 36)->index();
            $table->char('place_uuid', 36)->nullable()->index();
            // FK to waypoints if the stop corresponds to a specific payload waypoint
            // (nullable for simple single-dropoff orders where no explicit Waypoint row exists)
            $table->char('waypoint_uuid', 36)->nullable()->index();
            $table->string('status', 50)->default('pending')->index();
            // 'pending'   — not yet visited
            // 'arrived'   — driver entered geofence / tapped "Arrived"
            // 'completed' — delivery confirmed
            // 'skipped'   — dispatcher or driver skipped this stop
            $table->unsignedSmallInteger('sequence')->default(0);
            $table->timestamp('estimated_arrival')->nullable();
            $table->timestamp('actual_arrival')->nullable();
            $table->unsignedInteger('distance_from_prev_m')->default(0);
            $table->unsignedInteger('duration_from_prev_s')->default(0);
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('manifest_uuid')->references('uuid')->on('manifests')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manifest_stops');
    }
};
