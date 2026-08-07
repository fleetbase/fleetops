<?php

use Fleetbase\FleetOps\Http\Controllers\Api\v1\TrackingStatusController;
use Fleetbase\FleetOps\Http\Requests\CreateTrackingStatusRequest;
use Fleetbase\FleetOps\Http\Requests\UpdateTrackingStatusRequest;
use Fleetbase\FleetOps\Models\TrackingStatus;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;

class FleetOpsApiTrackingStatusControllerProbe extends TrackingStatusController
{
    public ?TrackingStatus $trackingStatus = null;
    public array $createdTrackingStatuses  = [];
    public array $trackingNumberLookups    = [];
    public array $orderLookups             = [];
    public array $preparedCodes            = [];
    public mixed $queryResults             = null;
    public bool $trackingStatusNotFound    = false;

    protected function prepareTrackingStatusCode(string $status): string
    {
        $this->preparedCodes[] = $status;

        return 'code-' . str_replace(' ', '-', strtolower($status));
    }

    protected function getTrackingNumberUuid(string $table, array $where): ?string
    {
        $this->trackingNumberLookups[] = [$table, $where];

        return 'tracking-number-uuid';
    }

    protected function getOrderTrackingNumberUuid(string $orderId): ?string
    {
        $this->orderLookups[] = $orderId;

        return 'order-tracking-number-uuid';
    }

    protected function createTrackingStatus(array $input): TrackingStatus
    {
        $this->createdTrackingStatuses[] = $input;

        $trackingStatus = new FleetOpsApiTrackingStatusFake();
        $trackingStatus->setRawAttributes(array_merge(['uuid' => 'created-tracking-status-uuid'], $input));

        return $trackingStatus;
    }

    protected function findTrackingStatus(string $id): TrackingStatus
    {
        if ($this->trackingStatusNotFound) {
            throw new ModelNotFoundException();
        }

        $this->trackingStatus?->setAttribute('lookup_id', $id);

        return $this->trackingStatus;
    }

    protected function queryTrackingStatuses(Request $request)
    {
        return $this->queryResults ?? [['uuid' => 'tracking-status-uuid']];
    }

    protected function trackingStatusResource(TrackingStatus $trackingStatus)
    {
        return ['resource' => 'tracking-status', 'trackingStatus' => $trackingStatus];
    }

    protected function trackingStatusResourceCollection($results)
    {
        return ['collection' => 'tracking-status', 'items' => $results];
    }

    protected function deletedTrackingStatusResource(TrackingStatus $trackingStatus)
    {
        return ['resource' => 'deleted-tracking-status', 'trackingStatus' => $trackingStatus];
    }

    protected function jsonResponse(array $payload, int $status)
    {
        return ['json' => $payload, 'status' => $status];
    }
}

class FleetOpsApiTrackingStatusFake extends TrackingStatus
{
    public array $updates       = [];
    public bool $deletedForTest = false;

    public function update(array $attributes = [], array $options = []): bool
    {
        $this->updates[] = $attributes;
        $this->setRawAttributes(array_merge($this->getAttributes(), $attributes));

        return true;
    }

    public function delete()
    {
        $this->deletedForTest = true;

        return true;
    }
}

function fleetopsCreateTrackingStatusRequest(array $input): CreateTrackingStatusRequest
{
    return CreateTrackingStatusRequest::create('/api/v1/tracking-statuses', 'POST', $input);
}

function fleetopsUpdateTrackingStatusRequest(array $input): UpdateTrackingStatusRequest
{
    return UpdateTrackingStatusRequest::create('/api/v1/tracking-statuses/status-public', 'PUT', $input);
}

test('api tracking status controller creates statuses with tracking number and generated point', function () {
    session(['company' => 'company-uuid']);

    $controller = new FleetOpsApiTrackingStatusControllerProbe();

    $response = $controller->create(fleetopsCreateTrackingStatusRequest([
        'tracking_number' => 'tracking-public',
        'status'          => 'Out For Delivery',
        'details'         => 'Driver departed hub',
        'city'            => 'Singapore',
        'country'         => 'SG',
        'latitude'        => '1.3001',
        'longitude'       => '103.8001',
        'ignored'         => 'not copied',
    ]));

    $input = $controller->createdTrackingStatuses[0];

    expect($response['resource'])->toBe('tracking-status')
        ->and($controller->preparedCodes)->toBe(['Out For Delivery'])
        ->and($controller->trackingNumberLookups)->toBe([
            [
                'tracking_numbers',
                [
                    'public_id'    => 'tracking-public',
                    'company_uuid' => 'company-uuid',
                ],
            ],
        ])
        ->and($input)->toMatchArray([
            'status'               => 'Out For Delivery',
            'details'              => 'Driver departed hub',
            'city'                 => 'Singapore',
            'country'              => 'SG',
            'code'                 => 'code-out-for-delivery',
            'company_uuid'         => 'company-uuid',
            'tracking_number_uuid' => 'tracking-number-uuid',
        ])
        ->and($input['location'])->toBeInstanceOf(Point::class)
        ->and($input)->not->toHaveKey('latitude')
        ->and($input)->not->toHaveKey('longitude')
        ->and($input)->not->toHaveKey('ignored');
});

test('api tracking status controller can assign tracking number from order input', function () {
    session(['company' => 'company-uuid']);

    $controller = new FleetOpsApiTrackingStatusControllerProbe();

    $controller->create(fleetopsCreateTrackingStatusRequest([
        'order'    => 'order-public',
        'status'   => 'Delivered',
        'details'  => 'Package received',
        'code'     => 'delivered',
        'location' => ['latitude' => 1.30, 'longitude' => 103.80],
    ]));

    expect($controller->orderLookups)->toBe(['order-public'])
        ->and($controller->preparedCodes)->toBe([])
        ->and($controller->createdTrackingStatuses[0])->toMatchArray([
            'tracking_number_uuid' => 'order-tracking-number-uuid',
            'code'                 => 'delivered',
        ]);
});

test('api tracking status controller updates finds queries and deletes statuses', function () {
    $trackingStatus = new FleetOpsApiTrackingStatusFake();
    $trackingStatus->setRawAttributes([
        'uuid'   => 'tracking-status-uuid',
        'status' => 'Pending',
        'code'   => null,
    ]);

    $controller                 = new FleetOpsApiTrackingStatusControllerProbe();
    $controller->trackingStatus = $trackingStatus;
    $controller->queryResults   = [['uuid' => 'status-a'], ['uuid' => 'status-b']];

    $updated = $controller->update('status-public', fleetopsUpdateTrackingStatusRequest([
        'status'    => 'Arrived',
        'details'   => 'Driver arrived',
        'latitude'  => 1.31,
        'longitude' => 103.81,
    ]));
    $query   = $controller->query(new Request(['limit' => 2]));
    $found   = $controller->find('status-public');
    $deleted = $controller->delete('status-public');

    expect($updated['resource'])->toBe('tracking-status')
        ->and($trackingStatus->updates[0])->toMatchArray([
            'status'  => 'Arrived',
            'details' => 'Driver arrived',
            'code'    => 'code-arrived',
        ])
        ->and($trackingStatus->updates[0]['location'])->toBeInstanceOf(Point::class)
        ->and($query)->toBe([
            'collection' => 'tracking-status',
            'items'      => [['uuid' => 'status-a'], ['uuid' => 'status-b']],
        ])
        ->and($found)->toBe(['resource' => 'tracking-status', 'trackingStatus' => $trackingStatus])
        ->and($deleted)->toBe(['resource' => 'deleted-tracking-status', 'trackingStatus' => $trackingStatus])
        ->and($trackingStatus->lookup_id)->toBe('status-public')
        ->and($trackingStatus->deletedForTest)->toBeTrue();
});

test('api tracking status controller preserves existing code and handles missing resources', function () {
    $trackingStatus = new FleetOpsApiTrackingStatusFake();
    $trackingStatus->setRawAttributes([
        'uuid'   => 'tracking-status-uuid',
        'status' => 'Pending',
        'code'   => 'pending',
    ]);

    $controller                 = new FleetOpsApiTrackingStatusControllerProbe();
    $controller->trackingStatus = $trackingStatus;

    $controller->update('status-public', fleetopsUpdateTrackingStatusRequest([
        'status'  => 'Still Pending',
        'details' => 'No change',
    ]));

    expect($trackingStatus->updates[0])->not->toHaveKey('code')
        ->and($controller->preparedCodes)->toBe([]);

    $controller                         = new FleetOpsApiTrackingStatusControllerProbe();
    $controller->trackingStatusNotFound = true;

    $expected = [
        'json'   => ['error' => 'TrackingStatus resource not found.'],
        'status' => 404,
    ];

    expect($controller->update('missing-status', fleetopsUpdateTrackingStatusRequest(['status' => 'Missing'])))->toBe($expected)
        ->and($controller->find('missing-status'))->toBe($expected)
        ->and($controller->delete('missing-status'))->toBe($expected);
});
