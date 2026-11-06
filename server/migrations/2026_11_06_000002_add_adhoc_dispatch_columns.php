<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How adhoc orders are offered to drivers, as real columns:
 * - orders: `adhoc_strategy` overrides the config and company strategy for one order, and
 *   `required_vehicle_type` limits the order to drivers whose vehicle is of that type.
 * - order_configs: `adhoc_strategy` and `adhoc_offer_timeout` for orders on the config,
 *   `customer_orders_adhoc` makes orders customers place adhoc, and `match_vehicle_type` prices
 *   and matches customer orders by the vehicle type of their fare.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('adhoc_strategy', 32)->nullable()->after('adhoc_distance');
            $table->string('required_vehicle_type')->nullable()->after('adhoc_strategy');
        });

        Schema::table('order_configs', function (Blueprint $table) {
            $table->string('adhoc_strategy', 32)->nullable();
            $table->unsignedSmallInteger('adhoc_offer_timeout')->nullable();
            $table->boolean('customer_orders_adhoc')->default(false);
            $table->boolean('match_vehicle_type')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['adhoc_strategy', 'required_vehicle_type']);
        });

        Schema::table('order_configs', function (Blueprint $table) {
            $table->dropColumn(['adhoc_strategy', 'adhoc_offer_timeout', 'customer_orders_adhoc', 'match_vehicle_type']);
        });
    }
};
