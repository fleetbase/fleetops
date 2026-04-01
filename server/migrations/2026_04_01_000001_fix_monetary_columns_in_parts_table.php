<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fix monetary columns in the parts table.
 *
 * - Convert unit_cost and msrp from decimal(12,2) to integer (cents)
 *   to align with the Fleetbase monetary storage standard (all amounts stored as cents).
 * - Add currency column for internationalisation support.
 */
return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('parts', function (Blueprint $table) {
            // Convert decimal monetary columns to integer (cents) — only needed on
            // existing installs where the original migration used decimal(12,2).
            // Fresh installs already have bigInteger from the updated create migration.
            $columnType = Schema::getColumnType('parts', 'unit_cost');
            if (in_array($columnType, ['decimal', 'float', 'double'])) {
                $table->bigInteger('unit_cost')->nullable()->change();
                $table->bigInteger('msrp')->nullable()->change();
            }

            // Add currency column for internationalisation if it doesn't exist yet
            if (!Schema::hasColumn('parts', 'currency')) {
                $table->string('currency')->nullable()->after('msrp');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('parts', function (Blueprint $table) {
            $table->decimal('unit_cost', 12, 2)->nullable()->change();
            $table->decimal('msrp', 12, 2)->nullable()->change();
            $table->dropColumn('currency');
        });
    }
};
