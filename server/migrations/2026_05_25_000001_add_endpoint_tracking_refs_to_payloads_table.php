<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('payloads', function (Blueprint $table) {
            if (!Schema::hasColumn('payloads', 'pickup_tracking_number_uuid')) {
                $table->uuid('pickup_tracking_number_uuid')->nullable()->index('payloads_pickup_tracking_number_uuid_index')->after('pickup_uuid');
            }

            if (!Schema::hasColumn('payloads', 'dropoff_tracking_number_uuid')) {
                $table->uuid('dropoff_tracking_number_uuid')->nullable()->index('payloads_dropoff_tracking_number_uuid_index')->after('dropoff_uuid');
            }

            if (!Schema::hasColumn('payloads', 'return_tracking_number_uuid')) {
                $table->uuid('return_tracking_number_uuid')->nullable()->index('payloads_return_tracking_number_uuid_index')->after('return_uuid');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payloads', function (Blueprint $table) {
            if (Schema::hasColumn('payloads', 'pickup_tracking_number_uuid')) {
                $table->dropIndex('payloads_pickup_tracking_number_uuid_index');
                $table->dropColumn('pickup_tracking_number_uuid');
            }

            if (Schema::hasColumn('payloads', 'dropoff_tracking_number_uuid')) {
                $table->dropIndex('payloads_dropoff_tracking_number_uuid_index');
                $table->dropColumn('dropoff_tracking_number_uuid');
            }

            if (Schema::hasColumn('payloads', 'return_tracking_number_uuid')) {
                $table->dropIndex('payloads_return_tracking_number_uuid_index');
                $table->dropColumn('return_tracking_number_uuid');
            }
        });
    }
};
