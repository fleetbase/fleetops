<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Rename legacy table if it exists
        if (Schema::hasTable('vehicle_devices') && !Schema::hasTable('devices')) {
            Schema::rename('vehicle_devices', 'devices');
        }

        // Bail out if the destination table still doesn't exist for some reason
        if (!Schema::hasTable('devices')) {
            return;
        }

        // Add/align new columns (guard each add with hasColumn)
        Schema::table('devices', function (Blueprint $table) {
            // warranty_uuid
            if (!Schema::hasColumn('devices', 'warranty_uuid')) {
                $table->foreignUuid('warranty_uuid')->after('uuid')->nullable()->constrained('warranties', 'uuid')->nullOnDelete();
            }

            // telematic_uuid (1:1 to telematics.uuid)
            if (!Schema::hasColumn('devices', 'telematic_uuid')) {
                $table->foreignUuid('telematic_uuid')->after('uuid')->nullable()->constrained('telematics', 'uuid')->nullOnDelete();
            }

            // lifecycle / telemetry
            if (!Schema::hasColumn('devices', 'last_online_at')) {
                $table->timestamp('last_online_at')->after('notes')->nullable()->index();
            }

            // polymorphic attachment: attachable_type + attachable_uuid (UUID)
            if (!Schema::hasColumn('devices', 'attachable_type') && !Schema::hasColumn('devices', 'attachable_uuid')) {
                $table->string('attachable_type')->after('telematic_uuid')->nullable();
                $table->uuid('attachable_uuid')->after('telematic_uuid')->nullable();
                $table->index(['attachable_type', 'attachable_uuid']);
            }

            // meta/options blobs (order doesn’t matter; avoid AFTER to keep this migration portable)
            if (!Schema::hasColumn('devices', 'meta')) {
                $table->json('meta')->nullable();
            }

            if (!Schema::hasColumn('devices', 'options')) {
                $table->json('options')->nullable()->after('meta');
            }

            if (!Schema::hasColumn('devices', 'slug')) {
                $table->string('slug')->nullable()->index()->after('status');
            }

            if (!Schema::hasColumn('devices', '_key')) {
                $table->string('_key')->nullable()->index()->after('uuid');
            }
        });

        // Data migration: vehicle_uuid → attachable (Vehicle)
        if (Schema::hasColumn('devices', 'vehicle_uuid')) {
            // Move values (MySQL/MariaDB & PostgreSQL compatible form)
            // - For MySQL: backslashes in class name must be doubled in SQL string literal
            $vehicleModel = 'Fleetbase\\\\FleetOps\\\\Models\\\\Vehicle';

            // Use raw SQL to assign attachable_id from vehicle_uuid in one pass
            // MySQL / MariaDB
            if ($this->isMySql()) {
                DB::statement("
                    UPDATE `devices`
                    SET `attachable_type` = '{$vehicleModel}',
                        `attachable_uuid` = `vehicle_uuid`
                    WHERE `vehicle_uuid` IS NOT NULL
                ");
            } else {
                // PostgreSQL / others (no backticks)
                $vehicleModelPg = 'Fleetbase\\FleetOps\\Models\\Vehicle';
                DB::statement("
                    UPDATE devices
                    SET attachable_type = '{$vehicleModelPg}',
                        attachable_uuid = vehicle_uuid
                    WHERE vehicle_uuid IS NOT NULL
                ");
            }

            // Drop the old column now that data is migrated
            Schema::table('devices', function (Blueprint $table) {
                if (Schema::hasColumn('devices', 'vehicle_uuid')) {
                    $table->dropForeign('vehicle_devices_vehicle_uuid_foreign');
                    $table->dropColumn('vehicle_uuid');
                }
            });
        }
    }

    public function down(): void
    {
        // If devices table doesn't exist, nothing to do
        if (!Schema::hasTable('devices')) {
            return;
        }

        // Re-introduce vehicle_uuid to reverse the migration
        Schema::table('devices', function (Blueprint $table) {
            if (!Schema::hasColumn('devices', 'vehicle_uuid')) {
                $table->uuid('vehicle_uuid')->after('uuid')->nullable();
            }
        });

        // Move back attachable → vehicle_uuid where it was a Vehicle
        $vehicleModelSqlMy = 'Fleetbase\\\\FleetOps\\\\Models\\\\Vehicle';
        $vehicleModelSqlPg = 'Fleetbase\\FleetOps\\Models\\Vehicle';

        if ($this->isMySql()) {
            DB::statement("
                UPDATE `devices`
                SET `vehicle_uuid` = `attachable_uuid`
                WHERE `attachable_type` = '{$vehicleModelSqlMy}'
            ");
        } else {
            DB::statement("
                UPDATE devices
                SET vehicle_uuid = attachable_uuid
                WHERE attachable_type = '{$vehicleModelSqlPg}'
            ");
        }

        // Drop new columns added in up()
        Schema::table('devices', function (Blueprint $table) {
            // Drop FKs before columns if present
            if (Schema::hasColumn('devices', 'telematic_uuid')) {
                // fallback: FK name is unknown; attempt generic drop then column
                try {
                    $table->dropForeign(['telematic_uuid']);
                } catch (Throwable $e) {
                }
                $table->dropColumn('telematic_uuid');
            }

            // Drop FKs before columns if present
            if (Schema::hasColumn('devices', 'warranty_uuid')) {
                // fallback: FK name is unknown; attempt generic drop then column
                try {
                    $table->dropForeign(['warranty_uuid']);
                } catch (Throwable $e) {
                }
                $table->dropColumn('warranty_uuid');
            }

            if (Schema::hasColumn('devices', 'last_online_at')) {
                $table->dropColumn('last_online_at');
            }

            if (Schema::hasColumn('devices', 'attachable_type') || Schema::hasColumn('devices', 'attachable_uuid')) {
                $table->dropColumn('attachable_uuid');
                $table->dropColumn('attachable_type');
            }

            if (Schema::hasColumn('devices', 'options')) {
                $table->dropColumn('options');
            }
            if (Schema::hasColumn('devices', 'meta')) {
                $table->dropColumn('meta');
            }
        });

        // Rename devices back to vehicle_devices if that table does not already exist
        if (!Schema::hasTable('vehicle_devices') && Schema::hasTable('devices')) {
            Schema::rename('devices', 'vehicle_devices');
            Schema::table('vehicle_devices', function (Blueprint $table) {
                if (Schema::hasColumn('vehicle_devices', 'vehicle_uuid')) {
                    $table->foreign(['vehicle_uuid'])->references('uuid')->on('vehicles')->cascadeOnDelete();
                }
            });
            Schema::table('vehicle_device_events', function (Blueprint $table) {
                if (Schema::hasColumn('vehicle_device_events', 'vehicle_device_uuid')) {
                    $table->foreign(['vehicle_device_uuid'])->references('uuid')->on('vehicle_devices')->cascadeOnDelete();
                }
            });
        }
    }

    private function isMySql(): bool
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb']);
    }
};
