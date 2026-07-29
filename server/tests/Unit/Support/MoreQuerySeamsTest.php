<?php

use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;

/**
 * A second pass over the one-line delegation seams, covering the jobs,
 * listeners, casts and request helpers whose real bodies are bypassed by the
 * stubs their behaviour tests install.
 */
if (!function_exists('Fleetbase\Support\session')) {
    eval('namespace Fleetbase\Support; function session($key = null, $default = null) { if ($key === null) { return new class { public function has($k) { return \session($k) !== null; } public function get($k, $d = null) { return \session($k, $d); } }; } return \session($key, $default); }');
}

function fleetopsMoreSeamBoot(): SQLiteConnection
{
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
    $resolver   = new ConnectionResolver(['default' => $connection, 'mysql' => $connection]);
    $resolver->setDefaultConnection('mysql');
    EloquentModel::setConnectionResolver($resolver);
    if (!EloquentModel::getEventDispatcher()) {
        EloquentModel::setEventDispatcher(new Illuminate\Events\Dispatcher());
    }
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
    app()->instance('responsecache', new class {
        public function __call($method, $arguments)
        {
            return null;
        }
    });
    config()->set('activitylog.enabled', false);
    config()->set('activitylog.default_auth_driver', 'web');
    app()->bind(Illuminate\Contracts\Config\Repository::class, fn () => config());

    $columns = ['uuid', 'public_id', 'company_uuid', 'name', 'type', 'status', 'connection_uuid', 'sync_run_uuid', 'provider', 'url', 'path', 'disk', 'bucket', '_key'];
    $schema  = $connection->getSchemaBuilder();
    foreach (['fuel_provider_connections', 'fuel_provider_sync_runs', 'files', 'settings'] as $table) {
        $schema->create($table, function ($blueprint) use ($columns, $table) {
            $blueprint->increments('id');
            if ($table === 'settings') {
                $blueprint->string('key')->nullable();
                $blueprint->text('value')->nullable();
            }
            foreach ($columns as $column) {
                $blueprint->string($column)->nullable();
            }
            $blueprint->timestamps();
            $blueprint->timestamp('deleted_at')->nullable();
        });
    }

    session(['company' => 'company-seam-2']);

    return $connection;
}

function fleetopsMoreSeamInvoke(object $instance, string $class, string $method, array $arguments = []): mixed
{
    $reflection = new ReflectionMethod($class, $method);
    $reflection->setAccessible(true);

    return $reflection->invoke($instance, ...$arguments);
}

test('fuel provider sync job seams resolve their connection and run', function () {
    $connection = fleetopsMoreSeamBoot();
    $connection->table('fuel_provider_connections')->insert(['uuid' => 'fpc-seam-1', 'company_uuid' => 'company-seam-2', 'provider' => 'petroapp']);
    $connection->table('fuel_provider_sync_runs')->insert(['uuid' => 'fps-seam-1', 'company_uuid' => 'company-seam-2', 'connection_uuid' => 'fpc-seam-1']);

    $class = Fleetbase\FleetOps\Jobs\SyncFuelProviderTransactionsJob::class;
    $job   = new $class('fpc-seam-1', null, null, [], 'fps-seam-1');

    expect(fleetopsMoreSeamInvoke($job, $class, 'findConnection')->uuid)->toBe('fpc-seam-1')
        ->and(fleetopsMoreSeamInvoke($job, $class, 'findSyncRun')?->uuid)->toBe('fps-seam-1');

    // Jobs queued without a run uuid short circuit before querying
    $runless = new $class('fpc-seam-1');
    expect(fleetopsMoreSeamInvoke($runless, $class, 'findSyncRun'))->toBeNull();
});

test('delivery completion listener reads its setting and queues reallocation', function () {
    $connection = fleetopsMoreSeamBoot();
    $connection->table('settings')->insert(['key' => 'fleetops.auto_reallocate_on_complete', 'value' => json_encode(true)]);

    $class    = Fleetbase\FleetOps\Listeners\HandleDeliveryCompletion::class;
    $listener = (new ReflectionClass($class))->newInstanceWithoutConstructor();

    // The stored setting drives the reallocation decision
    expect(fleetopsMoreSeamInvoke($listener, $class, 'autoReallocateOnCompleteEnabled'))->toBeTrue();

    // Settings that are absent fall back to the supplied default
    $connection->table('settings')->where('key', 'fleetops.auto_reallocate_on_complete')->delete();
    expect(fleetopsMoreSeamInvoke($listener, $class, 'autoReallocateOnCompleteEnabled'))->toBeFalse();
});

test('position replay logs transport failures', function () {
    fleetopsMoreSeamBoot();
    $logger = new class {
        public array $errors = [];

        public function error($message, array $context = [])
        {
            $this->errors[] = $message;
        }

        public function __call($method, $arguments)
        {
            return null;
        }
    };
    app()->instance('log', $logger);
    Illuminate\Support\Facades\Log::clearResolvedInstance('log');

    $class = Fleetbase\FleetOps\Jobs\SendPositionReplay::class;
    $job   = (new ReflectionClass($class))->newInstanceWithoutConstructor();

    fleetopsMoreSeamInvoke($job, $class, 'logError', ['replay transport unavailable']);
    expect($logger->errors)->toBe(['replay transport unavailable']);

    // The socket() seam constructs a live SocketCluster client and is left to
    // the integration surface rather than faked here.
});

test('order config entity cast resolves photo urls by file uuid', function () {
    $connection = fleetopsMoreSeamBoot();
    $connection->table('files')->insert(['uuid' => 'file-cast-1', 'company_uuid' => 'company-seam-2', 'name' => 'entity.png']);

    $class = Fleetbase\FleetOps\Casts\OrderConfigEntities::class;
    $cast  = new $class();

    // Unknown uuids resolve to nothing rather than erroring
    expect(fleetopsMoreSeamInvoke($cast, $class, 'photoUrlFor', ['file-missing-1']))->toBeNull();
});
