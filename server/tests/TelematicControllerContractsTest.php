<?php

use Fleetbase\FleetOps\Http\Controllers\Internal\v1\CustomerController;
use Fleetbase\FleetOps\Http\Controllers\Internal\v1\TelematicController;
use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\FleetOps\Models\Telematic;
use Fleetbase\FleetOps\Support\Telematics\TelematicProviderRegistry;
use Fleetbase\FleetOps\Support\Telematics\TelematicService;
use Fleetbase\Models\Activity;

class FleetOpsTelematicControllerProbe extends TelematicController
{
    public function __construct()
    {
        parent::__construct(new TelematicService(new TelematicProviderRegistry()), new TelematicProviderRegistry());
    }

    public function callHelper(string $method, mixed ...$arguments): mixed
    {
        $reflection = new ReflectionMethod(TelematicController::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($this, ...$arguments);
    }
}

class FleetOpsCustomerControllerProbe extends CustomerController
{
    public function callHelper(string $method, mixed ...$arguments): mixed
    {
        $reflection = new ReflectionMethod(CustomerController::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($this, ...$arguments);
    }
}

class FleetOpsActivityLogFake extends Activity
{
    public array $fakeAttributes = [];

    public function getAttribute($key)
    {
        if (array_key_exists($key, $this->fakeAttributes)) {
            return $this->fakeAttributes[$key];
        }

        return parent::getAttribute($key);
    }
}

test('internal telematic controller builds metadata log entries for sync and connection states', function () {
    $controller = new FleetOpsTelematicControllerProbe();
    $telematic  = new Telematic();
    $telematic->setRawAttributes([
        'uuid' => 'telematic-uuid',
        'meta' => [
            'last_sync_result'       => 'failed',
            'last_sync_job_id'       => 'sync-job-1',
            'last_sync_total'        => 7,
            'last_sync_error'        => 'SQLSTATE connection: token leaked',
            'last_sync_error_type'   => 'database',
            'last_sync_started_at'   => '2026-01-01 09:00:00',
            'last_sync_failed_at'    => '2026-01-01 09:05:00',
            'last_test_result'       => 'success',
            'last_connection_test'   => '2026-01-01 10:00:00',
        ],
    ], true);

    $logs = $controller->callHelper('makeTelematicMetadataLogs', $telematic);

    expect($logs)->toHaveCount(2)
        ->and($logs[0])->toMatchArray([
            'id'          => 'sync-sync-job-1',
            'type'        => 'sync_failed',
            'label'       => 'Device sync failed',
            'description' => 'Device sync failed. Review the provider connection and server logs, then try again.',
            'status'      => 'warning',
            'icon'        => 'circle-exclamation',
            'created_at'  => '2026-01-01 09:05:00',
            'actor_name'  => null,
            'metadata'    => [
                'job_id'       => 'sync-job-1',
                'result'       => 'failed',
                'total'        => 7,
                'error_type'   => 'database',
                'started_at'   => '2026-01-01 09:00:00',
                'completed_at' => null,
                'failed_at'    => '2026-01-01 09:05:00',
            ],
        ])
        ->and($logs[1])->toMatchArray([
            'id'          => 'connection-test-2026-01-01 10:00:00',
            'type'        => 'connection_test_success',
            'label'       => 'Connection test verified',
            'description' => 'Provider credentials were verified successfully.',
            'status'      => 'success',
            'icon'        => 'plug',
            'created_at'  => '2026-01-01 10:00:00',
        ]);
});

test('internal telematic controller describes activity logs and safe issue messages', function () {
    $controller = new FleetOpsTelematicControllerProbe();

    $created                 = new FleetOpsActivityLogFake();
    $created->fakeAttributes = [
        'uuid'       => 'activity-created',
        'event'      => 'created',
        'created_at' => '2026-01-01 11:00:00',
    ];
    $created->setRelation('causer', (object) ['name' => 'Ada Admin']);

    $deleted                 = new FleetOpsActivityLogFake();
    $deleted->fakeAttributes = [
        'uuid'       => null,
        'id'         => 99,
        'event'      => 'deleted',
        'created_at' => '2026-01-01 12:00:00',
        'causer'     => null,
    ];

    expect($controller->callHelper('makeActivityLogEntry', $created))->toMatchArray([
        'id'          => 'activity-created',
        'type'        => 'activity_created',
        'label'       => 'Provider connection created',
        'description' => 'Provider connection details were created.',
        'status'      => 'default',
        'icon'        => 'plus',
        'created_at'  => '2026-01-01 11:00:00',
        'actor_name'  => 'Ada Admin',
        'metadata'    => ['event' => 'created'],
    ])
        ->and($controller->callHelper('makeActivityLogEntry', $deleted))->toMatchArray([
            'id'          => 99,
            'type'        => 'activity_deleted',
            'label'       => 'Provider connection deleted',
            'description' => 'Provider connection details were removed.',
            'status'      => 'warning',
            'icon'        => 'history',
            'metadata'    => ['event' => 'deleted'],
        ])
        ->and($controller->callHelper('syncSuccessDescription', ['last_sync_total' => 3]))->toBe('3 provider devices were synced.')
        ->and($controller->callHelper('syncSuccessDescription', []))->toBe('Provider device sync completed successfully.')
        ->and($controller->callHelper('userFacingIssueMessage', 'Provider timed out', 'fallback'))->toBe('Provider timed out')
        ->and($controller->callHelper('userFacingIssueMessage', 'select * from users', 'fallback'))->toBe('fallback')
        ->and($controller->callHelper('isSensitiveIssueMessage', 'stack trace leaked'))->toBeTrue()
        ->and($controller->callHelper('isSensitiveIssueMessage', 'Provider rejected credentials'))->toBeFalse();
});

test('internal customer controller serializes portal login payloads', function () {
    $controller = new FleetOpsCustomerControllerProbe();
    $customer   = new Contact();
    $customer->setRawAttributes([
        'uuid'      => 'customer-uuid',
        'public_id' => 'customer-public',
        'user_uuid' => 'user-uuid',
    ], true);
    $customer->setRelation('user', (object) [
        'uuid'           => 'user-uuid',
        'public_id'      => 'user-public',
        'name'           => 'Ada Customer',
        'email'          => 'ada@example.test',
        'phone'          => '+15551234567',
        'status'         => 'active',
        'session_status' => 'online',
        'avatar_url'     => 'https://example.test/avatar.png',
    ]);

    expect($controller->callHelper('customerPayload', $customer))->toBe([
        'id'        => 'customer-public',
        'uuid'      => 'customer-uuid',
        'user_uuid' => 'user-uuid',
        'user'      => [
            'id'             => 'user-public',
            'uuid'           => 'user-uuid',
            'name'           => 'Ada Customer',
            'email'          => 'ada@example.test',
            'phone'          => '+15551234567',
            'status'         => 'active',
            'session_status' => 'online',
            'avatar_url'     => 'https://example.test/avatar.png',
        ],
    ]);
});
