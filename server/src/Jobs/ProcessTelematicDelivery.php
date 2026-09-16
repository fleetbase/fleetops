<?php

namespace Fleetbase\FleetOps\Jobs;

use Fleetbase\FleetOps\Models\Telematic;
use Fleetbase\FleetOps\Support\Telematics\TelematicService;
use Fleetbase\FleetOps\Support\Telematics\Telemetry\Configuration;
use Fleetbase\FleetOps\Support\Telematics\Telemetry\Inbox;
use Fleetbase\FleetOps\Support\Telematics\Telemetry\Ingestor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class ProcessTelematicDelivery implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $uniqueFor = 300;

    public function uniqueId(): string
    {
        return $this->deliveryUuid;
    }

    public int $tries   = 1;
    public int $timeout = 60;

    public function __construct(public string $deliveryUuid)
    {
    }

    public function handle(Ingestor $ingestor, TelematicService $service): void
    {
        $lock = Cache::lock('telemetry:delivery:' . $this->deliveryUuid, 90);
        if (!$lock->get()) {
            return;
        }
        try {
            $row = DB::table('telematic_deliveries')->where('uuid', $this->deliveryUuid)->first();
            if (!$row || !in_array($row->status, ['pending', 'retry', 'processing'], true) || ($row->available_at && now()->lt($row->available_at))) {
                return;
            }
            if ($row->attempts >= 5) {
                $this->update(['status' => 'quarantined', 'failed' => max(1, $row->failed), 'error' => 'Worker retry budget exhausted; inspect and replay.']);
                Inbox::finishRun($row->run_uuid);

                return;
            }
            $telematic = Telematic::withoutGlobalScopes()->where('uuid', $row->telematic_uuid)->first();
            if (!$telematic || !Inbox::enabled($telematic)) {
                $this->update(['status' => 'quarantined', 'error' => 'Connection disabled or removed.', 'failed' => 1]);
                Inbox::finishRun($row->run_uuid);

                return;
            }
            $provider = Configuration::provider($telematic);
            $options  = Configuration::options($provider);
            if ($row->source === 'webhook' && !($options['webhooks_enabled'] ?? false)) {
                return;
            }
            $this->update(['status' => 'processing', 'attempts' => $row->attempts + 1, 'available_at' => now()->addSeconds(120)]);
            try {
                $units = $provider->telemetryUnits(json_decode(Crypt::decryptString($row->retry_payload ?? $row->payload), true, 512, JSON_THROW_ON_ERROR));
            } catch (\Throwable) {
                $this->update(['status' => 'quarantined', 'failed' => 1, 'error' => 'Unsupported or unreadable position payload; inspect and replay after adapter correction.']);
                Inbox::finishRun($row->run_uuid);

                return;
            }
            $failed       = [];
            $failureTypes = [];
            $applied      = 0;
            $invalid      = (int) $row->invalid_count;
            $sourceDelay  = null;
            $queueDelay   = max(0, now()->timestamp - \Illuminate\Support\Carbon::parse($row->received_at, 'UTC')->timestamp);
            foreach ($units as $unit) {
                try {
                    $result      = $ingestor->ingest($telematic, $provider, $unit, $service, $row->received_at, $row->source);
                    $sourceDelay = max($sourceDelay ?? 0, data_get($result['device']->meta, 'telemetry.source_delay_seconds', 0));
                    if ($result['invalid_position']) {
                        $invalid++;
                    } else {
                        $applied++;
                    }
                } catch (\Throwable $e) {
                    $failed[]       = $unit;
                    $failureTypes[] = class_basename($e);
                }
            }
            $this->update(['queue_delay_seconds' => $queueDelay, 'source_delay_seconds' => $sourceDelay]);
            if ($failed && $row->attempts < 4) {
                $this->update([
                    'status'  => 'retry', 'retry_payload' => Crypt::encryptString(json_encode($failed, JSON_THROW_ON_ERROR)),
                    'applied' => $row->applied + $applied, 'failed' => count($failed) + $invalid, 'invalid_count' => $invalid,
                    'error'   => 'Some units failed ingestion; retry scheduled. ' . implode(', ', array_unique($failureTypes)), 'available_at' => now()->addSeconds(15 * (2 ** $row->attempts)),
                ]);
            } else {
                $this->update([
                    'status'       => ($failed || $invalid) ? 'quarantined' : 'processed',
                    'applied'      => $row->applied + $applied, 'failed' => count($failed) + $invalid, 'invalid_count' => $invalid,
                    'error'        => ($failed || $invalid) ? 'Invalid positions or exhausted ingestion retries; inspect and replay.' : null,
                    'processed_at' => now(),
                ]);
            }
            Inbox::finishRun($row->run_uuid);
        } finally {
            $lock->release();
        }
    }

    private function update(array $attributes): void
    {
        DB::table('telematic_deliveries')->where('uuid', $this->deliveryUuid)->update(array_merge($attributes, ['updated_at' => now()]));
    }
}
