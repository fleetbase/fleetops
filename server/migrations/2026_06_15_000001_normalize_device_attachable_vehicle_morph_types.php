<?php

use Fleetbase\FleetOps\Models\Vehicle;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('devices') || !Schema::hasColumn('devices', 'attachable_type') || !Schema::hasColumn('devices', 'attachable_uuid')) {
            return;
        }

        DB::table('devices')
            ->whereNotNull('attachable_uuid')
            ->whereIn('attachable_type', [
                'Fleetbase\\Models\\Vehicle',
                '\\Fleetbase\\Models\\Vehicle',
            ])
            ->update([
                'attachable_type' => Vehicle::class,
            ]);
    }

    public function down(): void
    {
        // Intentionally do not restore invalid legacy morph class names.
    }
};
