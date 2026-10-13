<?php

use Fleetbase\FleetOps\Http\Controllers\Internal\v1\InspectionSubmissionController;
use Fleetbase\FleetOps\Http\Controllers\Internal\v1\MaintenanceController;
use Fleetbase\FleetOps\Http\Filter\InspectionFormFilter;
use Fleetbase\FleetOps\Http\Filter\InspectionSubmissionFilter;
use Fleetbase\FleetOps\Http\Filter\MaintenanceFilter;
use Fleetbase\FleetOps\Http\Filter\ProofFilter;
use Fleetbase\FleetOps\Http\Filter\RouteFilter;
use Fleetbase\FleetOps\Http\Filter\TelematicFilter;
use Fleetbase\FleetOps\Http\Filter\WarrantyFilter;
use Fleetbase\FleetOps\Models\InspectionForm;
use Fleetbase\FleetOps\Models\InspectionSubmission;
use Fleetbase\FleetOps\Models\Maintenance;
use Fleetbase\FleetOps\Models\Proof;
use Fleetbase\FleetOps\Models\Route;
use Fleetbase\FleetOps\Models\Telematic;
use Fleetbase\FleetOps\Models\Warranty;
use Fleetbase\Support\Find;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Http\Request;

/*
 * A console list is scoped to the signed-in company only by the resource's
 * Filter (core-api applies `queryForInternal` and nothing else), so a model
 * without one lists every company's rows. These tests hold each tenant-owned
 * resource to its own company, for lists and for the by-id endpoints that
 * look records up themselves.
 */

const FLEETOPS_TENANT_OWN   = 'company-own';
const FLEETOPS_TENANT_OTHER = 'company-other';

function fleetopsTenantScopingBoot(): SQLiteConnection
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

    $columns = ['uuid', 'public_id', 'company_uuid', 'name', 'description', 'type', 'status', 'result', 'summary', 'notes', 'provider', 'model', 'serial_number', 'imei', 'policy_number', 'vehicle_uuid', 'driver_uuid', 'user_uuid', 'inspection_form_uuid', 'line_items', 'labor_cost', 'tax', 'parts_cost', 'total_cost'];
    $schema  = $connection->getSchemaBuilder();
    foreach (['inspection_forms', 'inspection_submissions', 'maintenances', 'telematics', 'warranties', 'proofs', 'routes', 'vehicles', 'drivers', 'users'] as $table) {
        $schema->create($table, function ($blueprint) use ($columns) {
            $blueprint->increments('id');
            foreach ($columns as $column) {
                $blueprint->string($column)->nullable();
            }
            $blueprint->timestamps();
            $blueprint->timestamp('deleted_at')->nullable();
        });
    }

    foreach (['inspection_forms', 'inspection_submissions', 'maintenances', 'telematics', 'warranties', 'proofs', 'routes'] as $table) {
        foreach ([FLEETOPS_TENANT_OWN => 'own', FLEETOPS_TENANT_OTHER => 'other'] as $company => $label) {
            $connection->table($table)->insert([
                'uuid'          => "{$table}-{$label}",
                'public_id'     => "{$table}_{$label}",
                'company_uuid'  => $company,
                'name'          => 'Shared name',
                'summary'       => 'Shared name',
                'provider'      => 'shared',
                'type'          => 'shared',
                'policy_number' => 'shared',
            ]);
        }
    }

    session(['company' => FLEETOPS_TENANT_OWN]);

    return $connection;
}

function fleetopsTenantScopingRequest(string $uri, array $query = []): Request
{
    $request = Request::create('/' . $uri, 'GET', $query);
    $session = app('session.store');
    $session->put('company', FLEETOPS_TENANT_OWN);
    $request->setLaravelSession($session);
    $request->setRouteResolver(fn () => new class($uri) {
        public array $action = [];

        public function __construct(private string $uri)
        {
        }

        public function uri(): string
        {
            return $this->uri;
        }
    });

    return $request;
}

dataset('tenant scoped resources', [
    'inspection forms'       => [InspectionForm::class, InspectionFormFilter::class, 'inspection_forms'],
    'inspection submissions' => [InspectionSubmission::class, InspectionSubmissionFilter::class, 'inspection_submissions'],
    'maintenances'           => [Maintenance::class, MaintenanceFilter::class, 'maintenances'],
    'telematics'             => [Telematic::class, TelematicFilter::class, 'telematics'],
    'warranties'             => [Warranty::class, WarrantyFilter::class, 'warranties'],
    'proofs'                 => [Proof::class, ProofFilter::class, 'proofs'],
    'routes'                 => [Route::class, RouteFilter::class, 'routes'],
]);

test('the resource resolves to its tenant filter', function (string $model, string $filter) {
    expect(ltrim((string) Find::httpFilterForModel(new $model()), '\\'))->toBe($filter);
})->with('tenant scoped resources');

test('a console list returns only the session company rows', function (string $model, string $filter, string $table) {
    fleetopsTenantScopingBoot();

    $uuids = $model::query()
        ->filter(new $filter(fleetopsTenantScopingRequest("int/v1/fleet-ops/{$table}")))
        ->pluck('uuid')
        ->all();

    expect($uuids)->toBe(["{$table}-own"]);
})->with('tenant scoped resources');

test('a public api list returns only the session company rows', function (string $model, string $filter, string $table) {
    fleetopsTenantScopingBoot();

    $uuids = $model::query()
        ->filter(new $filter(fleetopsTenantScopingRequest("v1/{$table}")))
        ->pluck('uuid')
        ->all();

    expect($uuids)->toBe(["{$table}-own"]);
})->with('tenant scoped resources');

test('a search that matches every company still returns only the session company rows', function (string $model, string $filter, string $table) {
    fleetopsTenantScopingBoot();

    $uuids = $model::query()
        ->filter(new $filter(fleetopsTenantScopingRequest("int/v1/fleet-ops/{$table}", ['query' => 'shared'])))
        ->pluck('uuid')
        ->all();

    expect($uuids)->toBe(["{$table}-own"]);
})->with('tenant scoped resources');

function fleetopsTenantScopingController(string $class): object
{
    return (new ReflectionClass($class))->newInstanceWithoutConstructor();
}

function fleetopsTenantScopingCall(object $controller, string $method, ...$arguments): mixed
{
    $reflection = new ReflectionMethod($controller, $method);
    $reflection->setAccessible(true);

    return $reflection->invoke($controller, ...$arguments);
}

test('a maintenance line item endpoint resolves only the session company record', function () {
    fleetopsTenantScopingBoot();
    $controller = fleetopsTenantScopingController(MaintenanceController::class);

    expect(fleetopsTenantScopingCall($controller, 'findMaintenanceForLineItem', 'maintenances-own')->uuid)->toBe('maintenances-own')
        ->and(fleetopsTenantScopingCall($controller, 'findMaintenanceForLineItem', 'maintenances_own')->uuid)->toBe('maintenances-own');

    foreach (['maintenances-other', 'maintenances_other'] as $id) {
        expect(fn () => fleetopsTenantScopingCall($controller, 'findMaintenanceForLineItem', $id))->toThrow(ModelNotFoundException::class);
    }

    $request = Request::create('/int/v1/fleet-ops/maintenances/maintenances-other/line-items', 'POST', ['description' => 'x', 'quantity' => 1, 'unit_cost' => 1]);
    expect(fn () => $controller->addLineItem('maintenances-other', $request))->toThrow(ModelNotFoundException::class)
        ->and(fn () => $controller->updateLineItem('maintenances-other', 0, $request))->toThrow(ModelNotFoundException::class)
        ->and(fn () => $controller->removeLineItem('maintenances-other', 0))->toThrow(ModelNotFoundException::class);
});

test('an inspection submission action resolves only the session company record', function () {
    $connection = fleetopsTenantScopingBoot();
    $controller = fleetopsTenantScopingController(InspectionSubmissionController::class);

    expect(fleetopsTenantScopingCall($controller, 'submissionQuery', 'inspection_submissions-own')->value('uuid'))->toBe('inspection_submissions-own')
        ->and(fleetopsTenantScopingCall($controller, 'submissionQuery', 'inspection_submissions_own')->value('uuid'))->toBe('inspection_submissions-own')
        ->and(fleetopsTenantScopingCall($controller, 'submissionQuery', 'inspection_submissions-other')->exists())->toBeFalse()
        ->and(fleetopsTenantScopingCall($controller, 'submissionQuery', 'inspection_submissions_other')->exists())->toBeFalse();

    foreach (['submit', 'createIssue', 'createWorkOrder', 'resolve'] as $action) {
        expect(fn () => $controller->{$action}('inspection_submissions-other'))->toThrow(ModelNotFoundException::class);
    }

    expect($connection->table('inspection_submissions')->where('uuid', 'inspection_submissions-other')->value('status'))->toBeNull();
});
