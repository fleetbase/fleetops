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
            $table->string('class')->after('type')->nullable()->index();
            $table->string('model_type')->after('model')->nullable()->index();
            $table->string('body_sub_type')->after('measurement_system')->nullable()->index();
            $table->string('body_type')->after('measurement_system')->nullable()->index();
        });

        Schema::table('contacts', function (Blueprint $table) {
            $table->mediumText('notes')->after('type')->nullable();
        });

        Schema::table('vendors', function (Blueprint $table) {
            $table->mediumText('notes')->after('type')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn('class');
            $table->dropColumn('model_type');
            $table->dropColumn('body_type');
            $table->dropColumn('body_sub_type');
        });

        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn('notes');
        });

        Schema::table('vendors', function (Blueprint $table) {
            $table->dropColumn('notes');
        });
    }
};
