<?php

use Fleetbase\FleetOps\Support\Database\TableIndexes;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * fleetops:prune-telematics-data purges soft-deleted rows on every run; without an
 * index on deleted_at that sweep walks the whole table even when nothing is trashed.
 */
return new class extends Migration {
    private const TABLE   = 'device_events';
    private const INDEX   = 'device_events_company_deleted_at_index';
    private const COLUMNS = ['company_uuid', 'deleted_at'];

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
