<?php

use Fleetbase\FleetOps\Exceptions\UserAlreadyExistsException;
use Fleetbase\FleetOps\Http\Controllers\Api\v1\CustomerController;
use Fleetbase\FleetOps\Http\Requests\CreateCustomerOrderRequest;
use Fleetbase\FleetOps\Http\Requests\CreateCustomerRequest;
use Fleetbase\FleetOps\Http\Requests\UpdateContactRequest;
use Fleetbase\FleetOps\Http\Requests\VerifyCreateCustomerRequest;
use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\OrderConfig;
use Fleetbase\FleetOps\Models\Payload;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Models\ServiceQuote;
use Fleetbase\FleetOps\Models\Waypoint;
use Fleetbase\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;

if (!class_exists('Illuminate\Foundation\Auth\User')) {
    eval('namespace Illuminate\Foundation\Auth; class User extends \Illuminate\Database\Eloquent\Model {}');
}

if (!function_exists('Fleetbase\FleetOps\Http\Controllers\Api\v1\response')) {
    eval('namespace Fleetbase\FleetOps\Http\Controllers\Api\v1; function response() { return new class {
        public function apiError(string $message, int $status = 400): \Illuminate\Http\JsonResponse
        {
            return new \Illuminate\Http\JsonResponse(["error" => $message], $status);
        }

        public function json(array $payload = [], int $status = 200): \Illuminate\Http\JsonResponse
        {
            return new \Illuminate\Http\JsonResponse($payload, $status);
        }
    }; }');
}

class FleetOpsApiCustomerControllerProbe extends CustomerController
{
    public ?string $companyUuid                                       = 'company-uuid';
    public ?Contact $currentCustomer                                  = null;
    public ?User $activeUser                                          = null;
    public ?User $genericUser                                         = null;
    public ?User $loginUser                                           = null;
    public ?User $verificationUser                                    = null;
    public ?User $uuidUser                                            = null;
    public ?Contact $customerContact                                  = null;
    public ?Contact $firstOrCreateContact                             = null;
    public ?Place $place                                              = null;
    public ?OrderConfig $orderConfig                                  = null;
    public ?Order $order                                              = null;
    public ?ServiceQuote $serviceQuote                                = null;
    public ?FleetOpsApiCustomerVerificationCodeFake $verificationCode = null;
    public ?object $file                                              = null;
    public mixed $ordersResult                                        = null;
    public mixed $placesResult                                        = null;
    public bool $emailVerificationFails                               = false;
    public bool $smsVerificationFails                                 = false;
    public bool $verificationExists                                   = true;
    public bool $passwordMatches                                      = true;
    public bool $findOrderFails                                       = false;
    public bool $createContactThrowsDuplicate                         = false;
    public bool $createContactThrowsGeneric                           = false;
    public bool $contactDuplicateFallback                             = true;
    public array $createdUsers                                        = [];
    public array $emailVerifications                                  = [];
    public array $smsVerifications                                    = [];
    public array $createdContacts                                     = [];
    public array $createdPlaces                                       = [];
    public array $createdFiles                                        = [];
    public array $createdTokens                                       = [];
    public array $userUpdates                                         = [];
    public array $orderQueries                                        = [];
    public array $placeQueries                                        = [];
    public array $createdOrders                                       = [];
    public array $uuidLookups                                         = [];
    public array $devices                                             = [];

    protected function isEmail(?string $identity): bool
    {
        return filter_var($identity, FILTER_VALIDATE_EMAIL) !== false;
    }

    protected function isPublicId(?string $value): bool
    {
        return is_string($value) && str_starts_with($value, 'file_');
    }

    protected function isBase64String(?string $value): bool
    {
        return is_string($value) && str_starts_with($value, 'data:');
    }

    protected function sessionCompany(): ?string
    {
        return $this->companyUuid;
    }

    protected function currentCustomer(): ?Contact
    {
        return $this->currentCustomer;
    }

    protected function findActiveUserByIdentity(string $identity, string $column): ?User
    {
        $this->userUpdates[] = ['findActive', $column, $identity];

        return $this->activeUser;
    }

    protected function findUserByIdentity(string $identity, string $column): ?User
    {
        $this->userUpdates[] = ['find', $column, $identity];

        return $this->genericUser;
    }

    protected function findUserForLogin(string $identity): ?User
    {
        $this->userUpdates[] = ['login', $identity];

        return $this->loginUser;
    }

    protected function findUserForVerification(string $identity): ?User
    {
        $this->userUpdates[] = ['verify', $identity];

        return $this->verificationUser;
    }

    protected function findUserByUuid(string $uuid): ?User
    {
        $this->userUpdates[] = ['findUuid', $uuid];

        return $this->uuidUser;
    }

    protected function createUser(array $attributes): User
    {
        $user = new FleetOpsApiCustomerUserFake();
        $user->setRawAttributes(array_merge(['uuid' => 'created-user-uuid'], $attributes), true);
        $this->createdUsers[] = $attributes;

        return $user;
    }

    protected function passwordMatches(string $password, string $hash): bool
    {
        $this->userUpdates[] = ['password', $password, $hash];

        return $this->passwordMatches;
    }

    protected function generateEmailVerification(User $user, string $for, array $options): mixed
    {
        if ($this->emailVerificationFails) {
            throw new RuntimeException('email failed');
        }
        $this->emailVerifications[] = [$user->uuid, $for, $options];

        return null;
    }

    protected function generateSmsVerification(User $user, string $for, array $options): mixed
    {
        if ($this->smsVerificationFails) {
            throw new RuntimeException('sms failed');
        }
        $this->smsVerifications[] = [$user->uuid, $for, $options];

        return null;
    }

    protected function verificationCodeExists(array $attributes): bool
    {
        $this->userUpdates[] = ['verificationExists', $attributes];

        return $this->verificationExists;
    }

    protected function findVerificationCode(array $attributes): mixed
    {
        $this->userUpdates[] = ['findVerification', $attributes];

        return $this->verificationCode;
    }

    protected function findFileByPublicId(string $publicId): mixed
    {
        $this->userUpdates[] = ['file', $publicId];

        return $this->file;
    }

    protected function createFileFromBase64(string $contents, string $path): mixed
    {
        $file                 = (object) ['uuid' => 'base64-file-uuid'];
        $this->createdFiles[] = [$contents, $path];

        return $file;
    }

    protected function findCustomerContact(array $attributes): ?Contact
    {
        $this->userUpdates[] = ['findContact', $attributes];

        if ($this->createContactThrowsDuplicate && $this->contactDuplicateFallback) {
            return $this->customerContact ?? fleetopsApiCustomerContact('fallback-contact-uuid');
        }

        return $this->customerContact;
    }

    protected function createContact(array $attributes): Contact
    {
        if ($this->createContactThrowsDuplicate) {
            throw new UserAlreadyExistsException('customer already exists');
        }
        if ($this->createContactThrowsGeneric) {
            throw new RuntimeException('contact failed');
        }
        $contact = fleetopsApiCustomerContact('created-contact-uuid');
        $contact->setRawAttributes(array_merge($contact->getAttributes(), $attributes), true);
        $this->createdContacts[] = $attributes;

        return $contact;
    }

    protected function firstOrCreateCustomerContact(array $attributes, array $values): Contact
    {
        $this->createdContacts[] = [$attributes, $values];

        return $this->firstOrCreateContact ?? fleetopsApiCustomerContact('first-or-create-contact');
    }

    protected function createCustomerToken(User $user, Contact $contact): mixed
    {
        $this->createdTokens[] = [$user->uuid, $contact->uuid];

        return (object) ['plainTextToken' => 'plain-token'];
    }

    protected function findPlaceByPublicId(string $publicId, string $companyUuid): ?Place
    {
        $this->createdPlaces[] = ['find', $publicId, $companyUuid];

        return $this->place;
    }

    protected function createPlace(array $attributes): Place
    {
        $place = new Place();
        $place->setRawAttributes(array_merge(['uuid' => 'created-place-uuid'], $attributes), true);
        $this->createdPlaces[] = $attributes;

        return $place;
    }

    protected function updateUserByUuid(string $uuid, array $attributes): mixed
    {
        $this->userUpdates[] = ['updateUuid', $uuid, $attributes];

        return 1;
    }

    protected function findAccessToken(string $token): mixed
    {
        return new FleetOpsApiCustomerTokenFake($token);
    }

    protected function deleteUserTokens(User $user): void
    {
        $user->tokensDeleted = true;
    }

    protected function queryOrders(Request $request, callable $callback): mixed
    {
        $query = new FleetOpsApiCustomerQueryFake();
        $callback($query);
        $this->orderQueries[] = $query->calls;

        return $this->ordersResult ?? [['uuid' => 'order-uuid']];
    }

    protected function findOrderOrFail(string $id): Order
    {
        if ($this->findOrderFails) {
            throw new ModelNotFoundException();
        }
        $this->order ??= fleetopsApiCustomerOrder('order-uuid', $this->currentCustomer?->uuid ?? 'customer-uuid');
        $this->order->lookupId = $id;

        return $this->order;
    }

    protected function resolveOrderConfig(CreateCustomerOrderRequest $request, string $companyUuid): ?OrderConfig
    {
        return $this->orderConfig;
    }

    protected function getUuid(array|string $table, array $where, array $options = []): mixed
    {
        $this->uuidLookups[] = [$table, $where, $options];

        return 'payload-uuid';
    }

    protected function getModelClassName(?string $table): ?string
    {
        return 'Fleetbase\\FleetOps\\Models\\Contact';
    }

    protected function createOrderRecord(array $attributes): Order
    {
        $order = fleetopsApiCustomerOrder('created-order-uuid', $attributes['customer_uuid']);
        $order->setRawAttributes(array_merge($order->getAttributes(), $attributes), true);
        $this->createdOrders[] = $attributes;

        return $order;
    }

    protected function resolveServiceQuote(CreateCustomerOrderRequest $request): mixed
    {
        return $this->serviceQuote;
    }

    protected function newPayload(): Payload
    {
        return new FleetOpsApiCustomerPayloadFake();
    }

    protected function queryPlaces(Request $request, callable $callback): mixed
    {
        $query = new FleetOpsApiCustomerQueryFake();
        $callback($query);
        $this->placeQueries[] = $query->calls;

        return $this->placesResult ?? [['uuid' => 'place-uuid']];
    }

    protected function firstOrCreateDevice(array $attributes, array $values): mixed
    {
        $this->devices[] = [$attributes, $values];

        return (object) ['public_id' => 'device-public'];
    }

    protected function customerResource(Contact $contact): mixed
    {
        return ['resource' => 'customer', 'contact' => $contact, 'token' => $contact->token ?? null];
    }

    protected function orderResource(Order $order): mixed
    {
        return ['resource' => 'order', 'order' => $order];
    }

    protected function orderResourceCollection(mixed $results): mixed
    {
        return ['collection' => 'orders', 'items' => $results];
    }

    protected function placeResourceCollection(mixed $results): mixed
    {
        return ['collection' => 'places', 'items' => $results];
    }
}

class FleetOpsApiCustomerUserFake extends User
{
    public bool $savedForTest    = false;
    public bool $tokensDeleted   = false;
    public array $filledPayloads = [];
    public array $assignedTypes  = [];

    public function setUserType(string $type): User
    {
        $this->assignedTypes[] = $type;
        $this->type            = $type;

        return $this;
    }

    public function save(array $options = []): bool
    {
        $this->savedForTest = true;

        return true;
    }

    public function fill(array $attributes)
    {
        $this->filledPayloads[] = $attributes;
        $this->setRawAttributes(array_merge($this->getAttributes(), $attributes), true);

        return $this;
    }

    public function setPasswordAttribute($password): void
    {
        $this->attributes['password'] = $password;
    }
}

class FleetOpsApiCustomerContactFake extends Contact
{
    public bool $savedForTest     = false;
    public bool $updatedForTest   = false;
    public array $filledPayloads  = [];
    public array $updatedPayloads = [];

    public function fill(array $attributes)
    {
        $this->filledPayloads[] = $attributes;
        $this->setRawAttributes(array_merge($this->getAttributes(), $attributes), true);

        return $this;
    }

    public function save(array $options = []): bool
    {
        $this->savedForTest = true;

        return true;
    }

    public function update(array $attributes = [], array $options = []): bool
    {
        if (($attributes['name'] ?? null) === 'explode') {
            throw new RuntimeException('update failed');
        }
        $this->updatedForTest    = true;
        $this->updatedPayloads[] = $attributes;
        $this->setRawAttributes(array_merge($this->getAttributes(), $attributes), true);

        return true;
    }

    public function fresh($with = [])
    {
        return $this;
    }
}

class FleetOpsApiCustomerOrderFake extends Order
{
    public ?string $lookupId      = null;
    public array $freshLoads      = [];
    public array $purchasedQuotes = [];

    public function fresh($with = [])
    {
        $this->freshLoads[] = $with;

        return $this;
    }

    public function purchaseServiceQuote($serviceQuote, $meta = [])
    {
        $this->purchasedQuotes[] = $serviceQuote;
    }
}

class FleetOpsApiCustomerPayloadFake extends Payload
{
    public array $calls = [];

    public function setPickup($place, array $options = [])
    {
        $this->calls[] = ['pickup', $place];
        if (isset($options['callback'])) {
            $options['callback'](new Place(), $this);
        }

        return $this;
    }

    public function setDropoff($place, array $options = [])
    {
        $this->calls[] = ['dropoff', $place];

        return $this;
    }

    public function setReturn($place, array $options = [])
    {
        $this->calls[] = ['return', $place];

        return $this;
    }

    public function setWaypoints($waypoints = [])
    {
        $this->calls[] = ['waypoints', $waypoints];

        return $this;
    }

    public function setEntities($entities = [])
    {
        $this->calls[] = ['entities', $entities];

        return $this;
    }

    public function setCurrentWaypoint(Place|Waypoint $destination, bool $save = true): Payload
    {
        $this->calls[] = ['current', $destination instanceof Place, $save];

        return $this;
    }

    public function getPickupOrFirstWaypoint(): ?Place
    {
        return new Place();
    }

    public function save(array $options = []): bool
    {
        $this->uuid    = 'payload-built-uuid';
        $this->calls[] = ['save'];

        return true;
    }
}

class FleetOpsApiCustomerQueryFake
{
    public array $calls = [];

    public function where(...$args)
    {
        $this->calls[] = ['where', $args];

        return $this;
    }

    public function whereNull(string $column)
    {
        $this->calls[] = ['whereNull', $column];

        return $this;
    }

    public function withoutGlobalScopes()
    {
        $this->calls[] = ['withoutGlobalScopes'];

        return $this;
    }
}

class FleetOpsApiCustomerTokenFake
{
    public bool $deleted = false;

    public function __construct(public string $token)
    {
    }

    public function delete(): bool
    {
        $this->deleted = true;

        return true;
    }
}

class FleetOpsApiCustomerVerificationCodeFake
{
    public bool $deleted = false;

    public function delete(): bool
    {
        $this->deleted = true;

        return true;
    }
}

class FleetOpsApiCustomerOrderRequest extends CreateCustomerOrderRequest
{
    public function isArray(string $key): bool
    {
        return is_array($this->input($key));
    }

    public function isString(string $key): bool
    {
        return is_string($this->input($key));
    }
}

function fleetopsApiCustomerUser(string $uuid = 'user-uuid', ?string $password = 'hashed-secret'): FleetOpsApiCustomerUserFake
{
    $user = new FleetOpsApiCustomerUserFake();
    $user->setRawAttributes([
        'uuid'     => $uuid,
        'name'     => 'Jane Customer',
        'email'    => 'jane@example.test',
        'phone'    => '+15551234567',
        'password' => $password,
        'type'     => 'customer',
    ], true);

    return $user;
}

function fleetopsApiCustomerContact(string $uuid = 'customer-uuid'): FleetOpsApiCustomerContactFake
{
    $contact = new FleetOpsApiCustomerContactFake();
    $contact->setRawAttributes([
        'uuid'         => $uuid,
        'public_id'    => 'contact_public',
        'company_uuid' => 'company-uuid',
        'user_uuid'    => 'user-uuid',
        'type'         => 'customer',
        'name'         => 'Jane Customer',
        'email'        => 'jane@example.test',
        'phone'        => '+15551234567',
    ], true);

    return $contact;
}

function fleetopsApiCustomerOrder(string $uuid = 'order-uuid', string $customerUuid = 'customer-uuid'): FleetOpsApiCustomerOrderFake
{
    $order = new FleetOpsApiCustomerOrderFake();
    $order->setRawAttributes([
        'uuid'          => $uuid,
        'public_id'     => 'order_public',
        'customer_uuid' => $customerUuid,
    ], true);

    return $order;
}

function fleetopsApiCustomerController(): FleetOpsApiCustomerControllerProbe
{
    $controller                   = new FleetOpsApiCustomerControllerProbe();
    $controller->currentCustomer  = fleetopsApiCustomerContact();
    $controller->activeUser       = fleetopsApiCustomerUser();
    $controller->genericUser      = fleetopsApiCustomerUser();
    $controller->loginUser        = fleetopsApiCustomerUser();
    $controller->verificationUser = fleetopsApiCustomerUser();
    $controller->uuidUser         = fleetopsApiCustomerUser();
    $controller->customerContact  = fleetopsApiCustomerContact();
    $controller->orderConfig      = new OrderConfig();
    $controller->orderConfig->setRawAttributes(['uuid' => 'config-uuid', 'key' => 'transport'], true);
    $controller->order           = fleetopsApiCustomerOrder();
    $controller->serviceQuote    = new ServiceQuote();
    $controller->file            = (object) ['uuid' => 'public-file-uuid'];

    return $controller;
}

function fleetopsApiCustomerJson($response): array
{
    return $response instanceof Illuminate\Http\JsonResponse ? $response->getData(true) : $response;
}

test('api customer controller sends creation verification codes and updates stubs', function () {
    $controller             = fleetopsApiCustomerController();
    $controller->activeUser = null;

    $created = fleetopsApiCustomerJson($controller->requestCreationCode(new VerifyCreateCustomerRequest([
        'mode'     => 'email',
        'identity' => 'jane@example.test',
        'name'     => 'Jane',
        'phone'    => '15551234567',
    ])));

    $stub                 = fleetopsApiCustomerUser('stub-user', null);
    $stub->name           = 'stub@example.test';
    $stub->email          = 'stub@example.test';
    $stub->phone          = null;
    $existing             = fleetopsApiCustomerController();
    $existing->activeUser = $stub;

    $updated = fleetopsApiCustomerJson($existing->requestCreationCode(new VerifyCreateCustomerRequest([
        'mode'     => 'email',
        'identity' => 'stub@example.test',
        'name'     => 'Stub Name',
        'phone'    => '15559990000',
    ])));

    $sms = fleetopsApiCustomerController();
    $sms->requestCreationCode(new VerifyCreateCustomerRequest([
        'mode'     => 'sms',
        'identity' => '15550000000',
    ]));

    expect($created)->toBe(['status' => 'ok'])
        ->and($controller->createdUsers[0])->toMatchArray([
            'company_uuid' => 'company-uuid',
            'name'         => 'Jane',
            'email'        => 'jane@example.test',
            'phone'        => '+15551234567',
        ])
        ->and($controller->emailVerifications[0][1])->toBe('fleetops_create_customer')
        ->and($updated)->toBe(['status' => 'ok'])
        ->and($stub->name)->toBe('Stub Name')
        ->and($stub->phone)->toBe('+15559990000')
        ->and($stub->savedForTest)->toBeTrue()
        ->and($sms->smsVerifications[0][0])->toBe('user-uuid')
        ->and($sms->userUpdates[0])->toBe(['findActive', 'phone', '+15550000000']);
});

test('api customer controller creates contacts and handles signup edge cases', function () {
    $controller                  = fleetopsApiCustomerController();
    $controller->activeUser      = null;
    $controller->customerContact = null;

    $created = $controller->create(new CreateCustomerRequest([
        'code'     => '123456',
        'identity' => 'jane@example.test',
        'name'     => 'Jane',
        'phone'    => '15551234567',
        'password' => 'password-secret',
        'photo'    => 'data:image/png;base64,AAAA',
        'place'    => ['name' => 'Home', 'ignored' => 'nope'],
        'meta'     => ['tier' => 'gold'],
    ]));

    $missingCode                     = fleetopsApiCustomerController();
    $missingCode->verificationExists = false;

    $duplicate                               = fleetopsApiCustomerController();
    $duplicate->customerContact              = null;
    $duplicate->createContactThrowsDuplicate = true;
    $duplicate->contactDuplicateFallback     = false;

    $generic                             = fleetopsApiCustomerController();
    $generic->customerContact            = null;
    $generic->createContactThrowsGeneric = true;

    expect($created)->toMatchArray(['resource' => 'customer', 'token' => 'plain-token'])
        ->and($controller->createdContacts[0])->toMatchArray([
            'company_uuid' => 'company-uuid',
            'name'         => 'Jane',
            'email'        => 'jane@example.test',
            'phone'        => '+15551234567',
            'photo_uuid'   => 'base64-file-uuid',
        ])
        ->and($created['contact']->place_uuid)->toBe('created-place-uuid')
        ->and($controller->createdTokens[0])->toBe(['created-user-uuid', 'created-contact-uuid'])
        ->and(fleetopsApiCustomerJson($missingCode->create(new CreateCustomerRequest([
            'code'     => 'bad',
            'identity' => 'jane@example.test',
        ]))))->toBe(['error' => 'Invalid verification code provided.'])
        ->and(fleetopsApiCustomerJson($duplicate->create(new CreateCustomerRequest([
            'code'     => '123456',
            'identity' => 'jane@example.test',
        ]))))->toBe(['error' => 'customer already exists'])
        ->and(fleetopsApiCustomerJson($generic->create(new CreateCustomerRequest([
            'code'     => '123456',
            'identity' => 'jane@example.test',
        ]))))->toBe(['error' => 'contact failed']);
});

test('api customer controller authenticates and verifies customer codes', function () {
    $controller = fleetopsApiCustomerController();

    $login = $controller->login(Request::create('/v1/customers/login', 'POST', [
        'identity' => 'jane@example.test',
        'password' => 'password-secret',
    ]));

    $sms = $controller->loginWithPhone(Request::create('/v1/customers/login/phone', 'POST', [
        'phone' => '15551234567',
    ]));

    $verify = $controller->verifyCode(Request::create('/v1/customers/verify', 'POST', [
        'identity' => 'jane@example.test',
        'code'     => '123456',
    ]));

    $badPassword                  = fleetopsApiCustomerController();
    $badPassword->passwordMatches = false;
    $noUser                       = fleetopsApiCustomerController();
    $noUser->activeUser           = null;
    $noCode                       = fleetopsApiCustomerController();
    $noCode->verificationExists   = false;

    expect($login)->toMatchArray(['resource' => 'customer', 'token' => 'plain-token'])
        ->and(fleetopsApiCustomerJson($sms))->toBe(['status' => 'ok', 'method' => 'sms'])
        ->and($controller->smsVerifications[0][1])->toBe('fleetops_customer_login')
        ->and($verify)->toMatchArray(['resource' => 'customer', 'token' => 'plain-token'])
        ->and(fleetopsApiCustomerJson($badPassword->login(Request::create('/v1/customers/login', 'POST', [
            'identity' => 'jane@example.test',
            'password' => 'bad',
        ]))))->toBe(['error' => 'Authentication failed using credentials provided.'])
        ->and(fleetopsApiCustomerJson($noUser->loginWithPhone(Request::create('/v1/customers/login/phone', 'POST', [
            'phone' => '15551234567',
        ]))))->toBe(['error' => 'No customer with this phone number found.'])
        ->and(fleetopsApiCustomerJson($noCode->verifyCode(Request::create('/v1/customers/verify', 'POST', [
            'identity' => 'jane@example.test',
            'code'     => 'bad',
        ]))))->toBe(['error' => 'Invalid verification code.']);
});

test('api customer controller resets forgotten passwords', function () {
    $controller = fleetopsApiCustomerController();
    $forgot     = $controller->forgotPassword(Request::create('/v1/customers/forgot-password', 'POST', [
        'identity' => 'jane@example.test',
    ]));

    $controller->verificationCode = new FleetOpsApiCustomerVerificationCodeFake();
    $reset                        = $controller->resetPassword(Request::create('/v1/customers/reset-password', 'POST', [
        'identity' => 'jane@example.test',
        'code'     => '123456',
        'password' => 'password-secret',
    ]));

    $unknown                   = fleetopsApiCustomerController();
    $unknown->genericUser      = null;
    $badCode                   = fleetopsApiCustomerController();
    $badCode->verificationCode = null;

    expect(fleetopsApiCustomerJson($forgot))->toBe(['status' => 'ok'])
        ->and($controller->emailVerifications[0][1])->toBe('fleetops_customer_password_reset')
        ->and(fleetopsApiCustomerJson($reset))->toBe(['status' => 'ok'])
        ->and($controller->genericUser->savedForTest)->toBeTrue()
        ->and($controller->genericUser->tokensDeleted)->toBeTrue()
        ->and($controller->verificationCode->deleted)->toBeTrue()
        ->and(fleetopsApiCustomerJson($unknown->forgotPassword(Request::create('/v1/customers/forgot-password', 'POST', [
            'identity' => 'missing@example.test',
        ]))))->toBe(['status' => 'ok'])
        ->and(fleetopsApiCustomerJson($badCode->resetPassword(Request::create('/v1/customers/reset-password', 'POST', [
            'identity' => 'jane@example.test',
            'code'     => 'bad',
            'password' => 'password-secret',
        ]))))->toBe(['error' => 'Invalid reset code.']);
});

test('api customer controller handles profile logout listing and device flows', function () {
    $controller = fleetopsApiCustomerController();
    $customer   = $controller->currentCustomer;

    $profile = $controller->me();
    $updated = $controller->updateMe(new UpdateContactRequest([
        'name'  => 'Jane Updated',
        'phone' => '15550001111',
        'photo' => 'REMOVE',
    ]));
    $logout = $controller->logout(Request::create('/v1/customers/logout', 'POST', [], [], [], [
        'HTTP_CUSTOMER_TOKEN' => 'token-secret',
    ]));
    $logoutAll = $controller->logoutAll();
    $orders    = $controller->orders(Request::create('/v1/customers/orders', 'GET'));
    $places    = $controller->places(Request::create('/v1/customers/places', 'GET'));
    $device    = $controller->registerDevice(Request::create('/v1/customers/devices', 'POST', [
        'token' => 'push-token',
        'os'    => 'ios',
    ]));

    expect($profile['contact'])->toBe($customer)
        ->and($updated['contact']->updatedPayloads[0])->toMatchArray([
            'name'       => 'Jane Updated',
            'phone'      => '+15550001111',
            'photo_uuid' => null,
        ])
        ->and($controller->userUpdates)->toContain(['updateUuid', 'user-uuid', [
            'name'  => 'Jane Updated',
            'phone' => '+15550001111',
        ]])
        ->and(fleetopsApiCustomerJson($logout))->toBe(['status' => 'ok'])
        ->and(fleetopsApiCustomerJson($logoutAll))->toBe(['status' => 'ok'])
        ->and($controller->uuidUser->tokensDeleted)->toBeTrue()
        ->and($orders)->toMatchArray(['collection' => 'orders'])
        ->and($controller->orderQueries[0])->toContain(['where', ['customer_uuid', 'customer-uuid']])
        ->and($places)->toMatchArray(['collection' => 'places'])
        ->and($controller->placeQueries[0])->toContain(['where', ['owner_uuid', 'customer-uuid']])
        ->and(fleetopsApiCustomerJson($device))->toBe(['status' => 'ok', 'device' => 'device-public'])
        ->and($controller->devices[0][1])->toMatchArray([
            'user_uuid' => 'user-uuid',
            'platform'  => 'ios',
            'token'     => 'push-token',
            'status'    => 'active',
        ]);
});

test('api customer controller finds and creates customer orders', function () {
    $controller = fleetopsApiCustomerController();
    $found      = $controller->findOrder('order_public');

    $wrongOwner              = fleetopsApiCustomerController();
    $wrongOwner->order       = fleetopsApiCustomerOrder('order-uuid', 'other-customer');
    $missing                 = fleetopsApiCustomerController();
    $missing->findOrderFails = true;

    $created = $controller->createOrder(new FleetOpsApiCustomerOrderRequest([
        'payload'      => ['pickup' => ['name' => 'A'], 'dropoff' => ['name' => 'B'], 'entities' => [['name' => 'Box']]],
        'notes'        => 'leave at door',
        'internal_id'  => 'INT-1',
        'scheduled_at' => '2026-07-26 12:00:00',
        'meta'         => ['source' => 'customer'],
    ]));

    $stringPayload = fleetopsApiCustomerController();
    $stringPayload->createOrder(new FleetOpsApiCustomerOrderRequest(['payload' => 'payload_public']));

    // Top-level pickup/dropoff/return inputs build a payload without a
    // wrapping payload key
    $inline        = fleetopsApiCustomerController();
    $inlineCreated = $inline->createOrder(new FleetOpsApiCustomerOrderRequest([
        'pickup'  => ['name' => 'Inline Pickup'],
        'dropoff' => ['name' => 'Inline Dropoff'],
        'return'  => ['name' => 'Inline Return'],
    ]));

    $noConfig              = fleetopsApiCustomerController();
    $noConfig->orderConfig = null;

    expect($found)->toMatchArray(['resource' => 'order'])
        ->and(fleetopsApiCustomerJson($wrongOwner->findOrder('order_public')))->toBe(['error' => 'Order not found.'])
        ->and(fleetopsApiCustomerJson($missing->findOrder('order_public')))->toBe(['error' => 'Order not found.'])
        ->and($created)->toMatchArray(['resource' => 'order'])
        ->and($controller->createdOrders[0])->toMatchArray([
            'company_uuid'      => 'company-uuid',
            'customer_uuid'     => 'customer-uuid',
            'customer_type'     => 'Fleetbase\\FleetOps\\Models\\Contact',
            'payload_uuid'      => 'payload-built-uuid',
            'order_config_uuid' => 'config-uuid',
            'type'              => 'transport',
            'status'            => 'created',
        ])
        ->and($created['order']->purchasedQuotes[0])->toBeInstanceOf(ServiceQuote::class)
        ->and($stringPayload->uuidLookups[0][0])->toBe('payloads')
        ->and($inlineCreated)->toMatchArray(['resource' => 'order'])
        ->and($inline->createdOrders[0])->toMatchArray(['payload_uuid' => 'payload-built-uuid'])
        ->and(fleetopsApiCustomerJson($noConfig->createOrder(new FleetOpsApiCustomerOrderRequest())))->toBe([
            'error' => 'No order config available for this company.',
        ]);
});

test('api customer controller handles verification delivery fallbacks and profile errors', function () {
    $fallback                       = fleetopsApiCustomerController();
    $fallback->smsVerificationFails = true;
    $fallback->activeUser->email    = 'jane@example.test';

    $emailFallback = $fallback->loginWithPhone(Request::create('/v1/customers/login/phone', 'POST', [
        'phone' => '15551234567',
    ]));

    $failed                         = fleetopsApiCustomerController();
    $failed->smsVerificationFails   = true;
    $failed->emailVerificationFails = true;

    $updateFailure                  = fleetopsApiCustomerController();
    $updateFailure->currentCustomer = fleetopsApiCustomerContact();

    expect(fleetopsApiCustomerJson($emailFallback))->toBe(['status' => 'ok', 'method' => 'email'])
        ->and($fallback->emailVerifications[0][1])->toBe('fleetops_customer_login')
        ->and(fleetopsApiCustomerJson($failed->loginWithPhone(Request::create('/v1/customers/login/phone', 'POST', [
            'phone' => '15551234567',
        ]))))->toBe(['error' => 'Unable to send verification code.'])
        ->and(fleetopsApiCustomerJson($updateFailure->updateMe(new UpdateContactRequest([
            'name' => 'explode',
        ]))))->toBe(['error' => 'update failed']);
});

/**
 * Run a callback with an app container that supports environment().
 *
 * The harness binds a bare Illuminate\Container\Container, which has no
 * environment() — so CustomerController::verificationBypassMatches fatals with
 * "Call to undefined method" without this. Mirrors the swap in
 * NotificationAndMailContractsTest, but carries every existing binding across so
 * the controller can still resolve config/request/db.
 */
function fleetopsApiCustomerWithEnvironment(string $environment, callable $callback): mixed
{
    $previousApp = Illuminate\Container\Container::getInstance();
    $app         = new class extends Illuminate\Container\Container {
        public string $fleetopsEnvironment = 'testing';

        public function environment(...$environments)
        {
            if (empty($environments)) {
                return $this->fleetopsEnvironment;
            }

            $environments = is_array($environments[0]) ? $environments[0] : $environments;

            return in_array($this->fleetopsEnvironment, $environments, true);
        }

        public function hasDebugModeEnabled()
        {
            return false;
        }
    };
    $app->fleetopsEnvironment = $environment;

    $reflection = new ReflectionClass(Illuminate\Container\Container::class);
    foreach (['bindings', 'instances', 'aliases', 'abstractAliases', 'resolved', 'scopedInstances'] as $property) {
        if (!$reflection->hasProperty($property)) {
            continue;
        }
        $handle = $reflection->getProperty($property);
        $handle->setAccessible(true);
        $handle->setValue($app, $handle->getValue($previousApp));
    }

    Illuminate\Container\Container::setInstance($app);

    try {
        return $callback();
    } finally {
        Illuminate\Container\Container::setInstance($previousApp);
    }
}

test('api customer controller ignores the verification bypass unless it is configured', function () {
    // Unset and empty-string are both inert. Without this the bypass would be a
    // standing hole in every default install, which is the entire risk of shipping one.
    foreach ([null, ''] as $bypassCode) {
        config(['fleetops.customers.verification_bypass_code' => $bypassCode]);

        fleetopsApiCustomerWithEnvironment('local', function () {
            $create                     = fleetopsApiCustomerController();
            $create->verificationExists = false;
            $verify                     = fleetopsApiCustomerController();
            $verify->verificationExists = false;
            $reset                      = fleetopsApiCustomerController();
            $reset->verificationCode    = null;

            expect(fleetopsApiCustomerJson($create->create(new CreateCustomerRequest([
                'code'     => '000000',
                'identity' => 'jane@example.test',
            ]))))->toBe(['error' => 'Invalid verification code provided.'])
                ->and(fleetopsApiCustomerJson($verify->verifyCode(Request::create('/v1/customers/verify-code', 'POST', [
                    'identity' => 'jane@example.test',
                    'code'     => '000000',
                ]))))->toBe(['error' => 'Invalid verification code.'])
                ->and(fleetopsApiCustomerJson($reset->resetPassword(Request::create('/v1/customers/reset-password', 'POST', [
                    'identity' => 'jane@example.test',
                    'code'     => '000000',
                    'password' => 'password-secret',
                ]))))->toBe(['error' => 'Invalid reset code.']);
        });
    }

    config(['fleetops.customers.verification_bypass_code' => null]);
});

test('api customer controller accepts a configured verification bypass for a listed review account', function () {
    config([
        'fleetops.customers.verification_bypass_code' => '000000',
        'fleetops.customers.review_accounts'          => ['jane@example.test'],
    ]);

    // Deliberately production. App store reviewers test a release build against
    // production, so refusing the bypass there made review impossible — that is the
    // behaviour this replaces. Safety now comes from the allowlist, not the environment.
    fleetopsApiCustomerWithEnvironment('production', function () {
        $create                     = fleetopsApiCustomerController();
        $create->verificationExists = false;
        $verify                     = fleetopsApiCustomerController();
        $verify->verificationExists = false;
        // No VerificationCode row on the bypass path — resetPassword must not fatal
        // calling ->delete() on null, and must still revoke existing sessions.
        $reset                   = fleetopsApiCustomerController();
        $reset->verificationCode = null;
        // A non-matching code is still rejected while the bypass is live.
        $wrong                     = fleetopsApiCustomerController();
        $wrong->verificationExists = false;

        expect($create->create(new CreateCustomerRequest([
            'code'     => '000000',
            'identity' => 'jane@example.test',
            'name'     => 'Jane',
            'password' => 'password-secret',
        ])))->toMatchArray(['resource' => 'customer', 'token' => 'plain-token'])
            ->and($verify->verifyCode(Request::create('/v1/customers/verify-code', 'POST', [
                'identity' => 'jane@example.test',
                'code'     => '000000',
            ])))->toMatchArray(['resource' => 'customer', 'token' => 'plain-token'])
            ->and(fleetopsApiCustomerJson($reset->resetPassword(Request::create('/v1/customers/reset-password', 'POST', [
                'identity' => 'jane@example.test',
                'code'     => '000000',
                'password' => 'password-secret',
            ]))))->toBe(['status' => 'ok'])
            ->and($reset->genericUser->tokensDeleted)->toBeTrue()
            ->and(fleetopsApiCustomerJson($wrong->create(new CreateCustomerRequest([
                'code'     => '999999',
                'identity' => 'jane@example.test',
            ]))))->toBe(['error' => 'Invalid verification code provided.']);
    });

    config(['fleetops.customers.verification_bypass_code' => null, 'fleetops.customers.review_accounts' => []]);
});

test('api customer controller refuses the verification bypass for an identity that is not listed', function () {
    // The point of the allowlist: holding the code is not sufficient. Previously
    // anyone who learned it could authenticate as any customer.
    foreach ([['someone-else@example.test'], []] as $reviewAccounts) {
        config([
            'fleetops.customers.verification_bypass_code' => '000000',
            'fleetops.customers.review_accounts'          => $reviewAccounts,
        ]);

        fleetopsApiCustomerWithEnvironment('local', function () {
            $create                     = fleetopsApiCustomerController();
            $create->verificationExists = false;
            $verify                     = fleetopsApiCustomerController();
            $verify->verificationExists = false;
            $reset                      = fleetopsApiCustomerController();
            $reset->verificationCode    = null;

            expect(fleetopsApiCustomerJson($create->create(new CreateCustomerRequest([
                'code'     => '000000',
                'identity' => 'jane@example.test',
            ]))))->toBe(['error' => 'Invalid verification code provided.'])
                ->and(fleetopsApiCustomerJson($verify->verifyCode(Request::create('/v1/customers/verify-code', 'POST', [
                    'identity' => 'jane@example.test',
                    'code'     => '000000',
                ]))))->toBe(['error' => 'Invalid verification code.'])
                ->and(fleetopsApiCustomerJson($reset->resetPassword(Request::create('/v1/customers/reset-password', 'POST', [
                    'identity' => 'jane@example.test',
                    'code'     => '000000',
                    'password' => 'password-secret',
                ]))))->toBe(['error' => 'Invalid reset code.']);
        });
    }

    config(['fleetops.customers.verification_bypass_code' => null, 'fleetops.customers.review_accounts' => []]);
});
