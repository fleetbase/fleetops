<?php

namespace Fleetbase\FleetOps\Support;

use Fleetbase\FleetOps\Models\InspectionSubmission;
use Fleetbase\Models\File;
use Illuminate\Support\Str;

/**
 * Turns what a driver sends for a photo or a signature into a platform file.
 *
 * The app is offline-first: it cannot upload a photo and then reference it,
 * so a photo arrives inside the submit body as base64. The platform's own
 * convention for a file held in a custom-field value is `file:<uuid>`, which
 * is what every value leaves here as — a URL, or a reference to a file that
 * already exists, is kept as it came.
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

        if (Str::startsWith($value, 'file:') || static::isUrl($value)) {
            return $value;
        }

        if (Str::isUuid($value)) {
            return 'file:' . $value;
        }

        if (Str::startsWith($value, 'file_')) {
            $file = File::query()->where('public_id', $value)->first();

            return $file ? 'file:' . $file->uuid : $value;
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

        return File::query()
            ->whereIn('uuid', $uuids)
            ->whereNull('subject_uuid')
            ->update(['subject_uuid' => $submission->uuid, 'subject_type' => $submission->getMorphClass()]);
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
