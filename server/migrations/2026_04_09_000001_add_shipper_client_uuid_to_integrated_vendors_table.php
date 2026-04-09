<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     *
     * Adds shipper_client_uuid to integrated_vendors so a broker can
     * scope a single carrier credential record to a specific shipper
     * client (modeled as a Vendor in Fleetbase). When the column is
     * NULL, the record acts as the default/catch-all for that
     * provider, matching the resolver fallback behavior in
     * ServiceQuoteController auto-resolve (Phase 2 Task 19).
     *
     * @return void
     */
    public function up()
    {
        Schema::table('integrated_vendors', function (Blueprint $table) {
            $table->uuid('shipper_client_uuid')->nullable()->after('company_uuid');
            $table->foreign('shipper_client_uuid')
                ->references('uuid')->on('vendors')
                ->onDelete('set null');
            $table->index(['company_uuid', 'provider', 'shipper_client_uuid'], 'iv_company_provider_shipper_idx');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('integrated_vendors', function (Blueprint $table) {
            $table->dropIndex('iv_company_provider_shipper_idx');
            $table->dropForeign(['shipper_client_uuid']);
            $table->dropColumn('shipper_client_uuid');
        });
    }
};
