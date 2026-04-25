<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add per-stop POD and notes columns to the waypoints table.
 *
 * New columns mirror the equivalent fields on the orders table so that
 * individual stops can carry their own proof-of-delivery requirements and
 * driver-facing notes independently of the parent order.
 *
 * Until per-waypoint POD is fully implemented in the driver app, the
 * order-level pod_method / pod_required values are used as a fallback.
 *
 * New columns:
 *   - notes        Free-text driver instructions for this specific stop.
 *   - pod_method   Proof-of-delivery method (e.g. 'signature', 'photo', 'scan').
 *                  Mirrors the allowed values from config('fleetops.pod_methods').
 *   - pod_required Whether POD must be collected at this stop.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('waypoints', function (Blueprint $table) {
            $table->text('notes')->nullable()->after('service_time');
            $table->string('pod_method')->nullable()->after('notes');
            $table->boolean('pod_required')->default(false)->after('pod_method');
        });
    }

    public function down(): void
    {
        Schema::table('waypoints', function (Blueprint $table) {
            $table->dropColumn(['notes', 'pod_method', 'pod_required']);
        });
    }
};
