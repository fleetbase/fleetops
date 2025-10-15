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
        Schema::table('service_rates', function (Blueprint $table) {
            $table->string('max_distance_unit')->default('km')->after('base_fee');
            $table->integer('max_distance')->default(5)->after('base_fee');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('service_rates', function (Blueprint $table) {
            $table->dropColumn(['max_distance_unit', 'max_distance']);
        });
    }
};
