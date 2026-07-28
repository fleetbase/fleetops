<?php

if (!function_exists('Fleetbase\Traits\config')) {
    eval('namespace Fleetbase\Traits; function config($key = null, $default = null) { return $key === "api.cache.enabled" ? false : $default; }');
}

if (!function_exists('Fleetbase\Models\config')) {
    eval('namespace Fleetbase\Models; function config($key = null, $default = null) { return $key === "fleetbase.connection.db" ? "mysql" : $default; }');
}

if (!function_exists('Fleetbase\FleetOps\Integrations\Lalamove\session')) {
    eval('namespace Fleetbase\FleetOps\Integrations\Lalamove; function session($key = null, $default = null) { return $key === "company" ? "company-1" : $default; }');
}

if (!function_exists('Fleetbase\Observers\event')) {
    eval('namespace Fleetbase\Observers; function event($event = null, $payload = []) { $GLOBALS["fleetopsLalamoveQuoteEvents"][] = $event; return []; }');
}

if (!function_exists('Fleetbase\Support\session')) {
    eval('namespace Fleetbase\Support; function session($key = null, $default = null) { if ($key === null) { return new class { public function has($k) { return \session($k) !== null; } public function get($k, $d = null) { return \session($k, $d); } }; } return \session($key, $default); }');
}

use Fleetbase\FleetOps\Integrations\Lalamove\Lalamove;
use Fleetbase\FleetOps\Models\Payload;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Models\ServiceQuote;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Covers Lalamove quote creation from payloads and preliminary stops with a
 * mocked HTTP client, and the full createOrderFromServiceQuote flow
 * resolving senders, recipients, markets and metadata from service-quote
 * meta and request payload references.
 */
if (!Str::hasMacro('humanize')) {
    Str::macro('humanize', fn ($value) => ucfirst(str_replace(['_', '-'], ' ', Str::snake((string) $value))));
}

if (!Request::hasMacro('or')) {
    Request::macro('or', function (array $params = [], $default = null) {
        foreach ($params as $param) {
            if ($this->has($param)) {
                return $this->input($param);
            }
        }

        return $default;
    });
}

function fleetopsLalamoveQuoteClient(array $responses, array &$history = []): Lalamove
{
    $mock    = new MockHandler($responses);
    $history = [];
    $stack   = HandlerStack::create($mock);
    $stack->push(Middleware::history($history));
    $client = new Client([
        'base_uri' => 'https://rest.sandbox.lalamove.com/v3/',
        'handler'  => $stack,
    ]);

    $lalamove = new Lalamove('api-key', 'api-secret', true, 'SG');
    $property = new ReflectionProperty($lalamove, 'client');
    $property->setAccessible(true);
    $property->setValue($lalamove, $client);

    return $lalamove;
}

function fleetopsLalamoveQuoteBoot(): SQLiteConnection
{
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
    $resolver   = new ConnectionResolver(['default' => $connection, 'mysql' => $connection]);
    $resolver->setDefaultConnection('mysql');
    EloquentModel::setConnectionResolver($resolver);
    EloquentModel::setEventDispatcher(new Illuminate\Events\Dispatcher());
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

    app()->instance('redis', new class {
        public function get($key)
        {
            return null;
        }

        public function set($key, $value)
        {
            return true;
        }

        public function connection($name = null)
        {
            return $this;
        }

        public function __call($method, $arguments)
        {
            return null;
        }
    });
    Illuminate\Support\Facades\Redis::clearResolvedInstance('redis');

    app()->instance('responsecache', new class {
        public function __call($method, $arguments)
        {
            return null;
        }
    });

    $schema = $connection->getSchemaBuilder();
    foreach (['places' => ['uuid', 'public_id', 'company_uuid', 'name', 'street1', 'city', 'country', 'phone', 'location', 'meta', 'type'], 'companies' => ['uuid', 'public_id', 'name', 'phone', 'country'], 'service_quotes' => ['uuid', 'public_id', 'request_id', 'company_uuid', 'payload_uuid', 'integrated_vendor_uuid', 'service_rate_uuid', 'amount', 'currency', 'meta', 'expired_at', '_key'], 'service_quote_items' => ['uuid', 'public_id', 'service_quote_uuid', 'amount', 'currency', 'details', 'code', '_key']] as $table => $columns) {
        $schema->create($table, function ($blueprint) use ($columns) {
            $blueprint->increments('id');
            foreach ($columns as $column) {
                $blueprint->string($column)->nullable();
            }
            $blueprint->timestamps();
            $blueprint->timestamp('deleted_at')->nullable();
        });
    }
    $connection->table('companies')->insert(['uuid' => 'company-1', 'public_id' => 'company_lala1', 'name' => 'Acme Logistics', 'phone' => '+6512345678', 'country' => 'SG']);

    session(['company' => 'company-1']);

    return $connection;
}

function fleetopsLalamoveQuotePlace(string $uuid, string $name): Place
{
    $place = new Place();
    $place->setRawAttributes([
        'uuid'    => $uuid,
        'name'    => $name,
        'street1' => $name . ' Street',
        'country' => 'SG',
        'phone'   => '+6591234567',
    ], true);
    $place->setAttribute('location', new Point(1.30, 103.80));

    return $place;
}

function fleetopsLalamoveQuotationBody(): array
{
    return [
        'data' => [
            'quotationId'    => 'quote-1',
            'scheduleAt'     => '2026-08-01T09:00:00Z',
            'expiresAt'      => '2026-08-01T10:00:00Z',
            'priceBreakdown' => [
                'currency' => 'SGD',
                'total'    => '18.50',
                'base'     => '15.00',
                'vat'      => '1.50',
            ],
            'stops' => [
                ['stopId' => 'stop-0'],
                ['stopId' => 'stop-1'],
            ],
        ],
    ];
}

test('preliminary and payload quotes resolve markets and build service quotes', function () {
    fleetopsLalamoveQuoteBoot();

    $pickup  = fleetopsLalamoveQuotePlace('11111111-1111-4111-8111-111111111111', 'Depot');
    $dropoff = fleetopsLalamoveQuotePlace('22222222-2222-4222-8222-222222222222', 'Customer');

    $history  = [];
    $lalamove = fleetopsLalamoveQuoteClient([
        new Response(200, [], json_encode(fleetopsLalamoveQuotationBody())),
        new Response(200, [], json_encode(fleetopsLalamoveQuotationBody())),
    ], $history);
    $lalamove->setRequestId('req-1');

    $preliminary = $lalamove->getQuoteFromPreliminaryPayload([$pickup, $dropoff], [], null, null);
    expect($preliminary)->toBeInstanceOf(ServiceQuote::class)
        ->and($preliminary->currency)->toBe('SGD')
        ->and((int) $preliminary->amount)->toBe(1850)
        ->and($preliminary->items)->toHaveCount(2);

    $payload = new Payload();
    $payload->setRawAttributes(['uuid' => 'payload-1'], true);
    $payload->setRelation('pickup', $pickup);
    $payload->setRelation('dropoff', $dropoff);
    $payload->setRelation('waypoints', collect());
    $payload->setRelation('entities', collect());

    $fromPayload = $lalamove->getQuoteFromPayload($payload, 'MOTORCYCLE');
    expect($fromPayload)->toBeInstanceOf(ServiceQuote::class)
        ->and($fromPayload->payload_uuid)->toBe('payload-1');
});

test('create order from service quote resolves senders recipients and metadata', function () {
    $connection = fleetopsLalamoveQuoteBoot();
    $connection->table('places')->insert([
        ['uuid' => '11111111-1111-4111-8111-111111111111', 'public_id' => 'place_lalasq1', 'company_uuid' => 'company-1', 'name' => 'Depot', 'street1' => 'Depot Street', 'country' => 'SG', 'phone' => '+6591234567'],
        ['uuid' => '22222222-2222-4222-8222-222222222222', 'public_id' => 'place_lalasq2', 'company_uuid' => 'company-1', 'name' => 'Customer Stop', 'street1' => 'Customer Street', 'country' => 'SG', 'phone' => '+6598765432'],
    ]);

    $company = new Fleetbase\Models\Company();
    $company->setRawAttributes(['uuid' => 'company-1', 'public_id' => 'company_lala1', 'name' => 'Acme Logistics', 'phone' => '+6512345678', 'country' => 'SG'], true);

    $serviceQuote = new ServiceQuote();
    $serviceQuote->setRawAttributes([
        'uuid'      => 'sq-1',
        'public_id' => 'service_quote_lala1',
        'currency'  => 'SGD',
        'meta'      => json_encode([
            'provider' => 'lalamove',
            'data'     => [
                'quotationId' => 'quote-1',
                'stops'       => [['stopId' => 'stop-0'], ['stopId' => 'stop-1']],
            ],
        ]),
    ], true);
    $serviceQuote->setRelation('company', $company);
    $serviceQuote->setRelation('integratedVendor', null);
    $serviceQuote->setRelation('payload', null);

    $request = Request::create('/v1/orders', 'POST', [
        'pickup'    => ['uuid' => '11111111-1111-4111-8111-111111111111'],
        'dropoff'   => ['uuid' => '22222222-2222-4222-8222-222222222222'],
        'waypoints' => [],
        'order'     => ['pod_required' => true],
    ]);

    $history  = [];
    $lalamove = fleetopsLalamoveQuoteClient([
        new Response(200, [], json_encode(['data' => ['orderId' => 'lala-order-1']])),
    ], $history);

    $result = $lalamove->createOrderFromServiceQuote($serviceQuote, $request);
    expect($result->orderId)->toBe('lala-order-1');

    $sent = json_decode((string) $history[0]['request']->getBody(), true);
    expect($sent['data']['quotationId'])->toBe('quote-1')
        ->and($sent['data']['sender']['stopId'])->toBe('stop-0')
        ->and($sent['data']['recipients'])->toHaveCount(1)
        ->and($sent['data']['isPODEnabled'])->toBeTrue()
        ->and($sent['data']['metadata']['company'])->toBe('company_lala1')
        ->and($sent['data']['metadata']['service_quote'])->toBe('service_quote_lala1');
});
