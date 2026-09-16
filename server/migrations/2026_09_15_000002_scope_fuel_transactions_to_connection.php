<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('fuel_provider_transactions', function (Blueprint $table) {
            $table->unique(['company_uuid', 'fuel_provider_connection_uuid', 'provider', 'active_provider_transaction_id'], 'fuel_provider_txn_connection_unique');
            $table->dropUnique('fuel_provider_txn_company_provider_unique');
        });
    }

    public function down(): void
    {
        // Restore the tighter constraint first; rollback must fail rather than
        // silently discard distinct sandbox and production transactions.
        Schema::table('fuel_provider_transactions', function (Blueprint $table) {
            $table->unique(['company_uuid', 'provider', 'active_provider_transaction_id'], 'fuel_provider_txn_company_provider_unique');
            $table->dropUnique('fuel_provider_txn_connection_unique');
        });
    }
};
