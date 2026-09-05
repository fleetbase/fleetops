<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One logical membership per (fleet, vehicle) and (fleet, driver) pair, enforced
 * by the database rather than by a read-then-write in the controller.
 *
 * The public membership endpoints are idempotent by checking for an existing
 * pivot and creating one only if there is none. That holds for sequential calls
 * and fails for concurrent ones: two requests can both find nothing and both
 * insert, leaving a fleet with the same vehicle twice. An importer that retries
 * a timed-out request is exactly the caller that produces this.
 *
 * The index deliberately covers soft-deleted rows too. Unlike the SKU and
 * provider-transaction keys — where a tombstone must free the key, and does so
 * through a generated column that is NULL when deleted — a removed membership
 * here must keep occupying its key, because re-assigning restores that row
 * rather than inserting a second one. Letting a tombstone free the key would
 * reintroduce the duplicate it is meant to prevent.
 *
 * Rows with a NULL fleet_uuid or subject_uuid are left alone: MySQL permits any
 * number of NULLs in a unique index, and such a row is orphaned data rather than
 * a membership.
 */
return new class extends Migration {
    /**
     * The pivots, and the column naming the member on each.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private array $pivots = [
        'fleet_vehicles' => ['vehicle_uuid', 'fleet_vehicles_fleet_vehicle_unique'],
        'fleet_drivers'  => ['driver_uuid', 'fleet_drivers_fleet_driver_unique'],
    ];

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        foreach ($this->pivots as $table => [$memberColumn, $indexName]) {
            if (!Schema::hasTable($table)) {
                continue;
            }

            $this->removeDuplicateMemberships($table, $memberColumn);

            if (!$this->indexExists($table, $indexName)) {
                Schema::table($table, function (Blueprint $blueprint) use ($memberColumn, $indexName) {
                    $blueprint->unique(['fleet_uuid', $memberColumn], $indexName);
                });
            }
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        foreach ($this->pivots as $table => [$memberColumn, $indexName]) {
            if (Schema::hasTable($table) && $this->indexExists($table, $indexName)) {
                Schema::table($table, function (Blueprint $blueprint) use ($indexName) {
                    $blueprint->dropUnique($indexName);
                });
            }
        }
    }

    /**
     * Collapse every duplicated pair down to the one row worth keeping.
     *
     * An active row wins over a tombstone, because that is the membership the
     * fleet actually has today. Among equals the lowest id wins, so the outcome
     * is deterministic and a re-run is a no-op. When every row for a pair is
     * soft-deleted, one is still kept — removing them all would turn a later
     * re-assignment into a new row and lose the original membership's history.
     *
     * Only redundant pivot rows are removed. No fleet, vehicle or driver is
     * touched, and no surviving membership changes state.
     */
    private function removeDuplicateMemberships(string $table, string $memberColumn): void
    {
        $duplicatePairs = DB::table($table)
            ->select('fleet_uuid', $memberColumn)
            ->whereNotNull('fleet_uuid')
            ->whereNotNull($memberColumn)
            ->groupBy('fleet_uuid', $memberColumn)
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicatePairs as $pair) {
            $pair = (array) $pair;

            $rows = DB::table($table)
                ->where('fleet_uuid', $pair['fleet_uuid'])
                ->where($memberColumn, $pair[$memberColumn])
                // Active rows first, then oldest first.
                ->orderByRaw('CASE WHEN deleted_at IS NULL THEN 0 ELSE 1 END')
                ->orderBy('id')
                ->pluck('id');

            $redundant = $rows->slice(1)->values();

            if ($redundant->isNotEmpty()) {
                DB::table($table)->whereIn('id', $redundant->all())->delete();
            }
        }
    }

    private function indexExists(string $table, string $index): bool
    {
        $database = DB::connection()->getDatabaseName();

        return DB::table('information_schema.statistics')
            ->where('table_schema', $database)
            ->where('table_name', $table)
            ->where('index_name', $index)
            ->exists();
    }
};
