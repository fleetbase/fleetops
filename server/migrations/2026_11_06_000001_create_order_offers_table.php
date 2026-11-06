<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Timed offers: an order whose config turns on `meta.offers` is offered to one driver at a
 * time, nearest first, and moves to the next driver when an offer is declined or runs out.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('order_offers', function (Blueprint $table) {
            $table->increments('id');
            $table->uuid('uuid')->unique();
            $table->uuid('company_uuid')->nullable()->index();
            $table->uuid('order_uuid')->index();
            $table->uuid('driver_uuid')->index();
            $table->string('status', 16)->default('pending');
            $table->unsignedInteger('distance')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['order_uuid', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_offers');
    }
};
