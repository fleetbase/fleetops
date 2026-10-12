<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TrackingSession and TrackingSessionGrant extend Fleetbase\Models\Model, which soft
 * deletes, so every query on them reads `deleted_at`. The tables were created without
 * it, which made each session and grant lookup fail and no visitor could verify.
 */
return new class extends Migration {
    private const TABLES = ['tracking_sessions', 'tracking_session_grants'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (Schema::hasTable($table) && !Schema::hasColumn($table, 'deleted_at')) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->softDeletes());
            }
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'deleted_at')) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropSoftDeletes());
            }
        }
    }
};
