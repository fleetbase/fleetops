<?php

if (!function_exists('Fleetbase\Traits\config')) {
    eval('namespace Fleetbase\Traits; function config($key = null, $default = null) { return $key === "api.cache.enabled" ? false : $default; }');
}

if (!function_exists('Fleetbase\Models\config')) {
    eval('namespace Fleetbase\Models; function config($key = null, $default = null) { return $key === "fleetbase.connection.db" ? "mysql" : $default; }');
}

use Fleetbase\FleetOps\Models\IntegratedVendor;
use Fleetbase\FleetOps\Models\Payload;
use Fleetbase\FleetOps\Models\ServiceQuote;
use Fleetbase\FleetOps\Models\ServiceQuoteItem;
use Fleetbase\FleetOps\Models\ServiceRate;
use Fleetbase\Models\Company;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\SQLiteConnection;
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

class FleetOpsServiceQuoteUnitNamedFake extends ServiceQuote
{
    public ?string $pluralName   = null;
    public ?string $singularName = null;
    public ?string $payloadKey   = null;
}

function fleetopsServiceQuoteUseRelationConnection(): void
{
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
    $resolver   = new ConnectionResolver([
        'default' => $connection,
        'mysql'   => $connection,
    ]);

    $resolver->setDefaultConnection('mysql');
    EloquentModel::setConnectionResolver($resolver);
}

test('service quote relationship and accessor contracts resolve expected models', function () {
    fleetopsServiceQuoteUseRelationConnection();

    $serviceRate = new ServiceRate();
    $serviceRate->setRawAttributes(['service_name' => 'Same Day'], true);

    $quote = new ServiceQuote();
    $quote->setRelation('serviceRate', $serviceRate);

    expect($quote->items())->toBeInstanceOf(HasMany::class)
        ->and($quote->items()->getRelated())->toBeInstanceOf(ServiceQuoteItem::class)
        ->and($quote->company())->toBeInstanceOf(BelongsTo::class)
        ->and($quote->company()->getRelated())->toBeInstanceOf(Company::class)
        ->and($quote->serviceRate())->toBeInstanceOf(BelongsTo::class)
        ->and($quote->serviceRate()->getRelated())->toBeInstanceOf(ServiceRate::class)
        ->and($quote->payload())->toBeInstanceOf(BelongsTo::class)
        ->and($quote->payload()->getRelated())->toBeInstanceOf(Payload::class)
        ->and($quote->integratedVendor())->toBeInstanceOf(BelongsTo::class)
        ->and($quote->integratedVendor()->getRelated())->toBeInstanceOf(IntegratedVendor::class)
        ->and($quote->service_rate_name)->toBe('Same Day');
});

test('service quote naming and integrated vendor helpers use explicit values fallbacks and metadata', function () {
    $named               = new FleetOpsServiceQuoteUnitNamedFake();
    $named->pluralName   = 'custom quotes';
    $named->singularName = 'custom quote';

    expect($named->getPluralName())->toBe('custom quotes')
        ->and($named->getSingularName())->toBe('custom quote');

    $payloadNamed             = new FleetOpsServiceQuoteUnitNamedFake();
    $payloadNamed->payloadKey = 'shipment';

    expect($payloadNamed->getPluralName())->toBe('shipments')
        ->and($payloadNamed->getSingularName())->toBe('shipment');

    $default = new ServiceQuote();

    expect($default->getPluralName())->toBe('service_quotes')
        ->and($default->getSingularName())->toBe('service_quote')
        ->and($default->fromIntegratedVendor())->toBeFalse();

    $vendorQuote = new ServiceQuote();
    $vendorQuote->setRawAttributes(['integrated_vendor_uuid' => 'vendor-uuid'], true);

    expect($vendorQuote->fromIntegratedVendor())->toBeTrue();
});

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

    expect(FleetOpsServiceQuoteUnitResolvableFake::resolveFromRequest(fleetopsServiceQuoteUnitRequestWithQuote(null)))->toBeNull()
        ->and(fn () => FleetOpsServiceQuoteUnitResolvableFake::resolveFromRequest(fleetopsServiceQuoteUnitRequestWithQuote('not-a-public-id')))
        ->toThrow(TypeError::class, 'Return value must be of type')
        ->and(FleetOpsServiceQuoteUnitResolvableFake::$lookups)->toBe([])
        ->and(fn () => $quote->createStripeCheckoutSession('/checkout/return'))
        ->toThrow(Exception::class, 'company you attempted to purchase')
        ->and($quote->loadedMissing)->toBe([['company']]);
});
