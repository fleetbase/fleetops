<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adhoc dispatch strategies, order config policies and triggers, and driver ratings.
 *
 * - order_offers: who an adhoc order was offered to and how each driver answered.
 * - orders: `adhoc_strategy` and `required_vehicle_type` for how the order is offered, and
 *   `chat_channel_uuid` for its customer-driver chat.
 * - order_configs: `policies`, the settings that decide how the config's orders behave, and
 *   `triggers`, the actions it runs on order events.
 * - service_quotes: the `distance` (metres) and `time` (seconds) a quote was priced on.
 * - driver_ratings: a customer's stars for the driver of a finished order, with each driver's
 *   average and count kept on `drivers`.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('order_offers', function (Blueprint $table) {
            $table->increments('id');
            $table->uuid('uuid')->unique();
            $table->uuid('company_uuid')->nullable()->index();
            $table->uuid('order_uuid')->index();
            $table->uuid('driver_uuid')->index();
            $table->string('status', 16)->default('pending');
            $table->unsignedInteger('distance')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['order_uuid', 'status']);
            $table->index(['driver_uuid', 'status']);
        });

        Schema::create('driver_ratings', function (Blueprint $table) {
            $table->increments('id');
            $table->uuid('uuid')->unique();
            $table->uuid('company_uuid')->nullable()->index();
            $table->uuid('order_uuid')->unique();
            $table->uuid('driver_uuid')->index();
            $table->uuid('customer_uuid')->nullable()->index();
            $table->string('customer_type')->nullable();
            $table->unsignedTinyInteger('stars');
            $table->text('comment')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->string('adhoc_strategy', 32)->nullable()->after('adhoc_distance');
            $table->string('required_vehicle_type')->nullable()->after('adhoc_strategy');
            $table->uuid('chat_channel_uuid')->nullable();
        });

        Schema::table('order_configs', function (Blueprint $table) {
            $table->json('policies')->nullable();
            $table->json('triggers')->nullable();
        });

        Schema::table('service_quotes', function (Blueprint $table) {
            $table->unsignedInteger('distance')->nullable();
            $table->unsignedInteger('time')->nullable();
        });

        Schema::table('drivers', function (Blueprint $table) {
            $table->decimal('rating_average', 3, 2)->nullable();
            $table->unsignedInteger('rating_count')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('drivers', fn (Blueprint $table) => $table->dropColumn(['rating_average', 'rating_count']));
        Schema::table('service_quotes', fn (Blueprint $table) => $table->dropColumn(['distance', 'time']));
        Schema::table('order_configs', fn (Blueprint $table) => $table->dropColumn(['policies', 'triggers']));
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn(['adhoc_strategy', 'required_vehicle_type', 'chat_channel_uuid']));
        Schema::dropIfExists('driver_ratings');
        Schema::dropIfExists('order_offers');
    }
};
