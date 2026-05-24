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
        Schema::table('service_rate_fees', function (Blueprint $table) {
            $table->uuid('service_area_uuid')->nullable()->index()->after('service_rate_uuid');
            $table->uuid('zone_uuid')->nullable()->index()->after('service_area_uuid');
            $table->string('label')->nullable()->after('zone_uuid');
            $table->integer('priority')->default(0)->after('label');
            $table->boolean('is_fallback')->default(false)->after('priority');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('service_rate_fees', function (Blueprint $table) {
            $table->dropIndex(['service_area_uuid']);
            $table->dropIndex(['zone_uuid']);
            $table->dropColumn(['service_area_uuid', 'zone_uuid', 'label', 'priority', 'is_fallback']);
        });
    }
};
