<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        /**
         * Devices: rename columns WITHOUT doctrine/dbal (MySQL 8+).
         */
        DB::statement('ALTER TABLE devices RENAME COLUMN device_name TO name');
        DB::statement('ALTER TABLE devices RENAME COLUMN device_type TO type');
        DB::statement('ALTER TABLE devices RENAME COLUMN device_location TO location');
        DB::statement('ALTER TABLE devices RENAME COLUMN device_model TO model');
        DB::statement('ALTER TABLE devices RENAME COLUMN device_provider TO provider');

        /**
         * Devices: add new scalar cols + spatial POINT (nullable first!).
         */
        Schema::table('devices', function (Blueprint $table) {
            $table->string('internal_id')->nullable()->after('device_id');
            $table->string('imei')->nullable()->after('device_id');
            $table->string('imsi')->nullable()->after('device_id');
            $table->string('firmware_version')->nullable()->after('device_id');

            // must be nullable now; we'll backfill and then make NOT NULL
            $table->point('last_position')->nullable()->after('serial_number');
        });

        // Backfill existing rows so NOT NULL will succeed
        DB::statement('UPDATE devices SET last_position = ST_SRID(POINT(0, 0), 4326) WHERE last_position IS NULL');

        // Make column NOT NULL (and optionally enforce SRID at column level)
        // If you want to enforce SRID on the column itself, uncomment the SRID variant:
        // DB::statement('ALTER TABLE devices MODIFY last_position POINT NOT NULL SRID 4326');
        DB::statement('ALTER TABLE devices MODIFY last_position POINT NOT NULL');

        // NOW add the spatial index (requires NOT NULL)
        Schema::table('devices', function (Blueprint $table) {
            $table->spatialIndex('last_position', 'devices_last_position_spx');
        });

        /**
         * Sensors: drop legacy column, add fields + POINT (nullable), backfill, NOT NULL, index.
         */
        Schema::table('sensors', function (Blueprint $table) {
            if (Schema::hasColumn('sensors', 'sensor_type')) {
                $table->dropColumn('sensor_type');
            }

            $table->foreignUuid('telematic_uuid')->nullable()->after('company_uuid')->constrained('telematics', 'uuid')->nullOnDelete();
            $table->string('firmware_version')->nullable()->after('name');
            $table->string('imei')->nullable()->after('name');
            $table->string('imsi')->nullable()->after('name');
            $table->string('serial_number')->nullable()->after('name');
            $table->string('internal_id')->nullable()->after('name');
            $table->string('status')->nullable()->after('last_value');

            $table->point('last_position')->nullable()->after('serial_number');
        });

        DB::statement('UPDATE sensors SET last_position = ST_SRID(POINT(0, 0), 4326) WHERE last_position IS NULL');
        DB::statement('ALTER TABLE sensors MODIFY last_position POINT NOT NULL');

        Schema::table('sensors', function (Blueprint $table) {
            $table->spatialIndex('last_position', 'sensors_last_position_spx');
        });
    }

    public function down(): void
    {
        /**
         * Devices: drop spatial index then column, drop added scalars, rename back.
         */
        Schema::table('devices', function (Blueprint $table) {
            // drop index BEFORE dropping column
            $table->dropSpatialIndex('devices_last_position_spx');
            $table->dropColumn('last_position');

            $table->dropColumn(['internal_id', 'imei', 'imsi', 'firmware_version']);
        });

        DB::statement('ALTER TABLE devices RENAME COLUMN name TO device_name');
        DB::statement('ALTER TABLE devices RENAME COLUMN type TO device_type');
        DB::statement('ALTER TABLE devices RENAME COLUMN location TO device_location');
        DB::statement('ALTER TABLE devices RENAME COLUMN model TO device_model');
        DB::statement('ALTER TABLE devices RENAME COLUMN provider TO device_provider');

        /**
         * Sensors: drop spatial index/column, drop new fields, restore sensor_type.
         */
        Schema::table('sensors', function (Blueprint $table) {
            $table->dropSpatialIndex('sensors_last_position_spx');
            $table->dropColumn('last_position');

            $table->dropForeign(['telematic_uuid']);
            $table->dropColumn(['telematic_uuid']);

            $table->dropColumn(['serial_number', 'internal_id', 'imei', 'imsi', 'firmware_version', 'status']);

            $table->string('sensor_type')->nullable()->after('slug');
        });
    }
};
