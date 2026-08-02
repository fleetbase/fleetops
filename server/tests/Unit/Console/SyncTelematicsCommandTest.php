<?php

use Fleetbase\FleetOps\Console\Commands\SyncTelematics;
use Fleetbase\FleetOps\Contracts\TelematicProviderDescriptor;
use Fleetbase\FleetOps\Support\Telematics\TelematicProviderRegistry;
use Fleetbase\TestSupport\DispatchRecorder;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;

/**
 * Covers the fleetops:sync-telematics command with the process lock skipped:
 * pollable-provider filtering by request/discovery/webhook flags, the
 * no-provider early exit, and the chunked job queueing over active
 * telematics connections.
 */
class FleetOpsSyncTelematicsProbe extends SyncTelematics
{
    public array $messages = [];
    public array $options  = ['no-lock' => true, 'provider' => [], 'limit' => 500, 'exclude-webhook-providers' => false];

    public function info($string, $verbosity = null)
    {
        $this->messages[] = ['info', $string];
    }

    public function warn($string, $verbosity = null)
    {
        $this->messages[] = ['warn', $string];
    }

    public function option($key = null, $default = null)
    {
        return $this->options[$key] ?? $default;
    }
}

function fleetopsSyncTelematicsRegistry(array $descriptors): TelematicProviderRegistry
{
    $registry = new TelematicProviderRegistry();
    foreach ($descriptors as $descriptor) {
        $registry->register(new TelematicProviderDescriptor($descriptor));
    }

    return $registry;
}

function fleetopsSyncTelematicsBoot(): SQLiteConnection
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

    $schema = $connection->getSchemaBuilder();
    $schema->create('telematics', function ($blueprint) {
        $blueprint->increments('id');
        foreach (['uuid', 'public_id', 'company_uuid', 'provider', 'status', 'credentials', 'name'] as $column) {
            $blueprint->string($column)->nullable();
        }
        $blueprint->timestamps();
        $blueprint->timestamp('deleted_at')->nullable();
    });

    DispatchRecorder::$dispatched = [];

    return $connection;
}

test('sync telematics exits early without pollable providers', function () {
    fleetopsSyncTelematicsBoot();

    $command  = new FleetOpsSyncTelematicsProbe();
    $registry = fleetopsSyncTelematicsRegistry([
        ['key' => 'webhook-only', 'label' => 'Webhook Only', 'supports_discovery' => false],
    ]);

    expect($command->handle($registry))->toBe(0)
        ->and($command->messages)->toContain(['info', 'No pollable telematics providers found.']);
});

test('sync telematics queues jobs for active matching connections', function () {
    $connection = fleetopsSyncTelematicsBoot();
    $connection->table('telematics')->insert([
        ['uuid' => 'tm-1', 'company_uuid' => 'company-1', 'provider' => 'traccar', 'status' => 'active'],
        ['uuid' => 'tm-2', 'company_uuid' => 'company-1', 'provider' => 'traccar', 'status' => 'connected'],
        ['uuid' => 'tm-3', 'company_uuid' => 'company-1', 'provider' => 'traccar', 'status' => 'disabled'],
        ['uuid' => 'tm-4', 'company_uuid' => null, 'provider' => 'traccar', 'status' => 'active'],
        ['uuid' => 'tm-5', 'company_uuid' => 'company-1', 'provider' => 'other', 'status' => 'active'],
    ]);

    $command  = new FleetOpsSyncTelematicsProbe();
    $registry = fleetopsSyncTelematicsRegistry([
        ['key' => 'traccar', 'label' => 'Traccar', 'supports_discovery' => true],
    ]);

    expect($command->handle($registry))->toBe(0)
        ->and(DispatchRecorder::$dispatched)->toHaveCount(2)
        ->and($command->messages)->toContain(['info', 'Queued 2 telematics sync job(s).']);
});

test('sync telematics filters providers by request and webhook flags', function () {
    fleetopsSyncTelematicsBoot();

    $command          = new FleetOpsSyncTelematicsProbe();
    $command->options = array_merge($command->options, [
        'provider'                  => ['traccar'],
        'exclude-webhook-providers' => true,
    ]);

    $registry = fleetopsSyncTelematicsRegistry([
        ['key' => 'traccar', 'label' => 'Traccar', 'supports_discovery' => true, 'supports_webhooks' => true],
        ['key' => 'samsara', 'label' => 'Samsara', 'supports_discovery' => true],
    ]);

    // traccar is requested but excluded for webhook support; samsara is
    // pollable but not requested — nothing remains.
    expect($command->handle($registry))->toBe(0)
        ->and($command->messages)->toContain(['info', 'No pollable telematics providers found.']);
});
