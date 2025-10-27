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
        Schema::table('device_events', function (Blueprint $table) {
            $table->foreignUuid('company_uuid')->nullable()->after('_key')->constrained('companies', 'uuid')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('device_events', function (Blueprint $table) {
            $table->dropForeign(['company_uuid']);
            $table->dropColumn(['company_uuid']);
        });
    }
};
