<?php

use Fleetbase\FleetOps\Support\Authorization;
use Fleetbase\FleetOps\Traits\AuthorizesMethods;
use Fleetbase\Models\User;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

if (!function_exists('Fleetbase\\Support\\auth')) {
    eval('namespace Fleetbase\\Support; function auth() { return \\app("fleetops.authorization.test-auth"); }');
}

if (!function_exists('Fleetbase\\Support\\session')) {
    eval('namespace Fleetbase\\Support; function session($key = null, $default = null) { return \\session($key, $default); }');
}

class FleetOpsAuthorizationUser extends User
{
    public array $grantedPermissions        = [];
    public bool $permissionStoreUnavailable = false;

    public function hasPermissionTo($permission, $guardName = null): bool
    {
        if ($this->permissionStoreUnavailable) {
            throw new RuntimeException('Permission store unavailable');
        }

        return in_array($permission->name, $this->grantedPermissions, true);
    }
}

beforeEach(function () {
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
    $resolver   = new ConnectionResolver(['mysql' => $connection]);
    $resolver->setDefaultConnection('mysql');
    Model::setConnectionResolver($resolver);
    config()->set('auth.defaults.guard', 'sanctum');
    config()->set('permission.table_names.permissions', 'permissions');
    $connection->getSchemaBuilder()->create('permissions', function ($table) {
        $table->string('id')->primary();
        $table->string('name');
        $table->string('guard_name')->default('sanctum');
    });
    $connection->table('permissions')->insert([
        ['id' => 'optimize', 'name' => 'fleet-ops optimize order'],
        ['id' => 'dispatch', 'name' => 'fleet-ops dispatch order'],
        ['id' => 'resource', 'name' => 'fleet-ops * order'],
        ['id' => 'service', 'name' => 'fleet-ops *'],
    ]);
    session(['user' => null]);
    $this->user = new FleetOpsAuthorizationUser();
    $this->user->setRawAttributes(['type' => 'user'], true);
    $this->auth = new class($this->user) {
        public bool $unavailable = false;

        public function __construct(public ?User $currentUser)
        {
        }

        public function user(): ?User
        {
            if ($this->unavailable) {
                throw new RuntimeException('Session unavailable');
            }

            return $this->currentUser;
        }
    };
    app()->instance('fleetops.authorization.test-auth', $this->auth);
});

test('operation authorization accepts exact and wildcard grants', function (string $grant) {
    $this->user->grantedPermissions = [$grant];

    expect(Authorization::canAny('optimize order'))->toBeTrue();
    Authorization::authorize('optimize order');
})->with(['fleet-ops optimize order', 'fleet-ops * order', 'fleet-ops *']);

test('operation authorization accepts any requested permission and rejects missing grants', function () {
    $this->user->grantedPermissions = ['fleet-ops dispatch order'];

    expect(Authorization::canAny('optimize order'))->toBeFalse()
        ->and(Authorization::canAny('optimize order', 'dispatch order'))->toBeTrue()
        ->and(Authorization::canAny('list unseeded-resource'))->toBeTrue();
    expect(fn () => Authorization::authorize('optimize order'))->toThrow(HttpResponseException::class);

    $this->user->permissionStoreUnavailable = true;
    expect(Authorization::canAny('dispatch order'))->toBeFalse();
});

test('platform administrators pass operations while system settings reject ordinary users', function () {
    expect(fn () => Authorization::authorizeAdmin())->toThrow(HttpResponseException::class);
    $this->user->setAttribute('type', 'admin');
    expect(Authorization::canAny('optimize order'))->toBeTrue();
    Authorization::authorizeAdmin();
});

test('authorization fails closed when the authenticated user is missing or cannot be read', function () {
    $this->auth->currentUser = null;
    expect(Authorization::canAny('optimize order'))->toBeFalse();
    expect(fn () => Authorization::authorizeAdmin())->toThrow(HttpResponseException::class);
    $this->auth->unavailable = true;
    expect(Authorization::canAny('optimize order'))->toBeFalse();
});

test('controller middleware applies the mapped operation and administrator checks', function () {
    $controller = new class extends Controller {
        use AuthorizesMethods;

        public function __construct()
        {
            $this->authorizeMethods(['optimize' => ['optimize order', 'dispatch order'], 'settings' => 'admin']);
        }
    };
    $middleware                     = $controller->getMiddleware();
    $request                        = Request::create('/');
    $this->user->grantedPermissions = ['fleet-ops dispatch order'];

    expect($middleware[0]['options']['only'])->toBe(['optimize'])
        ->and($middleware[0]['middleware']($request, fn ($nextRequest) => $nextRequest))->toBe($request);
    expect(fn () => $middleware[1]['middleware']($request, fn () => 'allowed'))->toThrow(HttpResponseException::class);
    $this->user->setAttribute('type', 'admin');
    expect($middleware[1]['middleware']($request, fn () => 'allowed'))->toBe('allowed');
});

test('analytics controllers enforce authorization before invoking their next middleware', function (string $class) {
    $connection = Model::getConnectionResolver()->connection();
    $connection->table('permissions')->insert(['id' => 'analytics', 'name' => 'fleet-ops view analytics']);
    $controller = new $class();
    $middleware = $controller->getMiddleware()[0]['middleware'];
    $request    = Request::create('/analytics');
    expect(fn () => $middleware($request, fn () => 'private analytics'))->toThrow(HttpResponseException::class);
    $this->user->grantedPermissions = ['fleet-ops view analytics'];
    expect($middleware($request, fn ($forwarded) => $forwarded))->toBe($request);
})->with([
    Fleetbase\FleetOps\Http\Controllers\Internal\v1\AnalyticsController::class,
    Fleetbase\FleetOps\Http\Controllers\Internal\v1\MetricsController::class,
]);
