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
            $table->foreignUuid('telematic_uuid')->after('company_uuid')->nullable()->constrained('telematics', 'uuid')->nullOnDelete();
            $table->foreignUuid('warranty_uuid')->after('telematic_uuid')->nullable()->constrained('warranties', 'uuid')->nullOnDelete();
            $table->foreignUuid('category_uuid')->after('telematic_uuid')->nullable()->constrained('categories', 'uuid')->nullOnDelete();

            // Identification
            $table->string('serial_number')->nullable()->after('plate_number');
            $table->string('call_sign')->nullable()->after('plate_number');

            // Financial Tracking
            $table->integer('acquisition_cost')->nullable()->after('vin_data');
            $table->integer('current_value')->nullable()->after('vin_data');
            $table->integer('depreciation_rate')->nullable()->after('vin_data'); // -- Annual percentage
            $table->integer('insurance_value')->nullable()->after('vin_data');
            $table->string('currency')->nullable()->after('vin_data');
            $table->string('financing_status')->nullable()->index()->after('vin_data'); // -- owned, leased, financed
            $table->timestamp('lease_expires_at')->nullable()->index()->after('slug');
            $table->timestamp('purchased_at')->nullable()->index()->after('slug');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropForeign(['telematic_uuid']);
            $table->dropForeign(['warranty_uuid']);
            $table->dropForeign(['category_uuid']);
            $table->dropColumn('telematic_uuid');
            $table->dropColumn('warranty_uuid');
            $table->dropColumn('category_uuid');
            $table->dropColumn('serial_number');
            $table->dropColumn('call_sign');
            $table->dropColumn('acquisition_cost');
            $table->dropColumn('current_value');
            $table->dropColumn('depreciation_rate');
            $table->dropColumn('insurance_value');
            $table->dropColumn('currency');
            $table->dropColumn('financing_status');
            $table->dropColumn('lease_expires_at');
            $table->dropColumn('purchased_at');
        });
        Schema::enableForeignKeyConstraints();
    }
};
