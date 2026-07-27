<?php

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
        'contacts'      => ['uuid', 'public_id', 'company_uuid', 'user_uuid', 'name', 'email', 'phone', 'type', 'title'],
        'users'         => ['uuid', 'public_id', 'company_uuid', 'name', 'email', 'phone', 'username', 'password', 'timezone', 'status', 'type'],
        'companies'     => ['uuid', 'public_id', 'name', 'timezone', 'owner_uuid'],
        'company_users' => ['uuid', 'company_uuid', 'user_uuid', 'status'],
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
