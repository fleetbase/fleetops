<?php

use Fleetbase\FleetOps\Exports\InspectionExport;
use Fleetbase\FleetOps\Http\Controllers\Internal\v1\InspectionFormController;
use Fleetbase\FleetOps\Http\Controllers\Internal\v1\InspectionSubmissionController;
use Fleetbase\FleetOps\Http\Resources\v1\InspectionForm as InspectionFormResource;
use Fleetbase\FleetOps\Http\Resources\v1\InspectionSubmission as InspectionSubmissionResource;
use Fleetbase\FleetOps\Models\InspectionForm;
use Fleetbase\FleetOps\Models\InspectionSubmission;
use Fleetbase\FleetOps\Support\InspectionFileStore;
use Fleetbase\FleetOps\Support\InspectionFormSync;
use Fleetbase\FleetOps\Support\InspectionSubmitter;
use Fleetbase\Http\Requests\ExportRequest;
use Fleetbase\Models\Category;
use Fleetbase\Models\CustomField;
use Fleetbase\Models\File;
use Illuminate\Config\Repository;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

if (!function_exists('Fleetbase\\Support\\auth')) {
    eval('namespace Fleetbase\\Support; function auth() { return new class { public function user() { return null; } public function id() { return null; } }; }');
}

/*
 * Creating a platform file wakes the webhook observer, which fires an event.
 * There is no event dispatcher here and nothing is listening; the stub lets
 * the file be written so the store can be watched doing it.
 */
if (!function_exists('Fleetbase\\Observers\\event')) {
    eval('namespace Fleetbase\\Observers; function event($event = null) { return $event; }');
}

/*
 * A file's caption is humanized from its name by a core-api macro that is not
 * loaded here, so the harness supplies the same shape.
 */
if (!Illuminate\Support\Str::hasMacro('humanize')) {
    Illuminate\Support\Str::macro('humanize', fn ($value, $uppercase = true) => str_replace('_', ' ', Illuminate\Support\Str::snake((string) $value)));
}

if (!class_exists('Fleetbase\\Http\\Requests\\ExportRequest', false)) {
    eval('namespace Fleetbase\\Http\\Requests; class ExportRequest extends \\Illuminate\\Http\\Request {}');
}

class FleetOpsInspectionExportRequestFake extends ExportRequest
{
}

/**
 * The submission controller with the download intercepted: what is asserted
 * is the sheet it asked for, not the workbook the spreadsheet library builds.
 */
class FleetOpsInspectionSubmissionControllerProbe extends InspectionSubmissionController
{
    public array $downloads = [];

    protected function downloadExport(InspectionExport $export, string $fileName)
    {
        $this->downloads[] = [$export, $fileName];

        return ['download' => $fileName, 'headings' => $export->headings()];
    }
}

/** Stands in for the route a resource asks whether it is answering. */
class FleetOpsInspectionFieldRouteFixture
{
    public function __construct(private string $uri)
    {
    }

    public function uri(): string
    {
        return $this->uri;
    }
}

/** Binds the request a resource reads to decide public from internal. */
function fleetOpsInspectionFieldRequest(bool $internal): Request
{
    $uri     = $internal ? 'api/int/v1/fleet-ops/inspection-submissions' : 'v1/inspections';
    $request = Request::create('/' . $uri, 'GET');
    $request->setRouteResolver(fn () => new FleetOpsInspectionFieldRouteFixture($uri));
    app()->instance('request', $request);

    return $request;
}

/**
 * A form built from fields, and a submission that answers it, against an
 * in-memory database. Every column is a nullable string: what is asserted
 * here is what the models, the writer and the resources put in and take out.
 */
function fleetOpsInspectionFieldDatabase(): SQLiteConnection
{
    $pdo        = new PDO('sqlite::memory:');
    $connection = new SQLiteConnection($pdo);
    $resolver   = new ConnectionResolver(['default' => $connection, 'mysql' => $connection]);
    $resolver->setDefaultConnection('mysql');
    EloquentModel::setConnectionResolver($resolver);
    EloquentModel::setEventDispatcher(new Dispatcher());
    EloquentModel::clearBootedModels();

    $root   = sys_get_temp_dir() . '/fleetops-inspection-files';
    $config = new Repository([
        'activitylog' => ['enabled' => false, 'default_auth_driver' => null, 'default_log_name' => 'default'],
        'api'         => ['cache' => ['enabled' => false]],
        // Not the `local` disk: a file on that one is answered through the
        // foundation's `asset()` helper, which has no app to ask here.
        'filesystems' => [
            'default' => 'uploads',
            'disks'   => ['uploads' => ['driver' => 'local', 'root' => $root, 'url' => 'https://files.example.com']],
        ],
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

    // Files are real here: the store writes base64 through the platform's
    // File model, and what it wrote is what the resource resolves back.
    app()->instance('filesystem', new FilesystemManager(app()));
    Illuminate\Support\Facades\Storage::clearResolvedInstances();
    Illuminate\Support\Facades\Storage::swap(app('filesystem'));

    $schema = $connection->getSchemaBuilder();
    $tables = [
        'inspection_forms'        => ['uuid', 'public_id', '_key', 'company_uuid', 'name', 'description', 'type', 'status', 'subject_type', 'subject_uuid', 'items', 'settings', 'meta', 'published_at', 'created_by_uuid', 'updated_by_uuid'],
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
    $connection->table('users')->insert(['uuid' => 'user-driver', 'public_id' => 'user_driver', 'company_uuid' => 'company-insp', 'name' => 'Dana Driver', 'email' => 'dana@example.com', 'type' => 'user']);
    $connection->table('vehicles')->insert(['uuid' => 'vehicle-1', 'public_id' => 'vehicle_one', 'company_uuid' => 'company-insp', 'name' => 'Truck 7', 'plate_number' => 'TRK-7']);
    $connection->table('drivers')->insert(['uuid' => 'driver-1', 'public_id' => 'driver_one', 'company_uuid' => 'company-insp', 'user_uuid' => 'user-driver', 'vehicle_uuid' => 'vehicle-1', 'status' => 'active']);

    session(['company' => 'company-insp', 'user' => 'user-driver']);

    return $connection;
}

/** The draft the console builder posts: two groups of typed fields. */
function fleetOpsInspectionFieldDraft(): array
{
    return [
        [
            'name'   => 'Exterior',
            'order'  => 1,
            'meta'   => ['grid_size' => 2],
            'fields' => [
                ['label' => 'Mirrors', 'name' => 'mirrors', 'type' => 'pass-fail', 'required' => true, 'order' => 1, 'meta' => ['severity' => 'medium', 'require_comment_on_fail' => true, 'unsafe_on_fail' => false]],
                ['label' => 'Brakes', 'name' => 'brakes', 'type' => 'pass-fail', 'required' => true, 'order' => 2, 'meta' => ['severity' => 'critical', 'require_photo_on_fail' => true, 'require_comment_on_fail' => true, 'unsafe_on_fail' => true]],
            ],
        ],
        [
            'name'  => 'Meter and sign-off',
            'order' => 2,
            'meta'  => ['grid_size' => 1],
            // `customFields` is the fliit builder's spelling; both are read.
            'customFields' => [
                ['label' => 'Odometer', 'type' => 'number', 'order' => 1, 'meta' => ['unit' => 'km', 'role' => 'odometer']],
                ['label' => 'Notes', 'type' => 'textarea', 'order' => 2],
                ['label' => 'Sign here', 'name' => 'signature', 'type' => 'signature', 'order' => 3],
                ['label' => 'Tail lift photo', 'name' => 'tail_lift', 'type' => 'file-upload', 'order' => 4],
                ['label' => 'Trailer attached', 'name' => 'trailer', 'type' => 'boolean', 'order' => 5],
                ['label' => 'Fuel level', 'name' => 'fuel', 'type' => 'radio-button', 'options' => ['full', 'half'], 'order' => 6],
            ],
        ],
    ];
}

function fleetOpsInspectionFieldForm(array $attributes = []): InspectionForm
{
    return InspectionForm::create(array_merge([
        'company_uuid' => 'company-insp',
        'name'         => 'Pre-trip DVIR',
        'type'         => 'dvir',
        'status'       => 'published',
        'published_at' => '2026-09-01 08:00:00',
        'settings'     => ['create_issue_on_failure' => false, 'create_work_order_on_failure' => false],
    ], $attributes));
}

function fleetOpsInspectionFieldSubmission(InspectionForm $form, array $attributes = []): InspectionSubmission
{
    return InspectionSubmission::create(array_merge([
        'company_uuid'         => 'company-insp',
        'inspection_form_uuid' => $form->uuid,
        'vehicle_uuid'         => 'vehicle-1',
        'driver_uuid'          => 'driver-1',
        'submitted_by_uuid'    => 'user-driver',
        'type'                 => 'dvir',
        'status'               => 'draft',
        'source'               => 'navigator',
    ], $attributes));
}

/** A one-pixel PNG, as the app sends one. */
function fleetOpsInspectionFieldPhoto(): string
{
    return base64_encode(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAC0lEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true));
}

afterEach(function () {
    Carbon::setTestNow();
});

test('the form writer builds groups of typed fields and prunes what a later draft drops', function () {
    fleetOpsInspectionFieldDatabase();
    $form = fleetOpsInspectionFieldForm();

    $written = InspectionFormSync::sync($form, fleetOpsInspectionFieldDraft());

    expect($written['groups'])->toHaveCount(2)
        ->and($written['fields'])->toHaveCount(8);

    $groups = $form->fieldGroups;
    expect($groups->pluck('name')->all())->toBe(['Exterior', 'Meter and sign-off'])
        ->and($groups->first()->for)->toBe(InspectionForm::GROUP_FOR)
        ->and($groups->first()->owner_uuid)->toBe($form->uuid)
        ->and($groups->first()->meta)->toBe(['grid_size' => 2])
        ->and($groups->first()->public_id)->toStartWith('category_')
        ->and($groups->first()->slug)->toBe('exterior');

    $fields = $form->fields->keyBy('name');
    expect($fields->keys()->sort()->values()->all())->toBe(['brakes', 'fuel', 'mirrors', 'notes', 'odometer', 'signature', 'tail-lift', 'trailer'])
        ->and($fields['brakes']->for)->toBe(InspectionForm::FIELD_FOR)
        ->and($fields['brakes']->subject_uuid)->toBe($form->uuid)
        ->and($fields['brakes']->category_uuid)->toBe($groups->first()->uuid)
        ->and($fields['brakes']->required)->toBeTrue()
        ->and($fields['brakes']->editable)->toBeTrue()
        ->and($fields['brakes']->component)->toBe('pass-fail')
        // A field named only by its label takes a slug of the label.
        ->and($fields['odometer']->label)->toBe('Odometer')
        ->and($fields['odometer']->meta)->toBe(['unit' => 'km', 'role' => 'odometer'])
        // `radio-button` is the type; the platform's component is named differently.
        ->and($fields['fuel']->component)->toBe('radio-button-select')
        ->and($fields['fuel']->options)->toBe(['full', 'half']);

    // A custom field the console's generic panel added to the form record has
    // the same subject; the builder must not take it for one of its own.
    CustomField::create(['company_uuid' => 'company-insp', 'subject_uuid' => $form->uuid, 'subject_type' => $form->getMorphClass(), 'name' => 'depot', 'label' => 'Depot', 'type' => 'text']);

    // A second post with the same uuids updates rather than duplicates, and
    // what it no longer lists is deleted.
    $draft                          = fleetOpsInspectionFieldDraft();
    $draft[0]['uuid']               = $groups->first()->uuid;
    $draft[0]['name']               = 'Exterior walk-around';
    $draft[0]['fields'][0]['uuid']  = $fields['mirrors']->uuid;
    $draft[0]['fields'][0]['label'] = 'Mirrors and glass';
    unset($draft[1]);

    InspectionFormSync::sync($form, $draft, true);

    $form->unsetRelation('fieldGroups');
    $form->unsetRelation('fields');
    expect($form->fieldGroups)->toHaveCount(1)
        ->and($form->fieldGroups->first()->name)->toBe('Exterior walk-around')
        ->and($form->fields)->toHaveCount(2)
        ->and($form->fields->firstWhere('uuid', $fields['mirrors']->uuid)->label)->toBe('Mirrors and glass')
        // Two inspection fields, plus the unrelated one, left where it was.
        ->and(CustomField::query()->count())->toBe(3)
        ->and(CustomField::query()->where('name', 'depot')->exists())->toBeTrue()
        ->and(Category::query()->count())->toBe(1);
});

test('the form writer gives a nameless, typeless field somewhere to go', function () {
    fleetOpsInspectionFieldDatabase();
    $form = fleetOpsInspectionFieldForm();

    InspectionFormSync::sync($form, [['fields' => [['type' => 'nonsense'], []]]]);

    $group = $form->fieldGroups->first();
    expect($group->name)->toBeNull()
        ->and($group->order)->toBe('1')
        ->and($form->fields->pluck('label')->all())->toBe(['Untitled field', 'Untitled field'])
        ->and($form->fields->pluck('type')->all())->toBe(['input', 'input'])
        ->and(InspectionFormSync::componentFor('signature'))->toBe('signature');
});

test('a first-cut checklist becomes a Checklist group of pass-fail fields, once', function () {
    fleetOpsInspectionFieldDatabase();
    $form = fleetOpsInspectionFieldForm(['items' => [
        ['key' => 'brakes', 'label' => 'Brakes', 'category' => 'Safety', 'severity' => 'critical'],
        ['title' => 'Lights', 'severity' => 'medium', 'required' => false, 'description' => 'All round'],
        [],
    ]]);

    expect(InspectionFormSync::convertLegacyItems($form))->toBe(3);

    $group = $form->fresh()->fieldGroups->first();
    expect($group->name)->toBe('Checklist')
        ->and($group->meta)->toBe(['grid_size' => 1, 'converted_from_items' => true]);

    $fields = $form->fields()->get()->keyBy('name');
    expect($fields['brakes']->type)->toBe('pass-fail')
        ->and($fields['brakes']->required)->toBeTrue()
        ->and($fields['brakes']->meta['severity'])->toBe('critical')
        ->and($fields['brakes']->meta['category'])->toBe('Safety')
        ->and($fields['brakes']->meta['unsafe_on_fail'])->toBeTrue()
        ->and($fields['lights']->required)->toBeFalse()
        ->and($fields['lights']->description)->toBe('All round')
        ->and($fields['lights']->meta['unsafe_on_fail'])->toBeFalse()
        ->and($fields['item-3']->label)->toBe('Item 3');

    // Idempotent: a form already built with fields is left alone, and so is a
    // form with nothing to convert.
    expect(InspectionFormSync::convertLegacyItems($form->fresh()))->toBe(0)
        ->and(InspectionFormSync::convertLegacyItems(fleetOpsInspectionFieldForm()))->toBe(0)
        ->and(CustomField::query()->count())->toBe(3);
});

test('a form answers with its groups in order, ungrouped fields last', function () {
    fleetOpsInspectionFieldDatabase();
    $form = fleetOpsInspectionFieldForm();
    InspectionFormSync::sync($form, fleetOpsInspectionFieldDraft());

    // A group and a field with no order of their own sort after the ones that
    // have one, oldest first; a field belonging to no group is gathered up.
    Category::query()->where('name', 'Exterior')->update(['order' => null]);
    CustomField::query()->where('name', 'brakes')->update(['category_uuid' => null, 'order' => null]);

    $form->unsetRelation('fieldGroups');
    $form->unsetRelation('fields');
    $groups = $form->grouped_fields;

    expect(collect($groups)->pluck('name')->all())->toBe(['Meter and sign-off', 'Exterior', 'Ungrouped'])
        ->and($groups[2]->exists)->toBeFalse()
        ->and($groups[2]->getRelation('fields')->pluck('name')->all())->toBe(['brakes'])
        ->and($groups[1]->getRelation('fields')->pluck('name')->all())->toBe(['mirrors'])
        ->and($form->item_count)->toBe(8);

    // The count falls back to the legacy checklist only while there are no fields.
    $legacy = fleetOpsInspectionFieldForm(['items' => [['key' => 'a', 'label' => 'A']]]);
    expect($legacy->item_count)->toBe(1);
});

test('the form resource answers grouped_fields for the driver and identifiers for the console', function () {
    fleetOpsInspectionFieldDatabase();
    $form = fleetOpsInspectionFieldForm();
    InspectionFormSync::sync($form, fleetOpsInspectionFieldDraft());

    $public = (new InspectionFormResource($form->fresh()))->toArray(fleetOpsInspectionFieldRequest(false));

    expect($public['id'])->toBe($form->public_id)
        ->and($public['grouped_fields'])->toHaveCount(2)
        ->and($public['grouped_fields'][0]['name'])->toBe('Exterior')
        ->and($public['grouped_fields'][0]['order'])->toBe(1)
        ->and($public['grouped_fields'][0]['meta'])->toBe(['grid_size' => 2])
        ->and($public['grouped_fields'][0])->not->toHaveKey('company_uuid');

    $field = $public['grouped_fields'][0]['fields'][1];
    expect($field['id'])->toBe($field['uuid'])
        ->and($field['name'])->toBe('brakes')
        ->and($field['label'])->toBe('Brakes')
        ->and($field['type'])->toBe('pass-fail')
        ->and($field['required'])->toBeTrue()
        ->and($field['editable'])->toBeTrue()
        ->and($field['options'])->toBe([])
        ->and($field['order'])->toBe(2)
        ->and($field['meta']['severity'])->toBe('critical')
        ->and($field)->not->toHaveKey('category_uuid');

    // A field written straight into the table carries no component; the type names one.
    CustomField::query()->where('name', 'brakes')->update(['component' => null, 'meta' => null, 'order' => null, 'editable' => null]);
    $bare = (new InspectionFormResource($form->fresh()))->toArray(fleetOpsInspectionFieldRequest(false))['grouped_fields'][0]['fields'];
    $bare = collect($bare)->firstWhere('name', 'brakes');
    expect($bare['component'])->toBe('pass-fail')
        ->and($bare['meta'])->toEqual((object) [])
        ->and($bare['order'])->toBeNull()
        ->and($bare['editable'])->toBeTrue();

    $request  = fleetOpsInspectionFieldRequest(true);
    $internal = (new InspectionFormResource($form->fresh()->load(['fieldGroups', 'fields'])))->toArray($request);
    expect($internal['grouped_fields'][0]['company_uuid'])->toBe('company-insp')
        ->and($internal['grouped_fields'][0]['for'])->toBe(InspectionForm::GROUP_FOR)
        ->and($internal['field_groups'])->toHaveCount(2)
        ->and($internal['field_groups'][0])->not->toHaveKey('fields')
        ->and($internal['fields'])->toHaveCount(8)
        ->and($internal['fields'][0]['subject_uuid'])->toBe($form->uuid)
        ->and($internal['fields'][0]['for'])->toBe(InspectionForm::FIELD_FOR);

    // A group with no fields loaded answers with none rather than reaching for them.
    $orphan = Category::query()->where('name', 'Exterior')->first();
    expect(InspectionFormResource::groupToArray($orphan, false)['fields'])->toBe([]);
});

test('the file store turns what a driver sends into a platform file', function () {
    fleetOpsInspectionFieldDatabase();
    $form       = fleetOpsInspectionFieldForm();
    $submission = fleetOpsInspectionFieldSubmission($form);

    $stored = InspectionFileStore::normalize(fleetOpsInspectionFieldPhoto(), $submission, InspectionFileStore::TYPE_PHOTO, 'user-driver');
    expect($stored)->toStartWith('file:');

    $file = InspectionFileStore::resolve($stored);
    expect($file)->toBeInstanceOf(File::class)
        ->and($file->subject_uuid)->toBe($submission->uuid)
        ->and($file->type)->toBe('inspection_photo')
        ->and($file->company_uuid)->toBe('company-insp')
        ->and($file->uploader_uuid)->toBe('user-driver')
        ->and($file->content_type)->toBe('image/png')
        ->and($file->path)->toStartWith('inspections/' . $submission->uuid . '/');

    // Anything already a reference, a URL or not a string at all is left alone.
    expect(InspectionFileStore::normalize($stored, $submission))->toBe($stored)
        ->and(InspectionFileStore::normalize('https://cdn.example.com/a.jpg', $submission))->toBe('https://cdn.example.com/a.jpg')
        ->and(InspectionFileStore::normalize('  ', $submission))->toBe('  ')
        ->and(InspectionFileStore::normalize(42, $submission))->toBe(42)
        ->and(InspectionFileStore::normalize('not base64!', $submission))->toBe('not base64!')
        ->and(InspectionFileStore::normalize($file->uuid, $submission))->toBe('file:' . $file->uuid)
        ->and(InspectionFileStore::normalize($file->public_id, $submission))->toBe('file:' . $file->uuid)
        ->and(InspectionFileStore::normalize('file_missing', $submission))->toBe('file_missing')
        // The console uploads a photo as soon as it is picked and keeps the
        // reference the upload answered with, which names the file by its
        // public id; that is rewritten to the uuid everything else reads.
        ->and(InspectionFileStore::normalize('file:' . $file->public_id, $submission))->toBe('file:' . $file->uuid)
        ->and(InspectionFileStore::normalize('file:file_missing', $submission))->toBe('file:file_missing');

    // A data URI carries its own content type; bare base64 is sniffed.
    $jpeg = InspectionFileStore::resolve(InspectionFileStore::normalize('data:image/jpeg;base64,' . fleetOpsInspectionFieldPhoto(), $submission, InspectionFileStore::TYPE_SIGNATURE));
    expect($jpeg->content_type)->toBe('image/jpeg')
        ->and($jpeg->type)->toBe('inspection_signature')
        ->and($jpeg->path)->toEndWith('.jpg')
        ->and($jpeg->uploader_uuid)->toBe('user-driver');

    expect(InspectionFileStore::sniffContentType(base64_encode("\xFF\xD8\xFF" . str_repeat('a', 20))))->toBe('image/jpeg')
        ->and(InspectionFileStore::sniffContentType(base64_encode('GIF89a' . str_repeat('a', 20))))->toBe('image/gif')
        ->and(InspectionFileStore::sniffContentType(base64_encode('RIFF____WEBP' . str_repeat('a', 20))))->toBe('image/webp')
        ->and(InspectionFileStore::sniffContentType(base64_encode('%PDF-1.4' . str_repeat('a', 20))))->toBe('application/pdf')
        ->and(InspectionFileStore::sniffContentType(base64_encode(str_repeat('a', 24))))->toBe('image/png')
        ->and(InspectionFileStore::extensionFor('image/gif'))->toBe('gif')
        ->and(InspectionFileStore::extensionFor('image/webp'))->toBe('webp')
        ->and(InspectionFileStore::extensionFor('application/pdf'))->toBe('pdf')
        ->and(InspectionFileStore::extensionFor('image/svg+xml'))->toBe('svg')
        ->and(InspectionFileStore::isBase64('data:image/png;base64,***'))->toBeFalse()
        ->and(InspectionFileStore::isUrl('ftp://x'))->toBeFalse();

    // A file uploaded before the submission existed is claimed by it.
    $loose = File::create(['company_uuid' => 'company-insp', 'disk' => 'uploads', 'path' => 'loose.png', 'type' => 'inspection_photo']);
    expect(InspectionFileStore::attachReferenced($submission, ['file:' . $loose->uuid, 'nonsense']))->toBe(0)
        ->and(InspectionFileStore::attachReferenced($submission, [$loose->uuid]))->toBe(1)
        ->and(InspectionFileStore::attachReferenced($submission, []))->toBe(0)
        ->and($loose->fresh()->subject_uuid)->toBe($submission->uuid);

    expect(InspectionFileStore::referencedUuid('file:not-a-uuid'))->toBeNull()
        ->and(InspectionFileStore::referencedUuid(null))->toBeNull()
        ->and(InspectionFileStore::resolve('file:' . (string) Illuminate\Support\Str::uuid()))->toBeNull()
        ->and(InspectionFileStore::project('https://cdn.example.com/a.jpg'))->toBe('https://cdn.example.com/a.jpg');

    $projected = InspectionFileStore::project($stored);
    expect($projected['id'])->toBe($file->public_id)
        ->and($projected['content_type'])->toBe('image/png')
        ->and($projected)->toHaveKeys(['id', 'url', 'filename', 'content_type']);
});

test('a submission answers a form of fields, and the item results follow', function () {
    fleetOpsInspectionFieldDatabase();
    Carbon::setTestNow('2026-09-10 07:00:00');
    $form = fleetOpsInspectionFieldForm();
    InspectionFormSync::sync($form, fleetOpsInspectionFieldDraft());
    $fields     = $form->fields->keyBy('name');
    $submission = fleetOpsInspectionFieldSubmission($form);

    InspectionSubmitter::applyCustomFieldValues($submission, [
        ['custom_field' => $fields['mirrors']->uuid, 'value_type' => 'object', 'value' => ['passed' => true, 'not_applicable' => false, 'severity' => null, 'comments' => null, 'photos' => [], 'unsafe' => false]],
        ['custom_field' => $fields['brakes']->uuid, 'value_type' => 'object', 'value' => ['passed' => false, 'severity' => 'Critical', 'comments' => 'Soft pedal', 'photos' => [fleetOpsInspectionFieldPhoto()], 'unsafe' => true]],
        // Named by its slug rather than its uuid, as an older build may.
        ['custom_field' => 'odometer', 'value_type' => 'number', 'value' => '112480'],
        ['custom_field' => $fields['notes']->uuid, 'value_type' => 'text', 'value' => 'Nearside mirror scuffed'],
        ['custom_field' => $fields['signature']->uuid, 'value_type' => 'file', 'value' => fleetOpsInspectionFieldPhoto()],
        ['custom_field' => $fields['trailer']->uuid, 'value_type' => 'boolean', 'value' => 'true'],
        ['custom_field' => $fields['fuel']->uuid, 'value' => ['full']],
    ], 'user-driver');

    $submission = $submission->fresh(['itemResults', 'customFieldValues.customField', 'files']);

    expect($submission->customFieldValues)->toHaveCount(7)
        ->and($submission->total_items)->toBe(2)
        ->and($submission->failed_items)->toBe(1)
        ->and($submission->result)->toBe('failed')
        ->and($submission->status)->toBe('submitted')
        ->and($submission->meta['unsafe'])->toBeTrue()
        ->and($submission->files)->toHaveCount(2);

    $results = $submission->itemResults->keyBy('item_key');
    expect($results->keys()->sort()->values()->all())->toBe(['brakes', 'mirrors'])
        ->and($results['brakes']->label)->toBe('Brakes')
        ->and($results['brakes']->category)->toBe('Exterior')
        ->and($results['brakes']->status)->toBe('failed')
        ->and($results['brakes']->severity)->toBe('critical')
        ->and($results['brakes']->passed)->toBeFalse()
        ->and($results['brakes']->comments)->toBe('Soft pedal')
        ->and($results['brakes']->photos[0])->toStartWith('file:')
        ->and($results['brakes']->meta['unsafe'])->toBeTrue()
        ->and($results['brakes']->meta['custom_field_uuid'])->toBe($fields['brakes']->uuid)
        ->and($results['mirrors']->status)->toBe('passed')
        ->and($results['mirrors']->severity)->toBeNull();

    // The value column is a string, so a meter reading is stored as one; the
    // resource is where it becomes a number again.
    $odometer = $submission->customFieldValues->first(fn ($value) => $value->custom_field_uuid === $fields['odometer']->uuid);
    expect($odometer->value)->toBe('112480')
        ->and($odometer->value_type)->toBe('number');

    // Answering again with the brakes passing and the mirrors not applicable
    // rewrites the results rather than adding to them.
    InspectionSubmitter::applyCustomFieldValues($submission, [
        ['custom_field' => $fields['mirrors']->uuid, 'value' => ['passed' => true, 'not_applicable' => true]],
        ['custom_field' => $fields['brakes']->uuid, 'value' => ['passed' => true]],
    ]);

    $submission = $submission->fresh(['itemResults']);
    $results    = $submission->itemResults->keyBy('item_key');
    expect($submission->itemResults)->toHaveCount(2)
        ->and($results['mirrors']->status)->toBe('not_applicable')
        ->and($results['mirrors']->passed)->toBeTrue()
        ->and($results['brakes']->status)->toBe('passed')
        ->and($results['brakes']->severity)->toBeNull()
        ->and($submission->meta['unsafe'])->toBeFalse()
        ->and($submission->result)->toBe('passed');

    // Clearing an answer is not an answer: the value row goes rather than a
    // null being written to a column that will not take one.
    InspectionSubmitter::applyCustomFieldValues($submission, [
        ['custom_field' => $fields['notes']->uuid, 'value' => null],
        ['custom_field' => $fields['odometer']->uuid, 'value' => ''],
    ]);
    expect($submission->fresh()->customFieldValues()->where('custom_field_uuid', $fields['notes']->uuid)->count())->toBe(0)
        ->and($submission->fresh()->customFieldValues()->where('custom_field_uuid', $fields['odometer']->uuid)->count())->toBe(0);

    // An answer that is taken away — the console clearing a field — takes its
    // result row with it; a row written any other way is left alone.
    $submission->customFieldValues()->where('custom_field_uuid', $fields['mirrors']->uuid)->delete();
    $submission->itemResults()->create(['company_uuid' => 'company-insp', 'item_key' => 'hand-written', 'label' => 'Hand written', 'passed' => true]);
    expect($submission->fresh()->syncItemResultsFromCustomFieldValues())->toBe(1)
        ->and($submission->fresh()->itemResults()->pluck('item_key')->sort()->values()->all())->toBe(['brakes', 'hand-written']);
});

test('a submission refuses an answer the form will not accept', function () {
    fleetOpsInspectionFieldDatabase();
    $form = fleetOpsInspectionFieldForm();
    InspectionFormSync::sync($form, fleetOpsInspectionFieldDraft());
    $fields     = $form->fields->keyBy('name');
    $submission = fleetOpsInspectionFieldSubmission($form);

    $refusal = null;
    try {
        InspectionSubmitter::applyCustomFieldValues($submission, [
            ['custom_field' => 'not-a-field-of-this-form', 'value' => 1],
            ['custom_field' => $fields['brakes']->uuid, 'value' => ['passed' => false]],
            ['value' => 'no field at all'],
        ]);
    } catch (ValidationException $exception) {
        $refusal = $exception->errors();
    }

    expect($refusal['custom_field_values.0.custom_field'][0])->toContain('not-a-field-of-this-form')
        ->and($refusal['custom_field_values.1.value'])->toBe([
            'A comment is required when "Brakes" fails.',
            'A photo is required when "Brakes" fails.',
        ])
        ->and($refusal['custom_field_values.2.custom_field'][0])->toContain('"?"')
        // Nothing was written, and no photo was stored on the way to refusing.
        ->and($submission->customFieldValues()->count())->toBe(0)
        ->and(File::query()->count())->toBe(0);
});

test('a pass-fail answer is read whatever shape it arrives in', function () {
    expect(InspectionSubmitter::passFailAnswer(['passed' => false, 'severity' => 'High', 'comments' => 'x', 'photos' => ['a'], 'unsafe' => true]))
        ->toBe(['passed' => false, 'not_applicable' => false, 'severity' => 'high', 'comments' => 'x', 'photos' => ['a'], 'unsafe' => true])
        ->and(InspectionSubmitter::passFailAnswer('{"pass":false}')['passed'])->toBeFalse()
        ->and(InspectionSubmitter::passFailAnswer('{not json')['passed'])->toBeTrue()
        ->and(InspectionSubmitter::passFailAnswer('fail')['passed'])->toBeFalse()
        ->and(InspectionSubmitter::passFailAnswer('pass')['passed'])->toBeTrue()
        ->and(InspectionSubmitter::passFailAnswer(false)['passed'])->toBeFalse()
        ->and(InspectionSubmitter::passFailAnswer(['passed' => null])['not_applicable'])->toBeTrue()
        ->and(InspectionSubmitter::passFailAnswer(['na' => true])['not_applicable'])->toBeTrue()
        ->and(InspectionSubmitter::passFailAnswer(['severity' => ''])['severity'])->toBeNull();
});

test('the submission resource answers the answers, with the files resolved', function () {
    fleetOpsInspectionFieldDatabase();
    $form = fleetOpsInspectionFieldForm();
    InspectionFormSync::sync($form, fleetOpsInspectionFieldDraft());
    $fields     = $form->fields->keyBy('name');
    $submission = fleetOpsInspectionFieldSubmission($form);

    InspectionSubmitter::applyCustomFieldValues($submission, [
        ['custom_field' => $fields['brakes']->uuid, 'value' => ['passed' => false, 'severity' => 'critical', 'comments' => 'Soft pedal', 'photos' => [fleetOpsInspectionFieldPhoto()]]],
        ['custom_field' => $fields['signature']->uuid, 'value_type' => 'file', 'value' => fleetOpsInspectionFieldPhoto()],
        ['custom_field' => $fields['notes']->uuid, 'value' => 'Nothing else to report'],
        ['custom_field' => $fields['odometer']->uuid, 'value_type' => 'number', 'value' => '112480'],
        ['custom_field' => $fields['trailer']->uuid, 'value' => 'true'],
    ], 'user-driver');

    $loaded = $submission->fresh(['itemResults', 'customFieldValues.customField', 'files']);
    $public = (new InspectionSubmissionResource($loaded))->toArray(fleetOpsInspectionFieldRequest(false));

    $values = collect($public['custom_field_values'])->keyBy('name');
    expect($public['custom_field_values'])->toHaveCount(5)
        ->and($values['brakes']['custom_field'])->toBe($fields['brakes']->uuid)
        ->and($values['brakes']['label'])->toBe('Brakes')
        ->and($values['brakes']['type'])->toBe('pass-fail')
        ->and($values['brakes']['value']['passed'])->toBeFalse()
        ->and($values['brakes']['value']['comments'])->toBe('Soft pedal')
        ->and($values['brakes']['value']['photos'][0])->toHaveKeys(['id', 'url', 'filename', 'content_type'])
        ->and($values['signature']['value'])->toHaveKeys(['id', 'url', 'filename', 'content_type'])
        ->and($values['notes']['value'])->toBe('Nothing else to report')
        // A value column is a string; the resource hands back the number and
        // the boolean the app wrote.
        ->and($values['odometer']['value'])->toBe(112480)
        ->and($values['trailer']['value'])->toBeTrue()
        ->and($values['brakes'])->not->toHaveKey('uuid');

    expect($public['files'])->toHaveCount(2)
        ->and($public['files'][0])->toHaveKeys(['id', 'uuid', 'url', 'original_filename', 'content_type', 'type'])
        ->and($public['item_results'])->toHaveCount(1);

    $internal = (new InspectionSubmissionResource($loaded))->toArray(fleetOpsInspectionFieldRequest(true));
    $brakes   = collect($internal['custom_field_values'])->firstWhere('name', 'brakes');
    expect($brakes['uuid'])->not->toBeNull()
        ->and($brakes['category_uuid'])->toBe($fields['brakes']->category_uuid)
        ->and($brakes['order'])->toBe(2)
        ->and($brakes['meta']['severity'])->toBe('critical');

    // A submission whose answers were never loaded says nothing about them.
    $bare = (new InspectionSubmissionResource(new InspectionSubmission()))->toArray(fleetOpsInspectionFieldRequest(false));
    expect($bare['custom_field_values'])->toBe([])
        ->and($bare['files'])->toBe([]);
});

test('the submission resource survives a value whose field is gone or whose object is not one', function () {
    fleetOpsInspectionFieldDatabase();
    $form       = fleetOpsInspectionFieldForm();
    InspectionFormSync::sync($form, fleetOpsInspectionFieldDraft());
    $fields     = $form->fields->keyBy('name');
    $submission = fleetOpsInspectionFieldSubmission($form);

    $submission->syncCustomFieldValues([
        ['custom_field_uuid' => $fields['notes']->uuid, 'value' => 'not json', 'value_type' => 'object'],
        ['custom_field_uuid' => (string) Illuminate\Support\Str::uuid(), 'value' => 'orphan', 'value_type' => 'text'],
    ]);

    $loaded  = $submission->fresh(['customFieldValues.customField']);
    $payload = (new InspectionSubmissionResource($loaded))->toArray(fleetOpsInspectionFieldRequest(true));
    $rows    = collect($payload['custom_field_values']);

    expect($rows)->toHaveCount(2)
        ->and($rows->firstWhere('name', 'notes')['value'])->toBe('not json')
        ->and($rows->last()['label'])->toBeNull()
        ->and($rows->last()['type'])->toBe('text')
        ->and($rows->last()['value'])->toBe('orphan')
        ->and($rows->last()['meta'])->toEqual((object) []);

    expect($submission->fresh()->referencedFileUuids())->toBe([]);
});

test('the inspection export names the defects and whether the truck was parked', function () {
    fleetOpsInspectionFieldDatabase();
    $form = fleetOpsInspectionFieldForm();
    InspectionFormSync::sync($form, fleetOpsInspectionFieldDraft());
    $fields = $form->fields->keyBy('name');

    $failed = fleetOpsInspectionFieldSubmission($form, ['odometer' => 112480, 'engine_hours' => 3100]);
    InspectionSubmitter::applyCustomFieldValues($failed, [
        ['custom_field' => $fields['mirrors']->uuid, 'value' => ['passed' => true]],
        ['custom_field' => $fields['brakes']->uuid, 'value' => ['passed' => false, 'comments' => 'Soft pedal', 'photos' => [fleetOpsInspectionFieldPhoto()]]],
    ]);
    $passed = fleetOpsInspectionFieldSubmission($form);
    InspectionSubmitter::applyCustomFieldValues($passed, [
        ['custom_field' => $fields['mirrors']->uuid, 'value' => ['passed' => true]],
    ]);

    $export = new InspectionExport();
    $rows   = $export->collection();
    expect($rows)->toHaveCount(2)
        ->and($export->headings())->toHaveCount(20)
        ->and($export->columnFormats())->toHaveKeys(['Q', 'R', 'S', 'T']);

    $mapped = collect($rows)->map(fn ($row) => $export->map($row))->keyBy(0);
    $row    = $mapped[$failed->public_id];
    expect($row[1])->toBe('Pre-trip DVIR')
        ->and($row[2])->toBe('Truck 7')
        ->and($row[3])->toBe('Dana Driver')
        ->and($row[6])->toBe('failed')
        ->and($row[8])->toBe(112480)
        ->and($row[9])->toBe(3100)
        ->and($row[11])->toBe(1)
        ->and($row[12])->toBe('Brakes')
        ->and($row[13])->toBe('Yes')
        ->and($mapped[$passed->public_id][12])->toBe('')
        ->and($mapped[$passed->public_id][13])->toBe('No');

    // A selection narrows the sheet to the rows the console ticked.
    expect((new InspectionExport([$failed->uuid]))->collection())->toHaveCount(1);
});

test('the internal form controller writes the builder draft and reads the structure back', function () {
    fleetOpsInspectionFieldDatabase();
    $controller = new InspectionFormController();
    $form       = fleetOpsInspectionFieldForm();

    // The builder posts the whole form under the record it is saving.
    $controller->onAfterCreate(Request::create('/int/v1/inspection-forms', 'POST', ['inspection_form' => ['field_groups' => fleetOpsInspectionFieldDraft()]]), $form);
    expect($form->fieldGroups)->toHaveCount(2)
        ->and($form->fields)->toHaveCount(8);

    // A save that mentions no structure leaves the structure alone: publishing
    // a form must not empty it.
    $controller->onAfterUpdate(Request::create('/int/v1/inspection-forms/x', 'PUT', ['inspection_form' => ['status' => 'published']]), $form);
    expect($form->fresh()->fields()->count())->toBe(8);

    // fliit's builder posts the same thing under `draft`, and a form authored
    // there still saves; what it drops is pruned.
    $controller->onAfterUpdate(Request::create('/int/v1/inspection-forms/x', 'PUT', ['inspection_form' => ['draft' => [['name' => 'Only group', 'fields' => [['label' => 'One', 'type' => 'pass-fail']]]]]]), $form);
    expect($form->fresh()->fields()->count())->toBe(1)
        ->and($form->fresh()->fieldGroups()->count())->toBe(1);

    $find = InspectionForm::query();
    $controller->onFindRecord($find, Request::create('/'));
    $query = InspectionForm::query();
    $controller->onQueryRecord($query, Request::create('/'));
    expect(array_keys($find->getEagerLoads()))->toContain('fieldGroups', 'fields')
        ->and(array_keys($query->getEagerLoads()))->toContain('fieldGroups', 'fields')
        ->and($find->first()->relationLoaded('fields'))->toBeTrue();
});

test('the internal submission controller records answers, item results and an export', function () {
    fleetOpsInspectionFieldDatabase();
    $controller = new FleetOpsInspectionSubmissionControllerProbe();
    $form       = fleetOpsInspectionFieldForm();
    InspectionFormSync::sync($form, fleetOpsInspectionFieldDraft());
    $fields     = $form->fields->keyBy('name');
    $submission = fleetOpsInspectionFieldSubmission($form);

    $controller->onAfterCreate(Request::create('/int/v1/inspection-submissions', 'POST', ['inspection_submission' => ['custom_field_values' => [
        ['custom_field' => $fields['mirrors']->uuid, 'value' => ['passed' => false, 'comments' => 'Cracked']],
    ]]]), $submission, []);

    expect($submission->itemResults->pluck('item_key')->all())->toBe(['mirrors'])
        ->and($submission->itemResults->first()->comments)->toBe('Cracked')
        ->and($submission->relationLoaded('files'))->toBeTrue();

    // The flat spelling is read too, and answering again rewrites the row.
    $controller->onAfterUpdate(Request::create('/int/v1/inspection-submissions/x', 'PUT', ['custom_field_values' => [
        ['custom_field' => $fields['mirrors']->uuid, 'value' => ['passed' => true]],
    ]]), $submission, []);
    expect($submission->fresh()->itemResults->first()->passed)->toBeTrue();

    // A submission against a legacy checklist still posts results directly.
    $controller->onAfterUpdate(Request::create('/int/v1/inspection-submissions/x', 'PUT', ['inspection_submission' => ['item_results' => [
        ['item_key' => 'hand-written', 'label' => 'Hand written', 'passed' => false],
    ]]]), $submission, []);
    expect($submission->fresh()->itemResults->pluck('item_key')->all())->toBe(['hand-written']);

    $query = InspectionSubmission::query();
    $controller->onQueryRecord($query, Request::create('/'));
    expect(array_keys($query->getEagerLoads()))->toContain('form', 'vehicle', 'driver', 'itemResults');

    $response = $controller->export(FleetOpsInspectionExportRequestFake::create('/int/v1/inspection-submissions/export', 'POST', ['format' => 'csv', 'selections' => ['a', 'b']]));
    expect($response['download'])->toMatch('/^inspections-[0-9-]+\.csv$/')
        ->and($response['headings'])->toContain('Failed Items')
        ->and($controller->downloads[0][0])->toBeInstanceOf(InspectionExport::class);

    // No format and no selection: the whole sheet, as a workbook.
    expect($controller->export(FleetOpsInspectionExportRequestFake::create('/int/v1/inspection-submissions/export', 'POST'))['download'])->toEndWith('.xlsx');
});

test('the submitter takes the body the driver app builds, whole', function () {
    fleetOpsInspectionFieldDatabase();
    Carbon::setTestNow('2026-09-10 07:30:00');
    $form = fleetOpsInspectionFieldForm();
    InspectionFormSync::sync($form, fleetOpsInspectionFieldDraft());
    $fields = $form->fields->keyBy('name');
    $photo  = fleetOpsInspectionFieldPhoto();

    // Exactly what `buildSubmission` in the app's useInspections.ts emits:
    // both bodies, every pass-fail answer carrying `not_applicable` and
    // `unsafe`, photos and the signature as bare base64.
    $submission = InspectionSubmitter::submit($form, [
        'odometer'            => 112480,
        'engine_hours'        => null,
        'custom_field_values' => [
            ['custom_field' => $fields['brakes']->uuid, 'value_type' => 'object', 'value' => ['passed' => false, 'not_applicable' => false, 'severity' => 'critical', 'comments' => 'Soft pedal', 'photos' => [$photo], 'unsafe' => true]],
            ['custom_field' => $fields['mirrors']->uuid, 'value_type' => 'object', 'value' => ['passed' => true, 'not_applicable' => true, 'severity' => null, 'comments' => null, 'photos' => [], 'unsafe' => false]],
            ['custom_field' => $fields['odometer']->uuid, 'value_type' => 'number', 'value' => 112480],
            ['custom_field' => $fields['signature']->uuid, 'value_type' => 'file', 'value' => $photo],
            ['custom_field' => $fields['notes']->uuid, 'value_type' => 'text', 'value' => 'Nearside mirror scuffed'],
        ],
        'item_results' => [
            ['item_key' => 'brakes', 'label' => 'Brakes', 'category' => 'Exterior', 'status' => 'failed', 'severity' => 'critical', 'passed' => false, 'comments' => 'Soft pedal', 'photos' => [$photo]],
            ['item_key' => 'mirrors', 'label' => 'Mirrors', 'category' => 'Exterior', 'status' => 'not_applicable', 'severity' => null, 'passed' => true, 'comments' => null, 'photos' => []],
        ],
        'location'  => ['latitude' => 1.3521, 'longitude' => 103.8198],
        'signature' => ['image' => $photo, 'signed_at' => '2026-09-10T07:30:00Z'],
        'meta'      => ['source_app' => 'navigator', 'unsafe' => true],
    ], [
        'driver_uuid'       => 'driver-1',
        'vehicle_uuid'      => 'vehicle-1',
        'submitted_by_uuid' => 'user-driver',
        'source'            => 'navigator',
    ]);

    $submission = $submission->fresh(['itemResults', 'customFieldValues.customField', 'files']);
    $results    = $submission->itemResults->keyBy('item_key');

    expect($submission->customFieldValues)->toHaveCount(5)
        // The field values won; the duplicated `item_results` were ignored,
        // so there is one row per pass-fail field and no more.
        ->and($submission->itemResults)->toHaveCount(2)
        ->and($results['brakes']->status)->toBe('failed')
        ->and($results['brakes']->photos[0])->toStartWith('file:')
        ->and($results['mirrors']->status)->toBe('not_applicable')
        ->and($results['mirrors']->passed)->toBeTrue()
        ->and($submission->total_items)->toBe(2)
        ->and($submission->failed_items)->toBe(1)
        ->and($submission->result)->toBe('failed')
        ->and($submission->meta['unsafe'])->toBeTrue()
        ->and($submission->odometer)->toBe(112480)
        ->and($submission->files)->toHaveCount(2)
        ->and($submission->source)->toBe('navigator');
});

test('the submitter takes field answers straight from a submit body', function () {
    fleetOpsInspectionFieldDatabase();
    $form = fleetOpsInspectionFieldForm(['settings' => ['create_issue_on_failure' => true, 'create_work_order_on_failure' => true]]);
    InspectionFormSync::sync($form, fleetOpsInspectionFieldDraft());
    $fields = $form->fields->keyBy('name');

    $submission = InspectionSubmitter::submit($form, [
        'odometer'            => 112480,
        'custom_field_values' => [
            ['custom_field' => $fields['mirrors']->uuid, 'value' => ['passed' => false, 'comments' => 'Cracked', 'severity' => 'high']],
        ],
        // Sent alongside by the app; the field values win and these are ignored.
        'item_results' => [['item_key' => 'mirrors', 'label' => 'Mirrors', 'passed' => false]],
    ], ['driver_uuid' => 'driver-1', 'vehicle_uuid' => 'vehicle-1', 'submitted_by_uuid' => 'user-driver']);

    expect($submission->itemResults()->count())->toBe(1)
        ->and($submission->itemResults()->first()->severity)->toBe('high')
        ->and($submission->fresh()->result)->toBe('failed')
        ->and($submission->fresh()->issue_uuid)->not->toBeNull()
        ->and($submission->fresh()->work_order_uuid)->not->toBeNull();
});

test('a photo that cannot be stored is left as it arrived', function () {
    fleetOpsInspectionFieldDatabase();
    $form       = fleetOpsInspectionFieldForm();
    $submission = fleetOpsInspectionFieldSubmission($form);

    // A disk that cannot be written to: the store gives back what it was given
    // rather than losing the answer along with the photo.
    $readOnly = sys_get_temp_dir() . '/fleetops-inspection-readonly-' . bin2hex(random_bytes(6));
    $folder   = $readOnly . '/inspections/' . $submission->uuid;
    if (!is_dir($folder)) {
        mkdir($folder, 0755, true);
    }
    chmod($folder, 0555);
    app('config')->set('filesystems.disks.broken', ['driver' => 'local', 'root' => $readOnly, 'throw' => false]);
    app('config')->set('filesystems.default', 'broken');

    $photo = fleetOpsInspectionFieldPhoto();
    $left  = InspectionFileStore::normalize($photo, $submission);
    chmod($folder, 0755);

    expect($left)->toBe($photo)
        ->and(File::query()->count())->toBe(0);
});

test('groups and fields sort the way the builder laid them out', function () {
    $ordered               = new Category(['name' => 'first']);
    $unordered             = new Category(['name' => 'later']);
    $older                 = new Category(['name' => 'older']);
    $ordered->order        = 2;
    $older->created_at     = '2026-01-01 00:00:00';
    $unordered->created_at = '2026-06-01 00:00:00';

    expect(InspectionForm::sortByOrder(collect([$unordered, $ordered, $older]))->pluck('name')->all())
        ->toBe(['first', 'older', 'later'])
        ->and(InspectionForm::sortByOrder(collect([$ordered, $unordered]))->pluck('name')->all())
        ->toBe(['first', 'later'])
        ->and(InspectionForm::sortByOrder(collect([$older, $unordered]))->pluck('name')->all())
        ->toBe(['older', 'later']);
});
