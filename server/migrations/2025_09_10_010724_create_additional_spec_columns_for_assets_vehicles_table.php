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
            $table->mediumText('description')->after('photo_uuid')->nullable();
            $table->string('name')->after('photo_uuid')->nullable();
            $table->string('odometer_unit')->after('type')->nullable();
            $table->string('odometer')->after('type')->nullable();
            $table->string('ownership_type')->after('type')->nullable();
            $table->string('transmission')->after('trim')->nullable();
            $table->string('color')->after('trim')->nullable();
            $table->string('fuel_volume_unit')->after('trim')->nullable();
            $table->string('fuel_type')->after('trim')->nullable();
            $table->mediumText('notes')->after('meta')->nullable();
            $table->string('usage_type')->after('type')->nullable();
            $table->string('measurement_system')->after('type')->nullable();
            $table->json('vin_data')->change();
            $table->renameColumn('model_data', 'specs');
        });

        Schema::table('assets', function (Blueprint $table) {
            $table->string('ownership_type')->after('type')->nullable();
            $table->string('odometer_unit')->after('odometer')->nullable();
            $table->string('transmission')->after('engine_hours')->nullable();
            $table->string('fuel_volume_unit')->after('engine_hours')->nullable();
            $table->string('fuel_type')->after('engine_hours')->nullable();
            $table->mediumText('description')->after('name')->nullable();
            $table->mediumText('notes')->after('attributes')->nullable();
            $table->string('usage_type')->after('code')->nullable();
            $table->string('measurement_system')->after('code')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropColumn('odometer_unit');
            $table->dropColumn('transmission');
            $table->dropColumn('fuel_volume_unit');
            $table->dropColumn('fuel_type');
            $table->dropColumn('description');
            $table->dropColumn('notes');
            $table->dropColumn('usage_type');
            $table->dropColumn('measurement_system');
            $table->dropColumn('ownership_type');
        });

        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn('name');
            $table->dropColumn('description');
            $table->dropColumn('odometer_unit');
            $table->dropColumn('odometer');
            $table->dropColumn('transmission');
            $table->dropColumn('color');
            $table->dropColumn('fuel_type');
            $table->dropColumn('fuel_volume_unit');
            $table->dropColumn('notes');
            $table->dropColumn('usage_type');
            $table->dropColumn('measurement_system');
            $table->dropColumn('ownership_type');
            $table->mediumText('vin_data')->change();
            $table->renameColumn('specs', 'model_data');
        });
    }
};
