<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            // Existing Asset rows predate public identifiers. Keep this nullable so
            // Trailer support does not rewrite unrelated historical Asset data.
            $table->string('public_id')->nullable()->after('uuid')->unique();
            $table->string('asset_class')->nullable()->after('type')->index();
            $table->foreignUuid('photo_uuid')->nullable()->after('current_place_uuid')->constrained('files', 'uuid')->nullOnDelete();
            $table->boolean('online')->nullable()->after('altitude')->index();
            $table->timestamp('last_online_at')->nullable()->after('online')->index();
            $table->json('telematics')->nullable()->after('attributes');
            $table->date('purchased_at')->nullable()->after('lease_expires_at');
            $table->decimal('length', 12, 3)->nullable();
            $table->decimal('width', 12, 3)->nullable();
            $table->decimal('height', 12, 3)->nullable();
            $table->decimal('tare_weight', 12, 3)->nullable();
            $table->decimal('gvwr', 12, 3)->nullable();
            $table->decimal('payload_capacity', 12, 3)->nullable();
            $table->decimal('cargo_volume', 12, 3)->nullable();
            $table->unsignedSmallInteger('axle_count')->nullable();
            $table->unsignedSmallInteger('tire_count')->nullable();
            $table->unsignedSmallInteger('door_count')->nullable();
            $table->string('body_type')->nullable()->index();
            $table->string('coupling_type')->nullable();
            $table->string('brake_type')->nullable();
            $table->boolean('abs_equipped')->nullable();
            $table->boolean('ebs_equipped')->nullable();
            $table->boolean('refrigerated')->nullable()->index();
            $table->decimal('temperature_min', 8, 3)->nullable();
            $table->decimal('temperature_max', 8, 3)->nullable();
            $table->decimal('reefer_engine_hours', 14, 2)->nullable();
            $table->index(['company_uuid', 'asset_class', 'status'], 'assets_company_class_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropIndex('assets_company_class_status_index');
            $table->dropForeign(['photo_uuid']);
            $table->dropUnique(['public_id']);
            $table->dropColumn([
                'public_id', 'asset_class', 'photo_uuid', 'online', 'last_online_at', 'telematics', 'purchased_at',
                'length', 'width', 'height', 'tare_weight', 'gvwr', 'payload_capacity', 'cargo_volume',
                'axle_count', 'tire_count', 'door_count', 'body_type', 'coupling_type', 'brake_type',
                'abs_equipped', 'ebs_equipped', 'refrigerated', 'temperature_min', 'temperature_max',
                'reefer_engine_hours',
            ]);
        });
    }
};
