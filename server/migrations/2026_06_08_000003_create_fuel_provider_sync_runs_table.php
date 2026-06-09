<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::create('fuel_provider_sync_runs', function (Blueprint $table) {
            $table->increments('id');
            $table->string('uuid', 191)->nullable()->unique();
            $table->string('public_id', 191)->nullable()->unique();
            $table->uuid('company_uuid')->nullable()->index();
            $table->uuid('fuel_provider_connection_uuid')->nullable()->index();
            $table->string('provider', 80)->index();
            $table->string('status', 50)->default('queued')->index();
            $table->timestamp('from')->nullable();
            $table->timestamp('to')->nullable();
            $table->unsignedInteger('imported')->default(0);
            $table->unsignedInteger('matched')->default(0);
            $table->unsignedInteger('unmatched')->default(0);
            $table->unsignedInteger('fuel_reports_created')->default(0);
            $table->decimal('liters', 12, 3)->default(0);
            $table->unsignedBigInteger('amount')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->text('error')->nullable();
            $table->json('summary')->nullable();
            $table->json('meta')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('fuel_provider_sync_runs');
    }
};
