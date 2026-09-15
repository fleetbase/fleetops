<?php

namespace Fleetbase\FleetOps\Jobs;

use Fleetbase\FleetOps\Models\Telematic;
use Fleetbase\FleetOps\Support\Telematics\Afaqy\Inbox;
use Fleetbase\FleetOps\Support\Telematics\Afaqy\Ingestor;
use Fleetbase\FleetOps\Support\Telematics\Afaqy\Payload;
use Fleetbase\FleetOps\Support\Telematics\Providers\AfaqyProvider;
use Fleetbase\FleetOps\Support\Telematics\TelematicService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class ProcessAfaqyDelivery implements ShouldQueue, ShouldBeUnique
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
        $lock = Cache::lock('afaqy:delivery:' . $this->deliveryUuid, 90);
        if (!$lock->get()) {
            return;
        }
        try {
            $row = DB::table('afaqy_deliveries')->where('uuid', $this->deliveryUuid)->first();
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
            if ($row->source === 'webhook' && !config('telematics.afaqy.webhooks_enabled', false)) {
                return;
            }
            $this->update(['status' => 'processing', 'attempts' => $row->attempts + 1, 'available_at' => now()->addSeconds(120)]);
            try {
                $units = Payload::units(json_decode(Crypt::decryptString($row->retry_payload ?? $row->payload), true, 512, JSON_THROW_ON_ERROR));
            } catch (\Throwable) {
                $this->update(['status' => 'quarantined', 'failed' => 1, 'error' => 'Unsupported or unreadable position payload; inspect and replay after adapter correction.']);
                Inbox::finishRun($row->run_uuid);

                return;
            }
            $provider     = new AfaqyProvider(); // Normalization must never authenticate or contact AFAQY.
            $failed       = [];
            $failureTypes = [];
            $applied      = 0;
            $invalid      = (int) $row->invalid_count;
            foreach ($units as $unit) {
                try {
                    $result = $ingestor->ingest($telematic, $provider, $unit, $service, $row->received_at, $row->source);
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
        DB::table('afaqy_deliveries')->where('uuid', $this->deliveryUuid)->update(array_merge($attributes, ['updated_at' => now()]));
    }
}
