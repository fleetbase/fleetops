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
 *
 * Every step checks first and skips work that already exists. MySQL does not roll back DDL, so
 * a run that fails partway (the v0.8.0 production deploy stopped at `order_configs`) leaves the
 * earlier tables and columns in place, and a plain re-run would fail on "table already exists".
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('order_offers')) {
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
        }

        if (!Schema::hasTable('driver_ratings')) {
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
        }

        $this->addMissingColumns('orders', [
            'adhoc_strategy'        => fn (Blueprint $table) => $table->string('adhoc_strategy', 32)->nullable()->after('adhoc_distance'),
            'required_vehicle_type' => fn (Blueprint $table) => $table->string('required_vehicle_type')->nullable()->after('adhoc_strategy'),
            'chat_channel_uuid'     => fn (Blueprint $table) => $table->uuid('chat_channel_uuid')->nullable(),
        ]);

        $this->addMissingColumns('order_configs', [
            'policies' => fn (Blueprint $table) => $table->json('policies')->nullable(),
            'triggers' => fn (Blueprint $table) => $table->json('triggers')->nullable(),
        ]);

        $this->addMissingColumns('service_quotes', [
            'distance' => fn (Blueprint $table) => $table->unsignedInteger('distance')->nullable(),
            'time'     => fn (Blueprint $table) => $table->unsignedInteger('time')->nullable(),
        ]);

        $this->addMissingColumns('drivers', [
            'rating_average' => fn (Blueprint $table) => $table->decimal('rating_average', 3, 2)->nullable(),
            'rating_count'   => fn (Blueprint $table) => $table->unsignedInteger('rating_count')->default(0),
        ]);
    }

    public function down(): void
    {
        $this->dropExistingColumns('drivers', ['rating_average', 'rating_count']);
        $this->dropExistingColumns('service_quotes', ['distance', 'time']);
        $this->dropExistingColumns('order_configs', ['policies', 'triggers']);
        $this->dropExistingColumns('orders', ['adhoc_strategy', 'required_vehicle_type', 'chat_channel_uuid']);
        Schema::dropIfExists('driver_ratings');
        Schema::dropIfExists('order_offers');
    }

    /**
     * Add each column the table doesn't have yet, in the order given.
     *
     * @param array<string, \Closure(Blueprint): mixed> $columns
     */
    private function addMissingColumns(string $table, array $columns): void
    {
        $missing = array_filter($columns, fn ($column) => !Schema::hasColumn($table, $column), ARRAY_FILTER_USE_KEY);
        if (!$missing) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($missing) {
            foreach ($missing as $define) {
                $define($blueprint);
            }
        });
    }

    /**
     * @param array<int, string> $columns
     */
    private function dropExistingColumns(string $table, array $columns): void
    {
        $existing = array_values(array_filter($columns, fn ($column) => Schema::hasColumn($table, $column)));
        if ($existing) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropColumn($existing));
        }
    }
};
