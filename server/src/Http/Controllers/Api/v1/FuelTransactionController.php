<?php

namespace Fleetbase\FleetOps\Http\Controllers\Api\v1;

use Fleetbase\FleetOps\Http\Controllers\Api\v1\Concerns\ResolvesFleetOpsApiResources;
use Fleetbase\FleetOps\Http\Requests\CreateFuelTransactionRequest;
use Fleetbase\FleetOps\Http\Requests\UpdateFuelTransactionRequest;
use Fleetbase\FleetOps\Http\Resources\v1\DeletedResource;
use Fleetbase\FleetOps\Http\Resources\v1\FuelTransaction as FuelTransactionResource;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\FuelProviderConnection;
use Fleetbase\FleetOps\Models\FuelProviderTransaction;
use Fleetbase\FleetOps\Models\FuelReport;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\FleetOps\Support\FuelProviders\FuelProviderService;
use Fleetbase\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FuelTransactionController extends Controller
{
    use ResolvesFleetOpsApiResources;

    public function __construct(protected FuelProviderService $fuelProviderService)
    {
    }

    public function create(CreateFuelTransactionRequest $request)
    {
        $this->rejectUuidIdentifiers($request);

        $input                 = $this->input($request);
        $input['company_uuid'] = session('company');

        $transaction = FuelProviderTransaction::create($input)->load(['connection', 'vehicle', 'driver', 'order', 'fuelReport']);

        return new FuelTransactionResource($transaction);
    }

    public function update(string $id, UpdateFuelTransactionRequest $request)
    {
        $this->rejectUuidIdentifiers($request);

        try {
            $transaction = $this->findTransaction($id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return response()->json(['error' => 'FuelTransaction resource not found.'], 404);
        }

        $transaction->update($this->input($request));

        return new FuelTransactionResource($transaction->refresh()->load(['connection', 'vehicle', 'driver', 'order', 'fuelReport']));
    }

    public function query(Request $request)
    {
        $this->rejectUuidIdentifiers($request);

        $results = FuelProviderTransaction::queryWithRequest($request, function (&$query) {
            $query->with(['connection', 'vehicle', 'driver', 'order', 'fuelReport']);
        });

        return FuelTransactionResource::collection($results);
    }

    public function find(string $id)
    {
        try {
            $transaction = $this->findTransaction($id)->load(['connection', 'vehicle', 'driver', 'order', 'fuelReport']);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return response()->json(['error' => 'FuelTransaction resource not found.'], 404);
        }

        return new FuelTransactionResource($transaction);
    }

    public function delete(string $id)
    {
        try {
            $transaction = $this->findTransaction($id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return response()->json(['error' => 'FuelTransaction resource not found.'], 404);
        }

        $transaction->delete();

        return new DeletedResource($transaction);
    }

    public function matchVehicle(Request $request, string $id): JsonResponse
    {
        $this->rejectUuidIdentifiers($request);

        $request->validate(['vehicle' => 'required|string']);
        $transaction = $this->findTransaction($id);
        $vehicle     = $this->resolveModel(Vehicle::class, $request->input('vehicle'));

        return response()->json([
            'status'      => 'ok',
            'transaction' => new FuelTransactionResource($this->fuelProviderService->matchVehicle($transaction, $vehicle)),
        ]);
    }

    public function matchOrder(Request $request, string $id): JsonResponse
    {
        $this->rejectUuidIdentifiers($request);

        $request->validate(['order' => 'required|string']);
        $transaction = $this->findTransaction($id);
        $order       = $this->resolveModel(Order::class, $request->input('order'));

        return response()->json([
            'status'      => 'ok',
            'transaction' => new FuelTransactionResource($this->fuelProviderService->matchOrder($transaction, $order)),
        ]);
    }

    public function reprocess(string $id): JsonResponse
    {
        $transaction = $this->findTransaction($id);

        return response()->json([
            'status'      => 'ok',
            'transaction' => new FuelTransactionResource($this->fuelProviderService->reprocessTransaction($transaction)),
        ]);
    }

    public function review(Request $request, string $id): JsonResponse
    {
        $this->rejectUuidIdentifiers($request);

        $request->validate(['status' => 'required|string|in:reviewed,ignored']);
        $transaction = $this->findTransaction($id);

        return response()->json([
            'status'      => 'ok',
            'transaction' => new FuelTransactionResource($this->fuelProviderService->reviewTransaction($transaction, $request->input('status'))),
        ]);
    }

    protected function findTransaction(string $id): FuelProviderTransaction
    {
        return $this->resolveModel(FuelProviderTransaction::class, $id);
    }

    protected function input(Request $request): array
    {
        $input = $request->only([
            'provider',
            'provider_transaction_id',
            'provider_vehicle_id',
            'vehicle_card_id',
            'internal_number',
            'structure_number',
            'plate_number',
            'vin',
            'serial_number',
            'call_sign',
            'trip_number',
            'station_name',
            'station_latitude',
            'station_longitude',
            'transaction_at',
            'volume',
            'metric_unit',
            'amount',
            'currency',
            'odometer',
            'sync_status',
            'matched_at',
            'normalized_payload',
            'raw_payload',
            'meta',
        ]);

        $this->applyPublicIdRelation($input, 'connection', 'fuel_provider_connection_uuid', FuelProviderConnection::class, $request);
        $this->applyPublicIdRelation($input, 'fuel_report', 'fuel_report_uuid', FuelReport::class, $request);
        $this->applyPublicIdRelation($input, 'vehicle', 'vehicle_uuid', Vehicle::class, $request);
        $this->applyPublicIdRelation($input, 'driver', 'driver_uuid', Driver::class, $request);
        $this->applyPublicIdRelation($input, 'order', 'order_uuid', Order::class, $request);

        return $input;
    }
}
