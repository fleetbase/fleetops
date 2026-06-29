<?php

namespace Fleetbase\FleetOps\Support\Ai\Capabilities;

use Fleetbase\Ai\Models\AiTask;

class ImportOrdersPreviewCapability extends AbstractFleetOpsAICapability
{
    public function key(): string
    {
        return 'fleet-ops.import_orders_preview';
    }

    public function label(): string
    {
        return 'Import Fleet-Ops orders preview';
    }

    public function description(): string
    {
        return 'Explains and drafts preview-only Fleet-Ops import requirements for order/resource spreadsheets.';
    }

    public function type(): string
    {
        return 'action';
    }

    public function mode(): string
    {
        return 'preview';
    }

    public function permissions(): array
    {
        return ['fleet-ops import order'];
    }

    public function resolve(AiTask $task): array
    {
        return [
            'preview_only'     => true,
            'action'           => 'import_orders',
            'authorized'       => $this->can('fleet-ops import order'),
            'message'          => 'This pilot can inspect intent and explain import requirements, but it will not process uploaded spreadsheets.',
            'accepted_sources' => ['xlsx', 'csv'],
            'minimum_columns'  => [
                'pickup address or pickup place',
                'dropoff address or dropoff place',
                'customer/contact reference',
                'order type/configuration when required',
                'item/entity details when required',
            ],
            'draft_hints'      => [
                'Ask the user to upload a spreadsheet through the Fleet-Ops import flow.',
                'Do not claim rows were imported.',
                'Offer to summarize expected columns or validation issues once file inspection support exists.',
            ],
        ];
    }

    protected function matchesPrompt(string $prompt): bool
    {
        return $this->containsAny($prompt, ['import', 'spreadsheet', 'excel', 'xlsx', 'csv']) && $this->containsAny($prompt, ['order', 'orders', 'resources', 'vehicles', 'drivers']);
    }
}
