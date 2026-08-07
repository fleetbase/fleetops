<?php

use Fleetbase\FleetOps\Support\CustomerAuth;
use Illuminate\Http\Request;

if (!class_exists('Fleetbase\FleetOps\Models\Contact', false)) {
    eval('namespace Fleetbase\FleetOps\Models; class Contact { public static mixed $query = null; public static array $whereCalls = []; public function __construct(public ?string $uuid = null) {} public static function where(...$arguments): mixed { self::$whereCalls[] = $arguments; return self::$query; } }');
}

if (!class_exists('Laravel\Sanctum\PersonalAccessToken', false)) {
    eval('namespace Laravel\Sanctum; class PersonalAccessToken { public static mixed $token = null; public static array $lookups = []; public static function findToken($token): mixed { self::$lookups[] = $token; return self::$token; } }');
}

class FleetOpsCustomerAuthFallbackQueryFake
{
    public array $calls = [];

    public function __construct(private ArrayObject $firstResults)
    {
    }

    public function where(...$arguments): self
    {
        $this->calls[] = ['where', $arguments];

        return $this;
    }

    public function first(): mixed
    {
        $this->calls[] = ['first'];

        foreach ($this->firstResults as $result) {
            return $result;
        }

        return null;
    }

    public function __clone()
    {
        $this->calls[] = ['clone'];
        foreach ($this->firstResults as $key => $result) {
            $this->firstResults->offsetUnset($key);

            break;
        }
    }
}

afterEach(function () {
    app()->forgetInstance(CustomerAuth::APP_BINDING);
    Laravel\Sanctum\PersonalAccessToken::$lookups  = [];
    Laravel\Sanctum\PersonalAccessToken::$token    = null;
    Fleetbase\FleetOps\Models\Contact::$whereCalls = [];
    Fleetbase\FleetOps\Models\Contact::$query      = null;
    session([
        'company'     => null,
        'customer_id' => null,
        'contact_id'  => null,
    ]);
});

function fleetopsCustomerAuthRequest(string $token): Request
{
    return Request::create('/customers/me', 'GET', [], [], [], [
        'HTTP_CUSTOMER_TOKEN' => $token,
    ]);
}

test('customer auth returns null when the customer token cannot be resolved', function () {
    Laravel\Sanctum\PersonalAccessToken::$token = null;

    expect(CustomerAuth::resolveFromHeader(fleetopsCustomerAuthRequest('missing-token')))->toBeNull()
        ->and(Laravel\Sanctum\PersonalAccessToken::$lookups)->toBe(['missing-token'])
        ->and(Fleetbase\FleetOps\Models\Contact::$whereCalls)->toBe([]);
});

test('customer auth prefers the session company customer when token name does not resolve a contact', function () {
    session(['company' => 'company-preferred']);

    $companyContact = new Fleetbase\FleetOps\Models\Contact('22222222-2222-4222-8222-222222222222');
    $query          = new FleetOpsCustomerAuthFallbackQueryFake(new ArrayObject([
        null,
        $companyContact,
    ]));

    Laravel\Sanctum\PersonalAccessToken::$token = (object) [
        'name'      => '11111111-1111-4111-8111-111111111111',
        'tokenable' => (object) ['uuid' => 'user-uuid'],
    ];
    Fleetbase\FleetOps\Models\Contact::$query = $query;

    expect(CustomerAuth::resolveFromHeader(fleetopsCustomerAuthRequest('token-with-user')))->toBe($companyContact)
        ->and(Fleetbase\FleetOps\Models\Contact::$whereCalls)->toContain(
            ['uuid', '11111111-1111-4111-8111-111111111111'],
            ['user_uuid', 'user-uuid']
        )
        ->and($query->calls)->toContain(['where', ['type', 'customer']]);
});

test('customer auth falls back to the first customer for the tokenable id', function () {
    $fallbackContact = new Fleetbase\FleetOps\Models\Contact('33333333-3333-4333-8333-333333333333');
    $query           = new FleetOpsCustomerAuthFallbackQueryFake(new ArrayObject([$fallbackContact]));

    Laravel\Sanctum\PersonalAccessToken::$token = (object) [
        'name'         => 'api-token',
        'tokenable_id' => 'numeric-user-id',
        'tokenable'    => null,
    ];
    Fleetbase\FleetOps\Models\Contact::$query = $query;

    expect(CustomerAuth::resolveFromHeader(fleetopsCustomerAuthRequest('token-by-user-id')))->toBe($fallbackContact)
        ->and(Fleetbase\FleetOps\Models\Contact::$whereCalls)->toBe([
            ['user_uuid', 'numeric-user-id'],
        ])
        ->and($query->calls)->toContain(
            ['where', ['type', 'customer']],
            ['first']
        );
});

test('customer auth returns null when a token has no tokenable identity', function () {
    Laravel\Sanctum\PersonalAccessToken::$token = (object) [
        'name'         => 'api-token',
        'tokenable_id' => null,
        'tokenable'    => null,
    ];

    expect(CustomerAuth::resolveFromHeader(fleetopsCustomerAuthRequest('token-without-user')))->toBeNull()
        ->and(Fleetbase\FleetOps\Models\Contact::$whereCalls)->toBe([]);
});
