<?php

namespace Fleetbase\FleetOps\Http\Controllers\Internal\v1;

use Fleetbase\FleetOps\Http\Controllers\FleetOpsController;
use Fleetbase\FleetOps\Imports\MaintenanceImport;
use Fleetbase\FleetOps\Models\Maintenance;
use Fleetbase\Http\Requests\ImportRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class MaintenanceController extends FleetOpsController
{
    /**
     * The resource to query.
     *
     * @var string
     */
    public $resource = 'maintenance';

    /**
     * Eager-load polymorphic relationships after create so they appear in the API response.
     * Called automatically by HasApiControllerBehavior::createRecord() via getControllerCallback.
     */
    public function onAfterCreate($request, Maintenance $record, array $input): void
    {
        $record->load(['maintainable', 'performedBy']);
    }

    /**
     * Eager-load polymorphic relationships after update so they appear in the API response.
     * Called automatically by HasApiControllerBehavior::updateRecord() via getControllerCallback.
     */
    public function onAfterUpdate($request, Maintenance $record, array $input): void
    {
        $record->load(['maintainable', 'performedBy']);
    }

    /**
     * Eager-load polymorphic relationships when finding record so they appear in the API response.
     * Called automatically by HasApiControllerBehavior::findRecord() via getControllerCallback.
     */
    public function onFindRecord($builder, $request): void
    {
        $builder->with(['maintainable', 'performedBy']);
    }

    /**
     * Add a cost line item to a maintenance record.
     * POST /maintenances/{id}/line-items.
     */
    public function addLineItem(string $id, Request $request): JsonResponse
    {
        $maintenance = Maintenance::where('uuid', $id)
            ->orWhere('public_id', $id)
            ->firstOrFail();

        $validated = $request->validate([
            'description' => 'required|string|max:255',
            'quantity'    => 'required|numeric|min:0',
            'unit_cost'   => 'required|integer|min:0',
            'currency'    => 'nullable|string|size:3',
        ]);

        $maintenance->addLineItem($validated);
        $maintenance->refresh();
        $this->recalculateCosts($maintenance);

        return response()->json([
            'status'     => 'ok',
            'line_items' => $maintenance->line_items,
            'total_cost' => $maintenance->total_cost,
        ]);
    }

    /**
     * Update a cost line item on a maintenance record.
     * PUT /maintenances/{id}/line-items/{index}.
     */
    public function updateLineItem(string $id, int $index, Request $request): JsonResponse
    {
        $maintenance = Maintenance::where('uuid', $id)
            ->orWhere('public_id', $id)
            ->firstOrFail();

        $validated = $request->validate([
            'description' => 'required|string|max:255',
            'quantity'    => 'required|numeric|min:0',
            'unit_cost'   => 'required|integer|min:0',
            'currency'    => 'nullable|string|size:3',
        ]);

        $lineItems = $maintenance->line_items ?? [];

        if (!isset($lineItems[$index])) {
            return response()->json(['error' => 'Line item not found.'], 404);
        }

        $lineItems[$index] = array_merge($lineItems[$index], $validated, [
            'updated_at' => now(),
        ]);

        $maintenance->update(['line_items' => $lineItems]);
        $maintenance->refresh();
        $this->recalculateCosts($maintenance);

        return response()->json([
            'status'     => 'ok',
            'line_items' => $maintenance->line_items,
            'total_cost' => $maintenance->total_cost,
        ]);
    }

    /**
     * Remove a cost line item from a maintenance record.
     * DELETE /maintenances/{id}/line-items/{index}.
     */
    public function removeLineItem(string $id, int $index): JsonResponse
    {
        $maintenance = Maintenance::where('uuid', $id)
            ->orWhere('public_id', $id)
            ->firstOrFail();

        if (!$maintenance->removeLineItem($index)) {
            return response()->json(['error' => 'Line item not found.'], 404);
        }

        $maintenance->refresh();
        $this->recalculateCosts($maintenance);

        return response()->json([
            'status'     => 'ok',
            'line_items' => $maintenance->line_items,
            'total_cost' => $maintenance->total_cost,
        ]);
    }

    /**
     * Process import files (excel, csv) into Maintenance records.
     *
     * @return \Illuminate\Http\Response
     */
    public function import(ImportRequest $request)
    {
        $disk          = $request->input('disk', config('filesystems.default'));
        $files         = $request->resolveFilesFromIds();
        $importedCount = 0;

        foreach ($files as $file) {
            try {
                $import = new MaintenanceImport();
                Excel::import($import, $file->path, $disk);
                $importedCount += $import->imported;
            } catch (\Throwable $e) {
                return response()->error('Invalid file, unable to process.');
            }
        }

        return response()->json(['status' => 'ok', 'message' => 'Import completed', 'imported' => $importedCount]);
    }

    /**
     * Recalculate and persist parts_cost and total_cost derived from line_items.
     * All monetary values are stored as integers (smallest currency unit, e.g. cents).
     */
    protected function recalculateCosts(Maintenance $maintenance): void
    {
        $lineItems = $maintenance->line_items ?? [];
        $partsCost = (int) collect($lineItems)->sum(fn ($item) => ($item['quantity'] ?? 0) * ($item['unit_cost'] ?? 0));
        $laborCost = (int) ($maintenance->labor_cost ?? 0);
        $tax       = (int) ($maintenance->tax ?? 0);
        $totalCost = $laborCost + $partsCost + $tax;

        $maintenance->update([
            'parts_cost' => $partsCost,
            'total_cost' => $totalCost,
        ]);
    }
}
