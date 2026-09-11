<?php

use Fleetbase\FleetOps\Http\Controllers\Api\v1\InspectionController;
use Fleetbase\FleetOps\Http\Controllers\Internal\v1\InspectionFormController;
use Fleetbase\FleetOps\Http\Controllers\Internal\v1\InspectionSubmissionController;
use Fleetbase\FleetOps\Http\Controllers\Public\PublicInspectionController;
use Fleetbase\FleetOps\Http\Resources\v1\InspectionForm as InspectionFormResource;
use Fleetbase\FleetOps\Models\InspectionForm;
use Fleetbase\FleetOps\Models\InspectionItemResult;
use Fleetbase\FleetOps\Models\InspectionLink;
use Fleetbase\FleetOps\Models\InspectionSubmission;
use Fleetbase\FleetOps\Models\Issue;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\FleetOps\Models\WorkOrder;
use Illuminate\Config\Repository;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

if (!function_exists('Fleetbase\\Support\\auth')) {
    eval('namespace Fleetbase\\Support; function auth() { return new class { public function user() { return null; } public function id() { return null; } }; }');
}

/*
 * `abort()` is a foundation helper, absent locally. The public controller
 * aborts with a ready-made JSON response, which Laravel raises as an
 * HttpResponseException; the stub does the same so a refusal can be read
 * back as the response it carries.
 */
if (!function_exists('Fleetbase\\FleetOps\\Http\\Controllers\\Public\\abort')) {
    eval('namespace Fleetbase\\FleetOps\\Http\\Controllers\\Public; function abort($code, $message = "", array $headers = []) { if ($code instanceof \\Symfony\\Component\\HttpFoundation\\Response) { throw new \\Illuminate\\Http\\Exceptions\\HttpResponseException($code); } throw new \\Symfony\\Component\\HttpKernel\\Exception\\HttpException($code, $message, null, $headers); }');
}

/**
 * The same in-memory database the model contracts use. Every column is a
 * nullable string; the controllers are what is under test here.
 */
function fleetOpsInspectionControllerDatabase(): SQLiteConnection
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
        'inspection_forms'        => ['uuid', 'public_id', '_key', 'company_uuid', 'name', 'description', 'type', 'status', 'subject_type', 'subject_uuid', 'items', 'settings', 'meta', 'published_at', 'created_by_uuid', 'updated_by_uuid'],
        'inspection_links'        => ['uuid', 'public_id', '_key', 'company_uuid', 'inspection_form_uuid', 'driver_uuid', 'vehicle_uuid', 'assignee_uuid', 'created_by_uuid', 'token_hash', 'token', 'pin_hash', 'pin', 'pin_attempts', 'pin_sent_via', 'pin_sent_at', 'status', 'single_use', 'expires_at', 'last_viewed_at', 'used_at', 'used_ip', 'used_user_agent', 'meta'],
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
        'files'                   => ['uuid', 'public_id', '_key', 'company_uuid', 'uploader_uuid', 'subject_uuid', 'subject_type', 'path', 'disk', 'bucket', 'folder', 'etag', 'meta', 'original_filename', 'type', 'content_type', 'file_size', 'slug', 'caption'],
        'vendors'                 => ['uuid', 'public_id', '_key', 'company_uuid', 'name'],
        'orders'                  => ['uuid', 'public_id', '_key', 'company_uuid', 'driver_assigned_uuid', 'status'],
        'positions'               => ['uuid', 'public_id', '_key', 'company_uuid', 'subject_uuid', 'subject_type', 'coordinates'],
        'maintenances'            => ['uuid', 'public_id', '_key', 'company_uuid', 'maintainable_type', 'maintainable_uuid', 'status', 'completed_at'],
        'maintenance_schedules'   => ['uuid', 'public_id', '_key', 'company_uuid', 'subject_type', 'subject_uuid', 'status', 'next_due_at'],
        'custom_field_values'     => ['uuid', 'company_uuid', 'custom_field_uuid', 'subject_uuid', 'subject_type', 'value', 'value_type'],
        'custom_fields'           => ['uuid', 'company_uuid', 'category_uuid', 'subject_uuid', 'subject_type', 'name', 'label', 'type', 'for', 'component', 'options', 'required', 'editable', 'default_value', 'validation_rules', 'meta', 'description', 'help_text', 'order'],
        'categories'              => ['uuid', 'public_id', '_key', 'company_uuid', 'owner_uuid', 'owner_type', 'parent_uuid', 'icon_file_uuid', 'internal_id', 'name', 'description', 'translations', 'meta', 'tags', 'icon', 'icon_color', 'slug', 'order', 'for', 'core_category'],
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
    $connection->table('companies')->insert(['uuid' => 'company-other', 'public_id' => 'company_other', 'name' => 'Someone Else']);
    $connection->table('users')->insert(['uuid' => 'user-driver', 'public_id' => 'user_driver', 'company_uuid' => 'company-insp', 'name' => 'Dana Driver', 'email' => 'dana@example.com', 'phone' => '+15550001111', 'type' => 'user']);
    $connection->table('users')->insert(['uuid' => 'user-admin', 'public_id' => 'user_admin', 'company_uuid' => 'company-insp', 'name' => 'Avery Admin', 'email' => 'avery@example.com', 'type' => 'user']);
    $connection->table('vehicles')->insert(['uuid' => 'vehicle-1', 'public_id' => 'vehicle_one', 'company_uuid' => 'company-insp', 'name' => 'Truck 7', 'plate_number' => 'TRK-7', 'currency' => 'SGD']);
    $connection->table('vehicles')->insert(['uuid' => 'vehicle-2', 'public_id' => 'vehicle_two', 'company_uuid' => 'company-insp', 'name' => 'Van 2', 'plate_number' => 'VAN-2']);
    $connection->table('vehicles')->insert(['uuid' => 'vehicle-other', 'public_id' => 'vehicle_other', 'company_uuid' => 'company-other', 'name' => 'Not Ours']);
    $connection->table('drivers')->insert(['uuid' => 'driver-1', 'public_id' => 'driver_one', 'company_uuid' => 'company-insp', 'user_uuid' => 'user-driver', 'vehicle_uuid' => 'vehicle-1', 'status' => 'active']);
    $connection->table('drivers')->insert(['uuid' => 'driver-other', 'public_id' => 'driver_other', 'company_uuid' => 'company-other', 'user_uuid' => null, 'status' => 'active']);

    session(['company' => 'company-insp', 'user' => 'user-admin']);

    return $connection;
}

function fleetOpsInspectionControllerForm(array $attributes = []): InspectionForm
{
    return InspectionForm::create(array_merge([
        'company_uuid' => 'company-insp',
        'name'         => 'Pre-trip DVIR',
        'type'         => 'dvir',
        'status'       => 'published',
        'published_at' => '2026-09-01 08:00:00',
        'items'        => [
            ['key' => 'brakes', 'label' => 'Brakes', 'category' => 'Safety', 'severity' => 'critical'],
            ['key' => 'lights', 'label' => 'Lights', 'category' => 'Safety', 'severity' => 'medium'],
        ],
        'settings' => ['create_issue_on_failure' => true, 'create_work_order_on_failure' => true],
    ], $attributes));
}

function fleetOpsInspectionControllerLink(InspectionForm $form, string $token, array $attributes = []): InspectionLink
{
    return InspectionLink::create(array_merge([
        'company_uuid'         => 'company-insp',
        'inspection_form_uuid' => $form->uuid,
        'driver_uuid'          => 'driver-1',
        'vehicle_uuid'         => 'vehicle-1',
        'created_by_uuid'      => 'user-admin',
        'token_hash'           => InspectionLink::hashToken($token),
        'status'               => 'active',
        'single_use'           => true,
    ], $attributes));
}

function fleetOpsInspectionControllerBody(array $overrides = []): array
{
    return array_merge([
        'odometer'     => 120400,
        'item_results' => [
            ['item_key' => 'brakes', 'label' => 'Brakes', 'passed' => false, 'severity' => 'critical', 'comments' => 'Soft pedal', 'photos' => ['iVBORw0KGgoAAAANSUhEUg==']],
            ['item_key' => 'lights', 'label' => 'Lights', 'passed' => true],
        ],
    ], $overrides);
}

/** Runs a public-link call and hands back the refusal it aborted with, if any. */
function fleetOpsInspectionControllerRefusal(callable $call): ?JsonResponse
{
    try {
        $call();
    } catch (HttpResponseException $exception) {
        return $exception->getResponse();
    }

    return null;
}

afterEach(function () {
    Carbon::setTestNow();
});

test('public inspection link refuses a missing, unknown, unpublished, invalid or spent token', function () {
    fleetOpsInspectionControllerDatabase();
    $controller = new PublicInspectionController();
    $form       = fleetOpsInspectionControllerForm();
    fleetOpsInspectionControllerLink($form, 'good-token');
    fleetOpsInspectionControllerLink($form, 'spent-token', ['used_at' => '2026-09-01 09:00:00']);

    $refusal = fn (array $query, ?string $id = null) => fleetOpsInspectionControllerRefusal(
        fn () => $controller->show(Request::create('/public/inspections/forms/x', 'GET', $query), $id ?? $form->public_id)
    );

    expect($refusal([])->getStatusCode())->toBe(403)
        ->and($refusal([])->getData(true))->toBe(['error' => 'Inspection token is required.'])
        ->and($refusal(['token' => 'nope'])->getData(true))->toBe(['error' => 'Inspection link is invalid.'])
        ->and($refusal(['token' => 'spent-token'])->getData(true))->toBe(['error' => 'Inspection link is expired, revoked, or already used.']);

    $draft = fleetOpsInspectionControllerForm(['status' => 'draft', 'published_at' => null]);
    expect($refusal(['token' => 'good-token'], $draft->public_id)->getData(true))->toBe(['error' => 'This inspection form is not available.']);

    expect(fn () => $controller->show(Request::create('/public/inspections/forms/x', 'GET', ['token' => 'good-token']), 'inspection_form_missing'))
        ->toThrow(ModelNotFoundException::class);
});

test('public inspection link shows the form with the identity it was minted for', function () {
    fleetOpsInspectionControllerDatabase();
    Carbon::setTestNow('2026-09-09 08:00:00');
    $controller = new PublicInspectionController();
    $form       = fleetOpsInspectionControllerForm();
    $link       = fleetOpsInspectionControllerLink($form, 'good-token', ['expires_at' => '2026-09-10 08:00:00']);

    $payload = $controller->show(Request::create('/public/inspections/forms/x', 'GET', ['token' => 'good-token']), $form->uuid)->getData(true);

    expect($payload['form']['id'])->toBe($form->public_id)
        ->and($payload['form']['items'])->toHaveCount(2)
        ->and($payload['form']['is_published'])->toBeTrue()
        ->and($payload['identity']['driver'])->toBe(['id' => 'driver_one', 'name' => 'Dana Driver'])
        ->and($payload['identity']['vehicle'])->toBe(['id' => 'vehicle_one', 'name' => 'Truck 7', 'plate_number' => 'TRK-7'])
        ->and($payload['identity']['expires_at'])->toStartWith('2026-09-10')
        ->and($link->fresh()->last_viewed_at->toDateTimeString())->toBe('2026-09-09 08:00:00');

    // A link minted for nobody in particular carries no identity.
    fleetOpsInspectionControllerLink($form, 'anon-token', ['driver_uuid' => null, 'vehicle_uuid' => null]);
    $anonymous = $controller->show(Request::create('/public/inspections/forms/x', 'GET', ['token' => 'anon-token']), $form->public_id)->getData(true);
    expect($anonymous['identity'])->toBe(['assignee' => null, 'driver' => null, 'vehicle' => null, 'expires_at' => null]);
});

test('public inspection link files a submission and spends the link', function () {
    fleetOpsInspectionControllerDatabase();
    Carbon::setTestNow('2026-09-09 08:30:00');
    $controller = new PublicInspectionController();
    $form       = fleetOpsInspectionControllerForm();
    $link       = fleetOpsInspectionControllerLink($form, 'good-token');

    $request = Request::create('/public/inspections/forms/x/submit', 'POST', fleetOpsInspectionControllerBody(['token' => 'good-token']), [], [], ['REMOTE_ADDR' => '203.0.113.9', 'HTTP_USER_AGENT' => 'Safari']);
    $payload = $controller->submit($request, $form->public_id)->getData(true);

    expect($payload['message'])->toBe('Inspection submitted.')
        ->and($payload['submission']['source'])->toBe('public_link')
        ->and($payload['submission']['status'])->toBe('submitted')
        ->and($payload['submission']['result'])->toBe('failed')
        ->and($payload['submission']['failed_items'])->toBe(1)
        ->and($payload['submission']['meta']['inspection_link_id'])->toBe($link->public_id)
        ->and($payload['submission']['item_results'])->toHaveCount(2)
        ->and($payload['submission']['issue']['id'])->toStartWith('issue_')
        ->and($payload['submission']['work_order']['id'])->toStartWith('work_order_')
        ->and($payload['submission']['driver']['id'])->toBe('driver_one')
        ->and($payload['submission']['vehicle']['id'])->toBe('vehicle_one')
        ->and($payload['submission']['form']['id'])->toBe($form->public_id);

    $submission = InspectionSubmission::query()->first();
    expect($submission->submitted_by_uuid)->toBe('user-driver')
        ->and($submission->driver_uuid)->toBe('driver-1')
        ->and($submission->vehicle_uuid)->toBe('vehicle-1');

    $spent = $link->fresh();
    expect($spent->used_at->toDateTimeString())->toBe('2026-09-09 08:30:00')
        ->and($spent->used_ip)->toBe('203.0.113.9')
        ->and($spent->used_user_agent)->toBe('Safari')
        ->and($spent->isUsable())->toBeFalse();

    // Spent is spent: the same token cannot file twice.
    expect(fleetOpsInspectionControllerRefusal(fn () => $controller->submit($request, $form->public_id))->getStatusCode())->toBe(403);
});

test('internal inspection form controller publishes archives and mints links', function () {
    fleetOpsInspectionControllerDatabase();
    Carbon::setTestNow('2026-09-09 09:00:00');
    $controller = new InspectionFormController();
    $form       = fleetOpsInspectionControllerForm(['status' => 'draft', 'published_at' => null]);

    // A draft cannot be handed out.
    $refused = $controller->generateLink(Request::create('/', 'POST'), $form->public_id);
    expect($refused->getStatusCode())->toBe(422)
        ->and($refused->getData(true)['error'])->toContain('must be published');

    $published = $controller->publish($form->public_id)->getData(true);
    expect($published['status'])->toBe('ok')
        ->and($published['data']['status'])->toBe('published')
        ->and($published['data']['is_published'])->toBeTrue();

    $minted = $controller->generateLink(Request::create('/', 'POST', ['driver' => 'driver_one', 'vehicle' => 'vehicle-1', 'expires_at' => '2026-09-10 09:00:00', 'single_use' => false]), $form->uuid)->getData(true);
    $link   = InspectionLink::query()->first();

    expect($minted['status'])->toBe('ok')
        ->and($minted['link']['id'])->toBe($link->public_id)
        ->and($minted['link']['path'])->toBe('/~/inspection?id=' . urlencode($form->public_id) . '&token=' . urlencode($minted['link']['token']))
        ->and(strlen($minted['link']['token']))->toBe(64)
        ->and($link->token_hash)->toBe(InspectionLink::hashToken($minted['link']['token']))
        ->and($link->driver_uuid)->toBe('driver-1')
        ->and($link->vehicle_uuid)->toBe('vehicle-1')
        ->and($link->created_by_uuid)->toBe('user-admin')
        ->and($link->single_use)->toBeFalse()
        ->and($minted['link']['driver'])->toBe(['id' => 'driver_one', 'name' => 'Dana Driver'])
        ->and($minted['link']['vehicle'])->toBe(['id' => 'vehicle_one', 'name' => 'Truck 7'])
        ->and($minted['link']['expires_at'])->toStartWith('2026-09-10');

    // Nobody named: a link anyone may use, single use by default.
    $open = $controller->generateLink(Request::create('/', 'POST'), $form->public_id)->getData(true);
    expect($open['link']['driver'])->toBeNull()
        ->and($open['link']['vehicle'])->toBeNull()
        ->and((bool) InspectionLink::query()->where('public_id', $open['link']['id'])->value('single_use'))->toBeTrue();

    // A driver or vehicle from another company does not exist here.
    expect(fn () => $controller->generateLink(Request::create('/', 'POST', ['driver' => 'driver_other']), $form->public_id))->toThrow(ModelNotFoundException::class)
        ->and(fn () => $controller->generateLink(Request::create('/', 'POST', ['vehicle' => 'vehicle_other']), $form->public_id))->toThrow(ModelNotFoundException::class);

    $archived = $controller->archive($form->public_id)->getData(true);
    expect($archived['message'])->toBe('Inspection form archived.')
        ->and($archived['data']['status'])->toBe('archived');

    session(['company' => 'company-other']);
    expect(fn () => $controller->publish($form->public_id))->toThrow(ModelNotFoundException::class);
});

test('internal inspection submission controller syncs item results from the console form', function () {
    fleetOpsInspectionControllerDatabase();
    $controller = new InspectionSubmissionController();
    $form       = fleetOpsInspectionControllerForm();
    $submission = InspectionSubmission::create(['company_uuid' => 'company-insp', 'inspection_form_uuid' => $form->uuid, 'vehicle_uuid' => 'vehicle-1', 'driver_uuid' => 'driver-1', 'type' => 'dvir', 'status' => 'draft']);

    // Nothing sent: nothing touched.
    $controller->onAfterCreate(Request::create('/', 'POST'), $submission, []);
    expect(InspectionItemResult::query()->count())->toBe(0);

    // The console nests the payload under the resource name.
    $controller->onAfterCreate(Request::create('/', 'POST', ['inspection_submission' => ['item_results' => [
        ['item_key' => 'brakes', 'label' => 'Brakes', 'passed' => false, 'severity' => 'critical'],
        ['title' => 'Untitled item', 'status' => 'failed'],
        ['label' => 'Horn'],
    ]]]), $submission, []);

    $results = $submission->itemResults()->orderBy('id')->get();
    expect($results)->toHaveCount(3)
        ->and($results[0]->status)->toBe('failed')
        ->and($results[1]->label)->toBe('Untitled item')
        ->and($results[1]->passed)->toBeFalse()
        // No status and no flag: passed, as an unchecked box is.
        ->and($results[2]->status)->toBe('passed')
        ->and($results[2]->passed)->toBeTrue()
        ->and($submission->fresh()->failed_items)->toBe(2)
        ->and($submission->relationLoaded('itemResults'))->toBeTrue();

    // An update matches by uuid, then item key, then label — and drops what
    // the console no longer lists.
    $controller->onAfterUpdate(Request::create('/', 'PUT', ['item_results' => [
        ['uuid' => $results[0]->uuid, 'item_key' => 'brakes', 'label' => 'Brakes', 'passed' => true],
        ['label' => 'Horn', 'passed' => false, 'status' => 'failed', 'meta' => ['note' => 'weak']],
    ]]), $submission->fresh(), []);

    $synced = $submission->fresh();
    expect($synced->itemResults()->count())->toBe(2)
        ->and((bool) $synced->itemResults()->where('uuid', $results[0]->uuid)->value('passed'))->toBeTrue()
        ->and($synced->itemResults()->where('label', 'Untitled item')->exists())->toBeFalse()
        ->and($synced->itemResults()->where('label', 'Horn')->first()->meta['note'])->toBe('weak')
        ->and($synced->failed_items)->toBe(1);

    $builder = InspectionSubmission::query();
    $controller->onFindRecord($builder, Request::create('/'));
    expect(array_keys($builder->getEagerLoads()))->toContain('form', 'vehicle', 'driver', 'submittedBy', 'issue', 'workOrder', 'itemResults');
});

test('internal inspection submission controller submits, raises follow-up and resolves', function () {
    fleetOpsInspectionControllerDatabase();
    Carbon::setTestNow('2026-09-09 10:00:00');
    $controller = new InspectionSubmissionController();
    $form       = fleetOpsInspectionControllerForm();

    $clean = InspectionSubmission::create(['company_uuid' => 'company-insp', 'inspection_form_uuid' => $form->uuid, 'vehicle_uuid' => 'vehicle-1', 'driver_uuid' => 'driver-1', 'type' => 'dvir', 'status' => 'draft']);
    InspectionItemResult::create(['company_uuid' => 'company-insp', 'inspection_submission_uuid' => $clean->uuid, 'label' => 'Brakes', 'passed' => true]);

    $submitted = $controller->submit($clean->public_id)->getData(true);
    expect($submitted['message'])->toBe('Inspection submitted.')
        ->and($submitted['data']['status'])->toBe('submitted')
        ->and($submitted['data']['result'])->toBe('passed');

    // Nothing failed, nothing to raise.
    $noIssue = $controller->createIssue($clean->uuid)->getData(true);
    expect($noIssue['message'])->toBe('No failed inspection items found.')
        ->and($noIssue['issue'])->toBeNull();
    $noOrder = $controller->createWorkOrder($clean->uuid)->getData(true);
    expect($noOrder['message'])->toBe('No failed inspection items found.')
        ->and($noOrder['work_order'])->toBeNull();

    $failed = InspectionSubmission::create(['company_uuid' => 'company-insp', 'inspection_form_uuid' => $form->uuid, 'vehicle_uuid' => 'vehicle-1', 'driver_uuid' => 'driver-1', 'submitted_by_uuid' => 'user-driver', 'type' => 'dvir', 'status' => 'draft']);
    InspectionItemResult::create(['company_uuid' => 'company-insp', 'inspection_submission_uuid' => $failed->uuid, 'label' => 'Brakes', 'passed' => false, 'severity' => 'high']);

    $issued = $controller->createIssue($failed->public_id)->getData(true);
    expect($issued['message'])->toBe('Issue created from failed inspection items.')
        ->and($issued['issue']['title'])->toBe('Failed inspection: Truck 7')
        ->and($issued['data']['issue']['uuid'])->toBe($issued['issue']['uuid']);

    $ordered = $controller->createWorkOrder($failed->public_id)->getData(true);
    expect($ordered['message'])->toBe('Work order created from failed inspection items.')
        ->and($ordered['work_order']['subject'])->toBe('Inspection repair: Truck 7')
        ->and($ordered['work_order']['meta']['issue_uuid'])->toBe($issued['issue']['uuid'])
        ->and(Issue::query()->count())->toBe(1)
        ->and(WorkOrder::query()->count())->toBe(1);

    $resolved = $controller->resolve($failed->public_id)->getData(true);
    expect($resolved['message'])->toBe('Inspection resolved.')
        ->and($resolved['data']['status'])->toBe('resolved')
        ->and($failed->fresh()->resolved_at->toDateTimeString())->toBe('2026-09-09 10:00:00');

    expect(fn () => $controller->submit('inspection_submission_missing'))->toThrow(ModelNotFoundException::class);
});

test('driver inspection api lists only the published forms a vehicle can be inspected against', function () {
    fleetOpsInspectionControllerDatabase();
    $controller = new InspectionController();

    $wide  = fleetOpsInspectionControllerForm(['name' => 'Fleet-wide pre-trip', 'type' => 'pre_trip', 'published_at' => '2026-09-01 08:00:00']);
    $truck = fleetOpsInspectionControllerForm(['name' => 'Truck 7 lift gate', 'subject_type' => Vehicle::class, 'subject_uuid' => 'vehicle-1', 'published_at' => '2026-09-03 08:00:00']);
    fleetOpsInspectionControllerForm(['name' => 'Van 2 only', 'subject_type' => Vehicle::class, 'subject_uuid' => 'vehicle-2', 'published_at' => '2026-09-02 08:00:00']);
    fleetOpsInspectionControllerForm(['name' => 'Still a draft', 'status' => 'draft', 'published_at' => null]);
    fleetOpsInspectionControllerForm(['name' => 'Says published, never was', 'status' => 'published', 'published_at' => null]);
    fleetOpsInspectionControllerForm(['name' => 'Retired', 'status' => 'archived']);
    fleetOpsInspectionControllerForm(['name' => 'Another company', 'company_uuid' => 'company-other']);

    $all = $controller->queryForms(Request::create('/v1/inspection-forms', 'GET'))->resolve();
    expect(array_column($all, 'name'))->toBe(['Truck 7 lift gate', 'Van 2 only', 'Fleet-wide pre-trip'])
        ->and($all[0]['id'])->toBe($truck->public_id)
        ->and($all[0])->not->toHaveKey('uuid');

    $forTruck = $controller->queryForms(Request::create('/v1/inspection-forms', 'GET', ['vehicle' => 'vehicle_one']))->resolve();
    expect(array_column($forTruck, 'name'))->toBe(['Truck 7 lift gate', 'Fleet-wide pre-trip']);

    $preTrip = $controller->queryForms(Request::create('/v1/inspection-forms', 'GET', ['type' => 'pre_trip,post_trip', 'limit' => 1]))->resolve();
    expect(array_column($preTrip, 'id'))->toBe([$wide->public_id]);

    $capped = $controller->queryForms(Request::create('/v1/inspection-forms', 'GET', ['limit' => 2]))->resolve();
    expect($capped)->toHaveCount(2);

    expect($controller->queryForms(Request::create('/v1/inspection-forms', 'GET', ['vehicle' => 'vehicle_other']))->getStatusCode())->toBe(404);
});

test('driver inspection api shows a published form with its items and settings', function () {
    fleetOpsInspectionControllerDatabase();
    $controller = new InspectionController();

    $form  = fleetOpsInspectionControllerForm(['subject_type' => Vehicle::class, 'subject_uuid' => 'vehicle-1', 'meta' => ['sla' => 'am']]);
    $draft = fleetOpsInspectionControllerForm(['status' => 'draft', 'published_at' => null]);
    $other = fleetOpsInspectionControllerForm(['company_uuid' => 'company-other']);

    $shown = $controller->findForm($form->public_id)->resolve();
    expect($shown['id'])->toBe($form->public_id)
        ->and($shown['name'])->toBe('Pre-trip DVIR')
        ->and($shown['type'])->toBe('dvir')
        ->and($shown['items'])->toHaveCount(2)
        ->and($shown['item_count'])->toBe(2)
        ->and($shown['settings'])->toBe(['create_issue_on_failure' => true, 'create_work_order_on_failure' => true])
        ->and($shown['meta'])->toBe(['sla' => 'am'])
        ->and($shown['is_published'])->toBeTrue()
        ->and($shown['subject_name'])->toBe('Truck 7')
        ->and($shown['subject']['id'])->toBe('vehicle_one')
        ->and($shown['subject']['type'])->toBe('maintenance-subject-vehicle')
        ->and($shown['subject']['subject_type'])->toBe('maintenance-subject-vehicle');

    // By uuid as well as public id.
    expect($controller->findForm($form->uuid)->resolve()['id'])->toBe($form->public_id);

    // A form bound to nothing has no subject to describe.
    $wide = fleetOpsInspectionControllerForm();
    expect($controller->findForm($wide->public_id)->resolve()['subject'])->toBeNull();

    // Draft, other company, or nothing at all: all the same 404.
    expect($controller->findForm($draft->public_id)->getStatusCode())->toBe(404)
        ->and($controller->findForm($other->public_id)->getStatusCode())->toBe(404)
        ->and($controller->findForm('inspection_form_missing')->getStatusCode())->toBe(404)
        ->and($controller->findForm('inspection_form_missing')->getData(true))->toBe(['error' => 'Inspection form resource not found.']);
});

test('driver inspection api files a submission and answers with its follow-up', function () {
    fleetOpsInspectionControllerDatabase();
    Carbon::setTestNow('2026-09-09 07:30:00');
    $controller = new InspectionController();
    $form       = fleetOpsInspectionControllerForm();

    $body = fleetOpsInspectionControllerBody([
        'inspection_form' => $form->public_id,
        'driver'          => 'driver_one',
        'vehicle'         => 'vehicle_two',
        'started_at'      => '2026-09-09T07:10:00Z',
        'location'        => ['latitude' => 1.35, 'longitude' => 103.82],
    ]);

    $filed = $controller->submit(Request::create('/v1/inspections', 'POST', $body))->resolve();

    expect($filed['id'])->toStartWith('inspection_submission_')
        ->and($filed)->not->toHaveKey('uuid')
        ->and($filed['source'])->toBe('navigator')
        ->and($filed['status'])->toBe('submitted')
        ->and($filed['result'])->toBe('failed')
        ->and($filed['type'])->toBe('dvir')
        ->and($filed['odometer'])->toBe(120400)
        ->and($filed['total_items'])->toBe(2)
        ->and($filed['failed_items'])->toBe(1)
        ->and($filed['has_failures'])->toBeTrue()
        ->and($filed['location'])->toBe(['latitude' => 1.35, 'longitude' => 103.82])
        ->and($filed['started_at']->toDateTimeString())->toBe('2026-09-09 07:10:00')
        ->and($filed['submitted_at']->toDateTimeString())->toBe('2026-09-09 07:30:00')
        ->and($filed['form']->resolve()['id'])->toBe($form->public_id)
        ->and($filed['vehicle']->resolve()['id'])->toBe('vehicle_two')
        ->and($filed['driver']->resolve()['id'])->toBe('driver_one')
        ->and($filed['item_results']->resolve())->toHaveCount(2)
        ->and($filed['item_results']->resolve()[0])->toMatchArray(['item_key' => 'brakes', 'passed' => false, 'status' => 'failed', 'comments' => 'Soft pedal', 'photos' => ['iVBORw0KGgoAAAANSUhEUg==']])
        ->and($filed['item_results']->resolve()[0]['submission_id'])->toBe($filed['id'])
        ->and($filed['issue']->resolve()['id'])->toStartWith('issue_')
        ->and($filed['work_order']->resolve()['id'])->toStartWith('work_order_');

    $submission = InspectionSubmission::query()->first();
    expect($submission->submitted_by_uuid)->toBe('user-driver')
        ->and($submission->driver_uuid)->toBe('driver-1')
        ->and($submission->vehicle_uuid)->toBe('vehicle-2')
        ->and($submission->meta)->toBe([]);

    // No vehicle named: the driver's own truck, and no start time: now.
    $assigned = $controller->submit(Request::create('/v1/inspections', 'POST', fleetOpsInspectionControllerBody([
        'inspection_form' => $form->uuid,
        'driver'          => 'driver-1',
        'item_results'    => [['label' => 'Horn', 'passed' => true]],
    ])))->resolve();
    expect($assigned['vehicle']->resolve()['id'])->toBe('vehicle_one')
        ->and($assigned['result'])->toBe('passed')
        ->and($assigned['started_at']->toDateTimeString())->toBe('2026-09-09 07:30:00')
        ->and($assigned['issue'])->toBeNull()
        ->and($assigned['work_order'])->toBeNull();
});

test('driver inspection api answers a replayed submit with the submission it already filed', function () {
    fleetOpsInspectionControllerDatabase();
    $controller = new InspectionController();
    $form       = fleetOpsInspectionControllerForm();
    $body       = fleetOpsInspectionControllerBody(['inspection_form' => $form->public_id, 'driver' => 'driver_one']);
    $replay     = fn (string $key) => $controller->submit(Request::create('/v1/inspections', 'POST', $body, [], [], ['HTTP_IDEMPOTENCY_KEY' => $key]))->resolve();

    $first = $replay('queued-7f3a');
    expect(InspectionSubmission::query()->count())->toBe(1)
        ->and(InspectionSubmission::query()->first()->meta)->toBe(['idempotency_key' => 'queued-7f3a']);

    // The app lost the response and sent the same queued submit again.
    $second = $replay('queued-7f3a');
    expect($second['id'])->toBe($first['id'])
        ->and($second['item_results']->resolve())->toHaveCount(2)
        ->and(InspectionSubmission::query()->count())->toBe(1)
        ->and(Issue::query()->count())->toBe(1);

    // A different key is a different inspection, and the blank header is no key at all.
    $third = $replay('queued-9c11');
    expect($third['id'])->not->toBe($first['id'])
        ->and(InspectionSubmission::query()->count())->toBe(2);
    $replay('   ');
    expect(InspectionSubmission::query()->count())->toBe(3);
});

test('driver inspection api refuses a submit for a driver, form or vehicle it cannot find', function () {
    fleetOpsInspectionControllerDatabase();
    $controller = new InspectionController();
    $form       = fleetOpsInspectionControllerForm();
    $draft      = fleetOpsInspectionControllerForm(['status' => 'draft', 'published_at' => null]);
    $submit     = fn (array $overrides) => $controller->submit(Request::create('/v1/inspections', 'POST', fleetOpsInspectionControllerBody(array_merge(['inspection_form' => $form->public_id, 'driver' => 'driver_one'], $overrides))));

    expect($submit(['driver' => 'driver_other'])->getData(true))->toBe(['error' => 'Driver resource not found.'])
        ->and($submit(['driver' => 'driver_other'])->getStatusCode())->toBe(404)
        ->and($submit(['inspection_form' => $draft->public_id])->getData(true))->toBe(['error' => 'Inspection form resource not found.'])
        ->and($submit(['vehicle' => 'vehicle_other'])->getData(true))->toBe(['error' => 'Vehicle resource not found.'])
        ->and(InspectionSubmission::query()->count())->toBe(0);
});

test('driver inspection api lists and shows submissions with their filters', function () {
    fleetOpsInspectionControllerDatabase();
    $controller = new InspectionController();
    $form       = fleetOpsInspectionControllerForm(['settings' => []]);
    $postTrip   = fleetOpsInspectionControllerForm(['type' => 'post_trip', 'settings' => []]);

    $file = function (InspectionForm $form, string $vehicle, bool $passed, string $at) use ($controller) {
        Carbon::setTestNow($at);

        return $controller->submit(Request::create('/v1/inspections', 'POST', [
            'inspection_form' => $form->public_id,
            'driver'          => 'driver_one',
            'vehicle'         => $vehicle,
            'item_results'    => [['label' => 'Brakes', 'passed' => $passed]],
        ]))->resolve()['id'];
    };

    $oldest = $file($form, 'vehicle_one', true, '2026-09-07 08:00:00');
    $middle = $file($postTrip, 'vehicle_two', false, '2026-09-08 08:00:00');
    $newest = $file($form, 'vehicle_one', false, '2026-09-09 08:00:00');

    // Someone else's inspection never shows, whatever the filter.
    InspectionSubmission::create(['company_uuid' => 'company-other', 'inspection_form_uuid' => $form->uuid, 'driver_uuid' => 'driver-other', 'type' => 'dvir', 'status' => 'submitted', 'submitted_at' => '2026-09-10 08:00:00']);

    $ids = fn (Request $request) => array_column($controller->query($request)->resolve(), 'id');

    expect($ids(Request::create('/v1/inspections', 'GET')))->toBe([$newest, $middle, $oldest])
        ->and($ids(Request::create('/v1/inspections', 'GET', ['driver' => 'driver_one', 'limit' => 2])))->toBe([$newest, $middle])
        ->and($ids(Request::create('/v1/inspections', 'GET', ['vehicle' => 'vehicle_two'])))->toBe([$middle])
        ->and($ids(Request::create('/v1/inspections', 'GET', ['type' => 'post_trip'])))->toBe([$middle])
        ->and($ids(Request::create('/v1/inspections', 'GET', ['result' => 'passed'])))->toBe([$oldest])
        ->and($ids(Request::create('/v1/inspections', 'GET', ['status' => 'resolved,needs_review'])))->toBe([])
        ->and($ids(Request::create('/v1/inspections', 'GET', ['limit' => 0])))->toBe([$newest, $middle, $oldest]);

    expect($controller->query(Request::create('/v1/inspections', 'GET', ['driver' => 'driver_other']))->getStatusCode())->toBe(404)
        ->and($controller->query(Request::create('/v1/inspections', 'GET', ['vehicle' => 'vehicle_other']))->getStatusCode())->toBe(404);

    $shown = $controller->find($newest)->resolve();
    expect($shown['id'])->toBe($newest)
        ->and($shown['item_results']->resolve())->toHaveCount(1)
        ->and($shown['vehicle']->resolve()['id'])->toBe('vehicle_one')
        ->and($shown['driver']->resolve()['id'])->toBe('driver_one')
        ->and($shown['form']->resolve()['id'])->toBe($form->public_id)
        ->and($shown['issue'])->toBeNull()
        ->and($shown['work_order'])->toBeNull();

    $byUuid = $controller->find(InspectionSubmission::query()->where('public_id', $oldest)->value('uuid'))->resolve();
    expect($byUuid['id'])->toBe($oldest);

    $foreign = InspectionSubmission::query()->where('company_uuid', 'company-other')->first();
    expect($controller->find($foreign->public_id)->getStatusCode())->toBe(404)
        ->and($controller->find('inspection_submission_missing')->getData(true))->toBe(['error' => 'Inspection resource not found.']);

    $history = fn (string $vehicle, array $query = []) => array_column($controller->forVehicle(Request::create('/v1/vehicles/x/inspections', 'GET', $query), $vehicle)->resolve(), 'id');
    expect($history('vehicle_one'))->toBe([$newest, $oldest])
        ->and($history('vehicle-1', ['result' => 'failed']))->toBe([$newest])
        ->and($history('vehicle_one', ['limit' => 1]))->toBe([$newest])
        ->and($history('vehicle_two', ['type' => 'dvir']))->toBe([])
        ->and($controller->forVehicle(Request::create('/v1/vehicles/x/inspections', 'GET'), 'vehicle_other')->getStatusCode())->toBe(404);
});

test('inspection form resource keeps internal identifiers off the driver api', function () {
    fleetOpsInspectionControllerDatabase();
    $form = fleetOpsInspectionControllerForm(['subject_type' => Vehicle::class, 'subject_uuid' => 'vehicle-1']);

    $resolved = (new InspectionFormResource($form->fresh()))->resolve();

    expect($resolved['id'])->toBe($form->public_id)
        ->and(array_keys($resolved))->not->toContain('uuid', 'public_id', 'company_uuid', 'subject_uuid', 'subject_type', 'created_by_uuid', 'updated_by_uuid')
        ->and($resolved['subject']['type'])->toBe('maintenance-subject-vehicle');

    // A form bound to nothing, with the relation resolved as such: no subject
    // to transform, and nothing to stamp a type onto.
    $wide = fleetOpsInspectionControllerForm();
    $wide->setRelation('subject', null);
    expect((new InspectionFormResource($wide))->resolve()['subject'])->toBeNull();

    // `whenLoaded` answers a loaded-null relation itself, so the two helpers
    // never see a missing subject through the resource. Reached directly, as
    // the other morph resources are, so their guards are exercised.
    $resource  = new InspectionFormResource($wide);
    $transform = new ReflectionMethod(InspectionFormResource::class, 'transformMorphResource');
    $stamp     = new ReflectionMethod(InspectionFormResource::class, 'setSubjectType');
    $transform->setAccessible(true);
    $stamp->setAccessible(true);

    expect($transform->invoke($resource, null))->toBeNull()
        ->and($stamp->invoke($resource, null))->toBeNull()
        ->and($stamp->invoke($resource, []))->toBe([]);
});
