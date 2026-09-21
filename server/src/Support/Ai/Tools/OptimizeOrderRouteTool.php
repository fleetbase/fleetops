<?php

namespace Fleetbase\FleetOps\Support\Ai\Tools;

use Fleetbase\Ai\Contracts\AIToolCapabilityInterface;
use Fleetbase\Ai\Models\AiTask;
use Fleetbase\Ai\Support\AiToolContext;
use Fleetbase\FleetOps\Support\Ai\Capabilities\OptimizeOrderRouteCapability;

/**
 * Lets the model propose a resequenced waypoint order for a Fleet-Ops order. The proposal is shown as
 * a preview card; the route is only changed when the user applies it.
 */
class OptimizeOrderRouteTool extends OptimizeOrderRouteCapability implements AIToolCapabilityInterface
{
    public function toolName(): string
    {
        return 'propose_optimize_order_route';
    }

    public function toolDescription(): string
    {
        return 'Prepare an optimized waypoint sequence for one Fleet-Ops order, for the user to review. '
            . 'Use it when the user asks to optimize, reorder, or resequence the stops on an order. '
            . 'Each proposal appears as a card the user must confirm; the route is NOT changed by this tool. '
            . 'After calling, tell the user to review the card; never claim the route was changed.';
    }

    public function toolParameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'order' => ['type' => 'string', 'description' => 'The order public id, internal id, or UUID, exactly as the user gave it.'],
            ],
            'required'             => ['order'],
            'additionalProperties' => false,
        ];
    }

    public function availableFor(AiToolContext $context): bool
    {
        return $context->audience->canAll($this->permissions());
    }

    public function invoke(AiTask $task, array $arguments, AiToolContext $context): array
    {
        $order = trim((string) ($arguments['order'] ?? ''));

        if ($order === '') {
            return ['error' => 'invalid_arguments', 'message' => 'An order id is required to optimize a route.'];
        }

        // The capability resolves the order from the prompt text, so hand it a copy carrying just the
        // identifier the model supplied. The copy is never saved.
        $probe         = clone $task;
        $probe->prompt = $order;

        $preview = $context->addActionPreview($this, $this->preview($probe));

        return [
            'preview_id'     => $preview['preview_id'],
            'ready'          => $preview['ready'] ?? false,
            'missing_fields' => $preview['missing_fields'] ?? [],
            'fields'         => $preview['fields'] ?? [],
            'status'         => 'proposal_shown_to_user',
            'message'        => $preview['message'] ?? 'A route proposal card is shown to the user.',
        ];
    }
}
