<?php

use Fleetbase\FleetOps\Http\Controllers\Api\v1\ServiceQuoteController;
use Fleetbase\FleetOps\Http\Requests\QueryServiceQuotesRequest;
use Fleetbase\FleetOps\Http\Resources\v1\ServiceQuote as ServiceQuoteResource;
use Fleetbase\FleetOps\Models\Payload;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Models\ServiceQuote;
use Fleetbase\FleetOps\Models\ServiceQuoteItem;
use Fleetbase\FleetOps\Models\ServiceRate;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Http\JsonResponse;

/**
 * Covers the API ServiceQuoteController query() endpoint branches and the real
 * bodies of its protected helper methods. The existing contracts test covers
 * queryFromPreliminary() with an all-overriding probe, which leaves query()
 * and every real helper implementation unexecuted.
 */
class FleetOpsApiServiceQuoteQueryProbe extends ServiceQuoteController
{
    public ?ServiceRate $serviceRate = null;
    public iterable $servicableRates = [];
    public array $preliminaryCalls   = [];
    public array $createdQuotes      = [];
    public array $createdItems       = [];

    public function callProtected(string $method, array $arguments = []): mixed
    {
        $reflection = new ReflectionMethod(ServiceQuoteController::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($this, ...$arguments);
    }

    public function queryFromPreliminary(QueryServiceQuotesRequest $request)
    {
        $this->preliminaryCalls[] = $request->input('payload');

        return 'preliminary-fallback';
    }

    protected function generateServiceQuoteRequestId(): string
    {
        return 'request_test';
    }

    protected function findServiceRateForQuote(string $service, ?string $currency): ?ServiceRate
    {
        return $this->serviceRate;
    }

    protected function getServicableServiceRates(iterable $waypoints, ?string $serviceType, mixed $currency, callable $callback): iterable
    {
        $callback(new FleetOpsApiServiceQuoteQueryFakeQuery());

        return $this->servicableRates;
    }

    protected function createServiceQuote(array $attributes): ServiceQuote
    {
        $this->createdQuotes[] = $attributes;
        $quote                 = new FleetOpsApiServiceQuoteQuoteFake();
        $quote->setRawAttributes(array_merge(['uuid' => 'quote-' . count($this->createdQuotes)], $attributes), true);

        return $quote;
    }

    protected function createServiceQuoteItem(array $attributes): ServiceQuoteItem
    {
        $this->createdItems[] = $attributes;
        $item                 = new ServiceQuoteItem();
        $item->setRawAttributes($attributes, true);

        return $item;
    }
}

class FleetOpsApiServiceQuoteQueryFakeQuery
{
    public array $wheres = [];

    public function where(...$arguments): self
    {
        $this->wheres[] = $arguments;

        return $this;
    }
}

class FleetOpsApiServiceQuoteQuoteFake extends ServiceQuote
{
    protected $guarded = [];
    public $exists     = true;
}

class FleetOpsApiServiceQuoteQueryRateFake extends ServiceRate
{
    protected $guarded = [];
    public $exists     = true;

    public function quote($payload = null, array $options = [])
    {
        return [4900, collect([
            ['amount' => 4900, 'currency' => 'USD', 'details' => 'Base fee', 'code' => 'BASE'],
        ])];
    }
}

class FleetOpsApiServiceQuoteQueryPayloadFake extends Payload
{
    protected $guarded = [];
    public $exists     = true;

    public function getAllStops(): Illuminate\Support\Collection
    {
        $place = new Place();
        $place->setRawAttributes(['uuid' => 'stop-1', 'location' => new Point(1.0, 2.0)], true);

        return collect([$place]);
    }
}

function fleetopsApiServiceQuoteQueryBoot(): SQLiteConnection
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

    $schema = $connection->getSchemaBuilder();
    app()->instance('db.schema', $schema);
    $schema->create('service_quotes', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('request_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('service_rate_uuid')->nullable();
        $table->integer('amount')->nullable();
        $table->string('currency')->nullable();
        $table->text('meta')->nullable();
        $table->timestamp('expired_at')->nullable();
        $table->timestamps();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('service_quote_items', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('service_quote_uuid')->nullable();
        $table->integer('amount')->nullable();
        $table->string('currency')->nullable();
        $table->string('details')->nullable();
        $table->string('code')->nullable();
        $table->timestamps();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('service_rates', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('currency')->nullable();
        $table->string('service_type')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('places', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('name')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('integrated_vendors', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('provider')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });

    return $connection;
}

function fleetopsApiServiceQuoteQueryRequest(array $input): QueryServiceQuotesRequest
{
    $request = new QueryServiceQuotesRequest($input);
    $request->setLaravelSession(app('session.store'));

    return $request;
}

/*
|--------------------------------------------------------------------------
| query() endpoint branches
|--------------------------------------------------------------------------
*/

test('query falls back to preliminary quoting when no payload resolves', function () {
    fleetopsApiServiceQuoteQueryBoot();
    $probe = new FleetOpsApiServiceQuoteQueryProbe();

    $result = $probe->query(fleetopsApiServiceQuoteQueryRequest(['payload' => ['pickup' => '1 Main St']]));

    expect($result)->toBe('preliminary-fallback')
        ->and($probe->preliminaryCalls)->toHaveCount(1);
});

test('query returns an empty collection when the integrated vendor is missing', function () {
    fleetopsApiServiceQuoteQueryBoot();
    $probe   = new FleetOpsApiServiceQuoteQueryProbe();
    $payload = new FleetOpsApiServiceQuoteQueryPayloadFake();

    $result = $probe->query(fleetopsApiServiceQuoteQueryRequest([
        'payload'     => $payload,
        'facilitator' => 'integrated_vendor_missing',
    ]));

    expect($result)->toBeInstanceOf(Illuminate\Http\Resources\Json\ResourceCollection::class)
        ->and($result->count())->toBe(0);
});

test('query builds a single-service quote with items from the matched rate', function () {
    fleetopsApiServiceQuoteQueryBoot();
    $probe              = new FleetOpsApiServiceQuoteQueryProbe();
    $rate               = new FleetOpsApiServiceQuoteQueryRateFake();
    $rate->setRawAttributes(['uuid' => 'rate-1', 'company_uuid' => 'company-1', 'currency' => 'USD'], true);
    $probe->serviceRate = $rate;

    $single = $probe->query(fleetopsApiServiceQuoteQueryRequest([
        'payload' => new FleetOpsApiServiceQuoteQueryPayloadFake(),
        'service' => 'rate-1',
        'single'  => '1',
    ]));

    expect($single)->toBeInstanceOf(ServiceQuoteResource::class)
        ->and($probe->createdQuotes[0]['amount'])->toBe(4900)
        ->and($probe->createdQuotes[0]['service_rate_uuid'])->toBe('rate-1')
        ->and($probe->createdItems[0]['code'])->toBe('BASE');

    $collection = $probe->query(fleetopsApiServiceQuoteQueryRequest([
        'payload' => new FleetOpsApiServiceQuoteQueryPayloadFake(),
        'service' => 'rate-1',
    ]));

    expect($collection)->toBeInstanceOf(Illuminate\Http\Resources\Json\ResourceCollection::class)
        ->and($collection->count())->toBe(1);
});

test('query quotes all servicable rates and picks the best for single requests', function () {
    fleetopsApiServiceQuoteQueryBoot();
    $probe = new FleetOpsApiServiceQuoteQueryProbe();
    $rate  = new FleetOpsApiServiceQuoteQueryRateFake();
    $rate->setRawAttributes(['uuid' => 'rate-2', 'company_uuid' => 'company-1', 'currency' => 'USD'], true);
    $probe->servicableRates = [$rate];
    session(['company' => 'company-1']);

    $collection = $probe->query(fleetopsApiServiceQuoteQueryRequest([
        'payload' => new FleetOpsApiServiceQuoteQueryPayloadFake(),
    ]));

    expect($collection)->toBeInstanceOf(Illuminate\Http\Resources\Json\ResourceCollection::class)
        ->and($collection->count())->toBe(1)
        ->and($probe->createdItems[0]['details'])->toBe('Base fee');

    $best = $probe->query(fleetopsApiServiceQuoteQueryRequest([
        'payload' => new FleetOpsApiServiceQuoteQueryPayloadFake(),
        'single'  => '1',
    ]));

    expect($best)->toBeInstanceOf(ServiceQuoteResource::class);
});

/*
|--------------------------------------------------------------------------
| Real protected helper bodies
|--------------------------------------------------------------------------
*/

test('service quote helpers execute their real implementations', function () {
    $connection = fleetopsApiServiceQuoteQueryBoot();
    $controller = new class extends ServiceQuoteController {
        public function callProtected(string $method, array $arguments = []): mixed
        {
            $reflection = new ReflectionMethod(ServiceQuoteController::class, $method);
            $reflection->setAccessible(true);

            return $reflection->invoke($this, ...$arguments);
        }
    };

    // generateServiceQuoteRequestId
    $requestId = $controller->callProtected('generateServiceQuoteRequestId');
    expect($requestId)->toStartWith('request_');

    // createPlaceFromMixed returns existing instances untouched
    $place = new Place();
    $place->setRawAttributes(['uuid' => 'place-1'], true);
    expect($controller->callProtected('createPlaceFromMixed', [$place]))->toBe($place);

    // findPlaceByPublicId queries the database
    $connection->table('places')->insert(['uuid' => 'place-2', 'public_id' => 'place_test', 'company_uuid' => 'company-1']);
    $found = $controller->callProtected('findPlaceByPublicId', ['place_test']);
    expect($found)->toBeInstanceOf(Place::class)
        ->and($found->uuid)->toBe('place-2');

    // distanceMatrix uses the calculate provider
    config()->set('fleetops.distance_matrix.provider', 'calculate');
    $origin = new Place();
    $origin->setRawAttributes(['uuid' => 'o1', 'location' => new Point(1.0, 2.0)], true);
    $destination = new Place();
    $destination->setRawAttributes(['uuid' => 'd1', 'location' => new Point(1.5, 2.5)], true);
    $matrix = $controller->callProtected('distanceMatrix', [[$origin], [$destination]]);
    expect($matrix->distance)->toBeGreaterThan(0)
        ->and($matrix->time)->toBeGreaterThan(0);

    // findServiceRateForQuote matches by uuid/public id and currency
    $connection->table('service_rates')->insert(['uuid' => 'rate-uuid', 'public_id' => 'service_rate_x', 'currency' => 'USD', 'company_uuid' => 'company-1']);
    $rate = $controller->callProtected('findServiceRateForQuote', ['rate-uuid', 'usd']);
    expect($rate)->toBeInstanceOf(ServiceRate::class)
        ->and($rate->uuid)->toBe('rate-uuid');

    // findServiceRateByUuid
    $byUuid = $controller->callProtected('findServiceRateByUuid', ['rate-uuid']);
    expect($byUuid)->toBeInstanceOf(ServiceRate::class);

    // getServicableServiceRates delegates to the model with the scoping callback
    $rates = $controller->callProtected('getServicableServiceRates', [[], 'delivery', 'USD', function ($query) {
        $query->where('company_uuid', 'company-1');
    }]);
    expect(is_iterable($rates))->toBeTrue();

    // createServiceQuote and createServiceQuoteItem persist records
    $quote = $controller->callProtected('createServiceQuote', [[
        'request_id'        => $requestId,
        'company_uuid'      => 'company-1',
        'service_rate_uuid' => 'rate-uuid',
        'amount'            => 100,
        'currency'          => 'USD',
    ]]);
    expect($quote)->toBeInstanceOf(ServiceQuote::class)
        ->and($connection->table('service_quotes')->count())->toBe(1);

    $item = $controller->callProtected('createServiceQuoteItem', [[
        'service_quote_uuid' => $quote->uuid,
        'amount'             => 100,
        'currency'           => 'USD',
        'details'            => 'Base',
        'code'               => 'BASE',
    ]]);
    expect($item)->toBeInstanceOf(ServiceQuoteItem::class)
        ->and($connection->table('service_quote_items')->count())->toBe(1);

    // resource wrappers
    expect($controller->callProtected('serviceQuoteResource', [$quote]))->toBeInstanceOf(ServiceQuoteResource::class)
        ->and($controller->callProtected('serviceQuoteResourceCollection', [[$quote]]))
        ->toBeInstanceOf(Illuminate\Http\Resources\Json\ResourceCollection::class);

    // jsonResponse
    $response = $controller->callProtected('jsonResponse', [['ok' => true], 201]);
    expect($response)->toBeInstanceOf(JsonResponse::class)
        ->and($response->getStatusCode())->toBe(201);
});

test('find service quote helper loads records and reports missing ones', function () {
    $connection = fleetopsApiServiceQuoteQueryBoot();
    $controller = new class extends ServiceQuoteController {
        public function callProtected(string $method, array $arguments = []): mixed
        {
            $reflection = new ReflectionMethod(ServiceQuoteController::class, $method);
            $reflection->setAccessible(true);

            return $reflection->invoke($this, ...$arguments);
        }
    };

    $connection->table('service_quotes')->insert(['uuid' => 'sq-1', 'public_id' => 'service_quote_x', 'company_uuid' => 'company-1']);

    $found = $controller->callProtected('findServiceQuote', ['service_quote_x']);
    expect($found)->toBeInstanceOf(ServiceQuote::class)
        ->and($found->uuid)->toBe('sq-1');

    expect(fn () => $controller->callProtected('findServiceQuote', ['missing']))
        ->toThrow(Illuminate\Database\Eloquent\ModelNotFoundException::class);
});
