<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the `public_id` column to the `equipments` table on existing deployments.
 *
 * The original add_public_id_to_maintenance_tables migration referenced the table
 * as 'equipment' (singular) instead of 'equipments' (plural, the actual table name
 * used by the Equipment model). As a result the equipments table never received the
 * public_id column on any database that had already run that migration, causing a
 * SQLSTATE[42S22] "Unknown column 'public_id'" error whenever a new Equipment record
 * was saved (HasPublicId::generatePublicId checks for uniqueness against this column).
 *
 * This migration is fully idempotent: it checks both that the table exists and that
 * the column is absent before attempting to add it.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('equipments') && !Schema::hasColumn('equipments', 'public_id')) {
            Schema::table('equipments', function (Blueprint $table) {
                $table->string('public_id', 191)->nullable()->unique()->index()->after('_key');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('equipments') && Schema::hasColumn('equipments', 'public_id')) {
            Schema::table('equipments', function (Blueprint $table) {
                $table->dropColumn('public_id');
            });
        }
    }
};
