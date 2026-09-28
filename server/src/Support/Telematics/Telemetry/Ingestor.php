<?php

namespace Fleetbase\FleetOps\Support\Telematics\Telemetry;

use Fleetbase\FleetOps\Contracts\TelemetryProviderInterface;
use Fleetbase\FleetOps\Events\DeviceTelemetryUpdated;
use Fleetbase\FleetOps\Models\Device;
use Fleetbase\FleetOps\Models\DeviceEvent;
use Fleetbase\FleetOps\Models\Telematic;
use Fleetbase\FleetOps\Support\Telematics\Retention\TelemetryActivity;
use Fleetbase\FleetOps\Support\Telematics\TelematicService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class Ingestor
{
    public function ingest(Telematic $telematic, TelemetryProviderInterface $provider, array $raw, TelematicService $service, ?string $receivedAt = null, string $source = 'poll'): array
    {
        $sample                = $provider->normalizeTelemetrySnapshot($raw);
        $normalized            = $sample['device'];
        $event                 = $sample['event'];
        $event['occurred_at']  = Sample::timestamp($event['occurred_at'] ?? null);
        $event['last_seen_at'] = Sample::timestamp($event['last_seen_at'] ?? $event['occurred_at']);
        $event['ignition'] ??= null;
        $normalized['last_seen_at']       = Sample::timestamp($normalized['last_seen_at'] ?? $event['last_seen_at']);
        $options                          = Configuration::options($provider);
        $normalized['_ordered_telemetry'] = true;
        $event['_ordered_telemetry']      = true;
        $id                               = $normalized['device_id'] ?? null;
        if (!$id) {
            throw new \InvalidArgumentException('Unit identity is required.');
        }
        $key = 'telemetry:device:' . hash('sha256', $telematic->uuid . '|' . $id);

        // Telemetry saves (device, event, position, vehicle, sensors) only reach the activity log when the company opts in.
        return Cache::lock($key, 60)->block(5, fn () => TelemetryActivity::run($telematic->company_uuid, fn () => DB::transaction(function () use ($telematic, $sample, $options, $service, $receivedAt, $source, $normalized, $event, $id) {
            $device            = Device::withoutGlobalScopes()->where('company_uuid', $telematic->company_uuid)->where('telematic_uuid', $telematic->uuid)->where('device_id', $id)->lockForUpdate()->first();
            $current           = data_get($device?->meta, 'telemetry.position_at') ?? data_get($device?->meta, 'last_update.occurred_at');
            $valid             = Sample::validPosition($event);
            $newer             = $valid && (!$current || Carbon::parse($event['occurred_at'])->gt(Carbon::parse($current)));
            $event['event_id'] = Sample::signalKey($event);
            // Same identity as TelematicService::makeEventKey, serialized under the device lock.
            $eventKey  = sha1(implode('|', [$telematic->provider, $telematic->public_id ?? $telematic->uuid, $id, $event['event_id'], $event['event_type'], $event['occurred_at']]));
            $duplicate = $valid && DeviceEvent::withoutGlobalScopes()->where('_key', $eventKey)->exists();
            $received  = Sample::timestamp($receivedAt ?? now()->toISOString());
            $meta      = array_filter([
                'position_at'          => $event['occurred_at'], 'provider_at' => data_get($event, 'meta.telemetry.provider_at'),
                'received_at'          => $received, 'processed_at' => now()->toISOString(), 'source' => $source,
                'queue_delay_seconds'  => max(0, now()->timestamp - Carbon::parse($received)->timestamp),
                'source_delay_seconds' => $event['occurred_at'] ? max(0, Carbon::parse($received)->timestamp - Carbon::parse($event['occurred_at'])->timestamp) : null,
                'ignition'             => $event['ignition'],
            ], fn ($value) => $value !== null);
            $ignition                    = $event['ignition'] ?? data_get($device?->meta, 'telemetry.ignition');
            $meta['stale_after_seconds'] = (int) ($options[$ignition === true ? 'stale_engine_on_seconds' : 'stale_engine_off_seconds'] ?? ($ignition === true ? 120 : 600));
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
                    $normalized['meta']['telemetry'] = array_replace(data_get($device?->meta, 'telemetry', []), $meta);
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
                $device->meta           = array_replace_recursive($device->meta ?? [], ['telemetry' => ['provider_at' => $contact, 'last_received_at' => $received]]);
                $device->save();
                $contactChanged = true;
            }
            $stored = null;
            if ($valid && !$duplicate) {
                $event['_history_only']     = !$newer;
                $event['meta']['telemetry'] = $meta;
                // Do not replace the higher contact watermark with delayed transmission time.
                $event['last_seen_at'] = $device->last_online_at?->toISOString();
                $stored                = $service->storeDeviceEvent($telematic, $event, $device);
            }
            $sensors    = 0;
            foreach ($sample['sensors'] ?? [] as $sensor) {
                $sensor['_ordered_telemetry'] = true;
                if (!$sensor['recorded_at'] || Carbon::parse($sensor['recorded_at'])->gt(now()->addMinutes(5))) {
                    continue;
                }
                $service->storeSensor($telematic, $sensor, $device);
                $sensors++;
            }
            if ($newer || $contactChanged) {
                $snapshot = $device->fresh() ?? $device;
                DB::afterCommit(fn () => broadcast(Configuration::withBroadcastQueue(new DeviceTelemetryUpdated($snapshot))));
            }

            return ['device' => $device, 'event' => $stored, 'events' => $stored ? [$stored] : [], 'sensors' => $sensors, 'duplicate' => $duplicate, 'invalid_position' => !$valid];
        }, 3)));
    }
}
