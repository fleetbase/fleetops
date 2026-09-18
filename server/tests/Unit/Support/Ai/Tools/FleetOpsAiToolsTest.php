<?php

use Fleetbase\Ai\Models\AiTask;
use Fleetbase\Ai\Support\AiAudience;
use Fleetbase\Ai\Support\AiToolContext;
use Fleetbase\FleetOps\Models\OrderConfig;
use Fleetbase\FleetOps\Support\Ai\Tools\CreateOrderTool;
use Fleetbase\FleetOps\Support\Ai\Tools\SearchResourcesTool;

class FleetOpsCreateOrderToolProbe extends CreateOrderTool
{
    public array $resolvedPlaces = [];

    public function exposeBuildDraft(AiTask $task, array $input = []): array
    {
        return $this->buildDraft($task, $input);
    }

    protected function can(string $permission): bool
    {
        return true;
    }

    protected function draftFromPrompt(string $prompt): array
    {
        return array_filter([
            'order_config_uuid' => 'order-config-uuid',
            'type'              => 'transport',
            'payload'           => $prompt !== '' ? ['pickup_query' => 'Parsed from prompt'] : [],
            'dispatched'        => false,
        ], fn ($value) => $value !== []) + ['payload' => []];
    }

    protected function resolvePlace(?string $query): ?array
    {
        return $this->resolvedPlaces[$query] ?? null;
    }

    protected function resolveOrderConfig(array $draft): ?OrderConfig
    {
        $config = new OrderConfig();
        $config->setRawAttributes(['uuid' => 'order-config-uuid', 'key' => 'transport', 'name' => 'Transport'], true);

        return $config;
    }

    protected function resolveDriver(array $draft): ?Fleetbase\FleetOps\Models\Driver
    {
        return null;
    }

    protected function resolveVehicle(array $draft): ?Fleetbase\FleetOps\Models\Vehicle
    {
        return null;
    }
}

class FleetOpsSearchResourcesToolProbe extends SearchResourcesTool
{
    public array $searched = [];

    protected function searchAll(array $terms, ?array $types = null): array
    {
        $this->searched[] = [$terms, $types];

        return ['query_terms' => $terms, 'results' => []];
    }
}

test('create order tool prepares one draft preview per call from structured fields without parsing the prompt', function () {
    $tool    = new FleetOpsCreateOrderToolProbe();
    $task    = new AiTask(['prompt' => 'Create some dummy orders with made up data', 'metadata' => []]);
    $context = new AiToolContext($task, new AiAudience(false, ['fleet-ops create order']));

    $first = $tool->invoke($task, [
        'pickup'       => 'Kabelweg 57, Amsterdam',
        'dropoff'      => 'Danzigerkade 15, Amsterdam',
        'scheduled_at' => '2026-09-16T09:30:00+02:00',
        'driver'       => '  ',
        'notes'        => 'Test order',
    ], $context);
    $second = $tool->invoke($task, ['pickup' => 'Diemerhof 32, Diemen', 'dropoff' => 'Herikerbergweg 238, Amsterdam', 'dispatch' => true], $context);

    expect($first['preview_id'])->toBe('preview-1')
        ->and($second['preview_id'])->toBe('preview-2')
        ->and($first['status'])->toBe('draft_shown_to_user')
        ->and($first['message'])->toContain('does not exist until the user reviews the card')
        ->and($context->actionPreviews)->toHaveCount(2)
        ->and($context->actionPreviews[0]['draft']['payload']['pickup_query'])->toBe('Kabelweg 57, Amsterdam')
        ->and($context->actionPreviews[0]['draft']['scheduled_at'])->toBe('2026-09-16T09:30:00+02:00')
        ->and($context->actionPreviews[0]['draft']['notes'])->toBe('Test order')
        ->and($context->actionPreviews[0]['draft'])->not->toHaveKey('driver_query')
        ->and($context->actionPreviews[0]['draft']['dispatched'])->toBeFalse()
        ->and($context->actionPreviews[1]['draft']['payload']['pickup_query'])->toBe('Diemerhof 32, Diemen')
        ->and($context->actionPreviews[1]['draft']['dispatched'])->toBeTrue()
        ->and(json_encode($context->actionPreviews))->not->toContain('Parsed from prompt')
        ->and($tool->toolName())->toBe('propose_create_order')
        ->and($tool->key())->toBe('fleet-ops.create_order')
        ->and($tool->toolDescription())->toContain('NOT created by this tool')
        ->and($tool->toolParameters()['required'])->toBe(['pickup', 'dropoff'])
        ->and($tool->availableFor($context))->toBeTrue()
        ->and($tool->availableFor(new AiToolContext($task, new AiAudience(false, []))))->toBeFalse();
});

test('create order drafts refresh from the edited preview and still parse prompts in keyword mode', function () {
    $tool = new FleetOpsCreateOrderToolProbe();
    $task = new AiTask(['prompt' => 'create order', 'metadata' => ['action_previews' => [['draft' => ['notes' => 'first preview']]]]]);

    $refreshed = $tool->exposeBuildDraft($task, ['existing_draft' => ['notes' => 'second preview'], 'draft' => ['dispatched' => true]]);
    $keyword   = $tool->exposeBuildDraft($task, ['dispatched' => false]);

    expect($refreshed['notes'])->toBe('second preview')
        ->and($refreshed['dispatched'])->toBeTrue()
        ->and($refreshed['payload']['pickup_query'])->toBe('Parsed from prompt')
        ->and($keyword['notes'])->toBe('first preview')
        ->and($keyword)->not->toHaveKey('existing_draft');
});

test('search tool searches the requested phrase and identifiers for the chosen record types', function () {
    $tool    = new FleetOpsSearchResourcesToolProbe();
    $task    = new AiTask(['prompt' => 'where is Jane']);
    $context = new AiToolContext($task, new AiAudience(false, ['fleet-ops see driver']));

    $tool->invoke($task, ['query' => 'Jane Doe', 'types' => ['drivers', 'pets']], $context);
    $tool->invoke($task, ['query' => 'order order_yhkejdnzgz'], $context);

    expect($tool->searched[0])->toBe([['Jane Doe', 'Doe'], ['drivers']])
        ->and($tool->searched[1])->toBe([['order order_yhkejdnzgz', 'order_yhkejdnzgz'], null])
        ->and($tool->invoke($task, ['query' => ' x '], $context))->toBe(['error' => 'Provide an identifier or name to search for.'])
        ->and($tool->toolName())->toBe('fleetops_search')
        ->and($tool->key())->toBe('fleet-ops.search_resources')
        ->and($tool->toolDescription())->toContain('particular record')
        ->and($tool->toolParameters()['properties']['types']['items']['enum'])->toBe(SearchResourcesTool::TYPES)
        ->and($tool->availableFor($context))->toBeTrue()
        ->and($tool->availableFor(new AiToolContext($task, new AiAudience(false, []))))->toBeFalse();
});

test('search tool narrows the real search to the requested types', function () {
    $tool = new class extends SearchResourcesTool {
        public array $called = [];

        protected function orders(array $terms): array
        {
            $this->called[] = 'orders';

            return [['id' => 'order_1']];
        }

        protected function drivers(array $terms): array
        {
            $this->called[] = 'drivers';

            return [];
        }
    };

    $result = $tool->invoke(new AiTask(['prompt' => '']), ['query' => 'ORDER-1', 'types' => ['orders']], new AiToolContext(new AiTask(), new AiAudience(true)));

    expect($tool->called)->toBe(['orders'])
        ->and($result['results'])->toBe(['orders' => [['id' => 'order_1']]]);
});
