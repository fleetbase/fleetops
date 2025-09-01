<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Rename legacy table if needed
        if (Schema::hasTable('vehicle_device_events') && !Schema::hasTable('device_events')) {
            Schema::rename('vehicle_device_events', 'device_events');
        }

        if (!Schema::hasTable('device_events')) {
            // Nothing to do if the table doesn't exist
            return;
        }

        // Add device_uuid FK (many events per device; do NOT make it unique)
        Schema::table('device_events', function (Blueprint $table) {
            if (!Schema::hasColumn('device_events', 'device_uuid')) {
                $table->foreignUuid('device_uuid')->after('uuid')->nullable()->constrained('devices', 'uuid')->cascadeOnDelete();
            }

            if (!Schema::hasColumn('device_events', 'event_type')) {
                $table->string('event_type')->after('ident')->nullable();
            }

            if (!Schema::hasColumn('device_events', 'severity')) {
                $table->string('severity')->after('ident')->nullable();
            }

            if (!Schema::hasColumn('device_events', '_key')) {
                $table->string('_key')->after('uuid')->nullable()->index();
            }
        });

        // Migrate data: vehicle_device_uuid -> device_uuid (if legacy column exists)
        if (Schema::hasColumn('device_events', 'vehicle_device_uuid')) {
            // Copy values in one pass
            DB::table('device_events')
                ->whereNotNull('vehicle_device_uuid')
                ->update([
                    'device_uuid' => DB::raw('vehicle_device_uuid'),
                ]);

            // Drop legacy column after successful copy
            Schema::table('device_events', function (Blueprint $table) {
                if (Schema::hasColumn('device_events', 'vehicle_device_uuid')) {
                    $table->dropForeign('vehicle_device_events_vehicle_device_uuid_foreign');
                    $table->dropColumn('vehicle_device_uuid');
                }
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('device_events')) {
            return;
        }

        // 1) Re-introduce legacy column
        Schema::table('device_events', function (Blueprint $table) {
            if (!Schema::hasColumn('device_events', 'vehicle_device_uuid')) {
                $table->uuid('vehicle_device_uuid')->nullable();
            }
        });

        // 2) Move data back where present
        if (Schema::hasColumn('device_events', 'device_uuid')) {
            DB::table('device_events')
                ->whereNotNull('device_uuid')
                ->update([
                    'vehicle_device_uuid' => DB::raw('device_uuid'),
                ]);
        }

        // 3) Drop new FK/column
        Schema::table('device_events', function (Blueprint $table) {
            if (Schema::hasColumn('device_events', 'device_uuid')) {
                // Drop FK safely when possible
                // fallback: FK name is unknown; attempt generic drop then column
                try {
                    $table->dropForeign(['device_uuid']);
                } catch (Throwable $e) {
                }
                $table->dropColumn('device_uuid');
            }
        });

        // 4) Rename table back if the old name doesn't exist
        if (!Schema::hasTable('vehicle_device_events') && Schema::hasTable('device_events')) {
            Schema::rename('device_events', 'vehicle_device_events');
        }
    }
};
