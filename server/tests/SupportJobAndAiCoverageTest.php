<?php

use Fleetbase\Ai\Models\AiTask;
use Fleetbase\FleetOps\Jobs\SyncTelematicDevicesJob;
use Fleetbase\FleetOps\Models\IntegratedVendor;
use Fleetbase\FleetOps\Models\Telematic;
use Fleetbase\FleetOps\Support\Ai\Capabilities\SearchResourcesCapability;
use Fleetbase\FleetOps\Support\IntegratedVendors;
use Fleetbase\FleetOps\Support\ResolvedIntegratedVendor;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Carbon;

class FleetOpsSyncTelematicJobFake extends Telematic
{
    public bool $refreshed = false;
    public bool $saved     = false;

    public function refresh()
    {
        $this->refreshed = true;

        return $this;
    }

    public function save(array $options = [])
    {
        $this->saved = true;

        return true;
    }
}

class FleetOpsSearchResourcesCapabilityFake extends SearchResourcesCapability
{
    public bool $allowed = false;

    public function promptMatches(string $prompt): bool
    {
        return $this->matchesPrompt($prompt);
    }

    public function termsFor(string $prompt): array
    {
        return $this->searchTerms($prompt);
    }

    public function genericWhenDenied(): array
    {
        return $this->generic(Telematic::class, 'fleet-ops see telematic', ['public_id'], 'route.name', ['needle']);
    }

    public function allResourceBranchesWhenDenied(array $terms): array
    {
        return [
            'orders'       => $this->orders($terms),
            'vehicles'     => $this->vehicles($terms),
            'drivers'      => $this->drivers($terms),
            'work_orders'  => $this->workOrders($terms),
            'maintenances' => $this->maintenances($terms),
            'devices'      => $this->devices($terms),
            'sensors'      => $this->sensors($terms),
            'telematics'   => $this->telematics($terms),
        ];
    }

    protected function can(string $permission): bool
    {
        return $this->allowed;
    }
}

function fleetopsInvokeSyncTelematicJob(SyncTelematicDevicesJob $job, string $method, array $arguments = []): mixed
{
    $reflection = new ReflectionMethod($job, $method);
    $reflection->setAccessible(true);

    return $reflection->invokeArgs($job, $arguments);
}

test('integrated vendors resolve supported provider details and bridge params', function () {
    $supported = IntegratedVendors::all();
    $resolver  = IntegratedVendors::find('LALAMOVE');

    $vendor = new IntegratedVendor();
    $vendor->forceFill([
        'provider'    => 'lalamove',
        'credentials' => [
            'api_key'    => 'key-test',
            'api_secret' => 'secret-test',
        ],
        'options' => [
            'market' => 'SG',
        ],
        'sandbox' => 'true',
    ]);

    $resolver->setIntegratedVendor($vendor);

    expect($supported)->toHaveCount(1)
        ->and($resolver)->toBeInstanceOf(ResolvedIntegratedVendor::class)
        ->and($resolver->getName())->toBe('Lalamove')
        ->and($resolver->setHost('https://override.test'))->toBe($resolver)
        ->and($resolver->host)->toBe('https://override.test')
        ->and($resolver->missing_property)->toBeNull()
        ->and($resolver->missingMethod())->toBeNull()
        ->and($resolver->resolveBridgeParams())->toBe([
            'apiKey'    => 'key-test',
            'apiSecret' => 'secret-test',
            'sandbox'   => true,
            'market'    => 'SG',
        ])
        ->and($resolver->resolveIntegratedVendorParams(['ignored' => 'credentials.missing']))->toBe([])
        ->and($resolver->setSvc_bridge(null)->getServiceTypes())->toBe([])
        ->and($resolver->getServiceBridgeInstance())->toBeNull()
        ->and($resolver->setIso2cc_bridge(null)->getCountries())->toBe([])
        ->and($resolver->geIso2ccBridgeInstance())->toBeNull()
        ->and($resolver->setBridge(null)->getBridgeInstance())->toBeNull()
        ->and($resolver->toArray())->toMatchArray([
            'name'      => 'Lalamove',
            'code'      => 'lalamove',
            'sandbox'   => 'https://rest.sandbox.lalamove.com/',
            'namespace' => 'v3',
        ])
        ->and(json_decode($resolver->toJson(), true))->toMatchArray([
            'name' => 'Lalamove',
            'code' => 'lalamove',
        ])
        ->and(IntegratedVendors::find(fn ($detail) => $detail->code === 'lalamove'))->toBeInstanceOf(ResolvedIntegratedVendor::class)
        ->and(IntegratedVendors::find(['not-supported']))->toBeNull()
        ->and(IntegratedVendors::resolverFromIntegratedVendor($vendor))->toBeInstanceOf(ResolvedIntegratedVendor::class);
});

test('sync telematic devices job exposes stable identifiers failure metadata and safe messages', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-25 12:00:00'));

    try {
        $telematic = new FleetOpsSyncTelematicJobFake();
        $telematic->setRawAttributes([
            'uuid'     => 'telematic_uuid_test',
            'provider' => 'safee',
            'status'   => 'active',
            'meta'     => ['existing' => 'kept'],
        ], true);

        $job = new SyncTelematicDevicesJob($telematic, ['limit' => 50], 'job-id-test');

        expect($job->getJobId())->toBe('job-id-test')
            ->and($job->options)->toBe(['limit' => 50])
            ->and(fleetopsInvokeSyncTelematicJob($job, 'resolveLinkedDeviceKey', [['device' => (object) ['uuid' => 'device-uuid']], []]))->toBe('device-uuid')
            ->and(fleetopsInvokeSyncTelematicJob($job, 'resolveLinkedDeviceKey', [['device' => (object) ['device_id' => 'device-id']], []]))->toBe('device-id')
            ->and(fleetopsInvokeSyncTelematicJob($job, 'resolveLinkedDeviceKey', [[], ['external_id' => 'external-id']]))->toBe('external-id')
            ->and(fleetopsInvokeSyncTelematicJob($job, 'resolveLinkedDeviceKey', [[], ['device_id' => '']]))->toBeNull()
            ->and(fleetopsInvokeSyncTelematicJob($job, 'safeSyncErrorMessage', [new RuntimeException('Provider rejected request')]))->toBe('Provider rejected request')
            ->and(fleetopsInvokeSyncTelematicJob($job, 'safeSyncErrorMessage', [new RuntimeException('token=secret-value')]))
            ->toBe('Device sync failed. Review the provider connection and safe sync metadata, then try again.')
            ->and(fleetopsInvokeSyncTelematicJob($job, 'safeSyncErrorMessage', [new RuntimeException('')]))
            ->toBe('Device sync failed. Review the provider connection and safe sync metadata, then try again.');

        $job->failed(new TimeoutExceededException('Queue timeout bearer secret'));

        expect($telematic->refreshed)->toBeTrue()
            ->and($telematic->saved)->toBeTrue()
            ->and($telematic->status)->toBe('error')
            ->and($telematic->meta)->toMatchArray([
                'existing'                 => 'kept',
                'last_sync_job_id'         => 'job-id-test',
                'last_sync_result'         => 'failed',
                'last_sync_error'          => 'Device sync failed. Review the provider connection and safe sync metadata, then try again.',
                'last_sync_error_type'     => 'TimeoutExceededException',
                'last_sync_failed_reason'  => 'job_timeout',
                'last_sync_failed_at'      => '2026-07-25 12:00:00',
            ]);
    } finally {
        Carbon::setTestNow();
    }
});

test('search resources capability metadata prompts terms and denied branches are stable', function () {
    $capability = new FleetOpsSearchResourcesCapabilityFake();
    $task       = new AiTask(['prompt' => 'Find driver DRV-123 and vehicle TRUCK_9']);

    expect($capability->key())->toBe('fleet-ops.search_resources')
        ->and($capability->label())->toBe('Search Fleet-Ops resources')
        ->and($capability->description())->toContain('Finds relevant Fleet-Ops')
        ->and($capability->permissions())->toContain('fleet-ops see order', 'fleet-ops see telematic')
        ->and($capability->module())->toBe('fleet-ops')
        ->and($capability->shouldResolve($task))->toBeTrue()
        ->and($capability->promptMatches('tell me about sensor SENSOR-1'))->toBeTrue()
        ->and($capability->promptMatches('compose a friendly email'))->toBeFalse()
        ->and($capability->termsFor('Find order ORDER-123 for driver driver_456'))->toBe(['ORDER-123', 'for', 'driver_456'])
        ->and($capability->termsFor('show order'))->toBe(['show order'])
        ->and($capability->genericWhenDenied())->toBe([])
        ->and($capability->allResourceBranchesWhenDenied(['needle']))->toBe([
            'orders'       => [],
            'vehicles'     => [],
            'drivers'      => [],
            'work_orders'  => [],
            'maintenances' => [],
            'devices'      => [],
            'sensors'      => [],
            'telematics'   => [],
        ])
        ->and($capability->resolve($task))->toBe([
            'query_terms' => ['DRV-123', 'and', 'TRUCK_9'],
            'results'     => [],
        ]);
});
