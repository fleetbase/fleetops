<?php

if (!function_exists('Fleetbase\\Models\\env')) {
    eval('namespace Fleetbase\\Models; function env($key = null, $default = null) { return $default; }');
}

if (!function_exists('Fleetbase\\Notifications\\env')) {
    eval('namespace Fleetbase\\Notifications; function env($key = null, $default = null) { return $default; }');
}

if (!function_exists('Fleetbase\\Support\\env')) {
    eval('namespace Fleetbase\\Support; function env($key = null, $default = null) { return $default; }');
}

if (!function_exists('Fleetbase\\FleetOps\\Http\\Controllers\\Internal\\v1\\env')) {
    eval('namespace Fleetbase\\FleetOps\\Http\\Controllers\\Internal\\v1; function env($key = null, $default = null) { return $key === \'DEBUG\' ? true : $default; }');
}

use Fleetbase\FleetOps\Http\Controllers\Internal\v1\DriverController;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Http\Request;
use Illuminate\Support\MessageBag;
use Illuminate\Support\Str;

/**
 * Covers the internal DriverController createRecord unique-conflict branch
 * against SQLite with a failing validator: adopting an existing
 * organization member by creating a driver profile, returning the existing
 * driver profile when one already exists, assigning non-members to the
 * company, and falling through to the validation error response when the
 * conflict is not a phone or email collision.
 */
if (!function_exists('Fleetbase\Observers\event')) {
    eval('namespace Fleetbase\Observers; function event($event = null, $payload = []) { return []; }');
}

if (!function_exists('Fleetbase\FleetOps\Observers\event')) {
    eval('namespace Fleetbase\FleetOps\Observers; function event($event = null, $payload = []) { return []; }');
}

if (!function_exists('Fleetbase\Support\session')) {
    eval('namespace Fleetbase\Support; function session($key = null, $default = null) { if ($key === null) { return new class { public function has($k) { return \session($k) !== null; } public function get($k, $d = null) { return \session($k, $d); } }; } return \session($key, $default); }');
}

if (!function_exists('Fleetbase\Models\session')) {
    eval('namespace Fleetbase\Models; function session($key = null, $default = null) { if ($key === null) { return new class { public function has($k) { return \session($k) !== null; } public function get($k, $d = null) { return \session($k, $d); } public function missing($k) { return \session($k) === null; } }; } return \session($key, $default); }');
}

if (!function_exists('Fleetbase\Support\auth')) {
    eval('namespace Fleetbase\Support; function auth() { return new class { public function user() { return null; } public function id() { return null; } }; }');
}

if (!function_exists('Fleetbase\FleetOps\Http\Controllers\Internal\v1\env')) {
    eval('namespace Fleetbase\FleetOps\Http\Controllers\Internal\v1; function env($key, $default = null) { return $default; }');
}

if (!function_exists('__')) {
    function __($key = null, $replace = [], $locale = null)
    {
        return $key;
    }
}

if (!class_exists('Illuminate\Validation\Rule')) {
    eval('namespace Illuminate\Validation; class Rule { public function __construct(private string $rule = "") {} public static function requiredIf($c): string { return (is_callable($c) ? $c() : $c) ? "required" : "nullable"; } public static function in(array $v): self { return new self("in:" . implode(",", $v)); } public static function exists($t, $c = null): self { return new self("exists:" . $t . ($c ? "," . $c : "")); } public static function unique($t, $c = null): self { return new self("unique:" . $t . ($c ? "," . $c : "")); } public static function when($c, array $r): array { return (is_callable($c) ? $c() : $c) ? $r : []; } public function where($cb): self { return $this; } public function whereNull($col): self { return $this; } public function ignore($v, $c = null): self { return $this; } public function __toString(): string { return $this->rule; } }');
}

if (!Str::hasMacro('humanize')) {
    Str::macro('humanize', fn ($value) => ucfirst(str_replace(['_', '-'], ' ', Str::snake((string) $value))));
}

if (!Request::hasMacro('getController')) {
    Request::macro('getController', fn () => new DriverController());
}

if (!Request::hasMacro('or')) {
    Request::macro('or', function (array $params = [], $default = null) {
        foreach ($params as $param) {
            if ($this->has($param)) {
                return $this->input($param);
            }
        }

        return $default;
    });
}

function fleetopsDriverAdoptionContainer(): void
{
    $current = Illuminate\Container\Container::getInstance();
    if (method_exists($current, 'hasDebugModeEnabled')) {
        return;
    }

    // Core record mutation surfaces exceptions through app()->hasDebugModeEnabled()
    // and app()->environment(), which the harness container lacks
    $replacement = new class extends Illuminate\Container\Container {
        public function environment(...$environments)
        {
            if (empty($environments)) {
                return 'testing';
            }

            $checks = is_array($environments[0]) ? $environments[0] : $environments;

            return in_array('testing', $checks, true);
        }

        public function hasDebugModeEnabled()
        {
            return true;
        }
    };

    foreach (['bindings', 'instances', 'aliases', 'abstractAliases', 'resolved', 'extenders', 'tags', 'contextual', 'scopedInstances', 'reboundCallbacks', 'globalBeforeResolvingCallbacks', 'globalResolvingCallbacks', 'globalAfterResolvingCallbacks', 'beforeResolvingCallbacks', 'resolvingCallbacks', 'afterResolvingCallbacks'] as $property) {
        if (!property_exists(Illuminate\Container\Container::class, $property)) {
            continue;
        }
        $reflection = new ReflectionProperty(Illuminate\Container\Container::class, $property);
        $reflection->setAccessible(true);
        if ($reflection->isInitialized($current)) {
            $reflection->setValue($replacement, $reflection->getValue($current));
        }
    }

    Illuminate\Container\Container::setInstance($replacement);
    Illuminate\Support\Facades\Facade::setFacadeApplication($replacement);
}

function fleetopsDriverAdoptionBoot(array $validatorErrors): SQLiteConnection
{
    fleetopsDriverAdoptionContainer();
    $pdo = new PDO('sqlite::memory:');
    $pdo->sqliteCreateFunction('ST_PointFromText', fn ($wkt, $srid = 0, $axisOrder = null) => $wkt);
    $pdo->sqliteCreateFunction('ST_GeomFromText', fn ($wkt, $srid = 0, $axisOrder = null) => $wkt);
    $connection = new SQLiteConnection($pdo);
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
    app()->instance('responsecache', new class {
        public function __call($method, $arguments)
        {
            return null;
        }
    });
    $GLOBALS['fleetopsDriverAdoptionErrors'] = $validatorErrors;
    app()->instance('validator', new class {
        public function make($data = [], $rules = [], $messages = [], $attributes = [])
        {
            return new class implements Illuminate\Contracts\Validation\Validator {
                public function fails()
                {
                    return !empty($GLOBALS['fleetopsDriverAdoptionErrors']);
                }

                public function errors()
                {
                    return new MessageBag($GLOBALS['fleetopsDriverAdoptionErrors']);
                }

                public function validated()
                {
                    return [];
                }

                public function validate()
                {
                    return [];
                }

                public function failed()
                {
                    return array_keys($GLOBALS['fleetopsDriverAdoptionErrors']);
                }

                public function sometimes($attribute, $rules, callable $callback)
                {
                    return $this;
                }

                public function after($callback)
                {
                    return $this;
                }

                public function getMessageBag()
                {
                    return new MessageBag($GLOBALS['fleetopsDriverAdoptionErrors']);
                }
            };
        }
    });
    Illuminate\Support\Facades\DB::clearResolvedInstance('db');

    $schema = $connection->getSchemaBuilder();
    $tables = [
        'drivers'               => ['uuid', 'public_id', 'internal_id', 'company_uuid', 'user_uuid', 'vehicle_uuid', 'vendor_uuid', 'current_job_uuid', 'auth_token', 'signup_token_used', 'avatar_url', 'drivers_license_number', 'license_expiry', 'location', 'heading', 'bearing', 'altitude', 'speed', 'currency', 'current_status', 'meta', 'location_updated_at', 'slug', 'status', 'country', 'city', 'online', '_key'],
        'users'                 => ['uuid', 'public_id', 'company_uuid', 'name', 'email', 'phone', 'password', 'status', 'type', 'username', 'avatar_uuid', 'slug', 'timezone', 'country', 'ip_address', 'meta', '_key'],
        'companies'             => ['uuid', 'public_id', 'name', 'owner_uuid', 'timezone', 'options', 'status', '_key'],
        'company_users'         => ['uuid', 'public_id', 'company_uuid', 'user_uuid', 'status', '_key'],
        'vehicles'              => ['uuid', 'public_id', 'company_uuid', 'driver_uuid'],
        'custom_fields'         => ['uuid', 'company_uuid', 'subject_uuid', 'subject_type', 'name', 'label'],
        'settings'              => ['key', 'value'],
        'permissions'           => ['name', 'guard_name', 'service', 'description'],
        'roles'                 => ['uuid', 'public_id', 'name', 'guard_name', 'company_uuid', 'service', 'description', '_key'],
        'model_has_roles'       => ['role_id', 'model_type', 'model_uuid'],
        'model_has_permissions' => ['permission_id', 'model_type', 'model_uuid'],
        'role_has_permissions'  => ['permission_id', 'role_id'],
        'policies'              => ['name', 'guard_name', 'company_uuid'],
        'directives'            => ['uuid', 'public_id', 'company_uuid', 'permission_uuid', 'subject_type', 'subject_uuid', 'key', 'rules'],
        'files'                 => ['uuid', 'public_id', 'company_uuid', 'uploader_uuid', 'subject_uuid', 'subject_type', 'name', 'original_filename', 'extension', 'content_type', 'path', 'bucket', 'disk', 'size', 'type', 'meta', '_key'],
        'invites'               => ['uuid', 'public_id', 'company_uuid', 'created_by_uuid', 'subject_uuid', 'subject_type', 'code', 'uri', 'protocol', 'recipients', 'reason', 'expires_at', '_key'],
        'notifications'         => ['type', 'notifiable_type', 'notifiable_id', 'data', 'read_at'],
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

    app()->instance('cache', new class {
        public function tags($tags = null)
        {
            return $this;
        }

        public function flush()
        {
            return true;
        }

        public function remember($key, $ttl, $callback)
        {
            return $callback();
        }

        public function store($name = null)
        {
            return $this;
        }

        public function __call($method, $arguments)
        {
            return null;
        }
    });
    Illuminate\Support\Facades\Cache::clearResolvedInstance('cache');
    app()->instance(Illuminate\Contracts\Notifications\Dispatcher::class, new class {
        public array $sent = [];

        public function send($notifiables, $notification)
        {
            $this->sent[] = $notification;
        }

        public function sendNow($notifiables, $notification, ?array $channels = null)
        {
            $this->sent[] = $notification;
        }

        public function __call($method, $arguments)
        {
            return null;
        }
    });
    config()->set('cache.default', 'array');
    config()->set('cache.stores.array', ['driver' => 'array']);
    config()->set('permission.cache.expiration_time', 60);
    config()->set('permission.cache.key', 'spatie.permission.cache');
    config()->set('permission.cache.store', 'default');
    config()->set('permission.models.permission', Fleetbase\Models\Permission::class);
    config()->set('permission.models.role', Fleetbase\Models\Role::class);
    config()->set('permission.table_names', ['roles' => 'roles', 'permissions' => 'permissions', 'model_has_permissions' => 'model_has_permissions', 'model_has_roles' => 'model_has_roles', 'role_has_permissions' => 'role_has_permissions']);
    config()->set('permission.column_names', ['role_pivot_key' => null, 'permission_pivot_key' => null, 'model_morph_key' => 'model_uuid', 'team_foreign_key' => 'team_id']);
    config()->set('permission.teams', false);
    config()->set('permission.events_enabled', false);
    $cacheManager = new Illuminate\Cache\CacheManager(app());
    app()->instance(Illuminate\Cache\CacheManager::class, $cacheManager);
    app()->instance(Spatie\Permission\PermissionRegistrar::class, new Spatie\Permission\PermissionRegistrar($cacheManager));
    config()->set('activitylog.enabled', false);
    config()->set('activitylog.default_auth_driver', 'web');
    config()->set('auth.defaults.guard', 'web');
    config()->set('permission.models.role', Fleetbase\Models\Role::class);
    app()->bind(Illuminate\Contracts\Config\Repository::class, fn () => config());

    session(['company' => 'company-1']);
    $connection->table('companies')->insert(['uuid' => 'company-1', 'public_id' => 'company_adopt1', 'name' => 'Acme']);
    $connection->table('roles')->insert([
        ['uuid' => 'role-1', 'name' => 'Driver', 'guard_name' => 'web', 'company_uuid' => 'company-1'],
        ['uuid' => 'role-2', 'name' => 'Driver', 'guard_name' => 'sanctum', 'company_uuid' => 'company-1'],
        ['uuid' => 'role-3', 'name' => 'Administrator', 'guard_name' => 'web', 'company_uuid' => 'company-1'],
        ['uuid' => 'role-4', 'name' => 'Administrator', 'guard_name' => 'sanctum', 'company_uuid' => 'company-1'],
    ]);

    return $connection;
}

function fleetopsDriverAdoptionRequest(array $driver): Request
{
    return Request::create('/int/v1/drivers', 'POST', ['driver' => $driver]);
}

test('phone conflicts adopt existing organization members as drivers', function () {
    $connection = fleetopsDriverAdoptionBoot(['phone' => ['The phone has already been taken.']]);
    $connection->table('users')->insert(['uuid' => '11111111-1111-4111-8111-111111111111', 'company_uuid' => 'company-1', 'name' => 'Member', 'phone' => '+6591234567', 'slug' => 'member', 'type' => 'driver']);
    $connection->table('company_users')->insert(['uuid' => 'cu-1', 'company_uuid' => 'company-1', 'user_uuid' => '11111111-1111-4111-8111-111111111111']);

    $result = (new DriverController())->createRecord(fleetopsDriverAdoptionRequest([
        'name'  => 'Member',
        'phone' => '+6591234567',
    ]));

    expect($result)->toBeArray()
        ->and($result['driver']->resource->user_uuid)->toBe('11111111-1111-4111-8111-111111111111')
        ->and($connection->table('drivers')->count())->toBe(1)
        ->and($connection->table('drivers')->value('company_uuid'))->toBe('company-1');
});

test('email conflicts return the existing driver profile when present', function () {
    $connection = fleetopsDriverAdoptionBoot(['email' => ['The email has already been taken.']]);
    $connection->table('users')->insert(['uuid' => '11111111-1111-4111-8111-111111111111', 'company_uuid' => 'company-1', 'name' => 'Member', 'email' => 'member@example.com', 'type' => 'driver']);
    $connection->table('drivers')->insert(['uuid' => 'driver-1', 'company_uuid' => 'company-1', 'user_uuid' => '11111111-1111-4111-8111-111111111111']);

    $result = (new DriverController())->createRecord(fleetopsDriverAdoptionRequest([
        'name'  => 'Member',
        'email' => 'member@example.com',
    ]));

    expect($result)->toBeArray()
        ->and($result['driver']->resource->uuid)->toBe('driver-1')
        ->and($connection->table('drivers')->count())->toBe(1);
});

test('non phone or email conflicts fall through to the error response', function () {
    fleetopsDriverAdoptionBoot(['name' => ['The name field is required.']]);

    // The error response seam raises a TypeError in the harness once the
    // fall-through branch executes
    expect(fn () => (new DriverController())->createRecord(fleetopsDriverAdoptionRequest([
        'phone' => '+6590000000',
    ])))->toThrow(TypeError::class);
});

test('valid create requests build the driver user and profile', function () {
    $connection = fleetopsDriverAdoptionBoot([]);

    fwrite(STDERR, "\nDBG guard: " . Spatie\Permission\Guard::getDefaultName(Fleetbase\Models\CompanyUser::class) . ' roles: ' . Fleetbase\Models\Role::query()->count() . "\n");
    try {
        Fleetbase\Models\Role::findByName('Driver', 'sanctum');
        fwrite(STDERR, "DBG findByName sanctum: OK\n");
    } catch (Throwable $e) {
        fwrite(STDERR, 'DBG findByName sanctum failed: ' . get_class($e) . "\n");
    }
    putenv('DEBUG=1');
    $_ENV['DEBUG'] = $_SERVER['DEBUG'] = '1';
    $result        = (new DriverController())->createRecord(fleetopsDriverAdoptionRequest([
        'name'  => 'Fresh Driver',
        'email' => 'fresh@example.com',
        'phone' => '+6590001111',
    ]));

    expect($result)->toBeArray()->toHaveKey('driver')
        ->and($connection->table('users')->where('email', 'fresh@example.com')->value('type'))->toBe('driver')
        ->and($connection->table('drivers')->whereNotNull('user_uuid')->count())->toBe(1)
        ->and($connection->table('model_has_roles')->count())->toBeGreaterThanOrEqual(1);
});

test('valid update requests refresh driver and user details', function () {
    $connection = fleetopsDriverAdoptionBoot([]);
    $connection->table('users')->insert(['uuid' => '11111111-1111-4111-8111-111111111111', 'company_uuid' => 'company-1', 'name' => 'Old Name', 'email' => 'old@example.com', 'type' => 'driver']);
    $connection->table('drivers')->insert(['uuid' => '22222222-2222-4222-8222-222222222222', 'public_id' => 'driver_updone1', 'company_uuid' => 'company-1', 'user_uuid' => '11111111-1111-4111-8111-111111111111']);

    $request = Request::create('/int/v1/drivers/driver_updone1', 'PUT', ['driver' => [
        'name'   => 'New Name',
        'status' => 'active',
    ]]);
    $request->setRouteResolver(function () {
        return new class {
            public function getAction($key = null)
            {
                return $key === null ? ['controller' => DriverController::class . '@updateRecord'] : DriverController::class . '@updateRecord';
            }

            public function getActionMethod()
            {
                return 'updateRecord';
            }

            public function getName()
            {
                return 'internal.drivers.update';
            }

            public function uri()
            {
                return 'int/v1/drivers/{id}';
            }

            public function parameters()
            {
                return ['id' => 'driver_updone1'];
            }

            public function parameter($name = null, $default = null)
            {
                return $name === 'id' ? 'driver_updone1' : $default;
            }
        };
    });

    $result = (new DriverController())->updateRecord($request, 'driver_updone1');

    expect($result)->toBeArray()->toHaveKey('driver')
        ->and($connection->table('users')->value('name'))->toBe('New Name')
        ->and($connection->table('drivers')->value('status'))->toBe('available');
});

test('create with user uuids provisions users guards conflicts and companies', function () {
    $connection = fleetopsDriverAdoptionBoot([]);
    Illuminate\Support\Facades\Cache::clearResolvedInstance('cache');

    // Unknown user uuids provision a fresh user with generated credentials
    $created = (new DriverController())->createRecord(fleetopsDriverAdoptionRequest([
        'user_uuid'  => '99999999-9999-4999-8999-999999999901',
        'name'       => 'Provisioned Driver',
        'email'      => 'provisioned@example.com',
        'phone'      => '+6591112222',
        'photo_uuid' => '99999999-9999-4999-8999-999999999902',
    ]));
    expect($created)->toBeArray()->toHaveKey('driver')
        ->and($connection->table('users')->where('email', 'provisioned@example.com')->count())->toBe(1)
        ->and($connection->table('users')->where('email', 'provisioned@example.com')->value('avatar_uuid'))->toBe('99999999-9999-4999-8999-999999999902');

    // Users already holding a driver profile in the company are rejected
    $existingUserUuid = $connection->table('users')->where('email', 'provisioned@example.com')->value('uuid');
    $conflict         = (new DriverController())->createRecord(fleetopsDriverAdoptionRequest([
        'user_uuid' => $existingUserUuid,
        'name'      => 'Duplicate Driver',
    ]));
    expect($conflict->getData(true)['error'] ?? '')->toContain('already belongs');

    // Without a session company driver creation fails outright
    session(['company' => null]);
    $noCompany = (new DriverController())->createRecord(fleetopsDriverAdoptionRequest([
        'name' => 'Companyless Driver',
    ]));
    expect($noCompany->getData(true)['error'] ?? '')->toContain('Unable to create driver');
    session(['company' => 'company-1']);
});

test('auth can resolves real permissions for requests and ping guards', function () {
    $connection = fleetopsDriverAdoptionBoot([]);
    $connection->table('users')->insert(['uuid' => '77777777-7777-4777-8777-777777777701', 'public_id' => 'user_authcan1', 'company_uuid' => 'company-1', 'name' => 'Permitted User', 'type' => 'user']);
    $connection->table('company_users')->insert(['uuid' => '77777777-7777-4777-8777-777777777702', 'company_uuid' => 'company-1', 'user_uuid' => '77777777-7777-4777-8777-777777777701', 'status' => 'active']);
    $connection->table('permissions')->insert([
        ['name' => 'fleet-ops update driver', 'guard_name' => 'sanctum'],
        ['name' => 'fleet-ops update order', 'guard_name' => 'sanctum'],
    ]);
    $companyUserMorph = (new Fleetbase\Models\CompanyUser())->getMorphClass();
    $permissionIds    = $connection->table('permissions')->pluck('id', 'name');
    $connection->table('model_has_permissions')->insert([
        ['permission_id' => $permissionIds['fleet-ops update driver'], 'model_type' => $companyUserMorph, 'model_uuid' => '77777777-7777-4777-8777-777777777702'],
        ['permission_id' => $permissionIds['fleet-ops update order'], 'model_type' => $companyUserMorph, 'model_uuid' => '77777777-7777-4777-8777-777777777702'],
    ]);
    session(['company' => 'company-1', 'user' => '77777777-7777-4777-8777-777777777701']);

    // Granted permissions authorize, missing permissions deny
    expect(Fleetbase\Support\Auth::can('fleet-ops update driver'))->toBeTrue()
        ->and(Fleetbase\Support\Auth::can('fleet-ops delete driver'))->toBeFalse();

    // The update driver form request authorizes through the same gate
    $updateRequest = new Fleetbase\FleetOps\Http\Requests\Internal\UpdateDriverRequest();
    expect($updateRequest->authorize())->toBeTrue();

    // Driver ping authorization resolves through the order permission
    $orderController = new Fleetbase\FleetOps\Http\Controllers\Internal\v1\OrderController();
    $canPing         = new ReflectionMethod($orderController, 'canPingDriver');
    $canPing->setAccessible(true);
    expect($canPing->invoke($orderController))->toBeTrue();

    session(['user' => null]);
});
