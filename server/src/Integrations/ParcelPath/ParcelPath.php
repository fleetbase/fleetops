<?php

namespace Fleetbase\FleetOps\Integrations\ParcelPath;

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
 * ParcelPath API bridge (Mode A — default).
 *
 * Mirrors the Lalamove bridge shape (host / sandboxHost / namespace,
 * Guzzle client, setRequestId / setOptions / setIntegratedVendor
 * chainable setters, private request helpers). The rating, label,
 * tracking, and void methods are added in Tasks 3–5 on this branch.
 */
class ParcelPath
{
    /**
     * API Host URL.
     */
    private string $host = 'https://api.parcelpath.com/';

    /**
     * API Sandbox Host URL.
     */
    private string $sandboxHost = 'https://api-sandbox.parcelpath.com/';

    /**
     * API Namespace.
     */
    private string $namespace = 'v1';

    /**
     * Determines if instance is sandbox instance.
     */
    private bool $isSandbox = false;

    /**
     * ParcelPath API Key.
     */
    private ?string $apiKey;

    /**
     * Applicable request ID.
     */
    private ?string $requestId = null;

    /**
     * Applicable options (carrier_filter, label_format, insurance_default, markup_type, markup_amount).
     */
    private array $options = [];

    /**
     * HTTP Client Instance.
     */
    private Client $client;

    /**
     * The current integrated vendor accessing instance.
     */
    private ?IntegratedVendor $integratedVendor = null;

    public function __construct(?string $apiKey = null, bool $sandbox = false, ?HandlerStack $handler = null)
    {
        $this->isSandbox = $sandbox;
        $this->apiKey    = $apiKey;

        $clientConfig = [
            'base_uri' => $this->buildRequestUrl(),
        ];

        // Injectable handler for tests — prod path is a plain Guzzle client.
        if ($handler !== null) {
            $clientConfig['handler'] = $handler;
        }

        $this->client = new Client($clientConfig);
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

    public function getApiKey(): ?string
    {
        return $this->apiKey;
    }

    /**
     * Compose a request URL. Returns the bare base URL when `$path` is empty
     * and `<host><namespace>/<path>` otherwise. Matches Lalamove::buildRequestUrl.
     */
    public function buildRequestUrl(string $path = ''): string
    {
        $host = $this->isSandbox ? $this->sandboxHost : $this->host;

        return trim($host . $this->namespace . '/' . $path);
    }

    /**
     * Execute an authenticated request against the ParcelPath API.
     */
    private function request(string $method, string $path, array $options = [])
    {
        $options['headers'] = array_merge($options['headers'] ?? [], [
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
            'Authorization' => 'Bearer ' . (string) $this->apiKey,
        ]);

        if ($this->requestId !== null) {
            $options['headers']['X-Request-Id'] = $this->requestId;
        }

        $options['http_errors'] = false;

        $response = $this->client->request($method, $path, $options);
        $body     = (string) $response->getBody();

        return json_decode($body, true);
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
    //  These are static and take plain arrays / duck-typed objects so
    //  they can be unit-tested without booting Laravel. The runtime
    //  wrappers below (getQuoteFromPayload, createOrderFromServiceQuote,
    //  getTrackingStatus, voidShipment) compose these with Eloquent
    //  writes and the Guzzle client.
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Convert a Place-like object (anything with street1/city/province/
     * postal_code/country properties) into the ship_from / ship_to shape
     * ParcelPath expects.
     */
    public static function placeToAddress(object $place): array
    {
        return [
            'address' => (string) ($place->street1 ?? ''),
            'city'    => (string) ($place->city ?? ''),
            'state'   => (string) ($place->province ?? ''),
            'zip'     => (string) ($place->postal_code ?? ''),
            'country' => (string) ($place->country ?? 'US'),
        ];
    }

    /**
     * Convert an iterable of Entity-like parcel objects into the parcels
     * array ParcelPath expects. Non-parcel entities are skipped.
     */
    public static function entitiesToParcels(iterable $entities): array
    {
        $parcels = [];
        foreach ($entities as $entity) {
            $type = $entity->type ?? null;
            if ($type !== null && $type !== 'parcel') {
                continue;
            }
            $parcels[] = [
                'length' => (float) ($entity->length ?? 0),
                'width'  => (float) ($entity->width ?? 0),
                'height' => (float) ($entity->height ?? 0),
                'weight' => (float) ($entity->weight ?? 0),
                'template' => isset($entity->meta['package_template'])
                    ? (string) $entity->meta['package_template']
                    : null,
            ];
        }
        return $parcels;
    }

    /**
     * Build the POST /v1/rates request body.
     */
    public static function buildRatesRequest(
        array $shipFrom,
        array $shipTo,
        array $parcels,
        ?string $carrierFilter = 'all'
    ): array {
        return [
            'ship_from'      => $shipFrom,
            'ship_to'        => $shipTo,
            'parcels'        => $parcels,
            'carrier_filter' => $carrierFilter ?: 'all',
        ];
    }

    /**
     * Normalize the POST /v1/rates response into an array of rows ready
     * for ServiceQuote::create(...). Amount is converted to integer cents.
     * Each row carries a `meta` sub-array with carrier/service_token/
     * pp_rate_id/estimated_days/insurance_available/insurance_cost/
     * carrier_amount so the runtime wrapper can persist it verbatim.
     */
    public static function normalizeRatesResponse(array $response): array
    {
        $rows = [];
        $rates = $response['rates'] ?? [];
        foreach ($rates as $rate) {
            if (!isset($rate['amount'])) {
                continue;
            }
            $amountCents = (int) round(((float) $rate['amount']) * 100);
            $insuranceCostCents = isset($rate['insurance_cost'])
                ? (int) round(((float) $rate['insurance_cost']) * 100)
                : null;

            $rows[] = [
                'amount'   => $amountCents,
                'currency' => (string) ($rate['currency'] ?? 'USD'),
                'service'  => (string) ($rate['service'] ?? ''),
                'meta' => [
                    'carrier'              => (string) ($rate['carrier'] ?? ''),
                    'service_token'        => (string) ($rate['service_token'] ?? ''),
                    'pp_rate_id'           => $rate['rate_id'] ?? null,
                    'estimated_days'       => $rate['estimated_days'] ?? null,
                    'insurance_available'  => (bool) ($rate['insurance_available'] ?? false),
                    'insurance_cost'       => $insuranceCostCents,
                    'carrier_amount'       => $amountCents,
                ],
            ];
        }
        return $rows;
    }

    /**
     * Build the POST /v1/labels request body.
     */
    public static function buildLabelPurchaseRequest(?string $rateId, string $labelFormat = 'PDF'): array
    {
        if ($rateId === null || $rateId === '') {
            throw new \InvalidArgumentException('rate_id required');
        }

        return [
            'rate_id'      => $rateId,
            'label_format' => strtoupper($labelFormat),
        ];
    }

    /**
     * Normalize the POST /v1/labels response into a row ready for persistence.
     */
    public static function normalizeLabelResponse(array $response): array
    {
        if (empty($response['tracking_number']) || !isset($response['label_data'])) {
            throw new \RuntimeException('invalid label response');
        }

        $format = strtoupper((string) ($response['label_format'] ?? 'PDF'));
        $mime   = $format === 'ZPL' ? 'application/zpl' : 'application/pdf';

        return [
            'tracking_number'        => (string) $response['tracking_number'],
            'carrier'                => (string) ($response['carrier'] ?? ''),
            'label_binary'           => base64_decode((string) $response['label_data']),
            'label_format'           => $format,
            'label_mime'             => $mime,
            'parcelpath_shipment_id' => $response['parcelpath_shipment_id'] ?? null,
            'insurance'              => (array) ($response['insurance'] ?? []),
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    //  IMPURE RUNTIME WRAPPERS — compose pure helpers + HTTP + Eloquent.
    //  Not unit-tested in this phase; exercised via smoke test once the
    //  full label/order flow lands. Kept thin so the testable parts are
    //  all above.
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Rate a Payload via POST /v1/rates and persist each returned rate as
     * a ServiceQuote + ServiceQuoteItem. Returns the created ServiceQuote
     * collection. Called from ServiceQuoteController::query via the
     * IntegratedVendor bridge machinery.
     */
    public function getQuoteFromPayload(
        Payload $payload,
        ?string $serviceType = null,
        ?string $scheduledAt = null,
        ?bool $isRouteOptimized = null
    ): array {
        $carrierFilter = $this->integratedVendor?->options['carrier_filter'] ?? 'all';

        $body = static::buildRatesRequest(
            static::placeToAddress($payload->pickup),
            static::placeToAddress($payload->dropoff),
            static::entitiesToParcels($payload->entities ?? []),
            $carrierFilter
        );

        $response = $this->post('rates', ['json' => $body]) ?? [];
        $rows     = static::normalizeRatesResponse($response);

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
                'details'            => $row['meta']['carrier'] . ' ' . $row['service'],
                'code'               => $row['meta']['service_token'],
            ]);

            $quotes[] = $serviceQuote;
        }

        return $quotes;
    }

    /**
     * Purchase a label via POST /v1/labels, persist the label binary as a
     * File record, and stamp the ParcelPath shipment id / tracking number /
     * insurance payload onto Order.meta.integrated_vendor_order.
     */
    public function createOrderFromServiceQuote(ServiceQuote $serviceQuote, Order $order): array
    {
        $rateId = $serviceQuote->meta['pp_rate_id'] ?? null;
        $format = $this->integratedVendor?->options['label_format'] ?? 'PDF';

        $body     = static::buildLabelPurchaseRequest($rateId, $format);
        $response = $this->post('labels', ['json' => $body]) ?? [];
        $row      = static::normalizeLabelResponse($response);

        $ext              = strtolower($row['label_format']);
        $trackingNumber   = $row['tracking_number'];
        $path             = 'carrier-labels/pp_label_' . $trackingNumber . '.' . $ext;
        $disk             = config('filesystems.default');
        $originalFilename = 'pp_label_' . $trackingNumber . '.' . $ext;

        Storage::disk($disk)->put($path, $row['label_binary']);

        File::create([
            'company_uuid'      => $order->company_uuid,
            'subject_uuid'      => $order->uuid,
            'subject_type'      => Order::class,
            'folder'            => 'carrier-labels',
            'content_type'      => $row['label_mime'],
            'path'              => $path,
            'disk'              => $disk,
            'original_filename' => $originalFilename,
        ]);

        $order->updateMeta('integrated_vendor_order', [
            'parcelpath_shipment_id' => $row['parcelpath_shipment_id'],
            'tracking_number'        => $trackingNumber,
            'carrier'                => $row['carrier'],
            'insurance'              => $row['insurance'],
        ]);

        return $row;
    }
}
