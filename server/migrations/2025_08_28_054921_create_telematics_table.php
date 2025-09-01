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
        Schema::create('telematics', function (Blueprint $table) {
            $table->increments('id');
            $table->uuid('uuid')->index();
            $table->string('_key')->nullable()->index();
            $table->foreignUuid('company_uuid')->constrained('companies', 'uuid')->cascadeOnDelete();

            $table->string('name')->nullable()->index();          // optional label
            $table->string('provider')->nullable()->index();       // samsara, geotab, custom, etc.
            $table->string('model')->nullable();
            $table->string('serial_number')->nullable()->index();
            $table->string('firmware_version')->nullable();
            $table->string('status')->default('active')->index();  // active, inactive

            // Connectivity identifiers
            $table->string('imei')->nullable()->index();
            $table->string('iccid')->nullable()->index();
            $table->string('imsi')->nullable()->index();
            $table->string('msisdn')->nullable()->index();
            $table->string('type')->nullable()->index();

            // Last heartbeat / metrics
            $table->json('last_metrics')->nullable();              // { lat, lng, speed, heading, temp, ... }

            $table->json('config')->nullable();
            $table->json('meta')->nullable();

            $table->foreignUuid('created_by_uuid')->nullable()->constrained('users', 'uuid')->nullOnDelete();
            $table->foreignUuid('updated_by_uuid')->nullable()->constrained('users', 'uuid')->nullOnDelete();
            $table->foreignUuid('warranty_uuid')->nullable()->constrained('warranties', 'uuid')->nullOnDelete();

            $table->timestamp('last_seen_at')->nullable()->index();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['company_uuid', 'provider']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('telematics');
        Schema::enableForeignKeyConstraints();
    }
};
