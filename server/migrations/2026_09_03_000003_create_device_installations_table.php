<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('device_installations', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->foreignUuid('company_uuid')->constrained('companies', 'uuid')->cascadeOnDelete();
            $table->foreignUuid('device_uuid')->constrained('devices', 'uuid')->cascadeOnDelete();
            $table->string('attachable_type');
            $table->uuid('attachable_uuid');
            $table->uuid('active_device_uuid')->nullable()->unique();
            $table->timestamp('installed_at');
            $table->timestamp('removed_at')->nullable();
            $table->string('source')->default('manual');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['company_uuid', 'attachable_type', 'attachable_uuid'], 'device_installations_attachable_index');
            $table->index(['device_uuid', 'installed_at'], 'device_installations_history_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_installations');
    }
};
