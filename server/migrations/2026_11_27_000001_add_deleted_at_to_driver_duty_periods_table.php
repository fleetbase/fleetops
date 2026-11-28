<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The duty period model soft-deletes like every other model, so every read of
 * the table asks for `deleted_at`. The table was first created without it, and
 * a driver's day summary failed on that read.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('driver_duty_periods') || Schema::hasColumn('driver_duty_periods', 'deleted_at')) {
            return;
        }

        Schema::table('driver_duty_periods', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('driver_duty_periods') && Schema::hasColumn('driver_duty_periods', 'deleted_at')) {
            Schema::table('driver_duty_periods', function (Blueprint $table) {
                $table->dropSoftDeletes();
            });
        }
    }
};
