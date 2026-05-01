<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->uuid('recurring_order_schedule_uuid')->nullable()->after('manifest_uuid')->index();
            $table->dateTime('recurring_occurrence_at')->nullable()->after('recurring_order_schedule_uuid')->index();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['recurring_order_schedule_uuid']);
            $table->dropIndex(['recurring_occurrence_at']);
            $table->dropColumn(['recurring_order_schedule_uuid', 'recurring_occurrence_at']);
        });
    }
};
