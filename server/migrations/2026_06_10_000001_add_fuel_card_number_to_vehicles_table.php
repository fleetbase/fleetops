<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        if (!Schema::hasColumn('vehicles', 'fuel_card_number')) {
            Schema::table('vehicles', function (Blueprint $table) {
                $table->string('fuel_card_number')->nullable()->index()->after('call_sign');
            });
        }
    }

    public function down()
    {
        if (Schema::hasColumn('vehicles', 'fuel_card_number')) {
            Schema::table('vehicles', function (Blueprint $table) {
                $table->dropColumn('fuel_card_number');
            });
        }
    }
};
