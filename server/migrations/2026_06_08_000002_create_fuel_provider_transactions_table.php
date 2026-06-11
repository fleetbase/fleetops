<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::create('fuel_provider_transactions', function (Blueprint $table) {
            $table->increments('id');
            $table->string('uuid', 191)->nullable()->unique();
            $table->string('public_id', 191)->nullable()->unique();
            $table->uuid('company_uuid')->nullable()->index();
            $table->uuid('fuel_provider_connection_uuid')->nullable()->index();
            $table->uuid('fuel_report_uuid')->nullable()->index();
            $table->uuid('vehicle_uuid')->nullable()->index();
            $table->uuid('driver_uuid')->nullable()->index();
            $table->uuid('order_uuid')->nullable()->index();
            $table->string('provider', 80)->index();
            $table->string('provider_transaction_id', 191)->index();
            $table->string('provider_vehicle_id')->nullable()->index();
            $table->string('vehicle_card_id')->nullable()->index();
            $table->string('internal_number')->nullable()->index();
            $table->string('structure_number')->nullable()->index();
            $table->string('plate_number')->nullable()->index();
            $table->string('trip_number')->nullable()->index();
            $table->string('station_name')->nullable()->index();
            $table->decimal('station_latitude', 10, 7)->nullable();
            $table->decimal('station_longitude', 10, 7)->nullable();
            $table->timestamp('transaction_at')->nullable()->index();
            $table->decimal('volume', 12, 3)->nullable();
            $table->string('metric_unit', 20)->default('l');
            $table->unsignedBigInteger('amount')->nullable();
            $table->string('currency', 10)->default('SAR');
            $table->string('odometer')->nullable();
            $table->string('sync_status', 50)->default('imported')->index();
            $table->timestamp('matched_at')->nullable();
            $table->json('normalized_payload')->nullable();
            $table->json('raw_payload')->nullable();
            $table->json('meta')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['provider', 'provider_transaction_id'], 'fuel_provider_txn_provider_unique');
        });
    }

    public function down()
    {
        Schema::dropIfExists('fuel_provider_transactions');
    }
};
