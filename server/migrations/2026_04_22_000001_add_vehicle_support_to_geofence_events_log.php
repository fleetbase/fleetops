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
        Schema::table('geofence_events_log', function (Blueprint $table) {
            $table->uuid('subject_uuid')->nullable()->after('order_uuid')->index();
            $table->string('subject_type', 50)->default('driver')->after('subject_uuid')->index();
            $table->string('subject_name')->nullable()->after('subject_type');
            $table->uuid('driver_uuid')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('geofence_events_log', function (Blueprint $table) {
            $table->dropColumn(['subject_uuid', 'subject_type', 'subject_name']);
            $table->uuid('driver_uuid')->nullable(false)->change();
        });
    }
};
