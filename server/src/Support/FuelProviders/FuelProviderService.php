<?php

namespace Fleetbase\FleetOps\Support\FuelProviders;

use Fleetbase\FleetOps\Events\FuelProviderTransactionImported;
use Fleetbase\FleetOps\Events\FuelProviderTransactionMatched;
use Fleetbase\FleetOps\Events\FuelProviderTransactionUnmatched;
use Fleetbase\FleetOps\Events\FuelReportCreatedFromProvider;
use Fleetbase\FleetOps\Models\FuelProviderConnection;
use Fleetbase\FleetOps\Models\FuelProviderTransaction;
use Fleetbase\FleetOps\Models\FuelReport;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FuelProviderService
{
    public function __construct(protected FuelProviderRegistry $registry)
    {
    }

    public function providers(): Collection
    {
        return $this->registry->all()->map(fn ($provider) => $provider->toArray())->values();
    }

    public function testConnection(FuelProviderConnection $connection): array
    {
        $result = $this->registry->resolve($connection->provider)->testConnection($connection);

        $connection->update([
            'status' => data_get($result, 'success') ? 'connected' : 'error',
            'last_tested_at' => now(),
            'last_error' => data_get($result, 'success') ? null : data_get($result, 'message'),
        ]);

        return $result;
    }

    public function syncTransactions(FuelProviderConnection $connection, ?Carbon $from = null, ?Carbon $to = null, array $options = []): array
    {
        $settings = (array) $connection->sync_settings;
        $from ??= Carbon::parse(data_get($settings, 'from', $connection->last_synced_at?->copy()->subDay() ?? now()->subDays(7)));
        $to ??= Carbon::parse(data_get($settings, 'to', now()));

        $provider = $this->registry->resolve($connection->provider);
        $payloads = $provider->listTransactions($connection, $from, $to, $options);

        $summary = [
            'imported' => 0,
            'matched' => 0,
            'unmatched' => 0,
            'fuel_reports_created' => 0,
        ];

        foreach ($payloads as $payload) {
            $transaction = $this->ingestTransaction($connection, $payload);
            $summary['imported']++;

            if ($transaction->sync_status === 'matched') {
                $summary['matched']++;
            } else {
                $summary['unmatched']++;
            }

            if ($transaction->wasRecentlyCreated || $transaction->fuel_report_uuid) {
                $summary['fuel_reports_created']++;
            }
        }

        $connection->update([
            'status' => 'active',
            'last_synced_at' => now(),
            'last_error' => null,
            'last_sync_state' => [
                'from' => $from->toIso8601String(),
                'to' => $to->toIso8601String(),
                'summary' => $summary,
            ],
        ]);

        return $summary;
    }

    public function ingestTransaction(FuelProviderConnection $connection, array $payload): FuelProviderTransaction
    {
        return DB::transaction(function () use ($connection, $payload) {
            $provider = $payload['provider'] ?? $connection->provider;
            $providerTransactionId = $payload['provider_transaction_id'];
            $transaction = FuelProviderTransaction::updateOrCreate(
                ['provider' => $provider, 'provider_transaction_id' => $providerTransactionId],
                array_merge($payload, [
                    'company_uuid' => $connection->company_uuid,
                    'fuel_provider_connection_uuid' => $connection->uuid,
                    'sync_status' => 'imported',
                ])
            );

            event(new FuelProviderTransactionImported($transaction));

            $this->matchTransaction($transaction);
            $fuelReport = $this->ensureFuelReport($transaction);

            if ($fuelReport && !$transaction->fuel_report_uuid) {
                $transaction->fuel_report_uuid = $fuelReport->uuid;
            }

            if ($transaction->vehicle_uuid) {
                $transaction->sync_status = 'matched';
                $transaction->matched_at ??= now();
                $transaction->save();
                event(new FuelProviderTransactionMatched($transaction));
            } else {
                $transaction->sync_status = 'unmatched';
                $transaction->save();
                event(new FuelProviderTransactionUnmatched($transaction));
            }

            return $transaction->fresh(['vehicle', 'driver', 'fuelReport']);
        });
    }

    protected function matchTransaction(FuelProviderTransaction $transaction): void
    {
        if (!$transaction->vehicle_uuid) {
            $vehicle = $this->resolveVehicle($transaction);
            if ($vehicle) {
                $transaction->vehicle_uuid = $vehicle->uuid;
            }
        }

        if (!$transaction->order_uuid && $transaction->trip_number) {
            $order = Order::where('company_uuid', $transaction->company_uuid)
                ->where(function ($query) use ($transaction) {
                    $query->where('public_id', $transaction->trip_number)
                        ->orWhere('internal_id', $transaction->trip_number)
                        ->orWhereHas('trackingNumber', function ($trackingNumberQuery) use ($transaction) {
                            $trackingNumberQuery->where('tracking_number', $transaction->trip_number);
                        });
                })->first();

            if ($order) {
                $transaction->order_uuid = $order->uuid;
            }
        }
    }

    protected function resolveVehicle(FuelProviderTransaction $transaction): ?Vehicle
    {
        $identifiers = collect([
            $transaction->provider_vehicle_id,
            $transaction->internal_number,
            $transaction->structure_number,
            $transaction->plate_number,
            $transaction->vehicle_card_id,
        ])->filter()->unique()->values();

        foreach ($identifiers as $identifier) {
            $normalized = $this->normalizeIdentifier($identifier);
            $vehicle = Vehicle::where('company_uuid', $transaction->company_uuid)
                ->where(function ($query) use ($identifier, $normalized) {
                    $query->where('uuid', $identifier)
                        ->orWhere('public_id', $identifier)
                        ->orWhere('internal_id', $identifier)
                        ->orWhere('plate_number', $identifier)
                        ->orWhere('vin', $identifier)
                        ->orWhereRaw("replace(plate_number, ' ', '') = ?", [$normalized])
                        ->orWhereRaw("replace(internal_id, ' ', '') = ?", [$normalized]);
                })->first();

            if ($vehicle) {
                return $vehicle;
            }
        }

        return null;
    }

    protected function ensureFuelReport(FuelProviderTransaction $transaction): ?FuelReport
    {
        if ($transaction->fuel_report_uuid) {
            return FuelReport::where('uuid', $transaction->fuel_report_uuid)->first();
        }

        if (!$transaction->vehicle_uuid) {
            return null;
        }

        $location = null;
        if ($transaction->station_latitude && $transaction->station_longitude) {
            $location = new Point($transaction->station_latitude, $transaction->station_longitude);
        }

        $fuelReport = FuelReport::create([
            'company_uuid' => $transaction->company_uuid,
            'vehicle_uuid' => $transaction->vehicle_uuid,
            'driver_uuid' => $transaction->driver_uuid,
            'report' => trim("Imported {$transaction->provider} fuel transaction {$transaction->provider_transaction_id}"),
            'odometer' => $transaction->odometer,
            'amount' => $transaction->amount,
            'currency' => $transaction->currency,
            'volume' => $transaction->volume,
            'metric_unit' => $transaction->metric_unit,
            'status' => 'processed',
            'location' => Utils::parsePointToWkt($location ?? new Point(0, 0)),
            'meta' => [
                'source' => 'fuel_provider',
                'provider' => $transaction->provider,
                'fuel_provider_transaction_uuid' => $transaction->uuid,
                'station_name' => $transaction->station_name,
                'trip_number' => $transaction->trip_number,
            ],
        ]);

        $transaction->fuel_report_uuid = $fuelReport->uuid;
        event(new FuelReportCreatedFromProvider($transaction, $fuelReport));

        return $fuelReport;
    }

    protected function normalizeIdentifier(string $identifier): string
    {
        return Str::of($identifier)->replace(' ', '')->upper()->toString();
    }
}
