<?php

namespace Fleetbase\FleetOps\Support\FuelProviders;

use Fleetbase\FleetOps\Events\FuelProviderTransactionImported;
use Fleetbase\FleetOps\Events\FuelProviderTransactionMatched;
use Fleetbase\FleetOps\Events\FuelProviderTransactionUnmatched;
use Fleetbase\FleetOps\Events\FuelReportCreatedFromProvider;
use Fleetbase\FleetOps\Models\FuelProviderConnection;
use Fleetbase\FleetOps\Models\FuelProviderSyncRun;
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
    protected const DEFAULT_MATCHING_ORDER = ['plate_number', 'internal_id', 'vin', 'serial_number', 'call_sign', 'fuel_card_number', 'trip_number'];

    protected const LEGACY_DEFAULT_MATCHING_ORDER = ['provider_vehicle_id', 'internal_number', 'structure_number', 'plate_number', 'vehicle_card_id', 'trip_number'];

    protected const ALLOWED_MATCHING_FIELDS = ['plate_number', 'internal_id', 'vin', 'serial_number', 'call_sign', 'fuel_card_number', 'trip_number', 'provider_vehicle_id', 'structure_number'];

    protected const LEGACY_MATCHING_FIELDS = [
        'internal_number' => 'internal_id',
        'vehicle_card_id' => 'fuel_card_number',
    ];

    protected const TRANSACTION_FIELDS = [
        'plate_number'      => 'plate_number',
        'internal_id'       => 'internal_number',
        'vin'               => 'vin',
        'serial_number'     => 'serial_number',
        'call_sign'         => 'call_sign',
        'fuel_card_number'  => 'vehicle_card_id',
    ];

    protected const VEHICLE_FIELDS = [
        'plate_number'      => 'plate_number',
        'internal_id'       => 'internal_id',
        'vin'               => 'vin',
        'serial_number'     => 'serial_number',
        'call_sign'         => 'call_sign',
        'fuel_card_number'  => 'fuel_card_number',
    ];

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
            'status'         => data_get($result, 'success') ? 'connected' : 'error',
            'last_tested_at' => now(),
            'last_error'     => data_get($result, 'success') ? null : data_get($result, 'message'),
        ]);

        return $result;
    }

    public function testCredentials(string $providerKey, array $credentials, string $environment = 'production'): array
    {
        $connection = new FuelProviderConnection([
            'company_uuid' => session('company'),
            'provider'     => $providerKey,
            'environment'  => $environment,
            'status'       => 'draft',
            'credentials'  => $credentials,
        ]);

        return $this->registry->resolve($providerKey)->testConnection($connection);
    }

    public function syncTransactions(FuelProviderConnection $connection, ?Carbon $from = null, ?Carbon $to = null, array $options = [], ?FuelProviderSyncRun $syncRun = null): array
    {
        $settings = (array) $connection->sync_settings;
        $from ??= Carbon::parse(data_get($settings, 'from', $connection->last_synced_at?->copy()->subDay() ?? now()->subDays((int) data_get($settings, 'window_days', 7))));
        $to ??= Carbon::parse(data_get($settings, 'to', now()));

        $syncRun ??= $this->createSyncRun($connection, $from, $to, 'running');
        $syncRun->update(['status' => 'running', 'started_at' => now(), 'error' => null]);

        $summary = [
            'imported'             => 0,
            'matched'              => 0,
            'unmatched'            => 0,
            'fuel_reports_created' => 0,
            'liters'               => 0,
            'amount'               => 0,
        ];

        try {
            $provider = $this->registry->resolve($connection->provider);
            $payloads = $provider->listTransactions($connection, $from, $to, $options);

            foreach ($payloads as $payload) {
                $transaction = $this->ingestTransaction($connection, $payload);
                $summary['imported']++;
                $summary['liters'] += (float) $transaction->volume;
                $summary['amount'] += (int) $transaction->amount;

                if ($transaction->sync_status === 'matched') {
                    $summary['matched']++;
                } else {
                    $summary['unmatched']++;
                }

                if ($transaction->fuel_report_uuid) {
                    $summary['fuel_reports_created']++;
                }
            }
        } catch (\Throwable $e) {
            $syncRun->update(['status' => 'error', 'finished_at' => now(), 'error' => $e->getMessage(), 'summary' => $summary]);
            $connection->update(['status' => 'error', 'last_error' => $e->getMessage()]);
            throw $e;
        }

        $connection->update([
            'status'          => 'active',
            'last_synced_at'  => now(),
            'last_error'      => null,
            'last_sync_state' => [
                'from'    => $from->toIso8601String(),
                'to'      => $to->toIso8601String(),
                'summary' => $summary,
            ],
        ]);

        $syncRun->update([
            'status'               => 'completed',
            'finished_at'          => now(),
            'imported'             => $summary['imported'],
            'matched'              => $summary['matched'],
            'unmatched'            => $summary['unmatched'],
            'fuel_reports_created' => $summary['fuel_reports_created'],
            'liters'               => $summary['liters'],
            'amount'               => $summary['amount'],
            'summary'              => $summary,
            'error'                => null,
        ]);

        return $summary;
    }

    public function createSyncRun(FuelProviderConnection $connection, ?Carbon $from = null, ?Carbon $to = null, string $status = 'queued'): FuelProviderSyncRun
    {
        return FuelProviderSyncRun::create([
            'company_uuid'                   => $connection->company_uuid,
            'fuel_provider_connection_uuid'  => $connection->uuid,
            'provider'                       => $connection->provider,
            'status'                         => $status,
            'from'                           => $from,
            'to'                             => $to,
        ]);
    }

    public function ingestTransaction(FuelProviderConnection $connection, array $payload): FuelProviderTransaction
    {
        return DB::transaction(function () use ($connection, $payload) {
            $provider              = $payload['provider'] ?? $connection->provider;
            $providerTransactionId = $payload['provider_transaction_id'];
            $transaction           = FuelProviderTransaction::updateOrCreate(
                ['provider' => $provider, 'provider_transaction_id' => $providerTransactionId],
                array_merge($payload, [
                    'company_uuid'                  => $connection->company_uuid,
                    'fuel_provider_connection_uuid' => $connection->uuid,
                    'sync_status'                   => 'imported',
                ])
            );

            event(new FuelProviderTransactionImported($transaction));

            $this->matchTransaction($transaction, $connection);
            $fuelReport = $this->shouldCreateFuelReport($connection) ? $this->ensureFuelReport($transaction) : null;

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

    public function matchVehicle(FuelProviderTransaction $transaction, Vehicle|string $vehicle): FuelProviderTransaction
    {
        if (is_string($vehicle)) {
            $vehicle = Vehicle::where('company_uuid', $transaction->company_uuid)
                ->where(function ($query) use ($vehicle) {
                    $query->where('uuid', $vehicle)->orWhere('public_id', $vehicle)->orWhere('internal_id', $vehicle);
                })->firstOrFail();
        }

        $transaction->vehicle_uuid = $vehicle->uuid;
        $transaction->sync_status  = 'matched';
        $transaction->matched_at ??= now();
        $transaction->save();
        $this->ensureFuelReport($transaction);

        event(new FuelProviderTransactionMatched($transaction));

        return $transaction->fresh(['vehicle', 'driver', 'fuelReport']);
    }

    public function matchOrder(FuelProviderTransaction $transaction, Order|string $order): FuelProviderTransaction
    {
        if (is_string($order)) {
            $order = Order::where('company_uuid', $transaction->company_uuid)
                ->where(function ($query) use ($order) {
                    $query->where('uuid', $order)->orWhere('public_id', $order)->orWhere('internal_id', $order);
                })->firstOrFail();
        }

        $transaction->order_uuid = $order->uuid;
        $transaction->save();

        return $transaction->fresh(['vehicle', 'driver', 'fuelReport']);
    }

    public function reprocessTransaction(FuelProviderTransaction $transaction): FuelProviderTransaction
    {
        $transaction->sync_status = 'imported';
        $transaction->save();

        $this->matchTransaction($transaction, $transaction->connection);
        if ($transaction->vehicle_uuid) {
            $this->ensureFuelReport($transaction);
            $transaction->sync_status = 'matched';
            $transaction->matched_at ??= now();
            event(new FuelProviderTransactionMatched($transaction));
        } else {
            $transaction->sync_status = 'unmatched';
            event(new FuelProviderTransactionUnmatched($transaction));
        }

        $transaction->save();

        return $transaction->fresh(['vehicle', 'driver', 'fuelReport']);
    }

    public function reviewTransaction(FuelProviderTransaction $transaction, string $status): FuelProviderTransaction
    {
        if (!in_array($status, ['reviewed', 'ignored'], true)) {
            throw new \InvalidArgumentException('Fuel transaction review status must be reviewed or ignored.');
        }

        $meta                  = (array) $transaction->meta;
        $meta['reviewed_at']   = now()->toIso8601String();
        $meta['review_status'] = $status;

        $transaction->sync_status = $status;
        $transaction->meta        = $meta;
        $transaction->save();

        return $transaction;
    }

    protected function matchTransaction(FuelProviderTransaction $transaction, ?FuelProviderConnection $connection = null): void
    {
        foreach ($this->matchingOrder($connection) as $field) {
            if ($field === 'trip_number') {
                if (!$transaction->order_uuid && $transaction->trip_number) {
                    $order = $this->resolveOrder($transaction);
                    if ($order) {
                        $transaction->order_uuid = $order->uuid;
                    }
                }

                continue;
            }

            if (!$transaction->vehicle_uuid) {
                $vehicle = $this->resolveVehicle($transaction, $field);
                if ($vehicle) {
                    $transaction->vehicle_uuid = $vehicle->uuid;
                }
            }
        }
    }

    protected function matchingOrder(?FuelProviderConnection $connection = null): Collection
    {
        $configuredFields = collect(data_get((array) $connection?->sync_settings, 'matching_order', []))->filter()->values();

        if ($configuredFields->all() === self::LEGACY_DEFAULT_MATCHING_ORDER) {
            return collect(self::DEFAULT_MATCHING_ORDER);
        }

        $fields  = $configuredFields
            ->map(fn ($field) => $this->normalizeMatchingField($field))
            ->filter(fn ($field) => in_array($field, self::ALLOWED_MATCHING_FIELDS, true))
            ->unique()
            ->values();

        if ($fields->isNotEmpty()) {
            return $fields;
        }

        return collect(self::DEFAULT_MATCHING_ORDER);
    }

    protected function resolveOrder(FuelProviderTransaction $transaction): ?Order
    {
        return Order::where('company_uuid', $transaction->company_uuid)
            ->where(function ($query) use ($transaction) {
                $query->where('public_id', $transaction->trip_number)
                    ->orWhere('internal_id', $transaction->trip_number)
                    ->orWhereHas('trackingNumber', function ($trackingNumberQuery) use ($transaction) {
                        $trackingNumberQuery->where('tracking_number', $transaction->trip_number);
                    });
            })->first();
    }

    protected function resolveVehicle(FuelProviderTransaction $transaction, string $field): ?Vehicle
    {
        if ($field === 'provider_vehicle_id') {
            return $this->resolveVehicleByProviderId($transaction);
        }

        if ($field === 'structure_number') {
            return $this->resolveVehicleByColumns($transaction, 'structure_number', ['serial_number', 'internal_id', 'fuel_card_number']);
        }

        $transactionField = self::TRANSACTION_FIELDS[$field] ?? null;
        $vehicleField     = self::VEHICLE_FIELDS[$field] ?? null;

        if (!$transactionField || !$vehicleField) {
            return null;
        }

        return $this->resolveVehicleByColumns($transaction, $transactionField, [$vehicleField]);
    }

    protected function resolveVehicleByColumns(FuelProviderTransaction $transaction, string $transactionField, array $vehicleFields): ?Vehicle
    {
        $identifier = $transaction->{$transactionField};
        if (!$identifier) {
            return null;
        }

        $normalized = $this->normalizeIdentifier($identifier);

        return Vehicle::where('company_uuid', $transaction->company_uuid)
            ->where(function ($query) use ($identifier, $normalized, $vehicleFields) {
                foreach ($vehicleFields as $vehicleField) {
                    $query->orWhere($vehicleField, $identifier)
                        ->orWhereRaw("REPLACE(REPLACE(UPPER({$vehicleField}), ' ', ''), '-', '') = ?", [$normalized]);
                }
            })
            ->first();
    }

    protected function resolveVehicleByProviderId(FuelProviderTransaction $transaction): ?Vehicle
    {
        if (!$transaction->provider_vehicle_id) {
            return null;
        }

        return Vehicle::where('company_uuid', $transaction->company_uuid)
            ->where(function ($query) use ($transaction) {
                $query->where('meta->fuel_provider_vehicle_id', $transaction->provider_vehicle_id)
                    ->orWhere("meta->fuel_provider_ids->{$transaction->provider}", $transaction->provider_vehicle_id);
            })
            ->first();
    }

    protected function normalizeMatchingField(string $field): string
    {
        return self::LEGACY_MATCHING_FIELDS[$field] ?? $field;
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
            'driver_uuid'  => $transaction->driver_uuid,
            'report'       => trim("Imported {$transaction->provider} fuel transaction {$transaction->provider_transaction_id}"),
            'odometer'     => $transaction->odometer,
            'amount'       => $transaction->amount,
            'currency'     => $transaction->currency,
            'volume'       => $transaction->volume,
            'metric_unit'  => $transaction->metric_unit,
            'status'       => 'processed',
            'location'     => Utils::parsePointToWkt($location ?? new Point(0, 0)),
            'meta'         => [
                'source'                         => 'fuel_provider',
                'provider'                       => $transaction->provider,
                'fuel_provider_transaction_uuid' => $transaction->uuid,
                'station_name'                   => $transaction->station_name,
                'trip_number'                    => $transaction->trip_number,
            ],
        ]);

        $transaction->fuel_report_uuid = $fuelReport->uuid;
        event(new FuelReportCreatedFromProvider($transaction, $fuelReport));

        return $fuelReport;
    }

    protected function shouldCreateFuelReport(FuelProviderConnection $connection): bool
    {
        return (bool) data_get((array) $connection->sync_settings, 'auto_create_fuel_reports', true);
    }

    protected function normalizeIdentifier(string $identifier): string
    {
        return Str::of($identifier)->replace([' ', '-'], '')->upper()->toString();
    }
}
