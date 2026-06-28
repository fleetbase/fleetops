<?php

namespace Fleetbase\FleetOps\Support\Ai\Capabilities;

use Fleetbase\Ai\Models\AiTask;

class ConsoleNavigationCapability extends SearchResourcesCapability
{
    public function key(): string
    {
        return 'fleet-ops.console_navigation';
    }

    public function label(): string
    {
        return 'Fleet-Ops console navigation preview';
    }

    public function description(): string
    {
        return 'Returns preview-only console route suggestions for Fleet-Ops resources.';
    }

    public function type(): string
    {
        return 'ui';
    }

    public function mode(): string
    {
        return 'navigation_preview';
    }

    public function resolve(AiTask $task): array
    {
        $search = parent::resolve($task);
        $items  = collect(data_get($search, 'results', []))
            ->flatten(1)
            ->filter(fn ($result) => is_array($result) && isset($result['route']))
            ->take(5)
            ->values()
            ->all();

        return [
            'preview_only' => true,
            'message'      => 'Fleetbase AI can suggest where to navigate, but this pilot does not directly open panels yet.',
            'suggestions'  => $items,
        ];
    }

    protected function matchesPrompt(string $prompt): bool
    {
        return $this->containsAny($prompt, ['open', 'show me', 'go to', 'navigate', 'take me to']) && parent::matchesPrompt($prompt);
    }
}
