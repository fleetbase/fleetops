<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    /**
     * Backfill null directions for transactions linked to FleetOps orders.
     */
    public function up(): void
    {
        DB::table('transactions')
            ->join('orders', 'orders.transaction_uuid', '=', 'transactions.uuid')
            ->whereNull('transactions.direction')
            ->update(['transactions.direction' => 'credit']);
    }

    /**
     * Direction backfills are not reversible without losing real data.
     */
    public function down(): void
    {
    }
};
