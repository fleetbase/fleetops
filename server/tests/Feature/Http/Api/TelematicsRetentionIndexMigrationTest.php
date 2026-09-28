<?php

use Fleetbase\FleetOps\Support\Database\TableIndexes;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Facades\Schema;

/**
 * The retention sweep ranges over (company_uuid, created_at) and
 * (telematic_uuid, updated_at); these migrations add those indexes once,
 * skip equivalent existing ones, and roll back only what they created.
 */
function fleetopsRetentionIndexDatabase(): SQLiteConnection
{
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'), ':memory:', '', ['driver' => 'sqlite']);
    Schema::swap($connection->getSchemaBuilder());

    return $connection;
}

function fleetopsRetentionIndexMigrations(): array
{
    $migrations = [];
    foreach (glob(dirname(__DIR__, 4) . '/migrations/2026_09_23_00000*_add_retention_index_to_*.php') as $path) {
        $migrations[basename($path)] = fn () => require $path;
    }

    return $migrations;
}

test('retention index migrations add composite indexes once and roll them back', function () {
    fleetopsRetentionIndexDatabase();
    Schema::create('device_events', function ($table) {
        $table->increments('id');
        $table->uuid('company_uuid')->nullable();
        $table->timestamps();
    });
    Schema::create('positions', function ($table) {
        $table->increments('id');
        $table->uuid('company_uuid')->nullable();
        $table->timestamps();
    });
    Schema::create('telematic_sync_runs', function ($table) {
        $table->uuid('uuid')->primary();
        $table->uuid('telematic_uuid')->index();
        $table->timestamps();
    });

    $expected = [
        'device_events'       => ['device_events_company_created_at_index', ['company_uuid', 'created_at']],
        'positions'           => ['positions_company_created_at_index', ['company_uuid', 'created_at']],
        'telematic_sync_runs' => ['telematic_sync_runs_telematic_updated_at_index', ['telematic_uuid', 'updated_at']],
    ];
    $migrations = fleetopsRetentionIndexMigrations();
    expect($migrations)->toHaveCount(3);
    foreach ($migrations as $load) {
        $load()->up();
        $load()->up();
    }
    foreach ($expected as $table => [$name, $columns]) {
        expect(TableIndexes::for($table)[$name] ?? null)->toBe($columns)
            ->and(TableIndexes::covers($table, $columns))->toBeTrue()
            ->and(TableIndexes::covers($table, ['created_at']))->toBeFalse();
    }
    // The existing single-column index on telematic_uuid does not cover the composite.
    expect(count(array_filter(TableIndexes::for('telematic_sync_runs'), fn ($columns) => $columns === ['telematic_uuid'])))->toBe(1);

    foreach ($migrations as $load) {
        $load()->down();
    }
    foreach ($expected as $table => [$name]) {
        expect(TableIndexes::for($table))->not->toHaveKey($name);
    }
});

test('retention index migrations skip missing tables, missing columns and equivalent indexes', function () {
    fleetopsRetentionIndexDatabase();
    $migrations = fleetopsRetentionIndexMigrations();

    // No tables at all: nothing to do either way.
    foreach ($migrations as $load) {
        $load()->up();
        $load()->down();
    }

    Schema::create('device_events', fn ($table) => $table->increments('id'));
    Schema::create('positions', function ($table) {
        $table->increments('id');
        $table->uuid('company_uuid')->nullable();
        $table->timestamps();
        $table->index(['company_uuid', 'created_at', 'id'], 'positions_existing_company_created');
    });
    Schema::create('telematic_sync_runs', function ($table) {
        $table->uuid('uuid')->primary();
        $table->uuid('telematic_uuid');
        $table->timestamps();
        $table->index(['telematic_uuid', 'updated_at'], 'telematic_sync_runs_telematic_updated_at_index');
    });
    Schema::table('telematic_sync_runs', fn ($table) => $table->index('updated_at', 'unrelated_updated_at'));

    foreach ($migrations as $load) {
        $load()->up();
    }
    expect(TableIndexes::for('device_events'))->toBe([])
        ->and(array_keys(TableIndexes::for('positions')))->toBe(['positions_existing_company_created'])
        ->and(TableIndexes::for('telematic_sync_runs'))->toHaveKeys(['telematic_sync_runs_telematic_updated_at_index', 'unrelated_updated_at']);

    // Rolling back never drops an index the migration did not create, even under the reserved name when its shape differs.
    Schema::table('telematic_sync_runs', fn ($table) => $table->dropIndex('telematic_sync_runs_telematic_updated_at_index'));
    Schema::table('telematic_sync_runs', fn ($table) => $table->index('updated_at', 'telematic_sync_runs_telematic_updated_at_index'));
    foreach ($migrations as $load) {
        $load()->down();
    }
    expect(array_keys(TableIndexes::for('positions')))->toBe(['positions_existing_company_created'])
        ->and(TableIndexes::for('telematic_sync_runs')['telematic_sync_runs_telematic_updated_at_index'])->toBe(['updated_at']);
});
