<?php

if (!function_exists('Fleetbase\\Observers\\event')) {
    eval('namespace Fleetbase\\Observers; function event($event = null, $payload = []) { return []; }');
}

if (!function_exists('Fleetbase\\FleetOps\\Observers\\event')) {
    eval('namespace Fleetbase\\FleetOps\\Observers; function event($event = null, $payload = []) { return []; }');
}

if (!function_exists('__')) {
    function __($key = null, $replace = [], $locale = null)
    {
        return $key;
    }
}

if (!Illuminate\Support\Str::hasMacro('humanize')) {
    Illuminate\Support\Str::macro('humanize', fn ($value, $uppercase = true) => str_replace('_', ' ', Illuminate\Support\Str::snake((string) $value)));
}

use Fleetbase\FleetOps\Exceptions\ProfileIdentityConflictException;
use Fleetbase\FleetOps\Mail\CustomerCredentialsMail;
use Fleetbase\FleetOps\Mail\DriverCredentialsMail;
use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Support\ProfileAccountManager;
use Fleetbase\Models\CompanyUser;
use Fleetbase\Models\User;
use Fleetbase\Services\SmsService;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;

/**
 * Covers ProfileAccountManager against SQLite with real spatie roles: account
 * classification, identity lookups, linking a staff member or a managed
 * account of the same type, creating managed accounts without invites, proxy
 * field sync, releasing an account when its profile is deleted, and sending
 * credentials by email or SMS.
 */
class FleetOpsProfileAccountSmsFake extends SmsService
{
    public array $sent   = [];
    public array $result = ['success' => true];

    public function __construct()
    {
    }

    public function send(string $to, string $text, array $options = [], ?string $provider = null): array
    {
        $this->sent[] = [$to, $text];

        return $this->result;
    }
}

function fleetopsProfileAccountBoot(): SQLiteConnection
{
    // Model uuid hooks bind to whichever dispatcher exists when the class boots
    if (!EloquentModel::getEventDispatcher()) {
        EloquentModel::setEventDispatcher(new Illuminate\Events\Dispatcher());
    }

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
    app()->instance('responsecache', new class {
        public function __call($method, $arguments)
        {
            return null;
        }
    });
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

    Illuminate\Support\Facades\Mail::swap(new class {
        public array $sent = [];
        public array $to   = [];

        public function to($users)
        {
            $this->to[] = $users;

            return $this;
        }

        public function send($mailable)
        {
            $this->sent[] = $mailable;

            return null;
        }
    });

    $sms = new FleetOpsProfileAccountSmsFake();
    app()->instance(SmsService::class, $sms);

    $schema = $connection->getSchemaBuilder();
    $tables = [
        'users'                 => ['uuid', 'public_id', 'company_uuid', 'name', 'email', 'phone', 'password', 'status', 'type', 'username', 'avatar_uuid', 'slug', 'timezone', 'country', 'ip_address', 'meta', 'last_login', '_key'],
        'drivers'               => ['uuid', 'public_id', 'company_uuid', 'user_uuid'],
        'contacts'              => ['uuid', 'public_id', 'company_uuid', 'user_uuid', 'name', 'type'],
        'companies'             => ['uuid', 'public_id', 'name', 'owner_uuid', 'timezone'],
        'company_users'         => ['uuid', 'public_id', 'company_uuid', 'user_uuid', 'status', '_key'],
        'permissions'           => ['name', 'guard_name', 'service', 'description'],
        'roles'                 => ['uuid', 'public_id', 'name', 'guard_name', 'company_uuid', 'service', 'description', '_key'],
        'model_has_roles'       => ['role_id', 'model_type', 'model_uuid'],
        'model_has_permissions' => ['permission_id', 'model_type', 'model_uuid'],
        'role_has_permissions'  => ['permission_id', 'role_id'],
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
    config()->set('app.name', 'Fleetbase');
    app()->bind(Illuminate\Contracts\Config\Repository::class, fn () => config());

    session(['company' => 'company-1']);
    $connection->table('companies')->insert([
        ['uuid' => 'company-1', 'public_id' => 'company_one', 'name' => 'Acme', 'timezone' => 'Asia/Singapore'],
        ['uuid' => 'company-2', 'public_id' => 'company_two', 'name' => 'Globex', 'timezone' => null],
    ]);
    $roles = [];
    foreach (['company-1', 'company-2'] as $company) {
        foreach (['Driver', 'Fleet-Ops Contact', 'Fleet-Ops Customer'] as $name) {
            foreach (['web', 'sanctum'] as $guard) {
                $roles[] = ['uuid' => $company . $name . $guard, 'name' => $name, 'guard_name' => $guard, 'company_uuid' => $company];
            }
        }
    }
    $connection->table('roles')->insert($roles);

    return $connection;
}

function fleetopsProfileAccountRoles(SQLiteConnection $connection, string $userUuid, string $companyUuid): array
{
    return $connection->table('model_has_roles')
        ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
        ->join('company_users', 'company_users.uuid', '=', 'model_has_roles.model_uuid')
        ->where('company_users.user_uuid', $userUuid)
        ->where('company_users.company_uuid', $companyUuid)
        ->pluck('roles.name')
        ->unique()
        ->values()
        ->all();
}

/**
 * Insert rows one at a time: a multi-row insert takes its column list from the
 * first row, so rows with different keys would land in the wrong columns.
 */
function fleetopsProfileAccountRows(SQLiteConnection $connection, string $table, array $rows): void
{
    foreach ($rows as $row) {
        $connection->table($table)->insert($row);
    }
}

function fleetopsProfileAccountUser(array $attributes): User
{
    app('db')->connection()->table('users')->insert($attributes);

    return User::where('uuid', $attributes['uuid'])->first();
}

test('accounts are managed when typed driver contact or customer and staff otherwise', function () {
    expect(ProfileAccountManager::isManagedAccount(null))->toBeFalse()
        ->and(ProfileAccountManager::isStaffAccount(null))->toBeFalse()
        ->and(ProfileAccountManager::isManagedAccount((object) ['type' => 'driver']))->toBeTrue()
        ->and(ProfileAccountManager::isManagedAccount((object) ['type' => 'contact']))->toBeTrue()
        ->and(ProfileAccountManager::isManagedAccount((object) ['type' => 'customer']))->toBeTrue()
        ->and(ProfileAccountManager::isStaffAccount((object) ['type' => 'customer']))->toBeFalse()
        ->and(ProfileAccountManager::isStaffAccount((object) ['type' => 'user']))->toBeTrue()
        ->and(ProfileAccountManager::isStaffAccount((object) ['type' => 'admin']))->toBeTrue()
        ->and(ProfileAccountManager::isStaffAccount((object) []))->toBeTrue()
        ->and(ProfileAccountManager::normalizeEmail('  Ada@Example.COM '))->toBe('ada@example.com')
        ->and(ProfileAccountManager::normalizeEmail('   '))->toBeNull()
        ->and(ProfileAccountManager::normalizeEmail(null))->toBeNull()
        ->and(ProfileAccountManager::normalizePhone(' +65 9123-4567 '))->toBe('+6591234567')
        ->and(ProfileAccountManager::normalizePhone(''))->toBeNull()
        ->and(ProfileAccountManager::normalizePhone(null))->toBeNull();
});

test('identity lookups match email or phone and skip ignored and deleted accounts', function () {
    $connection = fleetopsProfileAccountBoot();
    fleetopsProfileAccountRows($connection, 'users', [
        ['uuid' => 'user-email', 'email' => 'ada@example.com', 'type' => 'user'],
        ['uuid' => 'user-phone', 'phone' => '+6591234567', 'type' => 'driver'],
        ['uuid' => 'user-deleted', 'email' => 'gone@example.com', 'type' => 'driver', 'deleted_at' => '2026-01-01 00:00:00'],
    ]);

    expect(ProfileAccountManager::findAccountByIdentity(null, ' '))->toBeNull()
        ->and(ProfileAccountManager::findAccountByIdentity('ADA@example.com', null)?->uuid)->toBe('user-email')
        ->and(ProfileAccountManager::findAccountByIdentity(null, '+65 9123 4567')?->uuid)->toBe('user-phone')
        ->and(ProfileAccountManager::findAccountByIdentity('nobody@example.com', '+6591234567')?->uuid)->toBe('user-phone')
        ->and(ProfileAccountManager::findAccountByIdentity('ada@example.com', null, 'user-email'))->toBeNull()
        ->and(ProfileAccountManager::findAccountByIdentity('gone@example.com', null))->toBeNull();
});

test('lookup reports the staff member to link or why the identity is unavailable', function () {
    $connection = fleetopsProfileAccountBoot();
    fleetopsProfileAccountRows($connection, 'users', [
        ['uuid' => 'staff-1', 'company_uuid' => 'company-1', 'name' => 'Staff', 'email' => 'staff@example.com', 'type' => 'user'],
        ['uuid' => 'staff-2', 'company_uuid' => 'company-2', 'email' => 'outsider@example.com', 'type' => 'admin'],
        ['uuid' => 'driver-1', 'company_uuid' => 'company-2', 'phone' => '+6590001111', 'type' => 'driver'],
        ['uuid' => 'current-1', 'company_uuid' => 'company-1', 'email' => 'me@example.com', 'type' => 'driver'],
    ]);
    $current = User::where('uuid', 'current-1')->first();

    $nothing  = ProfileAccountManager::lookup('company-1', 'driver', 'free@example.com', null);
    $staff    = ProfileAccountManager::lookup('company-1', 'driver', 'staff@example.com', null);
    $outsider = ProfileAccountManager::lookup('company-1', 'driver', 'outsider@example.com', null);
    $customer = ProfileAccountManager::lookup('company-1', 'customer', null, '+6590001111');
    $sameType = ProfileAccountManager::lookup('company-1', 'driver', null, '+6590001111');
    $taken    = ProfileAccountManager::lookup('company-1', 'driver', 'staff@example.com', null, $current);
    $own      = ProfileAccountManager::lookup('company-1', 'driver', 'me@example.com', null, $current);

    expect($nothing)->toBe(['account' => null, 'staff' => null, 'conflict' => null])
        ->and($staff['staff']?->uuid)->toBe('staff-1')
        ->and($staff['conflict'])->toBeNull()
        ->and($outsider['staff'])->toBeNull()
        ->and($outsider['conflict'])->toBe('This email is already in use by another account.')
        ->and($customer['conflict'])->toBe('This phone number is already used by a driver.')
        // A driver account of another organization is linked, not reported as staff
        ->and($sameType['account']?->uuid)->toBe('driver-1')
        ->and($sameType['staff'])->toBeNull()
        ->and($sameType['conflict'])->toBeNull()
        // An existing profile can't swap its account for another one
        ->and($taken['conflict'])->toBe('This email is already in use by another account.')
        ->and($taken['staff'])->toBeNull()
        ->and($own['account'])->toBeNull();
});

test('resolve for profile creates a managed account with the profile role and no invite', function () {
    $connection = fleetopsProfileAccountBoot();

    $driver = ProfileAccountManager::resolveForProfile('company-1', 'driver', 'Dana Driver', 'Dana@Example.com', '+65 9000 1111', [
        'password'    => 'chosen-secret',
        'status'      => 'active',
        'avatar_uuid' => 'avatar-1',
        'country'     => 'SG',
        'ip_address'  => '127.0.0.1',
    ]);

    $row = $connection->table('users')->where('uuid', $driver->uuid)->first();

    expect($driver->uuid)->not->toBeNull()
        ->and($row->type)->toBe('driver')
        ->and($row->email)->toBe('dana@example.com')
        ->and($row->phone)->toBe('+6590001111')
        ->and($row->password)->toBe('hashed:chosen-secret')
        ->and($row->status)->toBe('active')
        ->and($row->timezone)->toBe('Asia/Singapore')
        ->and($row->avatar_uuid)->toBe('avatar-1')
        ->and($row->company_uuid)->toBe('company-1')
        ->and(fleetopsProfileAccountRoles($connection, $driver->uuid, 'company-1'))->toBe(['Driver'])
        ->and($driver->getRelation('companyUser'))->toBeInstanceOf(CompanyUser::class);

    // A nameless customer in a company without a timezone gets a generated
    // username, a random password, the default status and the server timezone
    $customer    = ProfileAccountManager::resolveForProfile('company-2', 'customer', null, null, '+6590002222');
    $customerRow = $connection->table('users')->where('uuid', $customer->uuid)->first();

    expect($customerRow->type)->toBe('customer')
        ->and($customerRow->status)->toBe('pending')
        ->and($customerRow->name)->toBeNull()
        ->and($customerRow->username)->not->toBeEmpty()
        ->and($customerRow->password)->toStartWith('hashed:')
        ->and($customerRow->timezone)->toBe(date_default_timezone_get())
        ->and(fleetopsProfileAccountRoles($connection, $customer->uuid, 'company-2'))->toBe(['Fleet-Ops Customer']);

    // A contact of an organization missing from the table is created without membership
    $contact = ProfileAccountManager::createManagedAccount('company-missing', 'contact', 'Carl Contact', 'carl@example.com', null, ['timezone' => 'UTC']);

    expect($connection->table('users')->where('uuid', $contact->uuid)->value('type'))->toBe('contact')
        ->and($connection->table('users')->where('uuid', $contact->uuid)->value('timezone'))->toBe('UTC')
        ->and($connection->table('company_users')->where('user_uuid', $contact->uuid)->count())->toBe(0);
});

test('resolve for profile links staff members and managed accounts of the same type', function () {
    $connection = fleetopsProfileAccountBoot();
    fleetopsProfileAccountRows($connection, 'users', [
        ['uuid' => 'staff-1', 'company_uuid' => 'company-2', 'name' => 'Staff', 'email' => 'staff@example.com', 'type' => 'user'],
        ['uuid' => 'driver-1', 'company_uuid' => 'company-2', 'phone' => '+6590001111', 'type' => 'driver'],
        ['uuid' => 'driver-2', 'company_uuid' => null, 'phone' => '+6590003333', 'type' => 'driver'],
    ]);
    // The staff member belongs to company-1 through a membership row
    $connection->table('company_users')->insert(['uuid' => 'cu-staff', 'company_uuid' => 'company-1', 'user_uuid' => 'staff-1', 'status' => 'active']);

    $staff  = ProfileAccountManager::resolveForProfile('company-1', 'driver', 'Other Name', 'STAFF@example.com', null);
    $driver = ProfileAccountManager::resolveForProfile('company-1', 'driver', 'Driver', null, '+6590001111');
    $orphan = ProfileAccountManager::resolveForProfile('company-1', 'driver', 'Orphan', null, '+6590003333');

    expect($staff->uuid)->toBe('staff-1')
        ->and($staff->type)->toBe('user')
        ->and($connection->table('model_has_roles')->where('model_uuid', 'cu-staff')->count())->toBe(0)
        ->and($driver->uuid)->toBe('driver-1')
        ->and(fleetopsProfileAccountRoles($connection, 'driver-1', 'company-1'))->toBe(['Driver'])
        // The account keeps its original organization
        ->and($connection->table('users')->where('uuid', 'driver-1')->value('company_uuid'))->toBe('company-2')
        // An account without an organization adopts this one
        ->and($orphan->uuid)->toBe('driver-2')
        ->and($connection->table('users')->where('uuid', 'driver-2')->value('company_uuid'))->toBe('company-1')
        ->and($connection->table('users')->count())->toBe(3);
});

test('resolve for profile rejects accounts that cannot hold the profile', function () {
    $connection = fleetopsProfileAccountBoot();
    fleetopsProfileAccountRows($connection, 'users', [
        ['uuid' => 'staff-2', 'company_uuid' => 'company-2', 'email' => 'outsider@example.com', 'type' => 'admin'],
        ['uuid' => 'customer-1', 'company_uuid' => 'company-1', 'phone' => '+6590001111', 'type' => 'customer'],
        ['uuid' => 'driver-1', 'company_uuid' => 'company-1', 'email' => 'driver@example.com', 'type' => 'driver'],
        ['uuid' => 'contact-1', 'company_uuid' => 'company-1', 'email' => 'contact@example.com', 'type' => 'contact'],
    ]);
    $connection->table('drivers')->insert(['uuid' => 'd-1', 'company_uuid' => 'company-1', 'user_uuid' => 'driver-1']);
    $connection->table('contacts')->insert(['uuid' => 'c-1', 'company_uuid' => 'company-1', 'user_uuid' => 'contact-1', 'type' => 'contact']);

    $conflict = function (callable $callback): ?ProfileIdentityConflictException {
        try {
            $callback();
        } catch (ProfileIdentityConflictException $exception) {
            return $exception;
        }

        return null;
    };

    $outsider = $conflict(fn () => ProfileAccountManager::resolveForProfile('company-1', 'driver', 'X', 'outsider@example.com', null));
    $wrong    = $conflict(fn () => ProfileAccountManager::resolveForProfile('company-1', 'driver', 'X', 'nobody@example.com', '+6590001111'));
    $driver   = $conflict(fn () => ProfileAccountManager::resolveForProfile('company-1', 'driver', 'X', 'driver@example.com', null));
    $contact  = $conflict(fn () => ProfileAccountManager::resolveForProfile('company-1', 'contact', 'X', 'contact@example.com', null));

    expect($outsider?->getMessage())->toBe('This email is already in use by another account.')
        ->and($outsider?->getField())->toBe('email')
        ->and($outsider?->getErrors())->toBe(['email' => ['This email is already in use by another account.']])
        ->and($wrong?->getMessage())->toBe('This phone number is already used by a customer.')
        ->and($wrong?->getField())->toBe('phone')
        ->and($driver?->getMessage())->toBe('A driver with this email already exists.')
        ->and($contact?->getMessage())->toBe('A contact with this email already exists.')
        ->and(ProfileAccountManager::hasProfileInCompany(User::where('uuid', 'driver-1')->first(), 'company-2', 'driver'))->toBeFalse()
        ->and(ProfileAccountManager::isCompanyMember(User::where('uuid', 'driver-1')->first(), null))->toBeFalse()
        ->and(new ProfileIdentityConflictException('Taken'))->getField()->toBe('email');
});

test('sync proxy fields pushes normalized changes to managed accounts and only the name to staff', function () {
    $connection = fleetopsProfileAccountBoot();
    $connection->table('users')->insert(['uuid' => 'other-1', 'email' => 'taken@example.com', 'phone' => '+6599990000', 'type' => 'user']);
    $managed = fleetopsProfileAccountUser(['uuid' => 'managed-1', 'name' => 'Old', 'email' => 'old@example.com', 'phone' => '+6590000000', 'timezone' => 'UTC', 'type' => 'driver']);
    $staff   = fleetopsProfileAccountUser(['uuid' => 'staff-1', 'name' => 'Staff', 'email' => 'staff@example.com', 'phone' => '+6591111111', 'type' => 'user']);

    expect(ProfileAccountManager::syncProxyFields(null, ['name' => 'Nobody']))->toBeFalse()
        // Unchanged values and fields outside the proxy set are ignored
        ->and(ProfileAccountManager::syncProxyFields($managed, ['name' => 'Old', 'email' => 'OLD@example.com', 'type' => 'admin', 'timezone' => null]))->toBeFalse();

    expect(ProfileAccountManager::syncProxyFields($managed, [
        'name'     => 'New',
        'email'    => ' New@Example.com ',
        'phone'    => '+65 9000 0001',
        'timezone' => 'Asia/Singapore',
        'password' => 'new-secret',
    ]))->toBeTrue();

    $row = $connection->table('users')->where('uuid', 'managed-1')->first();
    expect($row->name)->toBe('New')
        ->and($row->email)->toBe('new@example.com')
        ->and($row->phone)->toBe('+6590000001')
        ->and($row->timezone)->toBe('Asia/Singapore')
        ->and($row->password)->toBe('hashed:new-secret');

    // Clearing the phone is a change the account takes
    expect(ProfileAccountManager::syncProxyFields($managed, ['phone' => null]))->toBeTrue()
        ->and($connection->table('users')->where('uuid', 'managed-1')->value('phone'))->toBeNull();

    // A staff account only takes the name
    expect(ProfileAccountManager::syncProxyFields($staff, ['name' => 'Staff Driver', 'email' => 'changed@example.com', 'phone' => null]))->toBeTrue()
        ->and($connection->table('users')->where('uuid', 'staff-1')->first())
        ->name->toBe('Staff Driver')
        ->email->toBe('staff@example.com')
        ->phone->toBe('+6591111111');

    // Taking another account's email or phone is refused
    $emailConflict = null;
    try {
        ProfileAccountManager::syncProxyFields($managed, ['email' => 'Taken@example.com']);
    } catch (ProfileIdentityConflictException $exception) {
        $emailConflict = $exception;
    }

    $phoneConflict = null;
    try {
        ProfileAccountManager::syncProxyFields($managed, ['email' => 'new@example.com', 'phone' => '+6599990000']);
    } catch (ProfileIdentityConflictException $exception) {
        $phoneConflict = $exception;
    }

    expect($emailConflict?->getMessage())->toBe('This email is already in use by another account.')
        ->and($emailConflict?->getField())->toBe('email')
        ->and($phoneConflict?->getMessage())->toBe('This phone number is already in use by another account.')
        ->and($phoneConflict?->getField())->toBe('phone')
        ->and($connection->table('users')->where('uuid', 'managed-1')->value('email'))->toBe('new@example.com');
});

test('release for profile deletes an unused managed account and leaves staff alone', function () {
    $connection = fleetopsProfileAccountBoot();
    config()->set('activitylog.enabled', false);
    $lonely  = fleetopsProfileAccountUser(['uuid' => 'lonely-1', 'type' => 'driver']);
    $shared  = fleetopsProfileAccountUser(['uuid' => 'shared-1', 'type' => 'contact']);
    $staying = fleetopsProfileAccountUser(['uuid' => 'staying-1', 'type' => 'driver']);
    $staff   = fleetopsProfileAccountUser(['uuid' => 'staff-1', 'type' => 'user']);
    $connection->table('contacts')->insert(['uuid' => 'c-2', 'company_uuid' => 'company-2', 'user_uuid' => 'shared-1', 'type' => 'contact']);
    $connection->table('drivers')->insert(['uuid' => 'd-1', 'company_uuid' => 'company-1', 'user_uuid' => 'staying-1']);
    $connection->table('company_users')->insert([
        ['uuid' => 'cu-1', 'company_uuid' => 'company-1', 'user_uuid' => 'shared-1'],
        ['uuid' => 'cu-2', 'company_uuid' => 'company-2', 'user_uuid' => 'shared-1'],
        ['uuid' => 'cu-3', 'company_uuid' => 'company-1', 'user_uuid' => 'staying-1'],
    ]);

    ProfileAccountManager::releaseForProfile(null, 'company-1');
    ProfileAccountManager::releaseForProfile($staff, 'company-1');
    ProfileAccountManager::releaseForProfile($lonely, 'company-1');
    // Already deleted accounts are skipped
    ProfileAccountManager::releaseForProfile($lonely, 'company-1');
    ProfileAccountManager::releaseForProfile($shared, 'company-1');
    ProfileAccountManager::releaseForProfile($staying, 'company-1');
    ProfileAccountManager::releaseForProfile($staying);

    expect($connection->table('users')->where('uuid', 'lonely-1')->value('deleted_at'))->not->toBeNull()
        ->and($lonely->trashed())->toBeTrue()
        ->and($connection->table('users')->where('uuid', 'staff-1')->value('deleted_at'))->toBeNull()
        ->and($connection->table('users')->where('uuid', 'shared-1')->value('deleted_at'))->toBeNull()
        // The shared account only leaves the organization it has no profile in
        ->and($connection->table('company_users')->whereNull('deleted_at')->where('user_uuid', 'shared-1')->pluck('company_uuid')->all())->toBe(['company-2'])
        ->and($connection->table('users')->where('uuid', 'staying-1')->value('deleted_at'))->toBeNull()
        ->and($connection->table('company_users')->whereNull('deleted_at')->where('user_uuid', 'staying-1')->count())->toBe(1)
        ->and(ProfileAccountManager::profileCompanies($shared)->all())->toBe(['company-2']);
});

test('attach to company adds membership once and only re-roles managed accounts', function () {
    $connection = fleetopsProfileAccountBoot();
    $managed    = fleetopsProfileAccountUser(['uuid' => 'managed-1', 'company_uuid' => 'company-2', 'type' => 'customer']);
    $staff      = fleetopsProfileAccountUser(['uuid' => 'staff-1', 'company_uuid' => 'company-1', 'type' => 'user']);
    $connection->table('company_users')->insert(['uuid' => 'cu-staff', 'company_uuid' => 'company-1', 'user_uuid' => 'staff-1', 'status' => 'active']);

    expect(ProfileAccountManager::attachToCompany($managed, 'company-missing', 'customer'))->toBeNull();

    $first           = ProfileAccountManager::attachToCompany($managed, 'company-1', 'customer');
    $second          = ProfileAccountManager::attachToCompany($managed, 'company-1', 'unknown-type');
    $staffMembership = ProfileAccountManager::attachToCompany($staff, 'company-1', 'driver');

    expect($first->uuid)->toBe($second->uuid)
        ->and($connection->table('company_users')->where('user_uuid', 'managed-1')->count())->toBe(1)
        // An unknown type falls back to the contact role
        ->and(fleetopsProfileAccountRoles($connection, 'managed-1', 'company-1'))->toBe(['Fleet-Ops Contact'])
        ->and($staffMembership->uuid)->toBe('cu-staff')
        ->and($connection->table('model_has_roles')->where('model_uuid', 'cu-staff')->count())->toBe(0)
        ->and($connection->table('users')->where('uuid', 'managed-1')->value('company_uuid'))->toBe('company-2');
});

test('send credentials resets the password activates the account and emails or texts it', function () {
    $connection = fleetopsProfileAccountBoot();
    $mail       = Illuminate\Support\Facades\Mail::getFacadeRoot();
    $sms        = app(SmsService::class);

    $emailUser = fleetopsProfileAccountUser(['uuid' => 'email-1', 'company_uuid' => 'company-1', 'email' => 'driver@example.com', 'status' => 'pending', 'type' => 'driver']);
    $driver    = new Driver();
    $driver->setRawAttributes(['uuid' => 'd-1', 'company_uuid' => 'company-1', 'user_uuid' => 'email-1'], true);
    $driver->setRelation('company', (object) ['name' => 'Acme']);

    expect(ProfileAccountManager::sendCredentials($driver, $emailUser, 'chosen-secret'))->toBe('email')
        ->and($connection->table('users')->where('uuid', 'email-1')->value('password'))->toBe('hashed:chosen-secret')
        ->and($connection->table('users')->where('uuid', 'email-1')->value('status'))->toBe('active')
        ->and($mail->sent[0])->toBeInstanceOf(DriverCredentialsMail::class)
        ->and($mail->to[0])->toBe($emailUser);

    // An active account is not re-activated; a random password is generated
    $customerUser = fleetopsProfileAccountUser(['uuid' => 'email-2', 'company_uuid' => 'company-1', 'email' => 'customer@example.com', 'status' => 'active', 'type' => 'customer']);
    $customer     = new Contact();
    $customer->setRawAttributes(['uuid' => 'c-1', 'company_uuid' => 'company-1', 'type' => 'customer'], true);

    expect(ProfileAccountManager::sendCredentials($customer, $customerUser))->toBe('email')
        ->and($connection->table('users')->where('uuid', 'email-2')->value('password'))->toStartWith('hashed:')
        ->and($mail->sent[1])->toBeInstanceOf(CustomerCredentialsMail::class);

    // Without an email the credentials are texted, naming the organization
    $phoneUser = fleetopsProfileAccountUser(['uuid' => 'phone-1', 'phone' => '+6590001111', 'status' => 'active', 'type' => 'driver']);
    expect(ProfileAccountManager::deliverCredentials($driver, $phoneUser, 'texted-secret'))->toBe('sms')
        ->and($sms->sent[0])->toBe(['+6590001111', 'Your Acme sign-in details. Login: +6590001111 Password: texted-secret']);

    // An organization-less profile falls back to the app name
    $orphan = new Driver();
    $orphan->setRawAttributes(['uuid' => 'd-2'], true);
    $orphan->setRelation('company', null);
    ProfileAccountManager::deliverCredentials($orphan, $phoneUser, 'app-secret');
    expect($sms->sent[1][1])->toBe('Your Fleetbase sign-in details. Login: +6590001111 Password: app-secret');

    // A refused text surfaces the provider's reason
    $sms->result = ['success' => false, 'error' => 'Invalid number'];
    expect(fn () => ProfileAccountManager::deliverCredentials($driver, $phoneUser, 'x'))
        ->toThrow(Exception::class, 'The credentials could not be texted: Invalid number');

    $sms->result = ['success' => false, 'message' => 'Quota exceeded'];
    expect(fn () => ProfileAccountManager::deliverCredentials($driver, $phoneUser, 'x'))
        ->toThrow(Exception::class, 'The credentials could not be texted: Quota exceeded');

    $sms->result = ['success' => false];
    expect(fn () => ProfileAccountManager::deliverCredentials($driver, $phoneUser, 'x'))
        ->toThrow(Exception::class, 'The credentials could not be texted: the SMS provider refused it.');

    // A result without a success flag is treated as sent
    $sms->result = [];
    expect(ProfileAccountManager::deliverCredentials($driver, $phoneUser, 'x'))->toBe('sms');

    $unreachable = fleetopsProfileAccountUser(['uuid' => 'none-1', 'status' => 'active', 'type' => 'driver']);
    expect(fn () => ProfileAccountManager::deliverCredentials($driver, $unreachable, 'x'))
        ->toThrow(Exception::class, 'This profile has no email or phone to send credentials to.');
});

test('driver credentials mail names the organization and the sign-in identity', function () {
    config()->set('app.name', 'Fleetbase');
    $user = new User();
    $user->setRawAttributes(['name' => 'Dana', 'email' => null, 'phone' => '+6590001111'], true);

    $driver = new Driver();
    $driver->setRelation('company', (object) ['name' => 'Acme']);

    $mail    = new DriverCredentialsMail('plain-secret', $driver, $user);
    $content = $mail->content();

    expect($mail->envelope()->subject)->toBe('Your Acme driver sign-in details')
        ->and($content->markdown)->toBe('fleetops::mail.driver-credentials')
        ->and($content->with)->toMatchArray([
            'driver'            => $driver,
            'user'              => $user,
            'companyName'       => 'Acme',
            'identity'          => '+6590001111',
            'plaintextPassword' => 'plain-secret',
        ]);

    $user->setRawAttributes(['name' => 'Dana', 'email' => 'dana@example.com'], true);
    $orphan = new Driver();
    $orphan->setRelation('company', null);
    $orphanMail = new DriverCredentialsMail('plain-secret', $orphan, $user);

    expect($orphanMail->envelope()->subject)->toBe('Your Fleetbase driver sign-in details')
        ->and($orphanMail->content()->with)->toMatchArray(['companyName' => 'Fleetbase', 'identity' => 'dana@example.com']);

    $view = file_get_contents(__DIR__ . '/../../../resources/views/mail/driver-credentials.blade.php');
    expect($view)->toContain('{{ $identity }}')
        ->toContain('{{ $plaintextPassword }}')
        ->toContain('{{ $companyName }}');
});
