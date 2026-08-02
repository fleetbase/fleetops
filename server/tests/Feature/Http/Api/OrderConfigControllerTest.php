<?php

use Fleetbase\FleetOps\Http\Controllers\Api\v1\OrderConfigController;
use Fleetbase\FleetOps\Http\Resources\v1\OrderConfig as OrderConfigResource;
use Fleetbase\FleetOps\Models\OrderConfig;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\SQLiteConnection;

/**
 * Covers the public API OrderConfigController helper implementations against
 * an in-memory SQLite fixture: identifier resolution, find-or-fail lookup,
 * resource wrapping, and the api error seam.
 */
class FleetOpsApiOrderConfigProbe extends OrderConfigController
{
    public function callProtected(string $method, mixed ...$arguments): mixed
    {
        $reflection = new ReflectionMethod(OrderConfigController::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($this, ...$arguments);
    }
}

function fleetopsApiOrderConfigBoot(): SQLiteConnection
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
    app()->instance('db.schema', $connection->getSchemaBuilder());
    Illuminate\Support\Facades\DB::clearResolvedInstance('db');

    $schema = $connection->getSchemaBuilder();
    $schema->create('order_configs', function ($table) {
        $table->increments('id');
        foreach (['uuid', 'public_id', 'company_uuid', 'name', 'key', 'namespace', 'flow', 'meta', 'entities', 'version'] as $column) {
            $table->string($column)->nullable();
        }
        $table->timestamps();
        $table->timestamp('deleted_at')->nullable();
    });

    session(['company' => 'company-1']);

    return $connection;
}

test('order config helpers resolve identifiers and wrap resources', function () {
    $connection = fleetopsApiOrderConfigBoot();
    $connection->table('order_configs')->insert([
        'uuid'         => '44444444-4444-4444-8444-444444444444',
        'public_id'    => 'order_config_test',
        'company_uuid' => 'company-1',
        'key'          => 'transport',
        'namespace'    => 'system:order-config:transport',
        'name'         => 'Transport',
    ]);

    $probe = new FleetOpsApiOrderConfigProbe();

    expect($probe->callProtected('resolveOrderConfig', 'transport'))->toBeInstanceOf(OrderConfig::class)
        ->and($probe->callProtected('resolveOrderConfig', 'missing-key'))->toBeNull();

    expect($probe->callProtected('findOrderConfigOrFail', 'order_config_test'))->toBeInstanceOf(OrderConfig::class);
    expect(fn () => $probe->callProtected('findOrderConfigOrFail', 'missing'))->toThrow(ModelNotFoundException::class);

    $config = OrderConfig::where('key', 'transport')->first();
    expect($probe->callProtected('orderConfigResource', $config))->toBeInstanceOf(OrderConfigResource::class)
        ->and($probe->callProtected('orderConfigCollection', [[$config]]))
        ->toBeInstanceOf(Illuminate\Http\Resources\Json\ResourceCollection::class);

    $error = $probe->callProtected('apiError', 'Order config not found.', 404);
    expect($error->getStatusCode())->toBe(404)
        ->and($error->getData(true))->toBe(['error' => 'Order config not found.']);
});

test('find endpoint resolves identifiers and reports missing configs', function () {
    $connection = fleetopsApiOrderConfigBoot();
    $connection->table('order_configs')->insert([
        'uuid'         => '44444444-4444-4444-8444-444444444444',
        'public_id'    => 'order_config_test',
        'company_uuid' => 'company-1',
        'key'          => 'transport',
        'namespace'    => 'system:order-config:transport',
        'name'         => 'Transport',
    ]);

    $controller = new OrderConfigController();

    expect($controller->find('transport'))->toBeInstanceOf(OrderConfigResource::class);

    $missing = $controller->find('missing');
    expect($missing->getStatusCode())->toBe(404);
});
