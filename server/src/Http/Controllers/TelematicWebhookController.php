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
            'correlation_id' => $correlationId,
            'provider'       => $providerKey,
            'headers'        => $request->headers->all(),
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

        // Find telematic for this provider. Provider webhooks can include an
        // integration id in the URL query or headers to disambiguate tenants.
        $telematicId = $request->query('telematic') ?? $request->query('integration') ?? $request->header('X-Fleetbase-Telematic');
        $telematic   = Telematic::where('provider', $providerKey)
            ->when($telematicId, function ($query) use ($telematicId) {
                $query->where(function ($query) use ($telematicId) {
                    $query->where('uuid', $telematicId)->orWhere('public_id', $telematicId);
                });
            })
            ->first();

        if (!$telematic) {
            Log::warning('No telematic found for provider', [
                'correlation_id' => $correlationId,
                'provider'       => $providerKey,
            ]);

            return response()->json(['error' => 'No telematic configured'], 404);
        }

        // Validate signature
        $signature   = $request->header('X-Webhook-Signature');
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
            $result = $provider->processWebhook($request->all(), $request->headers->all());

            $devicesByExternalId = [];

            // Link devices
            foreach ($result['devices'] as $deviceData) {
                $device     = $this->service->linkDevice($telematic, $deviceData);
                $externalId = $deviceData['external_id'] ?? $deviceData['device_id'] ?? null;
                if ($externalId) {
                    $devicesByExternalId[$externalId] = $device;
                }
            }

            foreach ($result['events'] as $eventData) {
                $externalId = $eventData['device_id'] ?? $eventData['external_id'] ?? $eventData['ident'] ?? null;
                $this->service->storeDeviceEvent($telematic, $eventData, $externalId ? ($devicesByExternalId[$externalId] ?? null) : null);
            }

            foreach ($result['sensors'] as $sensorData) {
                $externalId = $sensorData['device_id'] ?? $sensorData['external_id'] ?? $sensorData['ident'] ?? null;
                $this->service->storeSensor($telematic, $sensorData, $externalId ? ($devicesByExternalId[$externalId] ?? null) : null);
            }

            // Mark as processed
            if ($idempotencyKey) {
                $this->idempotency->markProcessed($idempotencyKey);
            }

            Log::info('Webhook processed successfully', [
                'correlation_id' => $correlationId,
                'devices_count'  => count($result['devices']),
                'events_count'   => count($result['events']),
                'sensors_count'  => count($result['sensors']),
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
        $telematic     = Telematic::where('uuid', $id)->orWhere('public_id', $id)->firstOrFail();
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
                    $device     = $this->service->linkDevice($telematic, $deviceData);
                    $externalId = $deviceData['external_id'] ?? $deviceData['device_id'] ?? null;
                    if ($externalId) {
                        $devicesByExternalId[$externalId] = $device;
                    }
                }
            }

            foreach ($request->input('events', []) as $eventData) {
                $externalId = $eventData['device_id'] ?? $eventData['external_id'] ?? $eventData['ident'] ?? null;
                $this->service->storeDeviceEvent($telematic, $eventData, $externalId ? ($devicesByExternalId[$externalId] ?? null) : null);
            }

            foreach ($request->input('sensors', []) as $sensorData) {
                $externalId = $sensorData['device_id'] ?? $sensorData['external_id'] ?? $sensorData['ident'] ?? null;
                $this->service->storeSensor($telematic, $sensorData, $externalId ? ($devicesByExternalId[$externalId] ?? null) : null);
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
