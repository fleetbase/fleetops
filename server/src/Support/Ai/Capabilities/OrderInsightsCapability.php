<?php

namespace Fleetbase\FleetOps\Support\Ai\Capabilities;

use Fleetbase\Ai\Models\AiTask;
use Fleetbase\Ai\Support\AiRelativeDateResolver;
use Fleetbase\FleetOps\Models\Order;

class OrderInsightsCapability extends AbstractFleetOpsAICapability
{
    public function key(): string
    {
        return 'fleet-ops.order_insights';
    }

    public function label(): string
    {
        return 'Fleet-Ops order insights';
    }

    public function description(): string
    {
        return 'Answers bounded aggregate questions about Fleet-Ops orders, statuses, and order values.';
    }

    public function permissions(): array
    {
        return ['fleet-ops see order'];
    }

    public function resolve(AiTask $task): array
    {
        if (!$this->can('fleet-ops see order')) {
            return ['authorized' => false, 'message' => 'Current user cannot access Fleet-Ops orders.'];
        }

        $prompt = $this->prompt($task);
        $window = $this->dateWindow($prompt);
        $amount = $this->amountThreshold($prompt);
        $query  = Order::where('company_uuid', session('company'));

        if ($window) {
            $query->whereBetween('created_at', [$window['start'], $window['end']]);
        }

        if ($amount !== null) {
            $query->whereHas('transaction', fn ($transaction) => $transaction->where('amount', '>', $amount));
        }

        $total = (clone $query)->count();

        return [
            'authorized'        => true,
            'metric'            => 'orders',
            'date_window'       => $window ? [
                'label'    => $window['label'],
                'timezone' => $window['timezone'],
                'start'    => $window['start']->toIso8601String(),
                'end'      => $window['end']->toIso8601String(),
            ] : null,
            'amount_threshold'  => $amount,
            'count'             => $total,
            'counts_by_status'  => (clone $query)
                ->selectRaw('status, count(*) as aggregate')
                ->groupBy('status')
                ->pluck('aggregate', 'status')
                ->all(),
            'sample_order_ids'  => (clone $query)->latest()->limit(10)->pluck('public_id')->filter()->values()->all(),
        ];
    }

    protected function matchesPrompt(string $prompt): bool
    {
        return str_contains($prompt, 'order') && $this->containsAny($prompt, ['how many', 'count', 'report', 'value', 'over', 'status', 'today', 'yesterday', 'tomorrow', 'last week', 'this week', 'next week', 'last month', 'this month', 'last 30 days', 'completed', 'active']);
    }

    protected function amountThreshold(string $prompt): ?float
    {
        if (preg_match('/(?:over|greater than|above)\s+\$?([0-9]+(?:\.[0-9]+)?)/', $prompt, $matches)) {
            return (float) $matches[1];
        }

        return null;
    }

    protected function dateWindow(string $prompt): ?array
    {
        return $this->relativeDateResolver()->resolveWindow($prompt);
    }

    protected function relativeDateResolver(): AiRelativeDateResolver
    {
        return function_exists('app') ? app(AiRelativeDateResolver::class) : new AiRelativeDateResolver(null);
    }
}
