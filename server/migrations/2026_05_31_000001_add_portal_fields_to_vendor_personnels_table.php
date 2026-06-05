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
        Schema::table('vendor_personnels', function (Blueprint $table) {
            $table->string('role')->default('member')->after('contact_uuid');
            $table->string('status')->default('active')->after('role');
            $table->foreignUuid('invited_by_uuid')->nullable()->after('status')->references('uuid')->on('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vendor_personnels', function (Blueprint $table) {
            $table->dropForeign(['invited_by_uuid']);
            $table->dropColumn(['role', 'status', 'invited_by_uuid']);
        });
    }
};
