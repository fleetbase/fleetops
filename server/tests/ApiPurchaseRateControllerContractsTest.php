<?php

use Fleetbase\FleetOps\Http\Controllers\Api\v1\PurchaseRateController;
use Fleetbase\FleetOps\Models\PurchaseRate;
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
