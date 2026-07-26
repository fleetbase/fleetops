<?php

use Fleetbase\FleetOps\Models\ServiceArea;
use Fleetbase\FleetOps\Models\Zone;
use Fleetbase\FleetOps\Support\GeofenceIntersectionService;
use Fleetbase\LaravelMysqlSpatial\Types\Point;

class FleetOpsUnitGeofenceIntersectionAlgorithmProbe extends GeofenceIntersectionService
{
    public array $zoneQueries        = [];
    public array $serviceAreaQueries = [];
    public array $stateQueries       = [];
    public array $geofenceLookups    = [];
    public $zones;
    public $serviceAreas;
    public $states;
    public array $geofences = [];

    public function detectForTest(Point $point): array
    {
        return $this->detectSubjectCrossings('company-uuid', $point, 'subject_states', 'subject_uuid', 'subject-uuid');
    }

    protected function insideZones(string $companyUuid, string $wkt)
    {
        $this->zoneQueries[] = [$companyUuid, $wkt];

        return collect($this->zones);
    }

    protected function insideServiceAreas(string $companyUuid, string $wkt)
    {
        $this->serviceAreaQueries[] = [$companyUuid, $wkt];

        return collect($this->serviceAreas);
    }

    protected function currentSubjectStates(string $stateTable, string $subjectColumn, string $subjectUuid)
    {
        $this->stateQueries[] = [$stateTable, $subjectColumn, $subjectUuid];

        return collect($this->states)->keyBy('geofence_uuid');
    }

    protected function findGeofence(string $geofenceType, string $geofenceUuid)
    {
        $this->geofenceLookups[] = [$geofenceType, $geofenceUuid];

        return $this->geofences[$geofenceType . ':' . $geofenceUuid] ?? null;
    }
}

function fleetopsUnitGeofenceZone(string $uuid): Zone
{
    $zone = new Zone();
    $zone->setRawAttributes([
        'uuid'         => $uuid,
        'company_uuid' => 'company-uuid',
    ], true);

    return $zone;
}

function fleetopsUnitGeofenceServiceArea(string $uuid): ServiceArea
{
    $serviceArea = new ServiceArea();
    $serviceArea->setRawAttributes([
        'uuid'         => $uuid,
        'company_uuid' => 'company-uuid',
    ], true);

    return $serviceArea;
}

test('geofence intersection algorithm detects entries exits and ignores unchanged inside states', function () {
    $enteredZone       = fleetopsUnitGeofenceZone('zone-entered');
    $unchangedZone     = fleetopsUnitGeofenceZone('zone-unchanged');
    $enteredArea       = fleetopsUnitGeofenceServiceArea('area-entered');
    $exitedArea        = fleetopsUnitGeofenceServiceArea('area-exited');
    $missingExitedZone = 'zone-missing';

    $service               = new FleetOpsUnitGeofenceIntersectionAlgorithmProbe();
    $service->zones        = [$enteredZone, $unchangedZone];
    $service->serviceAreas = [$enteredArea];
    $service->states       = [
        (object) ['geofence_uuid' => 'zone-unchanged', 'geofence_type' => 'zone', 'is_inside' => true],
        (object) ['geofence_uuid' => 'area-entered', 'geofence_type' => 'service_area', 'is_inside' => false],
        (object) ['geofence_uuid' => 'area-exited', 'geofence_type' => 'service_area', 'is_inside' => true],
        (object) ['geofence_uuid' => $missingExitedZone, 'geofence_type' => 'zone', 'is_inside' => true],
    ];
    $service->geofences = [
        'service_area:area-exited' => $exitedArea,
    ];

    $crossings = $service->detectForTest(new Point(1.25, 103.85));

    expect($service->zoneQueries)->toBe([['company-uuid', 'POINT(103.85 1.25)']])
        ->and($service->serviceAreaQueries)->toBe([['company-uuid', 'POINT(103.85 1.25)']])
        ->and($service->stateQueries)->toBe([['subject_states', 'subject_uuid', 'subject-uuid']])
        ->and($service->geofenceLookups)->toBe([
            ['service_area', 'area-exited'],
            ['zone', $missingExitedZone],
        ])
        ->and($crossings)->toHaveCount(3)
        ->and($crossings[0])->toMatchArray([
            'type'          => 'entered',
            'geofence_type' => 'zone',
        ])
        ->and($crossings[0]['geofence'])->toBe($enteredZone)
        ->and($crossings[1])->toMatchArray([
            'type'          => 'entered',
            'geofence_type' => 'service_area',
        ])
        ->and($crossings[1]['geofence'])->toBe($enteredArea)
        ->and($crossings[2])->toMatchArray([
            'type'          => 'exited',
            'geofence_type' => 'service_area',
        ])
        ->and($crossings[2]['geofence'])->toBe($exitedArea);
});
