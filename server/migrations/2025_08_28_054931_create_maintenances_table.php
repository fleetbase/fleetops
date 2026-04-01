<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('maintenances', function (Blueprint $table) {
            $table->increments('id');
            $table->uuid('uuid')->index();
            $table->string('_key')->nullable()->index();
            $table->foreignUuid('company_uuid')->constrained('companies', 'uuid')->cascadeOnDelete();
            $table->foreignUuid('category_uuid')->nullable()->constrained('categories', 'uuid')->nullOnDelete();

            // Target of maintenance (usually an asset; could be equipment)
            $table->string('maintainable_type')->nullable();
            $table->uuid('maintainable_uuid')->nullable();
            $table->index(['maintainable_type', 'maintainable_uuid']);

            $table->foreignUuid('work_order_uuid')->nullable()->constrained('work_orders', 'uuid')->nullOnDelete();

            $table->string('type')->nullable()->index();          // scheduled, unscheduled, inspection, corrective
            $table->string('status')->default('open')->index();   // open, in_progress, done, canceled
            $table->string('priority')->nullable()->index();      // low, normal, high, critical

            $table->timestamp('scheduled_at')->nullable()->index();
            $table->timestamp('started_at')->nullable()->index();
            $table->timestamp('completed_at')->nullable()->index();

            $table->unsignedBigInteger('odometer')->nullable();
            $table->unsignedBigInteger('engine_hours')->nullable();

            // Downtime
            $table->integer('estimated_downtime_hours')->nullable();
            $table->timestamp('downtime_start')->nullable();
            $table->timestamp('downtime_end')->nullable();

            // Vendor or internal performer
            $table->string('performed_by_type')->nullable();
            $table->uuid('performed_by_uuid')->nullable();
            $table->index(['performed_by_type', 'performed_by_uuid']);

            $table->text('summary')->nullable();
            $table->text('notes')->nullable();
            $table->json('line_items')->nullable(); // [{part_uuid, qty, unit_cost}, ...]
            $table->integer('labor_cost')->nullable();
            $table->integer('parts_cost')->nullable();
            $table->integer('tax')->nullable();
            $table->integer('total_cost')->nullable();
            $table->string('currency')->nullable();

            $table->json('attachments')->nullable();
            $table->json('meta')->nullable();

            $table->foreignUuid('created_by_uuid')->nullable()->constrained('users', 'uuid')->nullOnDelete();
            $table->foreignUuid('updated_by_uuid')->nullable()->constrained('users', 'uuid')->nullOnDelete();

            $table->softDeletes();
            $table->timestamps();

            $table->index(['company_uuid', 'status', 'type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('maintenances');
        Schema::enableForeignKeyConstraints();
    }
};
