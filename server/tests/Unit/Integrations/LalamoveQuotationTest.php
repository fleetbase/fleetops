<?php

use Fleetbase\FleetOps\Integrations\Lalamove\Lalamove;
use Fleetbase\FleetOps\Integrations\Lalamove\LalamoveMarket;
use Fleetbase\FleetOps\Models\IntegratedVendor;
use Fleetbase\FleetOps\Models\ServiceQuote;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;

/**
 * Covers the Lalamove integration's quotation-to-service-quote construction,
 * market resolution, constructor credential/market branches, and the static
 * call proxying variants.
 */
if (!function_exists('Fleetbase\Support\session')) {
    eval('namespace Fleetbase\Support; function session($key = null, $default = null) { if ($key === null) { return new class { public function has($k) { return \session($k) !== null; } public function get($k, $d = null) { return \session($k, $d); } }; } return \session($key, $default); }');
}

if (!function_exists('Fleetbase\Support\auth')) {
    eval('namespace Fleetbase\Support; function auth() { return new class { public function user() { return null; } public function id() { return null; } }; }');
}

function fleetopsLalamoveBoot(): SQLiteConnection
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
        'service_quotes'      => ['uuid', 'public_id', 'request_id', 'company_uuid', 'payload_uuid', 'integrated_vendor_uuid', 'service_rate_uuid', 'amount', 'currency', 'meta', 'expired_at', '_key'],
        'service_quote_items' => ['uuid', 'public_id', 'service_quote_uuid', 'amount', 'currency', 'details', 'code', '_key'],
        'companies'           => ['uuid', 'public_id', 'name', 'country'],
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

function fleetopsLalamoveQuotation(): stdClass
{
    return json_decode(json_encode([
        'quotationId'    => 'quote-123',
        'priceBreakdown' => [
            'currency'     => 'SGD',
            'total'        => '25.50',
            'base'         => '20.00',
            'extraMileage' => '3.50',
            'vat'          => '2.00',
        ],
    ]));
}

test('service quote from quotation builds amounts items and metadata', function () {
    fleetopsLalamoveBoot();

    $quotation    = fleetopsLalamoveQuotation();
    $serviceQuote = Lalamove::serviceQuoteFromQuotation($quotation, 'request_test');

    expect($serviceQuote)->toBeInstanceOf(ServiceQuote::class)
        ->and($serviceQuote->currency)->toBe('SGD')
        ->and($serviceQuote->request_id)->toBe('request_test')
        ->and($serviceQuote->items)->toHaveCount(3)
        ->and(collect($serviceQuote->items)->pluck('code')->all())->toBe(['BASE_FEE', 'EXTRA_MILEAGE_FEE', 'VAT_FEE']);

    expect(Lalamove::serviceQuoteFromQuotation(null))->toBeNull();

    // Wrapped data payloads unwrap recursively
    $wrapped = json_decode(json_encode(['data' => fleetopsLalamoveQuotation()]));
    expect(Lalamove::serviceQuoteFromQuotation($wrapped)->currency)->toBe('SGD');
});

test('service quote from quotation records integrated vendor linkage', function () {
    fleetopsLalamoveBoot();

    $vendor = new IntegratedVendor();
    $vendor->setRawAttributes(['uuid' => 'iv-1', 'public_id' => 'integrated_vendor_test'], true);

    $serviceQuote = Lalamove::serviceQuoteFromQuotation(fleetopsLalamoveQuotation(), null, $vendor);

    expect($serviceQuote->integrated_vendor_uuid)->toBe('iv-1')
        ->and($serviceQuote->getMeta('from_integrated_vendor'))->toBe('integrated_vendor_test');
});

test('create service quote from quotation persists the quote and items', function () {
    $connection = fleetopsLalamoveBoot();

    $serviceQuote = Lalamove::createServiceQuoteFromQuotation(fleetopsLalamoveQuotation(), 'request_test');

    expect($serviceQuote)->toBeInstanceOf(ServiceQuote::class)
        ->and($connection->table('service_quotes')->count())->toBe(1)
        ->and($connection->table('service_quote_items')->count())->toBe(3);
});

test('market and service type resolution handles keys and instances', function () {
    fleetopsLalamoveBoot();

    $market = Lalamove::getMarket('SG');
    expect($market)->toBeInstanceOf(LalamoveMarket::class)
        ->and(Lalamove::getMarket($market))->toBe($market)
        ->and(Lalamove::getMarket('XX'))->toBeNull();
});

test('constructor resolves explicit credentials markets and company defaults', function () {
    $connection = fleetopsLalamoveBoot();

    $explicit = new Lalamove('key-1', 'secret-1', true, 'SG');
    expect($explicit)->toBeInstanceOf(Lalamove::class);

    // Company-session country drives the market default
    $connection->table('companies')->insert(['uuid' => 'company-1', 'name' => 'Acme', 'country' => 'TH']);
    $fromCompany = new Lalamove('key-1', 'secret-1');
    expect($fromCompany)->toBeInstanceOf(Lalamove::class);
});

test('static call proxy resolves instance sandbox and unknown methods', function () {
    fleetopsLalamoveBoot();

    expect(Lalamove::instance('key-1', 'secret-1', false, 'SG'))->toBeInstanceOf(Lalamove::class);

    $options = Lalamove::getOptionsFromSandbox();
    expect($options)->toBeArray();

    expect(Lalamove::completelyUnknownMethod())->toBeNull();
});
