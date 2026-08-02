<?php

use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\Models\Company;
use Fleetbase\Models\CompanyUser;
use Fleetbase\Models\User;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;

class FleetOpsContactUnitCompanyUserFake extends CompanyUser
{
    public array $roles = [];

    public function assignSingleRole($role): self
    {
        $this->roles[] = $role;

        return $this;
    }
}

class FleetOpsContactUnitCompanyFake extends Company
{
    public array $addUserCalls                              = [];
    public ?FleetOpsContactUnitCompanyUserFake $companyUser = null;

    public function addUser(User $user, string $role = 'Administrator', string $status = 'active'): CompanyUser
    {
        $this->addUserCalls[] = [$user, $role, $status];

        return $this->companyUser ??= new FleetOpsContactUnitCompanyUserFake([
            'company_uuid' => $this->uuid,
            'user_uuid'    => $user->uuid,
            'status'       => $status,
        ]);
    }
}

class FleetOpsContactUnitUserFake extends User
{
    public array $quietSaves = [];
    public array $roles      = [];
    public array $updates    = [];
    public bool $deleted     = false;

    public function saveQuietly(array $options = [])
    {
        $this->quietSaves[] = $options;

        return true;
    }

    public function assignSingleRole($role): User
    {
        $this->roles[] = $role;

        return $this;
    }

    public function getCompanyUser(?Company $company = null): ?CompanyUser
    {
        return $this->getRelation('companyUser');
    }

    public function update(array $attributes = [], array $options = []): bool
    {
        $this->updates[] = $attributes;
        $this->forceFill($attributes);

        return true;
    }

    public function delete()
    {
        $this->deleted = true;

        return true;
    }
}

class FleetOpsContactUnitFake extends Contact
{
    public ?User $fakeUser        = null;
    public ?User $createdUser     = null;
    public ?User $normalizedUser  = null;
    public array $loadedMissing   = [];
    public array $updates         = [];
    public bool $createUserCalled = false;
    public bool $normalizeCalled  = false;

    public function loadMissing($relations)
    {
        $this->loadedMissing[] = $relations;

        return $this;
    }

    public function getUser(): ?User
    {
        return $this->fakeUser;
    }

    public function createUser(bool $sendInvite = false): User
    {
        $this->createUserCalled = true;

        return $this->createdUser;
    }

    public function normalizeCustomerUser(?User $user = null, bool $quiet = false): ?User
    {
        $this->normalizeCalled = true;
        $this->normalizedUser  = $user;

        return $user;
    }

    public function update(array $attributes = [], array $options = []): bool
    {
        $this->updates[] = $attributes;
        $this->forceFill($attributes);

        return true;
    }
}

class FleetOpsContactUnitCreateUserFake extends Contact
{
    public static ?User $nextUser    = null;
    public static array $createCalls = [];

    public static function createUserFromContact(Contact $contact, bool $sendInvite = false, bool $update = false): User
    {
        static::$createCalls[] = [$contact, $sendInvite, $update];

        return static::$nextUser;
    }
}

class FleetOpsContactUnitExistingContactQueryFake
{
    public array $whereNotCalls = [];
    public array $whereHasCalls = [];

    public function __construct(public ?Contact $result = null)
    {
    }

    public function whereNot(string $column, mixed $value): self
    {
        $this->whereNotCalls[] = [$column, $value];

        return $this;
    }

    public function whereHas(string $relation): self
    {
        $this->whereHasCalls[] = $relation;

        return $this;
    }

    public function first(): ?Contact
    {
        return $this->result;
    }
}

class FleetOpsContactUnitAssignableFake extends FleetOpsContactUnitFake
{
    public static ?FleetOpsContactUnitExistingContactQueryFake $query = null;
    public static array $whereCalls                                   = [];

    public static function where($column, $operator = null, $value = null, $boolean = 'and')
    {
        static::$whereCalls[] = [$column, $operator, $value, $boolean];

        return static::$query;
    }
}

function fleetopsContactUnitUseInMemoryConnection(): SQLiteConnection
{
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
    $connection->statement('create table orders (uuid varchar(64), customer_uuid varchar(64), deleted_at datetime null)');
    $connection->statement('create table users (uuid varchar(64), company_uuid varchar(64) null, name varchar(255) null, email varchar(255) null, phone varchar(255) null, type varchar(64) null, deleted_at datetime null)');
    $resolver = new ConnectionResolver([
        'default' => $connection,
        'mysql'   => $connection,
    ]);

    $resolver->setDefaultConnection('mysql');
    EloquentModel::setConnectionResolver($resolver);

    return $connection;
}

test('contact customer order count and normalization early exits avoid persistence', function () {
    $connection = fleetopsContactUnitUseInMemoryConnection();
    $connection->table('orders')->insert([
        ['uuid' => 'order-a', 'customer_uuid' => 'contact-uuid', 'deleted_at' => null],
        ['uuid' => 'order-b', 'customer_uuid' => 'contact-uuid', 'deleted_at' => null],
        ['uuid' => 'order-c', 'customer_uuid' => 'contact-uuid', 'deleted_at' => '2026-01-01 00:00:00'],
    ]);

    $contact = new Contact();
    $contact->setRawAttributes([
        'uuid' => 'contact-uuid',
        'type' => 'contact',
    ], true);

    $candidate = new FleetOpsContactUnitUserFake();
    $candidate->setRawAttributes(['uuid' => 'candidate-user', 'type' => 'contact'], true);

    $nonCustomer = new FleetOpsContactUnitFake(['type' => 'contact']);

    expect($contact->customer_orders_count)->toBe(2)
        ->and($nonCustomer->normalizeCustomerUser($candidate))->toBe($candidate)
        ->and($nonCustomer->loadedMissing)->toBe([]);

    $customer = new Contact(['type' => 'customer']);

    expect($customer->normalizeCustomerUser())->toBeNull();
});

test('contact repair delegates customer user creation and normalization when needed', function () {
    $created = new FleetOpsContactUnitUserFake();
    $created->setRawAttributes(['uuid' => 'created-user', 'type' => 'customer'], true);

    $customer = new FleetOpsContactUnitFake([
        'type'  => 'customer',
        'email' => 'new-customer@example.test',
    ]);
    $customer->createdUser = $created;

    expect($customer->repairCustomerTypeInvariant())->toBe($created)
        ->and($customer->createUserCalled)->toBeTrue()
        ->and($customer->normalizeCalled)->toBeTrue()
        ->and($customer->normalizedUser)->toBe($created);

    $contact = new FleetOpsContactUnitFake(['type' => 'contact']);
    $user    = new FleetOpsContactUnitUserFake();
    $user->setRawAttributes(['uuid' => 'existing-user'], true);
    $contact->fakeUser = $user;

    expect($contact->repairCustomerTypeInvariant())->toBe($user)
        ->and($contact->createUserCalled)->toBeFalse()
        ->and($contact->normalizeCalled)->toBeFalse();
});

test('contact create user dispatches through late static contact factory', function () {
    $user = new FleetOpsContactUnitUserFake();
    $user->setRawAttributes(['uuid' => 'created-user'], true);

    FleetOpsContactUnitCreateUserFake::$nextUser    = $user;
    FleetOpsContactUnitCreateUserFake::$createCalls = [];

    $contact = new FleetOpsContactUnitCreateUserFake(['type' => 'customer']);

    expect($contact->createUser(true))->toBe($user)
        ->and(FleetOpsContactUnitCreateUserFake::$createCalls)->toHaveCount(1)
        ->and(FleetOpsContactUnitCreateUserFake::$createCalls[0][0])->toBe($contact)
        ->and(FleetOpsContactUnitCreateUserFake::$createCalls[0][1])->toBeTrue()
        ->and(FleetOpsContactUnitCreateUserFake::$createCalls[0][2])->toBeFalse();
});

test('contact customer identity guard returns early for non customers and invalid user ids', function () {
    $contact = new Contact([
        'type'      => 'contact',
        'email'     => 'staff@example.test',
        'user_uuid' => 'not-a-uuid',
    ]);

    expect($contact->assertCustomerIdentityIsAvailable())->toBeNull();

    $customer = new Contact([
        'type'      => 'customer',
        'user_uuid' => 'not-a-uuid',
    ]);

    expect($customer->assertCustomerIdentityIsAvailable())->toBeNull();
});

test('contact company assignment helper returns null when no company is loaded', function () {
    $contact = new FleetOpsContactUnitFake(['type' => 'customer']);
    $user    = new FleetOpsContactUnitUserFake();
    $user->setRawAttributes(['uuid' => 'user-uuid'], true);

    $reflection = new ReflectionMethod(Contact::class, 'assignUserToContactCompany');
    $reflection->setAccessible(true);

    expect($reflection->invoke(null, $contact, $user))->toBeNull()
        ->and($contact->loadedMissing)->toBe(['company'])
        ->and($user->company_uuid)->toBeNull()
        ->and($user->quietSaves)->toBe([]);
});

test('contact notification routes import values and basic accessors are stable', function () {
    session(['company' => 'company-import']);

    $contact = new Contact([
        'type'  => 'customer',
        'phone' => '+1 (555) 100-2000',
    ]);
    $contact->setRelation('devices', collect([
        (object) ['platform' => 'android', 'token' => 'android-token'],
        (object) ['platform' => 'ios', 'token' => 'ios-token'],
        (object) ['platform' => 'web', 'token' => 'web-token'],
    ]));
    $contact->setRelation('photo', (object) ['url' => 'https://example.test/photo.png']);

    $imported = Contact::createFromImport([
        'full_name'      => 'Imported Contact',
        'mobile_number'  => '+1 555 333 4444',
        'email_address'  => 'imported@example.test',
        'empty_ignored'  => null,
    ]);

    expect($contact->routeNotificationForFcm())->toBe(['android-token'])
        ->and(array_values($contact->routeNotificationForApn()))->toBe(['ios-token'])
        ->and($contact->routeNotificationForTwilio())->toBe($contact->phone)
        ->and($contact->photo_url)->toBe('https://example.test/photo.png')
        ->and($contact->is_customer)->toBeTrue()
        ->and($contact->isCustomer())->toBeTrue()
        ->and((new Contact())->photo_url)->toBe('https://s3.ap-southeast-1.amazonaws.com/flb-assets/static/no-avatar.png')
        ->and($imported->company_uuid)->toBe('company-import')
        ->and($imported->name)->toBe('Imported Contact')
        ->and($imported->email)->toBe('imported@example.test')
        ->and($imported->type)->toBe('contact');
});

test('contact import rows can persist when requested', function () {
    $connection = fleetopsContactUnitUseInMemoryConnection();
    $connection->statement('create table contacts (id integer primary key autoincrement, uuid varchar(64) null, company_uuid varchar(64) null, name varchar(255) null, email varchar(255) null, phone varchar(255) null, type varchar(64) null, created_at datetime null, updated_at datetime null)');

    session(['company' => 'company-save-import']);

    $contact = Contact::createFromImport([
        'person'    => 'Saved Contact',
        'telephone' => '+1 555 900 1000',
        'email'     => 'saved@example.test',
    ], true);

    expect($contact->exists)->toBeTrue()
        ->and($contact->company_uuid)->toBe('company-save-import')
        ->and($contact->name)->toBe('Saved Contact')
        ->and($connection->table('contacts')->where('email', 'saved@example.test')->exists())->toBeTrue();
});

test('contact assigns non customer users to company and contact role without invitations', function () {
    $companyUser = new FleetOpsContactUnitCompanyUserFake([
        'company_uuid' => 'company-uuid',
        'user_uuid'    => 'user-uuid',
    ]);
    $companyUser->setRawAttributes([
        'uuid'         => 'company-user-uuid',
        'company_uuid' => 'company-uuid',
        'user_uuid'    => 'user-uuid',
        'status'       => 'active',
    ], true);

    $company = new FleetOpsContactUnitCompanyFake();
    $company->setRawAttributes([
        'uuid'     => 'company-uuid',
        'timezone' => 'Asia/Singapore',
    ], true);
    $company->companyUser = $companyUser;

    $user = new FleetOpsContactUnitUserFake();
    $user->setRawAttributes([
        'uuid' => 'user-uuid',
        'type' => 'contact',
    ], true);
    $user->setRelation('companyUser', $companyUser);

    $contact = new FleetOpsContactUnitFake([
        'uuid'         => 'contact-uuid',
        'company_uuid' => 'company-uuid',
        'type'         => 'contact',
    ]);
    $contact->setRelation('company', $company);

    expect($contact->assignUser($user))->toBe($contact)
        ->and($contact->loadedMissing)->toContain('company')
        ->and($company->addUserCalls)->toHaveCount(1)
        ->and($company->addUserCalls[0][0])->toBe($user)
        ->and($company->addUserCalls[0][1])->toBe('Fleet-Ops Contact')
        ->and($user->company_uuid)->toBe('company-uuid')
        ->and($user->quietSaves)->toBe([[]])
        ->and($user->getRelation('companyUser'))->toBe($companyUser)
        ->and($companyUser->roles)->toBe(['Fleet-Ops Contact'])
        ->and($contact->updates)->toBe([['user_uuid' => 'user-uuid']])
        ->and($contact->getRelation('user'))->toBe($user);
});

test('contact assigns customer users after scoped duplicate contact lookup', function () {
    $companyUser = new FleetOpsContactUnitCompanyUserFake([
        'company_uuid' => 'company-uuid',
        'user_uuid'    => 'customer-user',
    ]);
    $companyUser->setRawAttributes([
        'uuid'         => 'company-user-uuid',
        'company_uuid' => 'company-uuid',
        'user_uuid'    => 'customer-user',
        'status'       => 'active',
    ], true);

    $company = new FleetOpsContactUnitCompanyFake();
    $company->setRawAttributes(['uuid' => 'company-uuid'], true);
    $company->companyUser = $companyUser;

    $user = new FleetOpsContactUnitUserFake();
    $user->setRawAttributes([
        'uuid' => 'customer-user',
        'type' => 'customer',
    ], true);
    $user->setRelation('companyUser', $companyUser);

    $query                                         = new FleetOpsContactUnitExistingContactQueryFake();
    FleetOpsContactUnitAssignableFake::$query      = $query;
    FleetOpsContactUnitAssignableFake::$whereCalls = [];

    $contact = new FleetOpsContactUnitAssignableFake();
    $contact->setRawAttributes([
        'uuid'         => 'contact-uuid',
        'company_uuid' => 'company-uuid',
        'type'         => 'customer',
    ], true);
    $contact->setRelation('company', $company);

    expect($contact->assignUser($user))->toBe($contact)
        ->and(FleetOpsContactUnitAssignableFake::$whereCalls[0][0])->toBe([
            'user_uuid'    => 'customer-user',
            'company_uuid' => 'company-uuid',
        ])
        ->and($query->whereNotCalls)->toBe([['uuid', 'contact-uuid']])
        ->and($query->whereHasCalls)->toBe(['user'])
        ->and($company->addUserCalls[0][1])->toBe('Fleet-Ops Customer')
        ->and($companyUser->roles)->toBe(['Fleet-Ops Customer'])
        ->and($contact->updates)->toBe([['user_uuid' => 'customer-user']])
        ->and($contact->getRelation('user'))->toBe($user);
});

test('contact customer assignment rejects users already linked to another contact', function () {
    $query                                         = new FleetOpsContactUnitExistingContactQueryFake(new Contact());
    FleetOpsContactUnitAssignableFake::$query      = $query;
    FleetOpsContactUnitAssignableFake::$whereCalls = [];

    $contact = new FleetOpsContactUnitAssignableFake();
    $contact->setRawAttributes([
        'uuid'         => 'contact-uuid',
        'company_uuid' => 'company-uuid',
        'type'         => 'customer',
    ], true);

    $user = new FleetOpsContactUnitUserFake();
    $user->setRawAttributes([
        'uuid' => 'customer-user',
        'type' => 'customer',
    ], true);

    expect(fn () => $contact->assignUser($user))->toThrow(
        Fleetbase\FleetOps\Exceptions\UserAlreadyExistsException::class,
        'User already exists'
    )
        ->and(FleetOpsContactUnitAssignableFake::$whereCalls[0][0])->toBe([
            'user_uuid'    => 'customer-user',
            'company_uuid' => 'company-uuid',
        ])
        ->and($query->whereNotCalls)->toBe([['uuid', 'contact-uuid']])
        ->and($query->whereHasCalls)->toBe(['user']);
});

test('contact user conflict helpers guard staff users and allow customers', function () {
    $staff = new User([
        'type'  => 'admin',
        'email' => 'staff@example.test',
    ]);
    $phoneStaff = new User([
        'type'  => 'dispatcher',
        'phone' => '+15551234567',
    ]);
    $customer = new FleetOpsContactUnitUserFake();
    $customer->setRawAttributes(['type' => 'customer'], true);
    $contact  = new Contact(['type' => 'customer']);

    expect(Contact::customerUserConflictMessage($staff))->toContain('email')
        ->and(Contact::customerUserConflictMessage($phoneStaff))->toContain('phone number')
        ->and(Contact::customerUserConflictMessage())->toContain('user account')
        ->and($contact->assertCustomerUserCanBeAssigned($customer))->toBeNull()
        ->and(fn () => $contact->assertCustomerUserCanBeAssigned($staff))->toThrow(
            Fleetbase\FleetOps\Exceptions\CustomerUserConflictException::class,
            'existing staff user'
        );
});

test('contact sync delete and user presence helpers use loaded user relations', function () {
    $user = new FleetOpsContactUnitUserFake();
    $user->setRawAttributes([
        'uuid' => 'user-uuid',
        'type' => 'customer',
    ], true);

    $contact = new FleetOpsContactUnitFake();
    $contact->setRawAttributes([
        'uuid'      => 'contact-uuid',
        'type'      => 'customer',
        'user_uuid' => 'user-uuid',
        'name'      => 'Original Name',
        'email'     => 'original@example.test',
        'phone'     => '+15550000000',
        'timezone'  => 'UTC',
    ], true);
    $contact->syncOriginal();
    $contact->forceFill([
        'name'     => 'Updated Name',
        'email'    => 'updated@example.test',
        'phone'    => '+15551112222',
        'timezone' => 'Asia/Singapore',
    ]);
    $contact->setRelation('user', $user);
    $contact->fakeUser = $user;

    expect($contact->syncWithUser())->toBeTrue()
        ->and($user->updates)->toBe([[
            'name'     => 'Updated Name',
            'email'    => 'updated@example.test',
            'phone'    => '+15551112222',
            'timezone' => 'Asia/Singapore',
        ]])
        ->and($contact->deleteUser())->toBeTrue()
        ->and($user->deleted)->toBeTrue()
        ->and($contact->getUser())->toBe($user)
        ->and($contact->hasUser())->toBeTrue()
        ->and($contact->doesntHaveUser())->toBeFalse();

    $unlinked = new FleetOpsContactUnitFake(['type' => 'contact']);

    expect($unlinked->syncWithUser())->toBeFalse()
        ->and($unlinked->deleteUser())->toBeFalse()
        ->and($unlinked->hasUser())->toBeFalse()
        ->and($unlinked->doesntHaveUser())->toBeTrue();
});

test('contact delete user ignores mismatched relation types', function () {
    $user = new FleetOpsContactUnitUserFake();
    $user->setRawAttributes([
        'uuid' => 'user-uuid',
        'type' => 'customer',
    ], true);

    $contact = new FleetOpsContactUnitFake(['type' => 'contact']);
    $contact->setRelation('user', $user);

    expect($contact->deleteUser())->toBeFalse()
        ->and($user->deleted)->toBeFalse();
});

test('contact real user helpers return loaded relations without database lookup', function () {
    $user = new FleetOpsContactUnitUserFake();
    $user->setRawAttributes([
        'uuid' => 'loaded-user',
        'type' => 'contact',
    ], true);

    $contact = new Contact([
        'type'      => 'contact',
        'user_uuid' => 'not-a-real-uuid',
    ]);
    $contact->setRelation('user', $user);

    expect($contact->normalizeCustomerUser($user))->toBe($user)
        ->and($contact->getUser())->toBe($user)
        ->and($contact->hasUser())->toBeTrue()
        ->and($contact->doesntHaveUser())->toBeFalse();
});
