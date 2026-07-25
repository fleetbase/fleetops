<?php

use Fleetbase\FleetOps\Http\Controllers\Api\v1\SensorController;
use Fleetbase\FleetOps\Http\Controllers\Internal\v1\ProofController;
use Fleetbase\FleetOps\Models\Proof;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Illuminate\Http\Request;

class FleetOpsProofControllerProbe extends ProofController
{
    public function callHelper(string $method, mixed ...$arguments): mixed
    {
        $reflection = new ReflectionMethod(ProofController::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($this, ...$arguments);
    }
}

class FleetOpsSensorControllerProbe extends SensorController
{
    public function callInput(Request $request): array
    {
        return $this->input($request);
    }
}

test('internal proof controller builds success signature path and file metadata helpers', function () {
    session([
        'company' => 'company-uuid',
        'user'    => 'user-uuid',
    ]);
    app('config')->set('filesystems.disks.s3.bucket', 'fleetbase-test-bucket');

    $controller = new FleetOpsProofControllerProbe();
    $proof      = new Proof();
    $proof->setRawAttributes(['public_id' => 'proof-public'], true);
    $signature = base64_encode('signature-bytes');

    $path = $controller->callHelper('signatureStoragePath', $proof);

    expect($controller->callHelper('proofSuccessPayload', $proof))->toBe([
        'status' => 'success',
        'proof'  => 'proof-public',
    ])->and($path)->toBe('uploads/company-uuid/signatures/proof-public.png')
        ->and($controller->callHelper('signatureFileAttributes', $path, $signature))->toBe([
            'company_uuid'      => 'company-uuid',
            'uploader_uuid'     => 'user-uuid',
            'name'              => 'proof-public.png',
            'original_filename' => 'proof-public.png',
            'extension'         => 'png',
            'content_type'      => 'image/png',
            'path'              => 'uploads/company-uuid/signatures/proof-public.png',
            'bucket'            => 'fleetbase-test-bucket',
            'type'              => 'signature',
            'size'              => 15,
        ]);
});

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
