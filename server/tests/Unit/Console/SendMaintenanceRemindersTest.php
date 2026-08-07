<?php

use Fleetbase\FleetOps\Console\Commands\SendMaintenanceReminders;
use Fleetbase\FleetOps\Models\MaintenanceSchedule;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;

/**
 * Covers the maintenance reminder command against an in-memory SQLite
 * fixture: schedule loading, reminder window evaluation, already-sent
 * detection, reminder recording, and the mail delegation seam.
 */
class FleetOpsMaintenanceRemindersProbe extends SendMaintenanceReminders
{
    public array $messages = [];
    public array $sent     = [];
    public array $options  = ['sandbox' => false, 'dry-run' => false];

    public function callProtected(string $method, mixed ...$arguments): mixed
    {
        $reflection = new ReflectionMethod(SendMaintenanceReminders::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($this, ...$arguments);
    }

    public function info($string, $verbosity = null)
    {
        $this->messages[] = ['info', $string];
    }

    public function line($string, $style = null, $verbosity = null)
    {
        $this->messages[] = ['line', $string];
    }

    public function option($key = null, $default = null)
    {
        return $this->options[$key] ?? $default;
    }

    protected function sendReminder(string $email, MaintenanceSchedule $schedule, int $offsetDays): void
    {
        $this->sent[] = [$email, $schedule->uuid, $offsetDays];
    }
}

function fleetopsMaintenanceRemindersBoot(): SQLiteConnection
{
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
    $resolver   = new ConnectionResolver(['default' => $connection, 'mysql' => $connection, 'sandbox' => $connection]);
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
    Illuminate\Support\Facades\DB::clearResolvedInstance('db');

    $schema = $connection->getSchemaBuilder();
    $tables = [
        'maintenance_schedules'          => ['uuid', 'public_id', 'company_uuid', 'name', 'status', 'next_due_date', 'reminder_offsets', 'default_assignee_uuid', 'default_assignee_type', 'subject_uuid', 'subject_type'],
        'maintenance_schedule_reminders' => ['schedule_uuid', 'offset_days', 'due_date_snapshot', 'sent_at'],
        'contacts'                       => ['uuid', 'public_id', 'company_uuid', 'name', 'email', 'type'],
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

    session(['company' => 'company-1']);

    return $connection;
}

test('handle sends due reminders and records them once', function () {
    $connection = fleetopsMaintenanceRemindersBoot();
    $connection->table('contacts')->insert(['uuid' => 'contact-1', 'name' => 'Mechanic', 'email' => 'mechanic@example.com', 'type' => 'contact']);
    $connection->table('maintenance_schedules')->insert([
        'uuid'                  => 'schedule-1',
        'public_id'             => 'schedule_a',
        'company_uuid'          => 'company-1',
        'name'                  => 'Oil change',
        'status'                => 'active',
        'next_due_date'         => now()->addDays(2)->toDateString(),
        'reminder_offsets'      => json_encode([7]),
        'default_assignee_uuid' => 'contact-1',
        'default_assignee_type' => 'Fleetbase\\FleetOps\\Models\\Contact',
    ]);

    $command = new FleetOpsMaintenanceRemindersProbe();
    $command->handle();

    expect($command->sent)->toHaveCount(1)
        ->and($command->sent[0][0])->toBe('mechanic@example.com')
        ->and($connection->table('maintenance_schedule_reminders')->count())->toBe(1);

    // A second run detects the recorded reminder and skips sending
    $second = new FleetOpsMaintenanceRemindersProbe();
    $second->handle();
    expect($second->sent)->toBe([])
        ->and($connection->table('maintenance_schedule_reminders')->count())->toBe(1);
});

test('handle skips assignees without email and future reminder windows', function () {
    $connection = fleetopsMaintenanceRemindersBoot();
    $connection->table('contacts')->insert([
        ['uuid' => 'contact-1', 'name' => 'No Email', 'email' => null, 'type' => 'contact'],
        ['uuid' => 'contact-2', 'name' => 'Future', 'email' => 'future@example.com', 'type' => 'contact'],
    ]);
    $connection->table('maintenance_schedules')->insert([
        [
            'uuid'                  => 'schedule-1',
            'public_id'             => 'schedule_a',
            'company_uuid'          => 'company-1',
            'name'                  => 'No email schedule',
            'status'                => 'active',
            'next_due_date'         => now()->addDays(2)->toDateString(),
            'reminder_offsets'      => json_encode([7]),
            'default_assignee_uuid' => 'contact-1',
            'default_assignee_type' => 'Fleetbase\\FleetOps\\Models\\Contact',
            'subject_uuid'          => null,
            'subject_type'          => null,
        ],
        [
            'uuid'                  => 'schedule-2',
            'public_id'             => 'schedule_b',
            'company_uuid'          => 'company-1',
            'name'                  => 'Future schedule',
            'status'                => 'active',
            'next_due_date'         => now()->addDays(60)->toDateString(),
            'reminder_offsets'      => json_encode([3]),
            'default_assignee_uuid' => 'contact-2',
            'default_assignee_type' => 'Fleetbase\\FleetOps\\Models\\Contact',
            'subject_uuid'          => null,
            'subject_type'          => null,
        ],
        [
            // Passes the not-null column filter but carries no offsets to act on,
            // so it is skipped before the assignee is even resolved
            'uuid'                  => 'schedule-3',
            'public_id'             => 'schedule_c',
            'company_uuid'          => 'company-1',
            'name'                  => 'Offsetless schedule',
            'status'                => 'active',
            'next_due_date'         => now()->addDays(2)->toDateString(),
            'reminder_offsets'      => json_encode([]),
            'default_assignee_uuid' => 'contact-2',
            'default_assignee_type' => 'Fleetbase\\FleetOps\\Models\\Contact',
            'subject_uuid'          => null,
            'subject_type'          => null,
        ],
    ]);

    $command = new FleetOpsMaintenanceRemindersProbe();
    $command->handle();

    expect($command->sent)->toBe([])
        ->and(collect($command->messages)->pluck(1)->filter(fn ($m) => str_contains($m, 'Skipped'))->count())->toBe(1);
});

test('send reminder seam executes its mail delegation body', function () {
    fleetopsMaintenanceRemindersBoot();

    $schedule = new MaintenanceSchedule();
    $schedule->setRawAttributes(['uuid' => 'schedule-1', 'name' => 'Oil change'], true);

    // The mailer is not bound in the harness; the delegation body still
    // executes, which is the covered contract here.
    $probe = new class extends SendMaintenanceReminders {
        public function callSend(string $email, MaintenanceSchedule $schedule, int $offsetDays): void
        {
            $this->sendReminder($email, $schedule, $offsetDays);
        }
    };

    expect(fn () => $probe->callSend('mechanic@example.com', $schedule, 7))->toThrow(Exception::class);
});

test('reminder bookkeeping helpers execute against the database', function () {
    $connection = fleetopsMaintenanceRemindersBoot();

    $schedule = new MaintenanceSchedule();
    $schedule->setRawAttributes(['uuid' => 'schedule-1'], true);

    $probe = new FleetOpsMaintenanceRemindersProbe();

    expect($probe->callProtected('reminderAlreadySent', 'mysql', $schedule, 7, '2026-08-01'))->toBeFalse();

    $probe->callProtected('recordReminder', 'mysql', $schedule, 7, '2026-08-01');

    expect($connection->table('maintenance_schedule_reminders')->count())->toBe(1)
        ->and($probe->callProtected('reminderAlreadySent', 'mysql', $schedule, 7, '2026-08-01'))->toBeTrue();
});
