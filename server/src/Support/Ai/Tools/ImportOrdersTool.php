<?php

namespace Fleetbase\FleetOps\Support\Ai\Tools;

use Fleetbase\Ai\Contracts\AIToolCapabilityInterface;
use Fleetbase\Ai\Models\AiTask;
use Fleetbase\Ai\Support\AiToolContext;
use Fleetbase\FleetOps\Support\Ai\Capabilities\ImportOrdersPreviewCapability;

/**
 * Explains what a Fleet-Ops order import needs. Fleetbase AI cannot process an uploaded spreadsheet,
 * so this only reports the accepted formats and required columns for the user to act on themselves.
 */
class ImportOrdersTool extends ImportOrdersPreviewCapability implements AIToolCapabilityInterface
{
    public function toolName(): string
    {
        return 'fleetops_import_requirements';
    }

    public function toolDescription(): string
    {
        return 'Report what a Fleet-Ops order or resource import needs: accepted file formats and the columns a spreadsheet must contain. '
            . 'Use it when the user asks about importing or bulk uploading orders, vehicles, or drivers from a spreadsheet. '
            . 'This does NOT import anything and cannot read an uploaded file; never tell the user rows were imported.';
    }

    public function toolParameters(): array
    {
        return [
            'type'                 => 'object',
            'properties'           => new \stdClass(),
            'additionalProperties' => false,
        ];
    }

    public function availableFor(AiToolContext $context): bool
    {
        return $context->audience->canAll($this->permissions());
    }

    public function invoke(AiTask $task, array $arguments, AiToolContext $context): array
    {
        $requirements = $this->resolve($task);

        return [
            'accepted_sources' => $requirements['accepted_sources'] ?? [],
            'minimum_columns'  => $requirements['minimum_columns'] ?? [],
            'can_import_here'  => false,
            'message'          => $requirements['message'] ?? 'Fleetbase AI cannot process uploaded spreadsheets.',
        ];
    }
}
