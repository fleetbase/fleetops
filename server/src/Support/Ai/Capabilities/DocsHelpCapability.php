<?php

namespace Fleetbase\FleetOps\Support\Ai\Capabilities;

use Fleetbase\Ai\Models\AiTask;

class DocsHelpCapability extends AbstractFleetOpsAICapability
{
    public function key(): string
    {
        return 'fleet-ops.docs_help';
    }

    public function label(): string
    {
        return 'Fleet-Ops docs help';
    }

    public function description(): string
    {
        return 'Provides curated official Fleetbase documentation references for Fleet-Ops how-to questions.';
    }

    public function resolve(AiTask $task): array
    {
        $prompt = $this->prompt($task);

        return [
            'references' => array_values(array_filter([
                $this->reference('Fleet-Ops overview', 'https://fleetbase.io/docs/fleet-ops', 'Fleet-Ops capabilities, concepts, orders, resources, maintenance, connectivity, analytics, and reports.'),
                $this->containsAny($prompt, ['order', 'dispatch', 'create']) ? $this->reference('Fleet-Ops quickstart', 'https://fleetbase.io/docs/fleet-ops/getting-started/quickstart', 'Create your first order, assign a driver, and dispatch.') : null,
                $this->containsAny($prompt, ['driver app', 'navigator', 'mobile']) ? $this->reference('Navigator app setup', 'https://fleetbase.io/docs/fleet-ops/getting-started/navigator-app-setup', 'Set up Navigator for drivers.') : null,
                $this->containsAny($prompt, ['maintenance', 'work order', 'parts']) ? $this->reference('Maintenance overview', 'https://fleetbase.io/docs/fleet-ops/maintenance', 'Maintenance schedules, work orders, equipment, and parts.') : null,
                $this->containsAny($prompt, ['telematic', 'device', 'sensor', 'connectivity']) ? $this->reference('Connectivity overview', 'https://fleetbase.io/docs/fleet-ops/connectivity', 'Telematics, devices, sensors, and connectivity events.') : null,
                $this->containsAny($prompt, ['analytics', 'report', 'reports']) ? $this->reference('Analytics overview', 'https://fleetbase.io/docs/fleet-ops/analytics', 'Fleet-Ops analytics and report workflows.') : null,
            ])),
        ];
    }

    protected function matchesPrompt(string $prompt): bool
    {
        return $this->containsAny($prompt, ['how do i', 'how to', 'docs', 'documentation', 'help me', 'where do i', 'guide']);
    }

    protected function reference(string $title, string $url, string $description): array
    {
        return compact('title', 'url', 'description');
    }
}
