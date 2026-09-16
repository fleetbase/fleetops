<?php

use Fleetbase\FleetOps\Support\Radar\RadarItemState;
use Fleetbase\Models\Alert;
use Fleetbase\Models\User;
use Illuminate\Config\Repository;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The alerts table and the users it points at, in memory. Every column is a
 * nullable string: what is asserted is what the helper writes and reads.
 */
function fleetOpsRadarStateDatabase(): SQLiteConnection
{
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
    $resolver   = new ConnectionResolver(['default' => $connection, 'mysql' => $connection]);
    $resolver->setDefaultConnection('mysql');
    EloquentModel::setConnectionResolver($resolver);
    EloquentModel::setEventDispatcher(new Dispatcher());
    EloquentModel::clearBootedModels();

    $config = new Repository([
        'activitylog' => ['enabled' => false, 'default_auth_driver' => null, 'default_log_name' => 'default'],
        'api'         => ['cache' => ['enabled' => false]],
    ]);
    app()->instance('config', $config);
    app()->instance(Illuminate\Contracts\Config\Repository::class, $config);
    app()->instance(Spatie\Activitylog\CauserResolver::class, new class extends Spatie\Activitylog\CauserResolver {
        public function __construct()
        {
        }

        public function resolve(EloquentModel|int|string|null $subject = null): ?EloquentModel
        {
            return null;
        }
    });
    app()->instance('db', new class($connection) {
        public function __construct(public SQLiteConnection $connection)
        {
        }

        public function connection($name = null): SQLiteConnection
        {
            return $this->connection;
        }

        public function __call($method, $arguments)
        {
            return $this->connection->{$method}(...$arguments);
        }
    });
    Illuminate\Support\Facades\DB::clearResolvedInstance('db');
    app()->instance('db.schema', $connection->getSchemaBuilder());
    app()->instance('responsecache', new class {
        public function __call($method, $arguments)
        {
            return null;
        }
    });
    app()->instance('request', Request::create('/'));

    $schema = $connection->getSchemaBuilder();
    $tables = [
        'alerts'       => ['uuid', 'public_id', '_key', 'company_uuid', 'category_uuid', 'acknowledged_by_uuid', 'resolved_by_uuid', 'snoozed_by_uuid', 'assigned_to_uuid', 'type', 'severity', 'status', 'subject_type', 'subject_uuid', 'message', 'rule', 'context', 'meta', 'triggered_at', 'acknowledged_at', 'resolved_at', 'snoozed_until', 'planned_at'],
        'users'        => ['uuid', 'public_id', '_key', 'company_uuid', 'name', 'email', 'phone', 'type', 'status'],
        'companies'    => ['uuid', 'public_id', '_key', 'name', 'owner_uuid'],
        'activity_log' => ['uuid', 'company_uuid', 'log_name', 'description', 'subject_type', 'subject_id', 'causer_type', 'causer_id', 'properties', 'event', 'batch_uuid'],
        'settings'     => ['key', 'value'],
    ];

    foreach ($tables as $table => $columns) {
        $schema->create($table, function ($blueprint) use ($columns) {
            $blueprint->increments('id');
            foreach ($columns as $column) {
                $blueprint->string($column)->nullable();
            }
            $blueprint->timestamps();
            $blueprint->timestamp('deleted_at')->nullable();
        });
    }

    $connection->table('companies')->insert(['uuid' => 'company-radar', 'public_id' => 'company_radar', 'name' => 'Radar Co']);
    $connection->table('users')->insert(['uuid' => 'user-ada', 'public_id' => 'user_ada', 'company_uuid' => 'company-radar', 'name' => 'Ada Ops', 'type' => 'user']);
    $connection->table('users')->insert(['uuid' => 'user-bo', 'public_id' => 'user_bo', 'company_uuid' => 'company-radar', 'name' => 'Bo Tran', 'type' => 'user']);

    session(['company' => 'company-radar', 'user' => 'user-ada']);

    return $connection;
}

function fleetOpsRadarStateItem(string $key = 'issue_open:issue_1', array $extra = []): array
{
    [$rule] = explode(':', $key);

    return array_merge([
        'key'      => $key,
        'rule'     => $rule,
        'category' => 'issues',
        'chip'     => 'Issue',
        'severity' => 'critical',
        'title'    => 'Check engine light',
        'due_at'   => null,
        'record'   => ['route' => 'management.issues.index.details', 'model' => 'issue_1'],
        'subject'  => ['type' => 'vehicle', 'class' => 'Fleetbase\FleetOps\Models\Vehicle', 'uuid' => 'vehicle-311', 'public_id' => 'vehicle_311', 'label' => 'TRK-311', 'photo_url' => null],
    ], $extra);
}

afterEach(fn () => Carbon::setTestNow());

test('rowFor creates one open row per item key and reuses it', function () {
    fleetOpsRadarStateDatabase();
    Carbon::setTestNow(Carbon::parse('2026-09-15 08:35:00', 'UTC'));

    $row = RadarItemState::rowFor('company-radar', fleetOpsRadarStateItem());

    expect($row->exists)->toBeTrue()
        ->and($row->type)->toBe('issue_open')
        ->and($row->status)->toBe('open')
        ->and($row->severity)->toBe('critical')
        ->and($row->subject_type)->toBe('Fleetbase\FleetOps\Models\Vehicle')
        ->and($row->subject_uuid)->toBe('vehicle-311')
        ->and($row->message)->toBe('Check engine light')
        ->and($row->context['key'])->toBe('issue_open:issue_1')
        ->and($row->context['subject']['label'])->toBe('TRK-311')
        ->and($row->context['record']['model'])->toBe('issue_1')
        ->and(RadarItemState::keyOf($row))->toBe('issue_open:issue_1');

    $again = RadarItemState::rowFor('company-radar', fleetOpsRadarStateItem());
    expect($again->uuid)->toBe($row->uuid)
        ->and(Alert::query()->count())->toBe(1);

    // A resolved row is history: the next action opens a fresh one.
    RadarItemState::resolve($row, null, 'done');
    $fresh = RadarItemState::rowFor('company-radar', fleetOpsRadarStateItem());
    expect($fresh->uuid)->not->toBe($row->uuid)
        ->and(Alert::query()->count())->toBe(2);
});

test('statesFor and findRow read live rows by key, including notices by public id', function () {
    fleetOpsRadarStateDatabase();
    Carbon::setTestNow(Carbon::parse('2026-09-15 08:35:00', 'UTC'));

    $issue = RadarItemState::rowFor('company-radar', fleetOpsRadarStateItem());
    $ada   = User::query()->where('uuid', 'user-ada')->first();
    $bo    = User::query()->where('uuid', 'user-bo')->first();
    RadarItemState::acknowledge($issue, $ada);
    RadarItemState::assign($issue, $bo);
    RadarItemState::snooze($issue, now()->addHour(), 'Waiting on parts', $bo);
    RadarItemState::plan($issue, now()->addHours(3));

    // A second acknowledgement keeps the first one.
    RadarItemState::acknowledge($issue, $bo);
    expect(RadarItemState::refresh($issue)->getAttribute('acknowledged_by_uuid'))->toBe('user-ada')
        ->and(RadarItemState::refresh($issue)->meta['snooze_reason'])->toBe('Waiting on parts')
        ->and(RadarItemState::refresh(new Alert())->exists)->toBeFalse();

    $notice = Alert::create(['company_uuid' => 'company-radar', 'type' => 'radar_notice', 'severity' => 'info', 'status' => 'open', 'message' => 'Yard closed']);
    $notice->forceFill(['public_id' => 'alert_yard'])->save();

    // A row for another company, and one that is resolved, are not live.
    Alert::create(['company_uuid' => 'company-other', 'type' => 'issue_open', 'severity' => 'info', 'status' => 'open', 'message' => 'x', 'context' => ['key' => 'issue_open:issue_other']]);
    Alert::create(['company_uuid' => 'company-radar', 'type' => 'issue_open', 'severity' => 'info', 'status' => 'resolved', 'message' => 'x', 'context' => ['key' => 'issue_open:issue_done']]);

    $states = RadarItemState::statesFor('company-radar');

    expect(array_keys($states))->toBe(['issue_open:issue_1', 'notice:alert_yard'])
        ->and($states['issue_open:issue_1'])->toMatchArray([
            'status'               => 'acknowledged',
            'alert_id'             => $issue->public_id,
            'acknowledged_at'      => '2026-09-15T08:35:00+00:00',
            'acknowledged_by_name' => 'Ada Ops',
            'snoozed_until'        => '2026-09-15T09:35:00+00:00',
            'snoozed_by_name'      => 'Bo Tran',
            'planned_at'           => '2026-09-15T11:35:00+00:00',
        ])
        ->and($states['issue_open:issue_1']['assigned_to'])->toBe(['uuid' => 'user-bo', 'public_id' => 'user_bo', 'name' => 'Bo Tran', 'initials' => 'BT'])
        ->and(RadarItemState::findRow('company-radar', 'issue_open:issue_1')?->uuid)->toBe($issue->uuid)
        ->and(RadarItemState::findRow('company-radar', 'notice:alert_yard')?->uuid)->toBe($notice->uuid)
        ->and(RadarItemState::findRow('company-radar', 'notice:' . $notice->uuid)?->uuid)->toBe($notice->uuid)
        ->and(RadarItemState::findRow('company-radar', 'issue_open:issue_other'))->toBeNull()
        ->and(RadarItemState::findRow('company-radar', 'issue_open:issue_done'))->toBeNull()
        ->and(RadarItemState::findRow('company-radar', 'garbage'))->toBeNull();

    $notices = RadarItemState::notices('company-radar');
    expect($notices)->toHaveCount(1)
        ->and($notices[0]['public_id'])->toBe('alert_yard')
        ->and($notices[0]['state']['status'])->toBe('open');
});

test('reconcile resolves rows whose gap is gone and leaves the live ones', function () {
    fleetOpsRadarStateDatabase();
    Carbon::setTestNow(Carbon::parse('2026-09-15 08:35:00', 'UTC'));

    $live   = RadarItemState::rowFor('company-radar', fleetOpsRadarStateItem('issue_open:issue_1'));
    $gone   = RadarItemState::rowFor('company-radar', fleetOpsRadarStateItem('part_low_stock:part_pads', ['category' => 'parts', 'chip' => 'Parts', 'title' => 'Brake pads — 2 left']));
    $notice = Alert::create(['company_uuid' => 'company-radar', 'type' => 'radar_notice', 'severity' => 'info', 'status' => 'open', 'message' => 'Yard closed', 'context' => ['key' => 'notice:alert_yard']]);

    // A row written by something other than Radar carries no item key and is left alone.
    Alert::create(['company_uuid' => 'company-radar', 'type' => 'issue_open', 'severity' => 'info', 'status' => 'open', 'message' => 'Raised elsewhere']);
    expect(RadarItemState::statesFor('company-radar'))->not->toHaveKey('')
        ->and(RadarItemState::findRow('company-radar', 'issue_open:elsewhere'))->toBeNull();

    expect(RadarItemState::reconcile('company-radar', ['issue_open:issue_1', 'notice:alert_yard']))->toBe(1)
        ->and($live->fresh()->status)->toBe('open')
        ->and($notice->fresh()->status)->toBe('open')
        ->and($gone->fresh()->status)->toBe('resolved')
        ->and($gone->fresh()->resolved_at?->toIso8601String())->toBe('2026-09-15T08:35:00+00:00')
        ->and($gone->fresh()->meta['resolution'])->toBe('auto');

    $resolved = RadarItemState::resolvedSince('company-radar', Carbon::parse('2026-09-14 00:00:00', 'UTC'), now());
    expect($resolved)->toHaveCount(1)
        ->and($resolved[0]['key'])->toBe('part_low_stock:part_pads')
        ->and($resolved[0]['title'])->toBe('Brake pads — 2 left')
        ->and($resolved[0]['chip'])->toBe('Parts')
        ->and($resolved[0]['category'])->toBe('parts')
        ->and($resolved[0]['meta_line'])->toBe('closed on the record')
        ->and($resolved[0]['subject']['label'])->toBe('TRK-311')
        ->and($resolved[0]['state']['status'])->toBe('resolved')
        ->and($resolved[0]['state']['resolution'])->toBe('auto')
        ->and($resolved[0]['actions'])->toBe(['open_record']);
});

test('snoozeSchedule lists the snoozes waking within a week, soonest first', function () {
    fleetOpsRadarStateDatabase();
    Carbon::setTestNow(Carbon::parse('2026-09-15 08:35:00', 'UTC'));

    $soon  = RadarItemState::rowFor('company-radar', fleetOpsRadarStateItem('issue_open:issue_1', ['title' => 'Soon']));
    $later = RadarItemState::rowFor('company-radar', fleetOpsRadarStateItem('issue_open:issue_2', ['title' => 'Later']));
    $far   = RadarItemState::rowFor('company-radar', fleetOpsRadarStateItem('issue_open:issue_3', ['title' => 'Far']));
    $past  = RadarItemState::rowFor('company-radar', fleetOpsRadarStateItem('issue_open:issue_4', ['title' => 'Past']));

    RadarItemState::snooze($later, now()->addDays(3), null, null);
    RadarItemState::snooze($soon, now()->addHours(2), null, null);
    RadarItemState::snooze($far, now()->addDays(20), null, null);
    RadarItemState::snooze($past, now()->subHour(), null, null);

    // Woken early, a row leaves the schedule.
    $woken = RadarItemState::rowFor('company-radar', fleetOpsRadarStateItem('issue_open:issue_5', ['title' => 'Woken']));
    RadarItemState::snooze($woken, now()->addHours(1), null, null);
    RadarItemState::wake($woken);

    $schedule = RadarItemState::snoozeSchedule('company-radar', now());

    expect(array_column($schedule, 'title'))->toBe(['Soon', 'Later'])
        ->and($schedule[0]['key'])->toBe('issue_open:issue_1')
        ->and($schedule[0]['snoozed_until'])->toBe('2026-09-15T10:35:00+00:00');
});

test('initials come from the first and last name', function () {
    expect(RadarItemState::initials('Ada Ops'))->toBe('AO')
        ->and(RadarItemState::initials('Cher'))->toBe('C')
        ->and(RadarItemState::initials('  mary jane  watson '))->toBe('MW')
        ->and(RadarItemState::initials(null))->toBe('');
});
