<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('fuel_reports', function (Blueprint $table) {
            $table->increments('id');
            $table->string('_key')->nullable();
            $table->string('uuid', 191)->nullable()->index();
            $table->string('public_id', 191)->nullable()->unique();
            $table->string('company_uuid', 191)->nullable()->index();
            $table->string('driver_uuid', 191)->nullable()->index('fuel_reports_driver_uuid_foreign');
            $table->string('vehicle_uuid', 191)->nullable()->index('fuel_reports_vehicle_uuid_foreign');
            $table->string('odometer')->nullable();
            $table->point('location');
            $table->string('latitude')->nullable();
            $table->string('longitude')->nullable();
            $table->string('amount')->nullable();
            $table->string('currency')->nullable();
            $table->string('volume')->nullable();
            $table->string('metric_unit')->nullable();
            $table->softDeletes();
            $table->timestamp('created_at')->nullable()->index();
            $table->timestamp('updated_at')->nullable();

            $table->unique(['uuid']);
            $table->spatialIndex(['location'], 'location');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('fuel_reports');
    }
};
