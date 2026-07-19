<?php

use Fleetbase\FleetOps\Http\Resources\v1\FuelProviderConnection as FuelProviderConnectionResource;
use Fleetbase\FleetOps\Http\Resources\v1\FuelProviderSyncRun as FuelProviderSyncRunResource;
use Fleetbase\FleetOps\Http\Resources\v1\FuelProviderTransaction as FuelProviderTransactionResource;
use Illuminate\Http\Request;

class FleetOpsResourceRouteFixture
{
    public function __construct(private string $uri)
    {
    }

    public function uri(): string
    {
        return $this->uri;
    }
}

function fleetopsInternalResourceRequest(): Request
{
    $request = Request::create('/int/v1/fleet-ops/resource-test');
    $request->setRouteResolver(fn () => new FleetOpsResourceRouteFixture('int/v1/fleet-ops/resource-test'));
    app()->instance('request', $request);

    return $request;
}

function fuelProviderResourceFixture(array $attributes): object
{
    return (object) $attributes;
}

test('fuel provider connection resource exposes internal identifiers and sync state', function () {
    $connection = fuelProviderResourceFixture([
        'id'              => 12,
        'uuid'            => 'connection-uuid',
        'public_id'       => 'fuel_connection_public',
        'provider'        => 'test_provider',
        'name'            => 'Main Fuel Account',
        'environment'     => 'production',
        'status'          => 'configured',
        'sync_settings'   => ['window_days' => 7],
        'last_sync_state' => ['cursor' => 'abc'],
        'last_synced_at'  => '2026-07-01 10:00:00',
        'last_tested_at'  => '2026-07-01 09:00:00',
        'last_error'      => null,
        'meta'            => ['team' => 'ops'],
        'updated_at'      => '2026-07-01 11:00:00',
        'created_at'      => '2026-07-01 08:00:00',
    ]);

    $payload = (new FuelProviderConnectionResource($connection))->toArray(fleetopsInternalResourceRequest());

    expect($payload)->toMatchArray([
        'id'              => 12,
        'uuid'            => 'connection-uuid',
        'public_id'       => 'fuel_connection_public',
        'provider'        => 'test_provider',
        'name'            => 'Main Fuel Account',
        'environment'     => 'production',
        'status'          => 'configured',
        'sync_settings'   => ['window_days' => 7],
        'last_sync_state' => ['cursor' => 'abc'],
        'meta'            => ['team' => 'ops'],
    ]);
});

test('fuel provider sync run resource exposes counters windows and totals', function () {
    $syncRun = fuelProviderResourceFixture([
        'id'                            => 55,
        'uuid'                          => 'sync-run-uuid',
        'public_id'                     => 'sync_run_public',
        'fuel_provider_connection_uuid' => 'connection-uuid',
        'provider'                      => 'test_provider',
        'status'                        => 'finished',
        'from'                          => '2026-06-01',
        'to'                            => '2026-06-30',
        'imported'                      => 10,
        'matched'                       => 7,
        'unmatched'                     => 3,
        'fuel_reports_created'          => 5,
        'liters'                        => 432.5,
        'amount'                        => 1200,
        'started_at'                    => '2026-07-01 09:00:00',
        'finished_at'                   => '2026-07-01 09:05:00',
        'error'                         => null,
        'summary'                       => ['warnings' => 1],
        'meta'                          => ['source' => 'manual'],
    ]);

    $payload = (new FuelProviderSyncRunResource($syncRun))->toArray(fleetopsInternalResourceRequest());

    expect($payload)->toMatchArray([
        'id'                            => 55,
        'uuid'                          => 'sync-run-uuid',
        'public_id'                     => 'sync_run_public',
        'fuel_provider_connection_uuid' => 'connection-uuid',
        'provider'                      => 'test_provider',
        'status'                        => 'finished',
        'imported'                      => 10,
        'matched'                       => 7,
        'unmatched'                     => 3,
        'fuel_reports_created'          => 5,
        'liters'                        => 432.5,
        'amount'                        => 1200,
        'summary'                       => ['warnings' => 1],
        'meta'                          => ['source' => 'manual'],
    ]);
});

test('fuel provider transaction resource exposes identifiers matching fields and raw payloads', function () {
    $transaction = fuelProviderResourceFixture([
        'id'                            => 99,
        'uuid'                          => 'transaction-uuid',
        'public_id'                     => 'fuel_transaction_public',
        'fuel_provider_connection_uuid' => 'connection-uuid',
        'fuel_report_uuid'              => 'fuel-report-uuid',
        'fuel_report_id'                => 'fuel_report_public',
        'vehicle_uuid'                  => 'vehicle-uuid',
        'driver_uuid'                   => 'driver-uuid',
        'order_uuid'                    => 'order-uuid',
        'provider'                      => 'test_provider',
        'provider_transaction_id'       => 'txn-123',
        'provider_vehicle_id'           => 'provider-vehicle-1',
        'vehicle_card_id'               => 'card-1',
        'internal_number'               => 'internal-1',
        'structure_number'              => 'structure-1',
        'plate_number'                  => 'ABC-123',
        'vin'                           => 'VIN123',
        'serial_number'                 => 'SER123',
        'call_sign'                     => 'CALL-1',
        'trip_number'                   => 'TRIP-1',
        'station_name'                  => 'Station A',
        'station_latitude'              => 1.25,
        'station_longitude'             => 103.75,
        'station_location'              => ['type' => 'Point', 'coordinates' => [103.75, 1.25]],
        'transaction_at'                => '2026-07-01 12:00:00',
        'volume'                        => 80,
        'metric_unit'                   => 'L',
        'amount'                        => 240,
        'currency'                      => 'USD',
        'odometer'                      => 12345,
        'sync_status'                   => 'matched',
        'matched_at'                    => '2026-07-01 12:05:00',
        'vehicle_name'                  => 'Truck 12',
        'driver_name'                   => 'Jane Driver',
        'normalized_payload'            => ['amount' => 240],
        'raw_payload'                   => ['source' => 'provider'],
        'meta'                          => ['review_status' => 'approved'],
    ]);

    $payload = (new FuelProviderTransactionResource($transaction))->toArray(fleetopsInternalResourceRequest());

    expect($payload)->toMatchArray([
        'id'                            => 99,
        'uuid'                          => 'transaction-uuid',
        'public_id'                     => 'fuel_transaction_public',
        'fuel_provider_connection_uuid' => 'connection-uuid',
        'fuel_report_uuid'              => 'fuel-report-uuid',
        'fuel_report_id'                => 'fuel_report_public',
        'vehicle_uuid'                  => 'vehicle-uuid',
        'driver_uuid'                   => 'driver-uuid',
        'order_uuid'                    => 'order-uuid',
        'provider'                      => 'test_provider',
        'provider_transaction_id'       => 'txn-123',
        'plate_number'                  => 'ABC-123',
        'station_name'                  => 'Station A',
        'station_location'              => ['type' => 'Point', 'coordinates' => [103.75, 1.25]],
        'volume'                        => 80,
        'metric_unit'                   => 'L',
        'amount'                        => 240,
        'currency'                      => 'USD',
        'odometer'                      => 12345,
        'sync_status'                   => 'matched',
        'vehicle_name'                  => 'Truck 12',
        'driver_name'                   => 'Jane Driver',
        'normalized_payload'            => ['amount' => 240],
        'raw_payload'                   => ['source' => 'provider'],
        'meta'                          => ['review_status' => 'approved'],
    ]);
});
