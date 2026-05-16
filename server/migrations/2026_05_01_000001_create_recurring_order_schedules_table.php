<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('recurring_order_schedules', function (Blueprint $table) {
            $table->increments('id');
            $table->uuid('uuid')->index();
            $table->string('_key')->nullable()->index();
            $table->string('public_id', 191)->nullable()->unique()->index();
            $table->foreignUuid('company_uuid')->constrained('companies', 'uuid')->cascadeOnDelete();

            $table->string('name');
            $table->text('description')->nullable();
            $table->string('status')->default('active')->index();
            $table->string('timezone', 100)->default('UTC');
            $table->dateTime('starts_at')->nullable()->index();
            $table->dateTime('ends_at')->nullable()->index();
            $table->text('rrule');
            $table->dateTime('last_materialized_at')->nullable();
            $table->dateTime('materialization_horizon')->nullable()->index();

            $table->uuid('customer_uuid')->nullable()->index();
            $table->string('customer_type')->nullable();
            $table->uuid('facilitator_uuid')->nullable()->index();
            $table->string('facilitator_type')->nullable();
            $table->uuid('order_config_uuid')->nullable()->index();
            $table->uuid('driver_assigned_uuid')->nullable()->index();
            $table->uuid('vehicle_assigned_uuid')->nullable()->index();
            $table->uuid('service_rate_uuid')->nullable()->index();

            $table->json('template_order_meta')->nullable();
            $table->json('template_payload')->nullable();
            $table->json('template_entities')->nullable();
            $table->json('meta')->nullable();

            $table->foreignUuid('created_by_uuid')->nullable()->constrained('users', 'uuid')->nullOnDelete();
            $table->foreignUuid('updated_by_uuid')->nullable()->constrained('users', 'uuid')->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['company_uuid', 'status'], 'ros_company_status_idx');
            $table->index(['company_uuid', 'starts_at'], 'ros_company_starts_idx');
        });
    }

    public function down(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('recurring_order_schedules');
        Schema::enableForeignKeyConstraints();
    }
};
