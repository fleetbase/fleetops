<?php

namespace Fleetbase\FleetOps\Http\Controllers\Internal\v1;

use Fleetbase\FleetOps\Http\Controllers\FleetOpsController;
use Fleetbase\FleetOps\Jobs\SyncFuelProviderTransactionsJob;
use Fleetbase\FleetOps\Models\FuelProviderConnection;
use Fleetbase\FleetOps\Support\FuelProviders\FuelProviderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

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

    public function testConnection(Request $request, string $id): JsonResponse
    {
        $connection = $this->findConnection($id);
        $result = $this->fuelProviderService->testConnection($connection);

        return response()->json($result, data_get($result, 'success') ? 200 : 422);
    }

    public function sync(Request $request, string $id): JsonResponse
    {
        $connection = $this->findConnection($id);
        $async = $request->boolean('async', true);
        $from = $request->input('from') ? Carbon::parse($request->input('from')) : null;
        $to = $request->input('to') ? Carbon::parse($request->input('to')) : null;
        $options = $request->array('options', []);

        if ($async) {
            SyncFuelProviderTransactionsJob::dispatch($connection->uuid, $from?->toIso8601String(), $to?->toIso8601String(), $options);

            return response()->json(['status' => 'ok', 'message' => 'Fuel provider sync queued.'], 202);
        }

        $summary = $this->fuelProviderService->syncTransactions($connection, $from, $to, $options);

        return response()->json(['status' => 'ok', 'summary' => $summary]);
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
