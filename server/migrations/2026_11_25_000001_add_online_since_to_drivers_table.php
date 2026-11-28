<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When the driver last went on duty. `online` alone says a driver is on duty but
 * not for how long, so a driver app could not show "on duty since 08:14". Null
 * while off duty, and null for a driver who was already on duty when this
 * column arrived: nothing recorded when they started.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('drivers', function (Blueprint $table) {
            if (!Schema::hasColumn('drivers', 'online_since')) {
                $table->timestamp('online_since')->nullable()->after('online');
            }
        });
    }

    public function down(): void
    {
        Schema::table('drivers', function (Blueprint $table) {
            if (Schema::hasColumn('drivers', 'online_since')) {
                $table->dropColumn('online_since');
            }
        });
    }
};
