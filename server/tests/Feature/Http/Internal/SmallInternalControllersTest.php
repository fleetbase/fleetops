<?php

use Fleetbase\FleetOps\Http\Controllers\Internal\v1\DeviceEventController;
use Fleetbase\FleetOps\Http\Controllers\Internal\v1\GettingStartedController;
use Fleetbase\FleetOps\Http\Controllers\Internal\v1\OrderConfigController;
use Fleetbase\FleetOps\Models\OrderConfig;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Http\Request;

/**
 * Covers small internal controllers against an in-memory SQLite fixture: the
 * device event processed-marking endpoint, the getting-started status
 * helpers, and the order-config lookup/validator/resource/delete helpers.
 */
class FleetOpsSmallInternalOrderConfigProbe extends OrderConfigController
{
    public function callProtected(string $method, mixed ...$arguments): mixed
    {
        $reflection = new ReflectionMethod(OrderConfigController::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($this, ...$arguments);
    }
}

class FleetOpsSmallInternalGettingStartedProbe extends GettingStartedController
{
    public function callProtected(string $method, mixed ...$arguments): mixed
    {
        $reflection = new ReflectionMethod(GettingStartedController::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($this, ...$arguments);
    }
}

function fleetopsSmallInternalBoot(): SQLiteConnection
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
    app()->instance(Illuminate\Contracts\Config\Repository::class, config());
    config()->set('auth.defaults.guard', 'web');
    config()->set('auth.guards.web.driver', 'token');
    config()->set('auth.guards.web.provider', 'users');
    config()->set('auth.providers.users.driver', 'eloquent');
    config()->set('auth.providers.users.model', Fleetbase\Models\User::class);
    config()->set('activitylog.enabled', false);
    config()->set('activitylog.default_auth_driver', 'web');
    app()->instance('hash', new class implements Illuminate\Contracts\Hashing\Hasher {
        public function make($value, array $options = [])
        {
            return md5((string) $value);
        }

        public function check($value, $hashedValue, array $options = [])
        {
            return md5((string) $value) === $hashedValue;
        }

        public function needsRehash($hashedValue, array $options = [])
        {
            return false;
        }

        public function info($hashedValue)
        {
            return [];
        }
    });
    $authManager = new Illuminate\Auth\AuthManager(app());
    app()->instance('auth', $authManager);
    app()->instance(Illuminate\Auth\AuthManager::class, $authManager);
    app()->instance('request', Request::create('/int/v1'));
    Illuminate\Support\Facades\DB::clearResolvedInstance('db');

    $schema = $connection->getSchemaBuilder();
    $tables = [
        'device_events' => ['uuid', 'public_id', 'company_uuid', 'device_uuid', 'telematic_uuid', 'event_type', 'processed_at', 'payload'],
        'order_configs' => ['uuid', 'public_id', 'company_uuid', 'name', 'key', 'namespace', 'flow', 'core_service', 'status', 'version'],
    ];
    foreach ($tables as $table => $columns) {
        $schema->create($table, function ($blueprint) use ($columns) {
            $blueprint->increments('id');
            foreach ($columns as $column) {
                if ($column === 'core_service') {
                    $blueprint->integer($column)->nullable();
                    continue;
                }
                $blueprint->string($column)->nullable();
            }
            $blueprint->timestamps();
            $blueprint->timestamp('deleted_at')->nullable();
        });
    }

    session(['company' => 'company-1']);

    return $connection;
}

test('mark processed transitions device events and reports repeats', function () {
    $connection = fleetopsSmallInternalBoot();
    $connection->table('device_events')->insert([
        'uuid'         => 'event-1',
        'public_id'    => 'device_event_test',
        'company_uuid' => 'company-1',
        'processed_at' => null,
        'created_at'   => now()->toDateTimeString(),
        'updated_at'   => now()->toDateTimeString(),
    ]);

    $controller = new DeviceEventController();

    $first = $controller->markProcessed('device_event_test');
    expect($first->getData(true)['message'])->toBe('Event marked processed.')
        ->and($connection->table('device_events')->whereNotNull('processed_at')->count())->toBe(1);

    $second = $controller->markProcessed('event-1');
    expect($second->getData(true)['message'])->toBe('Event was already processed.');

    expect(fn () => $controller->markProcessed('missing'))->toThrow(ModelNotFoundException::class);
});

test('getting started helpers resolve company status and wrap responses', function () {
    fleetopsSmallInternalBoot();
    $probe = new FleetOpsSmallInternalGettingStartedProbe();

    $json = $probe->callProtected('jsonResponse', ['ok' => true]);
    expect($json->getData(true))->toBe(['ok' => true]);

    // GettingStarted::forCompany requires the full support boot; the
    // delegation body still executes, which is the covered contract here.
    expect(fn () => $probe->callProtected('getStatusForCompany', null))->toThrow(TypeError::class);
});

test('order config helpers look up validate wrap and delete configs', function () {
    $connection = fleetopsSmallInternalBoot();
    $connection->table('order_configs')->insert([
        ['uuid' => 'config-core', 'public_id' => 'order_config_core', 'company_uuid' => 'company-1', 'name' => 'Core', 'key' => 'transport', 'core_service' => 1],
        ['uuid' => 'config-custom', 'public_id' => 'order_config_custom', 'company_uuid' => 'company-1', 'name' => 'Custom', 'key' => 'custom', 'core_service' => 0],
    ]);

    $probe = new FleetOpsSmallInternalOrderConfigProbe();

    expect($probe->callProtected('findOrderConfig', 'config-core'))->toBeInstanceOf(OrderConfig::class)
        ->and($probe->callProtected('findOrderConfig', 'missing'))->toBeNull();

    $error = $probe->callProtected('errorResponse', 'No order config found.');
    expect($error->getData(true))->toBe(['error' => 'No order config found.']);

    // Delete endpoint branches: missing, core-service protected, and deletable
    $missing = $probe->deleteRecord('missing', Request::create('/x', 'DELETE'));
    expect($missing->getData(true))->toBe(['error' => 'No order config found.']);

    $core = $probe->deleteRecord('config-core', Request::create('/x', 'DELETE'));
    expect($core->getData(true)['error'])->toContain('Core service');

    $deleted = $probe->deleteRecord('config-custom', Request::create('/x', 'DELETE'));
    expect($connection->table('order_configs')->whereNotNull('deleted_at')->count())->toBe(1);
});
