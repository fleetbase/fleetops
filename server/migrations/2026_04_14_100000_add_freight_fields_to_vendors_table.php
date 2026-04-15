<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->string('scac_code', 10)->nullable()->index()->after('name');
            $table->string('mc_number', 20)->nullable()->index()->after('scac_code');
            $table->string('dot_number', 20)->nullable()->index()->after('mc_number');
            $table->date('insurance_expiry')->nullable()->after('dot_number');
            $table->decimal('insurance_amount', 12, 2)->nullable()->after('insurance_expiry');
            $table->integer('payment_terms_days')->nullable()->after('insurance_amount');
            $table->string('default_payment_method', 20)->nullable()->after('payment_terms_days');
            $table->string('carrier_type', 20)->nullable()->index()->after('default_payment_method');
            $table->boolean('is_preferred')->default(false)->index()->after('carrier_type');
        });
    }

    public function down(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->dropIndex(['scac_code']);
            $table->dropIndex(['mc_number']);
            $table->dropIndex(['dot_number']);
            $table->dropIndex(['carrier_type']);
            $table->dropIndex(['is_preferred']);
            $table->dropColumn([
                'scac_code',
                'mc_number',
                'dot_number',
                'insurance_expiry',
                'insurance_amount',
                'payment_terms_days',
                'default_payment_method',
                'carrier_type',
                'is_preferred',
            ]);
        });
    }
};
