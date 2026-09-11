<?php

namespace Fleetbase\FleetOps\Support;

use Fleetbase\FleetOps\Models\InspectionSubmission;
use Fleetbase\Models\File;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Turns what a driver sends for a photo or a signature into a platform file.
 *
 * The app is offline-first: it cannot upload a photo and then reference it,
 * so a photo arrives inside the submit body as base64. The platform's own
 * convention for a file held in a custom-field value is `file:<uuid>`, which
 * is what every value leaves here as — a URL, or a reference to a file that
 * already exists, is kept as it came.
 *
 * A reference is only ever kept for a file the submission may use: one that
 * belongs to the submission's own company. A submission through a public
 * link may go further than that only in one direction — it may reference
 * nothing but files uploaded through that same link, and it may not use an
 * outside URL as a photo at all.
 */
class InspectionFileStore
{
    public const TYPE_PHOTO     = 'inspection_photo';
    public const TYPE_SIGNATURE = 'inspection_signature';

    /**
     * Normalizes one file value. Returns the value unchanged when it is not a
     * string, is empty, or is something other than base64 or a file reference.
     */
    public static function normalize(mixed $value, InspectionSubmission $submission, string $type = self::TYPE_PHOTO, ?string $uploaderUuid = null): mixed
    {
        if (!is_string($value) || trim($value) === '') {
            return $value;
        }

        $value = trim($value);

        $linkUuid = static::linkUuidOf($submission);

        // A reference to a file that already exists — `file:<uuid>`, a bare
        // uuid, or the public id an upload answers with — is kept only for a
        // file the submission may use.
        if (Str::startsWith($value, 'file:') || Str::isUuid($value) || Str::startsWith($value, 'file_')) {
            return static::ownedReference($value, $submission, $linkUuid);
        }

        if (static::isUrl($value)) {
            if ($linkUuid) {
                throw ValidationException::withMessages(['photos' => 'A photo on an inspection link must be uploaded through the link.']);
            }

            return $value;
        }

        if (static::isBase64($value)) {
            $file = static::store($value, $submission, $type, $uploaderUuid);

            return $file ? 'file:' . $file->uuid : $value;
        }

        return $value;
    }

    /**
     * Stores a base64 payload — bare or as a data URI — as a file that belongs
     * to the submission, under `inspections/<submission uuid>/`.
     */
    public static function store(string $base64, InspectionSubmission $submission, string $type = self::TYPE_PHOTO, ?string $uploaderUuid = null): ?File
    {
        $contentType = null;
        $data        = $base64;

        if (preg_match('/^data:(?<content_type>[^;]+);base64,(?<data>.+)$/s', $base64, $matches)) {
            $contentType = $matches['content_type'];
            $data        = $matches['data'];
        }

        $data        = preg_replace('/\s+/', '', $data);
        $contentType = $contentType ?? static::sniffContentType($data);
        $extension   = static::extensionFor($contentType);
        $fileName    = Str::lower(Str::random(20)) . '.' . $extension;

        $file = File::createFromBase64($data, $fileName, 'inspections/' . $submission->uuid, $type, $contentType);
        if (!$file instanceof File) {
            return null;
        }

        $file->company_uuid  = $submission->company_uuid;
        $file->uploader_uuid = $uploaderUuid ?? $submission->submitted_by_uuid ?? $file->uploader_uuid;
        $file->setSubject($submission, $type);

        return $file;
    }

    /**
     * Files referenced by `file:<uuid>` values that were uploaded before the
     * submission existed — the console uploads a photo as soon as it is picked
     * — are claimed by the submission so its Photos panel lists them.
     *
     * @param string[] $uuids
     */
    public static function attachReferenced(InspectionSubmission $submission, array $uuids): int
    {
        $uuids = array_values(array_unique(array_filter($uuids, 'is_string')));
        if (empty($uuids)) {
            return 0;
        }

        // Only the submission's own company's files, and — for a submission
        // through a public link — only files uploaded through that link. This
        // claim was unscoped: any unattached file anywhere whose uuid appeared
        // in an answer was taken.
        $query = File::query()
            ->whereIn('uuid', $uuids)
            ->where('company_uuid', $submission->company_uuid)
            ->whereNull('subject_uuid');

        if ($linkUuid = static::linkUuidOf($submission)) {
            $query->where('meta->inspection_link_uuid', $linkUuid);
        }

        return $query->update(['subject_uuid' => $submission->uuid, 'subject_type' => $submission->getMorphClass()]);
    }

    /**
     * The inspection link a submission came through, or null when it came
     * through the console or the driver app.
     */
    protected static function linkUuidOf(InspectionSubmission $submission): ?string
    {
        if ($submission->source !== 'public_link') {
            return null;
        }

        $uuid = data_get($submission->meta, 'inspection_link_uuid');

        return is_string($uuid) && $uuid !== '' ? $uuid : null;
    }

    /**
     * A reference to an existing file, as `file:<uuid>`, kept only when the
     * file is the submission's to use.
     *
     * The lookup used to be unscoped, so a submission that named another
     * company's file — by uuid or public id — kept the reference, the file was
     * attached to it, and the submission resource then handed out that file's
     * URL. It is now scoped to the submission's company; a submission through
     * a public link must also have uploaded the file through that link, and is
     * refused outright rather than silently losing a photo it named.
     */
    protected static function ownedReference(string $value, InspectionSubmission $submission, ?string $linkUuid): ?string
    {
        $reference = Str::startsWith($value, 'file:') ? substr($value, 5) : $value;

        $file = File::query()
            ->where('company_uuid', $submission->company_uuid)
            ->where(Str::isUuid($reference) ? 'uuid' : 'public_id', $reference)
            ->first();

        if ($file && (!$linkUuid || data_get($file->meta, 'inspection_link_uuid') === $linkUuid)) {
            return 'file:' . $file->uuid;
        }

        if ($linkUuid) {
            throw ValidationException::withMessages(['photos' => 'A photo on an inspection link must be uploaded through the link.']);
        }

        return null;
    }

    /** The uuid a `file:<uuid>` value points at, or null for anything else. */
    public static function referencedUuid(mixed $value): ?string
    {
        if (!is_string($value) || !Str::startsWith($value, 'file:')) {
            return null;
        }

        $uuid = substr($value, 5);

        return Str::isUuid($uuid) ? $uuid : null;
    }

    /** Resolves a `file:<uuid>` value to its file, or null. */
    public static function resolve(mixed $value): ?File
    {
        $uuid = static::referencedUuid($value);

        return $uuid ? File::query()->where('uuid', $uuid)->first() : null;
    }

    /**
     * What the driver API answers for a file value: the file's id and where to
     * fetch it, or the value as it was stored when it is a URL.
     */
    public static function project(mixed $value): mixed
    {
        $file = static::resolve($value);
        if (!$file) {
            return $value;
        }

        return [
            'id'           => $file->public_id,
            'url'          => $file->url,
            'filename'     => $file->original_filename,
            'content_type' => $file->content_type,
        ];
    }

    public static function isUrl(string $value): bool
    {
        return (bool) preg_match('#^https?://#i', $value);
    }

    /** Bare base64 of at least a few bytes: long enough not to be a word, and only base64 characters. */
    public static function isBase64(string $value): bool
    {
        if (Str::startsWith($value, 'data:')) {
            return (bool) preg_match('/^data:[^;]+;base64,[A-Za-z0-9+\/=\s]+$/s', $value);
        }

        $stripped = preg_replace('/\s+/', '', $value);

        return strlen($stripped) >= 16
            && strlen($stripped) % 4 === 0
            && (bool) preg_match('/^[A-Za-z0-9+\/]+={0,2}$/', $stripped);
    }

    /** The image type from the decoded bytes' signature; PNG when it is not a known image. */
    public static function sniffContentType(string $base64): string
    {
        $bytes = (string) base64_decode(substr($base64, 0, 32), true);

        if (str_starts_with($bytes, "\xFF\xD8\xFF")) {
            return 'image/jpeg';
        }

        if (str_starts_with($bytes, 'GIF8')) {
            return 'image/gif';
        }

        if (str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP') {
            return 'image/webp';
        }

        if (str_starts_with($bytes, '%PDF')) {
            return 'application/pdf';
        }

        return 'image/png';
    }

    public static function extensionFor(string $contentType): string
    {
        return match (strtolower($contentType)) {
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/gif'               => 'gif',
            'image/webp'              => 'webp',
            'application/pdf'         => 'pdf',
            'image/svg+xml'           => 'svg',
            default                   => 'png',
        };
    }
}
