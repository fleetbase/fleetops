<?php

use Fleetbase\FleetOps\Console\Commands\FixLegacyOrderConfigs;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;

/**
 * Covers the real protected helper bodies on FixLegacyOrderConfigs:
 * companies(), createTransportConfig(), ordersWithoutConfig(),
 * transportConfigForCompany(), and createProgressBar().
 */
if (!EloquentModel::getEventDispatcher()) {
    EloquentModel::setEventDispatcher(new Illuminate\Events\Dispatcher());
}

test('legacy order config command helpers query and create through the real bodies', function () {
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
    app()->instance('responsecache', new class {
        public function __call($method, $arguments)
        {
            return null;
        }
    });
    config()->set('activitylog.enabled', false);
    config()->set('activitylog.default_auth_driver', 'web');
    app()->bind(Illuminate\Contracts\Config\Repository::class, fn () => config());

    $schema = $connection->getSchemaBuilder();
    $schema->create('companies', function ($blueprint) {
        $blueprint->increments('id');
        foreach (['uuid', 'public_id', 'name', 'country', 'options'] as $column) {
            $blueprint->string($column)->nullable();
        }
        $blueprint->timestamps();
        $blueprint->timestamp('deleted_at')->nullable();
    });
    $schema->create('order_configs', function ($blueprint) {
        $blueprint->increments('id');
        foreach (['uuid', 'public_id', 'company_uuid', 'created_by_uuid', 'author_uuid', 'category_uuid', 'icon_uuid', 'name', 'key', 'namespace', 'description', 'tags', 'flow', 'entities', 'meta', 'version', 'status', 'type', '_key'] as $column) {
            $blueprint->string($column)->nullable();
        }
        $blueprint->integer('core_service')->nullable();
        $blueprint->timestamps();
        $blueprint->timestamp('deleted_at')->nullable();
    });
    $schema->create('orders', function ($blueprint) {
        $blueprint->increments('id');
        foreach (['uuid', 'public_id', 'company_uuid', 'order_config_uuid', 'status', 'type', '_key'] as $column) {
            $blueprint->string($column)->nullable();
        }
        $blueprint->timestamps();
        $blueprint->timestamp('deleted_at')->nullable();
    });

    $connection->table('companies')->insert(['uuid' => 'company-legacy-1', 'name' => 'Legacy Co']);
    $connection->table('orders')->insert([
        ['uuid' => 'order-legacy-1', 'company_uuid' => 'company-legacy-1', 'order_config_uuid' => null],
        ['uuid' => 'order-legacy-2', 'company_uuid' => 'company-legacy-1', 'order_config_uuid' => 'config-existing'],
    ]);

    $command = new FixLegacyOrderConfigs();
    $invoke  = function (string $method, array $arguments = []) use ($command) {
        $reflection = new ReflectionMethod(FixLegacyOrderConfigs::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($command, ...$arguments);
    };

    $companies = $invoke('companies');
    expect($companies)->toHaveCount(1);

    $invoke('createTransportConfig', [$companies->first()]);
    expect($connection->table('order_configs')->where('namespace', 'system:order-config:transport')->count())->toBe(1);

    $config = $invoke('transportConfigForCompany', ['company-legacy-1']);
    expect($config)->toBeInstanceOf(Fleetbase\FleetOps\Models\OrderConfig::class)
        ->and($invoke('transportConfigForCompany', ['company-missing']))->toBeNull();

    $orders = $invoke('ordersWithoutConfig');
    expect($orders)->toHaveCount(1)
        ->and($orders->first()->uuid)->toBe('order-legacy-1');

    $command->setOutput(new Illuminate\Console\OutputStyle(
        new Symfony\Component\Console\Input\ArrayInput([]),
        new Symfony\Component\Console\Output\BufferedOutput()
    ));
    expect($invoke('createProgressBar', [3]))->toBeInstanceOf(Symfony\Component\Console\Helper\ProgressBar::class);
});
