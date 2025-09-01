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
        Schema::create('assets', function (Blueprint $table) {
            $table->increments('id');
            $table->uuid('uuid')->index();
            $table->string('_key')->nullable()->index();
            $table->foreignUuid('company_uuid')->constrained('companies', 'uuid')->cascadeOnDelete();
            $table->foreignUuid('category_uuid')->nullable()->constrained('categories', 'uuid')->nullOnDelete();

            // Asset operator - can be vendor, contact, driver, user
            $table->string('operator_type')->nullable();
            $table->uuid('operator_uuid')->nullable();
            $table->index(['operator_type', 'operator_uuid']);

            // Asset assigned to - can be vendor, contact, driver, user
            $table->string('assigned_to_type')->nullable();
            $table->uuid('assigned_to_uuid')->nullable();
            $table->index(['assigned_to_type', 'assigned_to_uuid']);

            $table->string('name')->index();
            $table->string('code')->nullable()->index();          // human code / fleet tag
            $table->string('type')->nullable()->index();          // vehicle, trailer, container, drone, etc.
            $table->string('status')->default('active')->index(); // active, inactive, retired, maintenance
            $table->point('location')->nullable();
            $table->string('speed')->nullable();
            $table->string('heading')->nullable();
            $table->string('altitude')->nullable();

            // Financial Tracking
            $table->integer('acquisition_cost')->nullable();
            $table->integer('current_value')->nullable();
            $table->integer('depreciation_rate')->nullable(); // -- Annual percentage
            $table->integer('insurance_value')->nullable();
            $table->string('currency')->nullable();
            $table->string('financing_status')->nullable(); // -- owned, leased, financed
            $table->date('lease_expires_at')->nullable()->index();

            // Identity / registration
            $table->string('vin')->nullable()->index();
            $table->string('plate_number')->nullable()->index();
            $table->string('make')->nullable();
            $table->string('model')->nullable();
            $table->unsignedSmallInteger('year')->nullable();
            $table->string('color')->nullable();
            $table->string('serial_number')->nullable()->index();
            $table->string('call_sign')->nullable()->index();
            $table->string('slug')->nullable()->index();

            // Attachments & relations
            $table->foreignUuid('vendor_uuid')->nullable()->constrained('vendors', 'uuid')->nullOnDelete();
            $table->foreignUuid('current_place_uuid')->nullable()->constrained('places', 'uuid')->nullOnDelete();
            $table->foreignUuid('telematic_uuid')->nullable()->constrained('telematics', 'uuid')->nullOnDelete();

            // Operational attributes
            $table->unsignedBigInteger('odometer')->nullable();     // in meters or units you prefer
            $table->unsignedBigInteger('engine_hours')->nullable();
            $table->decimal('gvw', 10, 2)->nullable();               // gross vehicle weight
            $table->json('capacity')->nullable();                    // e.g. { volume_l:..., payload_kg:... }
            $table->json('specs')->nullable();                       // arbitrary spec map
            $table->json('attributes')->nullable();                  // freeform tags/flags

            // Ownership, auditing
            $table->foreignUuid('created_by_uuid')->nullable()->constrained('users', 'uuid')->nullOnDelete();
            $table->foreignUuid('updated_by_uuid')->nullable()->constrained('users', 'uuid')->nullOnDelete();
            $table->foreignUuid('warranty_uuid')->nullable()->constrained('warranties', 'uuid')->nullOnDelete();

            $table->softDeletes();
            $table->timestamps();

            $table->index(['company_uuid', 'status']);
            $table->unique(['company_uuid', 'code']); // ensure human code unique per company
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('assets');
        Schema::enableForeignKeyConstraints();
    }
};
