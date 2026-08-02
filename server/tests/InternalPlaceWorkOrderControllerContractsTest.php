<?php

use Fleetbase\FleetOps\Http\Controllers\Internal\v1\PlaceController;
use Fleetbase\FleetOps\Http\Controllers\Internal\v1\WorkOrderController;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Models\WorkOrder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

if (!function_exists('Fleetbase\Traits\config')) {
    eval('namespace Fleetbase\Traits; function config($key = null, $default = null) { return false; }');
}

if (!function_exists('Fleetbase\Models\config')) {
    eval('namespace Fleetbase\Models; function config($key = null, $default = null) { return $default; }');
}

if (!Request::hasMacro('searchQuery')) {
    Request::macro('searchQuery', fn () => $this->input('query', $this->input('search', $this->input('searchQuery'))));
}

class FleetOpsInternalPlaceControllerProbe extends PlaceController
{
    public array $synced   = [];
    public array $searches = [];
    public array $avatars  = [
        ['name' => 'Warehouse', 'icon' => 'warehouse'],
    ];
    public Collection $searchResults;
    public FleetOpsPlaceQueryFake $query;

    public function __construct()
    {
        $this->searchResults = collect();
        $this->query         = new FleetOpsPlaceQueryFake();
    }

    protected function syncCustomFieldValues(Place $place, array $customFieldValues): void
    {
        $this->synced[] = [$place->public_id, $customFieldValues];
    }

    protected function newPlaceQuery()
    {
        return $this->query;
    }

    protected function searchPlaces($query, ?string $searchQuery, array $options)
    {
        $this->searches[] = [$query, $searchQuery, $options];

        return $this->searchResults;
    }

    protected function avatarOptions(): array
    {
        return $this->avatars;
    }
}

class FleetOpsPlaceQueryFake
{
    public array $calls = [];

    public function where(...$arguments): self
    {
        $this->calls[] = ['where', $arguments];

        return $this;
    }

    public function whereNull(string $column): self
    {
        $this->calls[] = ['whereNull', $column];

        return $this;
    }

    public function applyDirectivesForPermissions(string $permission): self
    {
        $this->calls[] = ['applyDirectivesForPermissions', $permission];

        return $this;
    }
}

class FleetOpsInternalWorkOrderControllerProbe extends WorkOrderController
{
    public ?WorkOrder $workOrder = null;
    public array $lookups        = [];
    public array $mail           = [];
    public array $activity       = [];

    protected function workOrderForEmail(string $id): WorkOrder
    {
        $this->lookups[] = $id;

        return $this->workOrder;
    }

    protected function sendWorkOrderDispatchedMail(string $email, WorkOrder $workOrder): void
    {
        $this->mail[] = [$email, $workOrder->public_id];
    }

    protected function recordWorkOrderSentActivity(WorkOrder $workOrder, string $email): void
    {
        $this->activity[] = [$workOrder->public_id, $email];
    }
}

function fleetopsInternalPlace(array $attributes): Place
{
    $place = new Place();
    $place->setRawAttributes($attributes, true);

    return $place;
}

function fleetopsInternalWorkOrder(?object $assignee): WorkOrder
{
    $workOrder = new WorkOrder();
    $workOrder->setRawAttributes(['public_id' => 'wo_public'], true);
    $workOrder->setRelation('assignee', $assignee);

    return $workOrder;
}

test('internal place controller syncs custom fields and returns avatars', function () {
    $controller = new FleetOpsInternalPlaceControllerProbe();
    $place      = fleetopsInternalPlace(['public_id' => 'place_public']);

    $controller->afterSave(new Request([
        'place' => [
            'custom_field_values' => [
                ['key' => 'dock', 'value' => 'A1'],
            ],
        ],
    ]), $place);

    expect($controller->synced)->toBe([
        ['place_public', [['key' => 'dock', 'value' => 'A1']]],
    ]);

    $controller->afterSave(new Request(['place' => ['custom_field_values' => []]]), $place);

    expect($controller->synced)->toHaveCount(1)
        ->and($controller->avatars()->getData(true))->toBe($controller->avatars);
});

test('internal place controller delegates search options', function () {
    session(['company' => 'company-uuid']);

    $controller                = new FleetOpsInternalPlaceControllerProbe();
    $controller->searchResults = collect([fleetopsInternalPlace(['public_id' => 'place_public', 'name' => 'Central Depot'])]);

    $searchResponse = $controller->search(new Request([
        'query'     => 'depot',
        'limit'     => 10,
        'geo'       => true,
        'latitude'  => '1.3000',
        'longitude' => '103.8000',
    ]));

    expect($searchResponse->collection)->toHaveCount(1)
        ->and($controller->query->calls)->toContain(['where', ['company_uuid', 'company-uuid']])
        ->and($controller->query->calls)->toContain(['whereNull', 'deleted_at'])
        ->and($controller->query->calls)->toContain(['applyDirectivesForPermissions', 'fleet-ops list place'])
        ->and($controller->searches[0][1])->toBe('depot')
        ->and($controller->searches[0][2])->toMatchArray([
            'limit'     => 10,
            'geo'       => true,
            'latitude'  => '1.3000',
            'longitude' => '103.8000',
        ]);
});

test('internal work order controller handles missing assignee email and successful sends', function () {
    $controller            = new FleetOpsInternalWorkOrderControllerProbe();
    $controller->workOrder = fleetopsInternalWorkOrder(null);
    $missingAssignee       = $controller->sendEmail('wo_public');

    expect($missingAssignee->getStatusCode())->toBe(422)
        ->and($missingAssignee->getData(true))->toBe([
            'error' => 'This work order has no assigned vendor.',
        ]);

    $controller->workOrder = fleetopsInternalWorkOrder((object) ['email' => null]);

    $missingEmail = $controller->sendEmail('wo_public');

    expect($missingEmail->getStatusCode())->toBe(422)
        ->and($missingEmail->getData(true))->toBe([
            'error' => 'The assigned vendor has no email address on file.',
        ]);

    $controller->workOrder = fleetopsInternalWorkOrder((object) ['email' => 'vendor@example.test']);
    $sent                  = $controller->sendEmail('wo_public');

    expect($sent->getData(true))->toBe([
        'status'  => 'ok',
        'message' => 'Work order successfully sent to vendor@example.test',
    ])
        ->and($controller->lookups)->toBe(['wo_public', 'wo_public', 'wo_public'])
        ->and($controller->mail)->toBe([['vendor@example.test', 'wo_public']])
        ->and($controller->activity)->toBe([['wo_public', 'vendor@example.test']]);
});
