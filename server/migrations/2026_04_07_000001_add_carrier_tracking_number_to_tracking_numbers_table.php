<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('tracking_numbers', function (Blueprint $table) {
            $table->string('carrier_tracking_number', 100)->nullable()->after('tracking_number');
            $table->index('carrier_tracking_number');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('tracking_numbers', function (Blueprint $table) {
            $table->dropIndex(['carrier_tracking_number']);
            $table->dropColumn('carrier_tracking_number');
        });
    }
};
