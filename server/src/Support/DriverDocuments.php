<?php

namespace Fleetbase\FleetOps\Support;

use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Notifications\DriverDocumentReviewed;
use Fleetbase\Models\File;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The documents an organization asks of its drivers, and what each driver has sent.
 *
 * The organization names the documents in its onboarding settings ("Driving
 * licence", "Insurance"). A driver sends one file for each from the Navigator
 * app; someone at the organization approves it or sends it back with a reason.
 * A driver who signed themselves up is held at `pending` until every document is
 * approved, and released the moment the last one is.
 *
 * Each submission is a core `File` whose subject is the driver and whose `meta`
 * holds which document it is and where its review stands. Sending a document
 * again replaces the earlier file, so there is one current file per document.
 */
class DriverDocuments
{
    public const FILE_TYPE = 'driver_document';

    public const MISSING   = 'missing';
    public const SUBMITTED = 'submitted';
    public const APPROVED  = 'approved';
    public const REJECTED  = 'rejected';

    /** 12 MB of file: a phone photo or a scanned PDF, with room to spare. */
    public const MAX_BYTES = 12 * 1024 * 1024;

    public const CONTENT_TYPES = [
        'image/jpeg'      => 'jpg',
        'image/png'       => 'png',
        'image/heic'      => 'heic',
        'image/webp'      => 'webp',
        'application/pdf' => 'pdf',
    ];

    public static function key(string $name): string
    {
        return Str::slug($name) ?: md5($name);
    }

    /**
     * The documents the driver's organization asks for: `[['key' => …, 'name' => …], …]`.
     */
    public static function requirements(Driver $driver): array
    {
        $names = DriverOnboarding::documents(DriverOnboarding::settings($driver->company_uuid));
        $seen  = [];
        $out   = [];
        foreach ($names as $name) {
            $name = trim($name);
            $key  = static::key($name);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[]      = ['key' => $key, 'name' => $name];
        }

        return $out;
    }

    /**
     * The driver's current file for each document key.
     */
    public static function files(Driver $driver): Collection
    {
        return File::where('subject_uuid', $driver->uuid)
            ->where('type', static::FILE_TYPE)
            ->orderBy('created_at')
            ->get()
            // Later files win, so a re-sent document shows its newest file.
            ->keyBy(fn (File $file) => (string) data_get($file->meta, 'document_key'));
    }

    /**
     * Every document asked for, with where the driver's own copy stands.
     */
    public static function forDriver(Driver $driver): array
    {
        $files = static::files($driver);

        return array_map(function (array $requirement) use ($files) {
            /** @var File|null $file */
            $file = $files->get($requirement['key']);
            $meta = $file ? (array) $file->meta : [];

            return [
                'key'          => $requirement['key'],
                'name'         => $requirement['name'],
                'status'       => $file ? static::statusOf($file) : static::MISSING,
                'note'         => $file ? (data_get($meta, 'review_note') ?: null) : null,
                'submitted_at' => $file ? optional($file->created_at)->toISOString() : null,
                'reviewed_at'  => data_get($meta, 'reviewed_at'),
                'reviewed_by'  => data_get($meta, 'reviewed_by_name'),
                'file'         => $file ? [
                    'id'                => $file->public_id,
                    'url'               => $file->url,
                    'original_filename' => $file->original_filename,
                    'content_type'      => $file->content_type,
                    'file_size'         => $file->file_size,
                ] : null,
            ];
        }, static::requirements($driver));
    }

    /**
     * Counts, and one word for where the driver stands overall:
     * `none` (nothing asked), `needed` (something to send or send again),
     * `in_review` (all sent, waiting on the organization), `approved`.
     */
    public static function summary(Driver $driver, ?array $documents = null): array
    {
        $documents = $documents ?? static::forDriver($driver);
        $count     = fn (string $status) => count(array_filter($documents, fn ($d) => $d['status'] === $status));
        $required  = count($documents);
        $approved  = $count(static::APPROVED);
        $rejected  = $count(static::REJECTED);
        $missing   = $count(static::MISSING);

        $state = 'none';
        if ($required > 0) {
            $state = $approved === $required ? 'approved' : ($missing + $rejected > 0 ? 'needed' : 'in_review');
        }

        return [
            'state'     => $state,
            'required'  => $required,
            'approved'  => $approved,
            'submitted' => $count(static::SUBMITTED),
            'rejected'  => $rejected,
            'missing'   => $missing,
            'complete'  => $required === 0 || $approved === $required,
            // Whether the driver is being kept from work until their documents are approved.
            'held'      => $driver->status === 'pending' && $required > 0 && $approved !== $required,
        ];
    }

    /**
     * Whether a driver who signed themselves up is still waiting on their documents.
     */
    public static function holdsDriver(Driver $driver): bool
    {
        return $driver->status === 'pending' && static::summary($driver)['held'];
    }

    /**
     * Stores the driver's file for one document, replacing what they sent before.
     *
     * @throws \InvalidArgumentException when the document is not one the organization asks for, or the file is unusable
     */
    public static function submit(Driver $driver, string $document, string $base64, ?string $contentType = null, ?string $fileName = null): File
    {
        $requirement = static::findRequirement($driver, $document);
        if (!$requirement) {
            throw new \InvalidArgumentException('That is not a document your organization asks for.');
        }

        if (preg_match('/^data:(?<type>[^;]+);base64,(?<data>.+)$/s', $base64, $matches)) {
            $contentType = $contentType ?: $matches['type'];
            $base64      = $matches['data'];
        }
        $contentType = strtolower(trim((string) $contentType)) ?: 'image/jpeg';
        if (!isset(static::CONTENT_TYPES[$contentType])) {
            throw new \InvalidArgumentException('Send a photo or a PDF.');
        }

        $bytes = base64_decode($base64, true);
        if ($bytes === false || $bytes === '') {
            throw new \InvalidArgumentException('The file did not arrive in one piece. Try again.');
        }
        if (strlen($bytes) > static::MAX_BYTES) {
            throw new \InvalidArgumentException('That file is too large. Keep it under 12 MB.');
        }

        $extension = static::CONTENT_TYPES[$contentType];
        $existing  = static::files($driver)->get($requirement['key']);
        if ($existing && static::statusOf($existing) === static::APPROVED) {
            throw new \InvalidArgumentException('That document has already been approved.');
        }

        $stored = File::createFromBase64(
            $base64,
            $requirement['key'] . '-' . Str::lower(Str::random(10)) . '.' . $extension,
            'uploads/driver-documents/' . $driver->public_id,
            static::FILE_TYPE,
            $contentType,
            strlen($bytes)
        );
        if (!$stored instanceof File) {
            throw new \RuntimeException('The file could not be stored.');
        }

        $stored->forceFill([
            'company_uuid'      => $driver->company_uuid,
            'uploader_uuid'     => $driver->user_uuid,
            'subject_uuid'      => $driver->uuid,
            'subject_type'      => Driver::class,
            'original_filename' => $fileName ? Str::limit(basename($fileName), 180, '') : $stored->original_filename,
            'caption'           => $requirement['name'],
            'meta'              => [
                'document_key'  => $requirement['key'],
                'document_name' => $requirement['name'],
                'status'        => static::SUBMITTED,
            ],
        ])->save();

        // One current file per document: earlier copies are no longer the driver's answer.
        File::where('subject_uuid', $driver->uuid)
            ->where('type', static::FILE_TYPE)
            ->where('uuid', '!=', $stored->uuid)
            ->get()
            ->filter(fn (File $file) => data_get($file->meta, 'document_key') === $requirement['key'])
            ->each(fn (File $file) => $file->delete());

        return $stored;
    }

    /**
     * Approves a driver's document, or sends it back with a reason. Approving the
     * last one releases a driver who was held at `pending`.
     *
     * @throws \InvalidArgumentException
     */
    public static function review(Driver $driver, string $document, string $decision, ?string $note = null, ?\Fleetbase\Models\User $reviewer = null): array
    {
        if (!in_array($decision, [static::APPROVED, static::REJECTED], true)) {
            throw new \InvalidArgumentException('A document is either approved or rejected.');
        }

        $requirement = static::findRequirement($driver, $document);
        /** @var File|null $file */
        $file = $requirement ? static::files($driver)->get($requirement['key']) : null;
        if (!$file) {
            throw new \InvalidArgumentException('The driver has not sent that document.');
        }

        $note = trim((string) $note);
        if ($decision === static::REJECTED && $note === '') {
            throw new \InvalidArgumentException('Say what is wrong with the document so the driver can send it again.');
        }

        $file->meta = array_merge((array) $file->meta, [
            'status'           => $decision,
            'review_note'      => $decision === static::REJECTED ? Str::limit($note, 500, '') : null,
            'reviewed_at'      => now()->toISOString(),
            'reviewed_by'      => optional($reviewer)->uuid,
            'reviewed_by_name' => optional($reviewer)->name,
        ]);
        $file->save();

        $documents = static::forDriver($driver);
        $summary   = static::summary($driver, $documents);
        $released  = false;
        if ($driver->status === 'pending' && $summary['complete']) {
            $driver->status = 'available';
            $driver->meta   = array_merge((array) $driver->meta, ['documents_approved_at' => now()->toISOString()]);
            $driver->saveQuietly();
            $driver->invalidateApiCache();
            $released = true;
            $summary  = static::summary($driver, $documents);
        }

        // Telling the driver is a courtesy; a push that cannot be sent must not undo the review.
        try {
            $driver->notify(new DriverDocumentReviewed($driver, $requirement['name'], $decision, $decision === static::REJECTED ? $note : null, $released));
        } catch (\Throwable $e) {
            report($e);
        }

        return ['documents' => $documents, 'summary' => $summary, 'released' => $released];
    }

    /**
     * Matches what the caller sent (a key, or the document's name) to a requirement.
     */
    public static function findRequirement(Driver $driver, string $document): ?array
    {
        $document = trim($document);
        $key      = static::key($document);
        foreach (static::requirements($driver) as $requirement) {
            if ($requirement['key'] === $document || $requirement['key'] === $key) {
                return $requirement;
            }
        }

        return null;
    }

    public static function statusOf(File $file): string
    {
        $status = data_get($file->meta, 'status');

        return in_array($status, [static::APPROVED, static::REJECTED], true) ? $status : static::SUBMITTED;
    }
}
