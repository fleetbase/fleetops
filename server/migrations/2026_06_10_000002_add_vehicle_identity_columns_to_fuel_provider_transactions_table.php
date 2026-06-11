<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        if (!Schema::hasColumn('fuel_provider_transactions', 'vin')) {
            Schema::table('fuel_provider_transactions', function (Blueprint $table) {
                $table->string('vin')->nullable()->index()->after('plate_number');
            });
        }

        if (!Schema::hasColumn('fuel_provider_transactions', 'serial_number')) {
            Schema::table('fuel_provider_transactions', function (Blueprint $table) {
                $table->string('serial_number')->nullable()->index()->after('vin');
            });
        }

        if (!Schema::hasColumn('fuel_provider_transactions', 'call_sign')) {
            Schema::table('fuel_provider_transactions', function (Blueprint $table) {
                $table->string('call_sign')->nullable()->index()->after('serial_number');
            });
        }
    }

    public function down()
    {
        foreach (['call_sign', 'serial_number', 'vin'] as $column) {
            if (Schema::hasColumn('fuel_provider_transactions', $column)) {
                Schema::table('fuel_provider_transactions', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
