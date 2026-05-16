<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('recurring_order_schedule_occurrences', function (Blueprint $table) {
            $table->increments('id');
            $table->uuid('uuid')->index();
            $table->string('_key')->nullable()->index();
            $table->string('public_id', 191)->nullable()->unique()->index();
            $table->foreignUuid('company_uuid')->constrained('companies', 'uuid')->cascadeOnDelete();
            $table->uuid('recurring_order_schedule_uuid');
            $table->uuid('order_uuid')->nullable()->index();
            $table->dateTime('occurrence_at')->index();
            $table->string('status')->default('generated')->index();
            $table->string('reason')->nullable();
            $table->json('meta')->nullable();
            $table->foreignUuid('created_by_uuid')->nullable()->constrained('users', 'uuid')->nullOnDelete();
            $table->foreignUuid('updated_by_uuid')->nullable()->constrained('users', 'uuid')->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['recurring_order_schedule_uuid', 'occurrence_at'], 'roso_schedule_occurrence_unique');
            $table->index(['company_uuid', 'occurrence_at'], 'roso_company_occurrence_idx');
            $table->index('recurring_order_schedule_uuid', 'roso_schedule_uuid_idx');
        });
    }

    public function down(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('recurring_order_schedule_occurrences');
        Schema::enableForeignKeyConstraints();
    }
};
