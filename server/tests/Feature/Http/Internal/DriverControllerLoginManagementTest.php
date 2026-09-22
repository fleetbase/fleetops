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
use Fleetbase\FleetOps\Http\Controllers\Internal\v1\FleetOpsLookupController;
use Fleetbase\Services\SmsService;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Http\Request;
use Illuminate\Support\MessageBag;
use Illuminate\Support\Str;

/**
 * Covers the internal DriverController login management endpoints against
 * SQLite with real spatie roles: sending and resetting credentials,
 * deactivating (and signing out) and reactivating a driver login, refusing
 * all of them for a driver linked to a team member's account, the proxy
 * field sync on update, and FleetOpsLookupController::profileIdentity.
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

function fleetopsDriverLoginContainer(): void
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

function fleetopsDriverLoginBoot(array $validatorErrors): SQLiteConnection
{
    fleetopsDriverLoginContainer();
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
    $GLOBALS['fleetopsDriverLoginErrors'] = $validatorErrors;
    app()->instance('validator', new class {
        public function make($data = [], $rules = [], $messages = [], $attributes = [])
        {
            return new class implements Illuminate\Contracts\Validation\Validator {
                public function fails()
                {
                    return !empty($GLOBALS['fleetopsDriverLoginErrors']);
                }

                public function errors()
                {
                    return new MessageBag($GLOBALS['fleetopsDriverLoginErrors']);
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
                    return array_keys($GLOBALS['fleetopsDriverLoginErrors']);
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
                    return new MessageBag($GLOBALS['fleetopsDriverLoginErrors']);
                }
            };
        }
    });
    Illuminate\Support\Facades\DB::clearResolvedInstance('db');
    app()->instance('hash', new class implements Illuminate\Contracts\Hashing\Hasher {
        public function info($hashedValue): array
        {
            return [];
        }

        public function make($value, array $options = []): string
        {
            return 'hashed:' . $value;
        }

        public function check($value, $hashedValue, array $options = []): bool
        {
            return 'hashed:' . $value === $hashedValue;
        }

        public function needsRehash($hashedValue, array $options = []): bool
        {
            return false;
        }
    });
    Illuminate\Support\Facades\Hash::clearResolvedInstance('hash');

    $schema = $connection->getSchemaBuilder();
    $tables = [
        'drivers'                => ['uuid', 'public_id', 'internal_id', 'company_uuid', 'user_uuid', 'vehicle_uuid', 'vendor_uuid', 'current_job_uuid', 'auth_token', 'signup_token_used', 'avatar_url', 'drivers_license_number', 'license_expiry', 'location', 'heading', 'bearing', 'altitude', 'speed', 'currency', 'current_status', 'meta', 'location_updated_at', 'slug', 'status', 'country', 'city', 'online', '_key'],
        'users'                  => ['uuid', 'public_id', 'company_uuid', 'name', 'email', 'phone', 'password', 'status', 'type', 'username', 'avatar_uuid', 'slug', 'timezone', 'country', 'ip_address', 'meta', '_key'],
        'companies'              => ['uuid', 'public_id', 'name', 'owner_uuid', 'timezone', 'options', 'status', '_key'],
        'company_users'          => ['uuid', 'public_id', 'company_uuid', 'user_uuid', 'status', '_key'],
        'vehicles'               => ['uuid', 'public_id', 'company_uuid', 'driver_uuid'],
        'custom_fields'          => ['uuid', 'public_id', 'company_uuid', 'subject_uuid', 'subject_type', 'name', 'label'],
        'custom_field_values'    => ['uuid', 'public_id', 'company_uuid', 'custom_field_uuid', 'subject_uuid', 'subject_type', 'value', 'value_type', '_key'],
        'settings'               => ['key', 'value'],
        'permissions'            => ['name', 'guard_name', 'service', 'description'],
        'roles'                  => ['uuid', 'public_id', 'name', 'guard_name', 'company_uuid', 'service', 'description', '_key'],
        'model_has_roles'        => ['role_id', 'model_type', 'model_uuid'],
        'model_has_permissions'  => ['permission_id', 'model_type', 'model_uuid'],
        'role_has_permissions'   => ['permission_id', 'role_id'],
        'policies'               => ['name', 'guard_name', 'company_uuid'],
        'directives'             => ['uuid', 'public_id', 'company_uuid', 'permission_uuid', 'subject_type', 'subject_uuid', 'key', 'rules'],
        'files'                  => ['uuid', 'public_id', 'company_uuid', 'uploader_uuid', 'subject_uuid', 'subject_type', 'name', 'original_filename', 'extension', 'content_type', 'path', 'bucket', 'disk', 'size', 'type', 'meta', '_key'],
        'invites'                => ['uuid', 'public_id', 'company_uuid', 'created_by_uuid', 'subject_uuid', 'subject_type', 'code', 'uri', 'protocol', 'recipients', 'reason', 'expires_at', '_key'],
        'notifications'          => ['type', 'notifiable_type', 'notifiable_id', 'data', 'read_at'],
        'contacts'               => ['uuid', 'public_id', 'company_uuid', 'user_uuid', 'name', 'email', 'phone', 'type'],
        'personal_access_tokens' => ['tokenable_type', 'tokenable_id', 'name', 'token', 'abilities', 'last_used_at', 'expires_at'],
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

class FleetOpsDriverLoginSmsFake extends SmsService
{
    public array $sent = [];

    public function __construct()
    {
    }

    public function send(string $to, string $text, array $options = [], ?string $provider = null): array
    {
        $this->sent[] = [$to, $text];

        return ['success' => true];
    }
}

function fleetopsDriverLoginFixture(): SQLiteConnection
{
    $connection = fleetopsDriverLoginBoot([]);

    Illuminate\Support\Facades\Mail::swap(new class {
        public array $sent = [];

        public function to($users)
        {
            return $this;
        }

        public function send($mailable)
        {
            $this->sent[] = $mailable;

            return null;
        }
    });
    app()->instance(SmsService::class, new FleetOpsDriverLoginSmsFake());

    $users = [
        ['uuid' => '11111111-1111-4111-8111-111111111111', 'public_id' => 'user_managed', 'company_uuid' => 'company-1', 'name' => 'Managed Driver', 'email' => 'managed@example.com', 'phone' => '+6590000001', 'password' => 'hashed:old', 'status' => 'pending', 'type' => 'driver'],
        ['uuid' => '22222222-2222-4222-8222-222222222222', 'public_id' => 'user_staff', 'company_uuid' => 'company-1', 'name' => 'Staff Driver', 'email' => 'staff@example.com', 'phone' => '+6590000002', 'password' => 'hashed:staff', 'status' => 'active', 'type' => 'user'],
        ['uuid' => '33333333-3333-4333-8333-333333333333', 'public_id' => 'user_nocontact', 'company_uuid' => 'company-1', 'name' => 'Unreachable', 'status' => 'active', 'type' => 'driver'],
    ];
    foreach ($users as $user) {
        $connection->table('users')->insert($user);
    }
    $connection->table('company_users')->insert(['uuid' => 'cu-managed', 'company_uuid' => 'company-1', 'user_uuid' => '11111111-1111-4111-8111-111111111111', 'status' => 'pending']);
    $drivers = [
        ['uuid' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa1', 'public_id' => 'driver_managed', 'company_uuid' => 'company-1', 'user_uuid' => '11111111-1111-4111-8111-111111111111'],
        ['uuid' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa2', 'public_id' => 'driver_staff', 'company_uuid' => 'company-1', 'user_uuid' => '22222222-2222-4222-8222-222222222222'],
        ['uuid' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa3', 'public_id' => 'driver_nocontact', 'company_uuid' => 'company-1', 'user_uuid' => '33333333-3333-4333-8333-333333333333'],
    ];
    foreach ($drivers as $driver) {
        $connection->table('drivers')->insert($driver);
    }

    return $connection;
}

function fleetopsDriverLoginUser(SQLiteConnection $connection, string $uuid = '11111111-1111-4111-8111-111111111111'): object
{
    return $connection->table('users')->where('uuid', $uuid)->first();
}

test('send credentials sets a new password activates the login and emails it', function () {
    $connection = fleetopsDriverLoginFixture();

    $response = (new DriverController())->sendCredentials('driver_managed');
    $data     = $response->getData(true);
    $user     = fleetopsDriverLoginUser($connection);

    expect($data['status'])->toBe('ok')
        ->and($data['sent_via'])->toBe('email')
        ->and($data['driver'])->toMatchArray([
            'id'              => 'driver_managed',
            'uuid'            => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa1',
            'user_uuid'       => '11111111-1111-4111-8111-111111111111',
            'is_staff_linked' => false,
            'login_status'    => 'active',
        ])
        ->and($data['driver']['user'])->toMatchArray([
            'id'     => 'user_managed',
            'email'  => 'managed@example.com',
            'phone'  => '+6590000001',
            'status' => 'active',
        ])
        ->and($user->status)->toBe('active')
        ->and($user->password)->not->toBe('hashed:old')
        ->and(Illuminate\Support\Facades\Mail::getFacadeRoot()->sent[0])->toBeInstanceOf(Fleetbase\FleetOps\Mail\DriverCredentialsMail::class);

    // A driver with neither an email nor a phone can't be sent credentials
    $unreachable = (new DriverController())->sendCredentials('driver_nocontact');
    expect($unreachable->getData(true))->toBe(['error' => 'This profile has no email or phone to send credentials to.']);
});

test('login management endpoints report missing drivers accounts and staff-linked logins', function () {
    $connection = fleetopsDriverLoginFixture();
    // The driver scope keeps drivers whose account was deleted (the user
    // relation includes trashed accounts), but such a driver has no login
    $connection->table('users')->insert(['uuid' => '44444444-4444-4444-8444-444444444444', 'company_uuid' => 'company-1', 'type' => 'driver', 'deleted_at' => '2026-01-01 00:00:00']);
    $connection->table('drivers')->insert(['uuid' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa4', 'public_id' => 'driver_orphan', 'company_uuid' => 'company-1', 'user_uuid' => '44444444-4444-4444-8444-444444444444']);
    $controller = new DriverController();
    $reset      = fn (string $id) => $controller->resetCredentials(Request::create('/x', 'POST', ['password' => 'long-enough', 'password_confirmation' => 'long-enough']), $id);

    foreach ([
        fn (string $id) => $controller->sendCredentials($id),
        $reset,
        fn (string $id) => $controller->deactivateLogin($id),
        fn (string $id) => $controller->reactivateLogin($id),
    ] as $endpoint) {
        $missing = $endpoint('driver_unknown');
        $orphan  = $endpoint('driver_orphan');
        $staff   = $endpoint('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa2');

        expect($missing->getStatusCode())->toBe(404)
            ->and($missing->getData(true))->toBe(['error' => 'Driver not found.'])
            ->and($orphan->getData(true))->toBe(['error' => 'This driver has no login account.'])
            ->and($staff->getStatusCode())->toBe(422)
            ->and($staff->getData(true))->toBe(['error' => 'This driver signs in with a team member account. Manage this login in IAM.']);
    }

    // The team member's account is never touched
    $staffUser = fleetopsDriverLoginUser($connection, '22222222-2222-4222-8222-222222222222');
    expect($staffUser->password)->toBe('hashed:staff')
        ->and($staffUser->status)->toBe('active')
        ->and(Illuminate\Support\Facades\Mail::getFacadeRoot()->sent)->toBe([]);
});

test('reset credentials validates the password and optionally sends it', function () {
    $connection = fleetopsDriverLoginFixture();
    $controller = new DriverController();
    $request    = fn (array $input) => Request::create('/x', 'POST', $input);

    expect($controller->resetCredentials($request(['password' => 'short', 'password_confirmation' => 'short']), 'driver_managed')->getData(true))
        ->toBe(['error' => 'Password must be at least 8 characters.'])
        ->and($controller->resetCredentials($request([]), 'driver_managed')->getData(true))
        ->toBe(['error' => 'Password must be at least 8 characters.'])
        ->and($controller->resetCredentials($request(['password' => 'long-enough', 'password_confirmation' => 'different']), 'driver_managed')->getData(true))
        ->toBe(['error' => 'Passwords do not match.']);

    $quiet = $controller->resetCredentials($request(['password' => 'quiet-secret', 'password_confirmation' => 'quiet-secret']), 'driver_managed');
    expect($quiet->getData(true)['status'])->toBe('ok')
        ->and(fleetopsDriverLoginUser($connection)->password)->toBe('hashed:quiet-secret')
        ->and(Illuminate\Support\Facades\Mail::getFacadeRoot()->sent)->toBe([]);

    $sent = $controller->resetCredentials($request(['password' => 'mailed-secret', 'password_confirmation' => 'mailed-secret', 'send_credentials' => true]), 'driver_managed');
    expect($sent->getData(true)['driver']['user_uuid'])->toBe('11111111-1111-4111-8111-111111111111')
        ->and(fleetopsDriverLoginUser($connection)->password)->toBe('hashed:mailed-secret')
        ->and(Illuminate\Support\Facades\Mail::getFacadeRoot()->sent)->toHaveCount(1);

    // The password is still changed when there's nowhere to send it
    $unsendable = $controller->resetCredentials($request(['password' => 'kept-secret', 'password_confirmation' => 'kept-secret', 'send_credentials' => '1']), 'driver_nocontact');
    expect($unsendable->getData(true))->toBe(['error' => 'This profile has no email or phone to send credentials to.'])
        ->and(fleetopsDriverLoginUser($connection, '33333333-3333-4333-8333-333333333333')->password)->toBe('hashed:kept-secret');
});

test('deactivating a login signs the driver out and reactivating restores it', function () {
    $connection = fleetopsDriverLoginFixture();
    $connection->table('users')->where('uuid', '11111111-1111-4111-8111-111111111111')->update(['status' => 'active']);
    $connection->table('personal_access_tokens')->insert([
        'tokenable_type' => Fleetbase\Models\User::class,
        'tokenable_id'   => '11111111-1111-4111-8111-111111111111',
        'name'           => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa1',
        'token'          => 'token-hash',
    ]);
    $connection->table('personal_access_tokens')->insert([
        'tokenable_type' => Fleetbase\Models\User::class,
        'tokenable_id'   => '22222222-2222-4222-8222-222222222222',
        'name'           => 'staff-session',
        'token'          => 'staff-token-hash',
    ]);
    $controller = new DriverController();

    $deactivated = $controller->deactivateLogin('driver_managed')->getData(true);

    expect($deactivated['status'])->toBe('ok')
        ->and($deactivated['driver']['login_status'])->toBe('inactive')
        ->and(fleetopsDriverLoginUser($connection)->status)->toBe('inactive')
        ->and($connection->table('company_users')->where('uuid', 'cu-managed')->value('status'))->toBe('inactive')
        // Only the driver's own tokens are revoked
        ->and($connection->table('personal_access_tokens')->pluck('name')->all())->toBe(['staff-session']);

    $reactivated = $controller->reactivateLogin('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa1')->getData(true);

    expect($reactivated['driver']['login_status'])->toBe('active')
        ->and($reactivated['driver']['user']['session_status'])->toBe('active')
        ->and(fleetopsDriverLoginUser($connection)->status)->toBe('active');
});

function fleetopsDriverLoginUpdateRequest(string $id, array $driver): Request
{
    $request = Request::create('/int/v1/drivers/' . $id, 'PUT', ['driver' => $driver]);
    $store   = app('session.store');
    $store->put('company', 'company-1');
    $request->setLaravelSession($store);
    $request->setRouteResolver(fn () => new class($id) {
        public function __construct(private string $id)
        {
        }

        public function getAction($key = null)
        {
            return DriverController::class . '@updateRecord';
        }

        public function getActionMethod()
        {
            return 'updateRecord';
        }

        public function uri()
        {
            return 'int/v1/drivers/{id}';
        }

        public function getName()
        {
            return 'int.v1.drivers.update';
        }

        public function parameters()
        {
            return ['id' => $this->id];
        }

        public function parameter($key, $default = null)
        {
            return $key === 'id' ? $this->id : $default;
        }
    });
    app()->instance('request', $request);

    return $request;
}

test('update record syncs proxy fields to a managed account and only the name to a team member', function () {
    $connection = fleetopsDriverLoginFixture();
    $controller = new DriverController();

    $managed = $controller->updateRecord(fleetopsDriverLoginUpdateRequest('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa1', [
        'name'       => 'Renamed Driver',
        'email'      => 'Renamed@Example.com',
        'phone'      => null,
        'photo_uuid' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
        'user_uuid'  => '22222222-2222-4222-8222-222222222222',
    ]), 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa1');

    $user = fleetopsDriverLoginUser($connection);

    expect($managed)->toBeArray()
        ->and($managed['driver']->resource->user_uuid)->toBe('11111111-1111-4111-8111-111111111111')
        ->and($user->name)->toBe('Renamed Driver')
        ->and($user->email)->toBe('renamed@example.com')
        ->and($user->phone)->toBeNull()
        ->and($user->avatar_uuid)->toBe('bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb');

    $staff = $controller->updateRecord(fleetopsDriverLoginUpdateRequest('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa2', [
        'name'  => 'Staff As Driver',
        'email' => 'changed@example.com',
    ]), 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa2');

    $staffUser = fleetopsDriverLoginUser($connection, '22222222-2222-4222-8222-222222222222');

    expect($staff)->toBeArray()
        ->and($staffUser->name)->toBe('Staff As Driver')
        ->and($staffUser->email)->toBe('staff@example.com');

    // Taking another account's email is refused
    $conflict = $controller->updateRecord(fleetopsDriverLoginUpdateRequest('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa1', [
        'email' => 'staff@example.com',
    ]), 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa1');

    expect($conflict->getStatusCode())->toBe(422)
        ->and($conflict->getData(true))->toBe(['error' => 'This email is already in use by another account.'])
        ->and(fleetopsDriverLoginUser($connection)->email)->toBe('renamed@example.com');
});

function fleetopsDriverLoginLookup(array $input): array
{
    return (new FleetOpsLookupController())->profileIdentity(Request::create('/int/v1/fleet-ops/lookup/profile-identity', 'GET', $input))->getData(true);
}

test('profile identity lookup reports the team member to link or the conflict', function () {
    $connection = fleetopsDriverLoginFixture();
    $connection->table('users')->insert(['uuid' => '55555555-5555-4555-8555-555555555555', 'company_uuid' => 'company-1', 'name' => 'Office Staff', 'email' => 'office@example.com', 'phone' => '+6590000005', 'type' => 'admin']);
    $connection->table('users')->insert(['uuid' => '66666666-6666-4666-8666-666666666666', 'company_uuid' => 'company-1', 'name' => 'Customer', 'email' => 'customer@example.com', 'type' => 'customer']);
    $connection->table('contacts')->insert(['uuid' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc', 'public_id' => 'contact_customer', 'company_uuid' => 'company-1', 'user_uuid' => '66666666-6666-4666-8666-666666666666', 'type' => 'customer']);
    $connection->table('contacts')->insert(['uuid' => 'cccccccc-cccc-4ccc-8ccc-ccccccccccc2', 'public_id' => 'contact_nouser', 'company_uuid' => 'company-1', 'type' => 'contact']);

    expect((new FleetOpsLookupController())->profileIdentity(Request::create('/x', 'GET', ['type' => 'user']))->getStatusCode())->toBe(422)
        ->and(fleetopsDriverLoginLookup(['type' => 'staff']))->toBe(['error' => 'Invalid profile type.'])
        // Nothing entered, nothing found
        ->and(fleetopsDriverLoginLookup([]))->toBe(['staff' => null, 'conflict' => null])
        // A new driver with a team member's email links to them
        ->and(fleetopsDriverLoginLookup(['email' => 'office@example.com']))->toBe([
            'staff'    => ['uuid' => '55555555-5555-4555-8555-555555555555', 'name' => 'Office Staff', 'email' => 'office@example.com', 'phone' => '+6590000005'],
            'conflict' => null,
        ])
        // A new driver can't take a customer's email
        ->and(fleetopsDriverLoginLookup(['type' => 'driver', 'email' => 'customer@example.com']))->toBe(['staff' => null, 'conflict' => 'This email is already used by a customer.'])
        // An existing driver's own email isn't taken, another account's is
        ->and(fleetopsDriverLoginLookup(['ignore' => 'driver_managed', 'email' => 'managed@example.com']))->toBe(['staff' => null, 'conflict' => null])
        ->and(fleetopsDriverLoginLookup(['ignore' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa1', 'phone' => '+6590000005']))->toBe(['staff' => null, 'conflict' => 'This phone number is already in use by another account.'])
        // Customer profiles are looked up among contacts
        ->and(fleetopsDriverLoginLookup(['type' => 'customer', 'ignore' => 'contact_customer', 'email' => 'customer@example.com']))->toBe(['staff' => null, 'conflict' => null])
        // A profile without an account, or an unknown profile id, is treated as a new profile
        ->and(fleetopsDriverLoginLookup(['type' => 'contact', 'ignore' => 'contact_nouser', 'email' => 'customer@example.com']))->toBe(['staff' => null, 'conflict' => 'This email is already used by a customer.'])
        ->and(fleetopsDriverLoginLookup(['type' => 'contact', 'ignore' => 'contact_unknown', 'email' => 'customer@example.com']))->toBe(['staff' => null, 'conflict' => 'This email is already used by a customer.']);
});

test('update record syncs custom field values and reports failures', function () {
    $connection = fleetopsDriverLoginFixture();
    $connection->table('custom_fields')->insert(['uuid' => 'dddddddd-dddd-4ddd-8ddd-dddddddddddd', 'company_uuid' => 'company-1', 'name' => 'badge', 'label' => 'Badge']);
    $controller = new DriverController();

    $updated = $controller->updateRecord(fleetopsDriverLoginUpdateRequest('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa1', [
        'custom_field_values' => [['custom_field_uuid' => 'dddddddd-dddd-4ddd-8ddd-dddddddddddd', 'value' => 'B-12']],
    ]), 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa1');

    expect($updated)->toBeArray()
        ->and($connection->table('custom_field_values')->where('subject_uuid', 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa1')->value('value'))->toBe('B-12');

    // An unknown driver surfaces the model's not-found error
    $missing = $controller->updateRecord(fleetopsDriverLoginUpdateRequest('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa9', ['name' => 'Ghost']), 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa9');
    expect($missing->getData(true)['error'])->toContain('not found');

    // A request validation failure raised while updating the account is returned
    $GLOBALS['fleetopsDriverLoginHashException'] = new Fleetbase\Exceptions\FleetbaseRequestValidationException(['password' => ['The password is too weak.']]);
    app()->instance('hash', new class implements Illuminate\Contracts\Hashing\Hasher {
        public function info($hashedValue): array
        {
            return [];
        }

        public function make($value, array $options = []): string
        {
            throw $GLOBALS['fleetopsDriverLoginHashException'];
        }

        public function check($value, $hashedValue, array $options = []): bool
        {
            return false;
        }

        public function needsRehash($hashedValue, array $options = []): bool
        {
            return false;
        }
    });
    Illuminate\Support\Facades\Hash::clearResolvedInstance('hash');

    $weak = $controller->updateRecord(fleetopsDriverLoginUpdateRequest('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa1', ['password' => 'weak-password']), 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa1');
    expect($weak->getData(true))->toBe(['error' => ['password' => ['The password is too weak.']]]);
});

test('update record returns validation failures', function () {
    fleetopsDriverLoginFixture();
    $GLOBALS['fleetopsDriverLoginErrors'] = ['email' => ['The email must be a valid email address.']];

    $failure = null;
    try {
        (new DriverController())->updateRecord(fleetopsDriverLoginUpdateRequest('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa1', ['email' => 'not-an-email']), 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa1');
    } catch (Illuminate\Validation\ValidationException $exception) {
        $failure = $exception;
    }

    expect($failure?->errors())->toBe(['email' => ['The email must be a valid email address.']]);
});
