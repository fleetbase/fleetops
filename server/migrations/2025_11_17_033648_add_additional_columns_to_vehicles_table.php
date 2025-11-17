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
        Schema::table('vehicles', function (Blueprint $table) {
            // Financing details
            $table->unsignedInteger('loan_number_of_payments')->nullable()->after('acquisition_cost');
            $table->date('loan_first_payment')->nullable()->after('acquisition_cost');
            $table->decimal('loan_amount', 12, 2)->nullable()->after('acquisition_cost');

            // Service life details
            $table->string('estimated_service_life_distance_unit', 16)->nullable()->after('acquisition_cost');
            $table->unsignedInteger('estimated_service_life_distance')->nullable()->after('acquisition_cost');
            $table->unsignedInteger('estimated_service_life_months')->nullable()->after('acquisition_cost');

            // Odometer at purchase (lifecycle context)
            $table->unsignedInteger('odometer_at_purchase')->nullable()->after('odometer');

            // Capacity and Dimensions
            $table->decimal('cargo_volume', 10, 2)->nullable()->after('fuel_volume_unit');
            $table->decimal('passenger_volume', 10, 2)->nullable()->after('fuel_volume_unit');
            $table->decimal('interior_volume', 10, 2)->nullable()->after('fuel_volume_unit');
            $table->decimal('weight', 10, 2)->nullable()->after('fuel_volume_unit');
            $table->decimal('width', 10, 2)->nullable()->after('fuel_volume_unit');
            $table->decimal('length', 10, 2)->nullable()->after('fuel_volume_unit');
            $table->decimal('height', 10, 2)->nullable()->after('fuel_volume_unit');
            $table->decimal('towing_capacity', 10, 2)->nullable()->after('fuel_volume_unit');
            $table->decimal('payload_capacity', 10, 2)->nullable()->after('fuel_volume_unit');
            $table->unsignedTinyInteger('seating_capacity')->nullable()->after('fuel_volume_unit');
            $table->decimal('ground_clearance', 10, 2)->nullable()->after('fuel_volume_unit');
            $table->decimal('bed_length', 10, 2)->nullable()->after('fuel_volume_unit');
            $table->decimal('fuel_capacity', 10, 2)->nullable()->after('fuel_volume_unit');

            // Regulatory / compliance
            $table->string('emission_standard', 32)->nullable()->after('payload_capacity');
            $table->boolean('dpf_equipped')->nullable()->after('emission_standard');
            $table->boolean('scr_equipped')->nullable()->after('dpf_equipped');
            $table->decimal('gvwr', 10, 2)->nullable()->after('scr_equipped'); // gross vehicle weight rating
            $table->decimal('gcwr', 10, 2)->nullable()->after('gvwr');         // gross combined weight rating

            // Engine specs
            $table->string('cylinder_arrangement', 8)->nullable()->after('serial_number');
            $table->unsignedTinyInteger('number_of_cylinders')->nullable()->after('serial_number');
            $table->unsignedInteger('torque_rpm')->nullable()->after('serial_number');
            $table->decimal('torque', 10, 2)->nullable()->after('serial_number');
            $table->unsignedInteger('horsepower_rpm')->nullable()->after('serial_number');
            $table->decimal('horsepower', 10, 2)->nullable()->after('serial_number');
            $table->decimal('engine_size', 5, 2)->nullable()->after('serial_number'); // e.g. 2.0 (L)
            $table->decimal('engine_displacement', 7, 2)->nullable()->after('serial_number'); // e.g. 1998.00 cc
            $table->string('engine_configuration', 32)->nullable()->after('serial_number'); // e.g. Inline-4
            $table->string('engine_family', 64)->nullable()->after('serial_number');
            $table->string('engine_make', 64)->nullable()->after('serial_number');
            $table->string('engine_model', 64)->nullable()->after('serial_number');
            $table->string('engine_number', 128)->nullable()->after('serial_number');

            // Indexes (engine + financing + compliance)
            $table->unique('engine_number', 'vehicles_engine_number_unique');
            $table->index('engine_make', 'vehicles_engine_make_index');
            $table->index('engine_model', 'vehicles_engine_model_index');
            $table->index('engine_family', 'vehicles_engine_family_index');
            $table->index('loan_first_payment', 'vehicles_loan_first_payment_index');
            $table->index('emission_standard', 'vehicles_emission_standard_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            // Drop indexes first
            $table->dropUnique('vehicles_engine_number_unique');
            $table->dropIndex('vehicles_engine_make_index');
            $table->dropIndex('vehicles_engine_model_index');
            $table->dropIndex('vehicles_engine_family_index');
            $table->dropIndex('vehicles_loan_first_payment_index');
            $table->dropIndex('vehicles_emission_standard_index');

            // Then drop columns
            $table->dropColumn([
                // Financing details
                'loan_number_of_payments',
                'loan_first_payment',
                'loan_amount',

                // Service life details
                'estimated_service_life_distance_unit',
                'estimated_service_life_distance',
                'estimated_service_life_months',

                // Odometer at purchase
                'odometer_at_purchase',

                // Capacity and Dimensions
                'cargo_volume',
                'passenger_volume',
                'interior_volume',
                'weight',
                'width',
                'length',
                'height',
                'towing_capacity',
                'payload_capacity',
                'seating_capacity',
                'ground_clearance',
                'bed_length',
                'fuel_capacity',

                // Regulatory / compliance
                'emission_standard',
                'dpf_equipped',
                'scr_equipped',
                'gvwr',
                'gcwr',

                // Engine specs
                'cylinder_arrangement',
                'number_of_cylinders',
                'torque_rpm',
                'torque',
                'horsepower_rpm',
                'horsepower',
                'engine_size',
                'engine_displacement',
                'engine_configuration',
                'engine_family',
                'engine_make',
                'engine_model',
                'engine_number',
            ]);
        });
    }
};
