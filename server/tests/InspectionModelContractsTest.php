<?php

use Fleetbase\FleetOps\Models\InspectionForm;
use Fleetbase\FleetOps\Models\InspectionItemResult;
use Fleetbase\FleetOps\Models\InspectionLink;
use Fleetbase\FleetOps\Models\InspectionSubmission;
use Fleetbase\FleetOps\Models\Issue;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\FleetOps\Models\WorkOrder;
use Fleetbase\FleetOps\Rules\Base64OrUrl;
use Fleetbase\FleetOps\Support\InspectionSubmitter;
use Illuminate\Config\Repository;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;

if (!function_exists('Fleetbase\\Support\\auth')) {
    eval('namespace Fleetbase\\Support; function auth() { return new class { public function user() { return null; } public function id() { return null; } }; }');
}

/**
 * The inspection tables, and the neighbours their relations reach, as an
 * in-memory SQLite database. Every column is a nullable string: what is
 * asserted here is what the models write and read, not the column types the
 * migration chooses.
 */
function fleetOpsInspectionModelDatabase(): SQLiteConnection
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->sqliteCreateFunction('ST_PointFromText', fn ($wkt, $srid = 0, $axisOrder = null) => $wkt);
    $pdo->sqliteCreateFunction('ST_GeomFromText', fn ($wkt, $srid = 0, $axisOrder = null) => $wkt);
    $connection = new SQLiteConnection($pdo);
    $resolver   = new ConnectionResolver(['default' => $connection, 'mysql' => $connection]);
    $resolver->setDefaultConnection('mysql');
    EloquentModel::setConnectionResolver($resolver);
    EloquentModel::setEventDispatcher(new Dispatcher());
    EloquentModel::clearBootedModels();

    $config = new Repository([
        'activitylog' => ['enabled' => false, 'default_auth_driver' => null, 'default_log_name' => 'default'],
        'api'         => ['cache' => ['enabled' => false]],
        'filesystems' => ['default' => 'local'],
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
        'inspection_forms'        => ['uuid', 'public_id', '_key', 'company_uuid', 'name', 'description', 'type', 'status', 'frequency', 'subject_type', 'subject_uuid', 'items', 'settings', 'meta', 'published_at', 'created_by_uuid', 'updated_by_uuid'],
        'inspection_links'        => ['uuid', 'public_id', '_key', 'company_uuid', 'inspection_form_uuid', 'driver_uuid', 'vehicle_uuid', 'created_by_uuid', 'token_hash', 'status', 'single_use', 'expires_at', 'last_viewed_at', 'used_at', 'used_ip', 'used_user_agent', 'meta'],
        'inspection_submissions'  => ['uuid', 'public_id', '_key', 'company_uuid', 'inspection_form_uuid', 'vehicle_uuid', 'driver_uuid', 'submitted_by_uuid', 'issue_uuid', 'work_order_uuid', 'type', 'status', 'result', 'source', 'odometer', 'engine_hours', 'total_items', 'failed_items', 'started_at', 'submitted_at', 'resolved_at', 'location', 'signature', 'attachments', 'meta', 'created_by_uuid', 'updated_by_uuid'],
        'inspection_item_results' => ['uuid', '_key', 'company_uuid', 'inspection_submission_uuid', 'issue_uuid', 'work_order_uuid', 'item_key', 'label', 'category', 'status', 'severity', 'passed', 'comments', 'photos', 'meta', 'created_by_uuid', 'updated_by_uuid'],
        'issues'                  => ['uuid', 'public_id', '_key', 'company_uuid', 'reported_by_uuid', 'assigned_to_uuid', 'vehicle_uuid', 'driver_uuid', 'order_uuid', 'issue_id', 'location', 'category', 'type', 'report', 'title', 'tags', 'priority', 'meta', 'resolved_at', 'status'],
        'work_orders'             => ['uuid', 'public_id', '_key', 'company_uuid', 'schedule_uuid', 'code', 'subject', 'category', 'status', 'priority', 'target_type', 'target_uuid', 'assignee_type', 'assignee_uuid', 'opened_at', 'due_at', 'closed_at', 'instructions', 'checklist', 'currency', 'estimated_cost', 'approved_budget', 'actual_cost', 'cost_center', 'budget_code', 'meta', 'created_by_uuid', 'updated_by_uuid'],
        'vehicles'                => ['uuid', 'public_id', 'internal_id', '_key', 'company_uuid', 'vendor_uuid', 'photo_uuid', 'name', 'make', 'model', 'year', 'trim', 'plate_number', 'vin', 'status', 'currency', 'slug', 'online', 'location'],
        'drivers'                 => ['uuid', 'public_id', 'internal_id', '_key', 'company_uuid', 'user_uuid', 'vehicle_uuid', 'vendor_uuid', 'current_job_uuid', 'photo_uuid', 'status', 'online', 'location', 'slug'],
        'users'                   => ['uuid', 'public_id', '_key', 'company_uuid', 'name', 'email', 'phone', 'avatar_uuid', 'type', 'status'],
        'companies'               => ['uuid', 'public_id', '_key', 'name', 'owner_uuid'],
        'company_users'           => ['uuid', '_key', 'company_uuid', 'user_uuid', 'role_uuid', 'status'],
        'settings'                => ['key', 'value'],
        'files'                   => ['uuid', 'public_id', '_key', 'company_uuid', 'subject_uuid', 'subject_type', 'path', 'disk', 'type'],
        'vendors'                 => ['uuid', 'public_id', '_key', 'company_uuid', 'name'],
        'orders'                  => ['uuid', 'public_id', '_key', 'company_uuid', 'driver_assigned_uuid', 'status'],
        'positions'               => ['uuid', 'public_id', '_key', 'company_uuid', 'subject_uuid', 'subject_type', 'coordinates'],
        'maintenances'            => ['uuid', 'public_id', '_key', 'company_uuid', 'maintainable_type', 'maintainable_uuid', 'status', 'completed_at'],
        'maintenance_schedules'   => ['uuid', 'public_id', '_key', 'company_uuid', 'subject_type', 'subject_uuid', 'status', 'next_due_at'],
        'custom_field_values'     => ['uuid', 'company_uuid', 'custom_field_uuid', 'subject_uuid', 'subject_type', 'value', 'value_type'],
        'custom_fields'           => ['uuid', 'company_uuid', 'label', 'name', 'type'],
        'activity_log'            => ['uuid', 'company_uuid', 'log_name', 'description', 'subject_type', 'subject_id', 'causer_type', 'causer_id', 'properties', 'event', 'batch_uuid'],
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

    $connection->table('companies')->insert(['uuid' => 'company-insp', 'public_id' => 'company_insp', 'name' => 'Inspection Co']);
    $connection->table('users')->insert(['uuid' => 'user-driver', 'public_id' => 'user_driver', 'company_uuid' => 'company-insp', 'name' => 'Dana Driver', 'email' => 'dana@example.com', 'phone' => '+15550001111', 'type' => 'user']);
    $connection->table('users')->insert(['uuid' => 'user-admin', 'public_id' => 'user_admin', 'company_uuid' => 'company-insp', 'name' => 'Avery Admin', 'email' => 'avery@example.com', 'type' => 'user']);
    $connection->table('vehicles')->insert(['uuid' => 'vehicle-1', 'public_id' => 'vehicle_one', 'company_uuid' => 'company-insp', 'name' => 'Truck 7', 'plate_number' => 'TRK-7', 'currency' => 'SGD']);
    $connection->table('vehicles')->insert(['uuid' => 'vehicle-2', 'public_id' => 'vehicle_two', 'company_uuid' => 'company-insp', 'make' => 'Isuzu', 'model' => 'NPR', 'year' => '2022', 'plate_number' => 'TRK-2']);
    $connection->table('drivers')->insert([
        ['uuid' => 'driver-1', 'public_id' => 'driver_one', 'company_uuid' => 'company-insp', 'user_uuid' => 'user-driver', 'vehicle_uuid' => 'vehicle-1', 'status' => 'active'],
    ]);

    session(['company' => 'company-insp', 'user' => 'user-admin']);

    return $connection;
}

function fleetOpsInspectionModelForm(array $attributes = []): InspectionForm
{
    return InspectionForm::create(array_merge([
        'company_uuid'    => 'company-insp',
        'name'            => 'Pre-trip DVIR',
        'type'            => 'dvir',
        'status'          => 'draft',
        'items'           => [
            ['key' => 'brakes', 'label' => 'Brakes', 'category' => 'Safety', 'severity' => 'critical'],
            ['key' => 'lights', 'label' => 'Lights', 'category' => 'Safety', 'severity' => 'medium'],
        ],
        'settings'        => ['create_issue_on_failure' => true, 'create_work_order_on_failure' => true],
        'created_by_uuid' => 'user-admin',
        'updated_by_uuid' => 'user-admin',
    ], $attributes));
}

function fleetOpsInspectionModelSubmission(InspectionForm $form, array $items, array $attributes = []): InspectionSubmission
{
    $submission = InspectionSubmission::create(array_merge([
        'company_uuid'         => 'company-insp',
        'inspection_form_uuid' => $form->uuid,
        'vehicle_uuid'         => 'vehicle-1',
        'driver_uuid'          => 'driver-1',
        'submitted_by_uuid'    => 'user-driver',
        'type'                 => 'dvir',
        'status'               => 'draft',
        'source'               => 'test',
    ], $attributes));

    foreach ($items as $item) {
        InspectionItemResult::create(array_merge([
            'company_uuid'               => 'company-insp',
            'inspection_submission_uuid' => $submission->uuid,
        ], $item));
    }

    return $submission;
}

afterEach(function () {
    Carbon::setTestNow();
});

test('inspection form knows when it is published and can be archived', function () {
    fleetOpsInspectionModelDatabase();

    $form = fleetOpsInspectionModelForm();

    // A draft is not published, and neither is a form whose status says so
    // without the timestamp to prove it.
    expect($form->is_published)->toBeFalse()
        ->and($form->item_count)->toBe(2)
        ->and($form->public_id)->toStartWith('inspection_form_')
        ->and($form->getActivitylogOptions())->toBeInstanceOf(LogOptions::class);

    $form->update(['status' => 'published']);
    expect($form->fresh()->is_published)->toBeFalse();

    Carbon::setTestNow('2026-09-09 08:00:00');
    expect($form->publish())->toBeTrue();

    $published = $form->fresh();
    expect($published->is_published)->toBeTrue()
        ->and($published->published_at->toDateTimeString())->toBe('2026-09-09 08:00:00');

    // Publishing again keeps the original publication date.
    Carbon::setTestNow('2026-09-10 08:00:00');
    $published->publish();
    expect($published->fresh()->published_at->toDateTimeString())->toBe('2026-09-09 08:00:00');

    expect($published->archive())->toBeTrue()
        ->and($published->fresh()->status)->toBe('archived')
        ->and($published->fresh()->is_published)->toBeFalse();
});

test('inspection form names its subject and exposes its relations', function () {
    fleetOpsInspectionModelDatabase();

    $bound = fleetOpsInspectionModelForm(['subject_type' => Vehicle::class, 'subject_uuid' => 'vehicle-1']);
    $wide  = fleetOpsInspectionModelForm(['name' => 'Fleet-wide']);

    expect($bound->fresh()->subject_name)->toBe('Truck 7')
        ->and($wide->fresh()->subject_name)->toBeNull()
        ->and($bound->subject())->toBeInstanceOf(MorphTo::class)
        ->and($bound->submissions())->toBeInstanceOf(HasMany::class)
        ->and($bound->createdBy())->toBeInstanceOf(BelongsTo::class)
        ->and($bound->updatedBy())->toBeInstanceOf(BelongsTo::class)
        ->and($bound->fresh()->createdBy->name)->toBe('Avery Admin');

    $submission = fleetOpsInspectionModelSubmission($bound, []);
    expect($bound->submissions()->count())->toBe(1)
        ->and($bound->submissions->first()->uuid)->toBe($submission->uuid);
});

test('inspection submission counts its results and names what it belongs to', function () {
    fleetOpsInspectionModelDatabase();

    $form       = fleetOpsInspectionModelForm();
    $submission = fleetOpsInspectionModelSubmission($form, [
        ['label' => 'Brakes', 'passed' => true],
        ['label' => 'Lights', 'passed' => false, 'severity' => 'medium'],
        ['label' => 'Horn', 'passed' => false],
    ]);

    expect($submission->form())->toBeInstanceOf(BelongsTo::class)
        ->and($submission->vehicle())->toBeInstanceOf(BelongsTo::class)
        ->and($submission->driver())->toBeInstanceOf(BelongsTo::class)
        ->and($submission->submittedBy())->toBeInstanceOf(BelongsTo::class)
        ->and($submission->issue())->toBeInstanceOf(BelongsTo::class)
        ->and($submission->workOrder())->toBeInstanceOf(BelongsTo::class)
        ->and($submission->itemResults())->toBeInstanceOf(HasMany::class)
        ->and($submission->failedItemResults())->toBeInstanceOf(HasMany::class)
        ->and($submission->getActivitylogOptions())->toBeInstanceOf(LogOptions::class)
        ->and($submission->public_id)->toStartWith('inspection_submission_');

    // Nothing counted yet: a fresh draft has no failures on record.
    expect($submission->has_failures)->toBeFalse();

    Carbon::setTestNow('2026-09-09 09:30:00');
    expect($submission->syncResultCounts())->toBeTrue();

    $synced = $submission->fresh();
    expect($synced->total_items)->toBe(3)
        ->and($synced->failed_items)->toBe(2)
        ->and($synced->result)->toBe('failed')
        ->and($synced->status)->toBe('submitted')
        ->and($synced->submitted_at->toDateTimeString())->toBe('2026-09-09 09:30:00')
        ->and($synced->has_failures)->toBeTrue()
        ->and($synced->form_name)->toBe('Pre-trip DVIR')
        ->and($synced->vehicle_name)->toBe('Truck 7')
        ->and($synced->driver_name)->toBe('Dana Driver')
        ->and($synced->submittedBy->name)->toBe('Dana Driver');

    // A second sync keeps the first submitted_at and leaves a non-draft status alone.
    $synced->update(['status' => 'needs_review']);
    Carbon::setTestNow('2026-09-09 11:00:00');
    $synced->syncResultCounts();
    expect($synced->fresh()->status)->toBe('needs_review')
        ->and($synced->fresh()->submitted_at->toDateTimeString())->toBe('2026-09-09 09:30:00');

    // `result` alone is enough to count as failed, for rows written before counts existed.
    $legacy = fleetOpsInspectionModelSubmission($form, [], ['result' => 'failed', 'failed_items' => 0]);
    expect($legacy->has_failures)->toBeTrue();

    // A vehicle with no name is named from its make and model, and a display
    // name is what the submission reports.
    $unnamed = fleetOpsInspectionModelSubmission($form, [], ['vehicle_uuid' => 'vehicle-2']);
    expect($unnamed->fresh()->vehicle_name)->toBe('2022 Isuzu NPR');
});

test('inspection submission ranks failures by severity', function () {
    fleetOpsInspectionModelDatabase();

    $form = fleetOpsInspectionModelForm();

    $clean = fleetOpsInspectionModelSubmission($form, [['label' => 'Brakes', 'passed' => true]]);
    $clean->syncResultCounts();
    expect($clean->fresh()->highestFailureSeverity())->toBe('low');

    // Failed without a severity is still a failure, and defaults high rather
    // than being quietly filed as low.
    $unranked = fleetOpsInspectionModelSubmission($form, [['label' => 'Horn', 'passed' => false]]);
    $unranked->syncResultCounts();
    expect($unranked->fresh()->highestFailureSeverity())->toBe('high');

    $mixed = fleetOpsInspectionModelSubmission($form, [
        ['label' => 'Lights', 'passed' => false, 'severity' => 'Medium'],
        ['label' => 'Mirror', 'passed' => false, 'severity' => 'low'],
        ['label' => 'Brakes', 'passed' => false, 'severity' => 'CRITICAL'],
        ['label' => 'Tyres', 'passed' => true, 'severity' => 'critical'],
    ]);
    $mixed->syncResultCounts();
    expect($mixed->fresh()->highestFailureSeverity())->toBe('critical');

    $medium = fleetOpsInspectionModelSubmission($form, [
        ['label' => 'Lights', 'passed' => false, 'severity' => 'medium'],
        ['label' => 'Mirror', 'passed' => false, 'severity' => 'low'],
    ]);
    $medium->syncResultCounts();
    expect($medium->fresh()->highestFailureSeverity())->toBe('medium');
});

test('inspection submission raises an issue from its failed items once', function () {
    fleetOpsInspectionModelDatabase();

    $form = fleetOpsInspectionModelForm();

    $clean = fleetOpsInspectionModelSubmission($form, [['label' => 'Brakes', 'passed' => true]]);
    $clean->syncResultCounts();
    expect($clean->fresh()->createIssueFromFailures())->toBeNull()
        ->and(Issue::query()->count())->toBe(0);

    $failed = fleetOpsInspectionModelSubmission($form, [
        ['label' => 'Brakes', 'passed' => false, 'severity' => 'critical', 'item_key' => 'brakes'],
        ['label' => 'Lights', 'passed' => false, 'severity' => 'medium', 'item_key' => 'lights'],
        ['label' => 'Horn', 'passed' => true],
    ]);
    $failed->syncResultCounts();
    $failed = $failed->fresh();

    $issue = $failed->createIssueFromFailures();

    expect($issue)->toBeInstanceOf(Issue::class)
        ->and($issue->title)->toBe('Failed inspection: Truck 7')
        ->and($issue->report)->toBe('Failed items: Brakes, Lights')
        ->and($issue->priority)->toBe('critical')
        ->and($issue->type)->toBe('inspection')
        ->and($issue->category)->toBe('inspection_failed')
        ->and($issue->status)->toBe('pending')
        ->and($issue->reported_by_uuid)->toBe('user-driver')
        ->and($issue->vehicle_uuid)->toBe('vehicle-1')
        ->and($issue->driver_uuid)->toBe('driver-1')
        ->and($issue->meta['inspection_submission_uuid'])->toBe($failed->uuid)
        ->and($issue->meta['failed_items'])->toBe(['Brakes', 'Lights'])
        ->and($failed->fresh()->issue_uuid)->toBe($issue->uuid);

    // Asking again hands back the same issue rather than filing a duplicate.
    $again = $failed->fresh()->createIssueFromFailures();
    expect($again->uuid)->toBe($issue->uuid)
        ->and(Issue::query()->count())->toBe(1);

    // No vehicle to name: the submission's own id stands in.
    $unassigned = fleetOpsInspectionModelSubmission($form, [['label' => 'Horn', 'passed' => false]], ['vehicle_uuid' => null]);
    $unassigned->syncResultCounts();
    $orphanIssue = $unassigned->fresh()->createIssueFromFailures();
    expect($orphanIssue->title)->toBe('Failed inspection: ' . $unassigned->public_id)
        ->and($orphanIssue->report)->toBe('Failed items: Horn');
});

test('inspection submission opens a work order with a checklist of the failed items', function () {
    fleetOpsInspectionModelDatabase();
    Carbon::setTestNow('2026-09-09 10:00:00');

    $form = fleetOpsInspectionModelForm();

    $clean = fleetOpsInspectionModelSubmission($form, [['label' => 'Brakes', 'passed' => true]]);
    $clean->syncResultCounts();
    expect($clean->fresh()->createWorkOrderFromFailures())->toBeNull()
        ->and(WorkOrder::query()->count())->toBe(0);

    $critical = fleetOpsInspectionModelSubmission($form, [
        ['label' => 'Brakes', 'passed' => false, 'severity' => 'critical', 'item_key' => 'brakes'],
        ['label' => 'Horn', 'passed' => true],
    ]);
    $critical->syncResultCounts();
    $critical->fresh()->createIssueFromFailures();
    $critical = $critical->fresh();

    $workOrder = $critical->createWorkOrderFromFailures();

    expect($workOrder)->toBeInstanceOf(WorkOrder::class)
        ->and($workOrder->subject)->toBe('Inspection repair: Truck 7')
        ->and($workOrder->status)->toBe('open')
        ->and($workOrder->priority)->toBe('critical')
        ->and($workOrder->target_type)->toBe(Vehicle::class)
        ->and($workOrder->target_uuid)->toBe('vehicle-1')
        ->and($workOrder->currency)->toBe('SGD')
        ->and($workOrder->created_by_uuid)->toBe('user-driver')
        // Critical is due tomorrow; anything else gets a week.
        ->and($workOrder->due_at->toDateTimeString())->toBe('2026-09-10 10:00:00')
        ->and($workOrder->checklist)->toHaveCount(1)
        ->and($workOrder->checklist[0])->toMatchArray(['title' => 'Brakes', 'item_key' => 'brakes', 'severity' => 'critical', 'required' => true, 'completed' => false, 'source' => 'inspection'])
        ->and($workOrder->meta['issue_uuid'])->toBe($critical->issue_uuid)
        ->and($critical->fresh()->work_order_uuid)->toBe($workOrder->uuid);

    // The failed items now point at the work order; the passed one does not.
    expect($critical->itemResults()->where('label', 'Brakes')->value('work_order_uuid'))->toBe($workOrder->uuid)
        ->and($critical->itemResults()->where('label', 'Horn')->value('work_order_uuid'))->toBeNull();

    $again = $critical->fresh()->createWorkOrderFromFailures();
    expect($again->uuid)->toBe($workOrder->uuid)
        ->and(WorkOrder::query()->count())->toBe(1);

    $routine = fleetOpsInspectionModelSubmission($form, [['label' => 'Lights', 'passed' => false, 'severity' => 'medium']], ['vehicle_uuid' => null]);
    $routine->syncResultCounts();
    $routineOrder = $routine->fresh()->createWorkOrderFromFailures();
    expect($routineOrder->due_at->toDateTimeString())->toBe('2026-09-16 10:00:00')
        ->and($routineOrder->target_type)->toBeNull()
        ->and($routineOrder->subject)->toBe('Inspection repair: ' . $routine->public_id);
});

test('inspection item result belongs to its submission and follow-up', function () {
    fleetOpsInspectionModelDatabase();

    $form       = fleetOpsInspectionModelForm();
    $submission = fleetOpsInspectionModelSubmission($form, [
        ['label' => 'Brakes', 'passed' => false, 'photos' => ['data:image/png;base64,iVBORw0KGgo='], 'meta' => ['note' => 'squeal'], 'created_by_uuid' => 'user-driver'],
    ]);

    $result = $submission->itemResults()->first();

    expect($result->submission())->toBeInstanceOf(BelongsTo::class)
        ->and($result->issue())->toBeInstanceOf(BelongsTo::class)
        ->and($result->workOrder())->toBeInstanceOf(BelongsTo::class)
        ->and($result->createdBy())->toBeInstanceOf(BelongsTo::class)
        ->and($result->getActivitylogOptions())->toBeInstanceOf(LogOptions::class)
        ->and($result->submission_id)->toBe($submission->public_id)
        ->and($result->passed)->toBeFalse()
        ->and($result->photos)->toBe(['data:image/png;base64,iVBORw0KGgo='])
        ->and($result->meta['note'])->toBe('squeal')
        ->and($result->createdBy->name)->toBe('Dana Driver');

    $orphan = new InspectionItemResult();
    expect($orphan->submission_id)->toBeNull();
});

test('inspection link is usable only while active, unexpired and unused', function () {
    fleetOpsInspectionModelDatabase();
    Carbon::setTestNow('2026-09-09 12:00:00');

    $token = InspectionLink::generateToken();
    expect(strlen($token))->toBe(64)
        ->and(InspectionLink::hashToken($token))->toBe(hash('sha256', $token))
        ->and(InspectionLink::generateToken())->not->toBe($token);

    $form = fleetOpsInspectionModelForm();
    $make = fn (array $attributes = []) => InspectionLink::create(array_merge([
        'company_uuid'         => 'company-insp',
        'inspection_form_uuid' => $form->uuid,
        'driver_uuid'          => 'driver-1',
        'vehicle_uuid'         => 'vehicle-1',
        'created_by_uuid'      => 'user-admin',
        'token_hash'           => InspectionLink::hashToken(InspectionLink::generateToken()),
        'status'               => 'active',
        'single_use'           => true,
    ], $attributes));

    $link = $make();
    expect($link->isUsable())->toBeTrue()
        ->and($link->public_id)->toStartWith('inspection_link_')
        ->and($link->form())->toBeInstanceOf(BelongsTo::class)
        ->and($link->driver())->toBeInstanceOf(BelongsTo::class)
        ->and($link->vehicle())->toBeInstanceOf(BelongsTo::class)
        ->and($link->createdBy())->toBeInstanceOf(BelongsTo::class);

    $loaded = $link->fresh();
    expect($loaded->form->name)->toBe('Pre-trip DVIR')
        ->and($loaded->driver->uuid)->toBe('driver-1')
        ->and($loaded->vehicle->name)->toBe('Truck 7')
        ->and($loaded->createdBy->name)->toBe('Avery Admin');

    expect($make(['status' => 'revoked'])->isUsable())->toBeFalse()
        ->and($make(['expires_at' => '2026-09-09 11:59:59'])->isUsable())->toBeFalse()
        ->and($make(['expires_at' => '2026-09-09 12:00:01'])->isUsable())->toBeTrue()
        ->and($make(['used_at' => '2026-09-09 11:00:00'])->isUsable())->toBeFalse()
        // A reusable link stays open after it has been used.
        ->and($make(['single_use' => false, 'used_at' => '2026-09-09 11:00:00'])->isUsable())->toBeTrue();

    $link->markViewed();
    expect($link->fresh()->last_viewed_at->toDateTimeString())->toBe('2026-09-09 12:00:00');

    $link->markUsed('203.0.113.9', 'NavigatorApp/3.0');
    $used = $link->fresh();
    expect($used->used_at->toDateTimeString())->toBe('2026-09-09 12:00:00')
        ->and($used->used_ip)->toBe('203.0.113.9')
        ->and($used->used_user_agent)->toBe('NavigatorApp/3.0')
        ->and($used->isUsable())->toBeFalse();
});

test('inspection submitter records a submission and the follow-up the form asks for', function () {
    fleetOpsInspectionModelDatabase();
    Carbon::setTestNow('2026-09-09 07:00:00');

    $rules = InspectionSubmitter::rules();
    expect($rules['item_results'])->toBe('required|array|min:1')
        ->and($rules['item_results.*.passed'])->toBe('required|boolean')
        ->and($rules['item_results.*.photos.*'][1])->toBeInstanceOf(Base64OrUrl::class);

    $form = fleetOpsInspectionModelForm();

    $submission = InspectionSubmitter::submit($form, [
        'odometer'     => 120400,
        'engine_hours' => 3100,
        'location'     => ['latitude' => 1.35, 'longitude' => 103.82],
        'signature'    => ['data' => 'sig'],
        'attachments'  => ['file_one'],
        'item_results' => [
            ['item_key' => 'brakes', 'label' => 'Brakes', 'category' => 'Safety', 'passed' => false, 'severity' => 'critical', 'comments' => 'Soft pedal', 'photos' => ['https://cdn.example.com/brakes.jpg']],
            // No explicit status: derived from `passed`.
            ['item_key' => 'lights', 'label' => 'Lights', 'passed' => true],
        ],
    ], [
        'driver_uuid'       => 'driver-1',
        'vehicle_uuid'      => 'vehicle-1',
        'submitted_by_uuid' => 'user-driver',
        'source'            => 'navigator',
        'started_at'        => Carbon::parse('2026-09-09 06:45:00'),
        'meta'              => ['idempotency_key' => 'abc'],
    ]);

    $submission = $submission->fresh(['itemResults', 'issue', 'workOrder']);

    expect($submission->status)->toBe('submitted')
        ->and($submission->source)->toBe('navigator')
        ->and($submission->type)->toBe('dvir')
        ->and($submission->odometer)->toBe(120400)
        ->and($submission->engine_hours)->toBe(3100)
        ->and($submission->started_at->toDateTimeString())->toBe('2026-09-09 06:45:00')
        ->and($submission->submitted_at->toDateTimeString())->toBe('2026-09-09 07:00:00')
        ->and($submission->location)->toBe(['latitude' => 1.35, 'longitude' => 103.82])
        ->and($submission->attachments)->toBe(['file_one'])
        ->and($submission->meta['idempotency_key'])->toBe('abc')
        ->and($submission->total_items)->toBe(2)
        ->and($submission->failed_items)->toBe(1)
        ->and($submission->result)->toBe('failed')
        ->and($submission->itemResults->pluck('status', 'item_key')->all())->toBe(['brakes' => 'failed', 'lights' => 'passed'])
        ->and($submission->itemResults->firstWhere('item_key', 'brakes')->photos)->toBe(['https://cdn.example.com/brakes.jpg'])
        ->and($submission->issue)->toBeInstanceOf(Issue::class)
        ->and($submission->workOrder)->toBeInstanceOf(WorkOrder::class)
        ->and($submission->workOrder->meta['issue_uuid'])->toBe($submission->issue->uuid);

    // A form that asks for nothing gets nothing, and a form with no type is a DVIR.
    $quiet = fleetOpsInspectionModelForm(['settings' => [], 'type' => null]);
    $clean = InspectionSubmitter::submit($quiet, [
        'item_results' => [['label' => 'Brakes', 'passed' => false, 'status' => 'failed']],
    ]);

    expect($clean->fresh()->type)->toBe('dvir')
        ->and($clean->fresh()->started_at->toDateTimeString())->toBe('2026-09-09 07:00:00')
        ->and($clean->fresh()->issue_uuid)->toBeNull()
        ->and($clean->fresh()->work_order_uuid)->toBeNull()
        ->and(Issue::query()->count())->toBe(1)
        ->and(WorkOrder::query()->count())->toBe(1);

    // Issue only, and no failures to raise it from.
    $issueOnly = fleetOpsInspectionModelForm(['settings' => ['create_issue_on_failure' => true]]);
    $passed    = InspectionSubmitter::submit($issueOnly, ['item_results' => [['label' => 'Brakes', 'passed' => true]]]);
    expect($passed->fresh()->result)->toBe('passed')
        ->and(Issue::query()->count())->toBe(1);
});

test('base64 or url rule accepts what the driver app sends for a photo', function () {
    $rule = new Base64OrUrl();

    expect($rule->passes('photos.0', 'https://cdn.example.com/photo.jpg'))->toBeTrue()
        ->and($rule->passes('photos.0', 'iVBORw0KGgoAAAANSUhEUgAAAAgAAAAI'))->toBeTrue()
        ->and($rule->passes('photos.0', "iVBORw0KGgo\nAAAANSUhEUg=="))->toBeTrue()
        ->and($rule->passes('photos.0', 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUg=='))->toBeTrue()
        ->and($rule->passes('photos.0', 'data:image/svg+xml;base64,PHN2Zz48L3N2Zz4='))->toBeTrue()
        ->and($rule->passes('photos.0', ''))->toBeFalse()
        ->and($rule->passes('photos.0', '   '))->toBeFalse()
        ->and($rule->passes('photos.0', ['not', 'a', 'string']))->toBeFalse()
        ->and($rule->passes('photos.0', 42))->toBeFalse()
        ->and($rule->passes('photos.0', 'not base64!'))->toBeFalse()
        ->and($rule->passes('photos.0', 'data:image/png;base64,'))->toBeFalse()
        ->and($rule->passes('photos.0', 'data:image/png;base64,***'))->toBeFalse()
        ->and($rule->message())->toContain(':attribute');
});
