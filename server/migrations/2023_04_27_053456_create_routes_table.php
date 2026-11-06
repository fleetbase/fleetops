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
        Schema::create('routes', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('_key')->nullable();
            $table->char('uuid', 36)->unique();
            $table->char('company_uuid', 36)->nullable()->index();
            $table->char('order_uuid', 36)->nullable()->index();
            $table->json('details')->nullable();
            $table->double('total_distance')->nullable();
            $table->double('total_time')->nullable();
            $table->softDeletes();
            $table->timestamp('created_at')->nullable()->index();
            $table->timestamp('updated_at')->nullable();
        });

        // Blueprint::double() only takes total and places before Laravel 11, so set them in SQL.
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            Schema::getConnection()->statement(
                'ALTER TABLE `routes` MODIFY COLUMN `total_distance` DOUBLE(12,2) NULL, MODIFY COLUMN `total_time` DOUBLE(12,2) NULL'
            );
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('routes');
    }
};
