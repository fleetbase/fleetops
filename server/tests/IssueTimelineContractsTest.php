<?php

use Fleetbase\FleetOps\Http\Controllers\Internal\v1\IssueController;
use Fleetbase\FleetOps\Models\Issue;
use Fleetbase\Models\Activity;
use Illuminate\Support\Carbon;

class FleetOpsIssueControllerProbe extends IssueController
{
    public function callHelper(string $method, mixed ...$arguments): mixed
    {
        $reflection = new ReflectionMethod(IssueController::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($this, ...$arguments);
    }
}

function fleetopsTimelineIssue(): Issue
{
    $issue = new Issue();
    $issue->setRawAttributes([
        'uuid'          => 'issue-uuid',
        'public_id'     => 'issue-public',
        'reporter_name' => 'Ada Reporter',
        'created_at'    => Carbon::parse('2026-01-01 10:00:00'),
    ], true);
    $issue->setRelation('reporter', (object) [
        'name'       => 'Ada Reporter',
        'avatar_url' => 'https://example.test/reporter.png',
    ]);

    return $issue;
}

function fleetopsTimelineActivity(array $attributes = []): Activity
{
    $activity = new Activity();
    $activity->setRawAttributes(array_merge([
        'uuid'        => 'activity-uuid',
        'description' => 'Issue was updated.',
        'created_at'  => Carbon::parse('2026-01-01 11:00:00'),
    ], $attributes), true);
    $activity->setRelation('causer', (object) [
        'name'       => 'Ops Manager',
        'avatar_url' => 'https://example.test/manager.png',
    ]);

    return $activity;
}

test('issue timeline opened and generic activity events include actor and issue metadata', function () {
    $controller = new FleetOpsIssueControllerProbe();
    $issue      = fleetopsTimelineIssue();
    $activity   = fleetopsTimelineActivity();

    $opened  = $controller->callHelper('makeIssueOpenedEvent', $issue);
    $generic = $controller->callHelper('makeGenericActivityEvent', $activity, $issue);

    expect($opened)->toMatchArray([
        'id'               => 'issue-uuid-opened',
        'type'             => 'issue_opened',
        'label'            => 'Issue opened',
        'description'      => 'Reported by Ada Reporter',
        'actor_name'       => 'Ada Reporter',
        'actor_avatar_url' => 'https://example.test/reporter.png',
        'icon'             => 'circle-plus',
        'tone'             => 'green',
        'meta'             => ['issue_id' => 'issue-public'],
    ])
        ->and($generic)->toMatchArray([
            'id'               => 'activity-uuid',
            'type'             => 'issue_updated',
            'label'            => 'Issue updated',
            'description'      => 'Issue was updated.',
            'actor_name'       => 'Ops Manager',
            'actor_avatar_url' => 'https://example.test/manager.png',
            'icon'             => 'pen',
            'tone'             => 'slate',
            'meta'             => ['issue_id' => 'issue-public'],
        ]);
});

test('issue timeline field events map important field changes to specialized labels', function () {
    $controller = new FleetOpsIssueControllerProbe();
    $issue      = fleetopsTimelineIssue();
    $activity   = fleetopsTimelineActivity(['id' => 123, 'uuid' => null]);

    $closed   = $controller->callHelper('makeFieldChangedEvent', $activity, $issue, 'status', 'open', 'resolved');
    $reopened = $controller->callHelper('makeFieldChangedEvent', $activity, $issue, 'status', 'resolved', 're_opened');
    $assignee = $controller->callHelper('makeFieldChangedEvent', $activity, $issue, 'assigned_to_uuid', null, 'user-uuid');
    $category = $controller->callHelper('makeFieldChangedEvent', $activity, $issue, 'category', 'damage', 'delay');

    expect($closed)->toMatchArray([
        'id'          => '123-status',
        'type'        => 'issue_closed',
        'label'       => 'Issue closed',
        'description' => 'Status changed from Open to Resolved.',
        'icon'        => 'circle-check',
        'tone'        => 'green',
        'meta'        => [
            'field'    => 'status',
            'from'     => 'open',
            'to'       => 'resolved',
            'issue_id' => 'issue-public',
        ],
    ])
        ->and($reopened)->toMatchArray([
            'type'  => 'issue_reopened',
            'label' => 'Issue re-opened',
            'icon'  => 'rotate-left',
            'tone'  => 'orange',
        ])
        ->and($assignee)->toMatchArray([
            'type'        => 'assignee_changed',
            'label'       => 'Assignee changed',
            'description' => 'Assigned To changed from none to User Uuid.',
            'icon'        => 'user-check',
            'tone'        => 'indigo',
        ])
        ->and($category)->toMatchArray([
            'type'        => 'issue_updated',
            'label'       => 'Category changed',
            'description' => 'Category changed from Damage to Delay.',
            'icon'        => 'pen',
            'tone'        => 'slate',
        ]);
});

test('issue timeline change descriptions normalize uuid suffixes and blank values', function () {
    $controller = new FleetOpsIssueControllerProbe();

    expect($controller->callHelper('formatChangeDescription', 'driver_uuid', '', 'driver-uuid'))
        ->toBe('Driver changed from none to Driver Uuid.')
        ->and($controller->callHelper('formatChangeDescription', 'resolved_at', null, null))
        ->toBe('Resolved At changed from none to none.');
});
