<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('device_events')) {
            return;
        }

        Schema::table('device_events', function (Blueprint $table) {
            if (!Schema::hasColumn('device_events', 'message')) {
                $table->text('message')->nullable()->after('severity');
            }

            if (!Schema::hasColumn('device_events', 'occurred_at')) {
                $table->timestamp('occurred_at')->nullable()->index()->after('message');
            }

            if (!Schema::hasColumn('device_events', 'processed_at')) {
                $table->timestamp('processed_at')->nullable()->index()->after('occurred_at');
            }

            if (!Schema::hasColumn('device_events', 'data')) {
                $table->json('data')->nullable()->after('payload');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('device_events')) {
            return;
        }

        Schema::table('device_events', function (Blueprint $table) {
            if (Schema::hasColumn('device_events', 'data')) {
                $table->dropColumn('data');
            }

            if (Schema::hasColumn('device_events', 'processed_at')) {
                $table->dropIndex(['processed_at']);
                $table->dropColumn('processed_at');
            }

            if (Schema::hasColumn('device_events', 'occurred_at')) {
                $table->dropIndex(['occurred_at']);
                $table->dropColumn('occurred_at');
            }

            if (Schema::hasColumn('device_events', 'message')) {
                $table->dropColumn('message');
            }
        });
    }
};
