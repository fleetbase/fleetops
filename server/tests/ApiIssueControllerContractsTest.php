<?php

use Fleetbase\FleetOps\Http\Controllers\Api\v1\IssueController;
use Fleetbase\FleetOps\Http\Requests\CreateIssueRequest;
use Fleetbase\FleetOps\Http\Requests\UpdateIssueRequest;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Issue;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;

class FleetOpsApiIssueControllerProbe extends IssueController
{
    public ?Driver $driver      = null;
    public ?Issue $issue        = null;
    public array $createdIssues = [];
    public mixed $queryResults  = null;
    public bool $driverNotFound = false;
    public bool $issueNotFound  = false;

    protected function findDriverRecord(string $id): Driver
    {
        if ($this->driverNotFound) {
            throw new ModelNotFoundException();
        }

        $this->driver?->setAttribute('lookup_id', $id);

        return $this->driver;
    }

    protected function createIssue(array $input): Issue
    {
        $this->createdIssues[] = $input;

        $issue = new FleetOpsApiIssueFake();
        $issue->setRawAttributes(array_merge(['uuid' => 'created-issue-uuid'], $input));

        return $issue;
    }

    protected function findIssueRecord(string $id): Issue
    {
        if ($this->issueNotFound) {
            throw new ModelNotFoundException();
        }

        $this->issue?->setAttribute('lookup_id', $id);

        return $this->issue;
    }

    protected function queryIssues(Request $request)
    {
        $this->queryResults = $this->queryResults ?? [['uuid' => 'issue-uuid']];

        return $this->queryResults;
    }

    protected function issueResource(Issue $issue)
    {
        return ['resource' => 'issue', 'issue' => $issue];
    }

    protected function issueResourceCollection($results)
    {
        return ['collection' => 'issue', 'items' => $results];
    }

    protected function deletedIssueResource(Issue $issue)
    {
        return ['resource' => 'deleted-issue', 'issue' => $issue];
    }

    protected function jsonResponse(array $payload, int $status)
    {
        return ['json' => $payload, 'status' => $status];
    }
}

class FleetOpsApiIssueFake extends Issue
{
    public array $updates       = [];
    public bool $deletedForTest = false;

    public function update(array $attributes = [], array $options = []): bool
    {
        $this->updates[] = $attributes;
        $this->forceFill($attributes);

        return true;
    }

    public function delete()
    {
        $this->deletedForTest = true;

        return true;
    }
}

function fleetopsApiIssueDriver(): Driver
{
    $driver = new Driver();
    $driver->setRawAttributes([
        'uuid'         => 'driver-uuid',
        'company_uuid' => 'company-uuid',
        'user_uuid'    => 'user-uuid',
        'vehicle_uuid' => 'vehicle-uuid',
    ]);

    return $driver;
}

function fleetopsCreateIssueRequest(array $input): CreateIssueRequest
{
    return CreateIssueRequest::create('/api/v1/issues', 'POST', $input);
}

function fleetopsUpdateIssueRequest(array $input): UpdateIssueRequest
{
    return UpdateIssueRequest::create('/api/v1/issues/issue-public', 'PUT', $input);
}

test('api issue controller creates issues from reporting driver context', function () {
    $controller         = new FleetOpsApiIssueControllerProbe();
    $controller->driver = fleetopsApiIssueDriver();

    $response = $controller->create(fleetopsCreateIssueRequest([
        'driver'   => 'driver-public',
        'location' => ['latitude' => 1.3, 'longitude' => 103.8],
        'category' => 'vehicle',
        'type'     => 'damage',
        'report'   => 'Door damage',
        'priority' => 'high',
        'tags'     => ['door', 'urgent'],
        'status'   => 'open',
        'ignored'  => 'not copied',
    ]));

    expect($response['resource'])->toBe('issue')
        ->and($controller->createdIssues)->toHaveCount(1)
        ->and($controller->createdIssues[0])->toMatchArray([
            'driver'           => 'driver-public',
            'location'         => ['latitude' => 1.3, 'longitude' => 103.8],
            'category'         => 'vehicle',
            'type'             => 'damage',
            'report'           => 'Door damage',
            'priority'         => 'high',
            'tags'             => ['door', 'urgent'],
            'status'           => 'open',
            'company_uuid'     => 'company-uuid',
            'driver_uuid'      => 'driver-uuid',
            'reported_by_uuid' => 'user-uuid',
            'vehicle_uuid'     => 'vehicle-uuid',
        ])
        ->and($controller->createdIssues[0])->not->toHaveKey('ignored');
});

test('api issue controller returns driver missing response during create', function () {
    $controller                 = new FleetOpsApiIssueControllerProbe();
    $controller->driverNotFound = true;

    expect($controller->create(fleetopsCreateIssueRequest(['driver' => 'missing-driver'])))->toBe([
        'json'   => ['error' => 'Driver reporting issue not found.'],
        'status' => 404,
    ]);
});

test('api issue controller updates finds queries and deletes issues', function () {
    $issue = new FleetOpsApiIssueFake();
    $issue->setRawAttributes(['uuid' => 'issue-uuid', 'status' => 'open']);

    $controller               = new FleetOpsApiIssueControllerProbe();
    $controller->issue        = $issue;
    $controller->queryResults = [['uuid' => 'issue-a'], ['uuid' => 'issue-b']];

    $updated = $controller->update('issue-public', fleetopsUpdateIssueRequest([
        'category' => 'delivery',
        'type'     => 'delay',
        'report'   => 'Delayed at pickup',
        'priority' => 'medium',
        'tags'     => ['pickup'],
        'status'   => 'resolved',
        'driver'   => 'ignored-driver',
    ]));
    $found   = $controller->find('issue-public');
    $query   = $controller->query(new Request(['limit' => 2]));
    $deleted = $controller->delete('issue-public');

    expect($updated['resource'])->toBe('issue')
        ->and($issue->updates[0])->toBe([
            'category' => 'delivery',
            'type'     => 'delay',
            'report'   => 'Delayed at pickup',
            'priority' => 'medium',
            'tags'     => ['pickup'],
            'status'   => 'resolved',
        ])
        ->and($found)->toBe(['resource' => 'issue', 'issue' => $issue])
        ->and($query)->toBe(['collection' => 'issue', 'items' => [['uuid' => 'issue-a'], ['uuid' => 'issue-b']]])
        ->and($deleted)->toBe(['resource' => 'deleted-issue', 'issue' => $issue])
        ->and($issue->deletedForTest)->toBeTrue();
});

test('api issue controller returns missing issue responses for update find and delete', function () {
    $controller                = new FleetOpsApiIssueControllerProbe();
    $controller->issueNotFound = true;

    $expected = [
        'json'   => ['error' => 'Issue resource not found.'],
        'status' => 404,
    ];

    expect($controller->update('missing-issue', fleetopsUpdateIssueRequest(['report' => 'Missing'])))->toBe($expected)
        ->and($controller->find('missing-issue'))->toBe($expected)
        ->and($controller->delete('missing-issue'))->toBe($expected);
});
