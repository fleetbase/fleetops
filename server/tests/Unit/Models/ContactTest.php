<?php

use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\Models\User;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;

class FleetOpsContactUnitUserFake extends User
{
    public array $quietSaves = [];
    public array $roles      = [];

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
}

class FleetOpsContactUnitFake extends Contact
{
    public ?User $fakeUser        = null;
    public ?User $createdUser     = null;
    public ?User $normalizedUser  = null;
    public array $loadedMissing   = [];
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

function fleetopsContactUnitUseInMemoryConnection(): SQLiteConnection
{
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
    $connection->statement('create table orders (uuid varchar(64), customer_uuid varchar(64), deleted_at datetime null)');
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
