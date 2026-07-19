<?php

use Fleetbase\FleetOps\Console\Commands\SyncTelematics;
use Fleetbase\FleetOps\Console\Commands\TestEmail;
use Fleetbase\FleetOps\Contracts\TelematicProviderDescriptor;
use Fleetbase\FleetOps\Support\Telematics\TelematicProviderRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

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
    $lock = Mockery::mock();
    $lock->shouldReceive('get')->once()->andReturnFalse();
    $lock->shouldNotReceive('release');

    Cache::shouldReceive('lock')
        ->once()
        ->with('fleetops:sync-telematics', 600)
        ->andReturn($lock);

    $registry = Mockery::mock(TelematicProviderRegistry::class);
    $registry->shouldNotReceive('all');

    $command = fleetOpsSyncTelematicsCommandWithOptions(['no-lock' => false]);

    expect($command->handle($registry))->toBe(Command::SUCCESS)
        ->and($command->messages)->toContain(['warn', 'Another telematics sync run appears to be in progress.']);
});

test('sync telematics reports no pollable providers after provider filtering', function () {
    $registry = Mockery::mock(TelematicProviderRegistry::class);
    $registry->shouldReceive('all')
        ->once()
        ->andReturn(collect([
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
    $registry = Mockery::mock(TelematicProviderRegistry::class);
    $registry->shouldReceive('all')
        ->once()
        ->andReturn(collect([
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
