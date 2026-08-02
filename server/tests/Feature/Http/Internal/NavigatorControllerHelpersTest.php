<?php

use Fleetbase\FleetOps\Http\Controllers\Internal\v1\NavigatorController;
use Fleetbase\Models\ApiCredential;
use Fleetbase\Models\Company;
use Fleetbase\Models\User;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;

/**
 * Covers the real bodies of the internal NavigatorController's protected
 * helper methods: admin lookup, navigator credential creation, deep-link
 * configuration values, redirects, api-credential token resolution,
 * organization lookup, and driver onboard settings.
 */
if (!function_exists('url')) {
    function url(?string $path = null)
    {
        if ($path === null) {
            return new class {
                public function secure(string $path = '/'): string
                {
                    return 'https://fleetbase.test' . $path;
                }
            };
        }

        return 'https://fleetbase.test/' . ltrim($path, '/');
    }
}

if (!function_exists('Fleetbase\FleetOps\Http\Controllers\Internal\v1\env')) {
    eval('namespace Fleetbase\FleetOps\Http\Controllers\Internal\v1; function env($key, $default = null) { return \FleetOpsNavigatorHelpersEnv::$values[$key] ?? $default; }');
}

class FleetOpsNavigatorHelpersEnv
{
    public static array $values = [];
}

class FleetOpsNavigatorHelpersRedirectFake
{
    public array $urls = [];

    public function away(string $url): string
    {
        $this->urls[] = $url;

        return 'redirected:' . $url;
    }
}

class FleetOpsInternalNavigatorHelpersProbe extends NavigatorController
{
    public function callProtected(string $method, array $arguments = []): mixed
    {
        $reflection = new ReflectionMethod(NavigatorController::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($this, ...$arguments);
    }
}

function fleetopsInternalNavigatorHelpersBoot(): SQLiteConnection
{
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
    $resolver   = new ConnectionResolver(['default' => $connection, 'mysql' => $connection, 'sandbox' => $connection]);
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
    $tables = [
        'users'           => ['uuid', 'public_id', 'company_uuid', 'type', 'name', 'email', 'phone'],
        'companies'       => ['uuid', 'public_id', 'name', 'owner_uuid'],
        'api_credentials' => ['uuid', 'public_id', 'user_uuid', 'company_uuid', 'name', 'key', 'secret', 'expires_at', 'last_used_at'],
        'settings'        => ['key', 'value'],
    ];
    foreach ($tables as $table => $columns) {
        $schema->create($table, function ($blueprint) use ($columns) {
            $blueprint->increments('id');
            foreach ($columns as $column) {
                $blueprint->string($column)->nullable();
            }
            $blueprint->timestamps();
            $blueprint->timestamp('deleted_at')->nullable();
        });
    }

    session(['company' => 'company-1']);

    return $connection;
}

test('admin lookup and navigator credential helpers execute against the database', function () {
    $connection = fleetopsInternalNavigatorHelpersBoot();
    $connection->table('companies')->insert(['uuid' => 'company-1', 'name' => 'Acme']);
    $connection->table('users')->insert(['uuid' => 'user-1', 'company_uuid' => 'company-1', 'type' => 'admin']);

    $probe = new FleetOpsInternalNavigatorHelpersProbe();

    $admin = $probe->callProtected('findAdminUser');
    expect($admin)->toBeInstanceOf(User::class)
        ->and($admin->uuid)->toBe('user-1');

    $credential = $probe->callProtected('firstOrCreateNavigatorCredential', [$admin]);
    expect($credential)->toBeInstanceOf(ApiCredential::class)
        ->and($connection->table('api_credentials')->count())->toBe(1)
        ->and($connection->table('api_credentials')->value('name'))->toBe('NavigationAppLinker');

    // Re-invocation reuses the existing credential
    $probe->callProtected('firstOrCreateNavigatorCredential', [$admin]);
    expect($connection->table('api_credentials')->count())->toBe(1);
});

test('deep link configuration helpers read url config and environment values', function () {
    fleetopsInternalNavigatorHelpersBoot();
    FleetOpsNavigatorHelpersEnv::$values = [
        'SOCKETCLUSTER_HOST'   => 'socket.fleetbase.test',
        'SOCKETCLUSTER_PORT'   => 38000,
        'SOCKETCLUSTER_SECURE' => 'true',
    ];

    $probe = new FleetOpsInternalNavigatorHelpersProbe();

    expect($probe->callProtected('secureRootUrl'))->toBe('https://fleetbase.test/')
        ->and($probe->callProtected('navigatorAppIdentifier'))->toBe('io.fleetbase.navigator')
        ->and($probe->callProtected('socketClusterHost'))->toBe('socket.fleetbase.test')
        ->and($probe->callProtected('socketClusterPort'))->toBe(38000)
        ->and($probe->callProtected('socketClusterSecure'))->toBeTrue();

    FleetOpsNavigatorHelpersEnv::$values = [];
    expect($probe->callProtected('socketClusterHost'))->toBe('socket')
        ->and($probe->callProtected('socketClusterPort'))->toBe(8000)
        ->and($probe->callProtected('socketClusterSecure'))->toBeFalse();
});

test('redirect away helper delegates to the redirector', function () {
    fleetopsInternalNavigatorHelpersBoot();
    $redirect = new FleetOpsNavigatorHelpersRedirectFake();
    app()->instance('redirect', $redirect);
    Illuminate\Support\Facades\Redirect::clearResolvedInstance('redirect');

    $result = (new FleetOpsInternalNavigatorHelpersProbe())->callProtected('redirectAway', ['flbnavigator://configure?key=abc']);

    expect($result)->toBe('redirected:flbnavigator://configure?key=abc')
        ->and($redirect->urls)->toBe(['flbnavigator://configure?key=abc']);
});

test('api credential token resolution finds keys and secrets', function () {
    $connection = fleetopsInternalNavigatorHelpersBoot();
    $connection->table('companies')->insert(['uuid' => 'company-1', 'name' => 'Acme']);
    $connection->table('api_credentials')->insert([
        'uuid'         => 'cred-1',
        'company_uuid' => 'company-1',
        'key'          => 'flb_live_abc',
        'secret'       => '$secret_abc',
    ]);

    $probe = new FleetOpsInternalNavigatorHelpersProbe();

    $byKey = $probe->callProtected('findApiCredentialForToken', ['flb_live_abc', 'mysql', false]);
    expect($byKey)->toBeInstanceOf(ApiCredential::class)
        ->and($byKey->uuid)->toBe('cred-1');

    $bySecret = $probe->callProtected('findApiCredentialForToken', ['$secret_abc', 'mysql', true]);
    expect($bySecret)->toBeInstanceOf(ApiCredential::class);

    expect($probe->callProtected('findApiCredentialForToken', ['missing', 'mysql', false]))->toBeNull();
});

test('organization and driver onboard settings helpers execute against the database', function () {
    $connection = fleetopsInternalNavigatorHelpersBoot();
    $connection->table('companies')->insert(['uuid' => 'company-1', 'name' => 'Acme']);
    $connection->table('settings')->insert(['key' => 'fleet-ops.driver-onboard', 'value' => json_encode(['enabled' => true])]);

    $probe = new FleetOpsInternalNavigatorHelpersProbe();

    $organization = $probe->callProtected('findOrganization', ['company-1']);
    expect($organization)->toBeInstanceOf(Company::class)
        ->and($organization->name)->toBe('Acme');

    $settings = $probe->callProtected('driverOnboardSettings');
    expect($settings)->not->toBeNull();
});
