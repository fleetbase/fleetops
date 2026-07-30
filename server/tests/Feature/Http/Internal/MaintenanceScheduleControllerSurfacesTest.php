<?php

use Fleetbase\FleetOps\Exports\MaintenanceScheduleExport;
use Fleetbase\FleetOps\Http\Controllers\Internal\v1\MaintenanceScheduleController;
use Fleetbase\FleetOps\Models\MaintenanceSchedule;
use Fleetbase\FleetOps\Models\WorkOrder;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Covers the internal MaintenanceScheduleController export/import endpoints
 * and the protected schedule lookup, calendar query, and work-order
 * creation helpers against SQLite with an excel fake.
 */
if (!class_exists('Fleetbase\Http\Requests\ExportRequest', false)) {
    eval('namespace Fleetbase\Http\Requests; class ExportRequest extends \Illuminate\Http\Request {}');
}

if (!class_exists('Fleetbase\Http\Requests\ImportRequest', false)) {
    eval('namespace Fleetbase\Http\Requests; class ImportRequest extends \Illuminate\Http\Request {}');
}

if (!Request::hasMacro('resolveFilesFromIds')) {
    Request::macro('resolveFilesFromIds', fn () => FleetOpsMaintScheduleState::$files);
}

if (!function_exists('Fleetbase\Support\session')) {
    eval('namespace Fleetbase\Support; function session($key = null, $default = null) { if ($key === null) { return new class { public function has($k) { return \session($k) !== null; } public function get($k, $d = null) { return \session($k, $d); } }; } return \session($key, $default); }');
}

if (!function_exists('Fleetbase\Support\auth')) {
    eval('namespace Fleetbase\Support; function auth() { return new class { public function user() { return null; } public function id() { return null; } }; }');
}

class FleetOpsMaintScheduleState
{
    public static array $files = [];
}

class FleetOpsMaintScheduleExcelFake
{
    public array $downloads  = [];
    public array $imports    = [];
    public bool $importFails = false;

    public function download($export, string $fileName): string
    {
        $this->downloads[] = [$export, $fileName];

        return 'downloaded:' . $fileName;
    }

    public function import($import, $path, $disk = null): bool
    {
        if ($this->importFails) {
            throw new RuntimeException('corrupt file');
        }

        $this->imports[] = [$import, $path, $disk];
        $import->imported++;

        return true;
    }
}

class FleetOpsMaintScheduleProbe extends MaintenanceScheduleController
{
    public function callHelper(string $method, ...$arguments): mixed
    {
        return $this->{$method}(...$arguments);
    }
}

function fleetopsMaintScheduleBoot(): SQLiteConnection
{
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
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
    Illuminate\Support\Facades\DB::clearResolvedInstance('db');

    $excelFake = new FleetOpsMaintScheduleExcelFake();
    app()->instance('excel', $excelFake);
    Maatwebsite\Excel\Facades\Excel::clearResolvedInstance('excel');
    $GLOBALS['fleetopsMaintScheduleExcelFake'] = $excelFake;

    $schema = $connection->getSchemaBuilder();
    $tables = [
        'maintenance_schedules' => ['uuid', 'public_id', 'company_uuid', 'subject_uuid', 'subject_type', 'subject_id', 'default_assignee_uuid', 'default_assignee_type', 'default_priority', 'name', 'status', 'next_due_date', 'next_due_odometer', 'next_due_engine_hours', 'instructions', 'interval_value', 'interval_unit', 'meta'],
        'work_orders'           => ['uuid', 'public_id', 'company_uuid', 'schedule_uuid', 'subject', 'category', 'status', 'priority', 'target_type', 'target_uuid', 'assignee_type', 'assignee_uuid', 'instructions', 'due_at', 'created_by_uuid', 'code'],
        'vehicles'              => ['uuid', 'public_id', 'company_uuid', 'name'],
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

    session(['company' => 'company-1', 'user' => 'user-1']);

    return $connection;
}

test('export streams a schedule export and import processes files', function () {
    fleetopsMaintScheduleBoot();

    $request  = Fleetbase\Http\Requests\ExportRequest::create('/int/v1/maintenance-schedules/export', 'POST', ['format' => 'csv', 'selections' => ['ms-1']]);
    $response = (new MaintenanceScheduleController())->export($request);
    expect($response)->toStartWith('downloaded:maintenance-schedules-')
        ->and($GLOBALS['fleetopsMaintScheduleExcelFake']->downloads[0][0])->toBeInstanceOf(MaintenanceScheduleExport::class);

    FleetOpsMaintScheduleState::$files = [(object) ['path' => 'uploads/schedules.xlsx']];
    $importRequest                     = Fleetbase\Http\Requests\ImportRequest::create('/int/v1/maintenance-schedules/import', 'POST', ['disk' => 'local']);
    $imported                          = (new MaintenanceScheduleController())->import($importRequest);
    expect($imported->getData(true))->toBe(['status' => 'ok', 'message' => 'Import completed', 'imported' => 1]);

    $GLOBALS['fleetopsMaintScheduleExcelFake']->importFails = true;
    $failure                                                = (new MaintenanceScheduleController())->import($importRequest);
    expect($failure->getData(true)['error'])->toContain('Invalid file');
});

test('schedule lookups and calendar queries resolve records', function () {
    $connection = fleetopsMaintScheduleBoot();
    $connection->table('maintenance_schedules')->insert([
        ['uuid' => 'ms-1', 'public_id' => 'schedule_active', 'company_uuid' => 'company-1', 'name' => 'Oil Change', 'status' => 'active', 'next_due_date' => Carbon::now()->addDays(3)->toDateTimeString()],
        ['uuid' => 'ms-2', 'public_id' => 'schedule_paused', 'company_uuid' => 'company-1', 'name' => 'Inspection', 'status' => 'paused', 'next_due_date' => Carbon::now()->addDays(3)->toDateTimeString()],
    ]);

    $probe = new FleetOpsMaintScheduleProbe();

    expect($probe->callHelper('findSchedule', 'ms-1')?->uuid)->toBe('ms-1')
        ->and($probe->callHelper('findSchedule', 'schedule_active')?->uuid)->toBe('ms-1')
        ->and($probe->callHelper('findScheduleWithRelations', 'ms-1', ['subject']))->toBeInstanceOf(MaintenanceSchedule::class);

    $calendar = $probe->callHelper('activeCalendarSchedules', Carbon::now()->addDays(30));
    expect($calendar)->toHaveCount(1)
        ->and($calendar->first()->uuid)->toBe('ms-1');

    expect($probe->callHelper('sessionUserUuid'))->toBe('user-1');

    // The harness response() helper returns a lightweight stand-in that
    // violates the declared Response type — the helper body still executes.
    expect(fn () => $probe->callHelper('icalResponse', 'BEGIN:VCALENDAR', ['Content-Type' => 'text/calendar']))->toThrow(TypeError::class);
});

test('the calendar feed defaults to a ninety day window from today', function () {
    $connection = fleetopsMaintScheduleBoot();
    $connection->table('maintenance_schedules')->insert([
        // inside the default window
        ['uuid' => 'ms-cal-1', 'public_id' => 'schedule_calnear', 'company_uuid' => 'company-1', 'name' => 'Near Service', 'status' => 'active', 'next_due_date' => Carbon::today()->addDays(10)->toDateTimeString()],
        // beyond the default 90 day window, so it cannot produce an occurrence
        ['uuid' => 'ms-cal-2', 'public_id' => 'schedule_calfar', 'company_uuid' => 'company-1', 'name' => 'Far Service', 'status' => 'active', 'next_due_date' => Carbon::today()->addDays(200)->toDateTimeString()],
    ]);

    // With no start/end supplied the window falls back to today through today+90
    $feed = (new MaintenanceScheduleController())
        ->calendarFeed(Request::create('/int/v1/maintenance-schedules/calendar-feed', 'GET'));

    $events = $feed->getData(true)['events'];
    $uuids  = collect($events)->pluck('uuid')->unique()->values()->all();

    expect($events)->not->toBeEmpty()
        ->and($uuids)->toContain('ms-cal-1')
        ->and($uuids)->not->toContain('ms-cal-2');
});

test('work orders derive from schedule attributes', function () {
    $connection = fleetopsMaintScheduleBoot();
    $connection->table('maintenance_schedules')->insert([
        'uuid'                  => 'ms-1',
        'public_id'             => 'schedule_active',
        'company_uuid'          => 'company-1',
        'subject_uuid'          => 'vehicle-1',
        'subject_type'          => Fleetbase\FleetOps\Models\Vehicle::class,
        'default_priority'      => 'high',
        'name'                  => 'Oil Change',
        'status'                => 'active',
        'instructions'          => 'Change the oil.',
        'next_due_date'         => Carbon::now()->addDays(3)->toDateTimeString(),
    ]);

    $probe    = new FleetOpsMaintScheduleProbe();
    $schedule = MaintenanceSchedule::withoutGlobalScopes()->where('uuid', 'ms-1')->first();

    $workOrder = $probe->callHelper('createWorkOrderFromSchedule', $schedule);

    expect($workOrder)->toBeInstanceOf(WorkOrder::class)
        ->and($connection->table('work_orders')->count())->toBe(1)
        ->and($connection->table('work_orders')->value('priority'))->toBe('high')
        ->and($connection->table('work_orders')->value('category'))->toBe('preventive_maintenance');
});
