<?php

namespace Fleetbase\FleetOps\Support\Telematics\Afaqy;

use Fleetbase\FleetOps\Events\DeviceTelemetryUpdated;
use Fleetbase\FleetOps\Models\Device;
use Fleetbase\FleetOps\Models\DeviceEvent;
use Fleetbase\FleetOps\Models\Telematic;
use Fleetbase\FleetOps\Support\Telematics\Providers\AfaqyProvider;
use Fleetbase\FleetOps\Support\Telematics\TelematicService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class Ingestor
{
    public function ingest(Telematic $telematic, AfaqyProvider $provider, array $raw, TelematicService $service, ?string $receivedAt = null, string $source = 'poll'): array
    {
        $raw        = Payload::unit($raw);
        $normalized = $provider->normalizeDevice($raw);
        $event      = $provider->normalizeEvent($raw);
        $id         = $normalized['device_id'] ?? null;
        if (!$id) {
            throw new \InvalidArgumentException('Unit identity is required.');
        }
        $key = 'afaqy:device:' . hash('sha256', $telematic->uuid . '|' . $id);

        return Cache::lock($key, 60)->block(5, fn () => DB::transaction(function () use ($telematic, $provider, $raw, $service, $receivedAt, $source, $normalized, $event, $id) {
            $device            = Device::withoutGlobalScopes()->where('company_uuid', $telematic->company_uuid)->where('telematic_uuid', $telematic->uuid)->where('device_id', $id)->lockForUpdate()->first();
            $current           = data_get($device?->meta, 'afaqy.position_at') ?? data_get($device?->meta, 'last_update.occurred_at');
            $valid             = Payload::validPosition($event);
            $newer             = $valid && (!$current || Carbon::parse($event['occurred_at'])->gt(Carbon::parse($current)));
            $event['event_id'] = Payload::signalKey($event);
            // Same identity as TelematicService::makeEventKey, serialized under the device lock.
            $eventKey  = sha1(implode('|', [$telematic->provider, $telematic->public_id ?? $telematic->uuid, $id, $event['event_id'], $event['event_type'], $event['occurred_at']]));
            $duplicate = $valid && DeviceEvent::withoutGlobalScopes()->where('_key', $eventKey)->exists();
            $received  = Payload::timestamp($receivedAt ?? now()->toISOString());
            $meta      = array_filter([
                'position_at'          => $event['occurred_at'], 'provider_at' => data_get($event, 'meta.afaqy.provider_at'),
                'received_at'          => $received, 'processed_at' => now()->toISOString(), 'source' => $source,
                'queue_delay_seconds'  => max(0, now()->timestamp - Carbon::parse($received)->timestamp),
                'source_delay_seconds' => $event['occurred_at'] ? max(0, Carbon::parse($received)->timestamp - Carbon::parse($event['occurred_at'])->timestamp) : null,
                'ignition'             => $event['ignition'],
            ], fn ($value) => $value !== null);
            $ignition                    = $event['ignition'] ?? data_get($device?->meta, 'afaqy.ignition');
            $meta['stale_after_seconds'] = (int) config($ignition === true ? 'telematics.afaqy.stale_engine_on_seconds' : 'telematics.afaqy.stale_engine_off_seconds', $ignition === true ? 120 : 600);
            if (!$device || $newer) {
                $contactAt = $normalized['last_seen_at'] ?? null;
                if ($contactAt && Carbon::parse($contactAt)->gt(now()->addMinutes(5))) {
                    $contactAt = null;
                }
                if ($device?->last_online_at && (!$contactAt || Carbon::parse($contactAt)->lt($device->last_online_at))) {
                    $contactAt = $device->last_online_at->toISOString();
                }
                $normalized['last_seen_at'] = $contactAt;
                if (!$valid) {
                    unset($normalized['location'], $normalized['meta']['last_update']);
                }
                $normalized['meta'] = array_replace_recursive($device?->meta ?? [], $normalized['meta'] ?? []);
                if ($newer) {
                    $normalized['meta']['afaqy'] = array_replace(data_get($device?->meta, 'afaqy', []), $meta);
                }
                $normalized['meta'] = array_replace_recursive($device?->meta ?? [], array_filter($normalized['meta'], fn ($v) => $v !== null));
                $device             = $service->linkDevice($telematic, $normalized);
            }
            // Last contact can advance independently of an old/repeated GPS fix.
            $contactChanged = false;
            $contact        = $event['last_seen_at'];
            if ($contact && Carbon::parse($contact)->lte(now()->addMinutes(5)) && (!$device->last_online_at || Carbon::parse($contact)->gt($device->last_online_at))) {
                $device->last_online_at = Carbon::parse($contact)->utc()->toDateTimeString();
                $device->online         = $device->last_online_at->gt(now()->subMinutes(10));
                if (in_array($device->status, ['online', 'recently_offline', 'offline', 'long_offline', 'never_connected'], true)) {
                    $minutes        = $device->last_online_at->diffInMinutes(now());
                    $device->status = $minutes <= 10 ? 'online' : ($minutes <= 60 ? 'recently_offline' : ($minutes <= 1440 ? 'offline' : 'long_offline'));
                }
                $device->meta           = array_replace_recursive($device->meta ?? [], ['afaqy' => ['provider_at' => $contact, 'last_received_at' => $received]]);
                $device->save();
                $contactChanged = true;
            }
            $stored = null;
            if ($valid && !$duplicate) {
                $event['_history_only'] = !$newer;
                $event['meta']['afaqy'] = $meta;
                // Do not replace the higher contact watermark with delayed transmission time.
                $event['last_seen_at'] = $device->last_online_at?->toISOString();
                $stored                = $service->storeDeviceEvent($telematic, $event, $device);
            }
            $sensors    = 0;
            $rawSensors = $raw['sensors'] ?? $raw['sensors_last_val'] ?? [];
            // sensors_chDate is undocumented as a value collection; never synthesize sensors from it.
            foreach (is_array($rawSensors) ? $rawSensors : [] as $name => $sensor) {
                if (!is_array($sensor)) {
                    $sensor = ['sensor_key' => (string) $name, 'name' => (string) $name, 'value' => $sensor];
                }
                try {
                    $sensor = $provider->normalizeSensor(array_merge(['device_id' => $id, 'sensor_key' => is_string($name) ? $name : null, 'updated_at' => $event['occurred_at']], $sensor));
                } catch (\InvalidArgumentException) {
                    continue; // Non-value sensor descriptors are not readings.
                }
                if (!$sensor['recorded_at'] || Carbon::parse($sensor['recorded_at'])->gt(now()->addMinutes(5))) {
                    continue;
                }
                $service->storeSensor($telematic, $sensor, $device);
                $sensors++;
            }
            if ($newer || $contactChanged) {
                $snapshot = $device->fresh() ?? $device;
                DB::afterCommit(fn () => broadcast(new DeviceTelemetryUpdated($snapshot)));
            }

            return ['device' => $device, 'event' => $stored, 'events' => $stored ? [$stored] : [], 'sensors' => $sensors, 'duplicate' => $duplicate, 'invalid_position' => !$valid];
        }, 3));
    }
}
