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
        Schema::create('purchase_rates', function (Blueprint $table) {
            $table->increments('id');
            $table->string('_key')->nullable();
            $table->string('uuid', 191)->nullable()->index();
            $table->string('public_id', 191)->nullable()->unique();
            $table->json('meta')->nullable();
            $table->string('customer_uuid')->nullable();
            $table->string('customer_type')->nullable();
            $table->string('company_uuid', 191)->nullable()->index('purchase_rates_company_uuid_foreign');
            $table->string('transaction_uuid', 191)->nullable()->index('purchase_rates_transaction_uuid_foreign');
            $table->string('service_quote_uuid', 191)->nullable()->index('purchase_rates_service_quote_uuid_foreign');
            $table->string('payload_uuid', 191)->nullable()->index('purchase_rates_payload_uuid_foreign');
            $table->string('status')->nullable();
            $table->softDeletes();
            $table->timestamp('created_at')->nullable()->index();
            $table->timestamp('updated_at')->nullable();

            $table->unique(['uuid']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('purchase_rates');
    }
};
