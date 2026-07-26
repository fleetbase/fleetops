<?php

use Fleetbase\FleetOps\Http\Controllers\Api\v1\ServiceAreaController as ApiServiceAreaController;
use Fleetbase\FleetOps\Http\Controllers\Api\v1\ZoneController as ApiZoneController;
use Fleetbase\FleetOps\Http\Requests\CreateServiceAreaRequest;
use Fleetbase\FleetOps\Http\Requests\CreateZoneRequest;
use Fleetbase\FleetOps\Http\Requests\UpdateServiceAreaRequest;
use Fleetbase\FleetOps\Http\Requests\UpdateZoneRequest;
use Fleetbase\FleetOps\Models\ServiceArea;
use Fleetbase\FleetOps\Models\Zone;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;

class FleetOpsApiServiceAreaControllerProbe extends ApiServiceAreaController
{
    public ?ServiceArea $serviceArea = null;
    public array $created            = [];
    public array $uuidLookups        = [];
    public array $borders            = [];
    public array $locations          = [];
    public mixed $queryResults       = null;
    public bool $notFound            = false;
    public bool $createThrows        = false;

    protected function serviceAreaUuid(string $publicId, array $where): ?string
    {
        $this->uuidLookups[] = [$publicId, $where];

        return 'parent-uuid';
    }

    protected function pointFromLocation(mixed $location)
    {
        $this->locations[] = $location;

        return new Point(1.31, 103.81);
    }

    protected function createBorderFromPoint(Point $point, int $radius)
    {
        $this->borders[] = [$point->getLat(), $point->getLng(), $radius];

        return 'multipolygon-' . count($this->borders);
    }

    protected function createServiceArea(array $input): ServiceArea
    {
        if ($this->createThrows) {
            throw new RuntimeException('create failed');
        }

        $this->created[] = $input;

        $area = new FleetOpsApiServiceAreaFake();
        $area->setRawAttributes(['uuid' => 'created-area-uuid'], true);

        return $area;
    }

    protected function findServiceAreaRecord(string $id): ServiceArea
    {
        if ($this->notFound) {
            throw new ModelNotFoundException();
        }

        $this->serviceArea?->setAttribute('lookup_id', $id);

        return $this->serviceArea;
    }

    protected function queryServiceAreas(Request $request)
    {
        $this->queryResults = $this->queryResults ?? [['uuid' => 'area-uuid']];

        return $this->queryResults;
    }

    protected function serviceAreaResource(ServiceArea $serviceArea)
    {
        return ['resource' => 'service-area', 'service_area' => $serviceArea];
    }

    protected function serviceAreaResourceCollection($results)
    {
        return ['collection' => 'service-area', 'items' => $results];
    }

    protected function deletedServiceAreaResource(ServiceArea $serviceArea)
    {
        return ['resource' => 'deleted-service-area', 'service_area' => $serviceArea];
    }

    protected function jsonResponse(array $payload, int $status)
    {
        return ['json' => $payload, 'status' => $status];
    }

    protected function apiError(string $message)
    {
        return ['error' => $message];
    }

    protected function logServiceAreaCreateFailure(Throwable $e): void
    {
    }
}

class FleetOpsApiZoneControllerProbe extends ApiZoneController
{
    public ?Zone $zone         = null;
    public array $created      = [];
    public array $uuidLookups  = [];
    public array $borders      = [];
    public array $locations    = [];
    public mixed $queryResults = null;
    public bool $notFound      = false;

    protected function serviceAreaUuid(string $publicId, array $where): ?string
    {
        $this->uuidLookups[] = [$publicId, $where];

        return 'service-area-uuid';
    }

    protected function pointFromLocation(mixed $location)
    {
        $this->locations[] = $location;

        return new Point(1.32, 103.82);
    }

    protected function createBorderFromPoint(Point $point, int $radius)
    {
        $this->borders[] = [$point->getLat(), $point->getLng(), $radius];

        return 'polygon-' . count($this->borders);
    }

    protected function createZone(array $input): Zone
    {
        $this->created[] = $input;

        $zone = new FleetOpsApiZoneFake();
        $zone->setRawAttributes(['uuid' => 'created-zone-uuid'], true);

        return $zone;
    }

    protected function findZoneRecord(string $id): Zone
    {
        if ($this->notFound) {
            throw new ModelNotFoundException();
        }

        $this->zone?->setAttribute('lookup_id', $id);

        return $this->zone;
    }

    protected function queryZones(Request $request)
    {
        $this->queryResults = $this->queryResults ?? [['uuid' => 'zone-uuid']];

        return $this->queryResults;
    }

    protected function zoneResource(Zone $zone)
    {
        return ['resource' => 'zone', 'zone' => $zone];
    }

    protected function zoneResourceCollection($results)
    {
        return ['collection' => 'zone', 'items' => $results];
    }

    protected function deletedZoneResource(Zone $zone)
    {
        return ['resource' => 'deleted-zone', 'zone' => $zone];
    }

    protected function jsonResponse(array $payload, int $status)
    {
        return ['json' => $payload, 'status' => $status];
    }
}

class FleetOpsApiServiceAreaFake extends ServiceArea
{
    public array $updates       = [];
    public bool $deletedForTest = false;
    public int $refreshes       = 0;

    public function update(array $attributes = [], array $options = []): bool
    {
        $this->updates[] = $attributes;

        return true;
    }

    public function refresh()
    {
        $this->refreshes++;

        return $this;
    }

    public function delete()
    {
        $this->deletedForTest = true;

        return true;
    }
}

class FleetOpsApiZoneFake extends Zone
{
    public array $updates       = [];
    public bool $deletedForTest = false;
    public int $refreshes       = 0;

    public function update(array $attributes = [], array $options = []): bool
    {
        $this->updates[] = $attributes;

        return true;
    }

    public function refresh()
    {
        $this->refreshes++;

        return $this;
    }

    public function delete()
    {
        $this->deletedForTest = true;

        return true;
    }
}

function fleetopsCreateServiceAreaRequest(array $input): CreateServiceAreaRequest
{
    return CreateServiceAreaRequest::create('/api/v1/service-areas', 'POST', $input);
}

function fleetopsUpdateServiceAreaRequest(array $input): UpdateServiceAreaRequest
{
    return UpdateServiceAreaRequest::create('/api/v1/service-areas/area-public', 'PATCH', $input);
}

function fleetopsCreateZoneRequest(array $input): CreateZoneRequest
{
    return CreateZoneRequest::create('/api/v1/zones', 'POST', $input);
}

function fleetopsUpdateZoneRequest(array $input): UpdateZoneRequest
{
    return UpdateZoneRequest::create('/api/v1/zones/zone-public', 'PATCH', $input);
}

test('api service area controller creates service areas with parent and coordinate borders', function () {
    session(['company' => 'company-uuid']);

    $controller = new FleetOpsApiServiceAreaControllerProbe();
    $response   = $controller->create(fleetopsCreateServiceAreaRequest([
        'name'                    => 'Downtown',
        'type'                    => 'delivery',
        'status'                  => 'active',
        'country'                 => 'SG',
        'parent'                  => 'parent-public',
        'latitude'                => 1.3,
        'longitude'               => 103.8,
        'radius'                  => 750,
        'color'                   => '#111111',
        'stroke_color'            => '#222222',
        'trigger_on_entry'        => true,
        'trigger_on_exit'         => false,
        'dwell_threshold_minutes' => 10,
        'speed_limit_kmh'         => 40,
        'ignored'                 => 'not copied',
    ]));

    expect($response['resource'])->toBe('service-area')
        ->and($controller->created[0])->toMatchArray([
            'name'                    => 'Downtown',
            'type'                    => 'delivery',
            'status'                  => 'active',
            'country'                 => 'SG',
            'company_uuid'            => 'company-uuid',
            'parent_uuid'             => 'parent-uuid',
            'border'                  => 'multipolygon-1',
            'color'                   => '#111111',
            'stroke_color'            => '#222222',
            'trigger_on_entry'        => true,
            'trigger_on_exit'         => false,
            'dwell_threshold_minutes' => 10,
            'speed_limit_kmh'         => 40,
        ])
        ->and($controller->created[0])->not->toHaveKey('ignored')
        ->and($controller->uuidLookups)->toBe([
            ['parent-public', ['public_id' => 'parent-public', 'company_uuid' => 'company-uuid']],
        ])
        ->and($controller->borders)->toBe([[1.3, 103.8, 750]])
        ->and($response['service_area']->refreshes)->toBe(1);
});

test('api service area controller updates queries finds deletes and handles failures', function () {
    session(['company' => 'company-uuid']);

    $area = new FleetOpsApiServiceAreaFake();
    $area->setRawAttributes(['uuid' => 'area-uuid', 'name' => 'Old'], true);

    $controller               = new FleetOpsApiServiceAreaControllerProbe();
    $controller->serviceArea  = $area;
    $controller->queryResults = [['uuid' => 'area-a'], ['uuid' => 'area-b']];

    $updated = $controller->update('area-public', fleetopsUpdateServiceAreaRequest([
        'name'     => 'Updated',
        'location' => ['latitude' => 1.31, 'longitude' => 103.81],
        'radius'   => '250',
        'parent'   => 'parent-public',
    ]));
    $query   = $controller->query(new Request(['limit' => 2]));
    $found   = $controller->find('area-public', new Request());
    $deleted = $controller->delete('area-public', new Request());

    expect($updated['resource'])->toBe('service-area')
        ->and($area->updates[0])->toMatchArray([
            'name'        => 'Updated',
            'parent_uuid' => 'parent-uuid',
            'border'      => 'multipolygon-1',
        ])
        ->and($controller->locations)->toBe([['latitude' => 1.31, 'longitude' => 103.81]])
        ->and($query)->toBe(['collection' => 'service-area', 'items' => [['uuid' => 'area-a'], ['uuid' => 'area-b']]])
        ->and($found)->toBe(['resource' => 'service-area', 'service_area' => $area])
        ->and($deleted)->toBe(['resource' => 'deleted-service-area', 'service_area' => $area])
        ->and($area->deletedForTest)->toBeTrue();

    $missing           = new FleetOpsApiServiceAreaControllerProbe();
    $missing->notFound = true;
    $expected          = ['json' => ['error' => 'ServiceArea resource not found.'], 'status' => 404];

    expect($missing->update('missing-area', fleetopsUpdateServiceAreaRequest(['name' => 'Missing'])))->toBe($expected)
        ->and($missing->find('missing-area', new Request()))->toBe($expected)
        ->and($missing->delete('missing-area', new Request()))->toBe($expected);

    $failed               = new FleetOpsApiServiceAreaControllerProbe();
    $failed->createThrows = true;

    expect($failed->create(fleetopsCreateServiceAreaRequest(['name' => 'Broken'])))->toBe([
        'error' => 'Failed to create service area.',
    ]);
});

test('api zone controller creates zones with service area and coordinate borders', function () {
    session(['company' => 'company-uuid']);

    $controller = new FleetOpsApiZoneControllerProbe();
    $response   = $controller->create(fleetopsCreateZoneRequest([
        'name'                    => 'Pickup Zone',
        'service_area'            => 'area-public',
        'latitude'                => 1.3,
        'longitude'               => 103.8,
        'radius'                  => 600,
        'status'                  => 'active',
        'description'             => 'Core pickup zone',
        'color'                   => '#333333',
        'stroke_color'            => '#444444',
        'trigger_on_entry'        => true,
        'trigger_on_exit'         => true,
        'dwell_threshold_minutes' => 20,
        'speed_limit_kmh'         => 35,
        'ignored'                 => 'not copied',
    ]));

    expect($response['resource'])->toBe('zone')
        ->and($controller->created[0])->toMatchArray([
            'name'                    => 'Pickup Zone',
            'company_uuid'            => 'company-uuid',
            'service_area_uuid'       => 'service-area-uuid',
            'border'                  => 'polygon-1',
            'status'                  => 'active',
            'description'             => 'Core pickup zone',
            'color'                   => '#333333',
            'stroke_color'            => '#444444',
            'trigger_on_entry'        => true,
            'trigger_on_exit'         => true,
            'dwell_threshold_minutes' => 20,
            'speed_limit_kmh'         => 35,
        ])
        ->and($controller->created[0])->not->toHaveKey('ignored')
        ->and($controller->uuidLookups)->toBe([
            ['area-public', ['public_id' => 'area-public', 'company_uuid' => 'company-uuid']],
        ])
        ->and($controller->borders)->toBe([[1.3, 103.8, 600]])
        ->and($response['zone']->refreshes)->toBe(1);
});

test('api zone controller updates queries finds deletes and handles missing records', function () {
    session(['company' => 'company-uuid']);

    $zone = new FleetOpsApiZoneFake();
    $zone->setRawAttributes(['uuid' => 'zone-uuid', 'name' => 'Old'], true);

    $controller               = new FleetOpsApiZoneControllerProbe();
    $controller->zone         = $zone;
    $controller->queryResults = [['uuid' => 'zone-a'], ['uuid' => 'zone-b']];

    $updated = $controller->update('zone-public', fleetopsUpdateZoneRequest([
        'name'         => 'Updated',
        'service_area' => 'area-public',
        'location'     => ['latitude' => 1.32, 'longitude' => 103.82],
        'radius'       => '300',
    ]));
    $query   = $controller->query(new Request(['limit' => 2]));
    $found   = $controller->find('zone-public');
    $deleted = $controller->delete('zone-public');

    expect($updated['resource'])->toBe('zone')
        ->and($zone->updates[0])->toMatchArray([
            'name'              => 'Updated',
            'service_area_uuid' => 'service-area-uuid',
            'border'            => 'polygon-1',
        ])
        ->and($controller->locations)->toBe([['latitude' => 1.32, 'longitude' => 103.82]])
        ->and($query)->toBe(['collection' => 'zone', 'items' => [['uuid' => 'zone-a'], ['uuid' => 'zone-b']]])
        ->and($found)->toBe(['resource' => 'zone', 'zone' => $zone])
        ->and($deleted)->toBe(['resource' => 'deleted-zone', 'zone' => $zone])
        ->and($zone->deletedForTest)->toBeTrue();

    $missing           = new FleetOpsApiZoneControllerProbe();
    $missing->notFound = true;
    $expected          = ['json' => ['error' => 'Zone resource not found.'], 'status' => 404];

    expect($missing->update('missing-zone', fleetopsUpdateZoneRequest(['name' => 'Missing'])))->toBe($expected)
        ->and($missing->find('missing-zone'))->toBe($expected)
        ->and($missing->delete('missing-zone'))->toBe($expected);
});
