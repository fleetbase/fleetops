<?php

if (!function_exists('Fleetbase\\Observers\\event')) {
    eval('namespace Fleetbase\\Observers; function event($event = null) { return $event; }');
}

if (!Illuminate\Support\Str::hasMacro('humanize')) {
    Illuminate\Support\Str::macro('humanize', fn ($value, $uppercase = true) => str_replace('_', ' ', Illuminate\Support\Str::snake((string) $value)));
}

use Fleetbase\FleetOps\Exceptions\CustomerUserConflictException;
use Fleetbase\FleetOps\Exceptions\UserAlreadyExistsException;
use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\Models\User;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;

/**
 * Covers the Contact model's customer/user linkage behavior against an
 * in-memory SQLite fixture: user creation from contacts, existing-user
 * adoption and conflict detection, customer normalization and repair, and
 * the identity lookup helpers.
 */
class FleetOpsContactCustomerUserProbe extends Contact
{
    protected $guarded = [];
    public $exists     = true;

    public function callPrivate(string $method, mixed ...$arguments): mixed
    {
        $reflection = new ReflectionMethod(Contact::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($this, ...$arguments);
    }
}

function fleetopsContactCustomerUserBoot(): SQLiteConnection
{
    // Model uuid hooks bind to whichever dispatcher exists when the class boots,
    // so this has to be installed once and never replaced — a fresh dispatcher
    // per test silently drops the hooks for already-booted models.
    if (!EloquentModel::getEventDispatcher()) {
        EloquentModel::setEventDispatcher(new Illuminate\Events\Dispatcher());
    }
    app()->instance('responsecache', new class {
        public function __call($method, $arguments)
        {
            return null;
        }
    });
    config()->set('activitylog.enabled', false);
    config()->set('activitylog.default_auth_driver', 'web');
    app()->bind(Illuminate\Contracts\Config\Repository::class, fn () => config());

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

    $schema = $connection->getSchemaBuilder();
    $tables = [
        'contacts'              => ['uuid', 'public_id', 'company_uuid', 'user_uuid', 'name', 'email', 'phone', 'type', 'title'],
        'users'                 => ['uuid', 'public_id', 'company_uuid', 'name', 'email', 'phone', 'username', 'password', 'timezone', 'status', 'type', 'slug', 'avatar_uuid', 'last_login'],
        'companies'             => ['uuid', 'public_id', 'name', 'timezone', 'owner_uuid'],
        'company_users'         => ['uuid', 'company_uuid', 'user_uuid', 'status'],
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
    config()->set('auth.defaults.guard', 'web');
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

    session(['company' => 'company-1']);
    $connection->table('roles')->insert([
        ['uuid' => 'role-fc-1', 'name' => 'Fleet-Ops Customer', 'guard_name' => 'web', 'company_uuid' => 'company-1'],
        ['uuid' => 'role-fc-2', 'name' => 'Fleet-Ops Customer', 'guard_name' => 'sanctum', 'company_uuid' => 'company-1'],
    ]);

    return $connection;
}

function fleetopsContactCustomerUserContact(array $attributes = []): FleetOpsContactCustomerUserProbe
{
    $contact = new FleetOpsContactCustomerUserProbe();
    $contact->setRawAttributes(array_merge([
        'uuid'         => 'contact-1',
        'company_uuid' => 'company-1',
        'name'         => 'Ada Contact',
        'type'         => 'contact',
    ], $attributes), true);

    return $contact;
}

test('create user from contact provisions a pending user with the contact type', function () {
    $connection = fleetopsContactCustomerUserBoot();
    $contact    = fleetopsContactCustomerUserContact(['email' => 'ada@example.com']);

    $user = Contact::createUserFromContact($contact);

    expect($user)->toBeInstanceOf(User::class)
        ->and($connection->table('users')->count())->toBe(1)
        ->and($connection->table('users')->value('status'))->toBe('pending')
        ->and($connection->table('users')->value('type'))->toBe('contact')
        ->and($contact->user_uuid)->toBe($user->uuid);
});

test('create user from contact adopts an existing matching user', function () {
    $connection = fleetopsContactCustomerUserBoot();
    $connection->table('users')->insert(['uuid' => 'user-9', 'company_uuid' => 'company-1', 'email' => 'match@example.com', 'type' => 'contact']);
    $contact = fleetopsContactCustomerUserContact(['email' => 'match@example.com']);

    $user = Contact::createUserFromContact($contact);

    expect($user->uuid)->toBe('user-9')
        ->and($contact->user_uuid)->toBe('user-9')
        ->and($connection->table('users')->count())->toBe(1);
});

test('create user from contact rejects users already linked to another contact', function () {
    $connection = fleetopsContactCustomerUserBoot();
    // The contact user() relation constrains users.type against the querying
    // contact type, which is null in the static whereHas context — a null-typed
    // user keeps the linked-contact subquery matchable.
    $connection->table('users')->insert(['uuid' => 'user-9', 'company_uuid' => 'company-1', 'email' => 'taken@example.com', 'type' => null]);
    $connection->table('contacts')->insert(['uuid' => 'contact-owner', 'company_uuid' => 'company-1', 'user_uuid' => 'user-9', 'name' => 'Owner', 'type' => 'contact']);
    $contact = fleetopsContactCustomerUserContact(['uuid' => 'contact-2', 'email' => 'taken@example.com']);

    expect(fn () => Contact::createUserFromContact($contact))->toThrow(UserAlreadyExistsException::class);
});

test('create user from contact rejects staff users for customer contacts', function () {
    $connection = fleetopsContactCustomerUserBoot();
    $connection->table('users')->insert(['uuid' => 'user-9', 'company_uuid' => 'company-1', 'email' => 'staff@example.com', 'type' => 'staff']);
    $contact = fleetopsContactCustomerUserContact(['type' => 'customer', 'email' => 'staff@example.com']);

    expect(fn () => Contact::createUserFromContact($contact))->toThrow(CustomerUserConflictException::class);
});

test('create user from contact assigns the customer role and persists the link', function () {
    $connection = fleetopsContactCustomerUserBoot();
    $connection->table('companies')->insert(['uuid' => 'company-1', 'name' => 'Acme']);
    $connection->table('contacts')->insert([
        'uuid'         => 'contact-cust-1',
        'company_uuid' => 'company-1',
        'name'         => 'New Customer',
        'email'        => 'newcustomer@example.com',
        'type'         => 'customer',
    ]);

    $contact = Contact::where('uuid', 'contact-cust-1')->first();
    // Nothing matches the identity, so a user is provisioned rather than adopted
    $user = Contact::createUserFromContact($contact, false, true);

    // Roles attach to the company-user record the user proxies through
    $assignment = $connection->table('model_has_roles')->first();

    expect($user->uuid)->not->toBeNull()
        ->and($connection->table('users')->where('uuid', $user->uuid)->value('email'))->toBe('newcustomer@example.com')
        ->and($assignment)->not->toBeNull()
        ->and($connection->table('roles')->where('id', $assignment->role_id)->value('name'))->toBe('Fleet-Ops Customer')
        ->and($connection->table('company_users')->where('uuid', $assignment->model_uuid)->value('user_uuid'))->toBe($user->uuid)
        // and the link is written back to the contact row rather than only
        // being set in memory
        ->and($connection->table('contacts')->where('uuid', 'contact-cust-1')->value('user_uuid'))->toBe($user->uuid);
});

test('normalize customer user passes through non customers and missing users', function () {
    fleetopsContactCustomerUserBoot();

    $nonCustomer = fleetopsContactCustomerUserContact();
    $user        = new User();
    $user->setRawAttributes(['uuid' => 'user-1', 'type' => 'customer'], true);
    expect($nonCustomer->normalizeCustomerUser($user))->toBe($user);

    $customer = fleetopsContactCustomerUserContact(['type' => 'customer']);
    $customer->setRelation('user', null);
    expect($customer->normalizeCustomerUser())->toBeNull();
});

test('normalize customer user links the user when no company record exists', function () {
    fleetopsContactCustomerUserBoot();

    $customer = fleetopsContactCustomerUserContact(['type' => 'customer', 'company_uuid' => 'company-missing']);
    $user     = new User();
    $user->setRawAttributes(['uuid' => 'user-1', 'type' => 'customer'], true);

    $normalized = $customer->normalizeCustomerUser($user);

    expect($normalized)->toBe($user)
        ->and($customer->getRelation('user'))->toBe($user);
});

test('repair customer type invariant creates a quiet customer user from identity', function () {
    $connection = fleetopsContactCustomerUserBoot();

    $nonCustomer = fleetopsContactCustomerUserContact();
    expect($nonCustomer->repairCustomerTypeInvariant())->toBeNull();

    $customer = fleetopsContactCustomerUserContact([
        'uuid'         => 'contact-9',
        'type'         => 'customer',
        'email'        => 'repair@example.com',
        'company_uuid' => 'company-missing',
    ]);
    $customer->setRelation('user', null);

    $user = $customer->repairCustomerTypeInvariant(true);

    expect($user)->toBeInstanceOf(User::class)
        ->and($connection->table('users')->value('type'))->toBe('customer')
        ->and($connection->table('contacts')->count())->toBe(0);
});

test('identity lookup helpers resolve users by email phone and uuid', function () {
    $connection = fleetopsContactCustomerUserBoot();
    $connection->table('users')->insert(['uuid' => 'user-1', 'company_uuid' => 'company-1', 'email' => 'find@example.com', 'phone' => '+15550001', 'type' => 'customer']);

    $byEmail = fleetopsContactCustomerUserContact(['email' => 'find@example.com']);
    expect($byEmail->callPrivate('findExistingUserByIdentity')?->uuid)->toBe('user-1');

    $byPhone = fleetopsContactCustomerUserContact(['phone' => '+15550001']);
    expect($byPhone->callPrivate('findExistingUserByIdentity')?->uuid)->toBe('user-1');

    $noIdentity = fleetopsContactCustomerUserContact();
    expect($noIdentity->callPrivate('findExistingUserByIdentity'))->toBeNull();

    $assigned = fleetopsContactCustomerUserContact(['user_uuid' => '11111111-1111-4111-8111-111111111111']);
    expect($assigned->callPrivate('getAssignedUserWithoutTypeScope'))->toBeNull();

    $badUuid = fleetopsContactCustomerUserContact(['user_uuid' => 'not-a-uuid']);
    expect($badUuid->callPrivate('getAssignedUserWithoutTypeScope'))->toBeNull();
});

test('assert customer identity is available detects staff conflicts', function () {
    $connection = fleetopsContactCustomerUserBoot();
    $connection->table('users')->insert(['uuid' => 'user-1', 'company_uuid' => 'company-1', 'email' => 'staff@example.com', 'type' => 'staff']);

    $nonCustomer = fleetopsContactCustomerUserContact();
    $nonCustomer->assertCustomerIdentityIsAvailable();

    $conflicted = fleetopsContactCustomerUserContact(['type' => 'customer', 'email' => 'staff@example.com']);
    expect(fn () => $conflicted->assertCustomerIdentityIsAvailable())->toThrow(CustomerUserConflictException::class);

    // An already-assigned user is checked ahead of the identity lookup, so a
    // contact whose email matches nothing still trips on its own assignment
    $connection->table('users')->insert(['uuid' => '33333333-3333-4333-8333-333333333333', 'company_uuid' => 'company-1', 'email' => 'assigned@example.com', 'type' => 'staff']);
    $assigned = fleetopsContactCustomerUserContact([
        'type'      => 'customer',
        'email'     => 'unmatched@example.com',
        'user_uuid' => '33333333-3333-4333-8333-333333333333',
    ]);
    expect(fn () => $assigned->assertCustomerIdentityIsAvailable())->toThrow(CustomerUserConflictException::class);
});

test('get user resolves from relation database or returns null', function () {
    $connection = fleetopsContactCustomerUserBoot();
    $connection->table('users')->insert(['uuid' => '22222222-2222-4222-8222-222222222222', 'company_uuid' => 'company-1', 'type' => 'customer']);

    $withRelation = fleetopsContactCustomerUserContact();
    $user         = new User();
    $user->setRawAttributes(['uuid' => 'user-r', 'type' => 'customer'], true);
    $withRelation->setRelation('user', $user);
    expect($withRelation->getUser())->toBe($user);

    $byUuid = fleetopsContactCustomerUserContact(['user_uuid' => '22222222-2222-4222-8222-222222222222']);
    $byUuid->setRelation('user', null);
    expect($byUuid->getUser()?->uuid)->toBe('22222222-2222-4222-8222-222222222222');

    $none = fleetopsContactCustomerUserContact();
    $none->setRelation('user', null);
    expect($none->getUser())->toBeNull();
});

test('phone identities and update flags adopt users and persist assignment', function () {
    $connection = fleetopsContactCustomerUserBoot();
    $connection->table('users')->insert(['uuid' => 'user-p1', 'company_uuid' => 'company-1', 'name' => 'Phone Match', 'email' => 'other@example.com', 'phone' => '+6597770001', 'type' => 'customer', 'status' => 'active']);
    $connection->table('contacts')->insert(['uuid' => 'contact-p1', 'company_uuid' => 'company-1', 'name' => 'Phone Contact', 'email' => 'nomatch@example.com', 'phone' => '+6597770001', 'type' => 'customer']);

    $contact = Contact::where('uuid', 'contact-p1')->first();
    $user    = Contact::createUserFromContact($contact, false, true);

    expect($user->uuid)->toBe('user-p1')
        ->and($connection->table('contacts')->where('uuid', 'contact-p1')->value('user_uuid'))->toBe('user-p1');
});

test('normalize customer user creates company membership and assigns the customer role', function () {
    $connection = fleetopsContactCustomerUserBoot();
    $connection->table('companies')->insert(['uuid' => 'company-1', 'name' => 'Acme']);
    $connection->table('users')->insert(['uuid' => 'user-n1', 'company_uuid' => 'company-1', 'name' => 'Normalized', 'email' => 'norm@example.com', 'type' => 'customer', 'status' => 'active']);
    $connection->table('contacts')->insert(['uuid' => 'contact-n1', 'company_uuid' => 'company-1', 'name' => 'Normalized Contact', 'email' => 'norm@example.com', 'type' => 'customer', 'user_uuid' => 'user-n1']);

    $contact = Contact::where('uuid', 'contact-n1')->first();
    $user    = User::where('uuid', 'user-n1')->first();
    $contact->normalizeCustomerUser($user);

    expect($connection->table('company_users')->where('user_uuid', 'user-n1')->count())->toBe(1)
        ->and($connection->table('model_has_roles')->count())->toBeGreaterThanOrEqual(1);
});
