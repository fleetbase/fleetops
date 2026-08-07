<?php

namespace Fleetbase\FleetOps\Http\Controllers\Internal\v1;

use Fleetbase\FleetOps\Http\Controllers\FleetOpsController;
use Fleetbase\FleetOps\Models\Entity;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Proof;
use Fleetbase\FleetOps\Models\Waypoint;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Models\File;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ProofController extends FleetOpsController
{
    /**
     * The resource to query.
     *
     * @var string
     */
    public $resource = 'proof';

    /**
     * Verify a QR code.
     *
     * @return void
     */
    public function verifyQrCode(string $publicId, Request $request)
    {
        $code = $request->input('code');
        $type = $request->input('type', strtok($publicId, '_'));

        $subject = $this->findQrSubject($type, $code);

        if (!$subject) {
            return $this->errorResponse('Unable to validate QR code data.');
        }

        // validate
        if ($publicId === $subject->public_id) {
            // create verification proof
            $proof = $this->createProof([
                'company_uuid' => session('company'),
                'subject_uuid' => $subject->uuid,
                'subject_type' => Utils::getModelClassName($subject),
                'remarks'      => 'Verified by QR Code Scan',
                'raw_data'     => $request->input('raw_data'),
                'data'         => $request->input('data'),
            ]);

            return $this->jsonResponse($this->proofSuccessPayload($proof));
        }

        return $this->errorResponse('Unable to validate QR code data.');
    }

    /**
     * Validate a QR code.
     *
     * @return void
     */
    public function captureSignature(string $publicId, Request $request)
    {
        $signature = $request->input('signature');
        $type      = $request->input('type', strtok($publicId, '_'));

        $subject = $this->findPublicSubject($type, $publicId);

        if (!$subject) {
            return $this->errorResponse('Unable to capture signature data.');
        }

        // create proof instance
        $proof = $this->createProof([
            'company_uuid' => session('company'),
            'subject_uuid' => $subject->uuid,
            'subject_type' => Utils::getModelClassName($subject),
            'remarks'      => 'Verified by Signature',
            'raw_data'     => $request->input('signature'),
        ]);

        // set the signature storage path
        $path = $this->signatureStoragePath($proof);

        // upload signature
        $this->storeSignature($path, base64_decode($signature), 'public');

        // create file record for upload
        $file = $this->createSignatureFile($path, $signature, $proof);

        // set file to proof
        $proof->file_uuid = $file->uuid;
        $proof->save();

        return $this->jsonResponse($this->proofSuccessPayload($proof));
    }

    protected function findQrSubject(string $type, ?string $code): mixed
    {
        return match ($type) {
            'order'    => Order::where('uuid', $code)->withoutGlobalScopes()->first(),
            'waypoint' => Waypoint::where('uuid', $code)->withoutGlobalScopes()->first(),
            'entity'   => Entity::where('uuid', $code)->withoutGlobalScopes()->first(),
            default    => null,
        };
    }

    protected function findPublicSubject(string $type, string $publicId): mixed
    {
        return match ($type) {
            'order'    => Order::where('public_id', $publicId)->withoutGlobalScopes()->first(),
            'waypoint' => Waypoint::where('public_id', $publicId)->withoutGlobalScopes()->first(),
            'entity'   => Entity::where('public_id', $publicId)->withoutGlobalScopes()->first(),
            default    => null,
        };
    }

    protected function createProof(array $attributes): Proof
    {
        return Proof::create($attributes);
    }

    protected function storeSignature(string $path, string|false $contents, string $visibility): void
    {
        Storage::disk('s3')->put($path, $contents, $visibility);
    }

    /**
     * Not exercisable in this harness: File::create() resolves a disk, an asset
     * url and app()->environment(), so it needs a real Illuminate\Foundation\Application
     * rather than the bare container the test bootstrap builds.
     */
    // @codeCoverageIgnoreStart
    protected function createSignatureFile(string $path, string $signature, Proof $proof): File
    {
        return File::create($this->signatureFileAttributes($path, $signature))->setKey($proof);
    }
    // @codeCoverageIgnoreEnd

    protected function jsonResponse(array $payload)
    {
        return response()->json($payload);
    }

    protected function errorResponse(string $message)
    {
        return response()->error($message);
    }

    protected function proofSuccessPayload(Proof $proof): array
    {
        return [
            'status' => 'success',
            'proof'  => $proof->public_id,
        ];
    }

    protected function signatureStoragePath(Proof $proof): string
    {
        return 'uploads/' . session('company') . '/signatures/' . $proof->public_id . '.png';
    }

    protected function signatureFileAttributes(string $path, string $signature): array
    {
        return [
            'company_uuid'      => session('company'),
            'uploader_uuid'     => session('user'),
            'name'              => basename($path),
            'original_filename' => basename($path),
            'extension'         => 'png',
            'content_type'      => 'image/png',
            'path'              => $path,
            'bucket'            => config('filesystems.disks.s3.bucket'),
            'type'              => 'signature',
            'size'              => Utils::getBase64ImageSize($signature),
        ];
    }
}
