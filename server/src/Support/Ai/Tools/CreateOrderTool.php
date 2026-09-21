<?php

namespace Fleetbase\FleetOps\Support\Ai\Tools;

use Fleetbase\Ai\Contracts\AIToolCapabilityInterface;
use Fleetbase\Ai\Models\AiTask;
use Fleetbase\Ai\Support\AiToolContext;
use Fleetbase\FleetOps\Support\Ai\Capabilities\CreateOrderPreviewCapability;

/**
 * Lets the model prepare a Fleet-Ops order draft from structured fields. The draft is shown to the user
 * as a preview card; the order is only created when the user applies it.
 */
class CreateOrderTool extends CreateOrderPreviewCapability implements AIToolCapabilityInterface
{
    public function toolName(): string
    {
        return 'propose_create_order';
    }

    public function toolDescription(): string
    {
        return 'Prepare a Fleet-Ops order draft for the user to review. Call once per order (several calls prepare several drafts). '
            . 'Each draft appears as a card the user must confirm; the order is NOT created by this tool. '
            . 'Pass addresses or saved place names exactly as the user gave them. Use ISO 8601 for scheduled_at, resolved with the temporal context. '
            . 'Only fill fields the user provided or explicitly asked you to make up (for example test data). After calling, tell the user to review the draft card; never claim the order exists.';
    }

    public function toolParameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'pickup'       => ['type' => 'string', 'description' => 'Pickup address or saved place name.'],
                'dropoff'      => ['type' => 'string', 'description' => 'Dropoff address or saved place name.'],
                'scheduled_at' => ['type' => 'string', 'description' => 'Optional ISO 8601 date and time.'],
                'driver'       => ['type' => 'string', 'description' => 'Optional driver name or public id.'],
                'vehicle'      => ['type' => 'string', 'description' => 'Optional vehicle name, plate, or public id.'],
                'order_config' => ['type' => 'string', 'description' => 'Optional order configuration key, e.g. transport.'],
                'notes'        => ['type' => 'string', 'description' => 'Optional notes for the order.'],
                'dispatch'     => ['type' => 'boolean', 'description' => 'Dispatch immediately. Only true when the user asked for it.'],
            ],
            'required'             => ['pickup', 'dropoff'],
            'additionalProperties' => false,
        ];
    }

    public function availableFor(AiToolContext $context): bool
    {
        return $context->audience->canAll($this->permissions());
    }

    public function invoke(AiTask $task, array $arguments, AiToolContext $context): array
    {
        $draft = array_filter([
            'payload' => array_filter([
                'pickup_query'  => $this->argument($arguments, 'pickup'),
                'dropoff_query' => $this->argument($arguments, 'dropoff'),
            ]),
            'scheduled_at'  => $this->argument($arguments, 'scheduled_at'),
            'driver_query'  => $this->argument($arguments, 'driver'),
            'vehicle_query' => $this->argument($arguments, 'vehicle'),
            'type'          => $this->argument($arguments, 'order_config'),
            'notes'         => $this->argument($arguments, 'notes'),
            'dispatched'    => (bool) ($arguments['dispatch'] ?? false),
        ], fn ($value) => $value !== null && $value !== []);

        $preview = $context->addActionPreview($this, $this->preview($task, ['source' => 'tool', 'draft' => $draft]));

        return [
            'preview_id'     => $preview['preview_id'],
            'ready'          => $preview['ready'] ?? false,
            'missing_fields' => $preview['missing_fields'] ?? [],
            'fields'         => $preview['fields'] ?? [],
            'status'         => 'draft_shown_to_user',
            'message'        => 'A draft order card is shown to the user. The order does not exist until the user reviews the card and clicks ' . ($preview['apply_label'] ?? 'Create order') . '.',
        ];
    }

    protected function argument(array $arguments, string $key): ?string
    {
        $value = trim((string) ($arguments[$key] ?? ''));

        return $value === '' ? null : $value;
    }
}
