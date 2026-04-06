<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds columns that are present in the Part model's $fillable / $appends but
 * were omitted from the original create_parts_table migration:
 *
 *   - public_id  : human-readable unique identifier (e.g. part_xxxxx)
 *   - status     : stock / lifecycle status string (e.g. 'in_stock', 'out_of_stock')
 *   - currency   : ISO 4217 currency code for monetary columns
 */
return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('parts')) {
            return;
        }

        Schema::table('parts', function (Blueprint $table) {
            if (!Schema::hasColumn('parts', 'public_id')) {
                $table->string('public_id')->nullable()->unique()->after('uuid');
            }

            if (!Schema::hasColumn('parts', 'status')) {
                $table->string('status')->nullable()->default('in_stock')->index()->after('type');
            }

            if (!Schema::hasColumn('parts', 'currency')) {
                $table->string('currency', 3)->nullable()->after('msrp');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasTable('parts')) {
            return;
        }

        Schema::table('parts', function (Blueprint $table) {
            $columns = [];

            if (Schema::hasColumn('parts', 'public_id')) {
                $columns[] = 'public_id';
            }
            if (Schema::hasColumn('parts', 'status')) {
                $columns[] = 'status';
            }
            if (Schema::hasColumn('parts', 'currency')) {
                $columns[] = 'currency';
            }

            if (!empty($columns)) {
                $table->dropColumn($columns);
            }
        });
    }
};
