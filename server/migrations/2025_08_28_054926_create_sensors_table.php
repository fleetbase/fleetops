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
        Schema::create('sensors', function (Blueprint $table) {
            $table->increments('id');
            $table->uuid('uuid')->index();
            $table->string('_key')->nullable()->index();
            $table->foreignUuid('company_uuid')->constrained('companies', 'uuid')->cascadeOnDelete();

            $table->string('name')->nullable()->index();
            $table->string('slug')->nullable()->index();
            $table->string('sensor_type')->index();             // temp, humidity, door, fuel, pressure, vibration, cargo-weight, etc.
            $table->string('unit')->nullable();                 // C, F, %, psi, kg, etc.
            $table->float('min_threshold')->nullable();
            $table->float('max_threshold')->nullable();
            $table->boolean('threshold_inclusive')->default(true);
            $table->string('type')->nullable()->index();

            $table->timestamp('last_reading_at')->nullable()->index();
            $table->string('last_value')->nullable();           // use string to store raw value
            $table->json('calibration')->nullable();            // { offset, slope, notes }
            $table->unsignedInteger('report_frequency_sec')->nullable();

            // A sensor can belong to a device, asset, or any other entity
            $table->string('sensorable_type')->nullable();
            $table->uuid('sensorable_uuid')->nullable();
            $table->index(['sensorable_type', 'sensorable_uuid']);

            // Optionally coupled to a device (if it streams via a device)
            $table->foreignUuid('device_uuid')->nullable()->constrained('devices', 'uuid')->nullOnDelete();
            $table->foreignUuid('warranty_uuid')->nullable()->constrained('warranties', 'uuid')->nullOnDelete();

            $table->json('meta')->nullable();
            $table->foreignUuid('created_by_uuid')->nullable()->constrained('users', 'uuid')->nullOnDelete();
            $table->foreignUuid('updated_by_uuid')->nullable()->constrained('users', 'uuid')->nullOnDelete();

            $table->softDeletes();
            $table->timestamps();

            $table->index(['company_uuid', 'sensor_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('sensors');
        Schema::enableForeignKeyConstraints();
    }
};
