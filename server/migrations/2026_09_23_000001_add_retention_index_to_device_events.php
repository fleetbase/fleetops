<?php

use Fleetbase\FleetOps\Support\Database\TableIndexes;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supports the bounded retention sweep in fleetops:prune-telematics-data,
 * which ranges over these columns for every company on every run.
 */
return new class extends Migration {
    private const TABLE   = 'device_events';
    private const INDEX   = 'device_events_company_created_at_index';
    private const COLUMNS = ['company_uuid', 'created_at'];

    public function up(): void
    {
        if (!Schema::hasTable(self::TABLE) || !Schema::hasColumns(self::TABLE, self::COLUMNS)) {
            return;
        }

        if (TableIndexes::covers(self::TABLE, self::COLUMNS)) {
            return;
        }

        Schema::table(self::TABLE, fn (Blueprint $table) => $table->index(self::COLUMNS, self::INDEX));
    }

    public function down(): void
    {
        if (!Schema::hasTable(self::TABLE)) {
            return;
        }

        if ((TableIndexes::for(self::TABLE)[self::INDEX] ?? null) === self::COLUMNS) {
            Schema::table(self::TABLE, fn (Blueprint $table) => $table->dropIndex(self::INDEX));
        }
    }
};
