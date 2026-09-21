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
 * Covers how the internal DriverController createRecord resolves the new
 * driver's login account through ProfileAccountManager against SQLite with
 * real spatie roles: creating a managed driver account without an invite,
 * linking a staff member of the organization without touching their role,
 * linking a managed driver account of another organization, rejecting an
 * email/phone held by another organization's staff, another profile type or
 * an existing driver of the organization, and returning validation failures.
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
        'drivers'               => ['uuid', 'public_id', 'internal_id', 'company_uuid', 'user_uuid', 'vehicle_uuid', 'vendor_uuid', 'current_job_uuid', 'auth_token', 'signup_token_used', 'avatar_url', 'drivers_license_number', 'license_expiry', 'location', 'heading', 'bearing', 'altitude', 'speed', 'currency', 'current_status', 'meta', 'location_updated_at', 'slug', 'status', 'country', 'city', 'online', '_key'],
        'users'                 => ['uuid', 'public_id', 'company_uuid', 'name', 'email', 'phone', 'password', 'status', 'type', 'username', 'avatar_uuid', 'slug', 'timezone', 'country', 'ip_address', 'meta', '_key'],
        'companies'             => ['uuid', 'public_id', 'name', 'owner_uuid', 'timezone', 'options', 'status', '_key'],
        'company_users'         => ['uuid', 'public_id', 'company_uuid', 'user_uuid', 'status', '_key'],
        'vehicles'              => ['uuid', 'public_id', 'company_uuid', 'driver_uuid'],
        'custom_fields'         => ['uuid', 'public_id', 'company_uuid', 'subject_uuid', 'subject_type', 'name', 'label'],
        'custom_field_values'   => ['uuid', 'public_id', 'company_uuid', 'custom_field_uuid', 'subject_uuid', 'subject_type', 'value', 'value_type', '_key'],
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

function fleetopsDriverAdoptionRoles(SQLiteConnection $connection, string $userUuid): array
{
    return $connection->table('model_has_roles')
        ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
        ->join('company_users', 'company_users.uuid', '=', 'model_has_roles.model_uuid')
        ->where('company_users.user_uuid', $userUuid)
        ->pluck('roles.name')
        ->unique()
        ->values()
        ->all();
}

test('a new driver gets a managed driver account added to the company without an invite', function () {
    $connection = fleetopsDriverAdoptionBoot([]);

    $result = (new DriverController())->createRecord(fleetopsDriverAdoptionRequest([
        'name'     => 'New Driver',
        'email'    => 'New.Driver@Example.com',
        'phone'    => '+65 9123-0000',
        'password' => 'chosen-secret',
    ]));

    $user = $connection->table('users')->first();

    expect($result)->toBeArray()
        ->and($result['driver']->resource->user_uuid)->toBe($user->uuid)
        ->and($user->type)->toBe('driver')
        ->and($user->status)->toBe('active')
        ->and($user->email)->toBe('new.driver@example.com')
        ->and($user->phone)->toBe('+6591230000')
        ->and($user->password)->toBe('hashed:chosen-secret')
        ->and($connection->table('company_users')->where(['company_uuid' => 'company-1', 'user_uuid' => $user->uuid])->count())->toBe(1)
        ->and(fleetopsDriverAdoptionRoles($connection, $user->uuid))->toBe(['Driver'])
        ->and($connection->table('invites')->count())->toBe(0);
});

test('a staff member of the organization is linked without changing their account or role', function () {
    $connection = fleetopsDriverAdoptionBoot([]);
    $connection->table('users')->insert(['uuid' => '11111111-1111-4111-8111-111111111111', 'company_uuid' => 'company-1', 'name' => 'Staff Member', 'email' => 'staff@example.com', 'phone' => '+6590001111', 'slug' => 'staff', 'type' => 'user']);

    $result = (new DriverController())->createRecord(fleetopsDriverAdoptionRequest([
        'name'  => 'Different Name',
        'email' => 'staff@example.com',
        'phone' => '+6599998888',
    ]));

    $staff = $connection->table('users')->where('uuid', '11111111-1111-4111-8111-111111111111')->first();

    expect($result)->toBeArray()
        ->and($result['driver']->resource->user_uuid)->toBe('11111111-1111-4111-8111-111111111111')
        ->and($connection->table('users')->count())->toBe(1)
        ->and($staff->type)->toBe('user')
        ->and($staff->phone)->toBe('+6590001111')
        ->and($staff->name)->toBe('Staff Member')
        ->and($connection->table('model_has_roles')->count())->toBe(0)
        ->and($connection->table('drivers')->value('company_uuid'))->toBe('company-1');
});

test('a managed driver account of another organization is linked and joins the company', function () {
    $connection = fleetopsDriverAdoptionBoot([]);
    $connection->table('users')->insert(['uuid' => '11111111-1111-4111-8111-111111111112', 'company_uuid' => 'company-2', 'name' => 'Shared Driver', 'phone' => '+6591234568', 'slug' => 'shared', 'type' => 'driver']);
    $connection->table('drivers')->insert(['uuid' => 'driver-elsewhere', 'company_uuid' => 'company-2', 'user_uuid' => '11111111-1111-4111-8111-111111111112']);

    $result = (new DriverController())->createRecord(fleetopsDriverAdoptionRequest([
        'name'  => 'Shared Driver',
        'phone' => '+6591234568',
    ]));

    expect($result)->toBeArray()
        ->and($result['driver']->resource->user_uuid)->toBe('11111111-1111-4111-8111-111111111112')
        ->and($connection->table('drivers')->where('company_uuid', 'company-1')->count())->toBe(1)
        ->and($connection->table('company_users')->where(['company_uuid' => 'company-1', 'user_uuid' => '11111111-1111-4111-8111-111111111112'])->count())->toBe(1)
        ->and(fleetopsDriverAdoptionRoles($connection, '11111111-1111-4111-8111-111111111112'))->toBe(['Driver'])
        ->and($connection->table('invites')->count())->toBe(0);
});

test('an email or phone that cannot be linked is rejected with a 422', function (array $user, ?array $driver, array $input, string $message) {
    $connection = fleetopsDriverAdoptionBoot([]);
    $connection->table('users')->insert(array_merge(['uuid' => '11111111-1111-4111-8111-111111111111', 'name' => 'Existing'], $user));
    if ($driver) {
        $connection->table('drivers')->insert($driver);
    }

    $result = (new DriverController())->createRecord(fleetopsDriverAdoptionRequest(array_merge(['name' => 'New Driver'], $input)));

    expect($result)->toBeInstanceOf(Illuminate\Http\JsonResponse::class)
        ->and($result->getStatusCode())->toBe(422)
        ->and($result->getData(true)['error'])->toBe($message)
        ->and($connection->table('drivers')->where('company_uuid', 'company-1')->where('user_uuid', '!=', '11111111-1111-4111-8111-111111111111')->count())->toBe(0)
        ->and($connection->table('users')->count())->toBe(1);
})->with([
    'staff of another organization' => [
        ['company_uuid' => 'company-2', 'email' => 'outsider@example.com', 'type' => 'admin'],
        null,
        ['email' => 'outsider@example.com'],
        'This email is already in use by another account.',
    ],
    'a customer account' => [
        ['company_uuid' => 'company-1', 'phone' => '+6591112222', 'type' => 'customer'],
        null,
        ['phone' => '+6591112222'],
        'This phone number is already used by a customer.',
    ],
    'an existing driver of the organization' => [
        ['company_uuid' => 'company-1', 'email' => 'member@example.com', 'type' => 'driver'],
        ['uuid'  => 'driver-1', 'company_uuid' => 'company-1', 'user_uuid' => '11111111-1111-4111-8111-111111111111'],
        ['email' => 'member@example.com'],
        'A driver with this email already exists.',
    ],
]);

test('validation failures return the error response', function () {
    fleetopsDriverAdoptionBoot(['name' => ['The name field is required.']]);

    // The error response seam raises the validation failure
    $failure = null;

    try {
        (new DriverController())->createRecord(fleetopsDriverAdoptionRequest(['phone' => '+6590000000']));
    } catch (Illuminate\Validation\ValidationException $exception) {
        $failure = $exception;
    }

    expect($failure?->errors())->toBe(['name' => ['The name field is required.']]);
});

function fleetopsDriverAdoptionThrowingHasher(Throwable $exception): void
{
    $GLOBALS['fleetopsDriverAdoptionHashException'] = $exception;
    app()->instance('hash', new class implements Illuminate\Contracts\Hashing\Hasher {
        public function info($hashedValue): array
        {
            return [];
        }

        public function make($value, array $options = []): string
        {
            throw $GLOBALS['fleetopsDriverAdoptionHashException'];
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
}

test('create record reports a missing organization', function () {
    fleetopsDriverAdoptionBoot([]);
    session(['company' => 'company-missing']);

    $result = (new DriverController())->createRecord(fleetopsDriverAdoptionRequest(['name' => 'Nowhere Driver']));

    expect($result->getData(true))->toBe(['error' => 'Unable to create driver.']);
});

test('create record syncs custom field values onto the new driver', function () {
    $connection = fleetopsDriverAdoptionBoot([]);
    $connection->table('custom_fields')->insert(['uuid' => 'dddddddd-dddd-4ddd-8ddd-dddddddddddd', 'company_uuid' => 'company-1', 'name' => 'badge', 'label' => 'Badge']);

    $result = (new DriverController())->createRecord(Request::create('/int/v1/drivers', 'POST', ['driver' => [
        'name'                => 'Badged Driver',
        'custom_field_values' => [['custom_field_uuid' => 'dddddddd-dddd-4ddd-8ddd-dddddddddddd', 'value' => 'B-12']],
    ]]));

    expect($result)->toBeArray()
        ->and($connection->table('custom_field_values')->where('subject_uuid', $result['driver']->resource->uuid)->value('value'))->toBe('B-12');
});

test('create record reports database and request validation failures', function () {
    $connection = fleetopsDriverAdoptionBoot([]);
    $connection->getSchemaBuilder()->drop('drivers');

    // The account is created before the driver row fails to insert
    $queryFailure = (new DriverController())->createRecord(fleetopsDriverAdoptionRequest(['name' => 'Tableless Driver']));
    expect($queryFailure->getData(true)['error'])->toContain('drivers');

    fleetopsDriverAdoptionThrowingHasher(new Fleetbase\Exceptions\FleetbaseRequestValidationException(['password' => ['The password is too weak.']]));
    $validationFailure = (new DriverController())->createRecord(fleetopsDriverAdoptionRequest(['name' => 'Weak Password', 'password' => 'weak-password']));

    expect($validationFailure->getData(true))->toBe(['error' => ['password' => ['The password is too weak.']]]);
});
