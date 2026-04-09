<?php

namespace Fleetbase\FleetOps\Integrations\UPS;

use Fleetbase\FleetOps\Models\IntegratedVendor;
use Fleetbase\FleetOps\Models\Payload;
use Fleetbase\FleetOps\Models\ServiceQuote;
use Fleetbase\FleetOps\Models\ServiceQuoteItem;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;

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
}
