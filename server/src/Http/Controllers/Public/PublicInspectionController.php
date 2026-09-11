<?php

namespace Fleetbase\FleetOps\Http\Controllers\Public;

use Fleetbase\FleetOps\Http\Resources\v1\InspectionForm as InspectionFormResource;
use Fleetbase\FleetOps\Http\Resources\v1\InspectionSubmission as InspectionSubmissionResource;
use Fleetbase\FleetOps\Models\InspectionForm;
use Fleetbase\FleetOps\Models\InspectionLink;
use Fleetbase\FleetOps\Support\InspectionFileStore;
use Fleetbase\FleetOps\Support\InspectionLinkPin;
use Fleetbase\FleetOps\Support\InspectionSubmitter;
use Fleetbase\Models\File;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

class PublicInspectionController extends Controller
{
    /** The largest photo a link will take, in kilobytes: a phone camera's JPEG, with room. */
    public const MAX_UPLOAD_KB = 10240;

    /** How many files one link may upload, so a leaked link cannot fill the bucket. */
    public const MAX_UPLOADS_PER_LINK = 40;

    /** What a locked link says, wherever the lock is found. */
    protected const LOCKED_MESSAGE = 'This link is locked after too many incorrect PINs. Ask whoever sent it for a new one.';

    /** The extension each accepted image type is stored under. */
    protected const EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
        'image/heic' => 'heic',
        'image/heif' => 'heif',
    ];

    public function show(Request $request, string $id): JsonResponse
    {
        [$form, $link] = $this->resolvePublishedFormAndLink($request, $id);

        $link->markViewed();

        return response()->json([
            'form'     => (new InspectionFormResource($form))->resolve(),
            'identity' => $this->identityPayload($link),
        ]);
    }

    public function submit(Request $request, string $id): JsonResponse
    {
        [$form, $link] = $this->resolvePublishedFormAndLink($request, $id);

        $validated = $request->validate(InspectionSubmitter::rules());

        // A link submits through its form's fields. The older flat checklist
        // stores photo URLs exactly as given, which on a public link would let
        // anyone put an arbitrary outside image on the record.
        if (!empty($validated['item_results']) && empty($validated['custom_field_values'])) {
            abort(response()->json(['error' => 'Submit this inspection through its form fields.'], 422));
        }

        // A single-use link is claimed before anything is written, inside the
        // same transaction as the submission: a second submit at the same
        // moment finds it already taken and writes nothing, and a submission
        // that fails to save gives the link back.
        $submission = DB::connection($link->getConnectionName())->transaction(function () use ($form, $link, $validated, $request) {
            if ($link->single_use && !$link->claim($request->ip(), (string) $request->userAgent())) {
                abort(response()->json(['error' => 'This inspection link has already been used.'], 409));
            }

            $submission = InspectionSubmitter::submit($form, $validated, [
                'vehicle_uuid'      => $link->vehicle_uuid,
                'driver_uuid'       => $link->driver_uuid,
                // The person the link is for: its assignee, or the driver's
                // account. What they typed as their name is kept beside it,
                // with whether a PIN stood between the link and the form.
                'submitted_by_uuid' => InspectionLinkPin::recipientFor($link)?->uuid,
                'source'            => 'public_link',
                'meta'              => [
                    'inspection_link_uuid' => $link->uuid,
                    'inspection_link_id'   => $link->public_id,
                    'completed_by_name'    => data_get($validated, 'signature.name'),
                    'pin_verified'         => $link->hasPin(),
                ],
            ]);

            if (!$link->single_use) {
                $link->markUsed($request->ip(), (string) $request->userAgent());
            }

            return $submission;
        });

        return response()->json([
            'message'    => 'Inspection submitted.',
            'submission' => (new InspectionSubmissionResource($submission->fresh(['form', 'vehicle', 'driver', 'itemResults', 'issue', 'workOrder'])))->resolve(),
        ]);
    }

    /**
     * Upload a photo or a signature through the link, before submitting.
     *
     * The console uploads through the platform's file endpoint, which needs a
     * session; a link has none, so this is the link's own. It checks the link
     * exactly as submitting does, takes images only, caps how many one link
     * may send, and tags each file with the link it came through — a
     * submission through a link may only reference files uploaded through
     * that same link.
     */
    public function upload(Request $request, string $id): JsonResponse
    {
        [, $link] = $this->resolvePublishedFormAndLink($request, $id);

        $request->validate([
            'file' => 'required|file|mimetypes:image/jpeg,image/png,image/webp,image/gif,image/heic,image/heif|max:' . static::MAX_UPLOAD_KB,
            'type' => 'nullable|in:' . InspectionFileStore::TYPE_PHOTO . ',' . InspectionFileStore::TYPE_SIGNATURE,
        ]);

        $already = File::query()
            ->where('company_uuid', $link->company_uuid)
            ->where('meta->inspection_link_uuid', $link->uuid)
            ->count();

        if ($already >= static::MAX_UPLOADS_PER_LINK) {
            abort(response()->json(['error' => 'This inspection link has reached its upload limit.'], 422));
        }

        $upload = $request->file('file');
        $disk   = config('filesystems.default');
        $bucket = config('filesystems.disks.' . $disk . '.bucket', config('filesystems.disks.s3.bucket'));
        // The stored name's extension comes from the type the server found in
        // the bytes, never from the name the device sent. Validation checks the
        // content, so an image that is also valid PHP, uploaded as `photo.php`,
        // would pass it — and stored under that extension on a web-served local
        // disk, it would be an invitation to run it.
        $extension = static::EXTENSIONS[$upload->getMimeType()] ?? 'jpg';
        $stored    = $upload->storeAs('inspections/links/' . $link->uuid, File::randomFileNameFromRequest($request, 'file', $extension), ['disk' => $disk]);

        if ($stored === false) {
            abort(response()->json(['error' => 'This photo could not be stored.'], 500));
        }

        // Built here rather than through File::createFromUpload(), which takes
        // the company and uploader from the session — and a link has none.
        // The type comes from the bytes the server received, not from the
        // name the device gave the file.
        $file = File::create([
            'company_uuid'      => $link->company_uuid,
            'uploader_uuid'     => InspectionLinkPin::recipientFor($link)?->uuid,
            'original_filename' => $upload->getClientOriginalName(),
            'content_type'      => $upload->getMimeType(),
            'disk'              => $disk,
            'path'              => $stored,
            'bucket'            => $bucket,
            'type'              => $request->input('type', InspectionFileStore::TYPE_PHOTO),
            'file_size'         => $upload->getSize(),
            'meta'              => ['inspection_link_uuid' => $link->uuid],
        ]);

        if ($file->company_uuid !== $link->company_uuid) {
            $file->forceFill(['company_uuid' => $link->company_uuid])->save();
        }

        return response()->json([
            'file' => [
                'id'           => $file->public_id,
                'url'          => $file->url,
                'filename'     => $file->original_filename,
                'content_type' => $file->content_type,
            ],
        ]);
    }

    protected function resolvePublishedFormAndLink(Request $request, string $id): array
    {
        $token = (string) $request->query('token', $request->input('token'));
        if (empty($token)) {
            abort(response()->json(['error' => 'Inspection token is required.'], 403));
        }

        $form = InspectionForm::where('uuid', $id)
            ->orWhere('public_id', $id)
            ->firstOrFail();

        if (!$form->is_published) {
            abort(response()->json(['error' => 'This inspection form is not available.'], 403));
        }

        $link = InspectionLink::where('inspection_form_uuid', $form->uuid)
            ->where('token_hash', InspectionLink::hashToken($token))
            ->first();

        if (!$link) {
            abort(response()->json(['error' => 'Inspection link is invalid.'], 403));
        }

        if ($link->status === 'locked') {
            abort(response()->json(['error' => static::LOCKED_MESSAGE, 'locked' => true], 403));
        }

        if (!$link->isUsable()) {
            abort(response()->json(['error' => 'Inspection link is expired, revoked, or already used.'], 403));
        }

        // A link with a PIN answers nothing, not even the form's name, until
        // the PIN is given. Wrong guesses count against the link and lock it.
        // Every refusal here is a 403, so the page handles them all one way.
        // The page sends it as a header, so it stays out of URLs and the
        // access logs that record them; a `pin` field is accepted as well.
        switch ($link->verifyPin($request->header('X-Inspection-Pin') ?: $request->input('pin'))) {
            case 'missing':
                abort(response()->json(['error' => 'Enter the PIN you were given with this link.', 'pin_required' => true], 403));
                // no break
            case 'wrong':
                abort(response()->json(['error' => 'That PIN is not right.', 'pin_required' => true, 'attempts_left' => $link->pinAttemptsLeft()], 403));
                // no break
            case 'locked':
                abort(response()->json(['error' => static::LOCKED_MESSAGE, 'locked' => true], 403));
        }

        return [$form, $link];
    }

    protected function identityPayload(InspectionLink $link): array
    {
        return [
            'assignee' => $link->assignee ? [
                'id'   => $link->assignee->public_id,
                'name' => $link->assignee->name,
            ] : null,
            // Name, not phone number: whoever holds the link needs to know who
            // it is for, not how to reach them.
            'driver' => $link->driver ? [
                'id'   => $link->driver->public_id,
                'name' => $link->driver->name,
            ] : null,
            'vehicle' => $link->vehicle ? [
                'id'           => $link->vehicle->public_id,
                'name'         => $link->vehicle->display_name ?? $link->vehicle->name,
                'plate_number' => $link->vehicle->plate_number,
            ] : null,
            'expires_at' => $link->expires_at,
        ];
    }
}
