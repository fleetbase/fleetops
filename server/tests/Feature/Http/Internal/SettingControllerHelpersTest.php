<?php

use Fleetbase\FleetOps\Http\Controllers\Internal\v1\SettingController;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;

/**
 * Covers the SettingController protected helper bodies: global and
 * company-scoped setting configuration and lookups, the current company
 * accessor, notification registry reads, tracking providers and the
 * google maps api key fallback.
 */
if (!function_exists('Fleetbase\Support\session')) {
    eval('namespace Fleetbase\Support; function session($key = null, $default = null) { if ($key === null) { return new class { public function has($k) { return \session($k) !== null; } public function get($k, $d = null) { return \session($k, $d); } public function missing($k) { return \session($k) === null; } }; } return \session($key, $default); }');
}

if (!class_exists('PhpOption\\Option', false)) {
    eval('namespace PhpOption; abstract class Option { public static function fromValue($value, $noneValue = null) { return $value === $noneValue ? None::create() : new Some($value); } abstract public function getOrCall($callable); abstract public function map($callable); abstract public function filter($callable); } class Some extends Option { public function __construct(private mixed $value) {} public function getOrCall($callable) { return $this->value; } public function map($callable) { return new Some($callable($this->value)); } public function filter($callable) { return $callable($this->value) ? $this : None::create(); } } class None extends Option { public static function create() { return new self(); } public function getOrCall($callable) { return $callable(); } public function map($callable) { return $this; } public function filter($callable) { return $this; } }');
}

if (!class_exists('Dotenv\\Repository\\RepositoryBuilder', false)) {
    eval('namespace Dotenv\\Repository; class RepositoryBuilder { public static function createWithDefaultAdapters() { return new self(); } public function addAdapter($adapter) { return $this; } public function immutable() { return $this; } public function make() { return new class { public function get($key) { $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key); return $value === false ? null : $value; } public function has($key) { return $this->get($key) !== null; } }; } }');
}

if (!function_exists('Fleetbase\Models\cache')) {
    eval('namespace Fleetbase\Models; function cache($key = null, $default = null) { return new class { public function forget($k) { return true; } public function get($k, $d = null) { return $d; } public function put($k, $v = null, $ttl = null) { return true; } public function remember($k, $ttl, $cb) { return $cb(); } public function __call($m, $a) { return null; } }; }');
}

if (!function_exists('Fleetbase\Models\session')) {
    eval('namespace Fleetbase\Models; function session($key = null, $default = null) { if ($key === null) { return new class { public function has($k) { return \session($k) !== null; } public function get($k, $d = null) { return \session($k, $d); } public function missing($k) { return \session($k) === null; } }; } return \session($key, $default); }');
}

function fleetopsSettingHelpersBoot(): SQLiteConnection
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
    app()->instance('db.schema', $connection->getSchemaBuilder());
    Illuminate\Support\Facades\DB::clearResolvedInstance('db');

    $schema = $connection->getSchemaBuilder();
    $schema->create('settings', function ($blueprint) {
        $blueprint->increments('id');
        foreach (['uuid', 'key', 'value', '_key'] as $column) {
            $blueprint->string($column)->nullable();
        }
        $blueprint->timestamps();
        $blueprint->timestamp('deleted_at')->nullable();
    });
    $schema->create('companies', function ($blueprint) {
        $blueprint->increments('id');
        foreach (['uuid', 'public_id', 'name', 'country', 'options'] as $column) {
            $blueprint->string($column)->nullable();
        }
        $blueprint->timestamps();
        $blueprint->timestamp('deleted_at')->nullable();
    });

    session(['company' => 'company-1']);
    $connection->table('companies')->insert(['uuid' => 'company-1', 'name' => 'Acme']);

    return $connection;
}

test('setting helpers configure and look up global and company settings', function () {
    $connection = fleetopsSettingHelpersBoot();

    $controller = new SettingController();
    $helper     = function (string $method, ...$arguments) use ($controller) {
        $reflection = new ReflectionMethod(SettingController::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($controller, ...$arguments);
    };

    // Global setting configure + lookups
    $helper('configureSetting', 'fleet-ops.test-flag', 'enabled');
    expect($connection->table('settings')->where('key', 'fleet-ops.test-flag')->count())->toBe(1)
        ->and($helper('lookupSetting', 'fleet-ops.test-flag'))->toBe('enabled')
        ->and($helper('lookupSetting', 'fleet-ops.missing', 'fallback'))->toBe('fallback')
        ->and($helper('settingValue', 'fleet-ops.test-flag'))->not->toBeNull();

    // Company-scoped configure + lookups
    $helper('configureCompanySetting', 'dispatch.window', '30');
    expect($helper('lookupFromCompanySetting', 'dispatch.window'))->toBe('30')
        ->and($helper('lookupCompanySetting', 'dispatch.window'))->toBe('30')
        ->and($helper('lookupCompanySetting', 'dispatch.missing', 'none'))->toBe('none');

    // Current company resolves through the session
    $company = $helper('currentCompany');
    expect($company)->not->toBeNull()
        ->and($company->uuid)->toBe('company-1');

    // Google maps api key falls back through config and env
    config()->set('services.google_maps.api_key', 'maps-key-1');
    expect($helper('googleMapsApiKey'))->toBe('maps-key-1');
});

test('setting helpers read notification and tracking registries', function () {
    fleetopsSettingHelpersBoot();

    $controller = new SettingController();
    $helper     = function (string $method, ...$arguments) use ($controller) {
        $reflection = new ReflectionMethod(SettingController::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($controller, ...$arguments);
    };

    expect($helper('notificationNotifiables'))->toBeArray()
        ->and($helper('notificationsByPackage', 'fleet-ops'))->toBeArray()
        ->and($helper('trackingProviders'))->toBeArray();
});
