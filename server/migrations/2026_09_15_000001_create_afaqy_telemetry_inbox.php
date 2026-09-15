<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('devices', fn (Blueprint $table) => $table->index(['company_uuid', 'telematic_uuid', 'device_id'], 'devices_afaqy_lookup'));
        Schema::create('afaqy_sync_runs', function (Blueprint $table) {
            $table->uuid('uuid')->primary();
            $table->uuid('telematic_uuid')->index();
            $table->string('status')->default('fetching')->index();
            $table->unsignedInteger('pages')->default(0);
            $table->unsignedInteger('units')->default(0);
            $table->unsignedInteger('applied')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->text('error')->nullable();
            $table->timestamps();
        });
        Schema::create('afaqy_deliveries', function (Blueprint $table) {
            $table->uuid('uuid')->primary();
            $table->uuid('telematic_uuid')->index();
            $table->uuid('run_uuid')->nullable()->index();
            $table->string('source', 16);
            $table->string('status', 24)->default('pending');
            $table->char('payload_hash', 64);
            $table->longText('payload');
            $table->longText('retry_payload')->nullable();
            $table->unsignedInteger('invalid_count')->default(0);
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedInteger('queue_delay_seconds')->nullable();
            $table->unsignedInteger('source_delay_seconds')->nullable();
            $table->unsignedInteger('applied')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('received_at');
            $table->timestamp('available_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'available_at']);
            $table->index(['status', 'updated_at']);
            $table->index(['telematic_uuid', 'status']);
            $table->index(['telematic_uuid', 'received_at']);
            $table->index(['telematic_uuid', 'source', 'received_at']);
        });
        Schema::create('afaqy_webhook_tokens', function (Blueprint $table) {
            $table->uuid('telematic_uuid')->primary();
            $table->text('token');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::table('devices', fn (Blueprint $table) => $table->dropIndex('devices_afaqy_lookup'));
        Schema::dropIfExists('afaqy_webhook_tokens');
        Schema::dropIfExists('afaqy_deliveries');
        Schema::dropIfExists('afaqy_sync_runs');
    }
};
