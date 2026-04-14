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
        Schema::create('maintenance_schedules', function (Blueprint $table) {
            $table->increments('id');
            $table->uuid('uuid')->index();
            $table->string('_key')->nullable()->index();
            $table->string('public_id', 191)->nullable()->unique()->index();
            $table->foreignUuid('company_uuid')->constrained('companies', 'uuid')->cascadeOnDelete();

            // The asset this schedule applies to (vehicle, equipment, etc.)
            $table->string('subject_type')->nullable();
            $table->uuid('subject_uuid')->nullable();
            $table->index(['subject_type', 'subject_uuid']);

            $table->string('name');
            $table->string('type')->nullable()->index();          // oil_change, inspection, tire_rotation, etc.
            $table->string('status')->default('active')->index(); // active, paused, completed

            // Interval definition — one or more can be set; whichever triggers first wins
            $table->string('interval_method')->nullable()->index(); // time, distance, engine_hours — the user-selected method
            $table->string('interval_type')->nullable()->index();   // time, distance, engine_hours, or combined (legacy/computed)
            $table->unsignedInteger('interval_value')->nullable();  // e.g. 5000
            $table->string('interval_unit')->nullable();            // km, miles, days, months

            // Odometer / engine-hour thresholds
            $table->unsignedBigInteger('interval_distance')->nullable();     // distance in base unit (km)
            $table->unsignedBigInteger('interval_engine_hours')->nullable(); // engine hours

            // Baseline readings (set when schedule is created or reset after completion)
            $table->unsignedBigInteger('last_service_odometer')->nullable();
            $table->unsignedBigInteger('last_service_engine_hours')->nullable();
            $table->timestamp('last_service_date')->nullable();

            // Next-due thresholds (computed and stored for fast querying)
            $table->timestamp('next_due_date')->nullable()->index();
            $table->unsignedBigInteger('next_due_odometer')->nullable()->index();
            $table->unsignedBigInteger('next_due_engine_hours')->nullable()->index();

            // Default work order settings for auto-generation
            $table->string('default_priority')->default('normal'); // low, normal, high, critical
            $table->string('default_assignee_type')->nullable();
            $table->uuid('default_assignee_uuid')->nullable();
            $table->index(['default_assignee_type', 'default_assignee_uuid'], 'ms_default_assignee_idx');

            $table->text('instructions')->nullable();
            $table->json('meta')->nullable();

            $table->foreignUuid('created_by_uuid')->nullable()->constrained('users', 'uuid')->nullOnDelete();
            $table->foreignUuid('updated_by_uuid')->nullable()->constrained('users', 'uuid')->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['company_uuid', 'status']);
            $table->index(['next_due_date', 'status']);
        });

        // Add schedule_uuid FK to work_orders for traceability
        Schema::table('work_orders', function (Blueprint $table) {
            $table->uuid('schedule_uuid')->nullable()->after('company_uuid');
            $table->index('schedule_uuid');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->dropIndex(['schedule_uuid']);
            $table->dropColumn('schedule_uuid');
        });

        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('maintenance_schedules');
        Schema::enableForeignKeyConstraints();
    }
};
