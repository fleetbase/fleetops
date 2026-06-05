<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('issues', function (Blueprint $table) {
            if (!Schema::hasColumn('issues', 'order_uuid')) {
                $table->uuid('order_uuid')->nullable()->index()->after('vehicle_uuid');
            }
        });
    }

    public function down(): void
    {
        Schema::table('issues', function (Blueprint $table) {
            if (Schema::hasColumn('issues', 'order_uuid')) {
                $table->dropColumn('order_uuid');
            }
        });
    }
};
