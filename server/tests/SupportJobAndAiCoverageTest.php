<?php

use Fleetbase\Ai\Models\AiTask;
use Fleetbase\FleetOps\Console\Commands\FixInvalidPolymorphicRelationTypeNamespaces;
use Fleetbase\FleetOps\Console\Commands\SyncTelematics;
use Fleetbase\FleetOps\Console\Commands\TestEmail;
use Fleetbase\FleetOps\Contracts\TelematicProviderDescriptor;
use Fleetbase\FleetOps\Jobs\SyncTelematicDevicesJob;
use Fleetbase\FleetOps\Mail\CustomerCredentialsMail;
use Fleetbase\FleetOps\Models\IntegratedVendor;
use Fleetbase\FleetOps\Models\Telematic;
use Fleetbase\FleetOps\Support\Ai\Capabilities\SearchResourcesCapability;
use Fleetbase\FleetOps\Support\IntegratedVendors;
use Fleetbase\FleetOps\Support\ResolvedIntegratedVendor;
use Fleetbase\FleetOps\Support\Telematics\TelematicProviderRegistry;
use Fleetbase\FleetOps\Support\Telematics\TelematicService;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

class FleetOpsSyncTelematicJobFake extends Telematic
{
    public bool $refreshed  = false;
    public bool $saved      = false;
    public int $saveCount   = 0;

    public function refresh()
    {
        $this->refreshed = true;

        return $this;
    }

    public function save(array $options = [])
    {
        $this->saved = true;
        $this->saveCount++;

        return true;
    }
}

class FleetOpsTelematicSyncLockFake
{
    public int $releaseCount = 0;

    public function __construct(public bool $acquired = true)
    {
    }

    public function get(): bool
    {
        return $this->acquired;
    }

    public function release(): void
    {
        $this->releaseCount++;
    }
}

class FleetOpsTelematicSyncCacheFake
{
    public array $locks = [];

    public function __construct(public FleetOpsTelematicSyncLockFake $lock)
    {
    }

    public function lock(string $key, int $seconds): FleetOpsTelematicSyncLockFake
    {
        $this->locks[] = [$key, $seconds];

        return $this->lock;
    }
}

class FleetOpsTelematicSyncProviderFake implements Fleetbase\FleetOps\Contracts\TelematicProviderInterface
{
    public array $connections       = [];
    public array $fetchOptions      = [];
    public array $normalizedPayload = [];
    public ?Throwable $throwable    = null;

    public function __construct(public array $pages = [], public array $enrichment = [])
    {
    }

    public function connect(Telematic $telematic): void
    {
        $this->connections[] = $telematic;

        if ($this->throwable) {
            throw $this->throwable;
        }
    }

    public function testConnection(array $credentials): array
    {
        return ['success' => true, 'message' => 'ok', 'metadata' => []];
    }

    public function fetchDevices(array $options = []): array
    {
        $this->fetchOptions[] = $options;

        return array_shift($this->pages) ?? ['devices' => [], 'next_cursor' => null, 'has_more' => false];
    }

    public function fetchDeviceTelemetrySnapshots(array $devices, array $options = []): array
    {
        return $this->enrichment;
    }

    public function fetchDeviceDetails(string $externalId): array
    {
        return ['external_id' => $externalId];
    }

    public function normalizeDevice(array $payload): array
    {
        $normalized = [
            'device_id'   => $payload['device_id'] ?? null,
            'external_id' => $payload['_id'] ?? $payload['id'] ?? null,
            'name'        => $payload['name'] ?? null,
        ];

        $this->normalizedPayload[] = $normalized;

        return $normalized;
    }

    public function normalizeEvent(array $payload): array
    {
        return $payload;
    }

    public function normalizeSensor(array $payload): array
    {
        return $payload;
    }

    public function validateWebhookSignature(string $payload, string $signature, array $credentials): bool
    {
        return true;
    }

    public function processWebhook(array $payload, array $headers = []): array
    {
        return ['devices' => [], 'events' => [], 'sensors' => []];
    }

    public function getCredentialSchema(): array
    {
        return [];
    }

    public function supportsWebhooks(): bool
    {
        return true;
    }

    public function supportsDiscovery(): bool
    {
        return true;
    }

    public function getRateLimits(): array
    {
        return ['requests_per_minute' => 60, 'burst_size' => 10];
    }
}

class FleetOpsTelematicSyncRegistryFake extends TelematicProviderRegistry
{
    public array $resolved = [];

    public function __construct(public FleetOpsTelematicSyncProviderFake $provider)
    {
    }

    public function resolve(string $key): Fleetbase\FleetOps\Contracts\TelematicProviderInterface
    {
        $this->resolved[] = $key;

        return $this->provider;
    }
}

class FleetOpsTelematicSyncServiceFake extends TelematicService
{
    public array $ingested = [];

    public function __construct(public array $results = [], public array $failFor = [])
    {
    }

    public function ingestDeviceSnapshot(Telematic $telematic, Fleetbase\FleetOps\Contracts\TelematicProviderInterface $provider, array $payload): array
    {
        $this->ingested[] = $payload;

        if (in_array($payload['_id'] ?? $payload['id'] ?? null, $this->failFor, true)) {
            throw ValidationException::withMessages(['device_id' => ['missing provider identity']]);
        }

        return $this->results[$payload['_id'] ?? $payload['id'] ?? null] ?? [
            'device'  => (object) ['uuid' => $payload['device_uuid'] ?? null, 'device_id' => $payload['device_id'] ?? null],
            'events'  => [],
            'sensors' => 0,
        ];
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

class FleetOpsSyncTelematicsCommandFake extends SyncTelematics
{
    public array $options  = [];
    public array $messages = [];

    public function option($key = null)
    {
        return $key === null ? $this->options : ($this->options[$key] ?? null);
    }

    public function info($string, $verbosity = null): void
    {
        $this->messages[] = ['info', $string];
    }

    public function warn($string, $verbosity = null): void
    {
        $this->messages[] = ['warn', $string];
    }

    public function pollableForTest(TelematicProviderRegistry $registry): array
    {
        return $this->pollableProviderKeys($registry);
    }
}

class FleetOpsTestEmailCommandFake extends TestEmail
{
    public array $arguments = [];
    public array $options   = [];
    public array $messages  = [];

    public function argument($key = null)
    {
        return $key === null ? $this->arguments : ($this->arguments[$key] ?? null);
    }

    public function option($key = null)
    {
        return $key === null ? $this->options : ($this->options[$key] ?? null);
    }

    public function info($string, $verbosity = null): void
    {
        $this->messages[] = ['info', $string];
    }

    public function error($string, $verbosity = null): void
    {
        $this->messages[] = ['error', $string];
    }
}

class FleetOpsMailRecorder
{
    public ?string $recipient = null;
    public array $sent        = [];

    public function to(string $email): self
    {
        $this->recipient = $email;

        return $this;
    }

    public function send($mailable): void
    {
        $this->sent[] = [$this->recipient, $mailable];
    }
}

class FleetOpsPolymorphicRepairCommandFake extends FixInvalidPolymorphicRelationTypeNamespaces
{
    public array $messages = [];

    public function repairForTest(string $modelClass, array $columns): void
    {
        $this->fixModelRelations($modelClass, $columns);
    }

    public function info($string, $verbosity = null): void
    {
        $this->messages[] = ['info', $string];
    }

    public function alert($string, $verbosity = null): void
    {
        $this->messages[] = ['alert', $string];
    }
}

class FleetOpsPolymorphicRepairBuilderFake
{
    public array $columns = [];

    public function __construct(private array $records)
    {
    }

    public function orWhereNotNull(string $column): self
    {
        $this->columns[] = $column;

        return $this;
    }

    public function get()
    {
        return collect($this->records);
    }
}

class FleetOpsPolymorphicRepairRecordFake
{
    public static array $records                                     = [];
    public static ?FleetOpsPolymorphicRepairBuilderFake $lastBuilder = null;

    public bool $saved = false;

    private array $original = [];

    public function __construct(public int $id = 0, public string $public_id = '', public ?string $customer_type = null, public ?string $owner_type = null)
    {
        $this->original = [
            'customer_type' => $customer_type,
            'owner_type'    => $owner_type,
        ];
    }

    public static function query(): FleetOpsPolymorphicRepairBuilderFake
    {
        static::$lastBuilder = new FleetOpsPolymorphicRepairBuilderFake(static::$records);

        return static::$lastBuilder;
    }

    public function isDirty(): bool
    {
        return $this->customer_type !== $this->original['customer_type']
            || $this->owner_type !== $this->original['owner_type'];
    }

    public function saveQuietly(): void
    {
        $this->saved    = true;
        $this->original = [
            'customer_type' => $this->customer_type,
            'owner_type'    => $this->owner_type,
        ];
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

test('sync telematic devices job skips when another discovery lock is active', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-25 12:00:00'));
    $originalCache = Cache::getFacadeRoot();

    try {
        $telematic = new FleetOpsSyncTelematicJobFake();
        $telematic->setRawAttributes([
            'uuid'     => 'telematic-lock-test',
            'provider' => 'safee',
            'meta'     => ['existing' => 'kept'],
        ], true);

        $lock  = new FleetOpsTelematicSyncLockFake(false);
        $cache = new FleetOpsTelematicSyncCacheFake($lock);
        Cache::swap($cache);

        $job = new SyncTelematicDevicesJob($telematic, [], 'job-lock-test');
        $job->handle(
            new FleetOpsTelematicSyncRegistryFake(new FleetOpsTelematicSyncProviderFake()),
            new FleetOpsTelematicSyncServiceFake()
        );

        expect($telematic->saved)->toBeTrue()
            ->and($cache->locks)->toBe([['fleetops:sync-telematic-devices:telematic-lock-test', 3660]])
            ->and($telematic->meta)->toMatchArray([
                'existing'                  => 'kept',
                'last_sync_job_id'          => 'job-lock-test',
                'last_sync_result'          => 'skipped',
                'last_sync_skipped_reason'  => 'sync_already_running',
                'last_sync_skipped_at'      => '2026-07-25 12:00:00',
            ])
            ->and($lock->releaseCount)->toBe(0);
    } finally {
        Cache::swap($originalCache);
        Carbon::setTestNow();
    }
});

test('sync telematic devices job handles paginated inventory enrichment skips and failures', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-25 12:00:00'));
    $originalCache = Cache::getFacadeRoot();

    try {
        $telematic = new FleetOpsSyncTelematicJobFake();
        $telematic->setRawAttributes([
            'uuid'     => 'telematic-sync-test',
            'provider' => 'safee',
            'status'   => 'active',
            'meta'     => ['existing' => 'kept'],
        ], true);

        $provider = new FleetOpsTelematicSyncProviderFake([
            [
                'devices'    => [
                    ['_id' => 'provider-1', 'device_id' => 'device-1', 'name' => 'Truck One'],
                    ['_id' => 'missing-identity', 'name' => 'Broken Unit'],
                ],
                'pagination'  => ['allCount' => 4, 'filtersCount' => 3, 'limit' => 2],
                'sync_meta'   => ['provider_batch' => 'inventory'],
                'next_cursor' => 'cursor-two',
                'has_more'    => true,
            ],
            [
                'devices'    => [
                    ['_id' => 'provider-2', 'device_id' => 'device-2', 'name' => 'Truck Two'],
                ],
                'pagination'  => ['allCount' => 4, 'filtersCount' => 3],
                'next_cursor' => null,
                'has_more'    => false,
            ],
        ], [
            'devices'   => [
                ['_id' => 'provider-1', 'device_id' => 'device-1', 'name' => 'Truck One Enriched'],
                ['_id' => 'enrichment-missing', 'name' => 'Broken Enrichment'],
                ['_id' => 'provider-3', 'device_id' => 'device-3', 'name' => 'Truck Three'],
            ],
            'sync_meta' => ['provider_batch' => 'enrichment', 'enriched' => true],
        ]);

        $service = new FleetOpsTelematicSyncServiceFake([
            'provider-1' => ['device' => (object) ['uuid' => 'device-1'], 'events' => [['type' => 'ignition']], 'sensors' => 2],
            'provider-2' => ['device' => (object) ['uuid' => 'device-2'], 'event' => ['type' => 'position'], 'sensors' => 1],
            'provider-3' => ['device' => (object) ['uuid' => 'device-3'], 'events' => [], 'sensors' => 4],
        ], ['missing-identity', 'enrichment-missing']);

        $lock  = new FleetOpsTelematicSyncLockFake(true);
        $cache = new FleetOpsTelematicSyncCacheFake($lock);
        Cache::swap($cache);

        $job = new SyncTelematicDevicesJob($telematic, ['limit' => 2, 'filters' => ['status' => 'active']], 'job-sync-test');
        $job->handle(new FleetOpsTelematicSyncRegistryFake($provider), $service);

        expect($provider->fetchOptions)->toBe([
            ['limit' => 2, 'cursor' => null, 'filters' => ['status' => 'active']],
            ['limit' => 2, 'cursor' => 'cursor-two', 'filters' => ['status' => 'active']],
        ])
            ->and($cache->locks)->toBe([['fleetops:sync-telematic-devices:telematic-sync-test', 3660]])
            ->and($service->ingested)->toHaveCount(6)
            ->and($lock->releaseCount)->toBe(1)
            ->and($telematic->saved)->toBeTrue()
            ->and($telematic->saveCount)->toBe(2)
            ->and($telematic->status)->toBe('active')
            ->and($telematic->meta)->toMatchArray([
                'existing'                              => 'kept',
                'provider_batch'                        => 'enrichment',
                'enriched'                              => true,
                'last_sync_job_id'                      => 'job-sync-test',
                'last_sync_result'                      => 'success',
                'last_sync_total'                       => 3,
                'last_sync_fetched_total'               => 3,
                'last_sync_linked_total'                => 3,
                'last_sync_link_attempts_total'         => 4,
                'last_sync_inventory_total'             => 3,
                'last_sync_inventory_linked_total'      => 2,
                'last_sync_inventory_skipped_total'     => 1,
                'last_sync_enrichment_total'            => 3,
                'last_sync_enrichment_completed'        => 2,
                'last_sync_enrichment_failures'         => 1,
                'last_sync_events_total'                => 3,
                'last_sync_sensors_total'               => 9,
                'last_sync_skipped_total'               => 2,
                'last_sync_page_count'                  => 2,
                'last_sync_provider_total'              => 3,
                'last_sync_provider_all_count'          => 4,
                'last_sync_provider_filters_count'      => 3,
                'last_sync_completed_at'                => '2026-07-25 12:00:00',
                'last_sync_error'                       => null,
                'last_sync_error_context'               => null,
            ]);

        $failureTelematic = new FleetOpsSyncTelematicJobFake();
        $failureTelematic->setRawAttributes([
            'uuid'     => 'telematic-failure-test',
            'provider' => 'safee',
            'status'   => 'active',
            'meta'     => [],
        ], true);

        $failureProvider            = new FleetOpsTelematicSyncProviderFake();
        $failureProvider->throwable = new RuntimeException('client_secret leaked');
        $failureLock                = new FleetOpsTelematicSyncLockFake(true);
        $failureCache               = new FleetOpsTelematicSyncCacheFake($failureLock);
        Cache::swap($failureCache);

        $failureJob = new SyncTelematicDevicesJob($failureTelematic, [], 'job-failure-test');

        expect(fn () => $failureJob->handle(new FleetOpsTelematicSyncRegistryFake($failureProvider), new FleetOpsTelematicSyncServiceFake()))
            ->toThrow(RuntimeException::class, 'client_secret leaked')
            ->and($failureCache->locks)->toBe([['fleetops:sync-telematic-devices:telematic-failure-test', 3660]])
            ->and($failureLock->releaseCount)->toBe(1)
            ->and($failureTelematic->status)->toBe('error')
            ->and($failureTelematic->meta)->toMatchArray([
                'last_sync_job_id'              => 'job-failure-test',
                'last_sync_result'              => 'failed',
                'last_sync_fetched_total'       => 0,
                'last_sync_linked_total'        => 0,
                'last_sync_link_attempts_total' => 0,
                'last_sync_error'               => 'Device sync failed. Review the provider connection and safe sync metadata, then try again.',
                'last_sync_error_type'          => 'RuntimeException',
                'last_sync_failed_at'           => '2026-07-25 12:00:00',
            ]);
    } finally {
        Cache::swap($originalCache);
        Carbon::setTestNow();
    }
});

test('sync telematics command filters pollable providers and exits cleanly without work', function () {
    $registry = new TelematicProviderRegistry();
    $registry->register(new TelematicProviderDescriptor([
        'key'                => 'pollable_webhook',
        'label'              => 'Pollable Webhook',
        'supports_discovery' => true,
        'supports_webhooks'  => true,
    ]));
    $registry->register(new TelematicProviderDescriptor([
        'key'                => 'pollable_polling',
        'label'              => 'Pollable Polling',
        'supports_discovery' => true,
        'supports_webhooks'  => false,
    ]));
    $registry->register(new TelematicProviderDescriptor([
        'key'                => 'webhook_only',
        'label'              => 'Webhook Only',
        'supports_discovery' => false,
        'supports_webhooks'  => true,
    ]));

    $command = new FleetOpsSyncTelematicsCommandFake();

    $command->options = [
        'provider'                  => [],
        'exclude-webhook-providers' => false,
        'no-lock'                   => true,
    ];
    expect($command->pollableForTest($registry))->toBe(['pollable_webhook', 'pollable_polling']);

    $command->options = [
        'provider'                  => ['pollable_webhook', 'missing'],
        'exclude-webhook-providers' => false,
        'no-lock'                   => true,
    ];
    expect($command->pollableForTest($registry))->toBe(['pollable_webhook']);

    $command->options = [
        'provider'                  => [],
        'exclude-webhook-providers' => true,
        'no-lock'                   => true,
    ];
    expect($command->pollableForTest($registry))->toBe(['pollable_polling']);

    $emptyRegistry    = new TelematicProviderRegistry();
    $command->options = [
        'provider'                  => [],
        'exclude-webhook-providers' => false,
        'no-lock'                   => true,
        'limit'                     => 25,
    ];

    expect($command->handle($emptyRegistry))->toBe(SyncTelematics::SUCCESS)
        ->and($command->messages)->toContain(['info', 'No pollable telematics providers found.']);
});

test('test email command builds and sends customer credential mailables', function () {
    if (!class_exists('Illuminate\Foundation\Auth\User')) {
        eval('namespace Illuminate\Foundation\Auth; class User extends \Illuminate\Database\Eloquent\Model {}');
    }

    $mailer = new FleetOpsMailRecorder();
    Mail::swap($mailer);

    $command            = new FleetOpsTestEmailCommandFake();
    $command->arguments = ['email' => 'customer@example.test'];
    $command->options   = ['type' => 'customer_credentials'];

    expect($command->handle())->toBe(TestEmail::SUCCESS)
        ->and($command->messages)->toContain(
            ['info', 'Sending test email...'],
            ['info', 'Type: customer_credentials'],
            ['info', 'To: customer@example.test'],
            ['info', '✓ Test email sent successfully!'],
        )
        ->and($mailer->sent)->toHaveCount(1)
        ->and($mailer->sent[0][0])->toBe('customer@example.test')
        ->and($mailer->sent[0][1])->toBeInstanceOf(CustomerCredentialsMail::class);
});

test('polymorphic namespace repair command normalizes fleetbase model references', function () {
    $recordToRepair = new FleetOpsPolymorphicRepairRecordFake(7, 'record_public', '\\Fleetbase\\Models\\Contact', 'Fleetbase\\FleetOps\\Models\\Place');
    $recordToKeep   = new FleetOpsPolymorphicRepairRecordFake(8, 'record_clean', null, 'App\\Models\\Other');

    FleetOpsPolymorphicRepairRecordFake::$records = [$recordToRepair, $recordToKeep];

    $command = new FleetOpsPolymorphicRepairCommandFake();
    $command->repairForTest(FleetOpsPolymorphicRepairRecordFake::class, ['customer_type', 'owner_type']);

    expect(FleetOpsPolymorphicRepairRecordFake::$lastBuilder?->columns)->toBe(['customer_type', 'owner_type'])
        ->and($recordToRepair->customer_type)->toBe('Fleetbase\\FleetOps\\Models\\Contact')
        ->and($recordToRepair->owner_type)->toBe('Fleetbase\\FleetOps\\Models\\Place')
        ->and($recordToRepair->saved)->toBeTrue()
        ->and($recordToKeep->saved)->toBeFalse()
        ->and($command->messages)->toContain(
            ['info', 'Processing FleetOpsPolymorphicRepairRecordFake...'],
            ['alert', 'Checking 2 FleetOpsPolymorphicRepairRecordFakes for invalid polymorphic relation type namespaces.'],
            ['info', "FleetOpsPolymorphicRepairRecordFake ID 7: Corrected namespace from '\\Fleetbase\\Models\\Contact' to 'Fleetbase\\FleetOps\\Models\\Contact'."],
            ['info', 'Saved changes for FleetOpsPolymorphicRepairRecordFake ID record_public.'],
            ['info', 'Finished processing FleetOpsPolymorphicRepairRecordFake. Total updated records: 1.'],
        );
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
