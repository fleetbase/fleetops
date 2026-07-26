<?php

if (!function_exists('Fleetbase\Traits\config')) {
    eval('namespace Fleetbase\Traits; function config($key = null, $default = null) { return $key === "api.cache.enabled" ? false : $default; }');
}

if (!function_exists('Fleetbase\Models\config')) {
    eval('namespace Fleetbase\Models; function config($key = null, $default = null) { return $key === "fleetbase.connection.db" ? "mysql" : $default; }');
}

use Fleetbase\FleetOps\Models\ServiceQuote;
use Illuminate\Http\Request;

function fleetopsServiceQuoteUnitRequestWithQuote(mixed $serviceQuote): Request
{
    return new class($serviceQuote) extends Request {
        public function __construct(private mixed $serviceQuote)
        {
            parent::__construct();
        }

        public function or(array $keys, mixed $default = null): mixed
        {
            expect($keys)->toBe(['order.service_quote_uuid', 'service_quote', 'service_quote_id', 'order.service_quote']);

            return $this->serviceQuote ?? $default;
        }
    };
}

class FleetOpsServiceQuoteUnitQueryFake
{
    public function __construct(private ?ServiceQuote $quote)
    {
    }

    public function first(): ?ServiceQuote
    {
        return $this->quote;
    }
}

class FleetOpsServiceQuoteUnitResolvableFake extends ServiceQuote
{
    public static array $records = [];
    public static array $lookups = [];

    public static function where($column, $operator = null, $value = null, $boolean = 'and')
    {
        self::$lookups[] = [$column, $operator];

        return new FleetOpsServiceQuoteUnitQueryFake(self::$records[$column][$operator] ?? null);
    }
}

class FleetOpsServiceQuoteUnitCheckoutFake extends ServiceQuote
{
    public array $loadedMissing = [];

    public function loadMissing($relations)
    {
        $this->loadedMissing[] = $relations;

        return $this;
    }
}

test('service quote resolves request references from uuid and public id', function () {
    $uuidQuote = new ServiceQuote();
    $uuidQuote->setRawAttributes([
        'uuid'     => '11111111-1111-4111-8111-111111111111',
        'amount'   => 1500,
        'currency' => 'SGD',
    ], true);

    $idQuote = new ServiceQuote();
    $idQuote->setRawAttributes([
        'uuid'     => '22222222-2222-4222-8222-222222222222',
        'amount'   => 2750,
        'currency' => 'USD',
    ], true);

    FleetOpsServiceQuoteUnitResolvableFake::$lookups = [];
    FleetOpsServiceQuoteUnitResolvableFake::$records = [
        'uuid' => [
            '11111111-1111-4111-8111-111111111111' => $uuidQuote,
        ],
        'public_id' => [
            'quote_HIJKLMN' => $idQuote,
        ],
    ];

    $resolvedUuidQuote = FleetOpsServiceQuoteUnitResolvableFake::resolveFromRequest(fleetopsServiceQuoteUnitRequestWithQuote('11111111-1111-4111-8111-111111111111'));
    $resolvedIdQuote   = FleetOpsServiceQuoteUnitResolvableFake::resolveFromRequest(fleetopsServiceQuoteUnitRequestWithQuote('quote_HIJKLMN'));

    expect($resolvedUuidQuote)->toBe($uuidQuote)
        ->and($resolvedIdQuote)->toBe($idQuote)
        ->and(FleetOpsServiceQuoteUnitResolvableFake::$lookups)->toBe([
            ['uuid', '11111111-1111-4111-8111-111111111111'],
            ['public_id', 'quote_HIJKLMN'],
        ]);
});

test('service quote rejects unresolved request values and checkout without company', function () {
    FleetOpsServiceQuoteUnitResolvableFake::$lookups = [];

    $quote = new FleetOpsServiceQuoteUnitCheckoutFake();
    $quote->setRawAttributes([
        'uuid'      => 'service-quote-uuid',
        'public_id' => 'quote_ABCDEFG',
        'amount'    => 1500,
        'currency'  => 'SGD',
    ], true);
    $quote->setRelation('company', null);

    expect(fn () => FleetOpsServiceQuoteUnitResolvableFake::resolveFromRequest(fleetopsServiceQuoteUnitRequestWithQuote('not-a-public-id')))
        ->toThrow(TypeError::class, 'Return value must be of type')
        ->and(FleetOpsServiceQuoteUnitResolvableFake::$lookups)->toBe([])
        ->and(fn () => $quote->createStripeCheckoutSession('/checkout/return'))
        ->toThrow(Exception::class, 'company you attempted to purchase')
        ->and($quote->loadedMissing)->toBe([['company']]);
});
