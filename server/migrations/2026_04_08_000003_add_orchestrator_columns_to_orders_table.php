<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add Orchestrator constraint columns to the orders table.
 *
 * New columns:
 *   - orchestrator_priority   Integer priority score (10–100) for routing engine
 *   - required_skills         JSON array of skills required to service this order
 *   - time_window_start       Earliest acceptable completion datetime
 *   - time_window_end         Latest acceptable completion datetime
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedTinyInteger('orchestrator_priority')->default(50)->after('status');
            $table->json('required_skills')->nullable()->after('orchestrator_priority');
            $table->dateTime('time_window_start')->nullable()->after('required_skills');
            $table->dateTime('time_window_end')->nullable()->after('time_window_start');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                'orchestrator_priority',
                'required_skills',
                'time_window_start',
                'time_window_end',
            ]);
        });
    }
};
