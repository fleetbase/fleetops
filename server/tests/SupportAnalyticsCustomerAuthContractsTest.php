<?php

use Fleetbase\FleetOps\Support\Analytics\TopDrivers;
use Fleetbase\FleetOps\Support\CustomerAuth;
use Fleetbase\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

if (!function_exists('Fleetbase\Traits\config')) {
    eval('namespace Fleetbase\Traits; function config($key = null, $default = null) { return $key === "api.cache.enabled" ? false : $default; }');
}

if (!class_exists('Fleetbase\FleetOps\Models\Order', false)) {
    eval('namespace Fleetbase\FleetOps\Models; class Order { public static mixed $query = null; public static array $whereCalls = []; public static function where(...$arguments): mixed { self::$whereCalls[] = $arguments; return self::$query; } }');
}

if (!class_exists('Fleetbase\FleetOps\Models\Contact', false)) {
    eval('namespace Fleetbase\FleetOps\Models; class Contact { public static mixed $query = null; public static array $whereCalls = []; public function __construct(public ?string $uuid = null) {} public static function where(...$arguments): mixed { self::$whereCalls[] = $arguments; return self::$query; } }');
}

if (!class_exists('Laravel\Sanctum\PersonalAccessToken', false)) {
    eval('namespace Laravel\Sanctum; class PersonalAccessToken { public static mixed $token = null; public static array $lookups = []; public static function findToken($token): mixed { self::$lookups[] = $token; return self::$token; } }');
}

class FleetOpsTopDriversQueryFake
{
    public array $calls = [];

    public function __construct(private iterable $rows)
    {
    }

    public function where(...$arguments): self
    {
        $this->calls[] = ['where', $arguments];

        return $this;
    }

    public function whereNotNull(...$arguments): self
    {
        $this->calls[] = ['whereNotNull', $arguments];

        return $this;
    }

    public function whereBetween(...$arguments): self
    {
        $this->calls[] = ['whereBetween', $arguments];

        return $this;
    }

    public function join(...$arguments): self
    {
        $this->calls[] = ['join', $arguments];

        return $this;
    }

    public function groupBy(...$arguments): self
    {
        $this->calls[] = ['groupBy', $arguments];

        return $this;
    }

    public function selectRaw(string $sql): self
    {
        $this->calls[] = ['selectRaw', $sql];

        return $this;
    }

    public function orderByRaw(string $sql): self
    {
        $this->calls[] = ['orderByRaw', $sql];

        return $this;
    }

    public function limit(int $limit): self
    {
        $this->calls[] = ['limit', $limit];

        return $this;
    }

    public function get(): Illuminate\Support\Collection
    {
        $this->calls[] = ['get'];

        return collect($this->rows);
    }
}

class FleetOpsCustomerAuthContactQueryFake
{
    public array $calls = [];

    public function __construct(private mixed $firstResult)
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

        return $this->firstResult;
    }

    public function __clone()
    {
        $this->calls[] = ['clone'];
    }
}

test('top drivers analytics builds leaderboard queries and maps rows', function () {
    Carbon::setTestNow('2026-07-26 12:00:00');

    $company = new Company();
    $company->setRawAttributes(['uuid' => 'company-uuid', 'currency' => 'SGD'], true);

    $query = new FleetOpsTopDriversQueryFake([
        (object) [
            'driver_uuid'      => 'driver-1',
            'name'             => 'A Driver',
            'avatar_uuid'      => 'avatar-1',
            'orders_completed' => '7',
            'distance_m'       => '1234.5',
            'on_time_count'    => '3',
            'scheduled_count'  => '4',
        ],
        (object) [
            'driver_uuid'      => 'driver-2',
            'name'             => 'B Driver',
            'avatar_uuid'      => null,
            'orders_completed' => '2',
            'distance_m'       => null,
            'on_time_count'    => '0',
            'scheduled_count'  => '0',
        ],
    ]);

    Fleetbase\FleetOps\Models\Order::$query      = $query;
    Fleetbase\FleetOps\Models\Order::$whereCalls = [];

    $payload = TopDrivers::forCompany($company)
        ->between(Carbon::parse('2026-07-01'), Carbon::parse('2026-07-26'))
        ->limit(2)
        ->sortBy('on_time')
        ->get();

    $orderByRaw = collect($query->calls)->firstWhere(0, 'orderByRaw')[1];

    expect($payload)->toBe([
        'rows'    => [
            [
                'driver_uuid'      => 'driver-1',
                'name'             => 'A Driver',
                'avatar_uuid'      => 'avatar-1',
                'orders_completed' => 7,
                'distance_m'       => 1234.5,
                'on_time_pct'      => 75.0,
            ],
            [
                'driver_uuid'      => 'driver-2',
                'name'             => 'B Driver',
                'avatar_uuid'      => null,
                'orders_completed' => 2,
                'distance_m'       => 0.0,
                'on_time_pct'      => null,
            ],
        ],
        'sort_by' => 'on_time',
    ])
        ->and($orderByRaw)->toContain('TIMESTAMPDIFF')
        ->and(collect($query->calls)->firstWhere(0, 'limit')[1])->toBe(2)
        ->and(Fleetbase\FleetOps\Models\Order::$whereCalls)->toContain(['orders.company_uuid', 'company-uuid'])
        ->and(collect($query->calls)->where(0, 'join')->pluck(1)->all())->toContain(
            ['drivers', 'drivers.uuid', '=', 'orders.driver_assigned_uuid'],
            ['users', 'users.uuid', '=', 'drivers.user_uuid']
        );

    Carbon::setTestNow();
});

test('customer auth resolves token contacts and binds the current customer', function () {
    $contact = new Fleetbase\FleetOps\Models\Contact('11111111-1111-4111-8111-111111111111');

    $uuidQuery = new FleetOpsCustomerAuthContactQueryFake($contact);

    Laravel\Sanctum\PersonalAccessToken::$lookups = [];
    Laravel\Sanctum\PersonalAccessToken::$token   = (object) [
        'name'         => '11111111-1111-4111-8111-111111111111',
        'tokenable_id' => 'user-id',
    ];
    Fleetbase\FleetOps\Models\Contact::$whereCalls = [];
    Fleetbase\FleetOps\Models\Contact::$query      = $uuidQuery;

    $resolved = CustomerAuth::resolveFromHeader(Request::create('/customers/me', 'GET', [], [], [], [
        'HTTP_CUSTOMER_TOKEN' => 'token-by-contact',
    ]));

    CustomerAuth::setCurrent($resolved);

    expect($resolved)->toBe($contact)
        ->and(Laravel\Sanctum\PersonalAccessToken::$lookups)->toBe(['token-by-contact'])
        ->and(Fleetbase\FleetOps\Models\Contact::$whereCalls)->toContain(['uuid', '11111111-1111-4111-8111-111111111111'])
        ->and($uuidQuery->calls)->toContain(['where', ['type', 'customer']], ['first'])
        ->and(CustomerAuth::current())->toBe($contact)
        ->and(session('customer_id'))->toBe('11111111-1111-4111-8111-111111111111')
        ->and(session('contact_id'))->toBe('11111111-1111-4111-8111-111111111111');
});
