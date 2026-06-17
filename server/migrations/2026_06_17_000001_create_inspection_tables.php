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
        Schema::create('inspection_forms', function (Blueprint $table) {
            $table->increments('id');
            $table->uuid('uuid')->index();
            $table->string('_key')->nullable()->index();
            $table->string('public_id', 191)->nullable()->unique()->index();
            $table->foreignUuid('company_uuid')->constrained('companies', 'uuid')->cascadeOnDelete();

            $table->string('name')->index();
            $table->text('description')->nullable();
            $table->string('type')->default('dvir')->index();
            $table->string('status')->default('draft')->index();
            $table->string('frequency')->nullable()->index();

            $table->string('subject_type')->nullable();
            $table->uuid('subject_uuid')->nullable();
            $table->index(['subject_type', 'subject_uuid']);

            $table->json('items')->nullable();
            $table->json('settings')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('published_at')->nullable()->index();

            $table->foreignUuid('created_by_uuid')->nullable()->constrained('users', 'uuid')->nullOnDelete();
            $table->foreignUuid('updated_by_uuid')->nullable()->constrained('users', 'uuid')->nullOnDelete();

            $table->softDeletes();
            $table->timestamps();

            $table->index(['company_uuid', 'status', 'type']);
        });

        Schema::create('inspection_submissions', function (Blueprint $table) {
            $table->increments('id');
            $table->uuid('uuid')->index();
            $table->string('_key')->nullable()->index();
            $table->string('public_id', 191)->nullable()->unique()->index();
            $table->foreignUuid('company_uuid')->constrained('companies', 'uuid')->cascadeOnDelete();
            $table->foreignUuid('inspection_form_uuid')->nullable()->constrained('inspection_forms', 'uuid')->nullOnDelete();
            $table->foreignUuid('vehicle_uuid')->nullable()->constrained('vehicles', 'uuid')->nullOnDelete();
            $table->foreignUuid('driver_uuid')->nullable()->constrained('drivers', 'uuid')->nullOnDelete();
            $table->foreignUuid('submitted_by_uuid')->nullable()->constrained('users', 'uuid')->nullOnDelete();
            $table->foreignUuid('issue_uuid')->nullable()->constrained('issues', 'uuid')->nullOnDelete();
            $table->foreignUuid('work_order_uuid')->nullable()->constrained('work_orders', 'uuid')->nullOnDelete();

            $table->string('type')->default('dvir')->index();
            $table->string('status')->default('draft')->index();
            $table->string('result')->nullable()->index();
            $table->string('source')->nullable()->index();
            $table->unsignedBigInteger('odometer')->nullable();
            $table->unsignedBigInteger('engine_hours')->nullable();
            $table->unsignedInteger('total_items')->default(0);
            $table->unsignedInteger('failed_items')->default(0);
            $table->timestamp('started_at')->nullable()->index();
            $table->timestamp('submitted_at')->nullable()->index();
            $table->timestamp('resolved_at')->nullable()->index();

            $table->json('location')->nullable();
            $table->json('signature')->nullable();
            $table->json('attachments')->nullable();
            $table->json('meta')->nullable();

            $table->foreignUuid('created_by_uuid')->nullable()->constrained('users', 'uuid')->nullOnDelete();
            $table->foreignUuid('updated_by_uuid')->nullable()->constrained('users', 'uuid')->nullOnDelete();

            $table->softDeletes();
            $table->timestamps();

            $table->index(['company_uuid', 'status', 'result']);
            $table->index(['vehicle_uuid', 'submitted_at']);
        });

        Schema::create('inspection_item_results', function (Blueprint $table) {
            $table->increments('id');
            $table->uuid('uuid')->index();
            $table->string('_key')->nullable()->index();
            $table->foreignUuid('company_uuid')->constrained('companies', 'uuid')->cascadeOnDelete();
            $table->foreignUuid('inspection_submission_uuid')->constrained('inspection_submissions', 'uuid')->cascadeOnDelete();
            $table->foreignUuid('issue_uuid')->nullable()->constrained('issues', 'uuid')->nullOnDelete();
            $table->foreignUuid('work_order_uuid')->nullable()->constrained('work_orders', 'uuid')->nullOnDelete();

            $table->string('item_key')->nullable()->index();
            $table->string('label')->index();
            $table->string('category')->nullable()->index();
            $table->string('status')->default('passed')->index();
            $table->string('severity')->nullable()->index();
            $table->boolean('passed')->default(true)->index();
            $table->text('comments')->nullable();
            $table->json('photos')->nullable();
            $table->json('meta')->nullable();

            $table->foreignUuid('created_by_uuid')->nullable()->constrained('users', 'uuid')->nullOnDelete();
            $table->foreignUuid('updated_by_uuid')->nullable()->constrained('users', 'uuid')->nullOnDelete();

            $table->softDeletes();
            $table->timestamps();

            $table->index(['company_uuid', 'passed', 'severity']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('inspection_item_results');
        Schema::dropIfExists('inspection_submissions');
        Schema::dropIfExists('inspection_forms');
        Schema::enableForeignKeyConstraints();
    }
};
