<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('service_areas', function (Blueprint $table) {
            $table->json('states_list')->nullable()->after('country');
            $table->json('zip_ranges')->nullable()->after('states_list');
        });
    }

    public function down(): void
    {
        Schema::table('service_areas', function (Blueprint $table) {
            $table->dropColumn(['states_list', 'zip_ranges']);
        });
    }
};
