<?php

use Fleetbase\FleetOps\Http\Controllers\Internal\v1\ProofController;
use Fleetbase\FleetOps\Models\Proof;
use Illuminate\Http\Request;

class FleetOpsProofControllerProbe extends ProofController
{
    public array $qrSubjects       = [];
    public array $publicSubjects   = [];
    public array $createdProofs    = [];
    public array $storedSignatures = [];
    public array $createdFiles     = [];

    public function callHelper(string $method, mixed ...$arguments): mixed
    {
        $reflection = new ReflectionMethod(ProofController::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($this, ...$arguments);
    }

    protected function findQrSubject(string $type, ?string $code): mixed
    {
        return $this->qrSubjects[$type . ':' . $code] ?? null;
    }

    protected function findPublicSubject(string $type, string $publicId): mixed
    {
        return $this->publicSubjects[$type . ':' . $publicId] ?? null;
    }

    protected function createProof(array $attributes): Proof
    {
        $this->createdProofs[] = $attributes;

        $proof = new FleetOpsProofControllerProofFake();
        $proof->setRawAttributes([
            'uuid'      => 'proof-uuid-' . count($this->createdProofs),
            'public_id' => 'proof-public-' . count($this->createdProofs),
        ], true);

        return $proof;
    }

    protected function storeSignature(string $path, string|false $contents, string $visibility): void
    {
        $this->storedSignatures[] = [$path, $contents, $visibility];
    }

    protected function createSignatureFile(string $path, string $signature, Proof $proof): Fleetbase\Models\File
    {
        $this->createdFiles[] = [$path, $signature, $proof->public_id];

        $file = new FleetOpsProofControllerFileFake();
        $file->setRawAttributes(['uuid' => 'file-uuid'], true);

        return $file;
    }

    protected function jsonResponse(array $payload): array
    {
        return ['json' => $payload];
    }

    protected function errorResponse(string $message): array
    {
        return ['error' => $message];
    }
}

class FleetOpsProofControllerProofFake extends Proof
{
    public bool $saved = false;

    public function save(array $options = []): bool
    {
        $this->saved = true;

        return true;
    }
}

class FleetOpsProofControllerFileFake extends Fleetbase\Models\File
{
    public array $keys = [];

    public function setKey($model, $type = null): Fleetbase\Models\File
    {
        $this->keys[] = $model->public_id;

        return $this;
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

test('internal proof controller verifies qr code subjects and handles validation failures', function () {
    session(['company' => 'company-uuid']);

    $controller = new FleetOpsProofControllerProbe();
    $subject    = new Fleetbase\FleetOps\Models\Order();
    $subject->setRawAttributes([
        'uuid'      => 'order-uuid',
        'public_id' => 'order_public',
    ], true);

    $controller->qrSubjects['order:order-uuid'] = $subject;

    $response = $controller->verifyQrCode('order_public', new Request([
        'type'     => 'order',
        'code'     => 'order-uuid',
        'raw_data' => 'raw scan',
        'data'     => ['lat' => 1],
    ]));

    expect($response)->toBe([
        'json' => [
            'status' => 'success',
            'proof'  => 'proof-public-1',
        ],
    ])->and($controller->createdProofs)->toHaveCount(1)
        ->and($controller->createdProofs[0])->toMatchArray([
            'company_uuid' => 'company-uuid',
            'subject_uuid' => 'order-uuid',
            'subject_type' => '\Fleetbase\FleetOps\Models\Order',
            'remarks'      => 'Verified by QR Code Scan',
            'raw_data'     => 'raw scan',
            'data'         => ['lat' => 1],
        ]);

    expect($controller->verifyQrCode('order_public', new Request([
        'type' => 'order',
        'code' => 'missing',
    ])))->toBe(['error' => 'Unable to validate QR code data.']);

    expect($controller->verifyQrCode('other_public', new Request([
        'type' => 'order',
        'code' => 'order-uuid',
    ])))->toBe(['error' => 'Unable to validate QR code data.']);
});

test('internal proof controller captures signatures and assigns uploaded file', function () {
    session([
        'company' => 'company-uuid',
        'user'    => 'user-uuid',
    ]);
    app('config')->set('filesystems.disks.s3.bucket', 'fleetbase-test-bucket');

    $controller = new FleetOpsProofControllerProbe();
    $subject    = new Fleetbase\FleetOps\Models\Waypoint();
    $subject->setRawAttributes([
        'uuid'      => 'waypoint-uuid',
        'public_id' => 'waypoint_public',
    ], true);

    $controller->publicSubjects['waypoint:waypoint_public'] = $subject;

    $signature = base64_encode('signature-bytes');
    $response  = $controller->captureSignature('waypoint_public', new Request([
        'type'      => 'waypoint',
        'signature' => $signature,
    ]));

    expect($response)->toBe([
        'json' => [
            'status' => 'success',
            'proof'  => 'proof-public-1',
        ],
    ])->and($controller->createdProofs)->toHaveCount(1)
        ->and($controller->createdProofs[0])->toMatchArray([
            'company_uuid' => 'company-uuid',
            'subject_uuid' => 'waypoint-uuid',
            'subject_type' => '\Fleetbase\FleetOps\Models\Waypoint',
            'remarks'      => 'Verified by Signature',
            'raw_data'     => $signature,
        ])
        ->and($controller->storedSignatures)->toBe([
            ['uploads/company-uuid/signatures/proof-public-1.png', 'signature-bytes', 'public'],
        ])
        ->and($controller->createdFiles)->toBe([
            ['uploads/company-uuid/signatures/proof-public-1.png', $signature, 'proof-public-1'],
        ]);

    expect($controller->captureSignature('missing_public', new Request([
        'type'      => 'waypoint',
        'signature' => $signature,
    ])))->toBe(['error' => 'Unable to capture signature data.']);
});
