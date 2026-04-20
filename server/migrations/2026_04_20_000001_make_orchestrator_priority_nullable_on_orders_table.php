<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

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
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedTinyInteger('orchestrator_priority')->default(50)->nullable()->change();
        });
    }

    public function down(): void
    {
        // First back-fill any NULLs so the NOT NULL constraint can be restored.
        \Illuminate\Support\Facades\DB::table('orders')
            ->whereNull('orchestrator_priority')
            ->update(['orchestrator_priority' => 50]);

        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedTinyInteger('orchestrator_priority')->default(50)->nullable(false)->change();
        });
    }
};
