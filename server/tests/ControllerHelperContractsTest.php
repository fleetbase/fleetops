<?php

use Fleetbase\FleetOps\Http\Controllers\Api\v1\CustomerController;
use Fleetbase\FleetOps\Http\Controllers\Api\v1\DriverController;
use Fleetbase\FleetOps\Http\Controllers\Internal\v1\OrderController as InternalOrderController;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

class FleetOpsInternalOrderControllerProbe extends InternalOrderController
{
    public function callHelper(string $method, mixed ...$arguments): mixed
    {
        $reflection = new ReflectionMethod(InternalOrderController::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($this, ...$arguments);
    }

    public function appendValues(mixed $value): array
    {
        $incoming = [];
        $this->appendProofPhotoInputs($incoming, $value);

        return $incoming;
    }
}

function fleetopsControllerStaticMethod(string $class, string $method): ReflectionMethod
{
    $reflection = new ReflectionMethod($class, $method);
    $reflection->setAccessible(true);

    return $reflection;
}

test('internal order controller collects proof photo upload inputs', function () {
    $controller = new FleetOpsInternalOrderControllerProbe();
    $tempPath   = tempnam(sys_get_temp_dir(), 'fleetops-proof-');
    file_put_contents($tempPath, 'uploaded image');

    $upload  = new UploadedFile($tempPath, 'proof.png', 'image/png', null, true);
    $request = new Request(
        [],
        [],
        [],
        [],
        [
            'photos' => [$upload],
        ]
    );

    $inputs = $controller->callHelper('collectProofPhotoInputs', $request);

    expect($inputs)->toHaveCount(1)
        ->and($inputs[0])->toBe($upload);
});

test('internal order controller fingerprints and validates proof photo payloads', function () {
    $controller = new FleetOpsInternalOrderControllerProbe();
    $payload    = base64_encode('same image');
    $other      = base64_encode('other image');
    $tempPath   = tempnam(sys_get_temp_dir(), 'fleetops-proof-');
    file_put_contents($tempPath, 'uploaded image');

    $upload = new UploadedFile($tempPath, 'proof.png', 'image/png', null, true);
    $nested = $controller->appendValues([
        'data:image/png;base64,' . $payload,
        [$payload, new stdClass(), null, $other],
    ]);
    $deduped = $controller->callHelper('dedupeProofPhotoInputs', $nested);

    expect($controller->callHelper('proofPhotoInputFingerprint', 'data:image/png;base64,' . $payload))
        ->toBe($controller->callHelper('proofPhotoInputFingerprint', $payload))
        ->and($nested)->toHaveCount(4)
        ->and($deduped)->toBe(['data:image/png;base64,' . $payload, $other])
        ->and($controller->callHelper('proofPhotoInputFingerprint', $upload))->toBeString()
        ->and($controller->callHelper('proofPhotoInputFingerprint', new stdClass()))->toBeNull()
        ->and($controller->callHelper('isValidBase64ProofPhoto', 'data:image/png;base64,' . $payload))->toBeTrue()
        ->and($controller->callHelper('isValidBase64ProofPhoto', 'not base64 !!'))->toBeFalse()
        ->and($controller->callHelper('isValidBase64ProofPhoto', $upload))->toBeFalse();
});

test('api controller phone helpers normalize explicit values', function () {
    $driverPhone   = fleetopsControllerStaticMethod(DriverController::class, 'phone');
    $customerPhone = fleetopsControllerStaticMethod(CustomerController::class, 'phone');

    expect($driverPhone->invoke(null, '15551234567'))->toBe('+15551234567')
        ->and($driverPhone->invoke(null, '+15551234567'))->toBe('+15551234567')
        ->and($customerPhone->invoke(null, ' 15551234567 '))->toBe('+15551234567')
        ->and($customerPhone->invoke(null, ''))->toBe('');
});
