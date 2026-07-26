<?php

use Fleetbase\FleetOps\Http\Controllers\Api\v1\PurchaseRateController;
use Fleetbase\FleetOps\Http\Requests\CreatePurchaseRateRequest;
use Fleetbase\FleetOps\Http\Resources\v1\PurchaseRate as PurchaseRateResource;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\PurchaseRate;
use Fleetbase\FleetOps\Models\ServiceQuote;
use Fleetbase\Http\Resources\FleetbaseResourceCollection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;

class FleetOpsApiPurchaseRateControllerProbe extends PurchaseRateController
{
    public ?PurchaseRate $purchaseRate = null;
    public mixed $queryResults         = null;
    public bool $purchaseRateNotFound  = false;
    public array $findCalls            = [];

    protected function queryPurchaseRates(Request $request)
    {
        return $this->queryResults ?? [['uuid' => 'purchase-rate-uuid']];
    }

    protected function findPurchaseRate(string $id): PurchaseRate
    {
        $this->findCalls[] = $id;

        if ($this->purchaseRateNotFound) {
            throw new ModelNotFoundException();
        }

        $this->purchaseRate ??= new PurchaseRate();
        $this->purchaseRate->setRawAttributes([
            'uuid'      => 'purchase-rate-uuid',
            'public_id' => 'purchase_rate_public',
            'status'    => 'created',
        ], true);

        return $this->purchaseRate;
    }

    protected function purchaseRateResource(PurchaseRate $purchaseRate)
    {
        return ['resource' => 'purchase-rate', 'purchaseRate' => $purchaseRate];
    }

    protected function purchaseRateResourceCollection($results)
    {
        return ['collection' => 'purchase-rate', 'items' => $results];
    }

    protected function jsonResponse(array $payload, int $status)
    {
        return ['json' => $payload, 'status' => $status];
    }
}

class FleetOpsApiPurchaseRateControllerParentProbe extends PurchaseRateController
{
    public function callParentHelper(string $method, mixed ...$arguments): mixed
    {
        $reflection = new ReflectionMethod(PurchaseRateController::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($this, ...$arguments);
    }
}

class FleetOpsApiPurchaseRateCreateControllerProbe extends PurchaseRateController
{
    public array $uuidLookups           = [];
    public array $createdPurchaseRates  = [];
    public array $serviceQuoteLookups   = [];
    public ?Order $order                = null;
    public ?Order $createdOrder         = null;
    public ?ServiceQuote $serviceQuote  = null;
    public mixed $customerLookup        = null;
    public ?PurchaseRate $purchaseRate  = null;

    protected function getResourceUuid($tables, array $where)
    {
        $this->uuidLookups[] = [$tables, $where];

        if ($tables === 'service_quotes') {
            return 'service-quote-uuid';
        }

        if ($tables === 'orders') {
            return 'order-uuid';
        }

        return $this->customerLookup;
    }

    protected function findOrderByUuid(?string $uuid): ?Order
    {
        $this->order?->setAttribute('lookup_uuid', $uuid);

        return $this->order;
    }

    protected function findServiceQuoteForPurchaseRate(?string $uuid, ?string $publicId): ?ServiceQuote
    {
        $this->serviceQuoteLookups[] = [$uuid, $publicId];

        return $this->serviceQuote;
    }

    protected function createOrderFromServiceQuote(?ServiceQuote $serviceQuote, CreatePurchaseRateRequest $request): ?Order
    {
        $this->createdOrder?->setAttribute('service_quote_uuid', $serviceQuote?->uuid);
        $this->createdOrder?->setAttribute('service_quote_public_id', $request->input('service_quote'));

        return $this->createdOrder;
    }

    protected function createPurchaseRate(array $input): PurchaseRate
    {
        $this->createdPurchaseRates[] = $input;

        $this->purchaseRate ??= new PurchaseRate();
        $this->purchaseRate->setRawAttributes(array_merge([
            'uuid'      => 'purchase-rate-uuid',
            'public_id' => 'purchase_rate_public',
            'status'    => 'created',
        ], $input), true);

        return $this->purchaseRate;
    }

}

class FleetOpsApiPurchaseRateOrderFake extends Order
{
    public array $attachedPurchaseRates = [];

    public function attachPurchaseRate(PurchaseRate $purchaseRate): bool
    {
        $this->attachedPurchaseRates[] = $purchaseRate;

        return true;
    }
}

test('api purchase rate controller queries finds and reports missing purchase rates', function () {
    $purchaseRate = new PurchaseRate();
    $purchaseRate->setRawAttributes([
        'uuid'      => 'purchase-rate-uuid',
        'public_id' => 'purchase_rate_public',
        'status'    => 'created',
    ], true);

    $controller               = new FleetOpsApiPurchaseRateControllerProbe();
    $controller->purchaseRate = $purchaseRate;
    $controller->queryResults = [['uuid' => 'purchase-rate-a'], ['uuid' => 'purchase-rate-b']];

    expect($controller->query(new Request(['limit' => 2])))->toBe([
        'collection' => 'purchase-rate',
        'items'      => [['uuid' => 'purchase-rate-a'], ['uuid' => 'purchase-rate-b']],
    ])
        ->and($controller->find('purchase_rate_public', new Request()))->toBe([
            'resource'     => 'purchase-rate',
            'purchaseRate' => $purchaseRate,
        ])
        ->and($controller->findCalls)->toBe(['purchase_rate_public']);

    $controller                       = new FleetOpsApiPurchaseRateControllerProbe();
    $controller->purchaseRateNotFound = true;

    expect($controller->find('missing-purchase-rate', new Request()))->toBe([
        'json'   => ['error' => 'PurchaseRate resource not found.'],
        'status' => 404,
    ]);
});

test('api purchase rate controller creates rates from existing orders and customer lookups', function () {
    session(['company' => 'company-uuid']);

    $order = new FleetOpsApiPurchaseRateOrderFake();
    $order->setRawAttributes([
        'uuid'          => 'order-uuid',
        'payload_uuid'  => 'payload-uuid',
        'customer_uuid' => 'order-customer-uuid',
        'customer_type' => '\\Fleetbase\\FleetOps\\Models\\Contact',
        'company_uuid'  => 'order-company-uuid',
    ], true);

    $controller                 = new FleetOpsApiPurchaseRateCreateControllerProbe();
    $controller->order          = $order;
    $controller->customerLookup = [
        'uuid'  => 'lookup-customer-uuid',
        'table' => 'vendors',
    ];

    $response = $controller->create(CreatePurchaseRateRequest::create('/api/v1/purchase-rates', 'POST', [
        'service_quote' => 'service_quote_public',
        'order'         => 'order_public',
        'customer'      => 'vendor_public',
        'meta'          => ['source' => 'quote'],
    ]));

    expect($response)->toBeInstanceOf(PurchaseRateResource::class)
        ->and($response->resource)->toBe($controller->purchaseRate)
        ->and($controller->uuidLookups)->toBe([
            ['service_quotes', ['public_id' => 'service_quote_public', 'company_uuid' => 'company-uuid']],
            ['orders', ['public_id'                => 'order_public', 'company_uuid' => 'company-uuid']],
            [['contacts', 'vendors'], ['public_id' => 'vendor_public', 'company_uuid' => 'company-uuid']],
        ])
        ->and($order->lookup_uuid)->toBe('order-uuid')
        ->and($controller->createdPurchaseRates[0])->toMatchArray([
            'meta'               => ['source' => 'quote'],
            'company_uuid'       => 'order-company-uuid',
            'service_quote_uuid' => 'service-quote-uuid',
            'payload_uuid'       => 'payload-uuid',
            'customer_uuid'      => 'lookup-customer-uuid',
            'customer_type'      => '\\Fleetbase\\FleetOps\\Models\\Vendor',
        ])
        ->and($order->attachedPurchaseRates)->toBe([$controller->purchaseRate]);
});

test('api purchase rate controller creates orders from service quotes when requested', function () {
    session(['company' => 'company-uuid']);

    $serviceQuote = new ServiceQuote();
    $serviceQuote->setRawAttributes(['uuid' => 'service-quote-uuid', 'public_id' => 'service_quote_public'], true);

    $createdOrder = new FleetOpsApiPurchaseRateOrderFake();
    $createdOrder->setRawAttributes([
        'uuid'          => 'created-order-uuid',
        'payload_uuid'  => 'created-payload-uuid',
        'customer_uuid' => 'created-customer-uuid',
        'customer_type' => '\\Fleetbase\\FleetOps\\Models\\Contact',
        'company_uuid'  => null,
    ], true);

    $controller               = new FleetOpsApiPurchaseRateCreateControllerProbe();
    $controller->serviceQuote = $serviceQuote;
    $controller->createdOrder = $createdOrder;

    $response = $controller->create(CreatePurchaseRateRequest::create('/api/v1/purchase-rates', 'POST', [
        'service_quote' => 'service_quote_public',
        'create_order'  => true,
        'customer'      => 'ignored_customer_public',
        'meta'          => ['source' => 'created-order'],
    ]));

    expect($response)->toBeInstanceOf(PurchaseRateResource::class)
        ->and($response->resource)->toBe($controller->purchaseRate)
        ->and($controller->serviceQuoteLookups)->toBe([
            ['service-quote-uuid', 'service_quote_public'],
        ])
        ->and($createdOrder->service_quote_uuid)->toBe('service-quote-uuid')
        ->and($createdOrder->service_quote_public_id)->toBe('service_quote_public')
        ->and($controller->createdPurchaseRates[0])->toMatchArray([
            'meta'               => ['source' => 'created-order'],
            'company_uuid'       => 'company-uuid',
            'service_quote_uuid' => 'service-quote-uuid',
        ])
        ->and($createdOrder->attachedPurchaseRates)->toBe([$controller->purchaseRate]);
});

test('api purchase rate controller parent resource wrappers expose purchase rate responses', function () {
    $purchaseRate = new PurchaseRate();
    $purchaseRate->setRawAttributes([
        'uuid'      => 'purchase-rate-uuid',
        'public_id' => 'purchase_rate_public',
        'status'    => 'created',
    ], true);

    $controller = new FleetOpsApiPurchaseRateControllerParentProbe();

    $single     = $controller->callParentHelper('purchaseRateResource', $purchaseRate);
    $collection = $controller->callParentHelper('purchaseRateResourceCollection', collect([$purchaseRate]));
    $json       = $controller->callParentHelper('jsonResponse', ['error' => 'PurchaseRate resource not found.'], 404);

    expect($single)->toBeInstanceOf(PurchaseRateResource::class)
        ->and($single->resource)->toBe($purchaseRate)
        ->and($collection)->toBeInstanceOf(FleetbaseResourceCollection::class)
        ->and($collection->collection->first())->toBeInstanceOf(PurchaseRateResource::class)
        ->and($collection->collection->first()->resource)->toBe($purchaseRate)
        ->and($json->getStatusCode())->toBe(404)
        ->and($json->getData(true))->toBe(['error' => 'PurchaseRate resource not found.']);
});
