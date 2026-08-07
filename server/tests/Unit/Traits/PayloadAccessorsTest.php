<?php

if (!function_exists('Fleetbase\Traits\config')) {
    eval('namespace Fleetbase\Traits; function config($key = null, $default = null) { return $key === "api.cache.enabled" ? false : $default; }');
}

if (!function_exists('Fleetbase\Models\config')) {
    eval('namespace Fleetbase\Models; function config($key = null, $default = null) { return $key === "fleetbase.connection.db" ? "mysql" : $default; }');
}

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Payload;
use Fleetbase\FleetOps\Traits\PayloadAccessors;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FleetOpsPayloadAccessorRelationFake extends BelongsTo
{
    public bool $withoutGlobalScopesCalled = false;

    public function __construct(public ?Payload $result = null)
    {
    }

    public function withoutGlobalScopes(?array $scopes = null)
    {
        $this->withoutGlobalScopesCalled = true;

        return $this;
    }

    public function first($columns = ['*']): ?Payload
    {
        return $this->result;
    }
}

class FleetOpsPayloadAccessorLookupFake
{
    public bool $withoutGlobalScopesCalled = false;
    public array $finds                    = [];

    public function __construct(public ?Payload $result = null)
    {
    }

    public function withoutGlobalScopes(?array $scopes = null): self
    {
        $this->withoutGlobalScopesCalled = true;

        return $this;
    }

    public function find(string $uuid): ?Payload
    {
        $this->finds[] = $uuid;

        return $this->result;
    }
}

class FleetOpsPayloadAccessorHostFake extends Model
{
    use PayloadAccessors;

    protected $fillable = ['payload_uuid'];

    public FleetOpsPayloadAccessorRelationFake $relation;
    public FleetOpsPayloadAccessorLookupFake $lookup;

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->relation = new FleetOpsPayloadAccessorRelationFake();
        $this->lookup   = new FleetOpsPayloadAccessorLookupFake();
    }

    public function payload(): BelongsTo
    {
        return $this->relation;
    }

    protected function payloadLookupQuery(): mixed
    {
        return $this->lookup;
    }
}

test('payload accessors return loaded scoped payload and order relation', function () {
    $order = new Order();
    $order->setRawAttributes(['uuid' => 'order-uuid'], true);

    $payload = new Payload();
    $payload->setRawAttributes(['uuid' => 'payload-uuid'], true);
    $payload->setRelation('order', $order);

    $host = new FleetOpsPayloadAccessorHostFake();
    $host->setRelation('payload', $payload);

    expect($host->getPayload())->toBe($payload)
        ->and($host->getOrder())->toBe($order)
        ->and($host->relation->withoutGlobalScopesCalled)->toBeFalse()
        ->and($host->lookup->finds)->toBe([]);
});

test('payload accessors resolve and cache payload from relationship query', function () {
    $payload = new Payload();
    $payload->setRawAttributes(['uuid' => 'payload-uuid'], true);

    $host                   = new FleetOpsPayloadAccessorHostFake();
    $host->relation->result = $payload;

    expect($host->getPayload())->toBe($payload)
        ->and($host->getRelation('payload'))->toBe($payload)
        ->and($host->relation->withoutGlobalScopesCalled)->toBeFalse()
        ->and($host->lookup->finds)->toBe([]);
});

test('payload accessors use unscoped relationship for trashed payloads', function () {
    $payload = new Payload();
    $payload->setRawAttributes(['uuid' => 'payload-uuid'], true);

    $host                   = new FleetOpsPayloadAccessorHostFake();
    $host->relation->result = $payload;

    expect($host->getTrashedPayload())->toBe($payload)
        ->and($host->relation->withoutGlobalScopesCalled)->toBeTrue()
        ->and($host->getRelation('payload'))->toBe($payload);
});

test('payload accessors fall back to uuid lookup and ignore invalid identifiers', function () {
    $payload = new Payload();
    $payload->setRawAttributes(['uuid' => '11111111-1111-4111-8111-111111111111'], true);

    $host = new FleetOpsPayloadAccessorHostFake([
        'payload_uuid' => '11111111-1111-4111-8111-111111111111',
    ]);
    $host->lookup->result = $payload;

    expect($host->getTrashedPayload())->toBe($payload)
        ->and($host->lookup->withoutGlobalScopesCalled)->toBeTrue()
        ->and($host->lookup->finds)->toBe(['11111111-1111-4111-8111-111111111111'])
        ->and($host->getRelation('payload'))->toBe($payload);

    $invalid = new FleetOpsPayloadAccessorHostFake(['payload_uuid' => 'not-a-uuid']);

    expect($invalid->getPayload())->toBeNull()
        ->and($invalid->lookup->finds)->toBe([]);
});
