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
        Schema::table('integrated_vendors', function (Blueprint $table) {
            $table->json('provider_options')->nullable()->after('provider');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('integrated_vendors', function (Blueprint $table) {
            $table->dropColumn('provider_options');
        });
    }
};
