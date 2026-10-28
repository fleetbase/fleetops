<?php

use Fleetbase\FleetOps\Http\Controllers\Api\v1\OrderController;
use Fleetbase\FleetOps\Http\Requests\CreateOrderRequest;
use Fleetbase\FleetOps\Http\Resources\v1\Order as OrderResource;
use Fleetbase\FleetOps\Models\Order;
use Illuminate\Support\Facades\Cache;

/**
 * POST /v1/orders with an Idempotency-Key: the first request creates the order, repeats replay it,
 * a concurrent duplicate is refused, and requests without a key are untouched.
 */
class FleetOpsOrderIdempotencyResource extends OrderResource
{
    public function toArray($request): array
    {
        return ['id' => $this->resource->public_id];
    }
}

class FleetOpsOrderIdempotencyProbe extends OrderController
{
    public int $created        = 0;
    public array $orders       = [];
    public mixed $nextResponse = null;

    public function __construct()
    {
    }

    protected function createOrderFromRequest(CreateOrderRequest $request)
    {
        $this->created++;
        if ($this->nextResponse !== null) {
            return $this->nextResponse;
        }

        $order = new Order();
        $order->setRawAttributes(['uuid' => 'order-uuid-' . $this->created, 'public_id' => 'order_idem' . $this->created, 'company_uuid' => 'company-1'], true);
        $this->orders[$order->public_id] = $order;

        return new OrderResource($order);
    }

    protected function sessionCompany(): ?string
    {
        return 'company-1';
    }

    protected function orderResource(Order $order)
    {
        return new FleetOpsOrderIdempotencyResource($order);
    }

    public function callProtected(string $method, array $arguments = []): mixed
    {
        $reflection = new ReflectionMethod(OrderController::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($this, ...$arguments);
    }
}

function fleetopsIdempotencyRequest(?string $key): CreateOrderRequest
{
    $request = CreateOrderRequest::create('/v1/orders', 'POST', [], [], [], $key === null ? [] : ['HTTP_IDEMPOTENCY_KEY' => $key]);
    $request->setLaravelSession(app('session.store'));

    return $request;
}

function fleetopsIdempotencyBoot(): Illuminate\Database\SQLiteConnection
{
    Cache::flush();
    session(['company' => 'company-1', 'api_credential' => 'cred-1']);
    $connection = new Illuminate\Database\SQLiteConnection(new PDO('sqlite::memory:'));
    $resolver   = new Illuminate\Database\ConnectionResolver(['default' => $connection, 'mysql' => $connection]);
    $resolver->setDefaultConnection('mysql');
    Illuminate\Database\Eloquent\Model::setConnectionResolver($resolver);
    $connection->getSchemaBuilder()->create('orders', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });

    return $connection;
}

test('requests without an idempotency key create every time', function () {
    fleetopsIdempotencyBoot();
    $controller = new FleetOpsOrderIdempotencyProbe();

    $controller->create(fleetopsIdempotencyRequest(null));
    $controller->create(fleetopsIdempotencyRequest('   '));
    $controller->create(fleetopsIdempotencyRequest(str_repeat('k', 256)));

    expect($controller->created)->toBe(3);
});

test('a repeated idempotency key replays the first order', function () {
    $connection = fleetopsIdempotencyBoot();
    $controller = new FleetOpsOrderIdempotencyProbe();

    $first = $controller->create(fleetopsIdempotencyRequest('shopify:shop1:5001'));
    expect($first)->toBeInstanceOf(OrderResource::class)
        ->and($controller->created)->toBe(1);

    $connection->table('orders')->insert(['uuid' => 'order-uuid-1', 'public_id' => 'order_idem1', 'company_uuid' => 'company-1']);
    $replay = $controller->create(fleetopsIdempotencyRequest('shopify:shop1:5001'));
    expect($controller->created)->toBe(1)
        ->and($replay->headers->get('Idempotent-Replayed'))->toBe('true')
        ->and(json_decode($replay->getContent(), true)['data']['id'] ?? json_decode($replay->getContent(), true)['id'] ?? null)->toBe('order_idem1');

    // A different key creates a new order
    $controller->create(fleetopsIdempotencyRequest('shopify:shop1:5002'));
    expect($controller->created)->toBe(2);
});

test('keys are scoped to the company and credential, and a vanished order is created again', function () {
    fleetopsIdempotencyBoot();
    $controller = new FleetOpsOrderIdempotencyProbe();

    $a = $controller->callProtected('orderIdempotencyCacheKey', ['k']);
    session(['api_credential' => 'cred-2']);
    $b = $controller->callProtected('orderIdempotencyCacheKey', ['k']);
    expect($a)->not->toBe($b);

    // The cached order no longer exists: replay finds nothing and the order is created
    Cache::put($b, 'order_gone', 60);
    $controller->create(fleetopsIdempotencyRequest('k'));
    expect($controller->created)->toBe(1);
});

test('a concurrent duplicate is refused and failed creations are not remembered', function () {
    fleetopsIdempotencyBoot();
    $controller = new FleetOpsOrderIdempotencyProbe();
    $cacheKey   = $controller->callProtected('orderIdempotencyCacheKey', ['busy']);

    $lock = Cache::lock($cacheKey . ':lock', 60);
    $lock->get();
    $busy = $controller->create(fleetopsIdempotencyRequest('busy'));
    expect($busy->getStatusCode())->toBe(409)
        ->and($controller->created)->toBe(0);
    $lock->release();

    // An error response is passed through and does not claim the key
    $controller->nextResponse = response()->json(['errors' => ['nope']], 400);
    $failed                   = $controller->create(fleetopsIdempotencyRequest('busy'));
    expect($failed->getStatusCode())->toBe(400)
        ->and(Cache::get($cacheKey))->toBeNull();

    $controller->nextResponse = null;
    $controller->create(fleetopsIdempotencyRequest('busy'));
    expect(Cache::get($cacheKey))->toBe('order_idem2');
});

test('an order stored by a duplicate that finished first is replayed once the lock is taken', function () {
    $connection = fleetopsIdempotencyBoot();
    $controller = new class extends FleetOpsOrderIdempotencyProbe {
        public int $lookups = 0;

        protected function replayIdempotentOrder(string $cacheKey, Illuminate\Http\Request $request)
        {
            // The first lookup misses; the duplicate finishes before this request takes the lock
            if ($this->lookups++ === 0) {
                Cache::put($cacheKey, 'order_raced', 60);

                return null;
            }

            return parent::replayIdempotentOrder($cacheKey, $request);
        }
    };
    $connection->table('orders')->insert(['uuid' => 'order-uuid-raced', 'public_id' => 'order_raced', 'company_uuid' => 'company-1']);

    $replay = $controller->create(fleetopsIdempotencyRequest('raced'));

    expect($controller->created)->toBe(0)
        ->and($controller->lookups)->toBe(2)
        ->and($replay->headers->get('Idempotent-Replayed'))->toBe('true');
});
