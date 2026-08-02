<?php

use Fleetbase\FleetOps\Http\Controllers\Api\v1\TrackingNumberController;
use Fleetbase\FleetOps\Http\Requests\CreateTrackingNumberRequest;
use Fleetbase\FleetOps\Http\Requests\DecodeTrackingNumberQR;
use Fleetbase\FleetOps\Models\TrackingNumber;
use Fleetbase\FleetOps\Support\Utils;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;

class FleetOpsApiTrackingNumberControllerProbe extends TrackingNumberController
{
    public ?TrackingNumber $trackingNumber = null;
    public ?Model $qrModel                 = null;
    public array $createdTrackingNumbers   = [];
    public array $ownerLookups             = [];
    public array $qrLookups                = [];
    public mixed $ownerResult              = null;
    public mixed $queryResults             = null;
    public bool $trackingNumberNotFound    = false;

    protected function getOwnerUuid(array $tables, array $where, array $options)
    {
        $this->ownerLookups[] = [$tables, $where, $options];

        return $this->ownerResult;
    }

    protected function createTrackingNumber(array $input): TrackingNumber
    {
        $this->createdTrackingNumbers[] = $input;

        $trackingNumber = new FleetOpsApiTrackingNumberFake();
        $trackingNumber->setRawAttributes(array_merge(['uuid' => 'created-tracking-number-uuid'], $input));

        return $trackingNumber;
    }

    protected function queryTrackingNumbers(Request $request)
    {
        return $this->queryResults ?? [['uuid' => 'tracking-number-uuid']];
    }

    protected function findTrackingNumber(string $id): TrackingNumber
    {
        if ($this->trackingNumberNotFound) {
            throw new ModelNotFoundException();
        }

        $this->trackingNumber?->setAttribute('lookup_id', $id);

        return $this->trackingNumber;
    }

    protected function trackingNumberResource(TrackingNumber $trackingNumber)
    {
        return ['resource' => 'tracking-number', 'trackingNumber' => $trackingNumber];
    }

    protected function trackingNumberResourceCollection($results)
    {
        return ['collection' => 'tracking-number', 'items' => $results];
    }

    protected function deletedTrackingNumberResource(TrackingNumber $trackingNumber)
    {
        return ['resource' => 'deleted-tracking-number', 'trackingNumber' => $trackingNumber];
    }

    protected function findQrModel(array $tables, array $where)
    {
        $this->qrLookups[] = [$tables, $where];

        return $this->qrModel;
    }

    protected function qrModelResource($model)
    {
        return ['resource' => 'qr-model', 'model' => $model];
    }

    protected function jsonResponse(array $payload, int $status)
    {
        return ['json' => $payload, 'status' => $status];
    }
}

class FleetOpsApiTrackingNumberFake extends TrackingNumber
{
    public bool $deletedForTest = false;

    public function delete()
    {
        $this->deletedForTest = true;

        return true;
    }
}

function fleetopsCreateTrackingNumberRequest(array $input): CreateTrackingNumberRequest
{
    return CreateTrackingNumberRequest::create('/api/v1/tracking-numbers', 'POST', $input);
}

function fleetopsDecodeTrackingNumberQrRequest(array $input): DecodeTrackingNumberQR
{
    return DecodeTrackingNumberQR::create('/api/v1/tracking-numbers/from-qr', 'POST', $input);
}

test('api tracking number controller creates tracking numbers with owner assignment', function () {
    session(['company' => 'company-uuid']);

    $controller              = new FleetOpsApiTrackingNumberControllerProbe();
    $controller->ownerResult = ['uuid' => 'owner-uuid', 'table' => 'orders'];

    $response = $controller->create(fleetopsCreateTrackingNumberRequest([
        'region'  => 'SG',
        'type'    => 'country',
        'status'  => 'active',
        'owner'   => 'order-public',
        'ignored' => 'not copied',
    ]));

    expect($response['resource'])->toBe('tracking-number')
        ->and($controller->ownerLookups)->toBe([
            [
                ['orders', 'entities'],
                [
                    'public_id'    => 'order-public',
                    'company_uuid' => 'company-uuid',
                ],
                ['with_table' => true],
            ],
        ])
        ->and($controller->createdTrackingNumbers[0])->toMatchArray([
            'region'       => 'SG',
            'type'         => 'country',
            'company_uuid' => 'company-uuid',
            'owner_uuid'   => 'owner-uuid',
            'owner_type'   => Utils::getModelClassName('orders'),
        ])
        ->and($controller->createdTrackingNumbers[0])->not->toHaveKey('status')
        ->and($controller->createdTrackingNumbers[0])->not->toHaveKey('ignored');
});

test('api tracking number controller skips owner assignment when lookup is not resolved', function () {
    session(['company' => 'company-uuid']);

    $controller              = new FleetOpsApiTrackingNumberControllerProbe();
    $controller->ownerResult = null;

    $response = $controller->create(fleetopsCreateTrackingNumberRequest([
        'region' => 'MN',
        'owner'  => 'missing-owner',
    ]));

    expect($response['resource'])->toBe('tracking-number')
        ->and($controller->createdTrackingNumbers[0])->toMatchArray([
            'region'       => 'MN',
            'company_uuid' => 'company-uuid',
        ])
        ->and($controller->createdTrackingNumbers[0])->not->toHaveKey('owner_uuid')
        ->and($controller->createdTrackingNumbers[0])->not->toHaveKey('owner_type');
});

test('api tracking number controller queries finds deletes and handles missing resources', function () {
    $trackingNumber = new FleetOpsApiTrackingNumberFake();
    $trackingNumber->setRawAttributes(['uuid' => 'tracking-number-uuid']);

    $controller                 = new FleetOpsApiTrackingNumberControllerProbe();
    $controller->trackingNumber = $trackingNumber;
    $controller->queryResults   = [['uuid' => 'tracking-a'], ['uuid' => 'tracking-b']];

    $query   = $controller->query(new Request(['limit' => 2]));
    $found   = $controller->find('tracking-public');
    $deleted = $controller->delete('tracking-public', new Request());

    expect($query)->toBe([
        'collection' => 'tracking-number',
        'items'      => [['uuid' => 'tracking-a'], ['uuid' => 'tracking-b']],
    ])
        ->and($found)->toBe(['resource' => 'tracking-number', 'trackingNumber' => $trackingNumber])
        ->and($deleted)->toBe(['resource' => 'deleted-tracking-number', 'trackingNumber' => $trackingNumber])
        ->and($trackingNumber->lookup_id)->toBe('tracking-public')
        ->and($trackingNumber->deletedForTest)->toBeTrue();

    $controller                         = new FleetOpsApiTrackingNumberControllerProbe();
    $controller->trackingNumberNotFound = true;

    $expected = [
        'json'   => ['error' => 'TrackingNumber resource not found.'],
        'status' => 404,
    ];

    expect($controller->find('missing-tracking-number'))->toBe($expected)
        ->and($controller->delete('missing-tracking-number', new Request()))->toBe($expected);
});

test('api tracking number controller decodes qr models and reports missing values', function () {
    $model = new class extends Model {
        protected $table = 'orders';
    };
    $model->setRawAttributes(['uuid' => 'order-uuid']);

    $controller          = new FleetOpsApiTrackingNumberControllerProbe();
    $controller->qrModel = $model;

    $response = $controller->fromQR(fleetopsDecodeTrackingNumberQrRequest(['code' => 'order-uuid']));

    expect($response)->toBe(['resource' => 'qr-model', 'model' => $model])
        ->and($controller->qrLookups)->toBe([
            [
                ['entities', 'orders'],
                ['uuid' => 'order-uuid'],
            ],
        ]);

    $controller = new FleetOpsApiTrackingNumberControllerProbe();

    expect($controller->fromQR(fleetopsDecodeTrackingNumberQrRequest(['code' => 'missing-uuid'])))->toBe([
        'json'   => ['error' => 'Unable to find QR code value'],
        'status' => 400,
    ]);
});
