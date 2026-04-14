<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('rate_contracts', function (Blueprint $table) {
            $table->boolean('is_shared')->default(false)->index()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('rate_contracts', function (Blueprint $table) {
            $table->dropIndex(['is_shared']);
            $table->dropColumn('is_shared');
        });
    }
};
