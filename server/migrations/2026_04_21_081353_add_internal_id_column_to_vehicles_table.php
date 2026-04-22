<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('vehicles', 'internal_id')) {
            Schema::table('vehicles', function (Blueprint $table) {
                $table->string('internal_id')
                    ->nullable()
                    ->after('public_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('vehicles', 'internal_id')) {
            Schema::table('vehicles', function (Blueprint $table) {
                $table->dropColumn('internal_id');
            });
        }
    }
};
