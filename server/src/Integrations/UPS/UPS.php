<?php

namespace Fleetbase\FleetOps\Integrations\UPS;

use Fleetbase\FleetOps\Models\IntegratedVendor;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Payload;
use Fleetbase\FleetOps\Models\ServiceQuote;
use Fleetbase\FleetOps\Models\ServiceQuoteItem;
use Fleetbase\Models\File;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use Illuminate\Support\Facades\Storage;

/**
 * UPS direct bridge class (Mode B).
 *
 * Mirrors the Phase 1 ParcelPath bridge pattern:
 *  - private host / sandboxHost / namespace fields
 *  - Guzzle Client built in the constructor with an injectable
 *    HandlerStack for tests
 *  - chainable setRequestId / setOptions / setIntegratedVendor
 *  - pure static helpers (buildRateShopRequest, normalizeRateShopResponse,
 *    buildShipRequest, normalizeShipResponse, normalizeVoidResponse,
 *    dimensionalWeight, billableWeight, placeToUpsAddress,
 *    entityToUpsPackage, signatureConfirmationCode)
 *  - impure instance wrappers (getQuoteFromPayload,
 *    createOrderFromServiceQuote, voidShipment) that compose the pure
 *    helpers with the Guzzle client and Eloquent writes.
 *
 * Authentication: UPSOAuthClient manages the bearer token lifecycle
 * with Redis caching so every bridge invocation reuses a cached
 * token until expiry.
 *
 * Per the Phase 2 extraction rules, no user-specific or
 * environment-specific logic from ParcelPath v9 is carried over.
 * Only generic UPS Rating/Ship/Void API semantics are implemented.
 * Edge cases that would normally come from PP v9 (NBNL barcode-to-
 * PDF handling, return label swap, Ground Saver estimated-days
 * derivation, multi-package letter merge) are intentionally NOT
 * ported here — they can be layered in as follow-up PRs once the
 * base integration is merged.
 */
class UPS
{
    /**
     * API Host URL.
     */
    private string $host = 'https://onlinetools.ups.com';

    /**
     * API Sandbox Host URL.
     */
    private string $sandboxHost = 'https://wwwcie.ups.com';

    /**
     * Determines if instance is sandbox instance.
     */
    private bool $isSandbox = false;

    /**
     * UPS OAuth2 client id / secret / account number.
     */
    private ?string $clientId;
    private ?string $clientSecret;
    private ?string $accountNumber;

    /**
     * Applicable request ID.
     */
    private ?string $requestId = null;

    /**
     * Applicable options (markup_type, markup_amount, label_format, etc).
     */
    private array $options = [];

    /**
     * HTTP Client Instance.
     */
    private Client $client;

    /**
     * OAuth token manager.
     */
    private ?UPSOAuthClient $oauthClient;

    /**
     * The current integrated vendor accessing instance.
     */
    private ?IntegratedVendor $integratedVendor = null;

    public function __construct(
        ?string $clientId = null,
        ?string $clientSecret = null,
        ?string $accountNumber = null,
        bool $sandbox = false,
        ?HandlerStack $handler = null,
        ?UPSOAuthClient $oauthClient = null
    ) {
        $this->clientId      = $clientId;
        $this->clientSecret  = $clientSecret;
        $this->accountNumber = $accountNumber;
        $this->isSandbox     = $sandbox;

        $clientConfig = [
            'base_uri' => $this->baseUrl(),
        ];

        if ($handler !== null) {
            $clientConfig['handler'] = $handler;
        }

        $this->client = new Client($clientConfig);

        $this->oauthClient = $oauthClient ?? (
            $clientId !== null && $clientSecret !== null
                ? new UPSOAuthClient($clientId, $clientSecret, $sandbox, $handler)
                : null
        );
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

    public function getAccountNumber(): ?string
    {
        return $this->accountNumber;
    }

    public function baseUrl(): string
    {
        return $this->isSandbox ? $this->sandboxHost : $this->host;
    }

    /**
     * Execute an authenticated request against the UPS API.
     */
    private function request(string $method, string $path, array $options = [])
    {
        if ($this->oauthClient === null) {
            throw new \RuntimeException('UPS bridge is not configured with credentials or an OAuth client.');
        }

        $token = $this->oauthClient->getAccessToken();

        $options['headers'] = array_merge($options['headers'] ?? [], [
            'Authorization' => 'Bearer ' . $token,
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
            'transactionSrc' => 'fleetops',
        ]);

        if ($this->requestId !== null) {
            $options['headers']['transId'] = $this->requestId;
        }

        $options['http_errors'] = false;

        $response = $this->client->request($method, $path, $options);
        return json_decode((string) $response->getBody(), true);
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
     * Dimensional weight in pounds. Default divisor 139 is domestic US UPS.
     * International UPS and USPS use 166.
     */
    public static function dimensionalWeight(float $length, float $width, float $height, int $divisor = 139): float
    {
        if ($divisor <= 0) {
            throw new \InvalidArgumentException('divisor must be positive');
        }
        return ($length * $width * $height) / $divisor;
    }

    /**
     * Billable weight is the maximum of actual weight and dimensional weight.
     */
    public static function billableWeight(float $actualLb, float $dimLb): float
    {
        return max($actualLb, $dimLb);
    }

    /**
     * Convert a Place-like object to the UPS Address shape.
     * Defaults CountryCode to US when absent.
     */
    public static function placeToUpsAddress(object $place): array
    {
        return [
            'AddressLine'       => [(string) ($place->street1 ?? '')],
            'City'              => (string) ($place->city ?? ''),
            'StateProvinceCode' => (string) ($place->province ?? ''),
            'PostalCode'        => (string) ($place->postal_code ?? ''),
            'CountryCode'       => (string) ($place->country ?? 'US'),
        ];
    }

    /**
     * Convert an Entity-like parcel object to the UPS Package shape.
     * Dimensions are reported in inches, weight in pounds, and billable
     * weight is computed as max(actual, dimensional).
     */
    public static function entityToUpsPackage(object $entity): array
    {
        $length = (float) ($entity->length ?? 0);
        $width  = (float) ($entity->width ?? 0);
        $height = (float) ($entity->height ?? 0);
        $actual = (float) ($entity->weight ?? 0);

        $dim      = self::dimensionalWeight($length, $width, $height);
        $billable = self::billableWeight($actual, $dim);

        return [
            'PackagingType' => [
                'Code'        => '02',    // 02 = Customer Supplied Package
                'Description' => 'Package',
            ],
            'Dimensions' => [
                'UnitOfMeasurement' => ['Code' => 'IN'],
                'Length'            => (string) $length,
                'Width'             => (string) $width,
                'Height'            => (string) $height,
            ],
            'PackageWeight' => [
                'UnitOfMeasurement' => ['Code' => 'LBS'],
                'Weight'            => sprintf('%.2f', $billable),
            ],
        ];
    }

    /**
     * Build the POST /api/rating/v2403/rate/{shop|rate} request body.
     * When $serviceCode is null, builds a Shop request that returns all
     * service levels. When set, builds a Rate request for that specific
     * UPS service.
     */
    public static function buildRateShopRequest(
        array $shipFrom,
        array $shipTo,
        array $packages,
        string $accountNumber,
        ?string $serviceCode = null
    ): array {
        $shipment = [
            'Shipper' => [
                'ShipperNumber' => $accountNumber,
                'Address'       => $shipFrom,
            ],
            'ShipTo'   => ['Address' => $shipTo],
            'ShipFrom' => ['Address' => $shipFrom],
            'Package'  => $packages,
        ];

        if ($serviceCode !== null && $serviceCode !== '') {
            $shipment['Service'] = ['Code' => $serviceCode];
        }

        return [
            'RateRequest' => [
                'Request' => [
                    'RequestOption' => $serviceCode ? 'Rate' : 'Shop',
                ],
                'Shipment' => $shipment,
            ],
        ];
    }

    /**
     * Normalize the POST /api/rating/v2403/rate/{shop|rate} response into
     * an array of rows ready for ServiceQuote::create(...). Amounts are
     * converted to integer cents. NegotiatedRateCharges take precedence
     * over TotalCharges when present (AGP selection). Flat or percent
     * markup is applied in cents on top of the carrier amount.
     *
     * NOTE: a single RatedShipment may come back as an object OR as an
     * array depending on UPS's response serialization — both are handled.
     */
    public static function normalizeRateShopResponse(
        array $response,
        string $markupType = 'flat',
        int $markupValue = 0
    ): array {
        $rated = $response['RateResponse']['RatedShipment'] ?? null;
        if ($rated === null) {
            return [];
        }
        // UPS returns a single rated shipment as a direct object, not an array.
        if (isset($rated['Service']) || isset($rated['TotalCharges'])) {
            $rated = [$rated];
        }

        $rows = [];
        foreach ($rated as $rs) {
            $currency = (string) (
                $rs['TotalCharges']['CurrencyCode']
                ?? $rs['NegotiatedRateCharges']['TotalCharge']['CurrencyCode']
                ?? 'USD'
            );

            // Prefer negotiated rate when present.
            $rawAmount = isset($rs['NegotiatedRateCharges']['TotalCharge']['MonetaryValue'])
                ? $rs['NegotiatedRateCharges']['TotalCharge']['MonetaryValue']
                : ($rs['TotalCharges']['MonetaryValue'] ?? null);

            if ($rawAmount === null) {
                continue;
            }

            $carrierAmount = (int) round(((float) $rawAmount) * 100);

            $markup = $markupType === 'percent'
                ? (int) round($carrierAmount * $markupValue / 100)
                : $markupValue;
            $sellAmount = $carrierAmount + $markup;

            $serviceCode = (string) ($rs['Service']['Code'] ?? '');
            $serviceType = UPSServiceType::find(function ($t) use ($serviceCode) {
                return $t->service_code === $serviceCode;
            });
            $description = $serviceType !== null
                ? (string) $serviceType->description
                : 'UPS Service ' . $serviceCode;

            $rows[] = [
                'amount'   => $sellAmount,
                'currency' => $currency,
                'service'  => $description,
                'meta'     => [
                    'carrier'        => 'UPS',
                    'service_code'   => $serviceCode,
                    'carrier_amount' => $carrierAmount,
                    'markup_amount'  => $markup,
                    'markup_type'    => $markupType,
                ],
            ];
        }

        return $rows;
    }

    /**
     * Map a human signature confirmation preference ('standard', 'adult')
     * to the UPS DCISType numeric code expected in
     * PackageServiceOptions.DeliveryConfirmation.DCISType. Returns null
     * when no signature is requested (default, none, empty, unknown).
     *
     * Ported from the ParcelPath v9 createUPSDapLabel signature-mapping
     * table per the Phase 2 extraction rule — only the generic mapping
     * is carried over, no user/session-specific fallbacks.
     */
    public static function signatureConfirmationCode(?string $preference): ?int
    {
        if ($preference === null || $preference === '') {
            return null;
        }
        return match (strtolower($preference)) {
            'standard' => 2,
            'adult'    => 3,
            default    => null,
        };
    }

    /**
     * Build the POST /api/shipments/v2409/ship request body.
     *
     * $labelFormat is normalized to uppercase and defaults to PDF.
     * $orderPublicId, when provided, is injected as the UPS
     * ReferenceNumber (code 00) on every package AND into the
     * Shipment.Description so the tracking event feed carries a
     * human-readable tie-back to the FleetOps order.
     * $signaturePreference follows the signatureConfirmationCode
     * mapping; null/unknown values omit DeliveryConfirmation entirely.
     */
    public static function buildShipRequest(
        string $shipperName,
        array $shipFrom,
        array $shipTo,
        array $packages,
        string $accountNumber,
        string $serviceCode,
        string $labelFormat = 'PDF',
        ?string $orderPublicId = null,
        ?string $signaturePreference = null
    ): array {
        $format = strtoupper($labelFormat);
        $sigCode = self::signatureConfirmationCode($signaturePreference);

        // Attach reference number + optional DeliveryConfirmation to every package.
        $preparedPackages = [];
        foreach ($packages as $pkg) {
            if ($orderPublicId !== null && $orderPublicId !== '') {
                $pkg['ReferenceNumber'] = [[
                    'Code'  => '00',
                    'Value' => $orderPublicId,
                ]];
            }
            if ($sigCode !== null) {
                $pkg['PackageServiceOptions'] = array_merge(
                    $pkg['PackageServiceOptions'] ?? [],
                    ['DeliveryConfirmation' => ['DCISType' => (string) $sigCode]]
                );
            }
            $preparedPackages[] = $pkg;
        }

        $description = 'FleetOps shipment'
            . ($orderPublicId !== null && $orderPublicId !== '' ? ' ' . $orderPublicId : '');

        return [
            'ShipmentRequest' => [
                'Request' => [
                    'RequestOption' => 'nonvalidate',
                ],
                'Shipment' => [
                    'Description' => $description,
                    'Shipper' => [
                        'Name'          => $shipperName,
                        'ShipperNumber' => $accountNumber,
                        'Address'       => $shipFrom,
                    ],
                    'ShipTo' => [
                        'Name'    => $shipperName,
                        'Address' => $shipTo,
                    ],
                    'ShipFrom' => [
                        'Name'    => $shipperName,
                        'Address' => $shipFrom,
                    ],
                    'PaymentInformation' => [
                        'ShipmentCharge' => [
                            'Type'        => '01', // Transportation
                            'BillShipper' => ['AccountNumber' => $accountNumber],
                        ],
                    ],
                    'Service' => ['Code' => $serviceCode],
                    'Package' => $preparedPackages,
                ],
                'LabelSpecification' => [
                    'LabelImageFormat' => ['Code' => $format],
                    'HTTPUserAgent'    => 'fleetops',
                ],
            ],
        ];
    }

    /**
     * Normalize the POST /api/shipments/v2409/ship response into a row
     * ready for persisting to Storage + the File table.
     *
     * Returns:
     *   [
     *     'tracking_number' => string,
     *     'shipment_id'     => string,  // UPS ShipmentIdentificationNumber
     *     'label_binary'    => string,  // raw decoded bytes
     *     'label_format'    => 'PDF' | 'ZPL' | 'GIF',
     *     'label_mime'      => 'application/pdf' | 'application/zpl' | 'image/gif',
     *   ]
     *
     * Handles the UPS quirk where a single PackageResults may come back
     * as an object rather than an array of one.
     *
     * Throws RuntimeException on malformed responses — the impure
     * wrapper should surface these as vendor errors to the caller.
     */
    public static function normalizeShipResponse(array $response): array
    {
        $results = $response['ShipmentResponse']['ShipmentResults'] ?? null;
        if (!is_array($results) || empty($results)) {
            throw new \RuntimeException('UPS ship response is missing ShipmentResults');
        }

        $shipmentId = $results['ShipmentIdentificationNumber'] ?? null;

        $packageResults = $results['PackageResults'] ?? null;
        if ($packageResults === null) {
            throw new \RuntimeException('UPS ship response is missing PackageResults');
        }

        // Single PackageResults can come back as a direct object rather than an array.
        if (isset($packageResults['TrackingNumber']) || isset($packageResults['ShippingLabel'])) {
            $firstPackage = $packageResults;
        } else {
            $firstPackage = $packageResults[0] ?? null;
        }
        if (!is_array($firstPackage)) {
            throw new \RuntimeException('UPS ship response has no package entries');
        }

        $trackingNumber = $firstPackage['TrackingNumber'] ?? $shipmentId;
        if (!is_string($trackingNumber) || $trackingNumber === '') {
            throw new \RuntimeException('UPS ship response is missing a tracking number');
        }

        $labelImage = $firstPackage['ShippingLabel']['GraphicImage'] ?? '';
        $labelFormat = strtoupper((string) ($firstPackage['ShippingLabel']['ImageFormat']['Code'] ?? 'PDF'));

        $labelMime = match ($labelFormat) {
            'ZPL'   => 'application/zpl',
            'GIF'   => 'image/gif',
            default => 'application/pdf',
        };

        return [
            'tracking_number' => $trackingNumber,
            'shipment_id'     => (string) ($shipmentId ?? $trackingNumber),
            'label_binary'    => base64_decode($labelImage),
            'label_format'    => $labelFormat,
            'label_mime'      => $labelMime,
        ];
    }

    /**
     * Normalize the DELETE /api/shipments/v1/void/cancel/{id} response.
     * Returns true when UPS reports a successful void by either returning
     * Status.Code='1' or Status.Description='Success' (case-insensitive).
     */
    public static function normalizeVoidResponse(array $response): bool
    {
        $status = $response['VoidShipmentResponse']['SummaryResult']['Status'] ?? null;
        if (!is_array($status)) {
            return false;
        }

        $code = isset($status['Code']) ? (string) $status['Code'] : '';
        if ($code === '1') {
            return true;
        }

        $description = isset($status['Description']) ? (string) $status['Description'] : '';
        if (strcasecmp($description, 'Success') === 0) {
            return true;
        }

        return false;
    }

    /**
     * Map a UPS Tracking API activity type code to the corresponding
     * Fleetbase TrackingStatus code. Per the integration spec §6.2.
     *
     * | UPS Code | Fleetbase Code      |
     * |----------|---------------------|
     * | I        | IN_TRANSIT          |
     * | D        | DELIVERED           |
     * | X        | EXCEPTION           |
     * | P        | PICKED_UP           |
     * | M        | MANIFESTED          |
     * | O        | OUT_FOR_DELIVERY    |
     * | RS       | RETURN_TO_SENDER    |
     *
     * Unknown codes pass through uppercased — the caller can decide how
     * to handle them.
     */
    public static function upsActivityCodeToFleetbaseCode(string $code): string
    {
        if ($code === '') {
            return '';
        }

        return match (strtoupper($code)) {
            'I'  => 'IN_TRANSIT',
            'D'  => 'DELIVERED',
            'X'  => 'EXCEPTION',
            'P'  => 'PICKED_UP',
            'M'  => 'MANIFESTED',
            'O'  => 'OUT_FOR_DELIVERY',
            'RS' => 'RETURN_TO_SENDER',
            default => strtoupper($code),
        };
    }

    /**
     * Normalize a UPS Tracking API v1 response
     * (GET /api/track/v1/details/{trackingNumber}) into the Fleetbase
     * tracking shape: {status, carrier, events[]}.
     *
     * UPS returns activity entries inside
     * trackResponse.shipment[0].package[0].activity[]. Each entry has
     * status.type (the activity code), date (YYYYMMDD), time (HHMMSS),
     * and optionally location.address.{city, stateProvince}.
     *
     * Handles the UPS quirk where a single activity may be returned
     * as an object rather than an array of one.
     *
     * Status is derived from the last event (UPS returns events in
     * chronological order with the most recent last).
     */
    public static function normalizeTrackingResponse(array $response): array
    {
        $activities = $response['trackResponse']['shipment'][0]['package'][0]['activity'] ?? null;

        if (!is_array($activities) || empty($activities)) {
            return [
                'status'  => 'UNKNOWN',
                'carrier' => 'UPS',
                'events'  => [],
            ];
        }

        // Single activity may be an object not an array.
        if (isset($activities['status']) || isset($activities['date'])) {
            $activities = [$activities];
        }

        $events = [];
        foreach ($activities as $activity) {
            $rawCode = (string) ($activity['status']['type'] ?? '');
            $code    = self::upsActivityCodeToFleetbaseCode($rawCode);

            $city  = $activity['location']['address']['city'] ?? null;
            $state = $activity['location']['address']['stateProvince'] ?? null;
            $location = null;
            if ($city !== null && $state !== null) {
                $location = $city . ', ' . $state;
            } elseif ($city !== null) {
                $location = $city;
            }

            $dateStr = (string) ($activity['date'] ?? '');
            $timeStr = (string) ($activity['time'] ?? '');
            $timestamp = null;
            if (strlen($dateStr) === 8) {
                $timestamp = substr($dateStr, 0, 4) . '-' . substr($dateStr, 4, 2) . '-' . substr($dateStr, 6, 2);
                if (strlen($timeStr) === 6) {
                    $timestamp .= 'T' . substr($timeStr, 0, 2) . ':' . substr($timeStr, 2, 2) . ':' . substr($timeStr, 4, 2);
                }
            }

            $events[] = [
                'code'      => $code,
                'status'    => (string) ($activity['status']['description'] ?? $code),
                'timestamp' => $timestamp,
                'location'  => $location,
                'details'   => null,
            ];
        }

        $finalCode = end($events)['code'] ?? 'UNKNOWN';

        return [
            'status'  => $finalCode,
            'carrier' => 'UPS',
            'events'  => $events,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    //  IMPURE RUNTIME WRAPPERS — compose pure helpers + HTTP + Eloquent.
    //  Not unit-tested in this phase; exercised via smoke test once the
    //  registry entry lands (Task 17) and a real UPS sandbox account is
    //  configured.
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Rate a Payload via POST /api/rating/v2403/rate/{shop|rate} and
     * persist each returned rate as a ServiceQuote + ServiceQuoteItem.
     * Returns the created ServiceQuote array. Called from
     * ServiceQuoteController::query through the IntegratedVendor bridge
     * machinery.
     */
    public function getQuoteFromPayload(
        Payload $payload,
        ?string $serviceType = null,
        ?string $scheduledAt = null,
        ?bool $isRouteOptimized = null
    ): array {
        $serviceCode = null;
        if ($serviceType !== null && $serviceType !== '') {
            $resolved = UPSServiceType::find($serviceType);
            $serviceCode = $resolved?->service_code;
        }

        $shipFrom = static::placeToUpsAddress($payload->pickup);
        $shipTo   = static::placeToUpsAddress($payload->dropoff);
        $packages = [];
        foreach ($payload->entities ?? [] as $entity) {
            if (($entity->type ?? 'parcel') !== 'parcel') {
                continue;
            }
            $packages[] = static::entityToUpsPackage($entity);
        }

        $body = static::buildRateShopRequest(
            $shipFrom,
            $shipTo,
            $packages,
            (string) $this->accountNumber,
            $serviceCode
        );

        $endpoint = $serviceCode
            ? '/api/rating/v2403/rate/Rate'
            : '/api/rating/v2403/rate/Shop';

        $response = $this->post($endpoint, ['json' => $body]) ?? [];

        $markupType  = (string) ($this->integratedVendor?->options['markup_type'] ?? 'flat');
        $markupValue = (int) ($this->integratedVendor?->options['markup_amount'] ?? 0);

        $rows = static::normalizeRateShopResponse($response, $markupType, $markupValue);

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
                'code'               => $row['meta']['service_code'],
            ]);

            $quotes[] = $serviceQuote;
        }

        return $quotes;
    }

    /**
     * Get tracking status from UPS Tracking API v1.
     */
    public function getTrackingStatus(string $trackingNumber): array
    {
        $response = $this->request(
            'GET',
            '/api/track/v1/details/' . rawurlencode($trackingNumber),
            []
        ) ?? [];

        return static::normalizeTrackingResponse($response);
    }

    /**
     * Purchase a UPS shipping label against the POST /api/shipments/v2409/ship
     * endpoint. Decodes the base64 label binary, writes it to the default
     * Storage disk under carrier-labels/, creates a File record pointing at
     * it, and stores the shipmentIdentificationNumber + tracking_number +
     * carrier ('UPS') + service_code on Order.meta.integrated_vendor_order.
     *
     * Not unit-tested here; exercised at runtime via smoke test once the
     * registry entry lands (Task 17) and a real UPS sandbox account is
     * configured. The tricky pieces — request assembly and response
     * parsing — are already covered by UPSLabelBuilderTest.
     */
    public function createOrderFromServiceQuote(ServiceQuote $serviceQuote, Order $order): array
    {
        $serviceCode = (string) ($serviceQuote->meta['service_code'] ?? '');
        if ($serviceCode === '') {
            throw new \RuntimeException('ServiceQuote missing UPS service_code in meta');
        }

        $format = strtoupper((string) ($this->integratedVendor?->options['label_format'] ?? 'PDF'));
        $signature = $order->getMeta('signature_confirmation', null);

        $shipperName = (string) ($order->payload->pickup->name ?? 'FleetOps Shipper');
        $shipFrom = static::placeToUpsAddress($order->payload->pickup);
        $shipTo   = static::placeToUpsAddress($order->payload->dropoff);

        $packages = [];
        foreach ($order->payload->entities ?? [] as $entity) {
            if (($entity->type ?? 'parcel') !== 'parcel') {
                continue;
            }
            $packages[] = static::entityToUpsPackage($entity);
        }

        $body = static::buildShipRequest(
            $shipperName,
            $shipFrom,
            $shipTo,
            $packages,
            (string) $this->accountNumber,
            $serviceCode,
            $format,
            $order->public_id,
            is_string($signature) ? $signature : null,
        );

        $response = $this->post('/api/shipments/v2409/ship', ['json' => $body]) ?? [];
        $result   = static::normalizeShipResponse($response);

        $ext   = strtolower($result['label_format']);
        $disk  = config('filesystems.default');
        $filename = 'ups_label_' . $result['tracking_number'] . '.' . $ext;
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
            'carrier'                      => 'UPS',
            'shipmentIdentificationNumber' => $result['shipment_id'],
            'tracking_number'              => $result['tracking_number'],
            'service_code'                 => $serviceCode,
        ]);

        return $result;
    }

    /**
     * Void a UPS shipment by its ShipmentIdentificationNumber.
     *
     * NOTE on extraction rule: ParcelPath v9's void path (per the
     * Phase 2 porting rules flagged by the team) contained an
     * email-based UPS URL override that switched hosts depending on
     * the logged-in user's email. That branch is NOT carried over.
     * Only the generic sandbox/production host selection managed by
     * UPSOAuthClient and $this->baseUrl() is used.
     */
    public function voidShipment(string $shipmentIdentificationNumber): bool
    {
        $response = $this->delete(
            '/api/shipments/v1/void/cancel/' . rawurlencode($shipmentIdentificationNumber)
        ) ?? [];

        return static::normalizeVoidResponse($response);
    }
}
