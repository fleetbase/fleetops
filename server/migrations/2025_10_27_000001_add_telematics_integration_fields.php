<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // Update telematics table
        Schema::table('telematics', function (Blueprint $table) {
            $table->json('credentials')->after('config');
            $table->string('public_id')->after('uuid')->nullable()->index();
        });

        // Update devices table
        Schema::table('devices', function (Blueprint $table) {
            $table->string('public_id')->after('uuid')->nullable()->index();
            $table->foreignUuid('company_uuid')->nullable()->after('_key')->constrained('companies', 'uuid')->nullOnDelete();
            $table->foreignUuid('photo_uuid')->nullable()->after('telematic_uuid')->constrained('files', 'uuid')->nullOnDelete();
        });

        // Update sensors table
        Schema::table('sensors', function (Blueprint $table) {
            $table->string('public_id')->after('uuid')->nullable()->index();
            $table->foreignUuid('photo_uuid')->nullable()->after('company_uuid')->constrained('files', 'uuid')->nullOnDelete();
        });

        // Update device events table
        Schema::table('device_events', function (Blueprint $table) {
            $table->string('public_id')->after('uuid')->nullable()->index();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        // Remove columns from telematics
        Schema::table('telematics', function (Blueprint $table) {
            $table->dropColumn(['credentials', 'public_id']);
        });

        // Remove columns from devices
        Schema::table('devices', function (Blueprint $table) {
            $table->dropForeign(['company_uuid']);
            $table->dropForeign(['photo_uuid']);
            $table->dropColumn(['public_id', 'photo_uuid', 'company_uuid']);
        });

        // Remove columns from sensors
        Schema::table('sensors', function (Blueprint $table) {
            $table->dropForeign(['photo_uuid']);
            $table->dropColumn(['public_id', 'photo_uuid']);
        });

        // Remove columns from device events
        Schema::table('device_events', function (Blueprint $table) {
            $table->dropColumn(['public_id']);
        });
    }
};
