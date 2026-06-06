<?php

namespace Fleetbase\FleetOps\Http\Controllers;

use Fleetbase\FleetOps\Models\Telematic;
use Fleetbase\FleetOps\Support\Telematics\TelematicProviderRegistry;
use Fleetbase\FleetOps\Support\Telematics\TelematicService;
use Fleetbase\Support\IdempotencyManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Class TelematicWebhookController.
 *
 * Handles webhook ingestion from telematics providers.
 */
class TelematicWebhookController extends Controller
{
    protected TelematicProviderRegistry $registry;
    protected TelematicService $service;
    protected IdempotencyManager $idempotency;

    public function __construct(
        TelematicProviderRegistry $registry,
        TelematicService $service,
        IdempotencyManager $idempotency,
    ) {
        $this->registry    = $registry;
        $this->service     = $service;
        $this->idempotency = $idempotency;
    }

    /**
     * Handle provider webhook.
     */
    public function handle(Request $request, string $providerKey): JsonResponse
    {
        $correlationId = Str::uuid()->toString();

        Log::info('Webhook received', [
            'correlation_id'      => $correlationId,
            'provider'            => $providerKey,
            'has_signature'       => $request->headers->has('X-Webhook-Signature'),
            'has_idempotency_key' => $request->headers->has('X-Idempotency-Key'),
        ]);

        // Check idempotency
        $idempotencyKey = $request->header('X-Idempotency-Key');
        if ($idempotencyKey && $this->idempotency->isDuplicate($idempotencyKey)) {
            Log::info('Duplicate webhook detected', [
                'correlation_id'  => $correlationId,
                'idempotency_key' => $idempotencyKey,
            ]);

            return response()->json(['status' => 'duplicate'], 200);
        }

        // Get provider
        $provider = $this->registry->resolve($providerKey);

        // Find telematic for this provider. Native provider payloads are
        // resolved by provider identifiers first; Fleetbase URL/header ids are
        // optional configuration affordances where provider dashboards allow it.
        $telematicId = $request->query('telematic') ?? $request->query('integration') ?? $request->header('X-Fleetbase-Telematic');
        $signature   = $request->header('X-Webhook-Signature');
        $telematic   = $this->service->resolveWebhookTelematic($providerKey, $request->all(), $request->headers->all(), $telematicId);

        if (!$telematic && !$telematicId && $signature) {
            $signatureMatches = Telematic::where('provider', $providerKey)
                ->get()
                ->filter(fn (Telematic $candidate) => $provider->validateWebhookSignature($request->getContent(), $signature, $this->service->getCredentials($candidate)));

            if ($signatureMatches->count() === 1) {
                $telematic = $signatureMatches->first();
            } elseif ($signatureMatches->count() > 1) {
                Log::warning('Ambiguous telematic webhook signature match', [
                    'correlation_id' => $correlationId,
                    'provider'       => $providerKey,
                    'matches'        => $signatureMatches->count(),
                ]);

                return response()->json(['error' => 'Ambiguous telematic integration'], 409);
            }
        }

        if (!$telematic) {
            Log::warning('Unable to resolve telematic for provider webhook', [
                'correlation_id'     => $correlationId,
                'provider'           => $providerKey,
                'has_integration_id' => (bool) $telematicId,
            ]);

            return response()->json(['error' => 'Unable to resolve telematic integration'], 422);
        }

        // Validate signature
        $credentials = $this->service->getCredentials($telematic);

        if ($signature && !$provider->validateWebhookSignature($request->getContent(), $signature, $credentials)) {
            Log::warning('Invalid webhook signature', [
                'correlation_id' => $correlationId,
                'provider'       => $providerKey,
            ]);

            return response()->json(['error' => 'Invalid signature'], 403);
        }

        // Process webhook
        try {
            $result  = $provider->processWebhook($request->all(), $request->headers->all());
            $devices = $result['devices'] ?? [];
            $events  = $result['events'] ?? [];
            $sensors = $result['sensors'] ?? [];

            $devicesByExternalId = [];

            // Link devices
            foreach ($devices as $deviceData) {
                try {
                    $device     = $this->service->linkDevice($telematic, $deviceData);
                    $externalId = $deviceData['external_id'] ?? $deviceData['device_id'] ?? $deviceData['unit_id'] ?? $deviceData['vehicle_id'] ?? $deviceData['imei'] ?? null;
                    if ($externalId) {
                        $devicesByExternalId[$externalId] = $device;
                    }
                } catch (\Illuminate\Validation\ValidationException) {
                    Log::warning('Skipping webhook device without provider identity', [
                        'correlation_id' => $correlationId,
                        'provider'       => $providerKey,
                    ]);
                }
            }

            foreach ($events as $eventData) {
                $externalId = $eventData['device_id'] ?? $eventData['external_id'] ?? $eventData['ident'] ?? $eventData['unit_id'] ?? $eventData['vehicle_id'] ?? $eventData['imei'] ?? null;
                $this->service->storeDeviceEvent($telematic, $eventData, $externalId ? ($devicesByExternalId[$externalId] ?? null) : null);
            }

            foreach ($sensors as $sensorData) {
                try {
                    $externalId = $sensorData['device_id'] ?? $sensorData['external_id'] ?? $sensorData['ident'] ?? $sensorData['unit_id'] ?? $sensorData['vehicle_id'] ?? $sensorData['imei'] ?? null;
                    $this->service->storeSensor($telematic, $sensorData, $externalId ? ($devicesByExternalId[$externalId] ?? null) : null);
                } catch (\Illuminate\Validation\ValidationException) {
                    Log::warning('Skipping webhook sensor without provider or device identity', [
                        'correlation_id' => $correlationId,
                        'provider'       => $providerKey,
                    ]);
                }
            }

            // Mark as processed
            if ($idempotencyKey) {
                $this->idempotency->markProcessed($idempotencyKey);
            }

            Log::info('Webhook processed successfully', [
                'correlation_id' => $correlationId,
                'devices_count'  => count($devices),
                'events_count'   => count($events),
                'sensors_count'  => count($sensors),
            ]);

            return response()->json(['status' => 'processed'], 200);
        } catch (\Exception $e) {
            Log::error('Webhook processing failed', [
                'correlation_id' => $correlationId,
                'error'          => $e->getMessage(),
            ]);

            return response()->json(['error' => 'Processing failed'], 500);
        }
    }

    /**
     * Handle custom provider ingest.
     */
    public function ingest(Request $request, string $id): JsonResponse
    {
        $telematic     = Telematic::where('public_id', $id)->orWhere('uuid', $id)->firstOrFail();
        $correlationId = Str::uuid()->toString();

        Log::info('Custom ingest received', [
            'correlation_id' => $correlationId,
            'telematic_uuid' => $id,
        ]);

        // Check idempotency
        $idempotencyKey = $request->header('X-Idempotency-Key');
        if ($idempotencyKey && $this->idempotency->isDuplicate($idempotencyKey)) {
            return response()->json(['status' => 'duplicate'], 200);
        }

        try {
            $devicesByExternalId = [];

            // Process devices
            if ($request->has('devices')) {
                foreach ($request->input('devices') as $deviceData) {
                    try {
                        $device     = $this->service->linkDevice($telematic, $deviceData);
                        $externalId = $deviceData['external_id'] ?? $deviceData['device_id'] ?? $deviceData['unit_id'] ?? $deviceData['vehicle_id'] ?? $deviceData['imei'] ?? null;
                        if ($externalId) {
                            $devicesByExternalId[$externalId] = $device;
                        }
                    } catch (\Illuminate\Validation\ValidationException) {
                        Log::warning('Skipping custom ingest device without provider identity', [
                            'correlation_id' => $correlationId,
                        ]);
                    }
                }
            }

            foreach ($request->input('events', []) as $eventData) {
                $externalId = $eventData['device_id'] ?? $eventData['external_id'] ?? $eventData['ident'] ?? $eventData['unit_id'] ?? $eventData['vehicle_id'] ?? $eventData['imei'] ?? null;
                $this->service->storeDeviceEvent($telematic, $eventData, $externalId ? ($devicesByExternalId[$externalId] ?? null) : null);
            }

            foreach ($request->input('sensors', []) as $sensorData) {
                try {
                    $externalId = $sensorData['device_id'] ?? $sensorData['external_id'] ?? $sensorData['ident'] ?? $sensorData['unit_id'] ?? $sensorData['vehicle_id'] ?? $sensorData['imei'] ?? null;
                    $this->service->storeSensor($telematic, $sensorData, $externalId ? ($devicesByExternalId[$externalId] ?? null) : null);
                } catch (\Illuminate\Validation\ValidationException) {
                    Log::warning('Skipping custom ingest sensor without provider or device identity', [
                        'correlation_id' => $correlationId,
                    ]);
                }
            }

            // Mark as processed
            if ($idempotencyKey) {
                $this->idempotency->markProcessed($idempotencyKey);
            }

            Log::info('Custom ingest processed', [
                'correlation_id' => $correlationId,
                'devices_count'  => count($request->input('devices', [])),
                'events_count'   => count($request->input('events', [])),
                'sensors_count'  => count($request->input('sensors', [])),
            ]);

            return response()->json(['status' => 'ingested'], 200);
        } catch (\Exception $e) {
            Log::error('Custom ingest failed', [
                'correlation_id' => $correlationId,
                'error'          => $e->getMessage(),
            ]);

            return response()->json(['error' => 'Ingest failed'], 500);
        }
    }
}
