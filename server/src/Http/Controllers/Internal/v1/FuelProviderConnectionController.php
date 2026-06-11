<?php

namespace Fleetbase\FleetOps\Http\Controllers\Internal\v1;

use Fleetbase\FleetOps\Http\Controllers\FleetOpsController;
use Fleetbase\FleetOps\Jobs\SyncFuelProviderTransactionsJob;
use Fleetbase\FleetOps\Models\FuelProviderConnection;
use Fleetbase\FleetOps\Support\FuelProviders\FuelProviderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class FuelProviderConnectionController extends FleetOpsController
{
    public $resource = 'fuel_provider_connection';

    public function __construct(protected FuelProviderService $fuelProviderService)
    {
        parent::__construct();
    }

    public function providers(): JsonResponse
    {
        return response()->json($this->fuelProviderService->providers());
    }

    public function onBeforeCreate(Request $request, array &$input)
    {
        $this->validateConnectionInput($input);
        $this->normalizeConnectionInput($input);
    }

    public function onBeforeUpdate(Request $request, FuelProviderConnection $connection, array &$input)
    {
        $candidate = array_merge($connection->toArray(), $input);
        $this->validateConnectionInput($candidate, $connection);
        $this->normalizeConnectionInput($input, $connection);
    }

    public function testCredentials(Request $request, string $provider): JsonResponse
    {
        $request->validate([
            'credentials' => 'required|array',
            'environment' => 'nullable|string|in:production,sandbox',
        ]);

        $result = $this->fuelProviderService->testCredentials(
            $provider,
            $request->array('credentials'),
            $request->input('environment', 'production')
        );

        return response()->json($result, data_get($result, 'success') ? 200 : 422);
    }

    public function testConnection(Request $request, string $id): JsonResponse
    {
        $connection = $this->findConnection($id);
        $result     = $this->fuelProviderService->testConnection($connection);

        return response()->json($result, data_get($result, 'success') ? 200 : 422);
    }

    public function sync(Request $request, string $id): JsonResponse
    {
        $connection = $this->findConnection($id);
        $async      = $request->boolean('async', true);
        $from       = $request->input('from') ? Carbon::parse($request->input('from')) : null;
        $to         = $request->input('to') ? Carbon::parse($request->input('to')) : null;
        $options    = $request->array('options', []);
        $syncRun    = $this->fuelProviderService->createSyncRun($connection, $from, $to, $async ? 'queued' : 'running');

        if ($async) {
            SyncFuelProviderTransactionsJob::dispatch($connection->uuid, $from?->toIso8601String(), $to?->toIso8601String(), $options, $syncRun->uuid);

            return response()->json(['status' => 'ok', 'message' => 'Fuel provider sync queued.', 'sync_run' => $syncRun], 202);
        }

        $summary = $this->fuelProviderService->syncTransactions($connection, $from, $to, $options, $syncRun);

        return response()->json(['status' => 'ok', 'summary' => $summary, 'sync_run' => $syncRun->fresh()]);
    }

    protected function validateConnectionInput(array $input, ?FuelProviderConnection $connection = null): void
    {
        $provider = data_get($input, 'provider');
        if (!$provider) {
            throw ValidationException::withMessages(['provider' => 'Fuel integration provider is required.']);
        }

        $descriptor = $this->fuelProviderService->providers()->firstWhere('key', $provider);
        if (!$descriptor) {
            throw ValidationException::withMessages(['provider' => 'Fuel integration provider is not registered.']);
        }

        foreach ((array) data_get($descriptor, 'required_fields', []) as $field) {
            if (data_get($field, 'required') && blank(data_get($input, 'credentials.' . data_get($field, 'name')))) {
                throw ValidationException::withMessages(['credentials.' . data_get($field, 'name') => data_get($field, 'label', data_get($field, 'name')) . ' is required.']);
            }
        }
    }

    protected function normalizeConnectionInput(array &$input, ?FuelProviderConnection $connection = null): void
    {
        $input['company_uuid'] ??= session('company');
        $input['environment'] ??= 'production';
        $input['status']        = $connection?->status && $connection->status !== 'draft' ? $connection->status : ($input['status'] ?? 'configured');
        $input['sync_settings'] = array_merge([
            'window_days'              => 7,
            'matching_order'           => ['plate_number', 'internal_id', 'vin', 'serial_number', 'call_sign', 'fuel_card_number', 'trip_number'],
            'auto_create_fuel_reports' => true,
        ], (array) data_get($input, 'sync_settings', []));
    }

    protected function findConnection(string $id): FuelProviderConnection
    {
        return FuelProviderConnection::where(function ($query) use ($id) {
            $query->where('uuid', $id)->orWhere('public_id', $id);
        })
            ->where('company_uuid', session('company'))
            ->firstOrFail();
    }
}
