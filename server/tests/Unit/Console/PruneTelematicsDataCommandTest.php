<?php

use Fleetbase\FleetOps\Console\Commands\PruneTelematicsData;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require_once __DIR__ . '/../../Support/PruneTelematicsDataProbe.php';

/**
 * fleetops:prune-telematics-data against an in-memory SQLite fixture: per-company
 * policies, compaction, inbox tables resolved through connections, orphans,
 * bounded batches, dry runs, filters and locking.
 */
function fleetopsPruneBoot(): SQLiteConnection
{
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'), '', '', ['name' => 'mysql', 'driver' => 'sqlite']);
    $resolver   = new ConnectionResolver(['default' => $connection, 'mysql' => $connection]);
    $resolver->setDefaultConnection('mysql');
    EloquentModel::setConnectionResolver($resolver);
    app()->instance('db', new class($connection) {
        public function __construct(public SQLiteConnection $c)
        {
        }

        public function connection($name = null): SQLiteConnection
        {
            return $this->c;
        }

        public function __call($method, $arguments)
        {
            return $this->c->{$method}(...$arguments);
        }
    });
    app()->instance('db.schema', $connection->getSchemaBuilder());
    DB::clearResolvedInstance('db');
    Schema::clearResolvedInstance('db.schema');

    foreach ([
        'companies'     => ['uuid', 'public_id', 'name'],
        'telematics'    => ['uuid', 'public_id', 'company_uuid', 'provider', 'status'],
        'devices'       => ['uuid', 'company_uuid', 'telematic_uuid', 'device_id'],
        'device_events' => ['uuid', 'company_uuid', 'device_uuid', 'event_type', 'payload', 'meta', 'data'],
        'positions'     => ['uuid', 'company_uuid', 'subject_uuid', 'subject_type'],
    ] as $table => $columns) {
        Schema::create($table, function ($blueprint) use ($columns) {
            $blueprint->increments('id');
            foreach ($columns as $column) {
                $blueprint->text($column)->nullable();
            }
            $blueprint->timestamps();
            $blueprint->timestamp('deleted_at')->nullable();
        });
    }
    (require __DIR__ . '/../../../migrations/2026_09_15_000001_create_telematic_telemetry_tables.php')->up();
    foreach (glob(__DIR__ . '/../../../migrations/2026_09_23_00000*_add_retention_index_to_*.php') as $migration) {
        (require $migration)->up();
    }

    Carbon::setTestNow('2026-09-23 12:00:00 UTC');
    DB::table('companies')->insert([
        ['id' => 1, 'uuid' => 'company-1', 'public_id' => 'company_one'],
        ['id' => 2, 'uuid' => 'company-2', 'public_id' => 'company_two'],
    ]);
    DB::table('telematics')->insert([
        ['uuid' => 'tm-1', 'company_uuid' => 'company-1', 'provider' => 'afaqy', 'status' => 'active', 'deleted_at' => null],
        ['uuid' => 'tm-1-trashed', 'company_uuid' => 'company-1', 'provider' => 'afaqy', 'status' => 'disabled', 'deleted_at' => '2026-09-01 00:00:00'],
        ['uuid' => 'tm-2', 'company_uuid' => 'company-2', 'provider' => 'safee', 'status' => 'active', 'deleted_at' => null],
    ]);

    return $connection;
}

function fleetopsPruneEvent(string $uuid, ?string $company, int $daysOld, array $extra = []): array
{
    $at = Carbon::now()->subDays($daysOld)->toDateTimeString();

    return array_merge([
        'uuid'       => $uuid, 'company_uuid' => $company, 'event_type' => 'telemetry_update',
        'payload'    => '{"raw":true}', 'meta' => '{"speed":1}', 'data' => '{"speed":1}',
        'created_at' => $at, 'updated_at' => $at, 'deleted_at' => null,
    ], $extra);
}

function fleetopsPruneDelivery(string $uuid, string $telematic, string $status, int $hoursOld): array
{
    $at = Carbon::now()->subHours($hoursOld)->toDateTimeString();

    return [
        'uuid'         => $uuid, 'telematic_uuid' => $telematic, 'source' => 'poll', 'status' => $status,
        'payload_hash' => str_repeat('a', 64), 'payload' => 'x', 'received_at' => $at, 'available_at' => $at,
        'created_at'   => $at, 'updated_at' => $at,
    ];
}

function fleetopsPruneRun(string $uuid, string $telematic, string $status, int $daysOld): array
{
    $at = Carbon::now()->subDays($daysOld)->toDateTimeString();

    return ['uuid' => $uuid, 'telematic_uuid' => $telematic, 'status' => $status, 'created_at' => $at, 'updated_at' => $at];
}

afterEach(fn () => Carbon::setTestNow());

test('prune applies each company policy to events, positions and the inbox, and system defaults to orphans', function () {
    fleetopsPruneBoot();
    DB::table('device_events')->insert([
        fleetopsPruneEvent('e-old', 'company-1', 40),
        fleetopsPruneEvent('e-compact', 'company-1', 10),
        fleetopsPruneEvent('e-compacted-already', 'company-1', 10, ['payload' => null, 'meta' => null]),
        fleetopsPruneEvent('e-fresh', 'company-1', 1),
        fleetopsPruneEvent('e-trashed', 'company-1', 1, ['deleted_at' => Carbon::now()->toDateTimeString()]),
        fleetopsPruneEvent('e-forever', 'company-2', 400),
        fleetopsPruneEvent('e-orphan-null', null, 40),
        fleetopsPruneEvent('e-orphan-gone', 'company-gone', 40),
        fleetopsPruneEvent('e-orphan-fresh', 'company-gone', 1),
    ]);
    DB::table('positions')->insert([
        ['uuid' => 'p-old', 'company_uuid' => 'company-1', 'created_at' => Carbon::now()->subDays(100)->toDateTimeString(), 'deleted_at' => null],
        ['uuid' => 'p-fresh', 'company_uuid' => 'company-1', 'created_at' => Carbon::now()->subDays(5)->toDateTimeString(), 'deleted_at' => null],
        ['uuid' => 'p-trashed', 'company_uuid' => 'company-1', 'created_at' => Carbon::now()->toDateTimeString(), 'deleted_at' => Carbon::now()->toDateTimeString()],
        ['uuid' => 'p-forever', 'company_uuid' => 'company-2', 'created_at' => Carbon::now()->subDays(1000)->toDateTimeString(), 'deleted_at' => null],
    ]);
    DB::table('telematic_deliveries')->insert([
        fleetopsPruneDelivery('d-processed-old', 'tm-1', 'processed', 30),
        fleetopsPruneDelivery('d-processed-trashed-connection', 'tm-1-trashed', 'processed', 30),
        fleetopsPruneDelivery('d-processed-fresh', 'tm-1', 'processed', 2),
        fleetopsPruneDelivery('d-pending-old', 'tm-1', 'pending', 200),
        fleetopsPruneDelivery('d-quarantined-old', 'tm-1', 'quarantined', 24 * 8),
        fleetopsPruneDelivery('d-quarantined-fresh', 'tm-1', 'quarantined', 24 * 2),
        fleetopsPruneDelivery('d-company-2-long-hours', 'tm-2', 'processed', 30),
        fleetopsPruneDelivery('d-orphan', 'tm-gone', 'processed', 30),
    ]);
    DB::table('telematic_sync_runs')->insert([
        fleetopsPruneRun('r-old', 'tm-1', 'completed', 10),
        fleetopsPruneRun('r-fresh', 'tm-1', 'completed', 2),
        fleetopsPruneRun('r-fetching-old', 'tm-1', 'fetching', 10),
        fleetopsPruneRun('r-ingesting-old', 'tm-1', 'ingesting', 10),
        fleetopsPruneRun('r-orphan', 'tm-gone', 'partial', 10),
    ]);

    $command           = new PruneTelematicsDataProbe();
    $command->policies = [
        'company-1' => ['event_retention_days' => 30, 'event_compact_after_days' => 7, 'position_retention_days' => 90, 'processed_retention_hours' => 24, 'quarantine_retention_days' => 7, 'sync_run_retention_days' => 7],
        'company-2' => ['event_retention_days' => 0, 'event_compact_after_days' => 0, 'position_retention_days' => 0, 'processed_retention_hours' => 48],
        ''          => ['event_retention_days' => 30, 'processed_retention_hours' => 24, 'sync_run_retention_days' => 7],
    ];

    expect($command->handle())->toBe(0);

    expect(DB::table('device_events')->orderBy('id')->pluck('uuid')->all())->toBe(['e-compact', 'e-compacted-already', 'e-fresh', 'e-forever', 'e-orphan-fresh']);
    $compacted = DB::table('device_events')->where('uuid', 'e-compact')->first();
    expect($compacted->payload)->toBeNull()->and($compacted->meta)->toBeNull()->and($compacted->data)->toBe('{"speed":1}');
    expect(DB::table('device_events')->where('uuid', 'e-fresh')->value('payload'))->toBe('{"raw":true}');
    expect(DB::table('positions')->orderBy('id')->pluck('uuid')->all())->toBe(['p-fresh', 'p-forever']);
    expect(DB::table('telematic_deliveries')->orderBy('uuid')->pluck('uuid')->all())->toBe(['d-company-2-long-hours', 'd-pending-old', 'd-processed-fresh', 'd-quarantined-fresh']);
    expect(DB::table('telematic_sync_runs')->orderBy('uuid')->pluck('uuid')->all())->toBe(['r-fetching-old', 'r-fresh', 'r-ingesting-old']);

    expect($command->messages)->toContain(
        ['info', 'company_one device_events: deleted=2 compacted=1 batches=3'],
        ['info', 'company_one positions: deleted=2 compacted=0 batches=2'],
        ['info', 'company_one telematic_deliveries: deleted=3 compacted=0 batches=2'],
        ['info', 'company_one telematic_sync_runs: deleted=1 compacted=0 batches=1'],
        ['info', 'orphaned device_events: deleted=2 compacted=0 batches=1'],
        ['info', 'orphaned telematic_deliveries: deleted=1 compacted=0 batches=1'],
        ['info', 'orphaned telematic_sync_runs: deleted=1 compacted=0 batches=1'],
        ['info', 'Telematics prune complete: deleted=12 compacted=1 batches=11'],
    );
    // Company two kept everything, so it reports nothing.
    expect(array_filter($command->messages, fn ($message) => str_starts_with($message[1], 'company_two')))->toBe([]);
});

test('prune bounds each sweep by batch count and reports a capped run', function () {
    fleetopsPruneBoot();
    $rows = [];
    for ($i = 0; $i < 120; $i++) {
        $rows[] = fleetopsPruneEvent('e-' . $i, 'company-1', 60);
    }
    DB::table('device_events')->insert($rows);

    $command                         = new PruneTelematicsDataProbe();
    $command->options['company']     = 'company_one';
    $command->options['table']       = ['device_events'];
    $command->options['batch-size']  = 10; // clamped up to the 100 row floor
    $command->options['max-batches'] = 1;
    $command->policies               = ['company-1' => ['event_retention_days' => 30, 'event_compact_after_days' => 0]];

    expect($command->handle())->toBe(0)
        ->and(DB::table('device_events')->count())->toBe(20)
        ->and($command->messages)->toContain(
            ['info', 'company_one device_events: deleted=100 compacted=0 batches=1 capped'],
            ['info', 'Telematics prune complete: deleted=100 compacted=0 batches=1 (capped; more rows remain for the next run)'],
        );

    // The next run finishes the backlog; an exact multiple of the batch size is still reported as capped.
    $command->messages = [];
    expect($command->handle())->toBe(0)->and(DB::table('device_events')->count())->toBe(0);
    $command->options['max-batches'] = 50;
    $command->messages               = [];
    expect($command->handle())->toBe(0)->and($command->messages)->toContain(['info', 'Telematics prune complete: deleted=0 compacted=0 batches=0']);

    // Compaction is bounded the same way.
    $rows = [];
    for ($i = 0; $i < 120; $i++) {
        $rows[] = fleetopsPruneEvent('c-' . $i, 'company-1', 10);
    }
    DB::table('device_events')->insert($rows);
    $command->options['max-batches'] = 1;
    $command->policies               = ['company-1' => ['event_retention_days' => 30, 'event_compact_after_days' => 7]];
    $command->messages               = [];
    expect($command->handle())->toBe(0)
        ->and(DB::table('device_events')->whereNull('payload')->count())->toBe(100)
        ->and($command->messages)->toContain(['info', 'company_one device_events: deleted=0 compacted=100 batches=1 capped']);
});

test('prune dry runs count without touching rows and honour table and company filters', function () {
    fleetopsPruneBoot();
    DB::table('device_events')->insert([
        fleetopsPruneEvent('e-old-1', 'company-1', 40),
        fleetopsPruneEvent('e-compact-1', 'company-1', 10),
        fleetopsPruneEvent('e-old-2', 'company-2', 40),
    ]);
    DB::table('positions')->insert([['uuid' => 'p-old', 'company_uuid' => 'company-1', 'created_at' => Carbon::now()->subDays(100)->toDateTimeString()]]);

    $command                     = new PruneTelematicsDataProbe();
    $command->options['dry-run'] = true;
    $command->options['company'] = 'company-1';
    $command->options['table']   = ['device_events', 'positions', 'device_events'];
    $command->policies           = ['company-1' => ['event_retention_days' => 30, 'event_compact_after_days' => 7, 'position_retention_days' => 90]];

    expect($command->handle())->toBe(0)
        ->and(DB::table('device_events')->count())->toBe(3)
        ->and(DB::table('positions')->count())->toBe(1)
        ->and($command->messages)->toContain(
            ['info', 'company_one device_events: deleted=1 compacted=1 batches=0'],
            ['info', 'company_one positions: deleted=1 compacted=0 batches=0'],
            ['info', 'Telematics prune dry run: deleted=2 compacted=1 batches=0'],
        );

    // Only the requested table is swept when not a dry run.
    $command->options['dry-run'] = false;
    $command->options['table']   = ['positions'];
    $command->messages           = [];
    expect($command->handle())->toBe(0)
        ->and(DB::table('device_events')->count())->toBe(3)
        ->and(DB::table('positions')->count())->toBe(0);
});

test('prune rejects unknown tables and companies', function () {
    fleetopsPruneBoot();
    $command                   = new PruneTelematicsDataProbe();
    $command->options['table'] = ['device_events', 'orders'];
    expect($command->handle())->toBe(PruneTelematicsData::FAILURE)
        ->and($command->messages)->toContain(['error', 'Unknown table. Valid tables: device_events, positions, telematic_deliveries, telematic_sync_runs.']);

    $command                     = new PruneTelematicsDataProbe();
    $command->options['company'] = 'company-missing';
    expect($command->handle())->toBe(PruneTelematicsData::FAILURE)
        ->and($command->messages)->toContain(['error', 'Company [company-missing] was not found.']);
});

test('prune takes a bounded lock and yields to a run already in progress', function () {
    fleetopsPruneBoot();
    $originalCache = Illuminate\Support\Facades\Cache::getFacadeRoot();
    try {
        $store = new class extends Illuminate\Cache\ArrayStore {
            public array $requestedLocks = [];

            public function lock($name, $seconds = 0, $owner = null)
            {
                $this->requestedLocks[] = [$name, $seconds];

                return parent::lock($name, $seconds, $owner);
            }
        };
        Illuminate\Support\Facades\Cache::swap(new Illuminate\Cache\Repository($store));

        $command                     = new PruneTelematicsDataProbe();
        $command->options['no-lock'] = false;
        expect($command->handle())->toBe(0)
            ->and($store->requestedLocks)->toBe([[PruneTelematicsData::LOCK, 840]])
            ->and($store->locks)->toBe([]);

        expect($store->lock(PruneTelematicsData::LOCK, 840)->get())->toBeTrue();
        $command->messages = [];
        expect($command->handle())->toBe(0)
            ->and($command->messages)->toBe([['warn', 'Another telematics prune run appears to be in progress.']]);
    } finally {
        Illuminate\Support\Facades\Cache::swap($originalCache);
    }
});

test('prune resolves the live company policy when none is injected', function () {
    fleetopsPruneBoot();
    Fleetbase\FleetOps\Support\Telematics\Retention\RetentionPolicy::flush();
    Fleetbase\FleetOps\Support\Telematics\Retention\RetentionPolicy::$settingsResolver = fn () => ['event_retention_days' => 5];
    try {
        DB::table('device_events')->insert([fleetopsPruneEvent('e-old', 'company-1', 6)]);
        $command = new class extends PruneTelematicsDataProbe {
            protected function policyFor(?string $companyUuid): Fleetbase\FleetOps\Support\Telematics\Retention\RetentionPolicy
            {
                return PruneTelematicsData::policyFor($companyUuid);
            }
        };
        expect($command->handle())->toBe(0)->and(DB::table('device_events')->count())->toBe(0);
    } finally {
        Fleetbase\FleetOps\Support\Telematics\Retention\RetentionPolicy::flush();
        Fleetbase\FleetOps\Support\Telematics\Retention\RetentionPolicy::$settingsResolver = null;
    }
});
