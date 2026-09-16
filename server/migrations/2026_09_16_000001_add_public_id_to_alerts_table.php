<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Give the core `alerts` table the `public_id` its model writes.
     *
     * `Fleetbase\Models\Alert` uses `HasPublicId`, whose creating hook checks
     * `alerts.public_id` for a collision before assigning one, but the core
     * migration never created the column. Every alert insert therefore fails
     * with "Unknown column 'public_id'": Radar's acknowledge and snooze (which
     * create a state row on first use), and the low-stock, sensor and device
     * event alerts FleetOps already raises.
     *
     * Guarded, so it is a no-op wherever core-api adds the column itself.
     */
    public function up(): void
    {
        if (!Schema::hasTable('alerts') || Schema::hasColumn('alerts', 'public_id')) {
            return;
        }

        Schema::table('alerts', function (Blueprint $table) {
            $table->string('public_id', 191)->nullable()->unique()->after('uuid');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasTable('alerts') || !Schema::hasColumn('alerts', 'public_id')) {
            return;
        }

        Schema::table('alerts', function (Blueprint $table) {
            $table->dropUnique(['public_id']);
            $table->dropColumn('public_id');
        });
    }
};
