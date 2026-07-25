<?php

use Carbon\Carbon;
use Fleetbase\FleetOps\Console\Commands\ProcessMaintenanceTriggers;
use Fleetbase\FleetOps\Console\Commands\SyncTelematics;
use Fleetbase\FleetOps\Console\Commands\TestEmail;
use Fleetbase\FleetOps\Contracts\TelematicProviderDescriptor;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\FleetOps\Support\Telematics\TelematicProviderRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class FleetOpsCommandCacheFake
{
    public function __construct(private FleetOpsCommandLockFake $lock)
    {
    }

    public function lock($key, $seconds)
    {
        return $this->lock;
    }

    public function forget($key): bool
    {
        return true;
    }
}

class FleetOpsCommandLockFake
{
    public bool $released = false;

    public function __construct(private bool $locked)
    {
    }

    public function get(): bool
    {
        return $this->locked;
    }

    public function release(): void
    {
        $this->released = true;
    }
}

class FleetOpsTelematicProviderRegistryFake extends TelematicProviderRegistry
{
    public function __construct(Illuminate\Support\Collection $providers)
    {
        $this->providers = $providers;
    }

    public function all(): Illuminate\Support\Collection
    {
        return $this->providers;
    }
}

class FleetOpsProcessMaintenanceTriggersProbe extends ProcessMaintenanceTriggers
{
    public function callHelper(string $method, mixed ...$arguments): mixed
    {
        $reflection = new ReflectionMethod(ProcessMaintenanceTriggers::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($this, ...$arguments);
    }
}

function fleetOpsSyncTelematicsCommandWithOptions(array $options = []): SyncTelematics
{
    return new class($options) extends SyncTelematics {
        public array $messages = [];

        public function __construct(private array $testOptions)
        {
            parent::__construct();
        }

        public function option($key = null)
        {
            return $key === null ? $this->testOptions : ($this->testOptions[$key] ?? null);
        }

        public function info($string, $verbosity = null)
        {
            $this->messages[] = ['info', $string];
        }

        public function warn($string, $verbosity = null)
        {
            $this->messages[] = ['warn', $string];
        }
    };
}

test('sync telematics exits cleanly when another process holds the lock', function () {
    $lock = new FleetOpsCommandLockFake(false);
    Cache::swap(new FleetOpsCommandCacheFake($lock));

    $registry = new FleetOpsTelematicProviderRegistryFake(collect());
    $command  = fleetOpsSyncTelematicsCommandWithOptions(['no-lock' => false]);

    expect($command->handle($registry))->toBe(Command::SUCCESS)
        ->and($command->messages)->toContain(['warn', 'Another telematics sync run appears to be in progress.'])
        ->and($lock->released)->toBeFalse();
});

test('sync telematics reports no pollable providers after provider filtering', function () {
    $registry = new FleetOpsTelematicProviderRegistryFake(collect([
        'webhook' => new TelematicProviderDescriptor([
            'key'                => 'webhook',
            'label'              => 'Webhook Provider',
            'supports_webhooks'  => true,
            'supports_discovery' => true,
        ]),
        'manual' => new TelematicProviderDescriptor([
            'key'                => 'manual',
            'label'              => 'Manual Provider',
            'supports_discovery' => false,
        ]),
    ]));

    $command = fleetOpsSyncTelematicsCommandWithOptions([
        'no-lock'                   => true,
        'provider'                  => [],
        'exclude-webhook-providers' => true,
    ]);

    expect($command->handle($registry))->toBe(Command::SUCCESS)
        ->and($command->messages)->toContain(['info', 'No pollable telematics providers found.']);
});

test('sync telematics filters requested pollable providers', function () {
    $registry = new FleetOpsTelematicProviderRegistryFake(collect([
        'afaqy' => new TelematicProviderDescriptor([
            'key'                => 'afaqy',
            'label'              => 'Afaqy',
            'supports_discovery' => true,
        ]),
        'samsara' => new TelematicProviderDescriptor([
            'key'                => 'samsara',
            'label'              => 'Samsara',
            'supports_discovery' => true,
        ]),
        'webhook_only' => new TelematicProviderDescriptor([
            'key'                => 'webhook_only',
            'label'              => 'Webhook Only',
            'supports_webhooks'  => true,
            'supports_discovery' => true,
        ]),
    ]));

    $command = fleetOpsSyncTelematicsCommandWithOptions([
        'provider'                  => ['samsara', 'webhook_only'],
        'exclude-webhook-providers' => true,
    ]);

    $method = new ReflectionMethod($command, 'pollableProviderKeys');
    $method->setAccessible(true);

    expect($method->invoke($command, $registry))->toBe(['samsara']);
});

test('process maintenance triggers exposes deterministic command helpers', function () {
    Carbon::setTestNow(Carbon::parse('2026-02-03 04:05:06'));

    $command  = new FleetOpsProcessMaintenanceTriggersProbe();
    $schedule = (object) [
        'next_due_date'         => Carbon::parse('2026-02-01'),
        'next_due_odometer'     => 12000,
        'next_due_engine_hours' => 300,
    ];

    $vehicle               = new Vehicle();
    $vehicle->odometer     = 12500;
    $vehicle->engine_hours = 450;

    expect($command->callHelper('connectionName', true))->toBe('sandbox')
        ->and($command->callHelper('connectionName', false))->toBe('mysql')
        ->and($command->callHelper('currentReadingsFromSubject', $vehicle))->toBe([12500, 450])
        ->and($command->callHelper('currentReadingsFromSubject', new stdClass()))->toBe([null, null])
        ->and($command->callHelper('triggerReasons', $schedule, 12500, 450))->toBe([
            'date due 2026-02-01',
            'odometer 12500 >= 12000',
            'engine hours 450 >= 300',
        ])
        ->and($command->callHelper('triggerReasons', $schedule, 11000, 250))->toBe([
            'date due 2026-02-01',
        ])
        ->and($command->callHelper('workOrderCode', 7, Carbon::parse('2026-02-03')))->toBe('WO-20260203-0007')
        ->and($command->callHelper('processedSummary', 2, false))->toBe('Processed 2 schedule trigger(s).')
        ->and($command->callHelper('processedSummary', 2, true))->toBe('Processed 2 schedule trigger(s) (dry run — no work orders created)');

    Carbon::setTestNow();
});

test('test email command rejects unsupported email types before sending mail', function () {
    $command = new class extends TestEmail {
        public array $messages = [];

        public function argument($key = null)
        {
            $arguments = ['email' => 'customer@example.test'];

            return $key === null ? $arguments : ($arguments[$key] ?? null);
        }

        public function option($key = null)
        {
            $options = ['type' => 'unknown'];

            return $key === null ? $options : ($options[$key] ?? null);
        }

        public function info($string, $verbosity = null)
        {
            $this->messages[] = ['info', $string];
        }

        public function error($string, $verbosity = null)
        {
            $this->messages[] = ['error', $string];
        }
    };

    expect($command->handle())->toBe(Command::FAILURE)
        ->and($command->messages)->toContain(['error', 'Unknown email type: unknown']);
});
