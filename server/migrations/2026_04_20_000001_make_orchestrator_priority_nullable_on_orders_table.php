<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Make `orchestrator_priority` nullable on the orders table.
 *
 * The original migration (2026_04_08_000003) added this column as NOT NULL
 * with a database-level default of 50.  When Eloquent explicitly passes NULL
 * for the column (e.g. when a user submits the order form without filling in
 * orchestrator constraints), the DB default is bypassed and MySQL raises:
 *
 *   SQLSTATE[23000]: Integrity constraint violation: 1048
 *   Column 'orchestrator_priority' cannot be null
 *
 * This migration makes the column nullable so that existing installations
 * that have already run the original migration are also protected.  The
 * application layer (Order model + controllers) already coerces null to 50,
 * so NULL values will not appear in practice; the nullable flag is a
 * belt-and-suspenders safety net for any code path that may have been missed.
 *
 * NOTE: We use raw DB::statement() instead of Blueprint::change() because
 * ->change() relies on Doctrine DBAL to introspect the existing column type,
 * and Doctrine does not recognise MySQL's TINYINT as a mapped type
 * ("Unknown column type tinyinteger requested"), causing the migration to
 * crash on deployment.  The raw ALTER TABLE statement is portable across all
 * MySQL/MariaDB versions supported by Fleetbase and requires no extra
 * dependencies.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::statement(
            'ALTER TABLE `orders` MODIFY COLUMN `orchestrator_priority` TINYINT UNSIGNED NULL DEFAULT 50'
        );
    }

    public function down(): void
    {
        // Back-fill any NULLs before restoring the NOT NULL constraint.
        DB::table('orders')
            ->whereNull('orchestrator_priority')
            ->update(['orchestrator_priority' => 50]);

        DB::statement(
            'ALTER TABLE `orders` MODIFY COLUMN `orchestrator_priority` TINYINT UNSIGNED NOT NULL DEFAULT 50'
        );
    }
};
