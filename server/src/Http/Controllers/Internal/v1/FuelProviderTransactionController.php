<?php

namespace Fleetbase\FleetOps\Http\Controllers\Internal\v1;

use Fleetbase\Attributes\SkipAuthorizationCheck;
use Fleetbase\FleetOps\Http\Controllers\FleetOpsController;
use Fleetbase\FleetOps\Models\FuelProviderTransaction;
use Fleetbase\FleetOps\Support\FuelProviders\FuelProviderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FuelProviderTransactionController extends FleetOpsController
{
    /**
     * Permissions for methods AuthorizationGuard cannot map to a schema action (see FleetOpsController).
     *
     * @var array<string, string|string[]>
     */
    protected array $methodPermissions = [
        'matchVehicle' => 'update fuel-provider-transaction',
        'matchOrder'   => 'update fuel-provider-transaction',
        'reprocess'    => 'update fuel-provider-transaction',
    ];

    public $resource = 'fuel_provider_transaction';

    public function __construct(protected FuelProviderService $fuelProviderService)
    {
        parent::__construct();
    }

    public static function onQueryRecord($query, $request): void
    {
        $query->with(['vehicle', 'driver', 'fuelReport']);
    }

    #[SkipAuthorizationCheck]
    public function matchVehicle(Request $request, string $id): JsonResponse
    {
        $request->validate(['vehicle' => 'required|string']);
        $transaction = $this->findTransaction($id);

        return response()->json([
            'status'      => 'ok',
            'transaction' => $this->fuelProviderService->matchVehicle($transaction, $request->input('vehicle')),
        ]);
    }

    #[SkipAuthorizationCheck]
    public function matchOrder(Request $request, string $id): JsonResponse
    {
        $request->validate(['order' => 'required|string']);
        $transaction = $this->findTransaction($id);

        return response()->json([
            'status'      => 'ok',
            'transaction' => $this->fuelProviderService->matchOrder($transaction, $request->input('order')),
        ]);
    }

    #[SkipAuthorizationCheck]
    public function reprocess(Request $request, string $id): JsonResponse
    {
        $transaction = $this->findTransaction($id);

        return response()->json([
            'status'      => 'ok',
            'transaction' => $this->fuelProviderService->reprocessTransaction($transaction),
        ]);
    }

    public function review(Request $request, string $id): JsonResponse
    {
        $request->validate(['status' => 'required|string|in:reviewed,ignored']);
        $transaction = $this->findTransaction($id);

        return response()->json([
            'status'      => 'ok',
            'transaction' => $this->fuelProviderService->reviewTransaction($transaction, $request->input('status')),
        ]);
    }

    protected function findTransaction(string $id): FuelProviderTransaction
    {
        return FuelProviderTransaction::where(function ($query) use ($id) {
            $query->where('uuid', $id)->orWhere('public_id', $id);
        })
            ->where('company_uuid', session('company'))
            ->firstOrFail();
    }
}
