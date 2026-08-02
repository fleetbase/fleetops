<?php

use Fleetbase\FleetOps\Http\Controllers\Api\v1\SensorController;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Illuminate\Http\Request;

class FleetOpsSensorControllerProbe extends SensorController
{
    public function callInput(Request $request): array
    {
        return $this->input($request);
    }
}

test('api sensor controller input maps positions and blank sensorable assignments', function () {
    $controller = new FleetOpsSensorControllerProbe();

    $withCoordinates = $controller->callInput(new Request([
        'name'                => 'Fuel Tank',
        'type'                => 'fuel',
        'latitude'            => '1.3521',
        'longitude'           => '103.8198',
        'threshold_inclusive' => true,
        'sensorable'          => '',
        'sensorable_type'     => 'fleet-ops:vehicle',
    ]));

    expect($withCoordinates['name'])->toBe('Fuel Tank')
        ->and($withCoordinates['type'])->toBe('fuel')
        ->and($withCoordinates['threshold_inclusive'])->toBeTrue()
        ->and($withCoordinates['last_position'])->toBeInstanceOf(Point::class)
        ->and($withCoordinates['last_position']->getLat())->toBe(1.3521)
        ->and($withCoordinates['last_position']->getLng())->toBe(103.8198)
        ->and($withCoordinates['sensorable_type'])->toBeNull()
        ->and($withCoordinates['sensorable_uuid'])->toBeNull();

    $withPositionArray = $controller->callInput(new Request([
        'last_position' => [103.82, 1.36],
    ]));

    expect($withPositionArray['last_position'])->toBeInstanceOf(Point::class)
        ->and($withPositionArray['last_position']->getLat())->toBe(103.82)
        ->and($withPositionArray['last_position']->getLng())->toBe(1.36);
});
