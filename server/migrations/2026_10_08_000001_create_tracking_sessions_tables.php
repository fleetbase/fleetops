<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Device sessions for the public customer tracking page.
 *
 * A visitor who enters a correct one-time code gets a device session, carried in an
 * httpOnly cookie whose value is never stored: `token_hash` is its SHA-256. Each session
 * holds grants, one per customer within an order (the TrackingScope), so verifying for one
 * delivery never unlocks another and one customer never sees another's stops.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('tracking_sessions', function (Blueprint $table) {
            $table->increments('id');
            $table->uuid('uuid')->unique();
            $table->string('token_hash', 64)->unique();
            $table->string('ip', 64)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('tracking_session_grants', function (Blueprint $table) {
            $table->increments('id');
            $table->uuid('uuid')->unique();
            $table->uuid('tracking_session_uuid')->index();
            $table->uuid('company_uuid')->nullable()->index();
            $table->uuid('order_uuid');
            $table->string('customer_type');
            $table->uuid('customer_uuid');
            $table->string('channel', 16)->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();

            $table->index(['tracking_session_uuid', 'order_uuid', 'customer_uuid'], 'tracking_session_grants_scope_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tracking_session_grants');
        Schema::dropIfExists('tracking_sessions');
    }
};
