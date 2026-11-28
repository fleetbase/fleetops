<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When the order was given to the driver it has now. An order said who its driver
 * was but not since when, so a driver app could not say "assigned 20 minutes ago".
 * Set whenever the assigned driver changes and cleared when the order has none;
 * null for an order assigned before this column arrived.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'driver_assigned_at')) {
                $table->timestamp('driver_assigned_at')->nullable()->after('dispatched_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'driver_assigned_at')) {
                $table->dropColumn('driver_assigned_at');
            }
        });
    }
};
