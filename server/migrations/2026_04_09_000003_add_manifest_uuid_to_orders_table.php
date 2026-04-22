<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add manifest_uuid to the orders table.
 *
 * Links each order to the Manifest it belongs to after an orchestration
 * commit. Nullable — orders that have not been through the orchestrator
 * (or were assigned manually) will have no manifest.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->char('manifest_uuid', 36)->nullable()->after('vehicle_assigned_uuid')->index();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('manifest_uuid');
        });
    }
};
