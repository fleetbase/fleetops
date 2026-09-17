<?php

use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Facades\Schema;

function fleetopsDeviceEventIndexMigrationDatabase(bool $createTable = true): SQLiteConnection
{
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'), ':memory:', '', ['driver' => 'sqlite']);
    Schema::swap($connection->getSchemaBuilder());

    if ($createTable) {
        Schema::create('device_events', function ($table) {
            $table->increments('id');
            $table->uuid('uuid')->nullable();
            $table->string('_key')->nullable()->index();
            $table->uuid('company_uuid')->nullable();
            $table->text('payload')->nullable();
        });
    }

    return $connection;
}

function fleetopsDeviceEventIndexMigration()
{
    return require dirname(__DIR__, 4) . '/migrations/2026_09_17_000001_add_device_event_uuid_lookup_index.php';
}

function fleetopsDeviceEventIndexNames(SQLiteConnection $connection): array
{
    return array_column($connection->select('PRAGMA index_list("device_events")'), 'name');
}

test('device event UUID migration indexes legacy lookups without changing data', function () {
    $connection = fleetopsDeviceEventIndexMigrationDatabase();
    $connection->table('device_events')->insert([
        ['uuid' => 'event-1', 'payload' => 'first'],
        ['uuid' => 'event-1', 'payload' => 'legacy duplicate'],
        ['uuid' => null, 'payload' => 'legacy null'],
    ]);
    $before    = $connection->table('device_events')->orderBy('id')->get()->all();
    $migration = fleetopsDeviceEventIndexMigration();

    $migration->up();
    $migration->up();

    expect(fleetopsDeviceEventIndexNames($connection))->toContain('device_events_uuid_lookup_index', 'device_events__key_index')
        ->and($connection->table('device_events')->orderBy('id')->get()->all())->toEqual($before);
    $plan = $connection->select('EXPLAIN QUERY PLAN SELECT * FROM device_events WHERE uuid = ? LIMIT 1', ['event-1']);
    expect($plan[0]->detail)->toContain('USING INDEX device_events_uuid_lookup_index');

    // Rollback is performed by a newly loaded migration, as in artisan migrate:rollback.
    fleetopsDeviceEventIndexMigration()->down();
    expect(fleetopsDeviceEventIndexNames($connection))->not->toContain('device_events_uuid_lookup_index')
        ->toContain('device_events__key_index')
        ->and($connection->table('device_events')->orderBy('id')->get()->all())->toEqual($before);
});

test('device event UUID migration preserves equivalent existing indexes', function (array $columns, bool $unique) {
    $connection = fleetopsDeviceEventIndexMigrationDatabase();
    Schema::table('device_events', function ($table) use ($columns, $unique) {
        $unique ? $table->unique($columns, 'existing_event_identity') : $table->index($columns, 'existing_event_identity');
    });
    $before = fleetopsDeviceEventIndexNames($connection);

    fleetopsDeviceEventIndexMigration()->up();
    fleetopsDeviceEventIndexMigration()->down();

    expect(fleetopsDeviceEventIndexNames($connection))->toBe($before);
})->with([
    'unique UUID'            => [['uuid'], true],
    'UUID leading composite' => [['uuid', 'company_uuid'], false],
]);

test('device event UUID migration recognizes a UUID primary key', function () {
    $connection = fleetopsDeviceEventIndexMigrationDatabase(false);
    Schema::create('device_events', function ($table) {
        $table->uuid('uuid')->primary();
    });
    $before = fleetopsDeviceEventIndexNames($connection);

    fleetopsDeviceEventIndexMigration()->up();
    fleetopsDeviceEventIndexMigration()->down();

    expect(fleetopsDeviceEventIndexNames($connection))->toBe($before);
});

test('device event UUID migration adds an index when UUID is not the leading column', function () {
    $connection = fleetopsDeviceEventIndexMigrationDatabase();
    Schema::table('device_events', fn ($table) => $table->index(['company_uuid', 'uuid'], 'existing_company_event'));

    fleetopsDeviceEventIndexMigration()->up();
    expect(fleetopsDeviceEventIndexNames($connection))->toContain('device_events_uuid_lookup_index', 'existing_company_event');

    fleetopsDeviceEventIndexMigration()->down();
    expect(fleetopsDeviceEventIndexNames($connection))->not->toContain('device_events_uuid_lookup_index')
        ->toContain('existing_company_event');
});

test('device event UUID migration tolerates an absent legacy table', function () {
    fleetopsDeviceEventIndexMigrationDatabase(false);
    fleetopsDeviceEventIndexMigration()->up();
    fleetopsDeviceEventIndexMigration()->down();

    expect(Schema::hasTable('device_events'))->toBeFalse();
});
