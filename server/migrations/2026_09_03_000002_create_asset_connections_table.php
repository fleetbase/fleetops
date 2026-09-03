<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('asset_connections', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->string('public_id')->unique();
            $table->foreignUuid('company_uuid')->constrained('companies', 'uuid')->cascadeOnDelete();
            $table->string('connector_type');
            $table->uuid('connector_uuid');
            $table->string('connected_type');
            $table->uuid('connected_uuid');
            $table->uuid('active_connected_uuid')->nullable()->unique();
            $table->string('active_connector_position')->nullable()->unique();
            $table->string('relationship_type')->default('towing');
            $table->unsignedSmallInteger('position')->default(1);
            $table->timestamp('connected_at');
            $table->timestamp('disconnected_at')->nullable();
            $table->string('source')->default('manual');
            $table->string('confidence')->nullable();
            $table->text('notes')->nullable();
            $table->json('meta')->nullable();
            $table->foreignUuid('created_by_uuid')->nullable()->constrained('users', 'uuid')->nullOnDelete();
            $table->foreignUuid('updated_by_uuid')->nullable()->constrained('users', 'uuid')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['company_uuid', 'connector_type', 'connector_uuid'], 'asset_connections_connector_index');
            $table->index(['company_uuid', 'connected_type', 'connected_uuid'], 'asset_connections_connected_index');
            $table->index(['company_uuid', 'disconnected_at'], 'asset_connections_active_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_connections');
    }
};
