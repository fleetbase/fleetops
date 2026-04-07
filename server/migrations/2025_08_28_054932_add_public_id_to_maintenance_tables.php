<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the `public_id` column (required by the HasPublicId trait) to the four
 * maintenance-related tables that were created without it.
 */
return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $tables = ['parts', 'equipment', 'work_orders', 'maintenances'];

        foreach ($tables as $table) {
            if (Schema::hasTable($table) && !Schema::hasColumn($table, 'public_id')) {
                Schema::table($table, function (Blueprint $t) {
                    // Insert public_id right after _key to match the convention used
                    // in all other FleetOps tables (vehicles, drivers, orders, etc.)
                    $t->string('public_id', 191)->nullable()->unique()->index()->after('_key');
                });
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $tables = ['parts', 'equipment', 'work_orders', 'maintenances'];

        foreach ($tables as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'public_id')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->dropColumn('public_id');
                });
            }
        }
    }
};
