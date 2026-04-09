<?php

namespace Fleetbase\FleetOps\Integrations\USPS;

use Fleetbase\FleetOps\Models\IntegratedVendor;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Payload;
use Fleetbase\FleetOps\Models\ServiceQuote;
use Fleetbase\FleetOps\Models\ServiceQuoteItem;
use Fleetbase\Models\File;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * USPS direct bridge class (Mode B) against USPS Web Tools v3.
 *
 * Mirrors the UPS bridge pattern from Phase 2 Tasks 14 + 15:
 *  - private host / sandbox / auth credentials
 *  - injectable Guzzle HandlerStack + \ArrayAccess token cache
 *  - chainable setRequestId / setOptions / setIntegratedVendor
 *  - pure static helpers for request builders and response normalizers
 *  - thin impure instance wrappers composing pure helpers + OAuth +
 *    Eloquent writes
 *
 * ## Differences from UPS
 *  - Inline OAuth rather than a separate USPSOAuthClient class. USPS
 *    v3 uses the same client_credentials grant as UPS but the flow is
 *    simple enough to fold directly onto this class — getAccessToken()
 *    is a private method that caches under usps_oauth_token_{clientId}.
 *  - PDF-only label flow. USPS v3 does NOT issue ZPL labels; the
 *    normalizer enforces PDF regardless of what the response reports
 *    in labelMetadata.labelImageFormat.
 *  - No account number. USPS v3 rates and labels are zip-code and
 *    credential scoped — there is no shipper account number.
 *
 * Per the Phase 2 extraction rule, no user-specific or
 * environment-specific logic from ParcelPath v9 / EasyPostService is
 * carried over. Only generic USPS Web Tools v3 API semantics are
 * implemented. Edge cases that would normally come from v9 (Shippo
 * fallback, predefined USPS package types, international flag
 * handling) are intentionally NOT ported — they can be layered in as
 * follow-up PRs.
 */
class USPS
{
    /**
     * API host (production).
     */
    private string $host = 'https://api.usps.com';

    /**
     * API host (test environment).
     */
    private string $sandboxHost = 'https://apis-tem.usps.com';

    /**
     * Determines if instance is sandbox instance.
     */
    private bool $isSandbox;

    /**
     * USPS Web Tools v3 client id / secret.
     */
    private ?string $clientId;
    private ?string $clientSecret;

    /**
     * Request context.
     */
    private ?string $requestId = null;
    private array $options = [];
    private ?IntegratedVendor $integratedVendor = null;

    /**
     * HTTP Client Instance.
     */
    private Client $client;

    /**
     * Token cache. Tests inject an \ArrayObject; production wires this
     * through an \ArrayAccess adapter around Laravel's Cache facade
     * so the bridge runs under Pest without Laravel bootstrap.
     */
    private \ArrayAccess $cache;

    /**
     * TTL applied to the most recent successful token fetch.
     * Exposed for observability.
     */
    private int $lastCachedTtl = 0;

    private const TTL_SAFETY = 60;
    private const TTL_MIN    = 60;

    public function __construct(
        ?string $clientId = null,
        ?string $clientSecret = null,
        bool $sandbox = false,
        ?HandlerStack $handler = null,
        ?\ArrayAccess $cache = null
    ) {
        $this->clientId     = $clientId;
        $this->clientSecret = $clientSecret;
        $this->isSandbox    = $sandbox;

        $clientConfig = [];
        if ($handler !== null) {
            $clientConfig['handler'] = $handler;
        }
        $this->client = new Client($clientConfig);

        $this->cache = $cache ?? new \ArrayObject();
    }

    public function setRequestId(?string $requestId): self
    {
        $this->requestId = $requestId;
        return $this;
    }

    public function setOptions(?array $options = []): self
    {
        $this->options = array_merge($this->options, (array) $options);
        return $this;
    }

    public function getOptions(): array
    {
        return $this->options;
    }

    public function setIntegratedVendor(IntegratedVendor $integratedVendor): self
    {
        $this->integratedVendor = $integratedVendor;
        return $this;
    }

    public function isSandbox(): bool
    {
        return $this->isSandbox;
    }

    public function getLastCachedTtl(): int
    {
        return $this->lastCachedTtl;
    }

    public function baseUrl(): string
    {
        return $this->isSandbox ? $this->sandboxHost : $this->host;
    }

    /**
     * Inline OAuth: fetches (or reads from cache) a bearer token for
     * USPS v3. Cache key is scoped by clientId so multi-tenant broker
     * deployments don't collide on each other's tokens.
     *
     * Mirrors UPSOAuthClient's semantics exactly — same TTL safety
     * (expires_in - 60s, clamped to ≥60s), same HTTP Basic auth, same
     * grant_type=client_credentials body. Kept inline because there
     * is no shared auth state across USPS endpoints the way there is
     * for UPS's multi-product setup.
     */
    public function getAccessToken(): string
    {
        $key = 'usps_oauth_token_' . (string) $this->clientId;

        if (isset($this->cache[$key]) && $this->cache[$key] !== null && $this->cache[$key] !== '') {
            return (string) $this->cache[$key];
        }

        $response = $this->client->request('POST', $this->baseUrl() . '/oauth2/v3/token', [
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode(((string) $this->clientId) . ':' . ((string) $this->clientSecret)),
                'Content-Type'  => 'application/x-www-form-urlencoded',
                'Accept'        => 'application/json',
            ],
            'body'        => 'grant_type=client_credentials',
            'http_errors' => false,
        ]);

        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            throw new RuntimeException(sprintf(
                'USPS OAuth token request failed with HTTP %d',
                $response->getStatusCode()
            ));
        }

        $body = json_decode((string) $response->getBody(), true) ?? [];
        if (!isset($body['access_token']) || !is_string($body['access_token']) || $body['access_token'] === '') {
            throw new RuntimeException('USPS OAuth response missing access_token');
        }

        $token     = $body['access_token'];
        $expiresIn = (int) ($body['expires_in'] ?? 3600);
        $this->lastCachedTtl = max(self::TTL_MIN, $expiresIn - self::TTL_SAFETY);

        $this->cache[$key] = $token;

        return $token;
    }

    /**
     * Execute an authenticated USPS v3 request.
     */
    private function request(string $method, string $path, array $options = [])
    {
        $token = $this->getAccessToken();

        $options['headers'] = array_merge($options['headers'] ?? [], [
            'Authorization' => 'Bearer ' . $token,
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
        ]);

        if ($this->requestId !== null) {
            $options['headers']['X-Request-Id'] = $this->requestId;
        }

        $options['http_errors'] = false;

        $response = $this->client->request($method, $this->baseUrl() . $path, $options);
        return json_decode((string) $response->getBody(), true);
    }

    public function get(string $path, array $options = [])
    {
        return $this->request('GET', $path, $options);
    }

    public function post(string $path, array $options = [])
    {
        return $this->request('POST', $path, $options);
    }

    public function delete(string $path, array $options = [])
    {
        return $this->request('DELETE', $path, $options);
    }

    // ─────────────────────────────────────────────────────────────────────
    //  PURE HELPERS — request builders and response normalizers.
    //  Static, take plain arrays / duck-typed objects, unit-testable
    //  under Pest without booting Laravel.
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Convert a Place-like object to the USPS v3 Address shape.
     * USPS uses streetAddress / city / state / ZIPCode instead of
     * UPS's AddressLine/StateProvinceCode/PostalCode naming.
     */
    public static function placeToUspsAddress(object $place): array
    {
        return [
            'streetAddress' => (string) ($place->street1 ?? ''),
            'city'          => (string) ($place->city ?? ''),
            'state'         => (string) ($place->province ?? ''),
            'ZIPCode'       => (string) ($place->postal_code ?? ''),
        ];
    }

    /**
     * Convert an Entity-like parcel to the USPS v3 parcel shape.
     * Dimensions are inches, weight is pounds. Unlike UPS we do not
     * compute dimensional weight here — USPS v3 accepts raw dimensions
     * + actual weight and computes the billable weight server-side.
     */
    public static function entityToUspsParcel(object $entity): array
    {
        return [
            'length' => (float) ($entity->length ?? 0),
            'width'  => (float) ($entity->width ?? 0),
            'height' => (float) ($entity->height ?? 0),
            'weight' => (float) ($entity->weight ?? 0),
        ];
    }

    /**
     * Build the POST /prices/v3/base-rates/search request body.
     * mailClass is optional; omitting it searches all services.
     */
    public static function buildRatesRequest(
        array $shipFrom,
        array $shipTo,
        array $parcel,
        ?string $mailClass = null
    ): array {
        $body = [
            'originZIPCode'      => (string) ($shipFrom['ZIPCode'] ?? ''),
            'destinationZIPCode' => (string) ($shipTo['ZIPCode'] ?? ''),
            'weight'             => $parcel['weight'],
            'length'             => $parcel['length'],
            'width'              => $parcel['width'],
            'height'             => $parcel['height'],
        ];

        if ($mailClass !== null && $mailClass !== '') {
            $body['mailClass'] = $mailClass;
        }

        return $body;
    }

    /**
     * Normalize a USPS /prices/v3/base-rates/search response into an
     * array of rows ready for ServiceQuote::create. Converts dollars
     * to integer cents, applies flat or percent markup, resolves the
     * service description via USPSServiceType, and silently skips
     * rows that don't carry a price.
     */
    public static function normalizeRatesResponse(
        array $response,
        string $markupType = 'flat',
        int $markupValue = 0
    ): array {
        $rates = $response['rates'] ?? [];
        if (!is_array($rates)) {
            return [];
        }

        $rows = [];
        foreach ($rates as $rate) {
            if (!isset($rate['price'])) {
                continue;
            }

            $carrierAmount = (int) round(((float) $rate['price']) * 100);
            $markup = $markupType === 'percent'
                ? (int) round($carrierAmount * $markupValue / 100)
                : $markupValue;
            $sellAmount = $carrierAmount + $markup;

            $mailClass = (string) ($rate['mailClass'] ?? '');
            $serviceType = USPSServiceType::find(function ($t) use ($mailClass) {
                return $t->mail_class === $mailClass;
            });
            $description = $serviceType !== null
                ? (string) $serviceType->description
                : 'USPS ' . $mailClass;

            $rows[] = [
                'amount'   => $sellAmount,
                'currency' => 'USD',
                'service'  => $description,
                'meta'     => [
                    'carrier'        => 'USPS',
                    'mail_class'     => $mailClass,
                    'carrier_amount' => $carrierAmount,
                    'markup_amount'  => $markup,
                    'markup_type'    => $markupType,
                ],
            ];
        }

        return $rows;
    }

    /**
     * Build the POST /labels/v3/label request body. USPS v3 accepts
     * exactly one parcel per label, so this takes a single parcel
     * array (not a list). Image type is always PDF — USPS does NOT
     * issue ZPL labels.
     */
    public static function buildLabelRequest(
        string $shipperName,
        array $shipFrom,
        string $recipientName,
        array $shipTo,
        array $parcel,
        string $mailClass,
        ?string $orderPublicId = null
    ): array {
        $packageDescription = [
            'mailClass' => $mailClass,
            'weight'    => $parcel['weight'],
            'length'    => $parcel['length'],
            'width'     => $parcel['width'],
            'height'    => $parcel['height'],
        ];

        if ($orderPublicId !== null && $orderPublicId !== '') {
            $packageDescription['customerReference'] = $orderPublicId;
        }

        return [
            'fromAddress'        => array_merge($shipFrom, ['firstName' => $shipperName]),
            'toAddress'          => array_merge($shipTo, ['firstName' => $recipientName]),
            'packageDescription' => $packageDescription,
            'imageInfo'          => [
                'imageType' => 'PDF',
            ],
        ];
    }

    /**
     * Normalize the POST /labels/v3/label response into a row ready
     * for persisting to Storage + the File table. USPS v3 is PDF-only
     * — label_format / label_mime are forced to PDF regardless of
     * what the response reports in labelMetadata.
     *
     * Throws RuntimeException when required fields are missing.
     */
    public static function normalizeLabelResponse(array $response): array
    {
        $trackingNumber = $response['trackingNumber'] ?? null;
        if (!is_string($trackingNumber) || $trackingNumber === '') {
            throw new RuntimeException('USPS label response is missing trackingNumber');
        }

        if (!isset($response['labelImage'])) {
            throw new RuntimeException('USPS label response is missing labelImage');
        }

        return [
            'tracking_number' => $trackingNumber,
            'label_binary'    => base64_decode((string) $response['labelImage']),
            'label_format'    => 'PDF',
            'label_mime'      => 'application/pdf',
        ];
    }

    /**
     * Normalize a USPS /tracking/v3/tracking/{id} response into the
     * Fleetbase shape. Maps USPS v3 eventType values to Fleetbase
     * codes — ALERT becomes EXCEPTION; every other known USPS code
     * matches the Fleetbase code verbatim.
     *
     * Status is derived from the final event in the trackingEvents
     * array (USPS returns events in chronological order).
     */
    public static function normalizeTrackingResponse(array $response): array
    {
        $events = $response['trackingEvents'] ?? null;
        if (!is_array($events) || empty($events)) {
            return [
                'status'  => 'UNKNOWN',
                'carrier' => 'USPS',
                'events'  => [],
            ];
        }

        $normalized = [];
        foreach ($events as $event) {
            $rawType = (string) ($event['eventType'] ?? '');
            $code = self::uspsEventTypeToFleetbaseCode($rawType);

            $normalized[] = [
                'code'      => $code,
                'status'    => $code,
                'timestamp' => (string) ($event['eventTimestamp'] ?? ''),
                'location'  => isset($event['eventCity']) ? (string) $event['eventCity'] : null,
                'details'   => null,
            ];
        }

        $finalCode = end($normalized)['code'] ?? 'UNKNOWN';

        return [
            'status'  => $finalCode,
            'carrier' => 'USPS',
            'events'  => $normalized,
        ];
    }

    /**
     * Map a USPS v3 eventType to a Fleetbase TrackingStatus code. Most
     * USPS codes map verbatim; only ALERT becomes EXCEPTION per the
     * plan spec. Unknown codes pass through uppercased — the caller
     * can decide how to treat them.
     */
    public static function uspsEventTypeToFleetbaseCode(string $eventType): string
    {
        $upper = strtoupper($eventType);
        if ($upper === 'ALERT') {
            return 'EXCEPTION';
        }
        return $upper;
    }

    /**
     * Normalize a USPS label refund response. USPS v3 treats label
     * voids as refunds — the response carries a refundStatus field
     * that can be APPROVED / PENDING / DENIED. We treat APPROVED as
     * success and everything else as failure (case-insensitive).
     */
    public static function normalizeVoidResponse(array $response): bool
    {
        $status = $response['refundStatus'] ?? null;
        if (!is_string($status)) {
            return false;
        }
        return strcasecmp($status, 'APPROVED') === 0;
    }

    // ─────────────────────────────────────────────────────────────────────
    //  IMPURE RUNTIME WRAPPERS — compose pure helpers + HTTP + Eloquent.
    //  Not unit-tested in this phase; exercised via smoke test once the
    //  registry entry lands (Task 17 USPS half) and a real USPS Web
    //  Tools v3 TEM account is configured.
    // ─────────────────────────────────────────────────────────────────────

    public function getQuoteFromPayload(
        Payload $payload,
        ?string $serviceType = null,
        ?string $scheduledAt = null,
        ?bool $isRouteOptimized = null
    ): array {
        $mailClass = null;
        if ($serviceType !== null && $serviceType !== '') {
            $resolved = USPSServiceType::find($serviceType);
            $mailClass = $resolved?->mail_class;
        }

        $shipFrom = static::placeToUspsAddress($payload->pickup);
        $shipTo   = static::placeToUspsAddress($payload->dropoff);

        // USPS v3 rates one parcel per request. For multi-parcel
        // orders we rate the first parcel only; batch shipping is
        // Phase 3 work (Task 25) and will dispatch per-parcel rates.
        $firstParcel = null;
        foreach ($payload->entities ?? [] as $entity) {
            if (($entity->type ?? 'parcel') !== 'parcel') {
                continue;
            }
            $firstParcel = static::entityToUspsParcel($entity);
            break;
        }

        if ($firstParcel === null) {
            return [];
        }

        $body = static::buildRatesRequest($shipFrom, $shipTo, $firstParcel, $mailClass);
        $response = $this->post('/prices/v3/base-rates/search', ['json' => $body]) ?? [];

        $markupType  = (string) ($this->integratedVendor?->options['markup_type'] ?? 'flat');
        $markupValue = (int) ($this->integratedVendor?->options['markup_amount'] ?? 0);

        $rows = static::normalizeRatesResponse($response, $markupType, $markupValue);

        $quotes = [];
        foreach ($rows as $row) {
            $serviceQuote = ServiceQuote::create([
                'company_uuid' => $this->integratedVendor?->company_uuid,
                'payload_uuid' => $payload->uuid,
                'service_type' => 'parcel',
                'amount'       => $row['amount'],
                'currency'     => $row['currency'],
                'meta'         => $row['meta'],
            ]);

            ServiceQuoteItem::create([
                'service_quote_uuid' => $serviceQuote->uuid,
                'amount'             => $row['amount'],
                'currency'           => $row['currency'],
                'details'            => $row['service'],
                'code'               => $row['meta']['mail_class'],
            ]);

            $quotes[] = $serviceQuote;
        }

        return $quotes;
    }

    public function createOrderFromServiceQuote(ServiceQuote $serviceQuote, Order $order): array
    {
        $mailClass = (string) ($serviceQuote->meta['mail_class'] ?? '');
        if ($mailClass === '') {
            throw new RuntimeException('ServiceQuote missing USPS mail_class in meta');
        }

        $shipperName = (string) ($order->payload->pickup->name ?? 'FleetOps Shipper');
        $recipientName = (string) ($order->payload->dropoff->name ?? 'Recipient');
        $shipFrom = static::placeToUspsAddress($order->payload->pickup);
        $shipTo   = static::placeToUspsAddress($order->payload->dropoff);

        $parcel = null;
        foreach ($order->payload->entities ?? [] as $entity) {
            if (($entity->type ?? 'parcel') !== 'parcel') {
                continue;
            }
            $parcel = static::entityToUspsParcel($entity);
            break;
        }
        if ($parcel === null) {
            throw new RuntimeException('USPS label purchase requires at least one parcel entity');
        }

        $body = static::buildLabelRequest(
            $shipperName,
            $shipFrom,
            $recipientName,
            $shipTo,
            $parcel,
            $mailClass,
            $order->public_id,
        );

        $response = $this->post('/labels/v3/label', ['json' => $body]) ?? [];
        $result   = static::normalizeLabelResponse($response);

        $disk  = config('filesystems.default');
        $filename = 'usps_label_' . $result['tracking_number'] . '.pdf';
        $path  = 'carrier-labels/' . $filename;
        Storage::disk($disk)->put($path, $result['label_binary']);

        File::create([
            'company_uuid'      => $order->company_uuid,
            'subject_uuid'      => $order->uuid,
            'subject_type'      => Order::class,
            'content_type'      => $result['label_mime'],
            'folder'            => 'carrier-labels',
            'path'              => $path,
            'disk'              => $disk,
            'original_filename' => $filename,
        ]);

        $order->updateMeta('integrated_vendor_order', [
            'carrier'         => 'USPS',
            'tracking_number' => $result['tracking_number'],
            'mail_class'      => $mailClass,
        ]);

        return $result;
    }

    public function getTrackingStatus(string $trackingNumber): array
    {
        $response = $this->get('/tracking/v3/tracking/' . rawurlencode($trackingNumber)) ?? [];
        return static::normalizeTrackingResponse($response);
    }

    /**
     * Void a USPS label by requesting a refund. USPS v3 refunds are
     * allowed only for unused labels within the carrier-side refund
     * window; this method does not second-guess that — it posts the
     * refund request and reports the resulting refundStatus.
     */
    public function voidShipment(string $trackingNumber): bool
    {
        $response = $this->post('/labels/v3/refund', [
            'json' => ['trackingNumber' => $trackingNumber],
        ]) ?? [];

        return static::normalizeVoidResponse($response);
    }
}
