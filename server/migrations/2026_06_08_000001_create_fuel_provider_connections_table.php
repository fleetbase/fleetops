<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::create('fuel_provider_connections', function (Blueprint $table) {
            $table->increments('id');
            $table->string('uuid', 191)->nullable()->unique();
            $table->string('public_id', 191)->nullable()->unique();
            $table->uuid('company_uuid')->nullable()->index();
            $table->string('provider', 80)->index();
            $table->string('name')->nullable();
            $table->string('environment', 40)->default('production')->index();
            $table->string('status', 50)->default('configured')->index();
            $table->json('credentials')->nullable();
            $table->json('sync_settings')->nullable();
            $table->json('last_sync_state')->nullable();
            $table->timestamp('last_synced_at')->nullable()->index();
            $table->timestamp('last_tested_at')->nullable();
            $table->text('last_error')->nullable();
            $table->json('meta')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['company_uuid', 'provider', 'environment'], 'fuel_provider_connection_unique');
        });
    }

    public function down()
    {
        Schema::dropIfExists('fuel_provider_connections');
    }
};
