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

test('real geofence queries filter triggered borders and resolve records', function () {
    $pdo = new PDO('sqlite::memory:');
    $pdo->sqliteCreateFunction('MBRContains', fn ($border, $point) => 1);
    $pdo->sqliteCreateFunction('ST_Contains', fn ($border, $point) => 1);
    $pdo->sqliteCreateFunction('ST_GeomFromText', fn ($wkt, $srid = 0, $axisOrder = null) => $wkt);
    $connection = new Illuminate\Database\SQLiteConnection($pdo);
    $resolver   = new Illuminate\Database\ConnectionResolver(['default' => $connection, 'mysql' => $connection]);
    $resolver->setDefaultConnection('mysql');
    Illuminate\Database\Eloquent\Model::setConnectionResolver($resolver);
    app()->instance('db', new class($connection) {
        public function __construct(public Illuminate\Database\SQLiteConnection $c)
        {
        }

        public function connection($name = null): Illuminate\Database\SQLiteConnection
        {
            return $this->c;
        }

        public function __call($method, $arguments)
        {
            return $this->c->{$method}(...$arguments);
        }
    });
    Illuminate\Support\Facades\DB::clearResolvedInstance('db');

    $schema = $connection->getSchemaBuilder();
    foreach (['zones' => ['uuid', 'company_uuid', 'name', 'border', 'trigger_on_entry', 'trigger_on_exit', 'dwell_threshold_minutes', 'service_area_uuid', 'public_id'], 'service_areas' => ['uuid', 'company_uuid', 'name', 'border', 'trigger_on_entry', 'trigger_on_exit', 'dwell_threshold_minutes', 'public_id']] as $table => $columns) {
        $schema->create($table, function ($blueprint) use ($columns) {
            $blueprint->increments('id');
            foreach ($columns as $column) {
                $blueprint->string($column)->nullable();
            }
            $blueprint->timestamps();
            $blueprint->timestamp('deleted_at')->nullable();
        });
    }
    $schema->create('driver_geofence_states', function ($blueprint) {
        $blueprint->increments('id');
        foreach (['driver_uuid', 'geofence_uuid', 'geofence_type', 'entered_at', 'exited_at'] as $column) {
            $blueprint->string($column)->nullable();
        }
        $blueprint->integer('is_inside')->nullable();
        $blueprint->timestamps();
    });

    $connection->table('zones')->insert([
        ['uuid' => 'zone-real-1', 'company_uuid' => 'company-1', 'name' => 'Triggered', 'border' => 'POLYGON', 'trigger_on_entry' => 1],
        ['uuid' => 'zone-real-2', 'company_uuid' => 'company-1', 'name' => 'Untriggered', 'border' => null, 'trigger_on_entry' => null],
    ]);
    $connection->table('service_areas')->insert(['uuid' => 'sa-real-1', 'company_uuid' => 'company-1', 'name' => 'Area', 'border' => 'POLYGON', 'trigger_on_exit' => 1]);
    $connection->table('driver_geofence_states')->insert(['driver_uuid' => 'driver-1', 'geofence_uuid' => 'zone-real-1', 'geofence_type' => 'zone', 'is_inside' => 1]);

    $service = new GeofenceIntersectionService();
    $invoke  = function (string $name, ...$arguments) use ($service) {
        $reflection = new ReflectionMethod(GeofenceIntersectionService::class, $name);
        $reflection->setAccessible(true);

        return $reflection->invoke($service, ...$arguments);
    };

    expect($invoke('insideZones', 'company-1', 'POINT(103.8 1.3)'))->toHaveCount(1)
        ->and($invoke('insideServiceAreas', 'company-1', 'POINT(103.8 1.3)'))->toHaveCount(1)
        ->and($invoke('currentSubjectStates', 'driver_geofence_states', 'driver_uuid', 'driver-1')->has('zone-real-1'))->toBeTrue()
        ->and($invoke('findGeofence', 'zone', 'zone-real-1')?->uuid)->toBe('zone-real-1')
        ->and($invoke('findGeofence', 'service_area', 'sa-real-1')?->uuid)->toBe('sa-real-1');
});
