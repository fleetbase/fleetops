<?php

namespace Fleetbase\FleetOps\Http\Controllers;

use Fleetbase\FleetOps\Models\Telematic;
use Fleetbase\FleetOps\Support\Telematics\Telemetry\Configuration;
use Fleetbase\FleetOps\Support\Telematics\Telemetry\Inbox;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class TelematicPositionWebhookController extends Controller
{
    public function handle(Request $request, Inbox $inbox, string $providerKey): JsonResponse
    {
        if (!$request->isMethod('post')) {
            return response()->json(['error' => 'POST required.'], 405);
        }
        $id    = $request->query('telematic');
        $token = $request->query('key');
        if (!is_string($id) || !is_string($token)) {
            return response()->json(['error' => 'Invalid webhook credentials.'], 403);
        }
        $telematic = Telematic::withoutGlobalScopes()->where('provider', $providerKey)->where('public_id', $id)->first();
        $stored    = $telematic ? DB::table('telematic_webhook_credentials')->where('telematic_uuid', $telematic->uuid)->value('token') : null;
        if (!$stored || !hash_equals(Crypt::decryptString($stored), $token) || !Inbox::enabled($telematic)) {
            return response()->json(['error' => 'Invalid webhook credentials.'], 403);
        }
        $options = Configuration::forConnection($telematic);
        if (!($options['webhooks_enabled'] ?? false)) {
            return response()->json(['error' => 'Webhook processing is disabled.'], 503);
        }
        $body = $request->getContent();
        if (strlen($body) > ($options['max_payload_bytes'] ?? 2097152)) {
            return response()->json(['error' => 'Payload too large.'], 413);
        }
        try {
            $payload = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($payload)) {
                throw new \InvalidArgumentException();
            }
        } catch (\Throwable) {
            return response()->json(['error' => 'JSON object or array required.'], 422);
        }
        try {
            // Per-connection pressure protection; The provider can retry after capacity recovers.
            $pending = DB::table('telematic_deliveries')->where('telematic_uuid', $telematic->uuid)->whereIn('status', ['pending', 'processing', 'retry'])->count();
            if ($pending >= ($options['max_pending_deliveries'] ?? 10000)) {
                return response()->json(['error' => 'Ingestion capacity exceeded.'], 503);
            }
            $delivery = $inbox->accept($telematic, $payload, 'webhook');

            return response()->json(['status' => 'accepted', 'delivery_id' => $delivery], 200);
        } catch (\Throwable) {
            return response()->json(['error' => 'Unable to persist delivery; retry required.'], 503);
        }
    }
}
