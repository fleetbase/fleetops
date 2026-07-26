<?php

if (!function_exists('Fleetbase\Traits\config')) {
    eval('namespace Fleetbase\Traits; function config($key = null, $default = null) { return $key === "api.cache.enabled" ? false : $default; }');
}

if (!function_exists('Fleetbase\Models\config')) {
    eval('namespace Fleetbase\Models; function config($key = null, $default = null) { return $key === "fleetbase.connection.db" ? "mysql" : $default; }');
}

if (!function_exists('Fleetbase\FleetOps\Integrations\Lalamove\session')) {
    eval('namespace Fleetbase\FleetOps\Integrations\Lalamove; function session($key = null, $default = null) { return $key === "company" ? "company-service-quote" : $default; }');
}

if (!class_exists('Stripe\StripeClient')) {
    eval('
        namespace Stripe;

        class Product
        {
            public function __construct(array $attributes = [])
            {
                foreach ($attributes as $key => $value) {
                    $this->{$key} = $value;
                }
            }
        }

        class Price
        {
            public function __construct(array $attributes = [])
            {
                foreach ($attributes as $key => $value) {
                    $this->{$key} = $value;
                }
            }
        }

        class StripeClient
        {
            public static ?object $nextClient = null;

            public function __construct(array $options = [])
            {
                if (self::$nextClient) {
                    foreach (get_object_vars(self::$nextClient) as $key => $value) {
                        $this->{$key} = $value;
                    }
                }
            }
        }

        namespace Stripe\Checkout;

        class Session
        {
            public function __construct(array $attributes = [])
            {
                foreach ($attributes as $key => $value) {
                    $this->{$key} = $value;
                }
            }
        }
    ');
}

use Fleetbase\FleetOps\Models\IntegratedVendor;
use Fleetbase\FleetOps\Models\Payload;
use Fleetbase\FleetOps\Models\ServiceQuote;
use Fleetbase\FleetOps\Models\ServiceQuoteItem;
use Fleetbase\FleetOps\Models\ServiceRate;
use Fleetbase\Models\Company;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;

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

class FleetOpsServiceQuoteUnitDatabaseProbe
{
    public function __construct(private SQLiteConnection $connection)
    {
    }

    public function connection(): SQLiteConnection
    {
        return $this->connection;
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

class FleetOpsServiceQuoteUnitNoPriceCheckoutFake extends FleetOpsServiceQuoteUnitCheckoutFake
{
    public function updateOrCreateStripePrice(): ?Stripe\Price
    {
        return null;
    }
}

class FleetOpsServiceQuoteUnitNamedFake extends ServiceQuote
{
    public ?string $pluralName   = null;
    public ?string $singularName = null;
    public ?string $payloadKey   = null;
}

class FleetOpsServiceQuoteUnitStripeProductsFake
{
    public array $created   = [];
    public array $retrieved = [];

    public function __construct(private ?Stripe\Product $retrievedProduct = null)
    {
    }

    public function retrieve(string $id, array $params): ?Stripe\Product
    {
        $this->retrieved[] = [$id, $params];

        return $this->retrievedProduct;
    }

    public function create(array $params): Stripe\Product
    {
        $this->created[] = $params;

        return new Stripe\Product(['id' => 'prod_created']);
    }
}

class FleetOpsServiceQuoteUnitStripePricesFake
{
    public array $all     = [];
    public array $created = [];
    public array $updated = [];

    public function __construct(private ?Stripe\Price $activePrice = null)
    {
    }

    public function all(array $params): object
    {
        $this->all[] = $params;

        return (object) ['data' => $this->activePrice ? [$this->activePrice] : []];
    }

    public function update(string $id, array $params): Stripe\Price
    {
        $this->updated[] = [$id, $params];

        return new Stripe\Price(['id' => $id, ...$params]);
    }

    public function create(array $params): Stripe\Price
    {
        $this->created[] = $params;

        return new Stripe\Price(['id' => 'price_created', ...$params]);
    }
}

class FleetOpsServiceQuoteUnitStripeSessionsFake
{
    public array $created = [];

    public function create(array $params): Stripe\Checkout\Session
    {
        $this->created[] = $params;

        return new Stripe\Checkout\Session(['id' => 'cs_created', 'params' => $params]);
    }
}

function fleetopsServiceQuoteUseStripeClient(object $client): void
{
    if (property_exists(Stripe\StripeClient::class, 'nextClient')) {
        Stripe\StripeClient::$nextClient = $client;
    }
}

function fleetopsServiceQuoteUseConsoleContainer(): Container
{
    $previous = Container::getInstance();

    $container = new class extends Container {
        public function environment($environments = null): bool
        {
            return in_array('local', (array) $environments, true);
        }
    };

    $container->instance('config', new Repository([
        'fleetbase' => [
            'console' => [
                'host'      => 'console.test',
                'secure'    => false,
                'subdomain' => null,
            ],
        ],
        'fleet-ops' => [
            'facilitator_fee' => 0,
        ],
    ]));

    Container::setInstance($container);
    Facade::setFacadeApplication($container);

    return $previous;
}

function fleetopsServiceQuoteUseRelationConnection(): void
{
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
    $connection->statement('create table service_quotes (uuid varchar(64), expired_at datetime null, deleted_at datetime null)');
    $connection->statement('create table service_quote_items (uuid varchar(64), deleted_at datetime null)');

    $resolver   = new ConnectionResolver([
        'default' => $connection,
        'mysql'   => $connection,
    ]);

    $resolver->setDefaultConnection('mysql');
    EloquentModel::setConnectionResolver($resolver);
    app()->instance('db', new FleetOpsServiceQuoteUnitDatabaseProbe($connection));
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

    $metadataQuote = new ServiceQuote();
    $metadataQuote->setMeta('from_integrated_vendor', 'vendor_public');

    expect($vendorQuote->fromIntegratedVendor())->toBeTrue()
        ->and($metadataQuote->fromIntegratedVendor())->toBeTrue();
});

test('service quote converts lalamove quotations into quote items without persisting', function () {
    fleetopsServiceQuoteUseRelationConnection();

    $quotation = (object) [
        'priceBreakdown' => (object) [
            'currency'     => 'SGD',
            'total'        => '19.95',
            'base'         => '12.50',
            'extraMileage' => '5.00',
            'vat'          => '2.45',
        ],
    ];

    $quote = ServiceQuote::fromLalamoveQuotation($quotation);
    $items = collect($quote->getRelation('items'));

    expect($quote)->toBeInstanceOf(ServiceQuote::class)
        ->and($quote->amount)->toBe(1995)
        ->and($quote->currency)->toBe('SGD')
        ->and($quote->getMeta('provider'))->toBe('lalamove')
        ->and($items)->toHaveCount(3)
        ->and($items)->each->toBeInstanceOf(ServiceQuoteItem::class)
        ->and($items->pluck('code')->all())->toBe(['BASE_FEE', 'EXTRA_MILEAGE_FEE', 'VAT_FEE']);

    $wrapped = ServiceQuote::fromLalamoveQuotation((object) ['data' => $quotation]);

    expect($wrapped)->toBeInstanceOf(ServiceQuote::class)
        ->and($wrapped->amount)->toBe(1995);
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

test('service quote checkout rejects company quotes without an active stripe price', function () {
    $company = new Company();
    $company->setRawAttributes([
        'uuid'              => 'company-uuid',
        'stripe_connect_id' => 'acct_test',
    ], true);

    $quote = new FleetOpsServiceQuoteUnitNoPriceCheckoutFake();
    $quote->setRawAttributes([
        'uuid'      => 'service-quote-uuid',
        'public_id' => 'quote_ABCDEFG',
        'amount'    => 1500,
        'currency'  => 'SGD',
    ], true);
    $quote->setRelation('company', $company);

    expect(fn () => $quote->createStripeCheckoutSession('/checkout/return'))
        ->toThrow(Exception::class, 'service quote you attempted to purchase')
        ->and($quote->loadedMissing)->toBe([['company']]);
});

test('service quote retrieves existing stripe products and stores product metadata', function () {
    $products = new FleetOpsServiceQuoteUnitStripeProductsFake(new Stripe\Product(['id' => 'prod_existing']));
    fleetopsServiceQuoteUseStripeClient((object) [
        'products' => $products,
    ]);

    $quote = new ServiceQuote();
    $quote->setRawAttributes([
        'public_id' => 'quote_EXISTING',
        'amount'    => 1995,
        'currency'  => 'SGD',
    ], true);
    $quote->setMeta('stripe_product_id', 'prod_existing');

    $product = $quote->getStripeProduct();

    expect($product)->toBeInstanceOf(Stripe\Product::class)
        ->and($product->id)->toBe('prod_existing')
        ->and($products->retrieved)->toBe([['prod_existing', []]])
        ->and($products->created)->toBe([])
        ->and($quote->getMeta('stripe_product_id'))->toBe('prod_existing');
});

test('service quote creates stripe products and active prices through the local stripe client', function () {
    $products = new FleetOpsServiceQuoteUnitStripeProductsFake();
    $prices   = new FleetOpsServiceQuoteUnitStripePricesFake(new Stripe\Price(['id' => 'price_active', 'unit_amount' => 3250]));
    fleetopsServiceQuoteUseStripeClient((object) [
        'products' => $products,
        'prices'   => $prices,
    ]);

    $quote = new ServiceQuote();
    $quote->setRawAttributes([
        'public_id' => 'quote_CREATE',
        'amount'    => 3250,
        'currency'  => 'USD',
    ], true);

    $product = $quote->getStripeProduct();
    $price   = $quote->getStripePrice();

    expect($product)->toBeInstanceOf(Stripe\Product::class)
        ->and($product->id)->toBe('prod_created')
        ->and($products->created)->toHaveCount(1)
        ->and($products->created[0]['name'])->toBe('quote_CREATE')
        ->and($products->created[0]['metadata'])->toBe(['fleetbase_id' => 'quote_CREATE'])
        ->and($quote->getMeta('stripe_product_id'))->toBe('prod_created')
        ->and($price)->toBeInstanceOf(Stripe\Price::class)
        ->and($price->id)->toBe('price_active')
        ->and($prices->all)->toBe([
            ['product' => 'prod_created', 'limit' => 1, 'active' => true],
        ]);
});

test('service quote replaces active stripe prices and creates embedded checkout sessions', function () {
    $previousContainer = fleetopsServiceQuoteUseConsoleContainer();

    $company = new Company();
    $company->setRawAttributes([
        'uuid'              => 'company-uuid',
        'stripe_connect_id' => 'acct_vendor',
    ], true);

    $products = new FleetOpsServiceQuoteUnitStripeProductsFake();
    $prices   = new FleetOpsServiceQuoteUnitStripePricesFake(new Stripe\Price(['id' => 'price_old', 'unit_amount' => 4500]));
    $sessions = new FleetOpsServiceQuoteUnitStripeSessionsFake();
    fleetopsServiceQuoteUseStripeClient((object) [
        'products' => $products,
        'prices'   => $prices,
        'checkout' => (object) [
            'sessions' => $sessions,
        ],
    ]);

    $quote = new FleetOpsServiceQuoteUnitCheckoutFake();
    $quote->setRawAttributes([
        'uuid'      => 'service-quote-checkout',
        'public_id' => 'quote_CHECKOUT',
        'amount'    => 4500,
        'currency'  => 'SGD',
    ], true);
    $quote->setRelation('company', $company);

    try {
        $createdPrice = $quote->updateOrCreateStripePrice();
        $session      = $quote->createStripeCheckoutSession('/checkout/return');

        expect($createdPrice)->toBeInstanceOf(Stripe\Price::class)
            ->and($createdPrice->id)->toBe('price_created')
            ->and($prices->updated)->toBe([
                ['price_old', ['active' => false]],
                ['price_old', ['active' => false]],
            ])
            ->and($prices->created)->toBe([
                ['unit_amount' => 4500, 'currency' => 'SGD', 'product' => 'prod_created'],
                ['unit_amount' => 4500, 'currency' => 'SGD', 'product' => 'prod_created'],
            ])
            ->and($session)->toBeInstanceOf(Stripe\Checkout\Session::class)
            ->and($session->id)->toBe('cs_created')
            ->and($sessions->created)->toHaveCount(1)
            ->and($sessions->created[0]['ui_mode'])->toBe('embedded')
            ->and($sessions->created[0]['line_items'])->toBe([
                ['price' => 'price_created', 'quantity' => 1],
            ])
            ->and($sessions->created[0]['mode'])->toBe('payment')
            ->and($sessions->created[0]['return_url'])->toContain('/checkout/return?service_quote=service-quote-checkout&checkout_session_id={CHECKOUT_SESSION_ID}&creating=1')
            ->and($sessions->created[0]['payment_intent_data'])->toBe([
                'application_fee_amount' => 0,
                'transfer_data'          => [
                    'destination' => 'acct_vendor',
                ],
            ])
            ->and($quote->loadedMissing)->toBe([['company']]);
    } finally {
        Container::setInstance($previousContainer);
        Facade::setFacadeApplication($previousContainer);
    }
});
