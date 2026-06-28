<?php

namespace Fleetbase\FleetOps\Support\Ai\Capabilities;

use Fleetbase\Ai\Models\AiTask;
use Fleetbase\FleetOps\Models\Order;
use Illuminate\Support\Carbon;

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
                'label' => $window['label'],
                'start' => $window['start']->toIso8601String(),
                'end'   => $window['end']->toIso8601String(),
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
        return str_contains($prompt, 'order') && $this->containsAny($prompt, ['how many', 'count', 'report', 'value', 'over', 'status', 'last month', 'this month', 'completed', 'active']);
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
        $now = Carbon::now();

        if (str_contains($prompt, 'last month')) {
            $start = $now->copy()->subMonthNoOverflow()->startOfMonth();
            $end   = $now->copy()->subMonthNoOverflow()->endOfMonth();

            return compact('start', 'end') + ['label' => 'last_month'];
        }

        if (str_contains($prompt, 'this month')) {
            $start = $now->copy()->startOfMonth();
            $end   = $now->copy()->endOfMonth();

            return compact('start', 'end') + ['label' => 'this_month'];
        }

        if (str_contains($prompt, 'last 30 days')) {
            $start = $now->copy()->subDays(30)->startOfDay();
            $end   = $now->copy()->endOfDay();

            return compact('start', 'end') + ['label' => 'last_30_days'];
        }

        return null;
    }
}
