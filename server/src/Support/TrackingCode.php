<?php

namespace Fleetbase\FleetOps\Support;

use Illuminate\Support\Str;

/**
 * The content printed into a tracking number's QR code and barcode, and the parser that
 * turns any scanned value back into identifiers.
 *
 * QR (v1) is a URL, so a phone camera opens the customer tracking page while scanners
 * and the navigator app read the parameters:
 *
 *     https://<console host>/~/track-order?order=<tracking number>&r=<owner public_id>&v=1
 *
 * Only the tracking number (printed on the label in plain text) and the owner's public
 * id go in: no uuids, no addresses, no names, no company. The owner's type is its
 * public_id prefix (order_, waypoint_, entity_, place_), so it is not repeated. Nothing
 * is signed: a label can be photocopied whatever it carries, so authorization belongs
 * to the endpoint that acts on a scan, not to the code.
 *
 * The linear barcode (Code 128) carries the bare tracking number, matching the text
 * printed beneath it, because warehouse handhelds type what they scan into a field.
 *
 * Codes already on parcels encode the owner's bare uuid, and parse() keeps accepting
 * them, alongside a bare tracking number or a bare public_id.
 */
class TrackingCode
{
    public const VERSION = 1;

    /**
     * Where a phone camera lands. The public tracking page is being rebuilt; when its
     * route changes, change these two and nothing else. parse() never depends on the
     * host or the path, since printed labels outlive both.
     *
     * `~/` is the console's public route prefix: it renders the page whether or not the
     * visitor is signed in. A bare `/track-order` falls into the console's own routes, so
     * a signed-in user landed on an empty console page instead of the tracking page.
     */
    public const PATH           = '~/track-order';
    public const TRACKING_PARAM = 'order';

    /**
     * The tracking page link for emails, notifications and the API: the company's own page
     * (`~/t/{slug}/{number}`) when its settings send links there, otherwise the shared page
     * (`~/track/{number}`). QR codes keep {@see PATH}, which labels already printed carry.
     */
    public static function pageUrl(string $trackingNumber, ?string $companyUuid = null): string
    {
        try {
            $slug = $companyUuid ? app(TrackingPage\TrackingResolver::class)->linkSlugFor($companyUuid) : null;
        } catch (\Throwable) {
            $slug = null;
        }

        $base = $slug ? '~/t/' . rawurlencode($slug) : '~/track';

        return Utils::consoleUrl($base . '/' . rawurlencode($trackingNumber), []);
    }

    /**
     * The QR content for a tracking number.
     */
    public static function qrContent(string $trackingNumber, ?string $ownerPublicId = null): string
    {
        $query = [static::TRACKING_PARAM => $trackingNumber];

        if (!empty($ownerPublicId)) {
            $query['r'] = $ownerPublicId;
        }

        $query['v'] = static::VERSION;

        return static::consoleBaseUrl() . '/' . static::PATH . '?' . http_build_query($query);
    }

    /**
     * The console origin, from the same config Utils::consoleUrl() reads. It does not
     * call consoleUrl() because that infers http/https from the app environment: a code
     * printed on a parcel should not change scheme with where it was generated, so it
     * is https unless the console is explicitly configured otherwise.
     */
    protected static function consoleBaseUrl(): string
    {
        $host = rtrim((string) config('fleetbase.console.host', ''), '/');

        if (Str::startsWith($host, 'http')) {
            return $host;
        }

        $subdomain = config('fleetbase.console.subdomain');
        $scheme    = config('fleetbase.console.secure', true) ? 'https://' : 'http://';

        return $scheme . ($subdomain ? $subdomain . '.' : '') . $host;
    }

    /**
     * The linear barcode content for a tracking number.
     */
    public static function barcodeContent(string $trackingNumber): string
    {
        return $trackingNumber;
    }

    /**
     * Break a scanned value into the identifiers it carries.
     *
     * - `uuid`: a legacy code, the owner's uuid.
     * - `tracking_number` and `public_id`: from a URL code.
     * - `reference`: a bare value that is either a tracking number (the barcode, or one
     *   typed by hand) or a public_id. Consumers try both.
     *
     * @return array{uuid: ?string, tracking_number: ?string, public_id: ?string, reference: ?string, version: ?int}
     */
    public static function parse(?string $code): array
    {
        $parsed = [
            'uuid'            => null,
            'tracking_number' => null,
            'public_id'       => null,
            'reference'       => null,
            'version'         => null,
        ];

        $code = trim((string) $code);
        if ($code === '') {
            return $parsed;
        }

        if (preg_match('#^https?://#i', $code)) {
            return array_merge($parsed, static::parseUrl($code));
        }

        if (Str::isUuid($code)) {
            $parsed['uuid'] = $code;

            return $parsed;
        }

        $parsed['reference'] = $code;

        return $parsed;
    }

    /**
     * Whether a scanned value identifies the given subject (an order, waypoint, entity or
     * place). The value comes straight from a request, so anything but a string, such as
     * a scanner library's whole result object, matches nothing.
     */
    public static function matches($code, $subject): bool
    {
        $subjectUuid = data_get($subject, 'uuid');
        if (!is_string($code) || !$subject || empty($subjectUuid)) {
            return false;
        }

        // Labels printed before v1 encode the subject's bare uuid; compare it exactly, as
        // scans always have been.
        if (trim($code) === $subjectUuid) {
            return true;
        }

        $parsed = static::parse($code);

        if ($parsed['uuid']) {
            return false;
        }

        $publicId       = data_get($subject, 'public_id');
        $trackingNumber = static::subjectTrackingNumber($subject);

        if ($parsed['reference']) {
            return static::sameTrackingNumber($parsed['reference'], $trackingNumber) || static::samePublicId($parsed['reference'], $publicId);
        }

        // A url code names its owner by public id, and that decides: an owner can hold more
        // than one tracking number (the API issues extra ones), so the number is compared
        // only when the code names no owner.
        if ($parsed['public_id']) {
            return static::samePublicId($parsed['public_id'], $publicId);
        }

        return $parsed['tracking_number'] !== null && static::sameTrackingNumber($parsed['tracking_number'], $trackingNumber);
    }

    /**
     * @return array{tracking_number: ?string, public_id: ?string, version: ?int}
     */
    protected static function parseUrl(string $url): array
    {
        $parts = parse_url($url);
        $query = [];
        parse_str(is_array($parts) ? ($parts['query'] ?? '') : '', $query);

        $trackingNumber = static::stringParam($query, static::TRACKING_PARAM) ?? static::stringParam($query, 'tn');

        // A future route may carry the tracking number in the path (/track/<number>). Only
        // a path of two or more segments can, and the tracking page's own path
        // (/track-order, /~/track-order) is never mistaken for one.
        if ($trackingNumber === null && is_array($parts) && isset($parts['path'])) {
            $segments = array_values(array_filter(explode('/', $parts['path']), fn ($segment) => $segment !== ''));
            if (count($segments) >= 2 && end($segments) !== basename(static::PATH)) {
                $trackingNumber = rawurldecode(end($segments));
            }
        }

        $version = static::stringParam($query, 'v');

        return [
            'tracking_number' => $trackingNumber,
            'public_id'       => static::stringParam($query, 'r'),
            'version'         => $version !== null && ctype_digit($version) ? (int) $version : null,
        ];
    }

    protected static function stringParam(array $query, string $key): ?string
    {
        $value = $query[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    protected static function subjectTrackingNumber($subject): ?string
    {
        $trackingNumber = data_get($subject, 'trackingNumber.tracking_number') ?? data_get($subject, 'tracking_number');

        return is_string($trackingNumber) && $trackingNumber !== '' ? $trackingNumber : null;
    }

    protected static function sameTrackingNumber(string $scanned, ?string $trackingNumber): bool
    {
        // The label prints the number upper-cased and handhelds may not preserve case.
        return $trackingNumber !== null && strcasecmp($scanned, $trackingNumber) === 0;
    }

    protected static function samePublicId(string $scanned, $publicId): bool
    {
        return is_string($publicId) && $publicId !== '' && $scanned === $publicId;
    }
}
