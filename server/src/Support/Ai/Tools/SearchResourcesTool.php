<?php

namespace Fleetbase\FleetOps\Support\Ai\Tools;

use Fleetbase\Ai\Contracts\AIToolCapabilityInterface;
use Fleetbase\Ai\Models\AiTask;
use Fleetbase\Ai\Support\AiToolContext;
use Fleetbase\FleetOps\Support\Ai\Capabilities\SearchResourcesCapability;
use Illuminate\Support\Str;

/**
 * Lets the model look up specific Fleet-Ops records by id, name, plate, email, or tracking number.
 */
class SearchResourcesTool extends SearchResourcesCapability implements AIToolCapabilityInterface
{
    public const TYPES = ['orders', 'vehicles', 'drivers', 'work_orders', 'maintenances', 'devices', 'sensors', 'telematics'];

    public function toolName(): string
    {
        return 'fleetops_search';
    }

    public function toolDescription(): string
    {
        return 'Find specific Fleet-Ops records (orders, vehicles, drivers, work orders, maintenances, devices, sensors, telematics) by public id, internal id, tracking number, name, plate number, VIN, email, or phone. '
            . 'Use it when the user refers to a particular record. For counts or status breakdowns use the counting tools instead. Returns at most 5 matches per type.';
    }

    public function toolParameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'query' => ['type' => 'string', 'description' => 'The identifier or name to look for, e.g. "order_yhkejdnzgz", "SBA1234Z", or "Jane Doe".'],
                'types' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => static::TYPES], 'description' => 'Optional record types to search. Searches all types when omitted.'],
            ],
            'required'             => ['query'],
            'additionalProperties' => false,
        ];
    }

    public function availableFor(AiToolContext $context): bool
    {
        foreach ($this->permissions() as $permission) {
            if ($context->audience->can($permission)) {
                return true;
            }
        }

        return false;
    }

    public function invoke(AiTask $task, array $arguments, AiToolContext $context): array
    {
        $query = trim((string) ($arguments['query'] ?? ''));

        if (mb_strlen($query) < 2) {
            return ['error' => 'Provide an identifier or name to search for.'];
        }

        // The model sends an intentional query, so the whole phrase is searched along with any ids in it.
        $terms = collect([Str::limit($query, 100, '')])
            ->merge($this->searchTerms($query))
            ->unique(fn ($term) => Str::lower($term))
            ->take(4)
            ->values()
            ->all();

        $types = array_values(array_intersect((array) ($arguments['types'] ?? []), static::TYPES));

        return $this->searchAll($terms, $types ?: null);
    }
}
