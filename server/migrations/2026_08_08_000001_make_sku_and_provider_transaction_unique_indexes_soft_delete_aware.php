<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Both `parts` and `fuel_provider_transactions` soft-delete, but their unique
 * indexes were not soft-delete aware.  A deleted row kept occupying the key, so
 * re-creating a Part with a previously used SKU (or re-ingesting a fuel provider
 * transaction that had been deleted) raised a UniqueConstraintViolationException.
 * On the public v1 API that surfaced as an HTTP 500, and because there is no
 * restore/force-delete route the key was unusable from then on.
 *
 * The fix follows the pattern already used for alrashed contract numbers in
 * 2026_05_25_000001_make_contract_number_unique_for_active_contracts: a STORED
 * generated column that is NULL for soft-deleted rows.  MySQL permits any number
 * of NULLs in a unique index, so tombstones stop colliding while live rows stay
 * constrained.
 *
 * `fuel_provider_transactions` additionally gains `company_uuid` in the key.  The
 * old index was global, so one company's provider transaction id blocked — or,
 * via FuelProviderService::ingestTransaction()'s updateOrCreate, silently
 * overwrote — another company's.  Provider transaction ids are only unique within
 * the account they were issued for, so the key belongs per tenant.
 *
 * Both new indexes are strictly more permissive than the ones they replace, so no
 * existing row can violate them and no backfill is required.
 */
return new class extends Migration {
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // ---- parts: (company_uuid, sku) -> (company_uuid, active_sku) ----
        if (!Schema::hasColumn('parts', 'active_sku')) {
            Schema::table('parts', function (Blueprint $table) {
                $table->string('active_sku')->nullable()->storedAs('IF(`deleted_at` IS NULL, `sku`, NULL)');
            });
        }

        // The replacement index MUST be created before the old one is dropped:
        // parts_company_uuid_sku_unique is currently the only index covering
        // company_uuid, and parts_company_uuid_foreign depends on it. Adding an
        // index that also leads with company_uuid lets it take over as the
        // covering index, otherwise the drop below fails with errno 150.
        if (!$this->indexExists('parts', 'parts_company_uuid_active_sku_unique')) {
            Schema::table('parts', function (Blueprint $table) {
                $table->unique(['company_uuid', 'active_sku'], 'parts_company_uuid_active_sku_unique');
            });
        }

        if ($this->indexExists('parts', 'parts_company_uuid_sku_unique')) {
            Schema::table('parts', function (Blueprint $table) {
                $table->dropUnique('parts_company_uuid_sku_unique');
            });
        }

        // ---- fuel_provider_transactions: (provider, provider_transaction_id)
        //      -> (company_uuid, provider, active_provider_transaction_id) ----
        if (!Schema::hasColumn('fuel_provider_transactions', 'active_provider_transaction_id')) {
            Schema::table('fuel_provider_transactions', function (Blueprint $table) {
                $table->string('active_provider_transaction_id', 191)->nullable()->storedAs('IF(`deleted_at` IS NULL, `provider_transaction_id`, NULL)');
            });
        }

        if (!$this->indexExists('fuel_provider_transactions', 'fuel_provider_txn_company_provider_unique')) {
            Schema::table('fuel_provider_transactions', function (Blueprint $table) {
                $table->unique(['company_uuid', 'provider', 'active_provider_transaction_id'], 'fuel_provider_txn_company_provider_unique');
            });
        }

        if ($this->indexExists('fuel_provider_transactions', 'fuel_provider_txn_provider_unique')) {
            Schema::table('fuel_provider_transactions', function (Blueprint $table) {
                $table->dropUnique('fuel_provider_txn_provider_unique');
            });
        }
    }

    /**
     * Reverse the migrations.
     *
     * Restoring the old global index can fail if rows now exist that only the new
     * key permits (two tenants sharing a provider transaction id, or a live row
     * whose SKU matches a soft-deleted one). That is inherent to reversing a
     * relaxed constraint, so the old indexes are restored on a best-effort basis
     * and the generated columns are dropped either way.
     *
     * @return void
     */
    public function down()
    {
        if (!$this->indexExists('parts', 'parts_company_uuid_sku_unique')) {
            try {
                Schema::table('parts', function (Blueprint $table) {
                    $table->unique(['company_uuid', 'sku'], 'parts_company_uuid_sku_unique');
                });
            } catch (Throwable $e) {
                // Duplicate SKUs the new index allowed now block the old one.
            }
        }

        if ($this->indexExists('parts', 'parts_company_uuid_active_sku_unique')) {
            Schema::table('parts', function (Blueprint $table) {
                $table->dropUnique('parts_company_uuid_active_sku_unique');
            });
        }

        if (Schema::hasColumn('parts', 'active_sku')) {
            Schema::table('parts', function (Blueprint $table) {
                $table->dropColumn('active_sku');
            });
        }

        if (!$this->indexExists('fuel_provider_transactions', 'fuel_provider_txn_provider_unique')) {
            try {
                Schema::table('fuel_provider_transactions', function (Blueprint $table) {
                    $table->unique(['provider', 'provider_transaction_id'], 'fuel_provider_txn_provider_unique');
                });
            } catch (Throwable $e) {
                // Cross-tenant duplicates the new index allows now block the old one.
            }
        }

        if ($this->indexExists('fuel_provider_transactions', 'fuel_provider_txn_company_provider_unique')) {
            Schema::table('fuel_provider_transactions', function (Blueprint $table) {
                $table->dropUnique('fuel_provider_txn_company_provider_unique');
            });
        }

        if (Schema::hasColumn('fuel_provider_transactions', 'active_provider_transaction_id')) {
            Schema::table('fuel_provider_transactions', function (Blueprint $table) {
                $table->dropColumn('active_provider_transaction_id');
            });
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
