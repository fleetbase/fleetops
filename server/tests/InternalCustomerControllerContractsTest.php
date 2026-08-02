<?php

use Fleetbase\FleetOps\Http\Controllers\Internal\v1\CustomerController;
use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\Models\User;
use Illuminate\Http\Request;

if (!class_exists('Illuminate\Foundation\Auth\User')) {
    eval('namespace Illuminate\Foundation\Auth; class User extends \Illuminate\Database\Eloquent\Model {}');
}

class FleetOpsInternalCustomerControllerProbe extends CustomerController
{
    public ?Contact $customer     = null;
    public ?User $user            = null;
    public ?User $createdUser     = null;
    public string $password       = 'fixed-secret';
    public array $sentCredentials = [];
    public array $userLookups     = [];
    public array $customerLookups = [];

    protected function resolveCustomer(Request $request): Contact
    {
        $this->customerLookups[] = $request->input('customer');

        return $this->customer;
    }

    protected function findUser(string $uuid): ?User
    {
        $this->userLookups[] = $uuid;

        return $this->user;
    }

    protected function createUserFromCustomer(Contact $customer): ?User
    {
        return $this->createdUser;
    }

    protected function randomPassword(): string
    {
        return $this->password;
    }

    protected function sendCustomerCredentials(User $user, string $password, Contact $customer): void
    {
        $this->sentCredentials[] = [$user->uuid, $password, $customer->uuid];
    }

    protected function freshCustomer(Contact $customer): Contact
    {
        return $customer;
    }
}

class FleetOpsInternalCustomerContactFake extends Contact
{
    public array $loadedMissing = [];

    public function loadMissing($relations)
    {
        $this->loadedMissing[] = $relations;

        return $this;
    }
}

class FleetOpsInternalCustomerUserFake extends User
{
    public array $passwords   = [];
    public int $activations   = 0;
    public int $deactivations = 0;

    public function activate(): User
    {
        $this->activations++;
        $this->status = 'active';

        return $this;
    }

    public function deactivate(): User
    {
        $this->deactivations++;
        $this->status = 'inactive';

        return $this;
    }

    public function changePassword($newPassword): User
    {
        $this->passwords[] = $newPassword;

        return $this;
    }

    public function getAvatarUrlAttribute(): string
    {
        return $this->attributes['avatar_url'] ?? '';
    }

    public function getAttribute($key)
    {
        if (array_key_exists($key, $this->attributes)) {
            return $this->attributes[$key];
        }

        return null;
    }
}

function fleetopsInternalCustomer(): FleetOpsInternalCustomerContactFake
{
    $customer = new FleetOpsInternalCustomerContactFake();
    $customer->setRawAttributes([
        'uuid'      => 'customer-uuid',
        'public_id' => 'customer_public',
        'user_uuid' => 'user-uuid',
    ], true);

    return $customer;
}

function fleetopsInternalCustomerUser(string $status = 'active'): FleetOpsInternalCustomerUserFake
{
    $user = new FleetOpsInternalCustomerUserFake();
    $user->setRawAttributes([
        'uuid'           => 'user-uuid',
        'public_id'      => 'user_public',
        'name'           => 'Ada Customer',
        'email'          => 'ada@example.test',
        'phone'          => '+15551234567',
        'status'         => $status,
        'session_status' => 'online',
        'avatar_url'     => 'https://example.test/avatar.png',
    ], true);

    return $user;
}

function fleetopsInternalCustomerController(?User $user = null, ?Contact $customer = null): FleetOpsInternalCustomerControllerProbe
{
    $controller           = new FleetOpsInternalCustomerControllerProbe();
    $controller->customer = $customer ?? fleetopsInternalCustomer();
    $controller->user     = $user;
    $controller->customer->setRelation('user', $user);

    return $controller;
}

function fleetopsInternalCustomerJson(mixed $response): array
{
    return $response->getData(true);
}

test('internal customer controller creates and toggles portal login users', function () {
    $inactiveUser = fleetopsInternalCustomerUser('pending');
    $controller   = fleetopsInternalCustomerController($inactiveUser);

    $created = fleetopsInternalCustomerJson($controller->createPortalLogin(new Request(['customer' => 'customer_public'])));

    $activeUser       = fleetopsInternalCustomerUser('active');
    $activeController = fleetopsInternalCustomerController($activeUser);
    $deactivated      = fleetopsInternalCustomerJson($activeController->deactivatePortalLogin(new Request(['customer' => 'customer_public'])));
    $reactivated      = fleetopsInternalCustomerJson($activeController->reactivatePortalLogin(new Request(['customer' => 'customer_public'])));

    expect($created['customer'])->toMatchArray([
        'id'        => 'customer_public',
        'uuid'      => 'customer-uuid',
        'user_uuid' => 'user-uuid',
    ])
        ->and($created['customer']['user']['uuid'])->toBe('user-uuid')
        ->and($created['customer']['user']['status'])->toBe('active')
        ->and($inactiveUser->activations)->toBe(1)
        ->and($controller->userLookups)->toBe(['user-uuid'])
        ->and($deactivated['customer']['user']['status'])->toBe('inactive')
        ->and($activeUser->deactivations)->toBe(1)
        ->and($reactivated['customer']['user']['status'])->toBe('active')
        ->and($activeUser->activations)->toBe(1);
});

test('internal customer controller sends credentials and resets passwords', function () {
    $user       = fleetopsInternalCustomerUser();
    $controller = fleetopsInternalCustomerController($user);

    $sent = fleetopsInternalCustomerJson($controller->sendCredentials(new Request(['customer' => 'customer_public'])));

    $reset = fleetopsInternalCustomerJson($controller->resetCredentials(new Request([
        'customer'              => 'customer_public',
        'password'              => 'chosen-secret',
        'password_confirmation' => 'chosen-secret',
        'send_credentials'      => true,
    ])));

    expect($sent['customer']['user']['uuid'])->toBe('user-uuid')
        ->and($user->passwords[0])->toBe('fixed-secret')
        ->and($controller->sentCredentials[0])->toBe(['user-uuid', 'fixed-secret', 'customer-uuid'])
        ->and($reset)->toMatchArray(['status' => 'ok'])
        ->and($user->passwords[1])->toBe('chosen-secret')
        ->and($controller->sentCredentials[1])->toBe(['user-uuid', 'chosen-secret', 'customer-uuid']);
});

test('internal customer controller can create missing customer users and reports portal errors', function () {
    $createdUser         = fleetopsInternalCustomerUser('active');
    $customer            = fleetopsInternalCustomer();
    $customer->user_uuid = null;

    $controller              = fleetopsInternalCustomerController(null, $customer);
    $controller->createdUser = $createdUser;
    $customer->setRelation('user', $createdUser);

    $created = fleetopsInternalCustomerJson($controller->createPortalLogin(new Request(['customer' => 'customer_public'])));
    $missing = fleetopsInternalCustomerController(null);

    expect($created['customer']['user']['uuid'])->toBe('user-uuid')
        ->and(fleetopsInternalCustomerJson($missing->createPortalLogin(new Request(['customer' => 'customer_public'])))['error'])->toBe('Unable to create customer portal login.')
        ->and(fleetopsInternalCustomerJson($missing->sendCredentials(new Request(['customer' => 'customer_public'])))['error'])->toBe('Unable to send customer portal credentials.')
        ->and(fleetopsInternalCustomerJson($missing->deactivatePortalLogin(new Request(['customer' => 'customer_public'])))['error'])->toBe('Customer portal login not found.')
        ->and(fleetopsInternalCustomerJson($missing->reactivatePortalLogin(new Request(['customer' => 'customer_public'])))['error'])->toBe('Customer portal login not found.');
});

test('internal customer controller validates reset credential error branches', function () {
    $missingUser = fleetopsInternalCustomerController(null);
    $withUser    = fleetopsInternalCustomerController(fleetopsInternalCustomerUser());

    expect(fleetopsInternalCustomerJson($withUser->resetCredentials(new Request()))['error'])->toBe('No customer specified to change password for.')
        ->and(fleetopsInternalCustomerJson($withUser->resetCredentials(new Request([
            'customer'              => 'customer_public',
            'password'              => 'one',
            'password_confirmation' => 'two',
        ])))['error'])->toBe('Passwords do not match.')
        ->and(fleetopsInternalCustomerJson($missingUser->resetCredentials(new Request([
            'customer'              => 'customer_public',
            'password'              => 'one',
            'password_confirmation' => 'one',
        ])))['error'])->toBe('Unable to reset customer credentials');
});
